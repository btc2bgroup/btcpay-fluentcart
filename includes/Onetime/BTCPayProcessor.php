<?php

namespace BTCPayForFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\Framework\Support\Arr;
use BTCPayForFluentCart\API\BTCPayAPI;
use BTCPayForFluentCart\Settings\BTCPaySettings;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

class BTCPayProcessor
{
    /**
     * Currencies without a minor unit - FluentCart stores these 1:1,
     * everything else is stored in the lowest unit (cents).
     */
    const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'
    ];

    /**
     * Create a BTCPay invoice for the order and hand the hosted
     * checkout link back to FluentCart as a redirect.
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $settings = new BTCPaySettings();

        if (!$settings->isConfigured()) {
            return new \WP_Error(
                'btcpay_not_configured',
                __('BTCPay Server is not configured. Please set the host, Store ID and API key in the payment settings.', 'btcpay-for-fluent-cart')
            );
        }

        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $order->customer;

        $currency = strtoupper($transaction->currency);

        $invoiceData = [
            'amount'   => $this->formatAmount($transaction->total, $currency),
            'currency' => $currency,
            'metadata' => array_filter([
                'orderId'              => (string)$order->id,
                'buyerEmail'           => $fcCustomer ? $fcCustomer->email : '',
                'fct_order_hash'       => $order->uuid,
                'fct_transaction_hash' => $transaction->uuid,
            ]),
            'checkout' => [
                'redirectURL'           => Arr::get($paymentArgs, 'success_url'),
                'redirectAutomatically' => true,
            ],
        ];

        // Apply filters for customization
        $invoiceData = apply_filters('fluent_cart/btcpay/onetime_payment_args', $invoiceData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        $invoice = BTCPayAPI::createInvoice($invoiceData);

        if (is_wp_error($invoice)) {
            fluent_cart_add_log(
                __('BTCPay Invoice Creation Failed', 'btcpay-for-fluent-cart'),
                $invoice->get_error_message(),
                'error',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id
                ]
            );

            return $invoice;
        }

        $invoiceId = Arr::get($invoice, 'id');
        $checkoutLink = Arr::get($invoice, 'checkoutLink');

        if (!$invoiceId || !$checkoutLink) {
            return new \WP_Error(
                'btcpay_invalid_response',
                __('BTCPay Server returned an unexpected response while creating the invoice.', 'btcpay-for-fluent-cart'),
                ['response' => $invoice]
            );
        }

        // Store the invoice ID against the transaction - the webhook uses this to find the order back
        $transaction->fill([
            'vendor_charge_id' => $invoiceId
        ]);
        $transaction->save();

        fluent_cart_add_log(
            __('BTCPay Invoice Created', 'btcpay-for-fluent-cart'),
            __('BTCPay invoice created. Invoice ID:', 'btcpay-for-fluent-cart') . ' ' . $invoiceId,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id
            ]
        );

        return [
            'status'      => 'success',
            'redirect_to' => $checkoutLink,
            'message'     => __('Redirecting to BTCPay Server checkout...', 'btcpay-for-fluent-cart'),
        ];
    }

    /**
     * Convert FluentCart's lowest-unit amount to the decimal string
     * BTCPay Server expects (e.g. 1050 -> "10.50" for USD).
     */
    private function formatAmount($total, $currency)
    {
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (string)(int)$total;
        }

        return number_format($total / 100, 2, '.', '');
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=btcpay');
    }
}
