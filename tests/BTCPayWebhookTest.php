<?php

namespace BTCPayTests;

use BTCPayForFluentCart\Webhook\BTCPayWebhook;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;

/**
 * The real handler ends every branch with sendResponse() + exit; this
 * subclass throws instead so PHPUnit can observe the outcome.
 */
class WebhookResponse extends \Exception
{
    public function __construct(int $statusCode, string $responseMessage)
    {
        parent::__construct($responseMessage, $statusCode);
    }
}

class TestableWebhook extends BTCPayWebhook
{
    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        throw new WebhookResponse($statusCode, $message);
    }
}

class BTCPayWebhookTest extends TestCase
{
    private const SECRET = 'whsecret789';

    private function sign(string $payload): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, self::SECRET);
    }

    private function verifySignature(string $payload): bool
    {
        $method = new \ReflectionMethod(BTCPayWebhook::class, 'verifySignature');
        return $method->invoke(new BTCPayWebhook(), $payload);
    }

    private function expectResponse(int $statusCode, callable $callback): WebhookResponse
    {
        try {
            $callback();
        } catch (WebhookResponse $response) {
            $this->assertSame($statusCode, $response->getCode(), 'Unexpected webhook HTTP status: ' . $response->getMessage());
            return $response;
        }

        $this->fail('Webhook handler did not send a response');
    }

    private function settledPayload(array $overrides = []): array
    {
        return array_merge([
            'deliveryId' => 'del_1',
            'webhookId'  => 'wh_1',
            'type'       => 'InvoiceSettled',
            'storeId'    => 'STORE123',
            'invoiceId'  => 'INV_abc123',
            'metadata'   => [
                'orderId'              => '123',
                'fct_order_hash'       => 'order-hash-abc',
                'fct_transaction_hash' => 'trx-hash-def',
            ],
        ], $overrides);
    }

    // --- Signature verification -------------------------------------------

    public function test_valid_signature_passes(): void
    {
        $this->setGatewaySettings();
        $payload = '{"type":"InvoiceSettled"}';
        $_SERVER['HTTP_BTCPAY_SIG'] = $this->sign($payload);

        $this->assertTrue($this->verifySignature($payload));
    }

    public function test_tampered_payload_fails_verification(): void
    {
        $this->setGatewaySettings();
        $_SERVER['HTTP_BTCPAY_SIG'] = $this->sign('{"type":"InvoiceSettled"}');

        $this->assertFalse($this->verifySignature('{"type":"InvoiceSettled","invoiceId":"evil"}'));
    }

    public function test_wrong_secret_fails_verification(): void
    {
        $this->setGatewaySettings();
        $payload = '{"type":"InvoiceSettled"}';
        $_SERVER['HTTP_BTCPAY_SIG'] = 'sha256=' . hash_hmac('sha256', $payload, 'not-the-secret');

        $this->assertFalse($this->verifySignature($payload));
    }

    public function test_missing_signature_header_fails_verification(): void
    {
        $this->setGatewaySettings();

        $this->assertFalse($this->verifySignature('{"type":"InvoiceSettled"}'));
    }

    public function test_missing_stored_secret_fails_verification(): void
    {
        $this->setGatewaySettings(['webhook_secret' => '']);
        $payload = '{"type":"InvoiceSettled"}';
        $_SERVER['HTTP_BTCPAY_SIG'] = $this->sign($payload);

        $this->assertFalse($this->verifySignature($payload));
    }

    public function test_signature_without_sha256_prefix_fails_verification(): void
    {
        $this->setGatewaySettings();
        $payload = '{"type":"InvoiceSettled"}';
        $_SERVER['HTTP_BTCPAY_SIG'] = hash_hmac('sha256', $payload, self::SECRET);

        $this->assertFalse($this->verifySignature($payload));
    }

    // --- InvoiceSettled -----------------------------------------------------

    public function test_settled_invoice_marks_transaction_paid_and_syncs_order(): void
    {
        $this->setGatewaySettings();
        $order = $this->makeOrder();
        $transaction = $this->makeTransaction(['vendor_charge_id' => 'INV_abc123']);

        $this->expectResponse(200, function () {
            (new TestableWebhook())->handleInvoiceSettled($this->settledPayload());
        });

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->status);
        $this->assertCount(1, StatusHelper::$synced);
        $this->assertSame($order, StatusHelper::$synced[0]['order']);
        $this->assertSame($transaction, StatusHelper::$synced[0]['transaction']);
    }

    public function test_settled_invoice_is_idempotent_when_already_paid(): void
    {
        $this->setGatewaySettings();
        $this->makeOrder();
        $transaction = $this->makeTransaction([
            'vendor_charge_id' => 'INV_abc123',
            'status'           => Status::TRANSACTION_SUCCEEDED,
        ]);

        $response = $this->expectResponse(200, function () {
            (new TestableWebhook())->handleInvoiceSettled($this->settledPayload());
        });

        $this->assertStringContainsString('already confirmed', $response->getMessage());
        $this->assertSame(0, $transaction->saveCount, 'Already-paid transaction must not be re-saved');
        $this->assertCount(0, StatusHelper::$synced);
    }

    public function test_settled_invoice_with_unknown_invoice_returns_404_for_redelivery(): void
    {
        $this->setGatewaySettings();

        $this->expectResponse(404, function () {
            (new TestableWebhook())->handleInvoiceSettled($this->settledPayload());
        });
    }

    public function test_transaction_found_via_metadata_hash_when_invoice_id_does_not_match(): void
    {
        $this->setGatewaySettings();
        $this->makeOrder();
        // vendor_charge_id never stored (e.g. save failed) - only the uuid matches the metadata
        $transaction = $this->makeTransaction(['uuid' => 'trx-hash-def', 'vendor_charge_id' => null]);

        $this->expectResponse(200, function () {
            (new TestableWebhook())->handleInvoiceSettled($this->settledPayload());
        });

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->status);
    }

    public function test_transaction_of_other_payment_method_is_not_touched(): void
    {
        $this->setGatewaySettings();
        $this->makeOrder();
        $transaction = $this->makeTransaction([
            'vendor_charge_id' => 'INV_abc123',
            'payment_method'   => 'stripe',
        ]);

        $this->expectResponse(404, function () {
            (new TestableWebhook())->handleInvoiceSettled($this->settledPayload());
        });

        $this->assertSame('pending', $transaction->status);
    }

    // --- InvoiceExpired / InvoiceInvalid -------------------------------------

    public function test_expired_invoice_marks_pending_transaction_failed(): void
    {
        $this->setGatewaySettings();
        $this->makeOrder();
        $transaction = $this->makeTransaction(['vendor_charge_id' => 'INV_abc123']);

        $this->expectResponse(200, function () {
            (new TestableWebhook())->handleInvoiceFailed(
                $this->settledPayload(['type' => 'InvoiceExpired', 'partiallyPaid' => false])
            );
        });

        $this->assertSame(Status::TRANSACTION_FAILED, $transaction->status);
        $this->assertSame('InvoiceExpired', $transaction->meta['btcpay_fail_reason']);
    }

    public function test_expired_invoice_never_downgrades_a_settled_payment(): void
    {
        $this->setGatewaySettings();
        $this->makeOrder();
        $transaction = $this->makeTransaction([
            'vendor_charge_id' => 'INV_abc123',
            'status'           => Status::TRANSACTION_SUCCEEDED,
        ]);

        $this->expectResponse(200, function () {
            (new TestableWebhook())->handleInvoiceFailed(
                $this->settledPayload(['type' => 'InvoiceExpired'])
            );
        });

        $this->assertSame(Status::TRANSACTION_SUCCEEDED, $transaction->status);
        $this->assertSame(0, $transaction->saveCount);
    }

    public function test_expired_invoice_without_matching_transaction_is_acknowledged(): void
    {
        $this->setGatewaySettings();

        // 200 (not 404): nothing to retry for a failure event we can't match
        $this->expectResponse(200, function () {
            (new TestableWebhook())->handleInvoiceFailed(
                $this->settledPayload(['type' => 'InvoiceExpired'])
            );
        });
    }
}
