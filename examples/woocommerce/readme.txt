=== Qut Pay for WooCommerce ===
Contributors: kazprose
Tags: kaspi, kaspi pay, kazakhstan, payment gateway, qr
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Kaspi QR payments in WooCommerce through the Qut Pay service. Money goes straight to the merchant's own Kaspi Pay account.

== Description ==

Kaspi.kz is the most used payment app in Kazakhstan. This plugin adds a "Pay with Kaspi" payment method to WooCommerce through the Qut Pay service (https://qut.kz): for every order an invoice is created, the customer pays by Kaspi QR or Kaspi link in the Kaspi.kz app, and the order is marked paid automatically.

* Customer chooses "Pay with Kaspi", lands on a payment page with a Kaspi QR and link, and pays in the Kaspi.kz app.
* The order becomes paid automatically through a signed webhook; the customer receives a receipt.
* Refunds, full or partial, from the WooCommerce order screen.
* Classic checkout and Checkout Blocks; HPOS (custom order tables) compatible.
* Sandbox mode: test the whole flow without real money.
* Store currency must be KZT.

**Important.** Qut Pay is an independent service and is not affiliated with Kaspi Bank. It works through the standard "Cashier" role of your own Kaspi Pay business account (sole proprietor or LLP). A Qut Pay account and a paid subscription (free trial first) are required: pricing at https://qut.kz/tarify/. Qut Pay never holds your money — payments settle directly in your Kaspi Pay account.

Russian: Приём оплаты через Kaspi (QR / ссылка Kaspi) в WooCommerce с помощью сервиса Qut Pay. Деньги поступают напрямую на ваш счёт Kaspi Pay. Сервис независимый, не партнёр Kaspi Bank; нужен бизнес-аккаунт Kaspi Pay (ИП/ТОО) и аккаунт Qut Pay.

== External services ==

This plugin connects to the Qut Pay API (https://api.qut.kz), a third-party service operated by Qut Pay (Kazakhstan). It is required for the plugin to work: it issues Kaspi invoices and reports their status.

What is sent and when:

* When a customer chooses "Pay with Kaspi" at checkout, the plugin sends the order amount, currency, order number and description, the customer's return URLs, and (if provided) the customer's name, phone and e-mail to the Qut Pay API, authenticated with the merchant's API key.
* On the thank-you page and when the merchant checks an order, the plugin requests the invoice status by invoice id.
* For refunds initiated by the merchant, the refund amount and reason are sent.
* Qut Pay calls back the site's REST endpoint `/wp-json/qutpay/v1/webhook` with signed payment events; the plugin verifies the HMAC-SHA256 signature before acting.

No data is sent until the merchant has entered their own Qut Pay API key. Terms of service: https://api.qut.kz/docs/guide/offer · Privacy policy: https://api.qut.kz/docs/guide/privacy · Security and reliability: https://qut.kz/en/trust/

== Installation ==

1. Register at https://qut.kz/app and connect your Kaspi Pay cashier (SMS code). Guide: https://api.qut.kz/docs/guide/en/start
2. In the Qut Pay cabinet → API keys → create a key and copy it.
3. Cabinet → Webhook → add the address `https://YOUR-SITE/wp-json/qutpay/v1/webhook` and copy the secret shown.
4. WordPress → Plugins → Add New → upload the zip (or install from the directory) → activate. WooCommerce must be installed.
5. WooCommerce → Settings → Payments → Qut Pay (Kaspi QR): paste the API key and the webhook secret, enable the method.
6. Place a test order. With a sandbox key (`qp_test_…`) the payment can be simulated with a button on the payment page.

== Frequently Asked Questions ==

= Do I need a contract with Kaspi? =
You need a Kaspi Pay business account (sole proprietor or LLP) and a separate cashier number in it. No bank acquiring contract or terminal is required for Kaspi QR; Kaspi charges its own QR fee as it would without any plugin.

= Does the plugin see my Kaspi password? =
No. The cashier signs in with an SMS code in the Qut Pay cabinet; the plugin only uses a Qut Pay API key.

= How does the order become paid? =
Qut Pay sends a signed webhook to your site when the customer pays. The plugin checks the signature, matches the order and sets the status you chose (processing or completed). The thank-you page also re-checks the status.

= Which currency? =
KZT only.

= Is Qut Pay free? =
There is a free trial; after that a flat monthly subscription from 4 990 ₸ (no percentage of payments). Details: https://qut.kz/tarify/

== Screenshots ==

1. Payment method in WooCommerce settings.
2. "Pay with Kaspi" at checkout.
3. Qut Pay payment page with Kaspi QR and link.
4. Order marked paid after the webhook.

== Changelog ==

= 1.0.0 =
* First public release: invoice creation, redirect to the payment page, signed webhook, status re-check on the thank-you page, refunds, Checkout Blocks, HPOS.

== Upgrade Notice ==

= 1.0.0 =
First public release.
