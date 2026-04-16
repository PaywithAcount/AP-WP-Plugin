<?php
//security check

use WpOrg\Requests\Requests;

if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}

/**
 * AcountPay Payment Gateway
 * 
 * @author AcountPay
 * @since 1.0.0
 */
class AcountPay_Payment_Gateway extends WC_Payment_Gateway_CC
{
    /**
     * Title
     * 
     */
    public $title;

    /**
     * Description
     * 
     */
    public $description;

    /**
     * API instance
     * 
     * @var AcountPay_API
     */
    private $api;

    /**
     * saved_cards
     * 
     */
    public $saved_cards;

    /**
     * remove_cancel_order_button
     * 
     */
    public $remove_cancel_order_button;

    /**
     * Logging enabled
     * 
     * @var string
     */
    public $logging;

    /**
     * SSL Verification enabled
     * 
     * @var string
     */
    public $sslverify;

    /**
     * Constructor
     * 
     * @since 1.0.0
     */
    public function __construct()
    {
        //id
        $this->id = 'acountpay_payment';
        //has fields
        $this->has_fields = true;
        //method title
        $this->method_title = __('AcountPay Payment Gateway', ACOUNTPAY_TEXT_DOMAIN);
        //description
        $this->method_description = __('This plugin allows you to accept payment on your website using AcountPay Payment Gateway.', ACOUNTPAY_TEXT_DOMAIN);
        //icon
        $this->icon = $this->get_logo_url();
        //supports
        $this->supports = array(
            'products'
            // 'tokenization',
            // 'subscriptions',
            // 'subscription_cancellation',
            // 'subscription_suspension',
            // 'subscription_reactivation',
            // 'subscription_amount_changes',
            // 'subscription_date_changes',
            // 'subscription_payment_method_change',
            // 'subscription_payment_method_change_customer',
            // 'subscription_payment_method_change_admin',
            // 'multiple_subscriptions',
        );
        //Add form fields
        $this->init_form_fields();

        ///// Form Fields //////////////////
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        //saved_cards
        $this->saved_cards = false;

        // Logging
        $this->logging = $this->get_option('logging', 'no');

        // SSL Verification
        $this->sslverify = $this->get_option('sslverify', 'yes');

        // Initialize API handler (v2 flow uses Client ID only)
        $api_base_url = $this->get_option('api_base_url', 'https://api.acountpay.com');
        $logging_enabled = $this->logging === 'yes';
        $sslverify_enabled = $this->sslverify === 'yes';
        $this->api = new AcountPay_API($api_base_url, $logging_enabled, $sslverify_enabled);

        //process admin options
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        //register styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        //admin script
        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
        //woocommerce available payment gateways
        add_action('woocommerce_available_payment_gateways', [$this, 'available_payment_gateways']);
        //register receipt page
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
        //register api endpoint
        add_action('woocommerce_api_acountpay_payment_gateway', [$this, 'acountpay_payment_callback']);
        //register webhook endpoint for server-to-server payment notifications
        add_action('woocommerce_api_acountpay_webhook', [$this, 'handle_webhook']);
        //admin notice when the site URL is not publicly reachable (local/docker testing)
        add_action('admin_notices', [$this, 'maybe_render_unreachable_host_notice']);

        //is valid for use
        if (!$this->is_valid_for_use()) {
            //disable the gateway because the current currency is not supported
            $this->enabled = 'no';
        }
    }

    /**
     * Get AcountPay payment icon URL.
     */
    public function get_logo_url()
    {
        $url = WC_HTTPS::force_https_url(ACOUNTPAY_PAYMENT_PLUGIN_URL . '/assets/images/logo.jpg');
        return apply_filters('woocommerce_acountpay_payment_icon', $url, $this->id);
    }

    /**
     * Filter gateway title output for better styling
     */
    public function get_title()
    {
        $title = parent::get_title();
        $logo_url = $this->get_logo_url();
        
        if ($logo_url) {
            return '<span class="acountpay-payment-label"><img src="' . esc_url($logo_url) . '" alt="' . esc_attr($title) . '" class="acountpay-payment-logo" /><span class="acountpay-payment-title">' . $title . '</span></span>';
        }
        
        return $title;
    }

