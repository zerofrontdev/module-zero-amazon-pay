<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Test\Unit\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\AmazonPay\Model\Api;
use Zero\AmazonPay\Model\Payment;

/**
 * The Amazon Pay refund id (A3-22 = A4-9) comes from RefundKey: the order's
 * refunded total before this refund plus its amount, both in base minor
 * units. A credit memo retried after a rollback sends the same id (the
 * provider refunds once); the next partial refund gets a new one. Ids fit
 * Amazon Pay's limit of 32 characters, longer ones become a hash.
 *
 * The method is built by Magento's unit ObjectManager helper, whose generated
 * context mocks carry no expectations.
 */
#[AllowMockObjectsWithoutExpectations]
class PaymentRefundTest extends TestCase
{
    private Api&MockObject $api;
    private Payment $method;
    /** @var list<string> the id of every refund sent */
    private array $ids = [];
    /** @var list<array<int, mixed>> every refund call's arguments */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->ids = [];
        $this->calls = [];
        $this->api = $this->createMock(Api::class);
        $this->api->method('createRefund')->willReturnCallback(function (...$args): array {
            $this->calls[] = $args;
            $this->ids[] = $args[2];

            return [];
        });
        $objectManager = new ObjectManager($this);
        $objectManager->prepareObjectManager([[DirectoryHelper::class, $this->createStub(DirectoryHelper::class)]]);
        $this->method = $objectManager->getObject(Payment::class, ['api' => $this->api]);
    }

    private function payment(float $refundedBefore, string $incrementId = '000000123'): OrderPaymentInfo
    {
        $order = $this->createStub(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getBaseCurrencyCode')->willReturn('EUR');
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getBaseToOrderRate')->willReturn(1.0);
        $order->method('getStoreId')->willReturn(1);
        $payment = $this->createStub(OrderPaymentInfo::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::CHARGE_ID, 'S01-1']]);
        $payment->method('getBaseAmountRefunded')->willReturn($refundedBefore);
        $payment->method('setTransactionId')->willReturnSelf();
        $payment->method('setParentTransactionId')->willReturnSelf();
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        return $payment;
    }

    public function testRefundIdIsTheSameOnRetry(): void
    {
        $this->method->refund($this->payment(0.0), 25.5);
        $this->method->refund($this->payment(0.0), 25.5);

        $this->assertSame(['zero-000000123-r0-2550', 'zero-000000123-r0-2550'], $this->ids);
        $this->assertSame(['S01-1', ['amount' => '25.50', 'currencyCode' => 'EUR']], [$this->calls[0][0], $this->calls[0][1]]);
    }

    public function testSecondPartialRefundGetsANewId(): void
    {
        $this->method->refund($this->payment(0.0), 25.5);
        $this->method->refund($this->payment(25.5), 25.5);
        $this->method->refund($this->payment(25.5), 10.0);

        $this->assertSame(
            ['zero-000000123-r0-2550', 'zero-000000123-r2550-2550', 'zero-000000123-r2550-1000'],
            $this->ids
        );
    }

    public function testALongIdIsHashedWithinTheLimitAndStaysTheSame(): void
    {
        $increment = str_repeat('9', 60);
        $this->method->refund($this->payment(0.0, $increment), 25.5);
        $this->method->refund($this->payment(0.0, $increment), 25.5);
        $this->method->refund($this->payment(25.5, $increment), 25.5);

        $this->assertLessThanOrEqual(32, strlen($this->ids[0]));
        $this->assertMatchesRegularExpression('/^zero\-[0-9a-f]+$/', $this->ids[0]);
        $this->assertSame($this->ids[0], $this->ids[1]);
        $this->assertNotSame($this->ids[0], $this->ids[2]);
    }
}
