# AcountPay Payment Gateway for WooCommerce

Accept secure bank-to-bank payments on your WooCommerce store with AcountPay.

## Features

- Secure open-banking payments directly from customer bank accounts
- Supports both classic and block-based WooCommerce checkout
- Real-time payment status updates via webhooks
- Single live environment with Client ID authentication
- WooCommerce HPOS (High-Performance Order Storage) compatible
- Comprehensive logging for debugging

## Requirements

| Requirement | Minimum Version |
|-------------|----------------|
| WordPress   | 5.8            |
| WooCommerce | 8.0            |
| PHP         | 7.4            |

## Installation

1. [Download the latest ZIP](https://github.com/PaywithAcount/AP-Woo-Plugin/archive/refs/heads/main.zip) from this repository.
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Choose the downloaded ZIP file and click **Install Now**.
4. Click **Activate Plugin**.

> If uploading manually via FTP, place the plugin folder in `wp-content/plugins/` so the main file is at `wp-content/plugins/acountpay-payment/acountpay-payment.php`.

## Configuration

### 1. Get your Client ID

1. Log in to the [AcountPay Merchant Dashboard](https://merchant.acountpay.com).
2. Navigate to the **Developer** section.
3. Copy your **Client ID**.

### 2. Configure the gateway

1. Go to **WooCommerce > Settings > Payments**.
2. Find **AcountPay Payment Gateway** and click **Set up** (or **Manage**).
3. Configure:
   - **Enable/Disable** -- Check to enable the gateway.
   - **Client ID** -- Paste the Client ID from your Merchant Dashboard.
   - **API Base URL** -- Leave as default (`https://api.acountpay.com`).
   - **Title** -- Display name at checkout (e.g. "Pay with AcountPay").
   - **Description** -- Short text shown under the payment method.
4. Click **Save changes**.

AcountPay will now appear as a payment option at checkout.

## Testing

1. Add a product to the cart and go to **Checkout**.
2. Select **AcountPay** as the payment method and place the order.
3. You will be redirected to AcountPay to complete the payment, then returned to your store's order confirmation page.
4. Check **WooCommerce > Orders** to verify the order status updated to **Processing**.

Enable **Enable Logging** in the gateway settings and check **WooCommerce > Status > Logs** if you encounter issues.

## Going Live

The plugin uses the live AcountPay environment by default. Once your Client ID and API Base URL are set correctly, you are ready to accept payments.

## Documentation

For the full integration guide, API reference, and troubleshooting, visit the [AcountPay Documentation](https://docs.acountpay.com).

## Support

- Website: [acountpay.com](https://acountpay.com)
- Email: [team@acountpay.com](mailto:team@acountpay.com)

## License

This plugin is licensed under the [GPL-2.0](LICENSE).
