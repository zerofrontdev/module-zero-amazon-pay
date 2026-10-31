<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\AmazonPay\Test\Unit\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Store\Model\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\AmazonPay\Model\Payment;

class PaymentTest extends TestCase
{
    public function testThePayloadIsAPayNowSessionForTheOrder(): void
    {
        $order = $this->order('EUR', 123.4, false);
        $payload = Payment::payload($order, 'https://shop.test/zero_amazonpay/payment/return/?order=100', 'amzn1.application-oa2-client.x', Payment::INTENT_CAPTURE, 'Zero Store');

        self::assertSame('ProcessOrder', $payload['webCheckoutDetails']['checkoutMode']);
        self::assertSame('https://shop.test/zero_amazonpay/payment/return/?order=100', $payload['webCheckoutDetails']['checkoutResultReturnUrl']);
        self::assertSame('amzn1.application-oa2-client.x', $payload['storeId']);
        self::assertSame('OneTime', $payload['chargePermissionType']);
        self::assertSame(['amount' => '123.40', 'currencyCode' => 'EUR'], $payload['paymentDetails']['chargeAmount']);
        self::assertSame('AuthorizeWithCapture', $payload['paymentDetails']['paymentIntent']);
        self::assertSame('100', $payload['merchantMetadata']['merchantReferenceId']);
        self::assertSame('Zero Store', $payload['merchantMetadata']['merchantStoreName']);
        self::assertSame([
            'name' => 'Zero Tester',
            'addressLine1' => '123 Main St',
            'city' => 'Austin',
            'stateOrRegion' => 'TX',
            'postalCode' => '78701',
            'countryCode' => 'US',
            'phoneNumber' => '5125550100',
        ], $payload['addressDetails']);
    }

    public function testAVirtualOrderSendsNoAddressAndYenHaveNoDecimals(): void
    {
        $order = $this->order('JPY', 1500.0, true);
        $payload = Payment::payload($order, 'https://shop.test/return', 'store', Payment::INTENT_AUTHORIZE, '');

        self::assertArrayNotHasKey('addressDetails', $payload);
        self::assertSame(['amount' => '1500', 'currencyCode' => 'JPY'], $payload['paymentDetails']['chargeAmount']);
        self::assertSame('Authorize', $payload['paymentDetails']['paymentIntent']);
        self::assertSame('Main Store', $payload['merchantMetadata']['merchantStoreName']);
    }

    private function order(string $currency, float $total, bool $virtual): Order
    {
        $address = $this->createMock(Address::class);
        $address->method('getFirstname')->willReturn('Zero');
        $address->method('getLastname')->willReturn('Tester');
        $address->method('getStreet')->willReturn(['123 Main St', '']);
        $address->method('getCity')->willReturn('Austin');
        $address->method('getRegionCode')->willReturn('TX');
        $address->method('getPostcode')->willReturn('78701');
        $address->method('getCountryId')->willReturn('US');
        $address->method('getTelephone')->willReturn('5125550100');
        $store = $this->createMock(Store::class);
        $store->method('getFrontendName')->willReturn('Main Store');
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('100');
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        $order->method('getGrandTotal')->willReturn($total);
        $order->method('getIsVirtual')->willReturn($virtual);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getStore')->willReturn($store);

        return $order;
    }

    /**
     * An order is captured once: every try sends the same key (no time()),
     * and it stays within Amazon's 32 characters (R8-9).
     */
    public function testCaptureKeyIsDeterministicAndBoundTo32Characters(): void
    {
        $this->assertSame('zero-capture-000000123', Payment::captureKey('000000123'));
        $this->assertSame(Payment::captureKey('000000123'), Payment::captureKey('000000123'));
        $this->assertSame('zero-capture-' . str_repeat('9', 19), Payment::captureKey(str_repeat('9', 19)));

        $long = Payment::captureKey(str_repeat('9', 20));
        $this->assertSame(32, strlen($long));
        $this->assertSame('zero-capture-' . substr(sha1('zero-capture-' . str_repeat('9', 20)), 0, 19), $long);
        $this->assertSame($long, Payment::captureKey(str_repeat('9', 20)));
        $this->assertNotSame($long, Payment::captureKey(str_repeat('9', 21)));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,32}$/', Payment::captureKey(str_repeat('x', 80)));
    }

    #[DataProvider('amounts')]
    public function testAmountUnchanged(string $currency, float $amount, string $expected): void
    {
        $order = $this->createStub(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn($currency);

        $this->assertSame(['amount' => $expected, 'currencyCode' => $currency], Payment::amount($order, $amount));
    }

    /** @return array<string, array{string, float, string}> */
    public static function amounts(): array
    {
        return [
            'EUR' => ['EUR', 10.5, '10.50'],
            'USD' => ['USD', 0.1, '0.10'],
            'GBP rounded' => ['GBP', 19.999, '20.00'],
            'JPY whole' => ['JPY', 1000.0, '1000'],
            'JPY rounded' => ['JPY', 1499.6, '1500'],
        ];
    }
}
