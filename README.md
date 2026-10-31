# Zero_AmazonPay

Amazon Pay for Zero Checkout: the customer places the order, pays on
Amazon's page with the card or bank account in their Amazon account, and
comes back. Amazon Pay Checkout v2 through Amazon's own SDK
(`amzn/amazon-pay-api-sdk-php`, which signs every request); no Amazon
script on the shop's pages.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-amazon-pay
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## How a payment goes

1. **Placing the order** creates an Amazon Pay checkout session for the
   order's grand total in the order's currency: a one-time "pay now"
   session (`checkoutMode: ProcessOrder`) with the order's shipping address,
   so Amazon shows the amount and the address and only asks the buyer to
   pick the payment instrument and confirm. The order stays **pending
   payment**, and Zero Checkout sends the customer to Amazon's page
   (`Placeorder::REDIRECT_URL`).
2. **The customer comes back** (`zero_amazonpay/payment/return?order=…`,
   with Amazon's `amazonCheckoutSessionId`). The session is read from Amazon
   and, when the buyer confirmed it, completed for the order's amount —
   that creates the charge:
   - Authorize and Capture (default): captured, invoice registered, order
     email;
   - Authorize: reserved, the order is processing; the invoice captures it;
   - declined by Amazon, or the buyer came back without confirming: the
     order cancelled, the cart restored.
3. A charge for another amount or currency than the order's leaves the
   order pending with a comment.

**Refunds:** a credit memo from the invoice refunds through the charge
(`createRefund`), fully or in part.

Amazon's IPN (notifications over SNS) is not used: the return settles the
order, and an order left pending (the customer closed the browser on
Amazon's page) stays pending payment, as with the other redirect methods.

## Configuration

Stores > Configuration > Sales > Payment Methods > **Amazon Pay (Zero)**:
Enabled, Title, Region (Europe/UK, North America, Japan), Sandbox, Merchant
ID, Store ID, Public Key ID, Private Key (stored encrypted), Payment Action,
countries, sort order.

The keys come from Seller Central > Integration Central: register the site
as an application (its Store ID), create a key pair (the Public Key ID, and
the private key file to paste). The method shows in checkout only when the
merchant ID, store ID and both keys are set.

API-created checkout sessions ("Additional Payment Button" / pay now) must
be enabled for the merchant account; Amazon Pay support enables it on
request. Supported currencies: EUR, GBP (eu), USD (na), JPY (jp); the
order's currency must be one of its region's.

## Tests

- `Test/Unit/Model/PaymentTest.php`: the checkout session request and the
  amounts.
- The checkout e2e (`checkout.spec.ts`) offers the method only where it is
  configured; a sandbox run needs Amazon's test buyer.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
