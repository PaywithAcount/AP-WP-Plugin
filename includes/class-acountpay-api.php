<?php
/**
 * AcountPay API Handler
 * 
 * Handles all API communications with AcountPay
 * 
 * @package AcountPay_Payment_Gateway
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit('You must not access this file directly');
}

class AcountPay_API
{
    /**
     * API Base URL
     * 
     * @var string
     */
    private $api_base_url;

    /**
     * Logging enabled
     * 
     * @var bool
     */
    private $logging_enabled;

    /**
     * SSL Verification enabled
     * 
     * @var bool
     */
    private $sslverify_enabled;

    /**
     * Constructor
     * 
     * @param string $api_base_url API Base URL (optional)
     * @param bool $logging_enabled Whether logging is enabled (optional)
     * @param bool $sslverify_enabled Whether SSL verification is enabled (optional, defaults to true for security)
     */
    public function __construct($api_base_url = '', $logging_enabled = false, $sslverify_enabled = true)
    {
        $this->logging_enabled = $logging_enabled;
        $this->sslverify_enabled = $sslverify_enabled;
        
        if (empty($api_base_url)) {
            $this->api_base_url = 'https://api.acountpay.com';
        } else {
            $this->api_base_url = rtrim($api_base_url, '/');
        }
    }

    /**
     * Make HTTP request to AcountPay API
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST, etc.)
     * @param array $data Request data
     * @return array|WP_Error Response data or WP_Error on failure
     */
    private function make_request($endpoint, $method = 'GET', $data = array(), $extra_headers = array())
    {
        $url = $this->api_base_url . $endpoint;
        
        // Log connection attempt
        if ($this->logging_enabled && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('AcountPay API: Connection attempt', array(
                'source' => 'acountpay-payment',
                'url' => $url,
                'method' => $method,
                'api_base_url' => $this->api_base_url,
                'endpoint' => $endpoint,
            ));
        }
        
        $headers = array(
            'Content-Type' => 'application/json',
        );
        if (is_array($extra_headers)) {
            foreach ($extra_headers as $h_name => $h_value) {
                if ($h_value === null || $h_value === '') {
                    continue;
                }
                $headers[$h_name] = (string) $h_value;
            }
        }

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => $this->sslverify_enabled, // SSL verification enabled by default for security
        );

        if (in_array($method, array('POST', 'PUT', 'PATCH'), true) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        } elseif ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Enhanced logging for connection errors
            if ($this->logging_enabled && function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error('AcountPay API: Connection failed', array(
                    'source' => 'acountpay-payment',
                    'url' => $url,
                    'method' => $method,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                    'api_base_url' => $this->api_base_url,
                    'endpoint' => $endpoint,
                ));
            }
            
            // Enhance error message with API URL for debugging
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();
            
            // Add API URL to connection-related errors
            if ($error_code === 'http_request_failed') {
                $enhanced_message = sprintf(
                    '%s (API URL: %s)',
                    $error_message,
                    $url
                );
                return new WP_Error($error_code, $enhanced_message, array_merge(
                    $response->get_error_data() ?: array(),
                    array('url' => $url, 'api_base_url' => $this->api_base_url)
                ));
            }
            
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Log request for debugging
        if ($this->logging_enabled && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $log_data = array(
                'source' => 'acountpay-payment',
                'url' => $url,
                'method' => $method,
                'response_code' => $response_code,
            );
            
            // Only log request data for POST/PUT/PATCH, not GET (may contain sensitive info)
            if (in_array($method, array('POST', 'PUT', 'PATCH'), true) && !empty($data)) {
                $log_data['request_data'] = $data;
            }
            
            // Log response (be careful with sensitive data)
            if ($response_code >= 200 && $response_code < 300) {
                $logger->info('AcountPay API Request Success', array_merge($log_data, array('response' => $response_data)));
            } else {
                $logger->error('AcountPay API Request Failed', array_merge($log_data, array('response' => $response_data)));
            }
        }

        // Check for HTTP errors
        if ($response_code < 200 || $response_code >= 300) {
            // Try to extract error message from various possible response formats
            $error_message = 'API request failed';
            
            if (isset($response_data['message'])) {
                $error_message = $response_data['message'];
            } elseif (isset($response_data['error'])) {
                $error_message = is_string($response_data['error']) ? $response_data['error'] : (isset($response_data['error']['message']) ? $response_data['error']['message'] : 'API request failed');
            } elseif (isset($response_data['errors']) && is_array($response_data['errors'])) {
                // Handle validation errors array
                $error_messages = array();
                foreach ($response_data['errors'] as $error) {
                    if (is_string($error)) {
                        $error_messages[] = $error;
                    } elseif (isset($error['message'])) {
                        $error_messages[] = $error['message'];
                    } elseif (isset($error['msg'])) {
                        $error_messages[] = $error['msg'];
                    }
                }
                if (!empty($error_messages)) {
                    $error_message = implode(', ', $error_messages);
                }
            } elseif (isset($response_data['data']['message'])) {
                $error_message = $response_data['data']['message'];
            }
            
            // Include response code in error message for debugging
            $error_message = sprintf(__('API Error (Status %d): %s', 'acountpay-payment'), $response_code, $error_message);
            
            return new WP_Error('api_error', $error_message, array('status' => $response_code, 'response' => $response_data));
        }

        // Ensure we never return a non-array (e.g. "-1" or -1) so callers always get a proper structure or WP_Error
        if (!is_array($response_data)) {
            if ($this->logging_enabled && function_exists('wc_get_logger')) {
                wc_get_logger()->warning('AcountPay API: Response was not a JSON object', array(
                    'source' => 'acountpay-payment',
                    'response_code' => $response_code,
                    'response_type' => gettype($response_data),
                ));
            }
            return new WP_Error(
                'invalid_response',
                __('Payment server returned an invalid response. Please try again.', 'acountpay-payment'),
                array('status' => $response_code, 'response' => $response_data)
            );
        }

        return $response_data;
    }

    /**
     * Get banks from API
     * 
     * @param array $params Optional parameters (countryCode, search, bankType, page, limit)
     * @return array|WP_Error Banks list or WP_Error on failure
     */
    public function get_banks($params = array())
    {
        $endpoint = '/v1/sdk/v1/banks';
        
        // Build query parameters - set a high limit to get all banks
        $query_params = array();
        if (!empty($params)) {
            $query_params = $params;
        }
        
        // If no limit specified, set a high limit to get all banks
        if (!isset($query_params['limit'])) {
            $query_params['limit'] = 1000; // High limit to get all banks
        }

        $all_banks = array();
        $page = isset($query_params['page']) ? intval($query_params['page']) : 1;
        $has_more = true;

        // Fetch all pages of banks
        while ($has_more) {
            $current_params = $query_params;
            $current_params['page'] = $page;
            
            $response = $this->make_request($endpoint, 'GET', $current_params);

            if (is_wp_error($response)) {
                // If first page fails, return error
                if ($page === 1) {
                    return $response;
                }
                // If subsequent pages fail, return what we have so far
                if ($this->logging_enabled && function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->warning('AcountPay API: Failed to fetch page ' . $page . ' of banks, returning ' . count($all_banks) . ' banks collected so far', array('source' => 'acountpay-payment'));
                }
                break;
            }

            // Log raw response structure for debugging
            if ($this->logging_enabled && function_exists('wc_get_logger') && $page === 1) {
                $logger = wc_get_logger();
                $logger->info('AcountPay API: Raw response structure', array(
                    'source' => 'acountpay-payment',
                    'has_data_key' => isset($response['data']),
                    'is_array' => is_array($response),
                    'has_pagination' => isset($response['pagination']),
                    'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
                    'pagination_info' => isset($response['pagination']) ? $response['pagination'] : 'none'
                ));
            }

            // Extract banks from response
            $banks = array();
            if (isset($response['data']) && is_array($response['data'])) {
                $banks = $response['data'];
            } elseif (is_array($response) && isset($response[0])) {
                $banks = $response;
            }

            // Add banks to the collection
            if (!empty($banks)) {
                $all_banks = array_merge($all_banks, $banks);
                
                // Log progress
                if ($this->logging_enabled && function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->info('AcountPay API: Fetched page ' . $page . ' with ' . count($banks) . ' banks (total so far: ' . count($all_banks) . ')', array('source' => 'acountpay-payment'));
                }
            }

            // Check if there are more pages
            if (isset($response['pagination']) && is_array($response['pagination'])) {
                $pagination = $response['pagination'];
                $current_page = isset($pagination['page']) ? intval($pagination['page']) : $page;
                $total_pages = isset($pagination['totalPages']) ? intval($pagination['totalPages']) : 1;
                $has_more = $current_page < $total_pages;
                $page = $current_page + 1;
                
                // Log pagination info
                if ($this->logging_enabled && function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->info('AcountPay API: Pagination info', array(
                        'source' => 'acountpay-payment',
                        'current_page' => $current_page,
                        'total_pages' => $total_pages,
                        'has_more' => $has_more
                    ));
                }
            } else {
                // If no pagination info, check if we got fewer banks than the limit
                $limit = isset($current_params['limit']) ? intval($current_params['limit']) : 1000;
                $banks_count = count($banks);
                $has_more = $banks_count >= $limit;
                
                // Log pagination decision
                if ($this->logging_enabled && function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->info('AcountPay API: No pagination info, checking limit', array(
                        'source' => 'acountpay-payment',
                        'banks_count' => $banks_count,
                        'limit' => $limit,
                        'has_more' => $has_more
                    ));
                }
                
                $page++;
            }

            // Safety check to prevent infinite loops
            if ($page > 100) {
                if ($this->logging_enabled && function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->warning('AcountPay API: Reached maximum page limit (100), stopping pagination', array('source' => 'acountpay-payment'));
                }
                break;
            }
        }
        
        // Log final result
        if ($this->logging_enabled && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('AcountPay API: Fetched all banks, total count: ' . count($all_banks), array('source' => 'acountpay-payment'));
        }

        return $all_banks;
    }

    /**
     * Create v2 payment link (extension flow – bank selected on AcountPay POS).
     * Requires: clientId, amount, referenceNumber, redirectUrl.
     * Optional: description, currency, webhookUrl, idempotencyKey.
     *
     * The idempotencyKey is sent as an `Idempotency-Key` header — if the backend
     * supports it, repeated calls with the same key (e.g. two "Place Order"
     * clicks) return the same payment link instead of creating a duplicate.
     *
     * @param array $data Payment link parameters
     * @return array|WP_Error Response with redirectUrl (POS pay page) or WP_Error on failure
     */
    public function create_payment_link_v2($data)
    {
        $endpoint = '/v1/sdk/v2/payment-link';

        $required = array('clientId', 'amount', 'referenceNumber', 'redirectUrl');
        foreach ($required as $field) {
            if (!isset($data[$field]) || (string) $data[$field] === '') {
                return new WP_Error('missing_field', sprintf(__('Missing required field: %s', 'acountpay-payment'), $field));
            }
        }

        $body = array(
            'clientId' => $data['clientId'],
            'amount' => floatval($data['amount']),
            'referenceNumber' => (string) $data['referenceNumber'],
            'redirectUrl' => $data['redirectUrl'],
        );
        if (!empty($data['description'])) {
            $body['description'] = $data['description'];
        }
        if (!empty($data['currency'])) {
            $body['currency'] = strtoupper(substr($data['currency'], 0, 3));
        }
        if (!empty($data['webhookUrl'])) {
            $body['webhookUrl'] = $data['webhookUrl'];
        }

        $headers = array();
        if (!empty($data['idempotencyKey'])) {
            $headers['Idempotency-Key'] = (string) $data['idempotencyKey'];
        }

        return $this->make_request($endpoint, 'POST', $body, $headers);
    }

    /**
     * Verify payment status with the backend.
     * Uses the notifications/transaction-status endpoint to check actual payment status.
     *
     * @param string $reference_number The order reference number
     * @return array|WP_Error Payment status data or WP_Error on failure
     */
    public function verify_payment_status($reference_number)
    {
        $endpoint = '/v1/notifications/transaction-status?referenceNumber=' . urlencode($reference_number);

        $response = $this->make_request($endpoint, 'GET');

        return $response;
    }

    /**
     * Fetch the slim, public bank-logo list for a country and cache it as a
     * WordPress transient. Falls back to the cached copy on transient errors.
     *
     * Format returned:
     *   array(
     *     array('bankId' => 'ngp-okoy', 'name' => 'OP Pohjola', 'logoUrl' => 'https://…png'),
     *     ...
     *   )
     *
     * @param string $country_code ISO 3166-1 alpha-2 (e.g. "FI", "DK").
     * @param bool   $force_refresh When true, bypasses the cache.
     * @return array<int,array<string,string>>|WP_Error
     */
    public function get_country_banks($country_code = 'FI', $force_refresh = false)
    {
        $country_code = strtoupper(substr((string) $country_code, 0, 2));
        if ($country_code === '') {
            return new WP_Error('invalid_country', 'Country code is required');
        }

        $cache_key = 'acountpay_banks_' . strtolower($country_code);

        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $endpoint = '/banks/public/logos?country=' . rawurlencode($country_code);
        $response = $this->make_request($endpoint, 'GET');

        if (is_wp_error($response)) {
            // On error, return the stale cache if any so the carousel doesn't
            // suddenly disappear when the API hiccups.
            $stale = get_transient($cache_key . '_stale');
            if (is_array($stale)) {
                return $stale;
            }
            return $response;
        }

        if (!is_array($response) || empty($response['banks']) || !is_array($response['banks'])) {
            $stale = get_transient($cache_key . '_stale');
            if (is_array($stale)) {
                return $stale;
            }
            return new WP_Error('invalid_response', 'Bank list response was empty or malformed');
        }

        $clean = array();
        foreach ($response['banks'] as $b) {
            if (empty($b['bankId']) || empty($b['logoUrl'])) {
                continue;
            }
            $logo_url = (string) $b['logoUrl'];
            if (!preg_match('#^https?://#i', $logo_url)) {
                continue;
            }
            $clean[] = array(
                'bankId'  => sanitize_text_field((string) $b['bankId']),
                'name'    => sanitize_text_field((string) ($b['name'] ?? $b['bankId'])),
                'logoUrl' => esc_url_raw($logo_url),
            );
        }

        if (empty($clean)) {
            $stale = get_transient($cache_key . '_stale');
            if (is_array($stale)) {
                return $stale;
            }
            return new WP_Error('empty_result', 'No banks with logos returned');
        }

        // Fresh cache: 24h. Long-lived stale cache: 7d (used as fallback when
        // the API fails so checkout never renders an empty/text-only carousel).
        set_transient($cache_key, $clean, DAY_IN_SECONDS);
        set_transient($cache_key . '_stale', $clean, 7 * DAY_IN_SECONDS);

        return $clean;
    }

    /**
     * Get API base URL
     * 
     * @return string
     */
    public function get_api_base_url()
    {
        return $this->api_base_url;
    }

    /**
     * Verify API server connection
     * 
     * @return bool|WP_Error True if connection successful, WP_Error on failure
     */
    public function verify_connection()
    {
        // Try a simple GET request to verify connection
        $test_endpoint = '/v1/sdk/v1/banks';
        $test_url = $this->api_base_url . $test_endpoint;
        
        $headers = array(
            'Content-Type' => 'application/json',
        );

        $args = array(
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 5, // Short timeout for connection check
            'sslverify' => $this->sslverify_enabled, // SSL verification enabled by default for security
        );

        $response = wp_remote_request($test_url, $args);

        if (is_wp_error($response)) {
            $error_code = $response->get_error_code();
            $error_message = $response->get_error_message();
            
            // Provide helpful error message
            if ($error_code === 'http_request_failed' && strpos($error_message, 'Connection refused') !== false) {
                return new WP_Error(
                    'connection_refused',
                    sprintf(
                        __('Cannot connect to AcountPay API server at %s. Please ensure the API server is running and accessible.', 'acountpay-payment'),
                        $this->api_base_url
                    ),
                    array('url' => $test_url, 'error' => $error_message)
                );
            }
            
            return new WP_Error(
                'connection_failed',
                sprintf(
                    __('Failed to connect to AcountPay API server at %s. Error: %s', 'acountpay-payment'),
                    $this->api_base_url,
                    $error_message
                ),
                array('url' => $test_url, 'error' => $error_message)
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 200 && $response_code < 300) {
            return true;
        }

        return new WP_Error(
            'connection_test_failed',
            sprintf(
                __('API server responded with status code %d. Server may be experiencing issues.', 'acountpay-payment'),
                $response_code
            ),
            array('url' => $test_url, 'status' => $response_code)
        );
    }
}

