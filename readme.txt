=== Bitcoin and Lightning Payments for FluentCart ===
Contributors: btc2bgroup
Tags: bitcoin, lightning, btcpay, payment gateway, fluentcart
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin and Lightning payments in FluentCart through your own self-hosted BTCPay Server.

== Description ==

This plugin adds BTCPay Server as a payment gateway inside FluentCart. At checkout the customer
is redirected to your BTCPay Server's hosted invoice page, pays with Bitcoin on-chain or over
Lightning, and is redirected back to your store. BTCPay notifies your store through a webhook
and the FluentCart order is marked paid.

You run the payment infrastructure yourself. There is no account to open with us, no middleman
holding your funds, and no percentage fee - the plugin talks only to the BTCPay Server instance
whose address you enter in the settings.

**Features**

* One-time payments only (BTCPay has no native recurring crypto billing, so subscriptions are out of scope)
* Redirect checkout - BTCPay's hosted page handles on-chain vs. Lightning and currency selection
* Webhook-based payment confirmation, verified with HMAC-SHA256 (`BTCPay-Sig`)
* No external dependencies - talks to the BTCPay Greenfield REST API directly

**Requirements**

* FluentCart 1.2.5 or higher
* A BTCPay Server instance you control (self-hosted or on a third-party host), reachable over HTTPS
* PHP 7.4 or higher

== External services ==

This plugin connects to one external service: **the BTCPay Server instance that you configure
yourself** in the plugin settings. No connection is made until you enter a host, Store ID and API
key. The plugin does not contact BTCPay Server's own servers, our servers, or any other
third-party service.

**What is sent, and when**

* When a customer places an order with the Bitcoin payment method, the plugin sends the order
  amount, the currency code, the order ID and reference, and the return/redirect URLs of your
  store to your BTCPay Server, in order to create an invoice. Your API key is sent as an
  authorization header on this request.
* When your store loads the payment settings screen or verifies an invoice, the plugin requests
  invoice and store details from your BTCPay Server.
* Your BTCPay Server sends webhook notifications back to your store when an invoice is settled,
  expires, or becomes invalid.

No customer personal data (name, email, address) is sent to BTCPay Server by this plugin, and no
data is sent anywhere else.

Because the BTCPay Server instance is one you choose and operate, the applicable terms and
privacy policy are those of that instance and its host. BTCPay Server is free and open-source
software; its project terms and documentation are available at:

* Website: https://btcpayserver.org/
* Documentation: https://docs.btcpayserver.org/
* Privacy policy: https://btcpayserver.org/privacy/
* Source: https://github.com/btcpayserver/btcpayserver

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

= Webhook setup (required) =

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

= Do I need an account with you to use this? =

No. The plugin connects only to the BTCPay Server instance you configure. There is no
registration, no API of ours involved, and no fee taken from your payments.

= Do I need to run my own BTCPay Server? =

You need a BTCPay Server instance you control. That can be one you self-host or one on a
third-party BTCPay host. The plugin does not provide one.

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

= Why must the BTCPay host be https? =

Your Greenfield API key is sent as an authorization header on every request. Over plain http it
would travel in the clear, so the settings screen rejects non-https hosts.

= My orders stay pending after the customer pays. What's wrong? =

Almost always a webhook problem. Check that the webhook exists in BTCPay, that its Payload URL
matches the one shown on the plugin settings screen, and that the Webhook Secret saved in
WordPress matches the one BTCPay generated. BTCPay's webhook screen shows recent delivery
attempts and their responses.

== Screenshots ==

1. The BTCPay Server payment method settings in FluentCart.
2. Bitcoin offered as a payment method at checkout.
3. The BTCPay-hosted invoice page where the customer pays on-chain or over Lightning.

== Changelog ==

= 0.0.7 =
* Security fixes.
* Renamed to "Bitcoin and Lightning Payments for FluentCart" for the WordPress.org directory.

= 0.0.6 =
* Enable the Place order button when Bitcoin is the only available payment method.

= 0.0.5 =
* Use "Bitcoin" for all customer-facing gateway labels.

= 0.0.1 =
* Initial release: redirect checkout via BTCPay hosted invoices, webhook confirmation with
  BTCPay-Sig HMAC verification, one-time payments.

== Upgrade Notice ==

= 0.0.7 =
Security fixes - update recommended.
