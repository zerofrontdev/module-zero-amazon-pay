<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Zero\Checkout\Controller\Checkout\Placeorder;
use Zero\Checkout\Model\Money\MinorUnits;
use Zero\Checkout\Model\Money\RefundKey;

/**
 * The Amazon Pay method of Zero Checkout. Placing the order creates an
 * Amazon Pay checkout session for the order's total ("pay now": the buyer
 * confirms on Amazon's page and comes back to zero_amazonpay/payment/return)
 * and sends the customer to Amazon. OrderUpdater settles the order on the
 * way back; refunds and later captures go through the charge.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'zero_amazonpay';
    public const SESSION_ID = 'amazon_checkout_session_id';
    public const CHARGE_ID = 'amazon_charge_id';
    public const CHARGE_PERMISSION_ID = 'amazon_charge_permission_id';
    public const INTENT_CAPTURE = 'AuthorizeWithCapture';
    public const INTENT_AUTHORIZE = 'Authorize';

    /** Amazon Pay's limit for x-amz-pay-idempotency-key (developer.amazon.com/docs/amazon-pay-api-v2/idempotency.html). */
    private const IDEMPOTENCY_KEY_MAX = 32;

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        private readonly Api $api,
        private readonly UrlInterface $urlBuilder,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->api->isConfigured($quote ? (int) $quote->getStoreId() : null);
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();
        $returnUrl = $this->urlBuilder->getUrl('zero_amazonpay/payment/return', [
            '_secure' => true,
            '_query' => ['order' => (string) $order->getIncrementId()],
        ]);
        $payload = self::payload(
            $order,
            $returnUrl,
            $this->api->value('store_id', $storeId),
            $this->intent($storeId),
            (string) $this->_scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE, $storeId)
        );
        $answer = $this->api->createCheckoutSession($payload, 'zero-' . $order->getIncrementId() . '-' . time(), $storeId);

        $payment->setAdditionalInformation(self::SESSION_ID, (string) $answer['checkoutSessionId']);
        $payment->setAdditionalInformation(Placeorder::REDIRECT_URL, (string) $answer['webCheckoutDetails']['amazonPayRedirectUrl']);
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * An invoice on an authorized order takes the money.
     *
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        $chargeId = (string) $payment->getAdditionalInformation(self::CHARGE_ID);
        if ($chargeId === '') {
            throw new LocalizedException(__('This order has no Amazon Pay charge to capture.'));
        }
        /** @var Order $order */
        $order = $payment->getOrder();
        $charge = $this->api->getCharge($chargeId, (int) $order->getStoreId());
        if (($charge['statusDetails']['state'] ?? '') === 'Captured') {
            return $this;
        }
        $capture = $this->api->captureCharge(
            $chargeId,
            self::amount($order, (float) $amount * (float) ($order->getBaseToOrderRate() ?: 1)),
            // An order is captured once: the same key for every try of it.
            self::captureKey((string) $order->getIncrementId()),
            (int) $order->getStoreId()
        );
        $payment->setTransactionId((string) ($capture['chargeId'] ?? $chargeId))->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * The capture's idempotency key: "zero-capture-<increment id>", or, when
     * that is longer than Amazon's limit, the prefix and a hash of it (the
     * same for every try, like RefundKey).
     */
    public static function captureKey(string $incrementId): string
    {
        $prefix = 'zero-capture-';
        $key = $prefix . $incrementId;
        if (strlen($key) <= self::IDEMPOTENCY_KEY_MAX) {
            return $key;
        }

        return $prefix . substr(sha1($key), 0, self::IDEMPOTENCY_KEY_MAX - strlen($prefix));
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $chargeId = (string) $payment->getAdditionalInformation(self::CHARGE_ID);
        if ($chargeId === '') {
            throw new LocalizedException(__('This order has no Amazon Pay charge to refund.'));
        }
        /** @var Order $order */
        $order = $payment->getOrder();
        $refund = $this->api->createRefund(
            $chargeId,
            self::amount($order, (float) $amount * (float) ($order->getBaseToOrderRate() ?: 1)),
            RefundKey::for($payment, (float) $amount, 'zero-', self::IDEMPOTENCY_KEY_MAX),
            (int) $order->getStoreId()
        );
        $payment->setTransactionId((string) ($refund['refundId'] ?? $chargeId . '-refund'))->setIsTransactionClosed(true);

        return $this;
    }

    public function intent(?int $storeId): string
    {
        return $this->api->value('payment_intent', $storeId) === self::INTENT_AUTHORIZE ? self::INTENT_AUTHORIZE : self::INTENT_CAPTURE;
    }

    /**
     * The Create Checkout Session request: a one-time "pay now" checkout for
     * the order's total, the buyer's address from the order, the result
     * return URL. Amazon shows the amount and the address, the buyer picks
     * the payment instrument and confirms.
     *
     * @return array<string, mixed>
     */
    public static function payload(Order $order, string $returnUrl, string $storeId, string $intent, string $storeName): array
    {
        $payload = [
            'webCheckoutDetails' => [
                'checkoutResultReturnUrl' => $returnUrl,
                'checkoutMode' => 'ProcessOrder',
            ],
            'storeId' => $storeId,
            'chargePermissionType' => 'OneTime',
            'paymentDetails' => [
                'paymentIntent' => $intent,
                'canHandlePendingAuthorization' => false,
                'chargeAmount' => self::amount($order, (float) $order->getGrandTotal()),
                'presentmentCurrency' => (string) $order->getOrderCurrencyCode(),
            ],
            'merchantMetadata' => [
                'merchantReferenceId' => (string) $order->getIncrementId(),
                'merchantStoreName' => mb_substr($storeName !== '' ? $storeName : (string) $order->getStore()->getFrontendName(), 0, 50),
                'noteToBuyer' => mb_substr((string) __('Order #%1', $order->getIncrementId()), 0, 255),
            ],
        ];
        $address = $order->getIsVirtual() ? null : $order->getShippingAddress();
        if ($address) {
            $street = array_values(array_filter(array_map('trim', (array) $address->getStreet())));
            $payload['addressDetails'] = array_filter([
                'name' => trim($address->getFirstname() . ' ' . $address->getLastname()),
                'addressLine1' => $street[0] ?? '',
                'addressLine2' => $street[1] ?? '',
                'city' => (string) $address->getCity(),
                'stateOrRegion' => (string) ($address->getRegionCode() ?: $address->getRegion()),
                'postalCode' => (string) $address->getPostcode(),
                'countryCode' => (string) $address->getCountryId(),
                'phoneNumber' => (string) $address->getTelephone(),
            ], 'strlen');
        }

        return $payload;
    }

    /**
     * An amount in the order's currency, as Amazon Pay takes it.
     *
     * @return array{amount: string, currencyCode: string}
     */
    public static function amount(Order $order, float $amount): array
    {
        $currency = (string) $order->getOrderCurrencyCode();

        return [
            'amount' => MinorUnits::format($amount, $currency, MinorUnits::AMAZON),
            'currencyCode' => $currency,
        ];
    }
}
