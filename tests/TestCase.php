<?php

namespace BTCPayTests;

use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Payments\PaymentInstance;
use BTCPayForFluentCart\API\BTCPayAPI;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BaseGatewaySettings::$testSettings = [];
        \FluentCart\Api\StoreSettings::$values = ['order_mode' => 'live'];
        Order::$records = [];
        OrderTransaction::$records = [];
        StatusHelper::$synced = [];

        $GLOBALS['btcpay_test_http_handler'] = null;
        $GLOBALS['btcpay_test_http_requests'] = [];
        $GLOBALS['btcpay_test_logs'] = [];
        $GLOBALS['btcpay_test_actions'] = [];

        unset($_SERVER['HTTP_BTCPAY_SIG']);

        // BTCPayAPI caches its settings instance across calls - reset between tests
        $prop = new \ReflectionProperty(BTCPayAPI::class, 'settings');
        $prop->setValue(null, null);
    }

    /**
     * Configure the gateway the way FluentCart would store it
     * (api_key and webhook_secret encrypted at rest).
     */
    protected function setGatewaySettings(array $overrides = []): void
    {
        BaseGatewaySettings::$testSettings = array_merge([
            'is_active'      => 'yes',
            'host'           => 'https://btcpay.example.com',
            'store_id'       => 'STORE123',
            'api_key'        => Helper::encryptKey('APIKEY456'),
            'webhook_secret' => Helper::encryptKey('whsecret789'),
        ], $overrides);
    }

    protected function makeOrder(array $attributes = []): Order
    {
        $order = new Order();
        $order->id = $attributes['id'] ?? 123;
        $order->uuid = $attributes['uuid'] ?? 'order-hash-abc';
        $order->customer = (object)['email' => $attributes['email'] ?? 'satoshi@example.com'];

        Order::$records[] = $order;

        return $order;
    }

    protected function makeTransaction(array $attributes = []): OrderTransaction
    {
        $transaction = new OrderTransaction();
        $transaction->uuid = $attributes['uuid'] ?? 'trx-hash-def';
        $transaction->order_id = $attributes['order_id'] ?? 123;
        $transaction->status = $attributes['status'] ?? 'pending';
        $transaction->payment_method = $attributes['payment_method'] ?? 'btcpay';
        $transaction->vendor_charge_id = $attributes['vendor_charge_id'] ?? null;
        $transaction->currency = $attributes['currency'] ?? 'USD';
        $transaction->total = $attributes['total'] ?? 1050;

        OrderTransaction::$records[] = $transaction;

        return $transaction;
    }

    protected function makePaymentInstance(Order $order, OrderTransaction $transaction): PaymentInstance
    {
        return new PaymentInstance($order, $transaction);
    }

    /**
     * Queue a canned HTTP response for the next BTCPay API call.
     */
    protected function mockHttpResponse(int $statusCode, array $body): void
    {
        $GLOBALS['btcpay_test_http_handler'] = function ($url, $args) use ($statusCode, $body) {
            return [
                'response' => ['code' => $statusCode],
                'body'     => json_encode($body),
            ];
        };
    }

    protected function lastHttpRequest(): ?array
    {
        $requests = $GLOBALS['btcpay_test_http_requests'];
        return $requests ? end($requests) : null;
    }
}
