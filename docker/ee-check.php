<?php
/**
 * Local checks for Estonian bank selection. Run inside WordPress via wp eval-file.
 */

if (!function_exists('wc_create_order')) {
    fwrite(STDERR, "WooCommerce is not loaded\n");
    exit(1);
}

$GLOBALS['failures'] = 0;

function ee_check($label, $ok, $detail = '')
{
    if (!$ok) {
        $GLOBALS['failures']++;
    }
    $status = $ok ? 'PASS' : 'FAIL';
    echo $status . '  ' . $label;
    if ($detail !== '') {
        echo '  (' . $detail . ')';
    }
    echo "\n";
}

$gateways = WC()->payment_gateways()->payment_gateways();
if (!isset($gateways['acountpay_payment'])) {
    fwrite(STDERR, "AcountPay gateway is not registered\n");
    exit(1);
}
$gw = $gateways['acountpay_payment'];

$settings = array(
    'enabled' => 'yes',
    'title' => 'Pay by Bank',
    'description' => 'Pay securely and directly from your bank account.',
    'bank_country' => array('FI', 'DK', 'EE'),
    'client_id' => 'local-test-client',
    'api_base_url' => 'http://banks:8099',
    'logging' => 'yes',
    'sslverify' => 'no',
    'webhook_signing_secret' => 'local-test-secret',
);
update_option('woocommerce_acountpay_payment_settings', $settings);
$gw->init_settings();

// The gateway builds its API client in the constructor, before this script
// can change the saved base URL. Point that client at the local catalogue.
$api_property = new ReflectionProperty($gw, 'api');
$api_property->setAccessible(true);
$api_property->setValue($gw, new AcountPay_API('http://banks:8099', true, false));

foreach (array('ee', 'fi', 'dk') as $cc) {
    delete_transient('acountpay_banks_' . $cc);
    delete_transient('acountpay_banks_' . $cc . '_stale');
}

$countries = $gw->get_bank_countries();
ee_check(
    'Estonia is an enabled bank country',
    in_array('EE', $countries, true),
    implode(',', $countries)
);

$ee_banks = $gw->get_supported_banks(array('EE'));
foreach (array('ee-lhv' => 'LHV Pank', 'ee-swedbank' => 'Swedbank', 'ee-seb' => 'SEB', 'ee-luminor' => 'Luminor', 'ee-coop' => 'Coop Pank') as $id => $name) {
    ee_check(
        'Estonian bank listed: ' . $name,
        isset($ee_banks[$id]) && $ee_banks[$id] === $name,
        isset($ee_banks[$id]) ? $ee_banks[$id] : 'missing'
    );
}

$fi_only = $gw->get_supported_banks(array('FI'));
ee_check(
    'Finnish list does not include LHV',
    !isset($fi_only['ee-lhv']) && isset($fi_only['ngp-okoy']),
    implode(',', array_keys($fi_only))
);

function ee_last_payment()
{
    $response = wp_remote_get('http://banks:8099/__last-payment');
    if (is_wp_error($response)) {
        return array('error' => $response->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : array();
}

function ee_pay($gw, $billing, $currency)
{
    $order = wc_create_order();
    $order->set_currency($currency);
    $order->set_billing_country($billing);
    $order->set_billing_email('shopper@example.test');
    $order->set_payment_method('acountpay_payment');
    $order->set_total($currency === 'DKK' ? 100 : 19.9);
    $order->save();
    $result = $gw->process_payment($order->get_id());
    $sent = ee_last_payment();
    return array(
        'result' => is_array($result) ? ($result['result'] ?? '') : '',
        'country' => isset($sent['posBankCountryCode']) ? $sent['posBankCountryCode'] : '',
        'currency' => isset($sent['currency']) ? $sent['currency'] : '',
    );
}

$cases = array(
    array('EUR', 'EE', 'EE', 'Estonian billing on a euro order uses Estonian banks'),
    array('EUR', 'FI', 'FI', 'Finnish billing on a euro order stays on Finnish banks'),
    array('EUR', 'DK', 'FI', 'Danish billing on a euro order does not use Danish banks'),
    array('DKK', 'DK', 'DK', 'Danish krone order uses Danish banks'),
);

foreach ($cases as $case) {
    list($currency, $billing, $expect, $label) = $case;
    $paid = ee_pay($gw, $billing, $currency);
    ee_check(
        $label,
        $paid['result'] === 'success' && $paid['country'] === $expect && $paid['currency'] === $currency,
        'result=' . $paid['result'] . ' sent=' . $paid['country'] . ' currency=' . $paid['currency']
    );
}

$gw->update_option('bank_country', array('EE'));
$estonia_only = ee_pay($gw, 'DE', 'EUR');
ee_check(
    'Euro order with only Estonia enabled uses Estonian banks',
    $estonia_only['result'] === 'success' && $estonia_only['country'] === 'EE',
    'result=' . $estonia_only['result'] . ' sent=' . $estonia_only['country']
);

$gw->update_option('bank_country', array('FI', 'DK', 'EE'));

if ($GLOBALS['failures'] > 0) {
    echo $GLOBALS['failures'] . " check(s) failed\n";
    exit(1);
}
echo "All Estonian bank checks passed\n";
