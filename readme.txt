=== BTCPay Server for FluentCart ===
Contributors: btc2bgroup
Tags: bitcoin, lightning, btcpay, fluentcart, payment gateway
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin (on-chain and Lightning) in FluentCart through your own self-hosted BTCPay Server.

== Description ==

This plugin adds BTCPay Server as a payment gateway inside FluentCart. At checkout the customer
is redirected to your BTCPay Server's hosted invoice page, pays with Bitcoin on-chain or over
Lightning, and is redirected back to your store. BTCPay notifies your store through a webhook
and the FluentCart order is marked paid.

* One-time payments only (BTCPay has no native recurring crypto billing, so subscriptions are out of scope)
* Redirect checkout - BTCPay's hosted page handles on-chain vs. Lightning and currency selection
* Webhook-based payment confirmation, verified with HMAC-SHA256 (`BTCPay-Sig`)
* No external dependencies - talks to the Greenfield REST API directly

== Installation ==

1. Install and activate FluentCart (1.2.5 or higher).
2. Upload/activate this plugin.
3. In BTCPay Server, create a Greenfield API key: Account -> Manage Account -> API Keys ->
   Generate Key, with at least the "Create an invoice" (`btcpay.store.cancreateinvoice`) and
   "View invoices" (`btcpay.store.canviewinvoices`) permissions for your store.
4. In WordPress go to FluentCart -> Settings -> Payment Methods -> BTCPay Server and fill in:
   * **BTCPay Server Host** - e.g. `https://btcpay.example.com`
   * **Store ID** - from BTCPay Store Settings -> General
   * **API Key** - the key created in step 3
5. Set up the webhook (see below), then enable the payment method.

== Webhook setup (required) ==

FluentCart is only told about completed payments through a BTCPay webhook. Without it, orders
will stay pending even after the customer pays.

1. In BTCPay Server open your Store -> Settings -> Webhooks and click **Create Webhook**.
2. Set the Payload URL to:

   `https://YOUR-SITE.com/?fluent-cart=fct_payment_listener_ipn&method=btcpay`

   (The exact URL for your site is shown on the plugin's settings screen.)
3. Reveal and copy the webhook **Secret** before saving.
4. Leave "Send me everything" enabled (the plugin ignores event types it doesn't need), save the webhook.
5. Paste the secret into the **Webhook Secret** field in the FluentCart BTCPay settings and save.

The plugin acts on `InvoiceSettled` (order marked paid) and `InvoiceExpired` / `InvoiceInvalid`
(transaction marked failed if it wasn't already paid). All other events are acknowledged and ignored.

== Frequently Asked Questions ==

= Does this support subscriptions? =

No. BTCPay Server does not support recurring crypto billing, so subscriptions are permanently
out of scope for this gateway.

= Does this support refunds from the FluentCart admin? =

Not yet. Note that BTCPay refunds work differently from card processors: they generate a claim
link the customer uses to pull the funds, rather than pushing money back automatically. You can
issue refunds manually from the BTCPay Server invoice screen.

= Is there a test mode? =

No built-in sandbox toggle - test against a separate shop/BTCPay store (e.g. one on testnet or
regtest) instead.

== Changelog ==

= 1.0.0 =
* Initial release: redirect checkout via BTCPay hosted invoices, webhook confirmation with
  BTCPay-Sig HMAC verification, one-time payments.
