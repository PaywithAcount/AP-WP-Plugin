<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package AcountPay_Payment_Gateway
 * @since 1.0.0
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check user permissions
if (!current_user_can('activate_plugins')) {
    return;
}

// Delete plugin options
$option_name = 'woocommerce_acountpay_payment_settings';
delete_option($option_name);

// Delete transients
global $wpdb;
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE %s 
        OR option_name LIKE %s",
        $wpdb->esc_like('_transient_acountpay_') . '%',
        $wpdb->esc_like('_transient_timeout_acountpay_') . '%'
    )
);

// Delete order meta data related to AcountPay
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} 
        WHERE meta_key LIKE %s",
        $wpdb->esc_like('_acountpay_') . '%'
    )
);

// Clear any cached data
wp_cache_flush();
