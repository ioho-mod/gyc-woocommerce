=== GetYourCash PayByLink ===
Tags: woocommerce, payment gateway, paybylink, pre-authorization, italy
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept WooCommerce payments with GetYourCash PayByLink: pre-authorization, secure redirect and automatic order status sync.

== Description ==

GetYourCash PayByLink adds GetYourCash as a payment method at checkout. When a customer places an order, the plugin creates a PayByLink pre-authorization and redirects the customer to the GetYourCash payment page. When the customer returns, and every 5 minutes in the background, the plugin checks the payment status with GetYourCash and updates the order.

Features:

* Pre-authorization payments (`PaymentType = 1`), captured from WooCommerce: automatically when the order is completed (or moved to Processing), or with the "Capture GetYourCash payment" order action.
* GetYourCash logo at checkout.
* Test mode with separate sandbox credentials.
* Connection test on save, which lists your Merchant Local Branch IDs.
* Works with both the classic checkout and the Cart & Checkout blocks.
* Compatible with High-Performance Order Storage (HPOS).
* Automatic status synchronization through Action Scheduler (no server cron required).
* Choose whether pre-authorized orders go to "On hold" or "Processing".
* Optional debug logging to WooCommerce > Status > Logs, with credentials and customer data masked.
* WP-CLI command: `wp gyc sync`.

A GetYourCash merchant account with PayByLink API credentials is required. GetYourCash supports payments in EUR only; the payment method is hidden at checkout for other store currencies.

== External services ==

This plugin connects to the GetYourCash API to create payment links and read their status. It cannot work without it.

* **When:** when a customer places an order with GetYourCash; when the customer returns from the payment page; every 5 minutes, for orders still awaiting payment; when a payment is captured; and when you save the plugin settings (connection test).
* **Data sent:** your API username and password (to obtain an access token); the order amount, currency, number, date, description and a transaction reference; the return URLs of your store; and the customer's billing details (first and last name, company, address, city, postcode, country, phone number, email address) and customer ID.
* **Where:** https://coresecure.getyourcash.eu (live) or https://coretestsecure.getyourcash.eu (test mode).

GetYourCash is provided by ULTRONEO S.r.l.: [website](https://getyourcash.eu/), [Privacy Policy](https://getyourcash.eu/privacy-policy/).

== Installation ==

1. Install and activate WooCommerce.
2. Install the plugin from Plugins > Add New, or upload the ZIP file, and activate it.
3. Go to WooCommerce > Settings > Payments > GetYourCash PayByLink.
4. Enter the API username and password provided by GetYourCash (live, sandbox, or both) and save. The confirmation message lists your Merchant Local Branch IDs.
5. Enter the Merchant Local Branch ID, enable the payment method and save.
6. To try it without real payments, enable "Test mode".

== Frequently Asked Questions ==

= How is the order amount sent? =

In euros, with decimals: an order of EUR 18.50 is sent as 18.5. The captured amount uses the same format.

= Is the payment captured immediately? =

You choose, under "Payment action":

* **Authorize only** (default): the funds are reserved on the customer's card and the order is set to "On hold". The payment is captured when you move the order to Processing or Completed (you can turn this off under "Capture on status change"), or with the "Capture GetYourCash payment" order action. Useful when you confirm stock or the final amount before charging.
* **Authorize and capture immediately**: the customer is charged as soon as the payment is authorized, and the order goes to Processing. If the immediate capture fails, the order is put "On hold" with the funds still reserved, so you can capture it manually.

With "Authorize only", the current order total is captured; if you reduced the order, the remaining reserved amount is released. If a capture fails, the order goes back to "On hold" and a note explains why.

= Can I refund or cancel a payment from WooCommerce? =

Not yet: the GetYourCash API does not offer refund or void operations. Issue refunds from your GetYourCash dashboard, then record them in WooCommerce with a manual refund. If you cancel an order that was never captured, the customer is not charged, and the reserved funds are released according to the card issuer's rules.

= Can I capture more than the pre-authorized amount? =

No. If the order total increased, take a new payment for the difference.

= Which currencies are supported? =

EUR only, as required by the GetYourCash API.

= How often are order statuses updated? =

When the customer returns to your store, every 5 minutes for pending orders until the payment link expires, and once an hour for pre-authorized orders waiting On hold (so a pre-authorization cancelled on the GetYourCash side, or a capture made from the GetYourCash dashboard, is picked up). You can also run `wp gyc sync` from WP-CLI. Scheduled runs are listed under Tools > Scheduled Actions (group "getyourcash").

= Where can I find the logs? =

Enable "Debug log" in the settings, then go to WooCommerce > Status > Logs and select the "getyourcash" source. API errors are always logged.

== Changelog ==

= 1.0.0 - 2026-10-01 =
* Initial release.