    /**
     * available_payment_gateways
     * 
     */
    public function available_payment_gateways($available_gateways)
    {
        if (!$this->is_available()) {
            //unset the gateway
            unset($available_gateways[$this->id]);
        }

        return $available_gateways;
    }

    /**
     * is available
     *
     * Chain into WooCommerce's own checks (enabled + currency + min/max + etc.) so we
     * don't accidentally skip store-level gating that merchants rely on.
     */
    public function is_available()
    {
        if ($this->enabled !== 'yes') {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Enqueue styles
     * 
     * @since 1.0.0
     */
    public function enqueue_styles()
    {
        // Only load on checkout and order-pay pages
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }

        // Check if gateway is enabled
        if ($this->enabled === 'no') {
            return;
        }

        wp_enqueue_style(
            'acountpay-checkout-style',
            ACOUNTPAY_PAYMENT_PLUGIN_URL . 'assets/css/acountpay-checkout.css',
            array(),
            ACOUNTPAY_PAYMENT_VERSION
        );
    }

    /**
     * Admin scripts
     * 
     * @since 1.0.0
     */
    public function admin_scripts()
    {
        wp_enqueue_script('acountpay-admin-script', ACOUNTPAY_PAYMENT_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], ACOUNTPAY_PAYMENT_VERSION, true);
    }

    /**
     * Checkout scripts (v2: no client-side scripts needed, payment is handled via POS redirect)
     */
    public function checkout_scripts()
    {
        // v2 flow: receipt page redirects to POS for bank selection, no client-side JS needed
    }

    /**
     * Initialize form fields
     * 
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $form_fields = apply_filters('woo_acountpay_payment', [
            'enabled' => [
                'title' => __('Enable/Disable', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable AcountPay Payment Gateway', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'no'
            ],
            'title' => [
                'title' => __('Title', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => __('AcountPay Payment Gateway', ACOUNTPAY_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'description' => [
                'title' => __('Description', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => __('Pay securely using your bank account via AcountPay Payment Gateway.', ACOUNTPAY_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'client_id' => [
                'title' => __('Client ID', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('Get your Client ID from the AcountPay Merchant Dashboard → Developer.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => '',
                'desc_tip' => true
            ],
            //api base url
            'api_base_url' => [
                'title' => __('API Base URL', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('The base URL for AcountPay API. Leave as default unless instructed otherwise.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'https://api.acountpay.com',
                'desc_tip' => true
            ],
            //logging
            'logging' => [
                'title' => __('Enable Logging', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable logging for debugging', ACOUNTPAY_TEXT_DOMAIN),
                'description' => __('Log AcountPay events, such as API requests and responses, to WooCommerce logs.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'no',
                'desc_tip' => true
            ],
            //ssl verification
            'sslverify' => [
                'title' => __('SSL Verification', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable SSL certificate verification', ACOUNTPAY_TEXT_DOMAIN),
                'description' => __('Verify SSL certificates when making API requests. Recommended for production environments.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'yes',
                'desc_tip' => true
            ],
            'webhook_signing_secret' => [
                'title' => __('Webhook signing secret', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'password',
                'description' => __('Must match MERCHANT_WEBHOOK_SECRET (or JWT fallback) on the AcountPay API. When set, server-to-server webhooks are verified with HMAC-SHA256 before updating orders. Leave empty to skip verification (not recommended in production).', ACOUNTPAY_TEXT_DOMAIN),
                'default' => '',
                'desc_tip' => true,
            ],
        ]);
        //return form fields to woocommerce
        $this->form_fields = $form_fields;
    }

    /**
     * Persist settings; keep webhook signing secret when the password field is left blank on save.
     */
    public function process_admin_options()
    {
        $post_key = 'woocommerce_' . $this->id . '_webhook_signing_secret';
        $posted_empty = !isset($_POST[$post_key]) || $_POST[$post_key] === '';
        if ($posted_empty) {
            unset($_POST[$post_key]);
        }
        parent::process_admin_options();
    }

