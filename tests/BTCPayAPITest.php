<?php

namespace BTCPayTests;

use BTCPayForFluentCart\API\BTCPayAPI;

class BTCPayAPITest extends TestCase
{
    public function test_requests_use_token_authorization_and_greenfield_url(): void
    {
        $this->setGatewaySettings();
        $this->mockHttpResponse(200, ['id' => 'INV_1']);

        BTCPayAPI::createInvoice(['amount' => '10.00', 'currency' => 'USD']);

        $request = $this->lastHttpRequest();

        $this->assertSame('https://btcpay.example.com/api/v1/stores/STORE123/invoices', $request['url']);
        $this->assertSame('POST', $request['args']['method']);
        $this->assertSame('token APIKEY456', $request['args']['headers']['Authorization']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);
        $this->assertTrue($request['args']['sslverify']);
    }

    public function test_api_key_is_decrypted_before_being_sent(): void
    {
        // setGatewaySettings stores the key encrypted; the header must carry the plain key
        $this->setGatewaySettings();
        $this->mockHttpResponse(200, []);

        BTCPayAPI::getInvoice('INV_1');

        $auth = $this->lastHttpRequest()['args']['headers']['Authorization'];
        $this->assertSame('token APIKEY456', $auth);
        $this->assertStringNotContainsString('enc:', $auth);
    }

    public function test_get_invoice_hits_invoice_endpoint(): void
    {
        $this->setGatewaySettings();
        $this->mockHttpResponse(200, ['id' => 'INV_1', 'status' => 'Settled']);

        $invoice = BTCPayAPI::getInvoice('INV_1');

        $this->assertSame(
            'https://btcpay.example.com/api/v1/stores/STORE123/invoices/INV_1',
            $this->lastHttpRequest()['url']
        );
        $this->assertSame('Settled', $invoice['status']);
    }

    public function test_missing_configuration_returns_wp_error_without_api_call(): void
    {
        $this->setGatewaySettings(['host' => '', 'api_key' => '']);

        $result = BTCPayAPI::createInvoice(['amount' => '10.00']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('btcpay_not_configured', $result->get_error_code());
        $this->assertSame([], $GLOBALS['btcpay_test_http_requests']);
    }

    public function test_missing_store_id_returns_wp_error(): void
    {
        $this->setGatewaySettings(['store_id' => '']);

        $result = BTCPayAPI::createInvoice(['amount' => '10.00']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('btcpay_not_configured', $result->get_error_code());
    }

    public function test_greenfield_object_error_is_mapped_to_wp_error(): void
    {
        $this->setGatewaySettings();
        $this->mockHttpResponse(403, ['code' => 'unauthorized', 'message' => 'Insufficient API permissions']);

        $result = BTCPayAPI::createInvoice(['amount' => '10.00']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('btcpay_api_error', $result->get_error_code());
        $this->assertSame('Insufficient API permissions', $result->get_error_message());
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function test_greenfield_field_error_list_is_mapped_to_wp_error(): void
    {
        $this->setGatewaySettings();
        $this->mockHttpResponse(422, [['path' => 'amount', 'message' => 'Amount must be positive']]);

        $result = BTCPayAPI::createInvoice(['amount' => '-1']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('Amount must be positive', $result->get_error_message());
    }

    public function test_transport_error_is_passed_through(): void
    {
        $this->setGatewaySettings();
        $GLOBALS['btcpay_test_http_handler'] = function () {
            return new \WP_Error('http_request_failed', 'cURL error 28: timed out');
        };

        $result = BTCPayAPI::createInvoice(['amount' => '10.00']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('http_request_failed', $result->get_error_code());
    }
}
