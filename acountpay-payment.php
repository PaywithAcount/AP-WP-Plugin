<?php

/**
 * Plugin Name: WooCommerce AcountPay Payment Gateway
 * Plugin URI:  https://acountpay.com
 * Author:      AcountPay
 * Author URI:  https://acountpay.com
 * Description: Pay by Bank for WooCommerce, powered by AcountPay. Lets shoppers pay directly from their bank account via PSD2 / open banking, with a configurable bank-logo carousel, classic + block checkout support, signed callbacks, signed server-to-server webhooks, an order-edit panel showing payment id and PSU lookup state, and a manual-refund flow driven from the AcountPay Merchant Dashboard.
 * Version:     2.1.19
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
define('ACOUNTPAY_PAYMENT_VERSION', '2.1.19');
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
    //bust the cached bank list whenever the plugin version bumps so merchants
    //who upgrade past a release with a broken /banks endpoint don't have to
    //wait up to 7 days for the stale-cache fallback to expire on its own.
    add_action('plugins_loaded', 'acountpay_payment_maybe_flush_bank_cache', 11);

    // Reconciliation cron — 15-minute global sweep that catches stale
    // pending orders even when the per-order Action Scheduler reverify
    // failed to register (e.g. older orders pre-dating the action
    // scheduler hook, or AS table flushes). Self-heals on init.
    register_activation_hook(__FILE__, 'acountpay_payment_activate');
    register_deactivation_hook(__FILE__, 'acountpay_payment_deactivate');
    add_filter('cron_schedules', 'acountpay_payment_cron_schedules');
    add_action('init', 'acountpay_payment_ensure_cron_scheduled');
    add_action('acountpay_reconcile_pending_orders', 'acountpay_payment_reconcile_pending_orders');
    //add settings url
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'acountpay_payment_settings_link');
    //register woocommerce payment gateway
    add_filter('woocommerce_payment_gateways', 'acountpay_payment_gateway');
    //woocommerce_blocks_loaded
    add_action('woocommerce_blocks_loaded', 'acountpay_payment_block_support');

    // Register admin AJAX hooks at module-load time. Without this, the hooks
    // only get bound from inside AcountPay_Payment_Gateway::__construct(),
    // which WooCommerce only triggers on demand (typically only when the WC
    // Settings → Payments page is rendered). In a plain admin-ajax.php
    // request the gateway is never instantiated, the action has no handler,
    // admin-ajax echoes the literal "0", and the JS shows "Request failed"
    // with no useful message. Registering the proxy here guarantees a
    // handler is always wired up for admins.
    add_action('wp_ajax_acountpay_test_connection', 'acountpay_ajax_proxy_test_connection');
    add_action('wp_ajax_acountpay_refresh_banks', 'acountpay_ajax_proxy_refresh_banks');
    add_action('wp_ajax_acountpay_reverify_order', 'acountpay_ajax_proxy_reverify_order');

    // Action Scheduler background re-verify. Registered at module load so
    // Action Scheduler's WP-Cron / async worker finds a handler even when
    // WooCommerce hasn't loaded the gateway list during the worker request.
    add_action('acountpay_reverify_pending_order', 'acountpay_action_scheduler_proxy_reverify', 10, 2);
}

/**
 * Lazily instantiate the gateway and dispatch the named handler. This is
 * called from the top-level AJAX hooks above so admin-ajax.php always has a
 * handler bound, even if WooCommerce hasn't otherwise loaded its payment
 * gateway list during this request.
 */
function acountpay_resolve_gateway()
{
    if (!class_exists('AcountPay_Payment_Gateway')) {
        if (defined('ACOUNTPAY_PAYMENT_PLUGIN_PATH')) {
            $api_path = ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/includes/class-acountpay-api.php';
            $gw_path  = ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/includes/main-file.php';
            if (file_exists($api_path)) include_once $api_path;
            if (file_exists($gw_path))  include_once $gw_path;
        }
    }
    if (!class_exists('AcountPay_Payment_Gateway')) {
        return null;
    }

    // Prefer Woo's cached instance so we share the same in-memory option
    // values + nonces with anything else on the page.
    if (function_exists('WC') && WC() && WC()->payment_gateways()) {
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (!empty($gateways['acountpay_payment'])) {
            return $gateways['acountpay_payment'];
        }
    }
    return new AcountPay_Payment_Gateway();
}

