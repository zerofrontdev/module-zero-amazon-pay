<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Model\Order;
use Zero\AmazonPay\Model\OrderUpdater;
use Zero\AmazonPay\Model\Payment;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Where Amazon sends the customer back (checkoutResultReturnUrl:
 * `?order=<increment id>&amazonCheckoutSessionId=…`). Only the checkout
 * session's own last order is looked at; the session is completed (through
 * ReturnFlow: under the order's lock, on a freshly read order) and the
 * customer lands on the success page, or back in the cart.
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater,
        private readonly ReturnFlow $returnFlow
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $orderParam = $this->request->getParam('order');
        $order = $this->returnResult->lastOrder(Payment::CODE, is_scalar($orderParam) ? (string) $orderParam : '');
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        $sessionId = $this->request->getParam('amazonCheckoutSessionId');
        $sessionId = is_scalar($sessionId) ? (string) $sessionId : '';
        $state = $this->returnFlow->run(
            $order,
            fn (Order $fresh): string => $this->orderUpdater->update($fresh, $sessionId),
            fn (Order $fresh) => $this->orderUpdater->abandon($fresh)
        );

        return $this->returnResult->redirect($state, 'Amazon Pay');
    }
}
