# Changelog

All notable changes to the WooCommerce **Pay by Bank** (AcountPay) plugin are
documented in this file.

The plugin follows [Semantic Versioning](https://semver.org/) once it leaves
2.x. Until then, the major number tracks the AcountPay payment-link API
generation (v2 → 2.x).

## 2.1.4 — 2026-04-19

### Added

- **Configurable payment reference (PSU bank-statement text).** Two new
  settings under *WooCommerce → Settings → Payments → Pay by Bank*:
  - **Payment reference (shown on customer's bank statement)** — a free-text
    template merged with placeholders `{order_number}`, `{order_id}`,
    `{site_title}`, `{first_name}`, `{last_name}`. Default
    `{site_title} #{order_number}` (e.g. `Acme Shop #1234`). This text is
    sent as `referenceNumber` on `POST /v1/sdk/v2/payment-link`, forwarded
    to Token.io as `displayReference`, and ends up as
    `remittanceInformationPrimary` — i.e. **the line the customer reads on
    their bank statement** next to the transaction. Previously the plugin
    only sent the bare Woo order number, so customers saw a meaningless
    integer and couldn't recognise the charge.
  - **Payment reference max length** — number input (6–35, default 18).
    The plugin pre-truncates the rendered template to this many characters
    before sending it upstream, so a long template doesn't get silently cut
    by the bank into something nonsensical. Recommended caps:
    18 (works on every supported FI/DK bank), 25 (Danske Bank ceiling),
    35 (Aktia / OP Pohjola / S-Pankki / Ålandsbanken / Wise).
- The rendered reference is sanitized to the conservative ASCII subset
  `[A-Za-z0-9 #\-_/.]` before being sent so it survives every bank's
  per-rail charset filter without surprise truncation. The backend's
  `payment-rails.config.ts` still enforces per-bank limits as a second line
  of defence.
- New filter `woocommerce_acountpay_payment_reference($rendered, $order, $gateway_id)`
  for sites that need to override the rendered text in code.
- Reference is persisted to `_acountpay_reference_number` order meta on
  first render so retries from *My Account → Pay* use the *same* statement
  text as the original attempt, keeping reconciliation stable.

## 2.1.3 — 2026-04-19

### Fixed

- **Bank-logo carousel now uses the merchant's configured `api_base_url`.**
  `get_supported_banks()` and `get_bank_logo_urls()` previously instantiated
  `AcountPay_API()` with no constructor arguments, which silently pinned every
  public-bank lookup to the hardcoded production URL. Merchants on sandbox /
  staging / ngrok hosts therefore got no live data and the carousel fell back
  to the bundled placeholder SVGs (which look like text). The two methods now
  reuse the gateway's already-configured `$this->api` instance via a new
  `get_api_for_banks()` helper.
- **Auto-flush the bank-list transient on plugin upgrade.** Existing installs
  that picked up a build with the un-versioned `/banks/public/logos` path
  cached an empty result for up to 7 days and could not refresh the carousel
  without manually clicking *Refresh bank list*. The plugin now compares the
  stored version against the live constant on `plugins_loaded` and flushes
  every cached country list (FI, DK, SE, NO, EE, LT, LV) whenever they
  differ — so simply pulling 2.1.3 self-heals stale caches.
- **Cache busts when `api_base_url` changes** in settings, not only when the
  country changes. Previously a merchant flipping from prod → sandbox would
  keep seeing the old environment's logos until the 24h fresh cache expired.

## 2.1.2 — 2026-04-19

### Fixed

- **Test connection** button now hits the public, no-auth
  `/v1/banks/public/logos?country=FI` endpoint instead of the legacy
  `/v1/sdk/v1/banks` (which never existed and always returned 404 — every
  click previously surfaced a red "Request failed" pill).
- **Refresh bank list** button + checkout carousel auto-load now hit
  `/v1/banks/public/logos` instead of `/banks/public/logos`. AcountBackend
  uses Nest URI versioning (`defaultVersion: '1'`) so every controller
  route is served under `/v1/`. Without the prefix the request fell
  through to a generic 404 and the merchant got an empty bank list.
- **Webhook URL hint text** in settings no longer instructs merchants to
  paste the URL into a "Developer → Webhook URL" field on the AcountPay
  dashboard. There is no such field — AcountPay reads the webhook URL
  from each `POST /v1/sdk/v2/payment-link` request the plugin makes, so
  the field in plugin settings is purely for sanity-checking and copy/
  paste into support tickets.

## 2.1.1 — 2026-04-19

### Added

- **Live bank logos.** The bank-logo carousel on the checkout button and the
  bank-logos multiselect in settings are now driven by the new public
  `GET /banks/public/logos?country=FI` endpoint on AcountPay, so merchants
  always see the latest, on-brand CDN logos (`d5cm9vkx3vulc.cloudfront.net/…`)
  without shipping a plugin update. Results are cached as a WordPress
  transient for 24 h with a 7-day stale fallback so checkout never breaks if
  the API is briefly unreachable.
- **Country setting.** New "Bank country" select in the gateway settings
  (currently FI / DK) drives which country's banks the carousel and the
  multiselect populate from. Saving a new country clears the cached list.
- **Refresh bank list button.** New admin AJAX endpoint
  (`acountpay_refresh_banks`) backed by a button in settings that drops the
  transient and re-pulls the bank list immediately — useful right after a
  new bank is enabled in AcountPay.
- **Bundled SVG fallback.** The shipped `assets/images/banks/*.svg` files are
  now treated as offline fallbacks and only used when the live API doesn't
  return a logo URL for that bankId.

### Changed

- `get_supported_banks()` and `get_bank_logo_urls()` now key on AcountPay
  / Token.io `bankId` (`ngp-okoy`, `ngp-ndeafi`, `ob-aktia`, …). Settings
  saved with the previous human-readable slugs (`op`, `nordea`, …) are
  migrated on the fly via an alias map in `get_bundled_banks_fallback()`,
  so no merchant action is required.

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