/**
 * Read the merchant's gateway settings without instantiating the full
 * gateway class. WooCommerce stores them in
 * wp_options under "woocommerce_acountpay_payment_settings".
 *
 * Used by the lightweight AJAX proxies below — instantiating the real
 * gateway triggers init_form_fields(), which itself fires an HTTP call
 * to the bank-list endpoint inside the constructor and significantly
 * increases the chance an admin-ajax request times out before our
 * handler ever runs.
 */
function acountpay_get_settings()
{
    $opts = get_option('woocommerce_acountpay_payment_settings', array());
    if (!is_array($opts)) {
        $opts = array();
    }
    return $opts;
}

function acountpay_make_api_client()
{
    if (!class_exists('AcountPay_API') && defined('ACOUNTPAY_PAYMENT_PLUGIN_PATH')) {
        $api_path = ACOUNTPAY_PAYMENT_PLUGIN_PATH . '/includes/class-acountpay-api.php';
        if (file_exists($api_path)) include_once $api_path;
    }
    if (!class_exists('AcountPay_API')) {
        return null;
    }
    $opts = acountpay_get_settings();
    $api_base_url      = isset($opts['api_base_url']) && $opts['api_base_url'] !== '' ? $opts['api_base_url'] : 'https://api.acountpay.com';
    $logging_enabled   = isset($opts['logging']) && $opts['logging'] === 'yes';
    $sslverify_enabled = !isset($opts['sslverify']) || $opts['sslverify'] !== 'no';
    return new AcountPay_API($api_base_url, $logging_enabled, $sslverify_enabled);
}

function acountpay_ajax_proxy_test_connection()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
    }
    check_ajax_referer('acountpay_test_connection');

    $api = acountpay_make_api_client();
    if (!$api) {
        wp_send_json_error(array('message' => 'AcountPay API client could not be loaded — is the plugin active?'));
    }
    $result = $api->verify_connection();
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    wp_send_json_success(array('message' => 'Connection OK'));
}

function acountpay_ajax_proxy_refresh_banks()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => 'Insufficient permissions'), 403);
    }
    check_ajax_referer('acountpay_refresh_banks');

    $opts = acountpay_get_settings();

    // Accept country=DK (legacy) or country[]=FI&country[]=DK (multi).
    $countries = array();
    if (isset($_POST['country'])) {
        $raw = wp_unslash($_POST['country']);
        if (is_array($raw)) {
            foreach ($raw as $cc) {
                $cc = strtoupper(substr(sanitize_text_field((string) $cc), 0, 2));
                if ($cc !== '' && !in_array($cc, $countries, true)) $countries[] = $cc;
            }
        } else {
            $cc = strtoupper(substr(sanitize_text_field((string) $raw), 0, 2));
            if ($cc !== '') $countries[] = $cc;
        }
    }
    if (empty($countries)) {
        // Fall back to whatever is saved in settings, tolerating both shapes
        // (string from <2.1.7 installs, array from current installs).
        $saved = isset($opts['bank_country']) ? $opts['bank_country'] : array('FI', 'DK');
        if (is_string($saved)) $saved = array($saved);
        if (!is_array($saved)) $saved = array('FI', 'DK');
        foreach ($saved as $cc) {
            $cc = strtoupper(substr((string) $cc, 0, 2));
            if ($cc !== '' && !in_array($cc, $countries, true)) $countries[] = $cc;
        }
        if (empty($countries)) $countries = array('FI', 'DK');
    }

    $api = acountpay_make_api_client();
    if (!$api) {
        wp_send_json_error(array('message' => 'AcountPay API client could not be loaded — is the plugin active?'));
    }

    $base   = method_exists($api, 'get_api_base_url') ? $api->get_api_base_url() : '';
    $totals = array();
    $errors = array();
    foreach ($countries as $cc) {
        $cache_key = 'acountpay_banks_' . strtolower($cc);
        delete_transient($cache_key);
        delete_transient($cache_key . '_stale');

        $result = $api->get_country_banks($cc, true);
        if (is_wp_error($result)) {
            $msg = $result->get_error_message();
            if ($msg === '') $msg = $result->get_error_code();
            $errors[$cc] = $cc . ': could not load from '
                . rtrim($base, '/') . '/v1/banks/public/logos?country=' . $cc
                . ' — ' . $msg;
            continue;
        }
        $totals[$cc] = is_array($result) ? count($result) : 0;
    }

    if (!empty($errors) && empty($totals)) {
        wp_send_json_error(array('message' => implode(' · ', $errors)));
    }

    $parts = array();
    foreach ($totals as $cc => $count) {
        $parts[] = sprintf('%d %s', $count, $cc);
    }
    $message = 'Loaded: ' . implode(' · ', $parts);
    if (!empty($errors)) $message .= ' · ' . implode(' · ', $errors);
    wp_send_json_success(array('message' => $message));
}

