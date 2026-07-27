<?php

namespace BTCPayTests;

use BTCPayForFluentCart\Onetime\BTCPayProcessor;

class BTCPayProcessorTest extends TestCase
{
    private function successfulInvoiceResponse(): array
    {
        return [
            'id'           => 'INV_abc123',
            'checkoutLink' => 'https://btcpay.example.com/i/INV_abc123',
            'status'       => 'New',
        ];
    }

    public function test_creates_invoice_and_returns_redirect_to_checkout_link(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction();
        $this->mockHttpResponse(200, $this->successfulInvoiceResponse());

        $result = (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $this->assertIsArray($result);
        $this->assertSame('success', $result['status']);
        $this->assertSame('https://btcpay.example.com/i/INV_abc123', $result['redirect_to']);
    }

    public function test_invoice_metadata_contains_fluentcart_order_id_and_hashes(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder(['id' => 777, 'uuid' => 'order-uuid-777']);
        $transaction = $this->makeTransaction(['uuid' => 'trx-uuid-777']);
        $this->mockHttpResponse(200, $this->successfulInvoiceResponse());

        (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $body = json_decode($this->lastHttpRequest()['args']['body'], true);

        $this->assertSame('777', $body['metadata']['orderId']);
        $this->assertSame('order-uuid-777', $body['metadata']['fct_order_hash']);
        $this->assertSame('trx-uuid-777', $body['metadata']['fct_transaction_hash']);
        $this->assertSame('satoshi@example.com', $body['metadata']['buyerEmail']);
    }

    public function test_invoice_request_carries_amount_currency_and_redirect_url(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction(['total' => 1050, 'currency' => 'USD']);
        $this->mockHttpResponse(200, $this->successfulInvoiceResponse());

        (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $request = $this->lastHttpRequest();
        $body = json_decode($request['args']['body'], true);

        $this->assertSame(
            'https://btcpay.example.com/api/v1/stores/STORE123/invoices',
            $request['url']
        );
        $this->assertSame('10.50', $body['amount']);
        $this->assertSame('USD', $body['currency']);
        $this->assertSame('https://shop.example.com/thank-you', $body['checkout']['redirectURL']);
        $this->assertTrue($body['checkout']['redirectAutomatically']);
    }

    public function test_zero_decimal_currency_amount_is_not_divided(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction(['total' => 1050, 'currency' => 'JPY']);
        $this->mockHttpResponse(200, $this->successfulInvoiceResponse());

        (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $body = json_decode($this->lastHttpRequest()['args']['body'], true);

        $this->assertSame('1050', $body['amount']);
        $this->assertSame('JPY', $body['currency']);
    }

    public function test_lowercase_transaction_currency_is_uppercased(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction(['currency' => 'eur']);
        $this->mockHttpResponse(200, $this->successfulInvoiceResponse());

        (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $body = json_decode($this->lastHttpRequest()['args']['body'], true);

        $this->assertSame('EUR', $body['currency']);
    }

    public function test_stores_invoice_id_on_transaction_for_webhook_lookup(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction();
        $this->mockHttpResponse(200, $this->successfulInvoiceResponse());

        (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $this->assertSame('INV_abc123', $transaction->vendor_charge_id);
        $this->assertGreaterThan(0, $transaction->saveCount);
    }

    public function test_returns_error_when_gateway_is_not_configured(): void
    {
        $this->setGatewaySettings(['host' => '', 'api_key' => '']);
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction();

        $result = (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('btcpay_not_configured', $result->get_error_code());
        $this->assertSame([], $GLOBALS['btcpay_test_http_requests'], 'No API call should be made when unconfigured');
    }

    public function test_returns_error_when_btcpay_rejects_the_invoice(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction();
        $this->mockHttpResponse(400, ['code' => 'invalid-currency', 'message' => 'Currency FOO is not supported']);

        $result = (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        // Customers only ever see Bitcoin wording; the raw BTCPay message is carried as error data.
        $this->assertStringContainsString('Bitcoin payment could not be started', $result->get_error_message());
        $this->assertSame(
            'Currency FOO is not supported',
            $result->get_error_data()['btcpay_error'] ?? null
        );
        $this->assertNull($transaction->vendor_charge_id);
        $this->assertSame(0, $transaction->saveCount);
    }

    public function test_returns_error_when_response_is_missing_checkout_link(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction();
        $this->mockHttpResponse(200, ['id' => 'INV_abc123']); // no checkoutLink

        $result = (new BTCPayProcessor())->handleSinglePayment(
            $this->makePaymentInstance($order, $transaction),
            ['success_url' => 'https://shop.example.com/thank-you']
        );

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('btcpay_invalid_response', $result->get_error_code());
    }
}
