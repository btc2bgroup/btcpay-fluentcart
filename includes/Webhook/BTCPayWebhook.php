<?php

namespace BTCPayForFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\Framework\Support\Arr;
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
            __('BTCPay Payment Settled', 'btcpay-for-fluent-cart'),
            __('Payment confirmation received from BTCPay Server. Invoice ID:', 'btcpay-for-fluent-cart') . ' ' . $invoiceId,
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
            __('BTCPay Invoice Failed', 'btcpay-for-fluent-cart'),
            sprintf(
                /* translators: 1: BTCPay event type, 2: BTCPay invoice ID */
                __('BTCPay invoice %2$s reported %1$s - transaction marked as failed.', 'btcpay-for-fluent-cart'),
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
