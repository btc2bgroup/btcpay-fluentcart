<?php

namespace BTCPayForFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
use BTCPayForFluentCart\API\BTCPayAPI;
use BTCPayForFluentCart\Onetime\BTCPayProcessor;
use BTCPayForFluentCart\Settings\BTCPaySettings;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class BTCPayWebhook
{
    /**
     * Verify and process a BTCPay Server webhook delivery.
     *
     * The payload is never touched before the BTCPay-Sig HMAC check passes.
     */
    public function verifyAndProcess()
    {
        $payload = $this->getWebhookPayload();
        if (is_wp_error($payload)) {
            $this->sendResponse(400, 'Not a valid payload');
        }

        if (!$this->verifySignature($payload)) {
            $this->sendResponse(401, 'Invalid signature / Verification failed');
        }

        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->sendResponse(400, 'Invalid JSON payload');
        }

        $type = Arr::get($data, 'type');
        $invoiceId = Arr::get($data, 'invoiceId');

        if (!$type || !$invoiceId) {
            // Signed, but not an invoice event we can act on - acknowledge so BTCPay stops retrying
            $this->sendResponse(200, 'Webhook received but not handled');
        }

        if (!self::isValidInvoiceId($invoiceId)) {
            $this->sendResponse(400, 'Malformed invoice ID');
        }

        do_action('fluent_cart/payments/btcpay/webhook_event', $data);

        switch ($type) {
            case 'InvoiceSettled':
                $this->handleInvoiceSettled($data);
                break;
            case 'InvoiceExpired':
            case 'InvoiceInvalid':
                $this->handleInvoiceFailed($data);
                break;
        }

        // Any other event type (InvoiceCreated, InvoiceProcessing, ...) is intentionally a no-op
        $this->sendResponse(200, 'Webhook received but event type not handled');
    }

    private function getWebhookPayload()
    {
        $input = file_get_contents('php://input');

        // Check payload size (max 1MB)
        if (strlen($input) > 1048576) {
            return new \WP_Error('payload_too_large', 'Webhook payload too large');
        }

        if (empty($input)) {
            return new \WP_Error('empty_payload', 'Empty webhook payload');
        }

        return $input;
    }

    /**
     * BTCPay signs the raw body with HMAC-SHA256 using the webhook secret and
     * sends it as `BTCPay-Sig: sha256=<hex>`.
     */
    private function verifySignature($payload)
    {
        $signature = $this->getSignatureHeader();

        if (!$signature) {
            return false;
        }

        $secret = (new BTCPaySettings())->getWebhookSecret();

        if (!$secret) {
            return false;
        }

        $computedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($computedSignature, $signature);
    }

    private function getSignatureHeader()
    {
        if (isset($_SERVER['HTTP_BTCPAY_SIG'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_BTCPAY_SIG']));
        }

        // Fallback: case-insensitive header scan, like BTCPay's own PHP example
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $key => $value) {
                if (strtolower($key) === 'btcpay-sig') {
                    return sanitize_text_field($value);
                }
            }
        }

        return '';
    }

    /**
     * InvoiceSettled: payment fully confirmed - mark the FluentCart order paid.
     */
    public function handleInvoiceSettled($data)
    {
        $transactionModel = $this->findTransaction($data);

        if (!$transactionModel) {
            // 404 so BTCPay's automatic redelivery retries in case of a race
            $this->sendResponse(404, 'No matching FluentCart transaction found for this invoice');
        }

        // Check if already processed
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            $this->sendResponse(200, 'Payment already confirmed');
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();

        if (!$order) {
            $this->sendResponse(404, 'Order not found for the matched transaction');
        }

        $invoiceId = Arr::get($data, 'invoiceId');

        // The webhook only claims "settled" - it never says for how much. Ask BTCPay
        // itself what the invoice was worth before we treat the order as paid.
        $verification = $this->verifyInvoice($data, $transactionModel);

        if (is_wp_error($verification)) {
            fluent_cart_add_log(
                __('BTCPay Invoice Verification Failed', 'bitcoin-payments-for-fluentcart'),
                $verification->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );

            // 503 when BTCPay was simply unreachable, so the webhook is retried;
            // 409 when the invoice genuinely does not match this order.
            $isTransient = $verification->get_error_code() === 'btcpay_invoice_unavailable';

            $this->sendResponse(
                $isTransient ? 503 : 409,
                $isTransient
                    ? 'Could not verify the invoice with BTCPay - will retry'
                    : 'Invoice does not match this order - payment not confirmed'
            );
        }

        $transactionModel->fill([
            'status'              => Status::TRANSACTION_SUCCEEDED,
            'vendor_charge_id'    => $invoiceId,
            'payment_method_type' => 'crypto',
            'meta'                => array_merge($transactionModel->meta ?? [], [
                'btcpay_invoice_id' => $invoiceId,
                'manually_marked'   => Arr::get($data, 'manuallyMarked') ? 'yes' : 'no',
                'over_paid'         => Arr::get($data, 'overPaid') ? 'yes' : 'no',
            ])
        ]);
        $transactionModel->save();

        fluent_cart_add_log(
            __('BTCPay Payment Settled', 'bitcoin-payments-for-fluentcart'),
            __('Payment confirmation received from BTCPay Server. Invoice ID:', 'bitcoin-payments-for-fluentcart') . ' ' . $invoiceId,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );

        (new StatusHelper($order))->syncOrderStatuses($transactionModel);

        do_action('fluent_cart/payments/btcpay/webhook_invoice_settled', [
            'payload'     => $data,
            'order'       => $order,
            'transaction' => $transactionModel
        ]);

        $this->sendResponse(200, 'Payment confirmed successfully');
    }

    /**
     * InvoiceExpired / InvoiceInvalid: mark the transaction failed
     * unless the payment already went through.
     */
    public function handleInvoiceFailed($data)
    {
        $transactionModel = $this->findTransaction($data);

        if (!$transactionModel) {
            $this->sendResponse(200, 'No matching FluentCart transaction found for this invoice');
        }

        // Never downgrade a settled payment
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            $this->sendResponse(200, 'Payment already confirmed, ignoring failure event');
        }

        $invoiceId = Arr::get($data, 'invoiceId');
        $type = Arr::get($data, 'type');

        $failedStatus = defined('FluentCart\App\Helpers\Status::TRANSACTION_FAILED')
            ? Status::TRANSACTION_FAILED
            : 'failed';

        $transactionModel->fill([
            'status' => $failedStatus,
            'meta'   => array_merge($transactionModel->meta ?? [], [
                'btcpay_invoice_id'  => $invoiceId,
                'btcpay_fail_reason' => $type,
                'partially_paid'     => Arr::get($data, 'partiallyPaid') ? 'yes' : 'no',
            ])
        ]);
        $transactionModel->save();

        fluent_cart_add_log(
            __('BTCPay Invoice Failed', 'bitcoin-payments-for-fluentcart'),
            sprintf(
                /* translators: 1: BTCPay event type, 2: BTCPay invoice ID */
                __('BTCPay invoice %2$s reported %1$s - transaction marked as failed.', 'bitcoin-payments-for-fluentcart'),
                $type,
                $invoiceId
            ),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transactionModel->order_id,
            ]
        );

        do_action('fluent_cart/payments/btcpay/webhook_invoice_failed', [
            'payload'     => $data,
            'transaction' => $transactionModel
        ]);

        $this->sendResponse(200, 'Invoice failure processed');
    }

    /**
     * A signed webhook proves the event came from the configured BTCPay
     * instance - it does not prove the invoice belongs to this order or was
     * for the right amount. Anyone able to create invoices on the same store
     * (pay buttons, PoS apps, a second shop) produces genuine signed events,
     * and the invoice metadata we fall back on is set by whoever created the
     * invoice. So re-read the invoice from BTCPay and check it ourselves.
     *
     * @return true|\WP_Error
     */
    private function verifyInvoice($data, $transactionModel)
    {
        $settings = new BTCPaySettings();
        $configuredStore = $settings->getStoreId();
        $invoiceId = Arr::get($data, 'invoiceId');

        $payloadStore = (string)Arr::get($data, 'storeId', '');
        if ($configuredStore && $payloadStore && !hash_equals($configuredStore, $payloadStore)) {
            return new \WP_Error(
                'btcpay_wrong_store',
                'Webhook is for BTCPay store "' . $payloadStore . '", not the configured store.'
            );
        }

        $invoice = BTCPayAPI::getInvoice($invoiceId);

        if (is_wp_error($invoice) || !is_array($invoice)) {
            $reason = is_wp_error($invoice) ? $invoice->get_error_message() : 'empty response';

            return new \WP_Error(
                'btcpay_invoice_unavailable',
                'Could not read invoice from BTCPay to verify it: ' . $reason
            );
        }

        // The invoice we just fetched is authoritative about which store it lives on.
        $invoiceStore = (string)Arr::get($invoice, 'storeId', '');
        if ($configuredStore && $invoiceStore && !hash_equals($configuredStore, $invoiceStore)) {
            return new \WP_Error(
                'btcpay_wrong_store',
                'Invoice belongs to BTCPay store "' . $invoiceStore . '", not the configured store.'
            );
        }

        $status = (string)Arr::get($invoice, 'status', '');
        if (!in_array(strtolower($status), ['settled', 'complete', 'confirmed'], true)) {
            return new \WP_Error(
                'btcpay_invoice_not_settled',
                'BTCPay reports invoice status "' . $status . '", refusing to mark the order paid.'
            );
        }

        $expectedCurrency = strtoupper((string)$transactionModel->currency);
        $invoiceCurrency = strtoupper((string)Arr::get($invoice, 'currency', ''));

        if ($expectedCurrency !== $invoiceCurrency) {
            return new \WP_Error(
                'btcpay_currency_mismatch',
                'Invoice currency ' . $invoiceCurrency . ' does not match the order currency ' . $expectedCurrency . '.'
            );
        }

        $expectedAmount = BTCPayProcessor::formatAmount($transactionModel->total, $expectedCurrency);
        $invoiceAmount = (string)Arr::get($invoice, 'amount', '');

        if ($invoiceAmount === '' || abs((float)$invoiceAmount - (float)$expectedAmount) > 0.00001) {
            return new \WP_Error(
                'btcpay_amount_mismatch',
                'Invoice amount ' . $invoiceAmount . ' does not match the order total ' . $expectedAmount . '.'
            );
        }

        return true;
    }

    /**
     * BTCPay invoice IDs are short base58-style tokens. Anything else is either
     * a broken integration or someone probing - and the value ends up stored on
     * the transaction and rendered into an admin link.
     */
    public static function isValidInvoiceId($invoiceId): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$invoiceId);
    }

    /**
     * Find the FluentCart transaction for a webhook event: primarily via the
     * BTCPay invoice ID we stored at invoice creation, with the invoice
     * metadata (echoed back in the webhook payload) as fallback.
     */
    private function findTransaction($data)
    {
        $invoiceId = Arr::get($data, 'invoiceId');

        $transactionModel = OrderTransaction::query()
            ->where('vendor_charge_id', $invoiceId)
            ->where('payment_method', 'btcpay')
            ->first();

        if ($transactionModel) {
            return $transactionModel;
        }

        $transactionHash = Arr::get($data, 'metadata.fct_transaction_hash');

        if ($transactionHash) {
            return OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'btcpay')
                ->first();
        }

        return null;
    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
        ]);

        exit;
    }
}