function acountpay_ajax_proxy_reverify_order()
{
    // Re-verify still needs the full gateway (it touches order meta, payment
    // status mapping, hooks etc.), so route through the heavyweight path.
    $gw = acountpay_resolve_gateway();
    if (!$gw) {
        wp_send_json_error(array('message' => 'AcountPay gateway could not be loaded. Please reactivate the plugin.'));
    }
    $gw->ajax_reverify_order();
}

/**
 * Action Scheduler dispatcher for the background re-verify poll. The
 * gateway already binds the same hook from its constructor; this top-level
 * wrapper guarantees the worker can dispatch even when the gateway isn't
 * otherwise instantiated for the cron request (mirrors the AJAX proxies
 * above).
 */
function acountpay_action_scheduler_proxy_reverify($order_id, $attempt = 1)
{
    $gw = acountpay_resolve_gateway();
    if (!$gw || !method_exists($gw, 'handle_scheduled_reverify')) {
        return;
    }
    // The gateway-bound handler is also registered, so guard against running
    // it twice in the same request.
    static $seen = array();
    $key = (int) $order_id . '-' . (int) $attempt;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $gw->handle_scheduled_reverify($order_id, $attempt);
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

/**
 * Flush the cached bank list (24h fresh + 7d stale transients) whenever the
 * plugin version stored in wp_options doesn't match the version constant.
 * This makes upgrades self-healing — merchants don't need to manually click
 * "Refresh bank list" after pulling a build that fixed the API path.
 *
 * Also runs the one-time scrubs that need to fire on plugin upgrade:
 *   - Strip HTML out of saved `title` and `description` settings (older
 *     installs may have a bank-logo carousel pasted in there).
 *   - Strip HTML out of `_payment_method_title` on existing AcountPay
 *     orders so admin/email/invoice surfaces stop showing raw markup.
 */
function acountpay_payment_maybe_flush_bank_cache()
{
    $stored = get_option('acountpay_payment_version');
    if ($stored === ACOUNTPAY_PAYMENT_VERSION) {
        return;
    }
    foreach (array('fi', 'dk', 'se', 'no', 'ee', 'lt', 'lv') as $cc) {
        delete_transient('acountpay_banks_' . $cc);
        delete_transient('acountpay_banks_' . $cc . '_stale');
    }
    acountpay_payment_scrub_legacy_html_settings();
    acountpay_payment_scrub_legacy_html_orders();
    acountpay_payment_scrub_legacy_order_notes_html();
    update_option('acountpay_payment_version', ACOUNTPAY_PAYMENT_VERSION, false);
}

/**
 * One-shot: strip HTML from any saved gateway `title` / `description`
 * options that older plugin versions allowed merchants to paste raw
 * markup into. Idempotent — only writes back when something actually
 * changed.
 */
function acountpay_payment_scrub_legacy_html_settings()
{
    $settings = get_option('woocommerce_acountpay_payment_settings', array());
    if (!is_array($settings)) {
        return;
    }
    $changed = false;
    foreach (array('title', 'description') as $field) {
        if (isset($settings[$field]) && is_string($settings[$field])) {
            $clean = wp_strip_all_tags($settings[$field]);
            if ($clean !== $settings[$field]) {
                $settings[$field] = $clean;
                $changed = true;
            }
        }
    }
    if ($changed) {
        update_option('woocommerce_acountpay_payment_settings', $settings);
    }
}

/**
 * One-shot: normalize `_payment_method_title` on existing AcountPay orders
 * to the canonical plain label "Pay by Bank" so the DB matches what invoices
 * and emails show (and so HTML/carousel blobs from older plugin builds are
 * removed wholesale). Paged in batches to avoid a single request timing out
 * on very large stores (max ~25k orders per upgrade request).
 */
function acountpay_payment_scrub_legacy_html_orders()
{
    if (!function_exists('wc_get_orders')) {
        return;
    }
    $canonical = __('Pay by Bank', ACOUNTPAY_TEXT_DOMAIN);
    $per_page  = 500;
    $max_pages = 50;

    for ($page = 1; $page <= $max_pages; $page++) {
        try {
            $orders = wc_get_orders(array(
                'limit'          => $per_page,
                'paged'          => $page,
                'payment_method' => 'acountpay_payment',
                'status'         => 'any',
                'orderby'        => 'ID',
                'order'          => 'DESC',
                'return'         => 'objects',
            ));
        } catch (\Throwable $e) {
            return;
        }
        if (empty($orders)) {
            return;
        }
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            // Read unfiltered stored title — never use get_payment_method_title()
            // here because the gateway filter would mask legacy HTML in meta.
            $data     = $order->get_data();
            $stored   = isset($data['payment_method_title']) ? (string) $data['payment_method_title'] : '';
            $stripped = wp_strip_all_tags($stored);
            if ($stripped !== $canonical || $stored !== $canonical) {
                $order->set_payment_method_title($canonical);
                $order->save();
            }
        }
        if (count($orders) < $per_page) {
            return;
        }
    }
}

