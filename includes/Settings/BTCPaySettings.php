<?php
/**
 * BTCPay Settings Base Class
 *
 * @package BTCPayForFluentCart
 * @since 1.0.0
 */

namespace BTCPayForFluentCart\Settings;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class BTCPaySettings extends BaseGatewaySettings
{
    public $settings;
    public $methodHandler = 'fluent_cart_payment_settings_btcpay';

    public function __construct()
    {
        parent::__construct();
        $settings = $this->getCachedSettings();
        $defaults = static::getDefaults();

        if (!$settings || !is_array($settings) || empty($settings)) {
            $settings = $defaults;
        } else {
            $settings = wp_parse_args($settings, $defaults);
        }

        $this->settings = apply_filters('btcpay_fc/btcpay_settings', $settings);
    }

    public static function getDefaults()
    {
        return [
            'is_active'      => 'no',
            'host'           => '',
            'store_id'       => '',
            'api_key'        => '',
            'webhook_secret' => '',
        ];
    }

    public function isActive(): bool
    {
        return $this->settings['is_active'] == 'yes';
    }

    public function get($key = '')
    {
        $settings = $this->settings;

        if ($key && isset($this->settings[$key])) {
            return $this->settings[$key];
        }
        return $settings;
    }

    public function getHost()
    {
        return untrailingslashit(trim((string)$this->get('host')));
    }

    public function getStoreId()
    {
        return trim((string)$this->get('store_id'));
    }

    public function getApiKey()
    {
        $apiKey = $this->get('api_key');

        if (!$apiKey) {
            return '';
        }

        return Helper::decryptKey($apiKey);
    }

    public function getWebhookSecret()
    {
        $secret = $this->get('webhook_secret');

        if (!$secret) {
            return '';
        }

        return Helper::decryptKey($secret);
    }

    /**
     * True when everything needed to create invoices is present.
     */
    public function isConfigured(): bool
    {
        return $this->getHost() && $this->getStoreId() && $this->getApiKey();
    }
}