    /**
     * Receipt page
     * 
     * @param int $order_id
     */
    public function receipt_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', ACOUNTPAY_TEXT_DOMAIN) . '</p>';
            return;
        }

        $client_id = trim($this->get_option('client_id', ''));
        if (empty($client_id) || !isset($this->api) || !$this->api) {
            echo '<p>' . esc_html__('Payment gateway is not configured. Please contact the store administrator.', ACOUNTPAY_TEXT_DOMAIN) . '</p>';
            return;
        }

        $amount = floatval($order->get_total());
        if ($amount <= 0) {
            echo '<p>' . esc_html__('Order amount must be greater than zero.', ACOUNTPAY_TEXT_DOMAIN) . '</p>';
            return;
        }

        $currency = strtoupper($order->get_currency());
        $callback_url = add_query_arg('order_id', $order_id, WC()->api_request_url('AcountPay_Payment_Gateway'));
        $webhook_url = add_query_arg('order_id', $order_id, WC()->api_request_url('acountpay_webhook'));

        $payment_data = array(
            'clientId'        => $client_id,
            'amount'          => $amount,
            'referenceNumber' => (string) $order->get_order_number(),
            'redirectUrl'     => $callback_url,
            'webhookUrl'      => $webhook_url,
            'description'     => sprintf(__('Payment for order #%s', ACOUNTPAY_TEXT_DOMAIN), $order->get_order_number()),
            'currency'        => $currency,
        );

        $this->log_info('receipt_page: Creating v2 payment link for retry', array('order_id' => $order_id));
        $response = $this->api->create_payment_link_v2($payment_data);

        if (is_wp_error($response) || !is_array($response)) {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : 'Invalid response';
            $this->log_error('receipt_page: v2 payment link failed', array('order_id' => $order_id, 'error' => $error_msg));
            echo '<p>' . esc_html__('Unable to start payment. Please try again.', ACOUNTPAY_TEXT_DOMAIN) . '</p>';
            return;
        }

        $redirect_url = isset($response['redirectUrl']) ? $response['redirectUrl'] : (isset($response['authorizationUrl']) ? $response['authorizationUrl'] : '');
        if (!empty($redirect_url) && is_string($redirect_url) && preg_match('#^https?://#i', $redirect_url)) {
            $this->log_info('receipt_page: Redirecting to POS via v2', array('order_id' => $order_id));
            wp_redirect($redirect_url);
            exit;
        }

        $this->log_error('receipt_page: No valid redirect URL in response', array('order_id' => $order_id, 'response' => $response));
        echo '<p>' . esc_html__('Unable to start payment. Please try again.', ACOUNTPAY_TEXT_DOMAIN) . '</p>';
    }

    /**
     * Is valid for use
     * 
     */
    public function is_valid_for_use()
    {
        // AcountPay supports multiple currencies
        // Currency validation is handled by the API
            return true;
    }

    /**
     * Show an admin notice when this gateway is enabled but the WordPress site URL is
     * not publicly reachable (localhost, 127.x.x.x, *.local, *.test, *.localhost). In
     * that environment the POS can't redirect customers back and the AcountPay backend
     * can't deliver webhooks, so payments will silently look "received" without ever
     * being confirmed.
     */
    public function maybe_render_unreachable_host_notice()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if ($this->enabled !== 'yes') {
            return;
        }

        $home = function_exists('home_url') ? home_url() : '';
        if (!is_string($home) || $home === '') {
            return;
        }

        $parsed = wp_parse_url($home);
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        if ($host === '') {
            return;
        }

        $looks_local = (
            $host === 'localhost'
            || strpos($host, '127.') === 0
            || substr($host, -6) === '.local'
            || substr($host, -5) === '.test'
            || substr($host, -10) === '.localhost'
        );
        if (!$looks_local) {
            return;
        }

        $message = sprintf(
            /* translators: 1: site URL */
            __('AcountPay Payment Gateway is enabled, but your WordPress site URL (%1$s) is not publicly reachable. The AcountPay backend will not be able to deliver webhooks to this store, and shoppers finishing payment on their phone will not be redirected back to your checkout. Before going live or testing with a real device, expose your site over a public URL (e.g. ngrok or Cloudflare Tunnel) and update WordPress Address / Site Address (or WP_HOME / WP_SITEURL) accordingly.', ACOUNTPAY_TEXT_DOMAIN),
            esc_html($home)
        );

        echo '<div class="notice notice-warning"><p><strong>AcountPay:</strong> ' . wp_kses_post($message) . '</p></div>';
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo '<div class="acountpay-description">' . wp_kses_post(wpautop(wptexturize($this->description))) . '</div>';
        }

        if (!is_ssl()) {
            return;
        }

        if ($this->supports('tokenization') && is_checkout() && $this->saved_cards && is_user_logged_in()) {
            $this->tokenization_script();
            $this->saved_payment_methods();
            $this->save_payment_method_checkbox();
        }
    }

    /**
     * Process the payment.
     * Uses v2 payment-link: create payment with callback URL, redirect customer to AcountPay POS to select bank.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id)
    {
        if (is_user_logged_in() && isset($_POST['wc-' . $this->id . '-new-payment-method']) && true === (bool) $_POST['wc-' . $this->id . '-new-payment-method'] && $this->saved_cards) {
            update_post_meta($order_id, '_wc_monnify_save_card', true);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        $client_id = trim($this->get_option('client_id', ''));
        if (empty($client_id)) {
            $this->log_error('process_payment: Client ID is missing');
            wc_add_notice(__('AcountPay is not configured. Please set your Client ID in the payment settings.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        if (!isset($this->api) || !$this->api) {
            $this->log_error('process_payment: API not initialized');
            wc_add_notice(__('Payment gateway is not configured. Please check your API keys.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        $amount = floatval($order->get_total());
        if ($amount <= 0) {
            wc_add_notice(__('Order amount must be greater than zero.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        $currency = $order->get_currency();
        if (empty($currency) || strlen($currency) !== 3) {
            $currency = get_woocommerce_currency();
        }
        $currency = strtoupper($currency);

        $callback_url = add_query_arg('order_id', $order_id, WC()->api_request_url('AcountPay_Payment_Gateway'));
        $webhook_url = add_query_arg('order_id', $order_id, WC()->api_request_url('acountpay_webhook'));
        $reference_number = (string) $order->get_order_number();
        $description = sprintf(__('Payment for order #%s', ACOUNTPAY_TEXT_DOMAIN), $order->get_order_number());

        $payment_data = array(
            'clientId' => $client_id,
            'amount' => $amount,
            'referenceNumber' => $reference_number,
            'redirectUrl' => $callback_url,
            'webhookUrl' => $webhook_url,
            'description' => $description,
            'currency' => $currency,
        );

        $response = $this->api->create_payment_link_v2($payment_data);

        if (is_wp_error($response)) {
            $this->log_error('process_payment: payment-link failed', array('order_id' => $order_id, 'error' => $response->get_error_message()));
            wc_add_notice($response->get_error_message(), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        // Guard against non-array or invalid redirect (e.g. API returning "-1" or scalar)
        if (!is_array($response)) {
            $this->log_error('process_payment: invalid response type', array('order_id' => $order_id, 'type' => gettype($response)));
            wc_add_notice(__('Payment could not be started. Please try again.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        $redirect_to_pos = isset($response['redirectUrl']) ? $response['redirectUrl'] : (isset($response['authorizationUrl']) ? $response['authorizationUrl'] : '');
        if (empty($redirect_to_pos) || !is_string($redirect_to_pos) || !preg_match('#^https?://#i', $redirect_to_pos)) {
            $this->log_error('process_payment: no redirect URL in response', array('order_id' => $order_id, 'response' => $response));
            wc_add_notice(__('Payment could not be started. Please try again.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        // Mark the order as pending until the callback + webhook confirm payment.
        // Without this, an abandoned payment would leave the order in its default post-checkout state.
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', __('Awaiting AcountPay confirmation.', ACOUNTPAY_TEXT_DOMAIN));
        } else {
            $order->add_order_note(__('Awaiting AcountPay confirmation.', ACOUNTPAY_TEXT_DOMAIN));
        }

        $this->log_info('process_payment: redirecting to POS', array('order_id' => $order_id));
        return array(
            'result' => 'success',
            'redirect' => $redirect_to_pos,
        );
    }

    /**
     * Payment validation callback
     * 
     */
    public function acountpay_payment_callback()
    {
        try {
            $this->log_info('Payment callback received');

            // Get the order id
            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
            
            if (!$order_id) {
                $this->log_error('Payment callback: Order ID is missing');
                throw new Exception(__('Order ID is required.', ACOUNTPAY_TEXT_DOMAIN));
            }

            $this->log_info('Payment callback: Processing order', array('order_id' => $order_id));

            // Get the order
            $order = wc_get_order($order_id);

            if (!$order) {
                $this->log_error('Payment callback: Order not found', array('order_id' => $order_id));
                throw new Exception(__('Order not found.', ACOUNTPAY_TEXT_DOMAIN));
            }

            // Check if payment intent ID exists in order meta
            $payment_intent_id = $order->get_meta('_acountpay_payment_intent_id');
            
            if (empty($payment_intent_id)) {
                // Payment intent might be in GET parameters
                $payment_intent_id = isset($_GET['payment_intent_id']) ? sanitize_text_field($_GET['payment_intent_id']) : '';
            }

            // If we have a payment intent ID, we can verify the payment
            // For now, we'll check if the order is already completed
            if ($order->get_status() === 'completed' || $order->get_status() === 'processing') {
                $this->log_info('Payment callback: Order already processed', array(
                    'order_id' => $order_id,
                    'status' => $order->get_status()
                ));
                // Order already processed, redirect to thank you page
                    wp_safe_redirect($order->get_checkout_order_received_url());
                    exit;
                }

            // Check for payment status in GET parameters
            $payment_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
            
            $this->log_info('Payment callback: Payment status', array(
                'order_id' => $order_id,
                'payment_status' => $payment_status,
                'payment_intent_id' => $payment_intent_id
            ));
            
            if (in_array($payment_status, array('success', 'completed', 'paid', 'settled'), true)) {
                // Server-side verification: confirm the payment status with the backend
                $verified = false;
                if (isset($this->api) && $this->api) {
                    $reference = (string) $order->get_order_number();
                    $verify_result = $this->api->verify_payment_status($reference);
                    if (!is_wp_error($verify_result) && is_array($verify_result)) {
                        $backend_status = isset($verify_result['status']) ? $verify_result['status'] : '';
                        if (in_array($backend_status, array('paid', 'settled', 'completed', 'processing', 'processed'), true)) {
                            $verified = true;
                            $this->log_info('Payment callback: Server-side verification passed', array('order_id' => $order_id, 'backend_status' => $backend_status));
                        } else {
                            $this->log_warning('Payment callback: Backend status mismatch', array(
                                'order_id' => $order_id,
                                'url_status' => $payment_status,
                                'backend_status' => $backend_status,
                            ));
                        }
                    } else {
                        $this->log_warning('Payment callback: Backend verification failed, trusting redirect status', array('order_id' => $order_id));
                        $verified = true;
                    }
                } else {
                    $verified = true;
                }

                if ($verified) {
                    $this->log_info('Payment callback: Payment successful', array('order_id' => $order_id, 'status' => $payment_status));
                    wc_add_notice(__('Payment successful using AcountPay Payment Gateway, thank you for your order.', ACOUNTPAY_TEXT_DOMAIN), 'success');
                    
                    $order->add_order_note(sprintf(__('Payment successful via AcountPay (status: %s, verified).', ACOUNTPAY_TEXT_DOMAIN), $payment_status));
                    // payment_complete() handles the transition (pending/on-hold/failed -> processing/completed) and fires downstream hooks.
                    $order->payment_complete();
                } else {
                    $this->log_warning('Payment callback: Verification failed, setting order on-hold', array('order_id' => $order_id));
                    $order->add_order_note(__('Payment callback received with success status but backend verification did not confirm. Order set to on-hold pending webhook confirmation.', ACOUNTPAY_TEXT_DOMAIN));
                    $order->update_status('on-hold', __('Awaiting payment verification from AcountPay.', ACOUNTPAY_TEXT_DOMAIN));
                }

                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            } elseif ($payment_status === 'processing' || $payment_status === 'pending') {
                // The bank redirect often arrives before the final webhook updates the payment status.
                // Check the backend to see if the payment has actually completed by now.
                $this->log_info('Payment callback: Status is processing/pending, checking backend for final status', array('order_id' => $order_id));
                
                $actual_status = $payment_status;
                if (isset($this->api) && $this->api) {
                    // Small delay to let the webhook finish processing
                    sleep(2);
                    $reference = (string) $order->get_order_number();
                    $verify_result = $this->api->verify_payment_status($reference);
                    if (!is_wp_error($verify_result) && is_array($verify_result)) {
                        $backend_status = isset($verify_result['status']) ? $verify_result['status'] : '';
                        $this->log_info('Payment callback: Backend status check', array('order_id' => $order_id, 'backend_status' => $backend_status));
                        if (in_array($backend_status, array('paid', 'settled', 'completed'), true)) {
                            $actual_status = 'paid';
                        }
                    }
                }

                if ($actual_status === 'paid') {
                    $this->log_info('Payment callback: Backend confirms payment is complete', array('order_id' => $order_id));
                    wc_add_notice(__('Payment successful using AcountPay Payment Gateway, thank you for your order.', ACOUNTPAY_TEXT_DOMAIN), 'success');
                    $order->add_order_note(__('Payment confirmed via AcountPay (redirect status was pending, backend confirmed paid).', ACOUNTPAY_TEXT_DOMAIN));
                    $order->payment_complete();
                } else {
                    $order->add_order_note(__('Payment is being processed by the bank. Status will update automatically via webhook.', ACOUNTPAY_TEXT_DOMAIN));
                    $order->update_status('on-hold', __('Awaiting payment confirmation from AcountPay.', ACOUNTPAY_TEXT_DOMAIN));
                }
                
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            } elseif (in_array($payment_status, array('failed', 'cancelled', 'rejected', 'failure_expired'), true)) {
                $this->log_info('Payment callback: Payment failed or cancelled', array('order_id' => $order_id, 'status' => $payment_status));
                $order->update_status('failed', sprintf(__('Payment %s via AcountPay.', ACOUNTPAY_TEXT_DOMAIN), $payment_status));
                wc_add_notice(__('Payment failed. Please try again or choose another payment method.', ACOUNTPAY_TEXT_DOMAIN), 'error');
                
                wp_safe_redirect($order->get_checkout_payment_url(true));
                exit;
            } else {
                $this->log_warning('Payment callback: Unknown or missing status', array('order_id' => $order_id, 'status' => $payment_status));
                $order->add_order_note(__('Payment callback received without a recognized status. Awaiting webhook confirmation.', ACOUNTPAY_TEXT_DOMAIN));
                $order->update_status('on-hold', __('Awaiting payment confirmation from AcountPay.', ACOUNTPAY_TEXT_DOMAIN));
                
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }
            
        } catch (Exception $e) {
            // Log error
            $this->log_error('Payment callback: Exception occurred', array(
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            
            // Checkout URL
            $checkout_url = wc_get_checkout_url();
            
            // Add error message
            wc_add_notice($e->getMessage(), 'error');
            
            // Redirect to checkout
            wp_safe_redirect($checkout_url);
            exit;
        }
    }

    /**
     * Handle server-to-server webhook from AcountPay backend.
     * Called when payment status changes (via Token.io webhook -> backend -> here).
     */
    public function handle_webhook()
    {
        $this->log_info('Webhook received');

        $raw_body = file_get_contents('php://input');
        if (empty($raw_body)) {
            $this->log_error('Webhook: Empty body');
            wp_send_json(array('received' => true, 'error' => 'empty body'), 400);
            return;
        }

        $signature = isset($_SERVER['HTTP_X_ACOUNTPAY_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ACOUNTPAY_SIGNATURE'])) : '';

        $signing_secret = trim((string) $this->get_option('webhook_signing_secret', ''));
        if ($signing_secret !== '') {
            $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $signing_secret);
            if ($signature === '' || ! hash_equals($expected, $signature)) {
                $this->log_error('Webhook: Invalid or missing signature', array('order_id' => isset($_GET['order_id']) ? absint($_GET['order_id']) : 0));
                wp_send_json(array('received' => false, 'error' => 'invalid signature'), 401);
                return;
            }
        } elseif ($signature !== '') {
            $this->log_info('Webhook: Signature present but webhook_signing_secret not configured; skipping verification');
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            $this->log_error('Webhook: Invalid JSON');
            wp_send_json(array('received' => true, 'error' => 'invalid json'), 400);
            return;
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $status = isset($data['status']) ? sanitize_text_field($data['status']) : '';
        $reference_number = isset($data['referenceNumber']) ? sanitize_text_field($data['referenceNumber']) : '';

        $this->log_info('Webhook: Processing', array(
            'order_id' => $order_id,
            'status' => $status,
            'reference' => $reference_number,
        ));

        if (!$order_id) {
            $this->log_error('Webhook: No order_id in query string');
            wp_send_json(array('received' => true, 'error' => 'missing order_id'), 400);
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_error('Webhook: Order not found', array('order_id' => $order_id));
            wp_send_json(array('received' => true, 'error' => 'order not found'), 404);
            return;
        }

        // Don't regress orders that are already in a final state
        $current_status = $order->get_status();
        if (in_array($current_status, array('completed', 'refunded'), true)) {
            $this->log_info('Webhook: Order already in final state', array('order_id' => $order_id, 'current' => $current_status));
            wp_send_json(array('received' => true, 'already_final' => true));
            return;
        }

        $amount = isset($data['amount']) ? floatval($data['amount']) : null;
        $currency = isset($data['currency']) ? sanitize_text_field($data['currency']) : '';

        if ($status === 'success' || $status === 'paid' || $status === 'settled' || $status === 'completed') {
            if ($current_status !== 'processing') {
                $order->add_order_note(sprintf(
                    __('Payment confirmed via AcountPay webhook (status: %s, amount: %s %s).', ACOUNTPAY_TEXT_DOMAIN),
                    $status,
                    $amount !== null ? number_format($amount, 2) : 'N/A',
                    $currency
                ));
                $order->payment_complete();
            }
        } elseif ($status === 'failed' || $status === 'rejected') {
            if (!in_array($current_status, array('processing', 'completed'), true)) {
                $order->add_order_note(sprintf(
                    __('Payment %s via AcountPay webhook.', ACOUNTPAY_TEXT_DOMAIN),
                    $status
                ));
                $order->update_status('failed', sprintf(__('Payment %s via AcountPay webhook.', ACOUNTPAY_TEXT_DOMAIN), $status));
            }
        } else {
            $order->add_order_note(sprintf(
                __('AcountPay webhook received with status: %s.', ACOUNTPAY_TEXT_DOMAIN),
                $status
            ));
        }

        wp_send_json(array('received' => true, 'updated' => true));
    }

    /**
     * Log a message
     * 
     * @param string $message Log message
     * @param string $level Log level (info, error, warning, debug)
     * @param array $context Additional context data
     * @since 1.0.0
     */
    public function log($message, $level = 'info', $context = array())
    {
        if ($this->logging !== 'yes') {
            return;
        }

        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $context['source'] = 'acountpay-payment';
        
        switch ($level) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger->warning($message, $context);
                break;
            case 'debug':
                $logger->debug($message, $context);
                break;
            case 'info':
            default:
                $logger->info($message, $context);
                break;
        }
    }

    /**
     * Log an error message
     * 
     * @param string $message Error message
     * @param array $context Additional context data
     * @since 1.0.0
     */
    public function log_error($message, $context = array())
    {
        $this->log($message, 'error', $context);
    }

    /**
     * Log an info message
     * 
     * @param string $message Info message
     * @param array $context Additional context data
     * @since 1.0.0
     */
    public function log_info($message, $context = array())
    {
        $this->log($message, 'info', $context);
    }

    /**
     * Log a debug message
     * 
     * @param string $message Debug message
     * @param array $context Additional context data
     * @since 1.0.0
     */
    public function log_debug($message, $context = array())
    {
        $this->log($message, 'debug', $context);
    }

    /**
     * Log a warning message
     * 
     * @param string $message Warning message
     * @param array $context Additional context data
     * @since 1.0.0
     */
    public function log_warning($message, $context = array())
    {
        $this->log($message, 'warning', $context);
    }
}
