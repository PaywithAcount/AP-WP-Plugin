<?php
//security
if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}
?>
<div class="notice notice-error is-dismissible">
    <p><strong><?php esc_html_e('AcountPay Payment Gateway requires WooCommerce to be installed and active.', 'acountpay-payment'); ?></strong><br>
    <?php esc_html_e('Install and activate WooCommerce first (Plugins → Add New → WooCommerce), then activate AcountPay again.', 'acountpay-payment'); ?></p>
</div>
