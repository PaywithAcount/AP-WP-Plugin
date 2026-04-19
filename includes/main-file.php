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
        $this->method_title = __('Pay by Bank', ACOUNTPAY_TEXT_DOMAIN);
        //description
        $this->method_description = __('Let your customers pay directly from their bank account via Pay by Bank (powered by AcountPay).', ACOUNTPAY_TEXT_DOMAIN);
        // We render our own logo carousel inside get_title(); leaving $this->icon empty
        // prevents WooCommerce from also rendering the legacy single-icon next to our label.
        $this->icon = '';
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

        // Don't let the customer cancel an order from My Account while it's
        // still pending — the webhook may already be in flight and cancelling
        // would race with it.
        $this->remove_cancel_order_button = true;

        //process admin options
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        //register styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        //also fire on the Blocks checkout page where wp_enqueue_scripts can run before is_checkout()
        add_action('enqueue_block_assets', [$this, 'enqueue_styles']);
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
        //admin notice when the gateway is enabled but the webhook signing secret is blank
        add_action('admin_notices', [$this, 'maybe_render_missing_signing_secret_notice']);

        // Order edit screen meta box (HPOS-aware): show payment id, status,
        // refunded amount, last webhook timestamp + a re-verify button.
        add_action('add_meta_boxes', [$this, 'register_order_meta_box']);

        // Order list column with a small "AP" status pill (both legacy and HPOS).
        add_filter('manage_edit-shop_order_columns', [$this, 'register_order_list_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_order_list_column'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'register_order_list_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_order_list_column_hpos'], 10, 2);

        // Thank-you page: show "awaiting bank confirmation" hint while the
        // webhook race is still in flight.
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'render_awaiting_confirmation_notice']);

        // AJAX endpoints — admin-only "Test connection", "Refresh banks",
        // "Re-verify status". These are *also* registered at module-load time
        // in acountpay-payment.php so admin-ajax.php finds a handler even
        // when WooCommerce hasn't instantiated payment gateways yet (the
        // common case for ad-hoc admin-ajax requests). Re-registering here
        // is harmless — WordPress de-duplicates identical [object, method]
        // callbacks via _wp_filter_build_unique_id.
        add_action('wp_ajax_acountpay_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_acountpay_refresh_banks', [$this, 'ajax_refresh_banks']);
        add_action('wp_ajax_acountpay_reverify_order', [$this, 'ajax_reverify_order']);

        //is valid for use
        if (!$this->is_valid_for_use()) {
            //disable the gateway because the current currency is not supported
            $this->enabled = 'no';
        }
    }

    /**
     * Get AcountPay payment icon URL.
     *
     * Kept for backwards compatibility / the woocommerce_acountpay_payment_icon
     * filter, but no longer rendered by default — we now show a configurable
     * bank-logo carousel inside get_title().
     */
    public function get_logo_url()
    {
        $url = WC_HTTPS::force_https_url(ACOUNTPAY_PAYMENT_PLUGIN_URL . '/assets/images/logo.jpg');
        return apply_filters('woocommerce_acountpay_payment_icon', $url, $this->id);
    }

    /**
     * Bundled fallback bank list shipped with the plugin. Used when the
     * AcountPay /banks/public/logos endpoint is unreachable so the carousel
     * never falls back to "text only".
     *
     * Each entry maps a Token.io / AcountPay bankId → display name + bundled
     * SVG filename under assets/images/banks/. The legacy slug aliases let
     * settings saved in plugin v2.0–v2.1 keep resolving cleanly after we
     * switched the option keys from human slugs to bankIds.
     *
     * @return array<string, array{name:string, svg:string, aliases:array<int,string>}>
     */
    public function get_bundled_banks_fallback()
    {
        return array(
            'ngp-okoy'        => array('name' => 'OP Pohjola',                'svg' => 'op.svg',               'aliases' => array('op')),
            'ngp-ndeafi'      => array('name' => 'Nordea Bank',               'svg' => 'nordea.svg',           'aliases' => array('nordea')),
            'ob-danske-fin'   => array('name' => 'Danske Bank',               'svg' => 'danske-bank.svg',      'aliases' => array('danske-bank')),
            'ob-spanfi'       => array('name' => 'S-Pankki',                  'svg' => 's-pankki.svg',         'aliases' => array('s-pankki')),
            'ob-aktia'        => array('name' => 'Aktia',                     'svg' => 'aktia.svg',            'aliases' => array('aktia')),
            'ngp-saot'        => array('name' => 'Säästöpankki',              'svg' => 'saastopankki.svg',     'aliases' => array('saastopankki')),
            'ngp-popf'        => array('name' => 'POP Pankki',                'svg' => 'pop-pankki.svg',       'aliases' => array('pop-pankki')),
            'ob-alanfi'       => array('name' => 'Ålandsbanken',              'svg' => 'alandsbanken.svg',     'aliases' => array('alandsbanken')),
            'ngp-omsa'        => array('name' => 'Oma Säästöpankki',          'svg' => 'oma-saastopankki.svg', 'aliases' => array('oma-saastopankki')),
        );
    }

    /**
     * Returns an AcountPay_API instance configured against the merchant's
     * currently-saved api_base_url / SSL settings.
     *
     * Critical: get_supported_banks() and get_bank_logo_urls() previously
     * instantiated AcountPay_API() with no arguments, which silently pinned
     * every public-bank lookup to the hardcoded production URL even when
     * the merchant had configured a sandbox / staging / ngrok host. Live
     * logos then never resolved on those environments and the carousel
     * fell back to the bundled placeholder SVGs (which look like text).
     *
     * @return AcountPay_API|null
     */
    private function get_api_for_banks()
    {
        if (isset($this->api) && $this->api instanceof AcountPay_API) {
            return $this->api;
        }
        if (!class_exists('AcountPay_API')) {
            return null;
        }
        $api_base_url      = $this->get_option('api_base_url', 'https://api.acountpay.com');
        $logging_enabled   = $this->get_option('logging', 'no') === 'yes';
        $sslverify_enabled = $this->get_option('sslverify', 'yes') === 'yes';
        return new AcountPay_API($api_base_url, $logging_enabled, $sslverify_enabled);
    }

    /**
     * Build the full options map (bankId => display name) for the merchant's
     * configured country. Live AcountPay data is preferred — bundled fallback
     * fills the gaps so the settings screen and carousel keep working offline.
     *
     * @param string $country_code ISO-3166-1 alpha-2 (defaults to merchant setting → "FI").
     * @return array<string,string>
     */
    public function get_supported_banks($country_code = null)
    {
        $country_code = strtoupper((string) ($country_code ?: $this->get_option('bank_country', 'FI')));
        $bundled      = $this->get_bundled_banks_fallback();

        $live = array();
        $api  = $this->get_api_for_banks();
        if ($api) {
            $result = $api->get_country_banks($country_code);
            if (is_array($result)) {
                foreach ($result as $row) {
                    if (empty($row['bankId'])) {
                        continue;
                    }
                    $live[$row['bankId']] = !empty($row['name']) ? $row['name'] : $row['bankId'];
                }
            }
        }

        // Live first (preserves API ordering), then any bundled rows the API
        // didn't return — only if we actually got live data; otherwise fall
        // back to the full bundled list so the settings UI is never empty.
        if (!empty($live)) {
            $supported = $live;
            foreach ($bundled as $bankId => $meta) {
                if (!isset($supported[$bankId])) {
                    $supported[$bankId] = $meta['name'];
                }
            }
        } else {
            $supported = array();
            foreach ($bundled as $bankId => $meta) {
                $supported[$bankId] = $meta['name'];
            }
        }

        return apply_filters('woocommerce_acountpay_supported_banks', $supported, $country_code, $this->id);
    }

    /**
     * Translate a stored option value (which may be a legacy slug from the
     * pre-2.1 settings or an empty array) into a list of canonical bankIds.
     *
     * @param mixed $stored
     * @return string[]
     */
    private function normalize_selected_bank_ids($stored)
    {
        if (!is_array($stored)) {
            return array();
        }
        $bundled    = $this->get_bundled_banks_fallback();
        $alias_map  = array();
        foreach ($bundled as $bankId => $meta) {
            foreach ($meta['aliases'] as $alias) {
                $alias_map[$alias] = $bankId;
            }
        }

        $out = array();
        foreach ($stored as $val) {
            $val = (string) $val;
            if ($val === '') {
                continue;
            }
            if (isset($alias_map[$val])) {
                $out[] = $alias_map[$val];
            } else {
                $out[] = $val;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Resolve the merchant-selected bank logos to renderable items.
     *
     * Live AcountPay logo URLs are preferred. If the live URL is missing for
     * a selected bank we fall back to the bundled SVG (when one ships for
     * that bankId). Banks with neither a live nor a bundled logo are skipped.
     *
     * @return array<int, array{slug:string,name:string,url:string}>
     */
    public function get_bank_logo_urls()
    {
        $country_code = strtoupper((string) $this->get_option('bank_country', 'FI'));
        $supported    = $this->get_supported_banks($country_code);
        $bundled      = $this->get_bundled_banks_fallback();

        $stored   = $this->get_option('bank_logos', array_keys($supported));
        $selected = $this->normalize_selected_bank_ids($stored);
        if (empty($selected)) {
            $selected = array_keys($supported);
        }

        // Build a lookup of live logoUrls keyed by bankId.
        $live_urls = array();
        $api       = $this->get_api_for_banks();
        if ($api) {
            $result = $api->get_country_banks($country_code);
            if (is_array($result)) {
                foreach ($result as $row) {
                    if (!empty($row['bankId']) && !empty($row['logoUrl'])) {
                        $live_urls[$row['bankId']] = $row['logoUrl'];
                    }
                }
            }
        }

        $urls = array();
        foreach ($selected as $bankId) {
            $name = isset($supported[$bankId]) ? $supported[$bankId] : (isset($bundled[$bankId]) ? $bundled[$bankId]['name'] : $bankId);

            $url = '';
            if (!empty($live_urls[$bankId])) {
                $url = $live_urls[$bankId];
            } elseif (isset($bundled[$bankId])) {
                $relative = 'assets/images/banks/' . $bundled[$bankId]['svg'];
                $absolute = ACOUNTPAY_PAYMENT_PLUGIN_PATH . $relative;
                if (file_exists($absolute)) {
                    $url = WC_HTTPS::force_https_url(ACOUNTPAY_PAYMENT_PLUGIN_URL . $relative);
                }
            }
            if ($url === '') {
                continue;
            }

            $urls[] = array(
                'slug' => $bankId,
                'name' => $name,
                'url'  => $url,
            );
        }

        return apply_filters('woocommerce_acountpay_bank_logos', $urls, $this->id);
    }

    /**
     * Build the payment reference string that will be sent to AcountPay as
     * `referenceNumber`, then forwarded to Token.io as `displayReference` →
     * `remittanceInformationPrimary`. This is what the PSU sees on their bank
     * statement next to the transaction.
     *
     * Why this matters: by default the plugin used to send the bare Woo order
     * number (e.g. "1234"), so customers saw a meaningless integer on their
     * statement. Letting the merchant configure a template like
     * `Acme Shop #{order_number}` means the customer recognises the charge,
     * which cuts down chargeback / "what was this?" support tickets.
     *
     * Bank constraints are enforced two ways:
     *   1. Here we sanitize to a conservative ASCII-friendly charset
     *      (alnum + space + hyphen + dot + hash + underscore + slash). This
     *      keeps every bank happy and avoids unexpected sanitization on the
     *      backend swallowing parts of the merchant's reference.
     *   2. The backend (`payment-rails.config.ts`) further sanitizes per-bank
     *      and truncates to each bank's `maxRemittancePrimaryLen` (typically
     *      25–40 chars). We pre-truncate to the merchant-configured cap
     *      (default 18) so a long template doesn't get silently cut by
     *      the bank into something nonsensical.
     *
     * Placeholders supported:
     *   {order_number}   → WC_Order::get_order_number() (respects Woo Sequential)
     *   {order_id}       → WC_Order::get_id() (raw post id)
     *   {site_title}     → WordPress site name (get_bloginfo('name'))
     *   {first_name}     → Billing first name
     *   {last_name}      → Billing last name
     *
     * @param WC_Order $order
     * @return string
     */
    public function build_payment_reference($order)
    {
        if (!$order instanceof WC_Order) {
            return '';
        }

        $template = (string) $this->get_option('payment_reference_template', '{site_title} #{order_number}');
        $template = trim($template);
        if ($template === '') {
            // Fallback: bare order number — preserves pre-2.1.4 behaviour for
            // merchants who explicitly cleared the field.
            return (string) $order->get_order_number();
        }

        $replacements = array(
            '{order_number}' => (string) $order->get_order_number(),
            '{order_id}'     => (string) $order->get_id(),
            '{site_title}'   => (string) get_bloginfo('name'),
            '{first_name}'   => (string) $order->get_billing_first_name(),
            '{last_name}'    => (string) $order->get_billing_last_name(),
        );
        $rendered = strtr($template, $replacements);

        // Strip any leftover {placeholder} tokens the merchant may have typed
        // (typos, unsupported names) so they don't leak through onto the
        // statement as literal "{foo}".
        $rendered = preg_replace('/\{[a-z_]+\}/i', '', (string) $rendered);

        // Conservative charset: keep alnum, space, hyphen, dot, hash,
        // underscore, slash. Most banks accept all of these; everything else
        // (curly braces, asterisks, emoji, etc.) gets dropped silently here
        // rather than at the bank's edge where it can cause confusing partial
        // truncations.
        $rendered = preg_replace('/[^A-Za-z0-9 #\-_\/.]/', '', (string) $rendered);
        $rendered = preg_replace('/\s+/', ' ', (string) $rendered);
        $rendered = trim((string) $rendered);

        $max_len  = (int) $this->get_option('payment_reference_max_length', 18);
        if ($max_len < 6) {
            $max_len = 6;
        } elseif ($max_len > 35) {
            // Above 35 chars most banks truncate anyway — clamp here so the
            // backend doesn't have to.
            $max_len = 35;
        }
        if (function_exists('mb_substr')) {
            $rendered = mb_substr($rendered, 0, $max_len, 'UTF-8');
        } else {
            $rendered = substr($rendered, 0, $max_len);
        }
        $rendered = trim($rendered);

        if ($rendered === '') {
            // Sanitization wiped everything (template was all unsupported
            // chars). Fall back to the bare order number rather than sending
            // an empty referenceNumber, which the backend would reject.
            return (string) $order->get_order_number();
        }

        return apply_filters('woocommerce_acountpay_payment_reference', $rendered, $order, $this->id);
    }

    /**
     * Three-step explanation shown in the "How it works" info bubble.
     *
     * @return array<int, string>
     */
    public function get_info_steps()
    {
        return array(
            __('Select your bank', ACOUNTPAY_TEXT_DOMAIN),
            __('Log in to your bank app or netbank', ACOUNTPAY_TEXT_DOMAIN),
            __('Authorise the payment', ACOUNTPAY_TEXT_DOMAIN),
        );
    }

    /**
     * Heading shown above the 3-step list in the info bubble.
     */
    public function get_info_heading()
    {
        return __('How Pay by Bank works', ACOUNTPAY_TEXT_DOMAIN);
    }

    /**
     * Filter gateway title output: render the title, an auto-scrolling carousel
     * of the merchant-selected bank logos, and an optional "i" info bubble.
     */
    public function get_title()
    {
        $title  = parent::get_title();
        $output = '<span class="acountpay-payment-label">'
            . '<span class="acountpay-payment-title">' . $title . '</span>';

        $logos = $this->get_bank_logo_urls();
        if (!empty($logos)) {
            $items = '';
            // Render the list twice so the marquee can translateX(-50%) seamlessly.
            foreach (array_merge($logos, $logos) as $logo) {
                $items .= '<img class="acountpay-bank-logo" src="' . esc_url($logo['url']) . '" alt="' . esc_attr($logo['name']) . '" loading="lazy" />';
            }
            $output .= '<span class="acountpay-bank-carousel" aria-hidden="true">'
                . '<span class="acountpay-bank-carousel-track">' . $items . '</span>'
                . '</span>';
        }

        if ('yes' === $this->get_option('show_info_bubble', 'yes')) {
            $heading    = esc_html($this->get_info_heading());
            $aria_label = esc_attr($this->get_info_heading());
            $steps_html = '';
            foreach ($this->get_info_steps() as $step) {
                $steps_html .= '<li>' . esc_html($step) . '</li>';
            }
            $output .= '<span class="acountpay-info-wrap">'
                . '<button type="button" class="acountpay-info-bubble" aria-label="' . $aria_label . '" aria-expanded="false">i</button>'
                . '<span class="acountpay-info-popover" role="tooltip">'
                    . '<strong>' . $heading . '</strong>'
                    . '<ol>' . $steps_html . '</ol>'
                . '</span>'
                . '</span>';
        }

        $output .= '</span>';
        return $output;
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
        if ($this->enabled === 'no') {
            return;
        }

        // Decide whether we're on a page that renders the gateway label. Cover:
        // - Classic checkout (is_checkout)
        // - "Pay for order" page (order-pay endpoint)
        // - Blocks checkout (page contains the wc/checkout block — enqueue_block_assets fires before is_checkout returns true on some themes, so fall back to a permissive check)
        $on_checkout_like_page = false;
        if (function_exists('is_checkout') && is_checkout()) {
            $on_checkout_like_page = true;
        } elseif (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $on_checkout_like_page = true;
        } elseif (function_exists('has_block')) {
            $post = get_post();
            if ($post && (has_block('woocommerce/checkout', $post) || has_block('woocommerce/cart', $post))) {
                $on_checkout_like_page = true;
            }
        }
        if (!$on_checkout_like_page) {
            return;
        }

        wp_enqueue_style(
            'acountpay-checkout-style',
            ACOUNTPAY_PAYMENT_PLUGIN_URL . 'assets/css/acountpay-checkout.css',
            array(),
            ACOUNTPAY_PAYMENT_VERSION
        );

        // Info-bubble toggle for classic checkout. Blocks checkout has its own React handler in src/block.js.
        if ('yes' === $this->get_option('show_info_bubble', 'yes')) {
            wp_enqueue_script(
                'acountpay-info-bubble',
                ACOUNTPAY_PAYMENT_PLUGIN_URL . 'assets/js/info-bubble.js',
                array(),
                ACOUNTPAY_PAYMENT_VERSION,
                true
            );
        }
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
        $bank_country    = strtoupper((string) $this->get_option('bank_country', 'FI'));
        $supported_banks = $this->get_supported_banks($bank_country);

        $form_fields = apply_filters('woo_acountpay_payment', [
            'enabled' => [
                'title' => __('Enable/Disable', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Enable Pay by Bank', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'no'
            ],
            'title' => [
                'title' => __('Title', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => __('Pay by Bank', ACOUNTPAY_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'description' => [
                'title' => __('Description', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => __('Pay securely and directly from your bank account.', ACOUNTPAY_TEXT_DOMAIN),
                'desc_tip' => true
            ],
            'bank_country' => [
                'title'       => __('Bank country', ACOUNTPAY_TEXT_DOMAIN),
                'type'        => 'select',
                'description' => __('Country whose banks AcountPay should offer. Picks the live bank list (and CDN logos) used by the carousel below.', ACOUNTPAY_TEXT_DOMAIN),
                'default'     => 'FI',
                'desc_tip'    => true,
                'options'     => array(
                    'FI' => __('Finland', ACOUNTPAY_TEXT_DOMAIN),
                    'DK' => __('Denmark', ACOUNTPAY_TEXT_DOMAIN),
                ),
            ],
            'bank_logos' => [
                'title' => __('Bank logos', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'min-width: 350px;',
                'description' => __('Pick which bank logos appear in the auto-scrolling carousel next to the Pay by Bank label on checkout. Live logos are pulled from AcountPay; bundled SVGs are used as a fallback. Save the country first if you just changed it, then pick logos.', ACOUNTPAY_TEXT_DOMAIN),
                'options' => $supported_banks,
                'default' => array_keys($supported_banks),
                'desc_tip' => true,
            ],
            'bank_logos_refresh' => [
                'type' => 'bank_logos_refresh',
            ],
            'show_info_bubble' => [
                'title' => __('Show "How it works" info bubble', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Show a small (i) info bubble next to the Pay by Bank label that explains the 3 payment steps to shoppers.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'yes',
            ],
            'skip_desktop_qr' => [
                'title' => __('Skip desktop QR page', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'checkbox',
                'label' => __('Send desktop shoppers straight to bank selection (skip the scan-with-phone QR step).', ACOUNTPAY_TEXT_DOMAIN),
                'description' => __('When enabled, desktop shoppers go directly to the bank selector on the AcountPay pay page instead of seeing the QR / continue-on-this-device screen first.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'no',
                'desc_tip' => true,
            ],
            'payment_reference_template' => [
                'title'       => __('Payment reference (shown on customer\'s bank statement)', ACOUNTPAY_TEXT_DOMAIN),
                'type'        => 'text',
                'description' => sprintf(
                    /* translators: 1: site title example, 2: placeholder list, 3: example output, 4: site title only example, 5: name + number example, 6: free text example */
                    __('<strong>This is the text your customer will see on their bank statement</strong> next to the transaction. Use it to make sure they recognise the charge — typically your business name plus an order number.<br/><br/><strong>You can type anything you want here</strong>, including your business name (e.g. <code>%1$s #1234</code>). To pull live data from the order, use these placeholders: %2$s. The default <code>{site_title} #{order_number}</code> renders as %3$s — it pulls from <em>WordPress → Settings → General → Site Title</em>.<br/><br/><strong>Examples:</strong> %4$s · %5$s · %6$s.<br/><br/>Leave blank to fall back to just the bare WooCommerce order number (not recommended — most customers won\'t recognise a 4-digit integer on their statement).', ACOUNTPAY_TEXT_DOMAIN),
                    esc_html(get_bloginfo('name') ?: 'Acme Shop'),
                    '<code>{order_number}</code>, <code>{order_id}</code>, <code>{site_title}</code>, <code>{first_name}</code>, <code>{last_name}</code>',
                    '<code>' . esc_html(get_bloginfo('name') ?: 'Acme Shop') . ' #1234</code>',
                    '<code>{site_title}</code> → <code>' . esc_html(get_bloginfo('name') ?: 'Acme Shop') . '</code>',
                    '<code>{site_title} order {order_number}</code> → <code>' . esc_html(get_bloginfo('name') ?: 'Acme Shop') . ' order 1234</code>',
                    '<code>Acme Shop #{order_number}</code> → <code>Acme Shop #1234</code>'
                ),
                'default'     => '{site_title} #{order_number}',
                'placeholder' => '{site_title} #{order_number}',
            ],
            'payment_reference_max_length' => [
                'title'       => __('Payment reference max length', ACOUNTPAY_TEXT_DOMAIN),
                'type'        => 'number',
                'description' => __('Banks enforce per-rail character limits on the remittance text. The plugin truncates the rendered reference to this many characters before sending it to AcountPay. Recommended values: 18 (safe across all supported FI/DK banks), 25 (Danske Bank cap, OBIE rails), 35 (Aktia / OP / S-Pankki / Ålandsbanken / Wise), 40 (Nordea Denmark / Nordea Finland Business). Anything above 35 will be truncated by most banks. Note: Oma / POP / Säästöpankki strip non-digits from the structured reference, but the remittance text remains visible to the PSU.', ACOUNTPAY_TEXT_DOMAIN),
                'default'     => '18',
                'custom_attributes' => array(
                    'min'  => '6',
                    'max'  => '35',
                    'step' => '1',
                ),
                'desc_tip'    => true,
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
                'description' => __('Copy this from your AcountPay Merchant Dashboard → Developer → Webhook signing secret. Used to verify that incoming payment webhooks really came from AcountPay (HMAC-SHA256). This value is per-merchant — do not share it. Leaving it blank disables signature verification and is rejected in production.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => '',
                'desc_tip' => true,
            ],
            'webhook_url_display' => [
                'title' => __('Webhook URL', ACOUNTPAY_TEXT_DOMAIN),
                'type'  => 'webhook_url_display',
            ],
            'webhook_health' => [
                'title' => __('Webhook health', ACOUNTPAY_TEXT_DOMAIN),
                'type'  => 'webhook_health',
            ],
            'test_connection' => [
                'title' => __('Connection test', ACOUNTPAY_TEXT_DOMAIN),
                'type'  => 'test_connection',
            ],
            'paid_order_status' => [
                'title' => __('Order status when payment is confirmed', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'select',
                'description' => __('WooCommerce normally moves Pay-by-Bank orders to "Processing" so you can fulfil them. Pick "Completed" if you want orders auto-completed after payment instead (e.g. digital goods).', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'default',
                'options' => [
                    'default'    => __('Default (Processing for physical, Completed for virtual)', ACOUNTPAY_TEXT_DOMAIN),
                    'processing' => __('Processing', ACOUNTPAY_TEXT_DOMAIN),
                    'completed'  => __('Completed', ACOUNTPAY_TEXT_DOMAIN),
                ],
                'desc_tip' => true,
            ],
            'failed_order_status' => [
                'title' => __('Order status when payment fails', ACOUNTPAY_TEXT_DOMAIN),
                'type' => 'select',
                'description' => __('Where to move orders when AcountPay reports failed / cancelled / expired. "Pending" lets the customer easily retry from My Account; "Failed" matches most A2A providers.', ACOUNTPAY_TEXT_DOMAIN),
                'default' => 'failed',
                'options' => [
                    'failed'  => __('Failed', ACOUNTPAY_TEXT_DOMAIN),
                    'pending' => __('Pending', ACOUNTPAY_TEXT_DOMAIN),
                ],
                'desc_tip' => true,
            ],
        ]);
        //return form fields to woocommerce
        $this->form_fields = $form_fields;
    }

    /**
     * Render the read-only Webhook URL field with a copy button. This is the
     * single biggest source of integration support tickets — making it visible
     * (and copy-pasteable) in settings rather than buried in plugin docs.
     */
    public function generate_webhook_url_display_html($key, $data)
    {
        $field_key   = $this->get_field_key($key);
        $webhook_url = function_exists('WC') ? esc_url(WC()->api_request_url('acountpay_webhook')) : '';
        $defaults = array(
            'title'       => __('Webhook URL', ACOUNTPAY_TEXT_DOMAIN),
            'description' => __('This is the URL your store listens on for payment status updates. The plugin sends it to AcountPay automatically with every Pay-by-Bank checkout — there is no separate webhook field to fill in on the AcountPay Merchant Dashboard. Keep it copyable here so support can verify the URL is reachable from the public internet.', ACOUNTPAY_TEXT_DOMAIN),
        );
        $data = wp_parse_args($data, $defaults);
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <input type="text" readonly id="<?php echo esc_attr($field_key); ?>" value="<?php echo esc_attr($webhook_url); ?>" style="width: 100%; max-width: 520px;" onclick="this.select();" />
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('<?php echo esc_js($field_key); ?>').value); this.textContent='<?php echo esc_js(__('Copied', ACOUNTPAY_TEXT_DOMAIN)); ?>'; setTimeout(()=>{this.textContent='<?php echo esc_js(__('Copy', ACOUNTPAY_TEXT_DOMAIN)); ?>';}, 1500);"><?php esc_html_e('Copy', ACOUNTPAY_TEXT_DOMAIN); ?></button>
                <p class="description"><?php echo esc_html($data['description']); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Show "last webhook seen" status so merchants can verify their webhook
     * configuration is actually delivering. Pulled from a plugin-wide option
     * we stamp every time handle_webhook() succeeds.
     */
    public function generate_webhook_health_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $last      = (int) get_option('acountpay_last_webhook_at', 0);
        $count     = (int) get_option('acountpay_webhook_count', 0);
        if ($last > 0) {
            $age = time() - $last;
            $fresh = $age < (24 * HOUR_IN_SECONDS);
            $color = $fresh ? '#0a7d20' : '#a05a00';
            $bg    = $fresh ? '#d6f4dc' : '#fff1d6';
            $label = $fresh
                ? sprintf(__('Last webhook received %s ago', ACOUNTPAY_TEXT_DOMAIN), human_time_diff($last, time()))
                : sprintf(__('Last webhook received %s ago — check your AcountPay dashboard webhook URL', ACOUNTPAY_TEXT_DOMAIN), human_time_diff($last, time()));
            $html  = sprintf('<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:%s;color:%s;font-weight:600;">%s</span> <small>%s</small>', esc_attr($bg), esc_attr($color), esc_html($label), esc_html(sprintf(__('(%d total)', ACOUNTPAY_TEXT_DOMAIN), $count)));
        } else {
            $html = '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fde2e2;color:#a40000;font-weight:600;">' . esc_html__('No webhook ever received', ACOUNTPAY_TEXT_DOMAIN) . '</span> <small>' . esc_html__('Pay-by-Bank orders won\'t auto-complete until AcountPay can deliver to the URL above.', ACOUNTPAY_TEXT_DOMAIN) . '</small>';
        }
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp"><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the "Test connection" button that pings the AcountPay API and
     * shows a green/red pill — same pattern Stripe / TrueLayer use.
     */
    public function generate_test_connection_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $nonce     = wp_create_nonce('acountpay_test_connection');
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <button type="button" class="button button-secondary" id="acountpay-test-connection-btn"
                    data-nonce="<?php echo esc_attr($nonce); ?>"
                    data-pending="<?php echo esc_attr__('Testing…', ACOUNTPAY_TEXT_DOMAIN); ?>"
                    data-default="<?php echo esc_attr__('Test connection', ACOUNTPAY_TEXT_DOMAIN); ?>"
                ><?php esc_html_e('Test connection', ACOUNTPAY_TEXT_DOMAIN); ?></button>
                <span id="acountpay-test-connection-result" style="margin-left:10px;"></span>
                <p class="description"><?php esc_html_e('Pings the AcountPay API base URL above. Green = your store can reach AcountPay over HTTPS. Red = network / SSL / firewall problem.', ACOUNTPAY_TEXT_DOMAIN); ?></p>
                <script>
                (function(){
                    var btn = document.getElementById('acountpay-test-connection-btn');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        var out = document.getElementById('acountpay-test-connection-result');
                        out.textContent = '';
                        btn.disabled = true;
                        btn.textContent = btn.dataset.pending;
                        var fd = new FormData();
                        fd.append('action', 'acountpay_test_connection');
                        fd.append('_wpnonce', btn.dataset.nonce);
                        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function(r){ return r.text().then(function(t){ return { status: r.status, text: t }; }); })
                            .then(function(resp){
                                btn.disabled = false;
                                btn.textContent = btn.dataset.default;
                                var data = null;
                                try { data = JSON.parse(resp.text); } catch(e) { /* non-JSON */ }
                                if (data && data.success) {
                                    out.innerHTML = '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#d6f4dc;color:#0a7d20;font-weight:600;">' + (data.data && data.data.message ? data.data.message : 'OK') + '</span>';
                                    return;
                                }
                                var msg;
                                if (data && data.data && data.data.message) {
                                    msg = data.data.message;
                                } else if (resp.text === '0' || resp.text === '-1') {
                                    // admin-ajax returns "0" when no action handler is bound and "-1"
                                    // for nonce / capability rejections. Both leave the JS without a
                                    // useful payload, so spell it out for the merchant.
                                    msg = 'Plugin AJAX handler is not registered. Try deactivating + reactivating the AcountPay plugin.';
                                } else {
                                    msg = 'Request failed (HTTP ' + resp.status + ')';
                                }
                                out.innerHTML = '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fde2e2;color:#a40000;font-weight:600;">' + msg + '</span>';
                            })
                            .catch(function(err){
                                btn.disabled = false;
                                btn.textContent = btn.dataset.default;
                                out.innerHTML = '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fde2e2;color:#a40000;font-weight:600;">' + (err && err.message ? err.message : 'Network error') + '</span>';
                            });
                    });
                })();
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Admin AJAX: ping AcountPay API to validate base URL + reachability.
     */
    public function ajax_test_connection()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', ACOUNTPAY_TEXT_DOMAIN)), 403);
        }
        check_ajax_referer('acountpay_test_connection');
        if (!isset($this->api) || !$this->api) {
            wp_send_json_error(array('message' => __('API not initialised', ACOUNTPAY_TEXT_DOMAIN)));
        }
        $result = $this->api->verify_connection();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        wp_send_json_success(array('message' => __('Connection OK', ACOUNTPAY_TEXT_DOMAIN)));
    }

    /**
     * "Refresh bank list" button next to the bank-logos multiselect. Clears
     * the cached bank list so the next page load (or this one, after the
     * AJAX completes and the page reloads) pulls fresh data + logos from
     * AcountPay.
     */
    public function generate_bank_logos_refresh_html($key, $data)
    {
        $field_key = $this->get_field_key($key);
        $nonce     = wp_create_nonce('acountpay_refresh_banks');
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php esc_html_e('Refresh bank list', ACOUNTPAY_TEXT_DOMAIN); ?></label>
            </th>
            <td class="forminp">
                <button type="button" class="button button-secondary" id="acountpay-refresh-banks-btn"
                    data-nonce="<?php echo esc_attr($nonce); ?>"
                    data-pending="<?php echo esc_attr__('Refreshing…', ACOUNTPAY_TEXT_DOMAIN); ?>"
                    data-default="<?php echo esc_attr__('Refresh bank list', ACOUNTPAY_TEXT_DOMAIN); ?>"
                ><?php esc_html_e('Refresh bank list', ACOUNTPAY_TEXT_DOMAIN); ?></button>
                <span id="acountpay-refresh-banks-result" style="margin-left:10px;"></span>
                <p class="description"><?php esc_html_e('Fetches the latest bank list and CDN logos from AcountPay for the country selected above. Cached for 24 hours; click here to force an immediate refresh.', ACOUNTPAY_TEXT_DOMAIN); ?></p>
                <script>
                (function(){
                    var btn = document.getElementById('acountpay-refresh-banks-btn');
                    if (!btn) return;
                    btn.addEventListener('click', function(){
                        var out = document.getElementById('acountpay-refresh-banks-result');
                        out.textContent = '';
                        btn.disabled = true;
                        btn.textContent = btn.dataset.pending;
                        var fd = new FormData();
                        fd.append('action', 'acountpay_refresh_banks');
                        fd.append('_wpnonce', btn.dataset.nonce);
                        var countryEl = document.getElementById('woocommerce_acountpay_payment_bank_country');
                        if (countryEl) { fd.append('country', countryEl.value || 'FI'); }
                        fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                            .then(function(r){ return r.text().then(function(t){ return { status: r.status, text: t }; }); })
                            .then(function(resp){
                                btn.disabled = false;
                                btn.textContent = btn.dataset.default;
                                var data = null;
                                try { data = JSON.parse(resp.text); } catch(e) { /* non-JSON */ }
                                if (data && data.success) {
                                    out.innerHTML = '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#d6f4dc;color:#0a7d20;font-weight:600;">' + (data.data && data.data.message ? data.data.message : 'OK') + '</span>';
                                    setTimeout(function(){ window.location.reload(); }, 800);
                                    return;
                                }
                                var msg;
                                if (data && data.data && data.data.message) {
                                    msg = data.data.message;
                                } else if (resp.text === '0' || resp.text === '-1') {
                                    msg = 'Plugin AJAX handler is not registered. Try deactivating + reactivating the AcountPay plugin.';
                                } else {
                                    msg = 'Request failed (HTTP ' + resp.status + ')';
                                }
                                // Render escaped text so we don't accidentally inject HTML from the server.
                                var span = document.createElement('span');
                                span.style.cssText = 'display:inline-block;padding:4px 10px;border-radius:999px;background:#fde2e2;color:#a40000;font-weight:600;max-width:600px;white-space:normal;line-height:1.4;';
                                span.textContent = msg;
                                out.innerHTML = '';
                                out.appendChild(span);
                            })
                            .catch(function(err){
                                btn.disabled = false;
                                btn.textContent = btn.dataset.default;
                                out.innerHTML = '<span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#fde2e2;color:#a40000;font-weight:600;">' + (err && err.message ? err.message : 'Network error') + '</span>';
                            });
                    });
                })();
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Admin AJAX: drop the cached bank/logo list for the current country and
     * re-pull from AcountPay so the merchant sees changes immediately.
     */
    public function ajax_refresh_banks()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', ACOUNTPAY_TEXT_DOMAIN)), 403);
        }
        check_ajax_referer('acountpay_refresh_banks');

        $country = isset($_POST['country']) ? strtoupper(substr(sanitize_text_field(wp_unslash($_POST['country'])), 0, 2)) : '';
        if ($country === '') {
            $country = strtoupper((string) $this->get_option('bank_country', 'FI'));
        }

        // Drop both the 24h "fresh" cache *and* the 7d "stale" fallback so a
        // truly forced refresh isn't masked by yesterday's empty list.
        $cache_key = 'acountpay_banks_' . strtolower($country);
        delete_transient($cache_key);
        delete_transient($cache_key . '_stale');

        $api = $this->get_api_for_banks();
        if (!$api) {
            wp_send_json_error(array('message' => __('API client could not be initialised — check that the AcountPay plugin is active.', ACOUNTPAY_TEXT_DOMAIN)));
        }

        $result = $api->get_country_banks($country, true);
        if (is_wp_error($result)) {
            $api_base = method_exists($api, 'get_api_base_url') ? $api->get_api_base_url() : '';
            $endpoint = '/v1/banks/public/logos?country=' . $country;
            $detail   = $result->get_error_message();
            if ($detail === '') {
                $detail = $result->get_error_code();
            }
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %1$s = full URL the plugin pinged, %2$s = upstream error message */
                    __('Could not load banks from %1$s — %2$s', ACOUNTPAY_TEXT_DOMAIN),
                    rtrim($api_base, '/') . $endpoint,
                    $detail
                ),
            ));
        }
        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %1$d count of banks, %2$s country code */
                __('%1$d banks loaded for %2$s', ACOUNTPAY_TEXT_DOMAIN),
                count($result),
                $country
            ),
        ));
    }

    /**
     * Persist settings; keep webhook signing secret when the password field is left blank on save.
     * Also drops the bank-list cache when the country changes so the multiselect
     * repopulates from the new country on next render.
     */
    public function process_admin_options()
    {
        $post_key = 'woocommerce_' . $this->id . '_webhook_signing_secret';
        $posted_empty = !isset($_POST[$post_key]) || $_POST[$post_key] === '';
        if ($posted_empty) {
            unset($_POST[$post_key]);
        }

        $previous_country = strtoupper((string) $this->get_option('bank_country', 'FI'));
        $previous_api_url = (string) $this->get_option('api_base_url', 'https://api.acountpay.com');

        parent::process_admin_options();

        $new_country = strtoupper((string) $this->get_option('bank_country', 'FI'));
        $new_api_url = (string) $this->get_option('api_base_url', 'https://api.acountpay.com');

        // Bust the bank transient when the merchant switches country OR
        // changes the API base URL. Without the second branch a merchant
        // who flips from prod → sandbox (or vice-versa) keeps seeing the
        // old environment's logo set for up to 24h, and any merchant who
        // upgraded from a build with the broken (un-versioned) logos
        // endpoint would be stuck looking at bundled placeholders until
        // the cache naturally expired.
        if ($previous_country !== $new_country || $previous_api_url !== $new_api_url) {
            $prev_key = 'acountpay_banks_' . strtolower($previous_country);
            $new_key  = 'acountpay_banks_' . strtolower($new_country);
            delete_transient($prev_key);
            delete_transient($new_key);
            delete_transient($prev_key . '_stale');
            delete_transient($new_key . '_stale');
        }
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

        // Reuse the previously-issued POS URL if it's still fresh (≤30 min)
        // and the order hasn't already been completed. This makes the
        // "Pay" button on the My Account → Orders → Pay page idempotent and
        // avoids charging a second payment-link create per retry.
        $stored_url     = (string) $order->get_meta('_acountpay_pos_url');
        $created_at     = (int) $order->get_meta('_acountpay_link_created_at');
        $stored_is_fresh = $stored_url !== '' && $created_at > 0 && (time() - $created_at) < 30 * MINUTE_IN_SECONDS;
        if ($stored_is_fresh && in_array($order->get_status(), array('pending', 'on-hold'), true)) {
            $this->log_info('receipt_page: reusing existing POS URL', array('order_id' => $order_id));
            wp_redirect($stored_url);
            exit;
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
        // Reuse the reference we stored at process_payment time so retries
        // from My Account → Pay show the *same* text on the customer's bank
        // statement as their original attempt — otherwise a merchant who
        // edited the template between attempts would confuse reconciliation.
        $reference_number = (string) $order->get_meta('_acountpay_reference_number');
        if ($reference_number === '') {
            $reference_number = $this->build_payment_reference($order);
        }

        $idempotency_key = $order->get_meta('_acountpay_idempotency_key');
        if (empty($idempotency_key)) {
            $idempotency_key = wp_generate_uuid4();
            $order->update_meta_data('_acountpay_idempotency_key', $idempotency_key);
        }

        $callback_url = $this->build_signed_callback_url($order_id, $reference_number, (string) $order->get_meta('_acountpay_payment_id'));
        $webhook_url  = add_query_arg('order_id', $order_id, WC()->api_request_url('acountpay_webhook'));

        $payment_data = array(
            'clientId'        => $client_id,
            'amount'          => $amount,
            'referenceNumber' => $reference_number,
            'redirectUrl'     => $callback_url,
            'webhookUrl'      => $webhook_url,
            'description'     => sprintf(__('Payment for order #%s', ACOUNTPAY_TEXT_DOMAIN), $order->get_order_number()),
            'currency'        => $currency,
            'idempotencyKey'  => $idempotency_key,
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
            if ('yes' === $this->get_option('skip_desktop_qr')) {
                $redirect_url = add_query_arg('skipDesktopQr', '1', $redirect_url);
            }

            $payment_id = isset($response['paymentId']) ? (string) $response['paymentId'] : '';
            if ($payment_id !== '') {
                $order->update_meta_data('_acountpay_payment_id', $payment_id);
                $order->set_transaction_id($payment_id);
                $callback_url = $this->build_signed_callback_url($order_id, $reference_number, $payment_id);
                $redirect_url = $this->maybe_replace_redirect_callback($redirect_url, $callback_url);
            }
            // Persist the rendered PSU-visible reference so any later retry
            // (My Account → Pay) reuses the exact same string we just sent
            // — see receipt_page() above.
            if ((string) $order->get_meta('_acountpay_reference_number') === '') {
                $order->update_meta_data('_acountpay_reference_number', $reference_number);
            }
            $order->update_meta_data('_acountpay_pos_url', $redirect_url);
            $order->update_meta_data('_acountpay_link_created_at', time());
            $order->save();

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
     * Show an error-level admin notice when this gateway is enabled but no
     * Webhook signing secret is configured. Without the secret, the plugin
     * rejects every incoming webhook — so live payments will never reach
     * the "processing/completed" state even if the customer pays.
     */
    public function maybe_render_missing_signing_secret_notice()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if ($this->enabled !== 'yes') {
            return;
        }
        $signing_secret = trim((string) $this->get_option('webhook_signing_secret', ''));
        if ($signing_secret !== '') {
            return;
        }

        $settings_url = esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . $this->id));
        $message = __('AcountPay Payment Gateway is enabled but no Webhook signing secret is configured. Incoming payment webhooks will be rejected until you paste the value from your AcountPay Merchant Dashboard → Developer → Webhook signing secret.', ACOUNTPAY_TEXT_DOMAIN);

        echo '<div class="notice notice-error"><p><strong>AcountPay:</strong> '
            . esc_html($message)
            . ' <a href="' . $settings_url . '">' . esc_html__('Open plugin settings', ACOUNTPAY_TEXT_DOMAIN) . '</a>.'
            . '</p></div>';
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields()
    {
        if ($this->description) {
            echo '<div class="acountpay-description">' . wp_kses_post(wpautop(wptexturize($this->description))) . '</div>';
        }

        // Open-banking redirects only succeed back to an HTTPS origin. Warn
        // the customer (in shop admin builds) rather than silently breaking.
        if (!is_ssl()) {
            if (current_user_can('manage_woocommerce')) {
                echo '<div class="acountpay-ssl-notice" style="padding:8px 12px;background:#fff8e5;border:1px solid #f0c36d;border-radius:4px;margin-top:8px;font-size:13px;">'
                    . esc_html__('Pay by Bank requires HTTPS. The bank will refuse to redirect customers back to a non-HTTPS site, so payments will fail until SSL is configured. (Only admins see this notice.)', ACOUNTPAY_TEXT_DOMAIN)
                    . '</div>';
            }
            return;
        }

        if ($this->supports('tokenization') && is_checkout() && $this->saved_cards && is_user_logged_in()) {
            $this->tokenization_script();
            $this->saved_payment_methods();
            $this->save_payment_method_checkbox();
        }
    }

    /**
     * Thank-you page hint shown while the order is still pending/on-hold so
     * the customer doesn't think the payment got lost between the bank app
     * and Woo. Mirrors what Klarna / Trustly / TrueLayer do.
     */
    public function render_awaiting_confirmation_notice($order_id)
    {
        $order = $order_id ? wc_get_order($order_id) : null;
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }
        $status = $order->get_status();
        if (!in_array($status, array('pending', 'on-hold'), true)) {
            return;
        }
        echo '<div class="woocommerce-info acountpay-awaiting" style="margin-top:12px;">'
            . esc_html__('Thanks! We\'re still waiting for your bank to confirm the payment. This usually only takes a few seconds — this page will refresh automatically once it\'s confirmed.', ACOUNTPAY_TEXT_DOMAIN)
            . '</div>';
        // Lightweight auto-refresh so the customer sees the status flip without F5'ing.
        echo '<script>setTimeout(function(){ location.reload(); }, 8000);</script>';
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
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', ACOUNTPAY_TEXT_DOMAIN), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }

        $client_id = trim($this->get_option('client_id', ''));
        if (empty($client_id)) {
            $this->log_error('process_payment: Client ID is missing');
            wc_add_notice(__('Pay by Bank is not configured. Please set your Client ID in the payment settings.', ACOUNTPAY_TEXT_DOMAIN), 'error');
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

        // Stable per-order idempotency key — prevents two "Place Order" clicks
        // from creating two different payment links for the same Woo order.
        $idempotency_key = $order->get_meta('_acountpay_idempotency_key');
        if (empty($idempotency_key)) {
            $idempotency_key = wp_generate_uuid4();
            $order->update_meta_data('_acountpay_idempotency_key', $idempotency_key);
        }

        // PSU-visible bank-statement text. Built from the merchant's
        // configurable template (Settings → Payment reference) so customers
        // see e.g. "Acme Shop #1234" on their bank statement instead of a
        // bare "1234". The backend forwards this verbatim to Token.io as
        // remittanceInformationPrimary, then sanitizes / truncates per-bank.
        $reference_number = $this->build_payment_reference($order);
        $description      = sprintf(__('Payment for order #%s', ACOUNTPAY_TEXT_DOMAIN), $order->get_order_number());
        $callback_url     = $this->build_signed_callback_url($order_id, $reference_number);
        $webhook_url      = add_query_arg('order_id', $order_id, WC()->api_request_url('acountpay_webhook'));

        $payment_data = array(
            'clientId'        => $client_id,
            'amount'          => $amount,
            'referenceNumber' => $reference_number,
            'redirectUrl'     => $callback_url,
            'webhookUrl'      => $webhook_url,
            'description'     => $description,
            'currency'        => $currency,
            'idempotencyKey'  => $idempotency_key,
        );

        $response = $this->api->create_payment_link_v2($payment_data);

        if (is_wp_error($response)) {
            $this->log_error('process_payment: payment-link failed', array('order_id' => $order_id, 'error' => $response->get_error_message()));
            wc_add_notice($response->get_error_message(), 'error');
            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
        }
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

        if ('yes' === $this->get_option('skip_desktop_qr')) {
            $redirect_to_pos = add_query_arg('skipDesktopQr', '1', $redirect_to_pos);
        }

        // Persist payment metadata on the order so the admin meta box, the
        // callback verifier and the webhook handler can cross-reference it.
        $payment_id = isset($response['paymentId']) ? (string) $response['paymentId'] : '';
        if ($payment_id !== '') {
            $order->update_meta_data('_acountpay_payment_id', $payment_id);
            $order->set_transaction_id($payment_id);
            // Re-sign the callback URL with the now-known payment id.
            $callback_url    = $this->build_signed_callback_url($order_id, $reference_number, $payment_id);
            $redirect_to_pos = $this->maybe_replace_redirect_callback($redirect_to_pos, $callback_url);
        }
        $order->update_meta_data('_acountpay_reference_number', $reference_number);
        $order->update_meta_data('_acountpay_pos_url', $redirect_to_pos);
        $order->update_meta_data('_acountpay_currency', $currency);
        $order->update_meta_data('_acountpay_amount', (string) $amount);
        $order->update_meta_data('_acountpay_status', 'created');
        $order->update_meta_data('_acountpay_link_created_at', time());

        // Mark the order as pending until the callback + webhook confirm payment.
        if ($order->get_status() !== 'pending') {
            $order->update_status('pending', __('Awaiting Pay by Bank confirmation.', ACOUNTPAY_TEXT_DOMAIN));
        } else {
            $order->add_order_note(__('Awaiting Pay by Bank confirmation.', ACOUNTPAY_TEXT_DOMAIN));
        }
        $order->save();

        $this->log_info('process_payment: redirecting to POS', array('order_id' => $order_id, 'payment_id' => $payment_id));
        return array(
            'result'   => 'success',
            'redirect' => $redirect_to_pos,
        );
    }

    /**
     * Build a signed callback URL containing order_id, reference, payment id
     * (when known), a timestamp and an HMAC-SHA256 token over those fields.
     * Without this, anyone could call /wc-api/AcountPay_Payment_Gateway?order_id=N&status=failed
     * to flip another customer's order to failed.
     */
    protected function build_signed_callback_url($order_id, $reference_number = '', $payment_id = '')
    {
        $url = WC()->api_request_url('AcountPay_Payment_Gateway');
        $ts  = time();
        $ref = (string) $reference_number;
        $pid = (string) $payment_id;
        $url = add_query_arg(array(
            'order_id'  => (int) $order_id,
            'ref'       => rawurlencode($ref),
            'pid'       => rawurlencode($pid),
            'ts'        => $ts,
        ), $url);
        $secret = trim((string) $this->get_option('webhook_signing_secret', ''));
        if ($secret !== '') {
            $token = hash_hmac('sha256', $order_id . '|' . $ref . '|' . $pid . '|' . $ts, $secret);
            $url   = add_query_arg('token', $token, $url);
        }
        return $url;
    }

    /**
     * If AcountPay built the POS URL by injecting our callback into a query
     * arg (e.g. ?callbackUrl=...), update that arg to the freshly-signed URL
     * once we know the payment id. Falls back to leaving the URL alone if it
     * doesn't carry a callback param.
     */
    protected function maybe_replace_redirect_callback($pos_url, $new_callback_url)
    {
        if (!is_string($pos_url) || $pos_url === '') {
            return $pos_url;
        }
        $parts = wp_parse_url($pos_url);
        if (empty($parts['query'])) {
            return $pos_url;
        }
        parse_str($parts['query'], $q);
        $candidates = array('callbackUrl', 'callback_url', 'redirectUrl', 'redirect_url', 'returnUrl', 'return_url');
        $changed = false;
        foreach ($candidates as $param) {
            if (isset($q[$param])) {
                $q[$param] = $new_callback_url;
                $changed = true;
            }
        }
        if (!$changed) {
            return $pos_url;
        }
        $rebuilt = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
            . (isset($parts['host']) ? $parts['host'] : '')
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . (isset($parts['path']) ? $parts['path'] : '')
            . '?' . http_build_query($q);
        if (!empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }
        return $rebuilt;
    }

    /**
     * Payment validation callback
     * 
     */
    public function acountpay_payment_callback()
    {
        try {
            $this->log_info('Payment callback received');

            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
            $url_ref  = isset($_GET['ref']) ? sanitize_text_field(wp_unslash($_GET['ref'])) : '';
            $url_pid  = isset($_GET['pid']) ? sanitize_text_field(wp_unslash($_GET['pid'])) : '';
            $url_ts   = isset($_GET['ts']) ? absint($_GET['ts']) : 0;
            $url_tok  = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

            if (!$order_id) {
                throw new Exception(__('Order ID is required.', ACOUNTPAY_TEXT_DOMAIN));
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found.', ACOUNTPAY_TEXT_DOMAIN));
            }

            // Reject if this order isn't actually a Pay-by-Bank order. Stops
            // a leaked URL from another site marking a non-AP order paid.
            if ($order->get_payment_method() !== $this->id) {
                $this->log_error('Payment callback: gateway mismatch', array('order_id' => $order_id, 'method' => $order->get_payment_method()));
                throw new Exception(__('Invalid payment session.', ACOUNTPAY_TEXT_DOMAIN));
            }

            // HMAC verification of the callback URL. Without this, anyone can
            // hit /wc-api/AcountPay_Payment_Gateway?order_id=X&status=success
            // and try to flip an order. We always require a token when a
            // signing secret is configured.
            $signing_secret = trim((string) $this->get_option('webhook_signing_secret', ''));
            if ($signing_secret !== '') {
                if ($url_tok === '' || $url_ts <= 0) {
                    $this->log_error('Payment callback: missing token/ts', array('order_id' => $order_id));
                    throw new Exception(__('Invalid or expired payment confirmation link.', ACOUNTPAY_TEXT_DOMAIN));
                }
                // 24h grace — bank redirects can take a long time on mobile.
                if (abs(time() - $url_ts) > DAY_IN_SECONDS) {
                    $this->log_error('Payment callback: expired token', array('order_id' => $order_id, 'ts' => $url_ts));
                    throw new Exception(__('Payment confirmation link has expired. Please retry the payment.', ACOUNTPAY_TEXT_DOMAIN));
                }
                $expected = hash_hmac('sha256', $order_id . '|' . $url_ref . '|' . $url_pid . '|' . $url_ts, $signing_secret);
                if (!hash_equals($expected, $url_tok)) {
                    $this->log_error('Payment callback: invalid token', array('order_id' => $order_id));
                    throw new Exception(__('Invalid payment confirmation link.', ACOUNTPAY_TEXT_DOMAIN));
                }
                // Cross-check pid against stored payment id when both are known.
                $stored_pid = (string) $order->get_meta('_acountpay_payment_id');
                if ($stored_pid !== '' && $url_pid !== '' && $stored_pid !== $url_pid) {
                    $this->log_error('Payment callback: pid mismatch', array('order_id' => $order_id, 'stored' => $stored_pid, 'url' => $url_pid));
                    throw new Exception(__('Invalid payment session.', ACOUNTPAY_TEXT_DOMAIN));
                }
            }

            // Already final? Just bounce to the thank-you page.
            if (in_array($order->get_status(), array('completed', 'processing', 'refunded'), true)) {
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // Treat the URL `status` as a HINT only — the source of truth is
            // the backend verification call (and the signed webhook). Bank
            // redirects can arrive with stale or even forged status values.
            $url_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
            $this->log_info('Payment callback: verifying with backend', array('order_id' => $order_id, 'url_status' => $url_status));

            $backend_status = '';
            if (isset($this->api) && $this->api) {
                $reference = (string) $order->get_meta('_acountpay_reference_number');
                if ($reference === '') {
                    $reference = (string) $order->get_order_number();
                }
                $verify_result = $this->api->verify_payment_status($reference);
                if (!is_wp_error($verify_result) && is_array($verify_result)) {
                    $backend_status = isset($verify_result['status']) ? (string) $verify_result['status'] : '';
                    $this->log_info('Payment callback: backend status', array('order_id' => $order_id, 'backend_status' => $backend_status));
                } else {
                    $this->log_warning('Payment callback: backend verification failed', array('order_id' => $order_id, 'error' => is_wp_error($verify_result) ? $verify_result->get_error_message() : 'invalid response'));
                }
            }

            $is_paid     = in_array($backend_status, array('paid', 'settled', 'completed', 'processed'), true);
            $is_pending  = in_array($backend_status, array('pending', 'processing', 'authorized', ''), true);
            $is_failed   = in_array($backend_status, array('failed', 'rejected', 'failure_expired'), true);
            $is_refunded = in_array($backend_status, array('refunded', 'partially_refunded'), true);

            if ($is_paid) {
                wc_add_notice(__('Payment successful, thank you for your order.', ACOUNTPAY_TEXT_DOMAIN), 'success');
                $order->add_order_note(sprintf(__('Pay by Bank: payment confirmed via callback (backend status: %s).', ACOUNTPAY_TEXT_DOMAIN), $backend_status));
                $order->payment_complete((string) $order->get_meta('_acountpay_payment_id'));
                $this->maybe_apply_paid_status_mapping($order);
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            if ($is_refunded) {
                $order->add_order_note(__('Pay by Bank: backend reports payment as refunded.', ACOUNTPAY_TEXT_DOMAIN));
                $order->update_status('refunded', __('Refunded via Pay by Bank.', ACOUNTPAY_TEXT_DOMAIN));
                wp_safe_redirect($order->get_checkout_order_received_url());
                exit;
            }

            if ($is_failed) {
                $this->apply_failed_status($order, $backend_status ?: $url_status);
                wc_add_notice(__('Payment failed or was cancelled. Please try again.', ACOUNTPAY_TEXT_DOMAIN), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }

            // Pending: stay on-hold and let the webhook close it out. Show
            // the customer the thank-you page with the awaiting-confirmation
            // banner instead of dropping them back onto checkout.
            $order->add_order_note(__('Pay by Bank: bank redirect arrived before final status. Awaiting webhook confirmation.', ACOUNTPAY_TEXT_DOMAIN));
            if (!in_array($order->get_status(), array('on-hold', 'pending'), true)) {
                $order->update_status('on-hold', __('Awaiting payment confirmation from the bank.', ACOUNTPAY_TEXT_DOMAIN));
            }
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        } catch (Exception $e) {
            $this->log_error('Payment callback: exception', array('message' => $e->getMessage()));
            wc_add_notice($e->getMessage(), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }
    }

    /**
     * Honor the "Order status when payment is confirmed" setting after
     * payment_complete() has already run (which only ever picks
     * processing/completed automatically).
     */
    protected function maybe_apply_paid_status_mapping($order)
    {
        $mapping = $this->get_option('paid_order_status', 'default');
        if ($mapping === 'completed' && $order->get_status() !== 'completed') {
            $order->update_status('completed', __('Auto-completed (Pay by Bank setting).', ACOUNTPAY_TEXT_DOMAIN));
        } elseif ($mapping === 'processing' && $order->get_status() !== 'processing') {
            $order->update_status('processing', __('Forced to processing (Pay by Bank setting).', ACOUNTPAY_TEXT_DOMAIN));
        }
    }

    /**
     * Apply the failure status mapping (failed vs pending).
     */
    protected function apply_failed_status($order, $reason = '')
    {
        $target = $this->get_option('failed_order_status', 'failed') === 'pending' ? 'pending' : 'failed';
        $note   = $reason !== ''
            ? sprintf(__('Pay by Bank: payment %s.', ACOUNTPAY_TEXT_DOMAIN), $reason)
            : __('Pay by Bank: payment was not completed.', ACOUNTPAY_TEXT_DOMAIN);
        if ($order->get_status() !== $target) {
            $order->update_status($target, $note);
        } else {
            $order->add_order_note($note);
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

        // The backend sends the signature as `X-AcountPay-Signature` (PHP
        // surfaces it as HTTP_X_ACOUNTPAY_SIGNATURE). Some proxies normalise
        // header casing differently — accept both common spellings.
        $signature = '';
        if (isset($_SERVER['HTTP_X_ACOUNTPAY_SIGNATURE'])) {
            $signature = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ACOUNTPAY_SIGNATURE']));
        } elseif (isset($_SERVER['HTTP_X_ACCOUNTPAY_SIGNATURE'])) {
            $signature = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ACCOUNTPAY_SIGNATURE']));
        }

        $signing_secret = trim((string) $this->get_option('webhook_signing_secret', ''));
        if ($signing_secret === '') {
            $this->log_error('Webhook: Rejected — webhook signing secret not configured in plugin settings');
            wp_send_json(array('received' => false, 'error' => 'signing secret not configured'), 401);
            return;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $raw_body, $signing_secret);
        if ($signature === '' || !hash_equals($expected, $signature)) {
            $this->log_error('Webhook: Invalid or missing signature');
            wp_send_json(array('received' => false, 'error' => 'invalid signature'), 401);
            return;
        }

        $data = json_decode($raw_body, true);
        if (!is_array($data)) {
            $this->log_error('Webhook: Invalid JSON');
            wp_send_json(array('received' => true, 'error' => 'invalid json'), 400);
            return;
        }

        // Dedup by event id when present. AcountPay may retry deliveries on
        // 5xx — we don't want a second delivery to e.g. fire payment_complete
        // again or replay a refund.
        $event_id = isset($data['eventId']) ? sanitize_text_field((string) $data['eventId']) : '';
        if ($event_id !== '') {
            $seen_key = 'acountpay_webhook_seen_' . md5($event_id);
            if (get_transient($seen_key)) {
                $this->log_info('Webhook: duplicate event, ignoring', array('event_id' => $event_id));
                wp_send_json(array('received' => true, 'duplicate' => true));
                return;
            }
            set_transient($seen_key, 1, DAY_IN_SECONDS);
        }

        // Trust the signed body, not query-string `order_id` (which is just a
        // routing hint and can be tampered with by the caller). Resolve the
        // order from referenceNumber → paymentId → query order_id, in order.
        $reference_number = isset($data['referenceNumber']) ? sanitize_text_field((string) $data['referenceNumber']) : '';
        $payment_id       = isset($data['paymentId']) ? sanitize_text_field((string) $data['paymentId']) : '';
        $event            = isset($data['event']) ? sanitize_text_field((string) $data['event']) : 'payment.status_changed';
        $status           = isset($data['status']) ? sanitize_text_field((string) $data['status']) : '';
        $internal_status  = isset($data['internalStatus']) ? sanitize_text_field((string) $data['internalStatus']) : '';
        $amount           = isset($data['amount']) ? floatval($data['amount']) : null;
        $currency         = isset($data['currency']) ? strtoupper(sanitize_text_field((string) $data['currency'])) : '';
        $refunded_amount  = isset($data['refundedAmount']) ? floatval($data['refundedAmount']) : null;

        $order = $this->resolve_order_from_webhook($reference_number, $payment_id);
        if (!$order && isset($_GET['order_id'])) {
            $order = wc_get_order(absint($_GET['order_id']));
            if ($order && $order->get_payment_method() !== $this->id) {
                $order = null;
            }
        }
        if (!$order) {
            $this->log_error('Webhook: Order not resolvable', array('reference' => $reference_number, 'paymentId' => $payment_id));
            wp_send_json(array('received' => true, 'error' => 'order not found'), 404);
            return;
        }

        // Belt-and-braces: only act on AP-paid orders.
        if ($order->get_payment_method() !== $this->id) {
            $this->log_error('Webhook: gateway mismatch on resolved order', array('order_id' => $order->get_id(), 'method' => $order->get_payment_method()));
            wp_send_json(array('received' => true, 'error' => 'gateway mismatch'), 200);
            return;
        }

        // Amount/currency sanity check — refuse to mark an order paid if the
        // backend says "DKK 1.00" but the order is "EUR 199.00".
        if ($amount !== null && $amount > 0) {
            $expected_amount   = (float) $order->get_total();
            $expected_currency = strtoupper((string) $order->get_currency());
            $amount_close      = abs($expected_amount - $amount) <= 0.01;
            $currency_ok       = ($currency === '' || $currency === $expected_currency);
            $is_success_event  = in_array($status, array('success', 'paid', 'settled', 'completed'), true);
            if ($is_success_event && (!$amount_close || !$currency_ok)) {
                $msg = sprintf(
                    __('Pay by Bank: refusing to mark paid — amount/currency mismatch. Order expects %1$s %2$s, webhook reported %3$s %4$s.', ACOUNTPAY_TEXT_DOMAIN),
                    number_format($expected_amount, 2),
                    $expected_currency,
                    number_format($amount, 2),
                    $currency
                );
                $this->log_error('Webhook: amount/currency mismatch', array(
                    'order_id'          => $order->get_id(),
                    'expected_amount'   => $expected_amount,
                    'reported_amount'   => $amount,
                    'expected_currency' => $expected_currency,
                    'reported_currency' => $currency,
                ));
                $order->add_order_note($msg);
                $order->update_status('on-hold', $msg);
                wp_send_json(array('received' => true, 'mismatch' => true));
                return;
            }
        }

        // Stamp metadata so the admin meta box is informative.
        if ($payment_id !== '' && $order->get_meta('_acountpay_payment_id') === '') {
            $order->update_meta_data('_acountpay_payment_id', $payment_id);
            $order->set_transaction_id($payment_id);
        }
        $order->update_meta_data('_acountpay_last_webhook_at', time());
        $order->update_meta_data('_acountpay_last_webhook_event', $event);
        if ($internal_status !== '') {
            $order->update_meta_data('_acountpay_status', $internal_status);
        }

        // Plugin-wide health markers (used by the settings "Webhook health" pill).
        update_option('acountpay_last_webhook_at', time(), false);
        update_option('acountpay_webhook_count', ((int) get_option('acountpay_webhook_count', 0)) + 1, false);

        $current_status = $order->get_status();

        // Refund events first — they can arrive against an already-completed order.
        $is_refund_event = in_array($event, array('payment.refunded', 'payment.partially_refunded'), true)
            || in_array($status, array('refunded', 'partially_refunded'), true)
            || in_array($internal_status, array('refunded', 'partially_refunded'), true);

        if ($is_refund_event) {
            $is_partial = ($event === 'payment.partially_refunded') || ($internal_status === 'partially_refunded') || ($status === 'partially_refunded');
            if ($refunded_amount !== null && $refunded_amount > 0) {
                $order->update_meta_data('_acountpay_refunded_amount', (string) $refunded_amount);
            }
            $note = $is_partial
                ? sprintf(__('Pay by Bank: partially refunded (%1$s %2$s).', ACOUNTPAY_TEXT_DOMAIN), $refunded_amount !== null ? number_format($refunded_amount, 2) : '?', $currency ?: $order->get_currency())
                : sprintf(__('Pay by Bank: refunded (%1$s %2$s).', ACOUNTPAY_TEXT_DOMAIN), $refunded_amount !== null ? number_format($refunded_amount, 2) : (string) $order->get_total(), $currency ?: $order->get_currency());
            $order->add_order_note($note);
            // Only WC's "refunded" status is fully terminal; "partially refunded"
            // doesn't exist as a core status, so we just leave the order in
            // its prior state and rely on the order note + meta for now.
            if (!$is_partial && $order->get_status() !== 'refunded') {
                $order->update_status('refunded', $note);
            }
            $order->save();
            wp_send_json(array('received' => true, 'updated' => true, 'kind' => 'refund'));
            return;
        }

        // Don't regress orders that are already in a final paid state.
        if (in_array($current_status, array('completed'), true)) {
            $order->save();
            $this->log_info('Webhook: Order already final, only meta refreshed', array('order_id' => $order->get_id(), 'current' => $current_status));
            wp_send_json(array('received' => true, 'already_final' => true));
            return;
        }

        if (in_array($status, array('success', 'paid', 'settled', 'completed'), true)) {
            $order->add_order_note(sprintf(
                __('Pay by Bank: payment confirmed via webhook (status: %1$s, amount: %2$s %3$s).', ACOUNTPAY_TEXT_DOMAIN),
                $status,
                $amount !== null ? number_format($amount, 2) : 'N/A',
                $currency ?: $order->get_currency()
            ));
            if ($current_status !== 'processing' && $current_status !== 'completed') {
                $order->payment_complete($payment_id);
            }
            $this->maybe_apply_paid_status_mapping($order);
        } elseif (in_array($status, array('failed', 'rejected'), true) || in_array($internal_status, array('failed', 'failure_expired', 'rejected'), true)) {
            if (!in_array($current_status, array('processing', 'completed'), true)) {
                $this->apply_failed_status($order, $status ?: $internal_status);
            }
        } else {
            $order->add_order_note(sprintf(
                __('Pay by Bank webhook received (event: %1$s, status: %2$s).', ACOUNTPAY_TEXT_DOMAIN),
                $event,
                $status
            ));
        }

        $order->save();
        wp_send_json(array('received' => true, 'updated' => true));
    }

    /**
     * Resolve the WooCommerce order targeted by a webhook from the signed
     * body alone. Tries:
     *  1. Stored _acountpay_payment_id meta lookup.
     *  2. Stored _acountpay_reference_number meta lookup.
     *  3. Order number lookup (covers stores not yet using the new meta).
     */
    protected function resolve_order_from_webhook($reference_number, $payment_id)
    {
        if ($payment_id !== '') {
            $orders = wc_get_orders(array(
                'limit'      => 1,
                'meta_key'   => '_acountpay_payment_id',
                'meta_value' => $payment_id,
                'return'     => 'ids',
            ));
            if (!empty($orders)) {
                return wc_get_order($orders[0]);
            }
        }
        if ($reference_number !== '') {
            $orders = wc_get_orders(array(
                'limit'      => 1,
                'meta_key'   => '_acountpay_reference_number',
                'meta_value' => $reference_number,
                'return'     => 'ids',
            ));
            if (!empty($orders)) {
                return wc_get_order($orders[0]);
            }
            // Fall back to the order number (Woo's get_order_number).
            $maybe = wc_get_order((int) $reference_number);
            if ($maybe) {
                return $maybe;
            }
        }
        return null;
    }

    /**
     * Admin: register the order-edit meta box on both legacy + HPOS screens.
     */
    public function register_order_meta_box()
    {
        $screens = array('shop_order');
        if (class_exists('Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController')) {
            // HPOS uses the woocommerce_page_wc-orders screen id.
            $screens[] = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
        }
        foreach ($screens as $screen) {
            add_meta_box(
                'acountpay-order-meta',
                __('Pay by Bank', ACOUNTPAY_TEXT_DOMAIN),
                array($this, 'render_order_meta_box'),
                $screen,
                'side',
                'default'
            );
        }
    }

    public function render_order_meta_box($post_or_order)
    {
        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order || $order->get_payment_method() !== $this->id) {
            echo '<p>' . esc_html__('Not a Pay by Bank order.', ACOUNTPAY_TEXT_DOMAIN) . '</p>';
            return;
        }
        $pid       = (string) $order->get_meta('_acountpay_payment_id');
        $ref       = (string) $order->get_meta('_acountpay_reference_number');
        $status    = (string) $order->get_meta('_acountpay_status');
        $refunded  = (string) $order->get_meta('_acountpay_refunded_amount');
        $last_hook = (int) $order->get_meta('_acountpay_last_webhook_at');
        $pos_url   = (string) $order->get_meta('_acountpay_pos_url');
        $nonce     = wp_create_nonce('acountpay_reverify_' . $order->get_id());
        ?>
        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Payment ID', ACOUNTPAY_TEXT_DOMAIN); ?>:</strong><br/>
            <code style="word-break:break-all;"><?php echo esc_html($pid !== '' ? $pid : '—'); ?></code>
        </p>
        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Reference', ACOUNTPAY_TEXT_DOMAIN); ?>:</strong> <code><?php echo esc_html($ref !== '' ? $ref : '—'); ?></code></p>
        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Backend status', ACOUNTPAY_TEXT_DOMAIN); ?>:</strong> <code><?php echo esc_html($status !== '' ? $status : '—'); ?></code></p>
        <?php if ($refunded !== '' && (float) $refunded > 0) : ?>
            <p style="margin:0 0 6px;"><strong><?php esc_html_e('Refunded amount', ACOUNTPAY_TEXT_DOMAIN); ?>:</strong> <?php echo esc_html(wc_price((float) $refunded, array('currency' => $order->get_currency()))); ?></p>
        <?php endif; ?>
        <p style="margin:0 0 6px;"><strong><?php esc_html_e('Last webhook', ACOUNTPAY_TEXT_DOMAIN); ?>:</strong>
            <?php echo $last_hook ? esc_html(human_time_diff($last_hook, time()) . ' ' . __('ago', ACOUNTPAY_TEXT_DOMAIN)) : esc_html__('never', ACOUNTPAY_TEXT_DOMAIN); ?>
        </p>
        <?php if ($pos_url !== '') : ?>
            <p style="margin:0 0 6px;"><a href="<?php echo esc_url($pos_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open POS link', ACOUNTPAY_TEXT_DOMAIN); ?></a></p>
        <?php endif; ?>
        <p style="margin-top:10px;">
            <button type="button" class="button button-secondary" id="acountpay-reverify-btn"
                data-order="<?php echo esc_attr((string) $order->get_id()); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Re-verify status', ACOUNTPAY_TEXT_DOMAIN); ?></button>
            <span id="acountpay-reverify-result" style="margin-left:6px;"></span>
        </p>
        <p style="margin-top:6px;font-size:11px;color:#666;">
            <?php esc_html_e('To refund a Pay by Bank order, refund the customer manually from your bank account, then mark the transaction as refunded in your AcountPay Merchant Dashboard. The order here will update automatically.', ACOUNTPAY_TEXT_DOMAIN); ?>
        </p>
        <script>
        (function(){
            var btn = document.getElementById('acountpay-reverify-btn');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var out = document.getElementById('acountpay-reverify-result');
                out.textContent = '…';
                btn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'acountpay_reverify_order');
                fd.append('order_id', btn.dataset.order);
                fd.append('_wpnonce', btn.dataset.nonce);
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        btn.disabled = false;
                        if (data && data.success) {
                            out.textContent = (data.data && data.data.message) ? data.data.message : 'OK';
                            out.style.color = '#0a7d20';
                            setTimeout(function(){ location.reload(); }, 1200);
                        } else {
                            out.textContent = (data && data.data && data.data.message) ? data.data.message : 'Failed';
                            out.style.color = '#a40000';
                        }
                    })
                    .catch(function(err){
                        btn.disabled = false;
                        out.textContent = err.message;
                        out.style.color = '#a40000';
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * Admin AJAX: re-poll the backend for current payment status and update
     * the order accordingly (without waiting for another webhook).
     */
    public function ajax_reverify_order()
    {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', ACOUNTPAY_TEXT_DOMAIN)), 403);
        }
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        check_ajax_referer('acountpay_reverify_' . $order_id);
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            wp_send_json_error(array('message' => __('Not a Pay by Bank order.', ACOUNTPAY_TEXT_DOMAIN)), 404);
        }
        if (!isset($this->api) || !$this->api) {
            wp_send_json_error(array('message' => __('API not initialised.', ACOUNTPAY_TEXT_DOMAIN)));
        }
        $reference = (string) $order->get_meta('_acountpay_reference_number');
        if ($reference === '') {
            $reference = (string) $order->get_order_number();
        }
        $result = $this->api->verify_payment_status($reference);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        if (!is_array($result)) {
            wp_send_json_error(array('message' => __('Unexpected response from AcountPay.', ACOUNTPAY_TEXT_DOMAIN)));
        }
        $backend_status = isset($result['status']) ? (string) $result['status'] : '';
        $order->update_meta_data('_acountpay_status', $backend_status);
        if (in_array($backend_status, array('paid', 'settled', 'completed', 'processed'), true) && !in_array($order->get_status(), array('processing', 'completed'), true)) {
            $order->payment_complete((string) $order->get_meta('_acountpay_payment_id'));
            $this->maybe_apply_paid_status_mapping($order);
        } elseif (in_array($backend_status, array('refunded', 'partially_refunded'), true) && $order->get_status() !== 'refunded') {
            $order->update_status('refunded', __('Pay by Bank: backend reports refunded (manual re-verify).', ACOUNTPAY_TEXT_DOMAIN));
        } elseif (in_array($backend_status, array('failed', 'rejected', 'failure_expired'), true) && !in_array($order->get_status(), array('processing', 'completed'), true)) {
            $this->apply_failed_status($order, $backend_status);
        }
        $order->save();
        wp_send_json_success(array('message' => sprintf(__('Status: %s', ACOUNTPAY_TEXT_DOMAIN), $backend_status ?: 'unknown')));
    }

    /**
     * Order list column.
     */
    public function register_order_list_column($columns)
    {
        $new = array();
        foreach ($columns as $key => $value) {
            $new[$key] = $value;
            if ($key === 'order_status') {
                $new['acountpay_status'] = __('Pay by Bank', ACOUNTPAY_TEXT_DOMAIN);
            }
        }
        if (!isset($new['acountpay_status'])) {
            $new['acountpay_status'] = __('Pay by Bank', ACOUNTPAY_TEXT_DOMAIN);
        }
        return $new;
    }

    public function render_order_list_column($column, $post_id)
    {
        if ($column !== 'acountpay_status') {
            return;
        }
        $order = wc_get_order($post_id);
        $this->render_order_list_column_pill($order);
    }

    public function render_order_list_column_hpos($column, $order)
    {
        if ($column !== 'acountpay_status') {
            return;
        }
        $this->render_order_list_column_pill($order);
    }

    protected function render_order_list_column_pill($order)
    {
        if (!$order || $order->get_payment_method() !== $this->id) {
            echo '—';
            return;
        }
        $status = (string) $order->get_meta('_acountpay_status');
        $color  = '#666';
        $bg     = '#eee';
        if (in_array($status, array('paid', 'settled', 'completed', 'processed'), true)) {
            $color = '#0a7d20';
            $bg    = '#d6f4dc';
        } elseif (in_array($status, array('refunded', 'partially_refunded'), true)) {
            $color = '#5a3eaa';
            $bg    = '#e9defa';
        } elseif (in_array($status, array('failed', 'rejected', 'failure_expired'), true)) {
            $color = '#a40000';
            $bg    = '#fde2e2';
        }
        printf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:%s;color:%s;font-size:11px;">%s</span>',
            esc_attr($bg),
            esc_attr($color),
            esc_html($status !== '' ? $status : '—')
        );
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
