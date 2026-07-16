<?php

require __DIR__ . '/../vendor/autoload.php';

define('ABSPATH', sys_get_temp_dir() . '/wordpress/');
define('BTCPAY_FCT_VERSION', '1.0.0');
define('BTCPAY_FCT_PLUGIN_FILE', dirname(__DIR__) . '/btcpay-for-fluent-cart.php');
define('BTCPAY_FCT_PLUGIN_DIR', dirname(__DIR__) . '/');
define('BTCPAY_FCT_PLUGIN_URL', 'https://shop.example.com/wp-content/plugins/btcpay-for-fluent-cart/');

require __DIR__ . '/stubs/wordpress.php';
require __DIR__ . '/stubs/fluentcart.php';

// Plugin classes under test (the plugin's autoloader lives in the bootstrap
// file, which we don't load here to avoid pulling in real WP hooks)
require BTCPAY_FCT_PLUGIN_DIR . 'includes/Settings/BTCPaySettings.php';
require BTCPAY_FCT_PLUGIN_DIR . 'includes/API/BTCPayAPI.php';
require BTCPAY_FCT_PLUGIN_DIR . 'includes/Onetime/BTCPayProcessor.php';
require BTCPAY_FCT_PLUGIN_DIR . 'includes/Webhook/BTCPayWebhook.php';
require BTCPAY_FCT_PLUGIN_DIR . 'includes/BTCPayGateway.php';

require __DIR__ . '/TestCase.php';