/**
 * Strip carousel markup out of WooCommerce order notes (wp_comments) left over
 * from when `_payment_method_title` contained HTML — those strings were copied
 * into customer-facing "Payment using …" notes verbatim.
 */
function acountpay_payment_scrub_legacy_order_notes_html()
{
    global $wpdb;
    if (!$wpdb || !isset($wpdb->comments)) {
        return;
    }
    $ids = $wpdb->get_col(
        "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = 'order_note' AND (comment_content LIKE '%acountpay-bank-carousel%' OR comment_content LIKE '%acountpay-payment-label%') LIMIT 5000"
    );
    foreach ($ids as $cid) {
        $cid = (int) $cid;
        if ($cid <= 0) {
            continue;
        }
        $content = (string) $wpdb->get_var($wpdb->prepare("SELECT comment_content FROM {$wpdb->comments} WHERE comment_ID = %d", $cid));
        if ($content === '') {
            continue;
        }
        $clean = preg_replace('/\s+/', ' ', trim(wp_strip_all_tags($content)));
        if ($clean !== '' && $clean !== $content) {
            $wpdb->update(
                $wpdb->comments,
                array('comment_content' => $clean),
                array('comment_ID' => $cid),
                array('%s'),
                array('%d')
            );
        }
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

/**
 * Register a 15-minute schedule used by the reconciliation cron.
 */
function acountpay_payment_cron_schedules($schedules)
{
    if (!isset($schedules['acountpay_fifteen_minutes'])) {
        $schedules['acountpay_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => __('Every 15 minutes (AcountPay)', 'acountpay-payment'),
        );
    }
    return $schedules;
}

/**
 * Self-heal the cron schedule on every load. wp_schedule_event is a
 * no-op if the event is already scheduled, so this is cheap.
 */
function acountpay_payment_ensure_cron_scheduled()
{
    if (!wp_next_scheduled('acountpay_reconcile_pending_orders')) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'acountpay_fifteen_minutes', 'acountpay_reconcile_pending_orders');
    }
}

