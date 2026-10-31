# Changelog: zerofront/module-zero-amazon-pay

## 1.3.6

- Returns settle through Zero Checkout's new flow, under the order's lock.
- An order is captured once: every try of the capture uses the same idempotency key.
- A payment for an order already cancelled is flagged, never booked.

Earlier releases (1.3.5 and before): see the Git tags.
