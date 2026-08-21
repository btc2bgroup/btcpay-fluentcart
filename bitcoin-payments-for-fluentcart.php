<?php
/**
 * Plugin Name: Bitcoin and Lightning Payments for FluentCart
 * Plugin URI: https://github.com/btc2bgroup/btcpay-fluentcart
 * Description: Accept Bitcoin and Lightning payments in FluentCart via your self-hosted BTCPay Server - redirect checkout with webhook confirmation.
 * Version: 0.0.12
 * Author: BTC2B Group
 * Author URI: https://btc2bgroup.com
 * Text Domain: bitcoin-payments-for-fluentcart
 * Requires at least: 5.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 BTC2B Group
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
defined('ABSPATH') || exit('Direct access not allowed.');

// Define plugin constants
define('BTCPAY_FCT_VERSION', '0.0.12');
define('BTCPAY_FCT_PLUGIN_FILE', __FILE__);
define('BTCPAY_FCT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BTCPAY_FCT_PLUGIN_URL', plugin_dir_url(__FILE__));


function btcpay_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Bitcoin and Lightning Payments for FluentCart', 'bitcoin-payments-for-fluentcart'); ?></strong>
                    <?php esc_html_e('requires FluentCart to be installed and activated.', 'bitcoin-payments-for-fluentcart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }

    if (version_compare(FLUENTCART_VERSION, '1.2.5', '<')) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('Bitcoin and Lightning Payments for FluentCart', 'bitcoin-payments-for-fluentcart'); ?></strong>
                    <?php esc_html_e('requires FluentCart version 1.2.5 or higher', 'bitcoin-payments-for-fluentcart'); ?>
                </p>
            </div>
            <?php
        });
        return false;
    }

    return true;
}


add_action('plugins_loaded', function() {
    if (!btcpay_fc_check_dependencies()) {
        return;
    }

    spl_autoload_register(function ($class) {
        $prefix = 'BTCPayForFluentCart\\';
        $base_dir = BTCPAY_FCT_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    add_action('fluent_cart/register_payment_methods', function($data) {
        \BTCPayForFluentCart\BTCPayGateway::register();
    }, 10);

}, 20);


register_activation_hook(__FILE__, 'btcpay_fc_on_activation');

/**
 * Plugin activation callback
 */
function btcpay_fc_on_activation() {
    if (!btcpay_fc_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Bitcoin and Lightning Payments for FluentCart requires FluentCart to be installed and activated.', 'bitcoin-payments-for-fluentcart'),
            esc_html__('Plugin Activation Error', 'bitcoin-payments-for-fluentcart'),
            ['back_link' => true]
        );
    }
}