/**
 * Plugin activation: schedule the reconciliation cron.
 */
function acountpay_payment_activate()
{
    acountpay_payment_ensure_cron_scheduled();
}

/**
 * Plugin deactivation: clear scheduled events so they don't keep firing
 * after the plugin is disabled.
 */
function acountpay_payment_deactivate()
{
    $timestamp = wp_next_scheduled('acountpay_reconcile_pending_orders');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'acountpay_reconcile_pending_orders');
    }
    wp_clear_scheduled_hook('acountpay_reconcile_pending_orders');
}

/**
 * 15-minute global sweep that re-polls the AcountPay backend for any
 * AcountPay orders that have been pending / on-hold for more than 30
 * minutes. The per-order Action Scheduler reverify
 * (`acountpay_reverify_pending_order`) is the primary path; this
 * exists as a safety net for orders the per-order scheduler missed
 * (older orders pre-dating the Action Scheduler hook, AS table flushes
 * etc).
 *
 * Capped at 50 orders per sweep so a backlog after a long outage
 * doesn't time the cron worker out — subsequent runs continue.
 */
function acountpay_payment_reconcile_pending_orders()
{
    if (!function_exists('wc_get_orders')) {
        return;
    }
    $gateway = acountpay_resolve_gateway();
    if (!$gateway || !isset($gateway->api) || !$gateway->api) {
        return;
    }

    $cutoff = time() - 30 * MINUTE_IN_SECONDS;

    try {
        $orders = wc_get_orders(array(
            'limit'          => 50,
            'status'         => array('pending', 'on-hold'),
            'payment_method' => 'acountpay_payment',
            'date_created'   => '<' . $cutoff,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'return'         => 'objects',
        ));
    } catch (\Throwable $e) {
        return;
    }

    if (empty($orders)) {
        return;
    }

    foreach ($orders as $order) {
        if (!$order instanceof WC_Order) {
            continue;
        }
        $order_id = $order->get_id();

        $reference = (string) $order->get_meta('_acountpay_reference_number');
        if ($reference === '') {
            $reference = (string) $order->get_order_number();
        }
        $pid = preg_replace('/\D/', '', (string) $order->get_meta('_acountpay_payment_id'));
        if ($reference === '' && $pid === '') {
            continue;
        }

        $result = method_exists($gateway, 'verify_payment_status_for_order')
            ? $gateway->verify_payment_status_for_order($order)
            : $gateway->api->verify_payment_status($reference, $pid);
        if (is_wp_error($result) || !is_array($result)) {
            // After 24h still unable to reach the backend / resolve the
            // payment — auto-fail locally so the order stops looking
            // perpetually pending to the merchant.
            $created = $order->get_date_created();
            $age = $created ? (time() - $created->getTimestamp()) : 0;
            if ($age >= DAY_IN_SECONDS && method_exists($gateway, 'apply_failed_status')) {
                $err = is_wp_error($result) ? $result->get_error_message() : 'invalid response';
                $gateway->apply_failed_status($order, sprintf('reconcile failed (%s)', $err));
                $order->add_order_note(sprintf(
                    /* translators: %s: error message returned by the AcountPay API */
                    __('Reconciled from AcountPay: backend unreachable for >24h, auto-failed (%s).', 'acountpay-payment'),
                    $err
                ));
            }
            continue;
        }

        $backend_status = isset($result['status']) ? (string) $result['status'] : '';
        if ($backend_status === '') {
            continue;
        }

        if (method_exists($gateway, 'apply_backend_status_to_order')) {
            $bucket = $gateway->apply_backend_status_to_order($order, $backend_status);
            if (in_array($bucket, array('paid', 'failed', 'refunded'), true)) {
                $order->add_order_note(sprintf(
                    /* translators: %1$s: bucket (paid/failed/refunded), %2$s: AcountPay backend status */
                    __('Reconciled from AcountPay (%1$s) — backend status: %2$s.', 'acountpay-payment'),
                    $bucket,
                    $backend_status
                ));
            }
        }
    }
}
