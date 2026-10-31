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
use PHPUnit\Framework\TestCase;
use Zero\AmazonPay\Model\Api;
use Zero\AmazonPay\Model\Payment;

/**
 * An invoice on an authorized Amazon Pay order captures the charge with a
 * deterministic idempotency key (R8-9, B5): "zero-capture-<increment id>",
 * the same on every try, hashed into 32 characters when longer, so a capture
 * retried after a rollback is taken once by Amazon.
 *
 * The method is built by Magento's unit ObjectManager helper, whose generated
 * context mocks carry no expectations.
 */
#[AllowMockObjectsWithoutExpectations]
class PaymentCaptureTest extends TestCase
{
    private Payment $method;
    /** @var list<array<int, mixed>> every captureCharge call's arguments */
    private array $captures = [];
    private string $chargeState = 'Authorized';

    protected function setUp(): void
    {
        $this->captures = [];
        $this->chargeState = 'Authorized';
        $api = $this->createStub(Api::class);
        $api->method('getCharge')->willReturnCallback(fn (): array => ['statusDetails' => ['state' => $this->chargeState]]);
        $api->method('captureCharge')->willReturnCallback(function (...$args): array {
            $this->captures[] = $args;

            return ['chargeId' => 'S01-1-C'];
        });
        $objectManager = new ObjectManager($this);
        $objectManager->prepareObjectManager([[DirectoryHelper::class, $this->createStub(DirectoryHelper::class)]]);
        $this->method = $objectManager->getObject(Payment::class, ['api' => $api]);
    }

    /** @param list<string> $transactionIds collects every setTransactionId value */
    private function payment(string $incrementId, array &$transactionIds = []): OrderPaymentInfo
    {
        $order = $this->createStub(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getBaseToOrderRate')->willReturn(1.0);
        $order->method('getStoreId')->willReturn(3);
        $payment = $this->createStub(OrderPaymentInfo::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::CHARGE_ID, 'S01-1']]);
        $payment->method('setTransactionId')->willReturnCallback(function (string $id) use (&$transactionIds, $payment) {
            $transactionIds[] = $id;

            return $payment;
        });
        $payment->method('setIsTransactionClosed')->willReturnSelf();

        return $payment;
    }

    public function testCaptureSendsTheSameKeyOnEveryTry(): void
    {
        $ids = [];
        $this->method->capture($this->payment('000000123', $ids), 49.9);
        $this->method->capture($this->payment('000000123'), 49.9);

        $this->assertCount(2, $this->captures);
        $this->assertSame(
            ['S01-1', ['amount' => '49.90', 'currencyCode' => 'EUR'], 'zero-capture-000000123', 3],
            $this->captures[0]
        );
        $this->assertSame('zero-capture-000000123', $this->captures[1][2]);
        $this->assertSame(['S01-1-C'], $ids);
    }

    public function testALongIncrementIdIsHashedInto32CharactersAndStaysTheSame(): void
    {
        $increment = str_repeat('9', 20);
        $this->method->capture($this->payment($increment), 10.0);
        $this->method->capture($this->payment($increment), 10.0);

        $expected = 'zero-capture-' . substr(sha1('zero-capture-' . $increment), 0, 19);
        $this->assertSame(32, strlen($expected));
        $this->assertSame([$expected, $expected], [$this->captures[0][2], $this->captures[1][2]]);
    }

    public function testAnAlreadyCapturedChargeIsNotCapturedAgain(): void
    {
        $this->chargeState = 'Captured';
        $ids = [];

        $this->assertSame($this->method, $this->method->capture($this->payment('000000123', $ids), 49.9));
        $this->assertSame([], $this->captures);
        $this->assertSame([], $ids);
    }
}
