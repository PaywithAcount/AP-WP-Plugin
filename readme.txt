=== AcountPay Payment Gateway ===
Contributors: acountpay
Tags: woocommerce, payment, payment-gateway, acountpay, bank-payment, checkout
Requires at least: 5.8
Tested up to: 6.9.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Easily integrate and accept secure online payments on your WooCommerce store using the AcountPay Payment Gateway.

== Description ==

AcountPay Payment Gateway for WooCommerce enables seamless bank payments and provides a user-friendly checkout experience. This plugin allows your customers to pay directly from their bank accounts through a secure payment gateway.

= Key Features =

* Secure bank-to-bank payments
* Support for multiple banks
* Compatible with both classic and block-based WooCommerce checkout
* Real-time payment status updates
* Webhook handling for payment confirmations
* Single live environment support
* Comprehensive logging for debugging
* SSL certificate verification for secure API communication
* Responsive design for mobile and desktop
* Customizable payment settings

= Requirements =

* WooCommerce 5.0 or higher
* WordPress 5.8 or higher
* PHP 7.4 or higher
* Valid AcountPay API credentials

= Installation =

1. Upload the plugin files to the `/wp-content/plugins/acountpay-payment` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to WooCommerce > Settings > Payments and configure the AcountPay Payment Gateway.
4. Enter your Client ID from the AcountPay Merchant Dashboard (merchant.acountpay.com).
5. Save changes and test the payment gateway.

= Configuration =

After activation, navigate to **WooCommerce > Settings > Payments > AcountPay Payment Gateway** to configure:

* **Enable/Disable**: Turn the payment gateway on or off
* **Title**: Customize the payment method title shown to customers
* **Description**: Add a description for the payment method
* **Client ID**: Your Client ID from the AcountPay Merchant Dashboard (merchant.acountpay.com)
* **API Base URL**: The base URL for the AcountPay API (default: https://api.acountpay.com)
* **Enable Logging**: Turn on detailed logging for debugging
* **SSL Verification**: Enable SSL certificate verification (recommended for production)
* **Redirect URL**: Custom URL for redirecting after payment (optional)

= Frequently Asked Questions =

= How do I get my API keys? =

Log in to the AcountPay Merchant Dashboard at merchant.acountpay.com and go to the Developer section to find your Client ID.

= Can I use this plugin in test mode? =

No. The current plugin version uses a single live environment. Use a dedicated test store and low-value orders for integration verification.

= Does this plugin support subscriptions? =

Currently, the plugin supports one-time payments. Subscription support may be added in future versions.

= Is SSL verification required? =

SSL verification is highly recommended for production environments to ensure secure communication with the payment gateway. You can disable it only for development/testing purposes.

= How do I enable logging? =

Go to the payment gateway settings and check the "Enable Logging" option. Logs will be available in WooCommerce > Status > Logs.

= Changelog =

= 2.0.0 =
* Extension flow: pay with Client ID only (no API key required)
* Redirect to AcountPay to select bank; callback return after payment
* Single Client ID setting for live environment

= 1.0.0 =
* Initial release
* Support for classic and block-based checkout
* Bank selection functionality
* Payment intent creation
* Webhook handling
* SSL verification support
* Comprehensive logging

== Upgrade Notice ==

= 2.0.0 =
Extension flow: Client ID only, redirect to AcountPay for bank selection. Update your settings to use the new Client ID field.

= 1.0.0 =
Initial release of AcountPay Payment Gateway for WooCommerce.

== Screenshots ==

1. Payment gateway settings page
2. Bank selection on checkout
3. Payment processing flow
4. Order confirmation page

== Support ==

For support, please visit https://acountpay.com or contact AcountPay support.

== Credits ==

Developed by AcountPay team.
