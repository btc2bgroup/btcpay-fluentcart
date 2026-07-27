<?php
/**
 * Minimal WordPress function/class stubs so the plugin classes can be
 * unit-tested without a WordPress install.
 *
 * HTTP requests are routed through $GLOBALS['btcpay_test_http_handler'],
 * a callable(string $url, array $args): array|WP_Error set per test.
 */

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function __($text, $domain = null)
{
    return $text;
}

function esc_html__($text, $domain = null)
{
    return $text;
}

function esc_html($text)
{
    return $text;
}

function esc_url($url)
{
    return $url;
}

function sanitize_text_field($str)
{
    return is_string($str) ? trim($str) : $str;
}

function wp_unslash($value)
{
    return $value;
}

function untrailingslashit($string)
{
    return rtrim($string, '/\\');
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

function site_url($path = '')
{
    return 'https://shop.example.com/' . ltrim($path, '/');
}

function get_bloginfo($show = '')
{
    return '6.9';
}

function wp_json_encode($data, $options = 0, $depth = 512)
{
    return json_encode($data, $options, $depth);
}

function wp_parse_args($args, $defaults = [])
{
    return array_merge($defaults, (array)$args);
}

function apply_filters($hook_name, $value, ...$args)
{
    return $value;
}

function do_action($hook_name, ...$args)
{
    $GLOBALS['btcpay_test_actions'][] = ['hook' => $hook_name, 'args' => $args];
}

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
{
    return true;
}

function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1)
{
    return true;
}

function fluent_cart_add_log($title, $message, $type = 'info', $args = [])
{
    $GLOBALS['btcpay_test_logs'][] = [
        'title'   => $title,
        'message' => $message,
        'type'    => $type,
        'args'    => $args,
    ];
}

function wp_remote_request($url, $args = [])
{
    $handler = $GLOBALS['btcpay_test_http_handler'] ?? null;

    if (!is_callable($handler)) {
        return new WP_Error('no_test_handler', 'No HTTP handler configured in this test');
    }

    $GLOBALS['btcpay_test_http_requests'][] = ['url' => $url, 'args' => $args];

    return $handler($url, $args);
}

function wp_remote_retrieve_body($response)
{
    if (is_wp_error($response) || !is_array($response)) {
        return '';
    }
    return $response['body'] ?? '';
}

function wp_remote_retrieve_response_code($response)
{
    if (is_wp_error($response) || !is_array($response)) {
        return '';
    }
    return $response['response']['code'] ?? 200;
}
