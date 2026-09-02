# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project
WordPress plugin `bitcoin-payments-for-fluentcart`: adds BTCPay Server (self-hosted Bitcoin/Lightning
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
bitcoin-payments-for-fluentcart/
├── bitcoin-payments-for-fluentcart.php     # bootstrap, registers gateway on fluent_cart/register_payment_methods
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
  plugin zip (only `bitcoin-payments-for-fluentcart.php`, `readme.txt`, `LICENSE`, `includes/`, `assets/` under a
  top-level `bitcoin-payments-for-fluentcart/` folder), and attaches it to a GitHub Release. Bump all
  version strings before tagging.
- Version bump: `bin/bump-version.sh 1.0.1` (or `composer bump -- 1.0.1`) rewrites the plugin
  header, `BTCPAY_FCT_VERSION`, readme.txt `Stable tag` and the SECURITY.md supported-versions
  table at once, then re-checks them with the same patterns the release workflow greps for, so a
  bad substitution fails locally instead of after the tag is pushed. Add `--tag` to also commit
  the bump and create the `v<version>` tag (it refuses on a dirty tree or an existing tag, and
  never pushes).
- WordPress.org SVN: `.github/workflows/deploy-wporg.yml` runs when a GitHub Release is published
  (or manually via workflow_dispatch with a tag), re-checks the version strings, and uses
  `10up/action-wordpress-plugin-deploy` to push trunk + the `<version>` SVN tag and sync
  `.wordpress-org/` (banners/icon/screenshots) to the SVN `assets/` dir. Needs repo secrets
  `SVN_USERNAME` / `SVN_PASSWORD` (WordPress.org account, not a token). `.distignore` controls
  what gets copied into SVN — keep it in sync with the zip file list in release.yml.
- WordPress.org assets/readme only: `.github/workflows/deploy-wporg-assets.yml` runs on pushes to
  `main` that touch `readme.txt` or `.wordpress-org/**` (or manually), and uses
  `10up/action-wordpress-plugin-asset-update` to sync just those into SVN trunk — no version bump,
  no tag. Use it for banner/icon/screenshot changes and readme-only edits (description, FAQ,
  `Tested up to`). It rewrites trunk's readme.txt, so never let `Stable tag` there point at a
  version that has no SVN tag yet: ship the code with deploy-wporg.yml first, then readme tweaks.
  Same `SVN_USERNAME` / `SVN_PASSWORD` secrets.
- Changelog: readme.txt is the WordPress.org-facing readme — the directory parses it and ignores
  README.md, so there is no README.md. Add a `== Changelog ==` entry (and `== Upgrade Notice ==`
  when relevant) by hand when releasing; the bump script does not write those.

## WordPress.org naming (don't rename without checking guideline 17)
The plugin slug/name deliberately does **not** start with "BTCPay" — the directory forbids a
third-party project name as the initial term of a slug. Public name is "Bitcoin and Lightning
Payments for FluentCart", slug/text domain `bitcoin-payments-for-fluentcart`. BTCPay Server is
referenced freely in descriptions and body text, just never as the leading term. The text domain
must always equal the slug. Internal PHP prefixes (`BTCPAY_FCT_`, `btcpay_fc_`) are unaffected.