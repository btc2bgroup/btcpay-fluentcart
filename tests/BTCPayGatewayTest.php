<?php

namespace BTCPayTests;

use BTCPayForFluentCart\BTCPayGateway;
use FluentCart\App\Helpers\Helper;

class BTCPayGatewayTest extends TestCase
{
    public function test_meta_exposes_btcpay_slug_and_payment_webhook_features(): void
    {
        $this->setGatewaySettings();
        $meta = (new BTCPayGateway())->meta();

        $this->assertSame('btcpay', $meta['slug']);
        $this->assertSame('btcpay', $meta['route']);
        $this->assertTrue($meta['status']);
        $this->assertSame(['payment', 'webhook'], $meta['supported_features']);
    }

    public function test_meta_status_is_false_when_gateway_disabled(): void
    {
        $this->setGatewaySettings(['is_active' => 'no']);

        $this->assertFalse((new BTCPayGateway())->meta()['status']);
    }

    public function test_before_settings_update_encrypts_secrets(): void
    {
        $data = BTCPayGateway::beforeSettingsUpdate([
            'host'           => 'https://btcpay.example.com/',
            'store_id'       => 'STORE123',
            'api_key'        => 'plain-api-key',
            'webhook_secret' => 'plain-webhook-secret',
        ], []);

        $this->assertSame('plain-api-key', Helper::decryptKey($data['api_key']));
        $this->assertNotSame('plain-api-key', $data['api_key']);
        $this->assertSame('plain-webhook-secret', Helper::decryptKey($data['webhook_secret']));
        $this->assertNotSame('plain-webhook-secret', $data['webhook_secret']);
    }

    public function test_before_settings_update_strips_trailing_slash_from_host(): void
    {
        $data = BTCPayGateway::beforeSettingsUpdate([
            'host' => 'https://btcpay.example.com/  ',
        ], []);

        $this->assertSame('https://btcpay.example.com', $data['host']);
    }

    public function test_before_settings_update_leaves_empty_secrets_untouched(): void
    {
        $data = BTCPayGateway::beforeSettingsUpdate([
            'api_key'        => '',
            'webhook_secret' => '',
        ], []);

        $this->assertSame('', $data['api_key']);
        $this->assertSame('', $data['webhook_secret']);
    }

    public function test_webhook_instructions_point_to_fluentcart_ipn_listener(): void
    {
        $this->setGatewaySettings();
        $instructions = (new BTCPayGateway())->getWebhookInstructions();

        $this->assertStringContainsString('fluent-cart=fct_payment_listener_ipn', $instructions['webhook_url']);
        $this->assertStringContainsString('method=btcpay', $instructions['webhook_url']);
    }

    public function test_transaction_url_links_to_btcpay_invoice(): void
    {
        $this->setGatewaySettings();
        $transaction = $this->makeTransaction(['vendor_charge_id' => 'INV_abc123']);

        $url = (new BTCPayGateway())->getTransactionUrl('', ['transaction' => $transaction]);

        $this->assertSame('https://btcpay.example.com/invoices/INV_abc123', $url);
    }

    public function test_settings_helpers_return_decrypted_and_normalized_values(): void
    {
        $this->setGatewaySettings(['host' => 'https://btcpay.example.com/']);
        $settings = new \BTCPayForFluentCart\Settings\BTCPaySettings();

        $this->assertSame('https://btcpay.example.com', $settings->getHost());
        $this->assertSame('STORE123', $settings->getStoreId());
        $this->assertSame('APIKEY456', $settings->getApiKey());
        $this->assertSame('whsecret789', $settings->getWebhookSecret());
        $this->assertTrue($settings->isConfigured());
        $this->assertTrue($settings->isActive());
    }

    public function test_settings_mode_follows_the_store_mode(): void
    {
        $this->setGatewaySettings();
        \FluentCart\Api\StoreSettings::$values = ['order_mode' => 'test'];

        $this->assertSame('test', (new \BTCPayForFluentCart\Settings\BTCPaySettings())->getMode());

        \FluentCart\Api\StoreSettings::$values = ['order_mode' => 'live'];

        $this->assertSame('live', (new \BTCPayForFluentCart\Settings\BTCPaySettings())->getMode());
    }

    public function test_settings_is_configured_is_false_when_anything_is_missing(): void
    {
        foreach (['host', 'store_id', 'api_key'] as $missingKey) {
            $this->setGatewaySettings([$missingKey => '']);
            $settings = new \BTCPayForFluentCart\Settings\BTCPaySettings();

            $this->assertFalse($settings->isConfigured(), "isConfigured() should be false without {$missingKey}");
        }
    }

    // --- Settings validation --------------------------------------------------

    private function validate(array $overrides = []): array
    {
        return BTCPayGateway::validateSettings(array_merge([
            'host'     => 'https://btcpay.example.com',
            'store_id' => 'STORE123',
            'api_key'  => 'some-api-key',
        ], $overrides));
    }

    public function test_valid_https_settings_pass_validation(): void
    {
        $this->assertArrayNotHasKey('status', $this->validate());
    }

    public function test_plain_http_host_is_rejected_so_the_api_key_is_not_sent_in_the_clear(): void
    {
        $result = $this->validate(['host' => 'http://btcpay.example.com']);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('https://', $result['message']);
    }

    public function test_http_is_allowed_for_tor_and_loopback_hosts(): void
    {
        // Tor encrypts the transport itself; loopback never leaves the machine
        $this->assertArrayNotHasKey('status', $this->validate(['host' => 'http://abcdef1234567890.onion']));
        $this->assertArrayNotHasKey('status', $this->validate(['host' => 'http://localhost:23000']));
        $this->assertArrayNotHasKey('status', $this->validate(['host' => 'http://127.0.0.1:23000']));
    }

    public function test_non_url_host_is_rejected(): void
    {
        $this->assertSame('failed', $this->validate(['host' => 'btcpay.example.com'])['status']);
        $this->assertSame('failed', $this->validate(['host' => 'not a url'])['status']);
        $this->assertSame('failed', $this->validate(['host' => ''])['status']);
    }

    public function test_missing_store_id_or_api_key_is_rejected(): void
    {
        $this->assertSame('failed', $this->validate(['store_id' => ''])['status']);
        $this->assertSame('failed', $this->validate(['api_key' => ''])['status']);
    }

    public function test_transaction_url_encodes_the_invoice_id_from_the_webhook(): void
    {
        $this->setGatewaySettings();
        $transaction = $this->makeTransaction(['vendor_charge_id' => 'INV/../evil?x=1']);

        $url = (new BTCPayGateway())->getTransactionUrl('', ['transaction' => $transaction]);

        $this->assertSame('https://btcpay.example.com/invoices/INV%2F..%2Fevil%3Fx%3D1', $url);
    }
}
