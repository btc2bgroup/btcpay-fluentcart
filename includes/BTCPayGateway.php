<?php
/**
 * BTCPay Gateway Class
 *
 * @package BTCPayForFluentCart
 * @since 1.0.0
 */

namespace BTCPayForFluentCart;

if (!defined('ABSPATH')) {
    exit; // Direct access not allowed.
}

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use BTCPayForFluentCart\Settings\BTCPaySettings;

class BTCPayGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'btcpay';

    public array $supportedFeatures = [
        'payment',
        'webhook'
    ];

    public function __construct()
    {
        parent::__construct(
            new BTCPaySettings()
        );
    }

    public function meta(): array
    {
        $logo = BTCPAY_FCT_PLUGIN_URL . 'assets/images/btcpay-logo.svg';

        return [
            'title'              => __('BTCPay Server', 'btcpay-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'BTCPay Server',
            'admin_title'        => 'BTCPay Server',
            'description'        => __('Pay with Bitcoin - on-chain or Lightning - via your self-hosted BTCPay Server', 'btcpay-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#F7931A',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        add_filter('fluent_cart/payment_methods/btcpay_settings', [$this, 'getSettings'], 10, 2);

        add_action('fluent_cart/checkout_embed_payment_method_content', [$this, 'renderPaymentContent'], 10, 1);
    }

    /**
     * FluentCart passes a single context array: ['method' => $gateway, 'cart' => $cart, 'route' => $route]
     *
     * @param array $context
     */
    public function renderPaymentContent($context = [])
    {
        if (Arr::get((array) $context, 'route') !== $this->methodSlug) {
            return;
        }

        echo '<div class="fluent-cart-btcpay-redirect"><p>'
            . esc_html__('You will be redirected to BTCPay Server to complete your payment with Bitcoin (on-chain or Lightning).', 'btcpay-for-fluent-cart')
            . '</p></div>';
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        return (new Onetime\BTCPayProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'btcpay-for-fluent-cart'),
            'payment_args' => [],
        ], 200);
    }

    public function handleIPN(): void
    {
        (new Webhook\BTCPayWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $host = (new BTCPaySettings())->getHost();

        if (!$host) {
            return (string)$url;
        }

        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction || !$transaction->vendor_charge_id) {
            return $host;
        }

        return $host . '/invoices/' . $transaction->vendor_charge_id;
    }

    public function getWebhookInstructions(): array
    {
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=btcpay');

        return [
            'title'       => __('Webhook URL', 'btcpay-for-fluent-cart'),
            'webhook_url' => esc_url($webhook_url),
            'description' => __('You must create a webhook in your BTCPay Server store so FluentCart gets notified when an invoice is paid.', 'btcpay-for-fluent-cart'),
            'steps'       => [
                'title' => __('How to configure?', 'btcpay-for-fluent-cart'),
                'list'  => [
                    __('In your BTCPay Server, go to your Store &rarr; Settings &rarr; Webhooks and click "Create Webhook"', 'btcpay-for-fluent-cart'),
                    __('Paste the Webhook URL above as the Payload URL', 'btcpay-for-fluent-cart'),
                    __('Under "Secret", copy the generated secret (click the eye icon to reveal it)', 'btcpay-for-fluent-cart'),
                    __('Save the webhook in BTCPay, then paste that secret into the "Webhook Secret" field here and save', 'btcpay-for-fluent-cart'),
                ],
            ],
        ];
    }

    public function fields(): array
    {
        return [
            'notice' => [
                'value' => '<p>' . __('Connect your self-hosted BTCPay Server instance. Create an API key in BTCPay under Account &rarr; Manage Account &rarr; API Keys with the "Create an invoice" and "View invoices" permissions for your store.', 'btcpay-for-fluent-cart') . '</p>',
                'label' => __('BTCPay Server notice', 'btcpay-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'host' => [
                'value'       => '',
                'label'       => __('BTCPay Server Host', 'btcpay-for-fluent-cart'),
                'type'        => 'text',
                'placeholder' => 'https://btcpay.example.com',
                'description' => __('The full URL of your BTCPay Server instance, without a trailing slash.', 'btcpay-for-fluent-cart'),
            ],
            'store_id' => [
                'value'       => '',
                'label'       => __('Store ID', 'btcpay-for-fluent-cart'),
                'type'        => 'text',
                'placeholder' => __('Found in BTCPay under Store Settings &rarr; General', 'btcpay-for-fluent-cart'),
            ],
            'api_key' => [
                'value'       => '',
                'label'       => __('API Key', 'btcpay-for-fluent-cart'),
                'type'        => 'password',
                'placeholder' => __('Greenfield API key', 'btcpay-for-fluent-cart'),
            ],
            'webhook_secret' => [
                'value'       => '',
                'label'       => __('Webhook Secret', 'btcpay-for-fluent-cart'),
                'type'        => 'password',
                'placeholder' => __('Secret from your BTCPay webhook', 'btcpay-for-fluent-cart'),
            ],
            'webhook_info' => [
                'value' => $this->getWebhookInstructions(),
                'label' => __('Webhook Configuration', 'btcpay-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        if (!empty($data['api_key'])) {
            $data['api_key'] = Helper::encryptKey($data['api_key']);
        }

        if (!empty($data['webhook_secret'])) {
            $data['webhook_secret'] = Helper::encryptKey($data['webhook_secret']);
        }

        if (!empty($data['host'])) {
            $data['host'] = untrailingslashit(trim($data['host']));
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('btcpay', new self());
    }
}
