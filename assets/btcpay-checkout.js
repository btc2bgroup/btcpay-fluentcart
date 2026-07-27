/**
 * FluentCart renders the "Place order" button disabled and only re-enables it
 * when the selected gateway answers the `fluent_cart_load_payments_<slug>`
 * event. Nothing else on the page does that for us, so a checkout where Bitcoin
 * is the only (or the pre-selected) method would stay stuck with a dead button.
 *
 * Redirect gateways need nothing more than this: the processor returns
 * `redirect_to` and FluentCart core performs the redirect itself.
 * This mirrors FluentCart's own cod-checkout.js.
 */
window.addEventListener('fluent_cart_load_payments_btcpay', function (e) {
    var paymentLoader = e.detail && e.detail.paymentLoader;

    if (!paymentLoader) {
        return;
    }

    var submitButton = (window.fluentcart_checkout_vars || {}).submit_button || {};

    paymentLoader.enableCheckoutButton(submitButton.text);
});
