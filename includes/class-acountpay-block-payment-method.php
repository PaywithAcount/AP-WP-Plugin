<?php
//check for security

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\StoreApi\Payments\PaymentContext;
use Automattic\WooCommerce\StoreApi\Payments\PaymentResult;

if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}

final class WC_AcountPay_Payment_Gateway_Block_Support extends AbstractPaymentMethodType
{
    /**
     * Payment method name
     * 
     */
    protected $name = 'acountpay_payment';

    /**
     * Initialize the payment method type
     * 
     */
    public function initialize()
    {
        $this->settings = get_option('woocommerce_acountpay_payment_settings', array());

        //add failure message
        add_action('woocommerce_rest_checkout_process_payment_with_context', array($this, 'add_failure_message'), 10, 2);
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active()
    {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return false;
        }
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        if (empty($payment_gateways['acountpay_payment'])) {
            return false;
        }
        return $payment_gateways['acountpay_payment']->is_available();
    }

    /**
     * Add failed payment notice to the payment details.
     *
     * @param PaymentContext $context Holds context for the payment.
     * @param PaymentResult  $result  Result object for the payment.
     */
    public function add_failure_message(PaymentContext $context, PaymentResult &$result)
    {
        if ('acountpay_payment' === $context->payment_method) {
            add_action(
                'wc_gateway_acountpay_payment_process_payment_error',
                function ($failed_notice) use (&$result) {
                    $payment_details                 = $result->payment_details;
                    $payment_details['errorMessage'] = wp_strip_all_tags($failed_notice);
                    $result->set_payment_details($payment_details);
                }
            );
        }
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        $payment_gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway          = isset($payment_gateways['acountpay_payment']) ? $payment_gateways['acountpay_payment'] : null;
        if (!$gateway) {
            return array(
                'title'             => $this->get_setting('title', 'Pay by Bank'),
                'description'       => $this->get_setting('description', ''),
                'supports'          => array(),
                'allow_saved_cards' => false,
                'bank_logos'        => array(),
                'info_bubble'       => array('enabled' => false),
            );
        }

        $info_enabled = 'yes' === $gateway->get_option('show_info_bubble', 'yes');

        return array(
            'title'             => $this->get_setting('title'),
            'description'       => $this->get_setting('description'),
            'supports'          => array_filter($gateway->supports, array($gateway, 'supports')),
            'allow_saved_cards' => $gateway->saved_cards,
            'bank_logos'        => $gateway->get_bank_logo_urls(),
            'info_bubble'       => array(
                'enabled' => $info_enabled,
                'heading' => $gateway->get_info_heading(),
                'steps'   => $gateway->get_info_steps(),
            ),
        );
    }


    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles()
    {
        $asset_path   = plugin_dir_path(ACOUNTPAY_PAYMENT_FILE) . 'assets/js/block/block.asset.php';
        $version      = null;
        $dependencies = array();
        if (file_exists($asset_path)) {
            $asset        = require $asset_path;
            $version      = isset($asset['version']) ? $asset['version'] : $version;
            $dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
        }

        wp_register_script(
            'wc-acountpay-payment-blocks-integration',
            plugin_dir_url(ACOUNTPAY_PAYMENT_FILE) . 'assets/js/block/block.js',
            $dependencies,
            $version,
            true
        );

        // Settings (title, description, bank_logos, info_bubble) are exposed to the
        // block via getSetting('acountpay_payment_data') from get_payment_method_data().

        return array('wc-acountpay-payment-blocks-integration');
    }
}
