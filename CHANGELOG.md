# Changelog

All notable changes to the WooCommerce **Pay by Bank** (AcountPay) plugin are
documented in this file.

The plugin follows [Semantic Versioning](https://semver.org/) once it leaves
2.x. Until then, the major number tracks the AcountPay payment-link API
generation (v2 → 2.x).

## 2.1.0 — 2026-04-19

This is a security + payment-tracking + refund-workflow release. Existing
2.0.x installs can update in-place — no merchant action is required to keep
existing orders working, but two new optional settings are exposed.

### Added

- **Signed callback URLs.** The customer-facing return URL
  (`/wc-api/AcountPay_Payment_Gateway`) now carries an HMAC-SHA256 token
  derived from the order id, reference number, payment id, timestamp and the
  per-merchant webhook signing secret. Forged URLs (e.g. someone calling the
  callback URL directly with `?status=success`) are rejected.
- **Per-order metadata** persisted on the WooCommerce order: payment id,
  reference number, POS URL, currency, amount, idempotency key and last
  webhook timestamp. Stored via `update_meta_data()` so it works under HPOS.
  The Token.io payment id is also written to WooCommerce's "Transaction ID"
  field, so it appears in the standard order header in admin.
- **Idempotency key** sent with every payment-link request, so a double-click
  on Place Order doesn't create two separate AcountPay payment links for the
  same WooCommerce order.
- **Order admin meta box ("Pay by Bank")** on both legacy `shop_order` and
  HPOS order screens, showing payment id, reference, backend status, refunded
  amount, last webhook timestamp, a deep link to the POS URL and a
  one-click "Re-verify status" button (calls the AcountPay backend and
  updates the order without waiting for another webhook).
- **Order list column** with a small green/purple/red status pill mirroring
  the AcountPay backend status (paid / refunded / failed / pending). Renders
  on both legacy and HPOS order list tables.
- **Settings page polish.** New rows:
    - Read-only **Webhook URL** with a Copy button (the most asked-about
      integration value).
    - **Webhook health** indicator showing when the last webhook was
      received and the lifetime delivery count.
    - **Test connection** button that pings the configured API base URL and
      shows a green/red pill — same UX pattern as Stripe / TrueLayer.
    - **Order status when payment is confirmed** and **Order status when
      payment fails** dropdowns to map AcountPay states to WooCommerce
      statuses.
- **Customer-side polish.**
    - Non-SSL admin warning shown inside the payment method on checkout
      (admins only) — bank redirects refuse non-HTTPS origins.
    - "Awaiting bank confirmation" thank-you-page banner with an 8-second
      auto-refresh while the order is `pending` / `on-hold`, so customers
      see the status flip to Processing without a manual reload.
- **Refund tracking** via webhook. The plugin now recognises
  `payment.refunded` and `payment.partially_refunded` events from the
  AcountPay backend, writes an order note with the refunded amount, stores
  `_acountpay_refunded_amount` and transitions full refunds to WooCommerce's
  `refunded` status. Manual refunds initiated from the Merchant Dashboard
  flow back to WooCommerce automatically.
- **Cancel order button is hidden** on My Account → Orders for pending
  Pay-by-Bank orders, so customers can't race the webhook by clicking
  Cancel right as confirmation arrives.
- **Blocks checkout CSS** is now enqueued when the page contains
  `woocommerce/checkout` or `woocommerce/cart` blocks even before
  `is_checkout()` returns true.

### Changed

- **Webhook handler is now hardened.** Orders are resolved from the
  *signed body* (`paymentId` → `referenceNumber` → query order_id fallback)
  instead of trusting `?order_id=N` in the query string. The payload is
  cross-checked against the order's gateway, currency and amount; any
  mismatch puts the order on hold instead of marking it paid. Deliveries
  carrying an `eventId` are deduplicated for 24 hours so retried POSTs are
  safe.
- **Callback handler** treats the URL `status` parameter as a hint only —
  the source of truth is the backend verification call. The forced `sleep(2)`
  has been removed (the customer's bank sometimes takes much longer than 2s
  to settle, but the thank-you-page auto-refresh covers that gap properly).
  Failure now redirects to the checkout URL instead of the pay-for-order
  endpoint.
- **`receipt_page()`** reuses the previously-issued POS URL when it's still
  fresh (≤30 minutes), which avoids creating a brand-new payment link every
  time a customer reloads My Account → Orders → Pay.
- **Block payment-method class** is more defensive — it handles the case
  where WooCommerce hasn't fully booted the gateway list (returns sensible
  defaults instead of fatal-erroring).

### Refunds — operating model

WooCommerce's standard "Refund" button in the order screen does **not**
automatically push money back through the bank, because Token.io / open
banking does not currently expose a programmatic A2A refund. The supported
flow is:

1. Initiate the refund manually from your business bank account (or from the
   refund account details we surface for you in the AcountPay Merchant
   Dashboard).
2. Open the transaction in the Merchant Dashboard, click "Mark as
   refunded" (full or partial).
3. The AcountPay backend updates the payment, then fires a signed
   `payment.refunded` / `payment.partially_refunded` webhook to this
   plugin's webhook URL. The matching WooCommerce order automatically
   transitions to `refunded` (full) or has a refunded-amount note added
   (partial).

The order admin meta box surfaces a short note explaining this so support
staff don't try to refund through Woo and wonder why it doesn't move money.

## 2.0.0

Initial public release. Pay by Bank rebrand, configurable bank logo
carousel, info bubble, Danish + Finnish translations, optional
"skip desktop QR" setting, classic + Blocks checkout support.
