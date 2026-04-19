<?php

/**
 * Plugin Name: WooCommerce AcountPay Payment Gateway
 * Plugin URI:  https://acountpay.com
 * Author:      AcountPay
 * Author URI:  https://acountpay.com
 * Description: Pay by Bank for WooCommerce, powered by AcountPay. Lets shoppers pay directly from their bank account via PSD2 / open banking, with a configurable bank-logo carousel, classic + block checkout support, signed callbacks, signed server-to-server webhooks, an order-edit panel showing payment id and PSU lookup state, and a manual-refund flow driven from the AcountPay Merchant Dashboard.
 * Version:     2.1.0
 * Requires at least: 5.8
 * Tested up to: 6.9.1
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.2
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: acountpay-payment
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}

//define the plugin constants
define('ACOUNTPAY_PAYMENT_VERSION', '2.1.0');
define('ACOUNTPAY_PAYMENT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ACOUNTPAY_PAYMENT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ACOUNTPAY_TEXT_DOMAIN', 'acountpay-payment');
//acountpay file
define('ACOUNTPAY_PAYMENT_FILE', __FILE__);

//check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    //add notice
    add_action('admin_notices', 'acountpay_payment_woocommerce_notice');
} else {
    // Declare HPOS compatibility
    add_action(
        'before_woocommerce_init',
        function () {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        }
    );
    //add action plugins loaded
    add_action('plugins_loaded', 'acountpay_payment_init');
    //add settings url
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'acountpay_payment_settings_link');
    //register woocommerce payment gateway
    add_filter('woocommerce_payment_gateways', 'acountpay_payment_gateway');
    //woocommerce_blocks_loaded
    add_action('woocommerce_blocks_loaded', 'acountpay_payment_block_support');
}

/**
 * acountpay_payment_block_support
 * 
 */
function acountpay_payment_block_support()
{
    //check for AbstractPaymentMethodType class
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        //include the AcountPayBlockPaymentMethod class
        include_once ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/includes/class-acountpay-block-payment-method.php';

        // registering our block support class
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                $payment_method_registry->register(new WC_AcountPay_Payment_Gateway_Block_Support);
            }
        );
    }
}


//initialize the plugin
function acountpay_payment_init()
{
    // Load translations from /languages so visible strings (e.g. "Pay by Bank")
    // render in the shopper's locale when da_DK / fi .mo files are present.
    load_plugin_textdomain(
        'acountpay-payment',
        false,
        dirname(plugin_basename(ACOUNTPAY_PAYMENT_FILE)) . '/languages'
    );

    //check if the class exists AcountPay_Payment_Gateway
    if (!class_exists('AcountPay_Payment_Gateway')) {
        // Include API class first
        include_once ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/includes/class-acountpay-api.php';
        // Then include main gateway class
        include_once ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/includes/main-file.php';
    }
}

//acountpay_payment_woocommerce_notice
function acountpay_payment_woocommerce_notice()
{
    ob_start();
    //require the admin notice template
    require_once ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/templates/admin_notice.php';
    $html = ob_get_clean();
    echo wp_kses_post($html);
}

//acountpay_payment_settings_link
function acountpay_payment_settings_link($links)
{
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=acountpay_payment">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}

//acountpay_payment_gateway
function acountpay_payment_gateway($gateways)
{
    $gateways[] = 'AcountPay_Payment_Gateway';
    return $gateways;
}
