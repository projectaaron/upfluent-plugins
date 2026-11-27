=== Order Sync for ShipFusion and FluentCart ===
Contributors: projectaaron
Tags: fluentcart, shipfusion, fulfillment, 3pl, shipping
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send paid FluentCart orders to your ShipFusion warehouse and get tracking and stock back automatically.

== Description ==

Order Sync for ShipFusion and FluentCart connects your FluentCart store to your ShipFusion fulfillment warehouse. Paid orders go to the warehouse on their own. When the warehouse ships, the tracking number appears on the order as a FluentCart shipment, and the customer gets FluentCart's shipment email with the tracking link. Stock levels from the warehouse are copied into your products.

= What it does =

* **Sends paid orders to the warehouse**, a few seconds after payment, with the customer's shipping address and the SKU and quantity of each physical item. Digital items stay out. Only orders placed after you install the plugin are sent.
* **Keeps the shipment in step with the order.** Cancel or fully refund an order and its shipment is cancelled. Refund some items and fewer units ship. Fix the address and the shipment is updated. Put the order on hold and the shipment is held too.
* **Records tracking the FluentCart way.** Shipped orders get a FluentCart shipment with the carrier and tracking number, so tracking shows on the order screen, in FluentCart's shipment email and in the customer's account. Orders can be marked completed when the carrier reports them delivered.
* **Works with several warehouses.** Each order goes to the warehouse that has its items, Canadian orders to Toronto first, and stock levels add up every warehouse you use.
* **Copies stock levels** from ShipFusion into FluentCart for every product option with a matching SKU. A bundle's stock follows its parts.
* **Tells you when something needs you.** Orders the warehouse can't handle (no SKU, an incomplete address, a hold at the warehouse) are listed with the reason and a Retry button, and you can get an email.
* **Works with ShipFusion's webhooks**, so shipments show up right away instead of at the next check.

= What it does not do =

* It does not calculate shipping rates at checkout; those come from your FluentCart shipping settings.
* It does not create products in ShipFusion. Your SKUs must already exist in ShipFusion and match the SKUs in FluentCart.
* It does not process returns or refunds.

= Requirements =

* FluentCart 2.3 or later (FluentCart's shipments arrived in 2.3).
* A ShipFusion account with an API token (Shipfusion 360, Settings, Integrations, API Credentials).
* Stock syncing needs FluentCart's Stock Management feature switched on.

== Installation ==

1. Install and activate FluentCart.
2. In Plugins, Add New Plugin, search for "Order Sync for ShipFusion and FluentCart", then install and activate it.
3. Open FluentCart, More, ShipFusion.
4. Paste your ShipFusion API token, check the warehouses you use and click "Save and test".
5. Copy the webhook address and make a webhook secret on the same screen. In Shipfusion 360, open Settings, Webhooks, and create one webhook for each of these events with that address, the secret in its authCode field: shipment_shipped, shipment_onHold, shipment_backOrder, and, if you update stock automatically, warehouseShipment_received and lowInventory.
6. Switch on "Send paid orders to ShipFusion automatically".

== Frequently Asked Questions ==

= Does this plugin send data anywhere? =
Yes, to ShipFusion, the fulfillment service you connect it to, at api.shipfusion.com. Nothing is sent until you save your ShipFusion API token. For each order it sends the order number and date, the customer's name, shipping address, phone number and email address, the shipping method's carrier and service if you map them, any order note, and the SKU and quantity of each physical item. It reads shipments, tracking numbers and stock levels back. It sends nothing anywhere else. ShipFusion's [terms of use](https://www.shipfusion.com/terms-of-use) and [privacy and security policy](https://www.shipfusion.com/privacy-security) apply to that data.

= Where is my data stored? =
In your WordPress database: the plugin's settings, the API token (encrypted), a fingerprint of the webhook secret (not the secret itself), one row per order with its ShipFusion shipment number and status, and an activity log kept for 30 days. The log leaves out customer details unless you switch on detailed logging.

= Which orders are sent? =
Paid orders placed after you installed the plugin that have at least one physical item. Test-mode orders are never sent, because ShipFusion has no test warehouse.

= What happens when I cancel or refund an order? =
If the warehouse hasn't shipped it, the shipment is cancelled in ShipFusion. If that fails, the order is listed under "Needs attention" and you're emailed, because the warehouse may still ship it.

= I was using the older "FluentCart ShipFusion" plugin. How do I switch? =
Install this plugin; it stays off while the old one is active. Deactivate and delete the old plugin, then paste your API token on the new ShipFusion screen and replace the webhook address and secret in Shipfusion 360 with the new ones.

= I use more than one ShipFusion warehouse. Does that work? =
Yes. Check every warehouse that stores your products (Chicago IL, Las Vegas LV, York PA, Toronto TO). Each order ships from one warehouse: the first checked warehouse in the customer's country that has every item in stock, so Canadian orders go to Toronto and US orders to a US warehouse when they can. If no warehouse has everything, the order goes to the first checked warehouse in the customer's country. Stock levels in FluentCart add up all checked warehouses.

= What if ShipFusion already has an order with the same number? =
FluentCart can reuse an order number, for example after you delete the newest order or restart the numbering. If ShipFusion already has a different order with that number, the order is sent as the number plus FluentCart's order ID (for example MAG-12-345), which never repeats. The order's ShipFusion card shows the number used. You keep choosing the prefix and starting number in FluentCart's settings.

= What does uninstalling remove? =
Deleting the plugin removes its tables, settings, token and scheduled tasks. Your orders, shipments and stock in FluentCart and in ShipFusion stay as they are.

= Where do I get support? =
At [upfluent.io/support](https://upfluent.io/support/).

== Screenshots ==

1. The ShipFusion screen: connection status, the last 7 days at a glance, orders that need attention, and run-now buttons.
2. Connect with your Shipfusion 360 API token and check every warehouse you use, including Toronto for Canadian orders.
3. Automatic sync: send orders, check tracking, update stock, complete delivered orders and get email alerts.
4. Webhook setup: the address, the secret and exactly which ShipFusion events to add, plus the latest updates received.
5. An order shipped by ShipFusion: carrier and tracking number in FluentCart's Shipments panel, and the ShipFusion card.

== Changelog ==

= 1.0.0 =
* First release on WordPress.org. Replaces the private "FluentCart ShipFusion" plugin.
* Tracking is recorded as FluentCart shipments, so customers see it in FluentCart's shipment email and their account.
* Refunded items are no longer shipped, address changes reach the warehouse, and test-mode orders are never sent.
* A new ShipFusion screen shows what needs your attention, with Retry and email alerts.
