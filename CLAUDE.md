# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project
WordPress plugin `btcpay-for-fluent-cart`: adds BTCPay Server (self-hosted Bitcoin/Lightning
payment processor) as a payment gateway inside FluentCart. Redirect-style checkout — customer
is sent to a BTCPay-hosted invoice page, pays, BTCPay fires a webhook, FluentCart order is
marked paid.

Full task-by-task build spec: see `btcpay-fluentcart-build-instructions.md` in this repo (or
ask for it if it's not present) — that's the one-time build plan. This file is the standing
context for every session.

## Reference material — read before writing gateway/API code
- Paystack for FluentCart (closest real template, mirror its structure):
  https://github.com/WPManageNinja/paystack-for-fluent-cart
  https://github.com/WPManageNinja/paystack-for-fluent-cart/blob/master/CUSTOM_PAYMENT_GATEWAY_INTEGRATION_GUIDE.md
- FluentCart payment-gateway dev docs: https://dev.fluentcart.com/payment-methods-integration/
- BTCPay Greenfield API (use the PHP example, matches our stack):
  https://docs.btcpayserver.org/Development/GreenfieldExample-PHP/
  https://docs.btcpayserver.org/API/Greenfield/v1/

Don't guess at FluentCart interface method signatures or BTCPay request/response shapes —
fetch and check the actual docs/example repo before implementing.

## Architecture
```
btcpay-for-fluent-cart/
├── btcpay-for-fluent-cart.php     # bootstrap, registers gateway on fluent_cart/register_payment_methods
├── includes/
│   ├── BTCPayGateway.php          # extends AbstractPaymentGateway, implements PaymentGatewayInterface
│   ├── API/BTCPayAPI.php          # wp_remote_post/get wrapper around Greenfield REST calls (no SDK/Composer dep)
│   ├── Onetime/BTCPayProcessor.php # creates invoice, returns checkoutLink
│   ├── Webhook/BTCPayWebhook.php  # verifies BTCPAY-SIG, updates order status
│   └── Settings/BTCPaySettings.php # extends BaseGatewaySettings
```

## Key decisions (don't relitigate these without asking)
- **No test/live mode split.** Single config: `host`, `store_id`, `api_key`, `webhook_secret`.
  Testing happens in a separate shop, not via a built-in sandbox toggle.
- **One-time payments only.** No subscriptions/recurring — BTCPay doesn't natively support
  recurring crypto billing, so this is permanently out of scope, not deferred.
- **Redirect checkout only.** Send the customer to BTCPay's hosted checkout page. Don't build
  a custom on-site crypto widget — BTCPay's page already handles on-chain vs. Lightning and
  currency/token selection.
- **Refunds are optional/stretch**, and even if built, note BTCPay refunds return a claim link
  for the customer rather than an instant push-refund like Stripe.
- Webhook IPN URL pattern (confirmed from the Paystack plugin):
  `?fluent-cart=fct_payment_listener_ipn&method=btcpay`
- Webhook signature: HMAC-SHA256 of raw body against stored `webhook_secret`, compared to the
  `BTCPAY-SIG` header via `hash_equals()`. Never process the payload before this check passes.

## Conventions
- PHP, WordPress plugin standards (no framework/build step).
- No Composer dependencies — call the Greenfield REST API directly with `wp_remote_post`/`wp_remote_get`.
- Encrypt stored API keys the same way the Paystack example does (`Helper::decryptKey` pattern).
- Fail gracefully: if `host`/`store_id`/`api_key`/`webhook_secret` are missing or invalid, show
  a clear admin notice — no fatals. The plugin will sit unconfigured until someone sets it up
  in the target shop.

## Commands
- `composer install` — installs PHPUnit (dev-only; the plugin itself still has zero runtime
  Composer dependencies).
- `composer test` (or `./vendor/bin/phpunit`) — runs the unit tests in `tests/`. The suite runs
  without WordPress/FluentCart: `tests/stubs/` contains minimal stubs for the WP functions and
  FluentCart classes the plugin touches, and HTTP calls are captured via a test handler global.
- Lint: `php -l <file>` (no config needed).
- CI: `.github/workflows/tests.yml` runs on PRs/pushes to `main` — lints plugin files on
  PHP 7.4 + 8.4 and runs PHPUnit on PHP 8.3 + 8.4.
- Release: push a `v*` tag (e.g. `git tag v1.0.1 && git push origin v1.0.1`) —
  `.github/workflows/release.yml` verifies the tag matches the plugin header version,
  `BTCPAY_FCT_VERSION`, and readme.txt `Stable tag`, runs the tests, builds an installable
  plugin zip (only `btcpay-for-fluent-cart.php`, `readme.txt`, `includes/`, `assets/` under a
  top-level `btcpay-for-fluent-cart/` folder), and attaches it to a GitHub Release. Bump all
  three version strings before tagging.