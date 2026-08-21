<?php
/**
 * BTCPay Greenfield API Handler
 *
 * Thin wrapper around the BTCPay Server Greenfield REST API using the
 * WordPress HTTP API - no SDK / Composer dependency.
 *
 * @package BTCPayForFluentCart
 * @since 1.0.0
 */

namespace BTCPayForFluentCart\API;

use BTCPayForFluentCart\Settings\BTCPaySettings;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class BTCPayAPI
{
    private static $settings = null;

    /**
     * Get settings instance
     */
    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = new BTCPaySettings();
        }
        return self::$settings;
    }

    private static function request($endpoint, $method = 'GET', $data = [])
    {
        if (empty($endpoint) || !is_string($endpoint)) {
            return new \WP_Error('invalid_endpoint', 'Invalid API endpoint provided');
        }

        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            return new \WP_Error('invalid_method', 'Invalid HTTP method');
        }

        $settings = self::getSettings();

        $host = $settings->getHost();
        $apiKey = $settings->getApiKey();

        if (!$host || !$apiKey) {
            return new \WP_Error(
                'btcpay_not_configured',
                __('BTCPay Server host or API key is not configured.', 'bitcoin-payments-for-fluentcart')
            );
        }

        $url = $host . '/api/v1/' . ltrim($endpoint, '/');

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'token ' . sanitize_text_field($apiKey),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'BTCPayFluentCart/' . BTCPAY_FCT_VERSION . ' WordPress/' . get_bloginfo('version'),
            ],
            'timeout' => 30,
            'sslverify' => true,
        ];

        if (in_array($args['method'], ['POST', 'PUT'], true) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode >= 400) {
            $message = 'Unknown BTCPay Server API error';
            if (is_array($decoded)) {
                // Greenfield errors come as {"code": "...", "message": "..."} or a list of field errors
                if (!empty($decoded['message'])) {
                    $message = $decoded['message'];
                } elseif (!empty($decoded[0]['message'])) {
                    $message = $decoded[0]['message'];
                }
            }

            return new \WP_Error(
                'btcpay_api_error',
                $message,
                ['status' => $statusCode, 'response' => $decoded]
            );
        }

        return $decoded;
    }

    /**
     * Create an invoice on the configured store.
     *
     * POST /api/v1/stores/{storeId}/invoices
     */
    public static function createInvoice($data = [])
    {
        $storeId = self::getSettings()->getStoreId();

        if (!$storeId) {
            return new \WP_Error(
                'btcpay_not_configured',
                __('BTCPay Server Store ID is not configured.', 'bitcoin-payments-for-fluentcart')
            );
        }

        return self::request('stores/' . rawurlencode($storeId) . '/invoices', 'POST', $data);
    }

    /**
     * Fetch an invoice from the configured store.
     *
     * GET /api/v1/stores/{storeId}/invoices/{invoiceId}
     */
    public static function getInvoice($invoiceId)
    {
        $storeId = self::getSettings()->getStoreId();

        if (!$storeId || !$invoiceId) {
            return new \WP_Error(
                'btcpay_not_configured',
                __('BTCPay Server Store ID or invoice ID is missing.', 'bitcoin-payments-for-fluentcart')
            );
        }

        return self::request('stores/' . rawurlencode($storeId) . '/invoices/' . rawurlencode($invoiceId), 'GET');
    }
}
