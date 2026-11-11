=== Custom Meta Fields for FluentCart ===
Contributors: projectaaron
Tags: fluentcart, fluent cart, custom fields, gtin, structured data
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom fields for FluentCart (Fluent Cart) products, shown anywhere with a shortcode, plus GTIN and MPN per variation and a brand for Google.

== Description ==

Custom Meta Fields for FluentCart adds custom product fields to FluentCart (also written Fluent Cart), the WordPress eCommerce plugin, plus GTIN, EAN, UPC, ISBN and MPN per variation and a brand per product for Google Shopping. Define a field once and it appears on every FluentCart product edit page, in a Custom Meta Fields card in the editor sidebar, saved with FluentCart's own Update button. It also adds your own questions and file uploads to the FluentCart checkout. Every feature is free.

Values are stored as real WordPress post meta and registered with `register_post_meta()`, so they are typed, exposed in the REST API, copied when a product is duplicated, and readable by page builders' custom-field dynamic tags.

= Who it is for =

* FluentCart stores that need extra product data: specs, materials, ingredients, sizes, author or ISBN.
* Stores selling on Google Shopping or Merchant Center that need a GTIN and MPN on every variation and a brand on every product.
* Stores moving from WooCommerce to FluentCart that want to keep their GTINs (identifier import).
* Stores that need extra answers at checkout: gift messages, delivery dates, file uploads, B2B details.
* Book sellers: products with fields using the IDs `spec_isbn13`, `spec_pages` or `spec_authors` (and similar `spec_` book fields) are marked up as a Book.

= Features =

* **Unlimited product fields:** text, textarea, number, select, checkbox, URL, email, date, color, media and repeater.
* **Shortcodes:** `[upfluent_cmf_field key="FIELD_ID"]` prints a field anywhere, with the shortcode shown and copyable next to each field.
* **Product structured data:** Product, BreadcrumbList and Organization JSON-LD built live from FluentCart prices, stock, images, store address, return policy and shipping rates.
* **GTIN, EAN, UPC, ISBN and MPN per variation:** FluentCart has a SKU per variation but no GTIN. Add one to every variation, plus a brand per product, with check-digit validation and duplicate detection. They go into Google structured data (as a ProductGroup with one variant per variation), and `[upfluent_cmf_gtin]` shows the selected variation's code.
* **Fluent look:** the settings screen matches the Fluent suite, supports dark mode and loads inside the FluentHub portal.
* **REST API and WordPress Abilities (MCP):** let AI agents and integrations manage your fields.
* **Permissions:** follows FluentCart's own roles for who can define fields and edit values.
* **Media fields:** images, video, audio or files from the media library, rendered responsively.
* **Repeater fields:** rows of sub-fields with drag-and-drop ordering, and `[upfluent_cmf_repeater]` in list, grid or scrolling-row layouts.
* **Elementor widgets** for media and repeater fields.
* **Checkout fields:** add your own questions to the FluentCart checkout with placement, required and conditional logic. Answers are saved on the order and shown on the order screen, the thank-you page and in emails.
* **File uploads at checkout,** stored through FluentCart's own storage driver (local, S3 or R2).
* **Identifier import:** bring GTINs and MPNs over from WooCommerce (its own GTIN field and the common GTIN plugins), matched on SKU, or import a CSV. Every row is previewed before anything is saved.

== Installation ==

1. Install and activate FluentCart.
2. In Plugins → Add New, search for "Custom Meta Fields for FluentCart", then install and activate it.
3. Go to FluentCart → More → Custom Fields (or Custom Fields in the FluentHub portal).
4. Add a field, then open any product to fill it in.

== Frequently Asked Questions ==

= How do I add custom fields to FluentCart products? =
Install this plugin, go to FluentCart → More → Custom Fields, and add a field with a title, a field ID and a type (text, textarea, number, select, checkbox, URL, email, date, color, media or repeater). The field then appears in a Custom Meta Fields card in the sidebar of every FluentCart product, saved with FluentCart's Update button.

= How do I show a custom field on a FluentCart product page? =
Use the shortcode `[upfluent_cmf_field key="FIELD_ID"]` in the product description, a page or a widget. Each field shows its ready-to-copy shortcode on the Custom Fields screen. Outside a product, add `product_id="123"`.

= Can I use FluentCart custom fields in Elementor or other page builders? =
Yes. Values are stored as WordPress post meta under the field ID, so custom-field dynamic tags (such as Elementor Pro's Post Custom Field) can read them. Media and repeater fields also have their own Elementor widgets.

= Does it add Google Shopping structured data to FluentCart? =
Yes. Every product page gets Product, BreadcrumbList and Organization JSON-LD with price, stock, images, brand, GTIN, MPN, seller, shipping and return information. Products whose variations have GTINs are marked up as a ProductGroup with one variant per variation, the format Google uses for product variants.

= Where do I get support? =
At [upfluent.io/support](https://upfluent.io/support/).

= Where are product values stored? =
As WordPress post meta on the product, keyed by the field ID.

= Where are checkout answers stored? =
On the order, in FluentCart's order meta, with a snapshot of the field label so old orders never change.

= How do I add a GTIN, EAN or UPC to FluentCart variations? =
No setup needed. Open a product: GTIN and MPN are columns right next to SKU in FluentCart's Inventory Management table, and save as you leave the field. The brand is set in the Custom Meta Fields card. A 10-digit ISBN is converted to its 13-digit GTIN automatically.

= I am moving from WooCommerce. Can I keep my GTINs? =
Yes. If the WooCommerce data is still in the same database, Custom Fields → Identifiers → Import from WooCommerce matches every GTIN to its FluentCart variation by SKU. Otherwise export a CSV of SKU and GTIN and import that.

= Does it need Fluent Forms? =
No. Checkout fields render directly into FluentCart's checkout form.

= Does this plugin send data anywhere? =
No. This plugin makes no outside requests and collects no data. Checkout uploads are stored through FluentCart's own storage driver (local, S3 or R2), which you choose in FluentCart.

= I bought the Pro edition. What happens now? =
Everything that was in Pro is now in this free plugin, and both use the same data. Install this plugin, then deactivate and delete "Custom Meta Fields for FluentCart Pro"; your fields, values and checkout answers stay as they are. The Pro edition's last update does this for you.

== Screenshots ==

1. Add custom fields to FluentCart products on the Custom Fields screen and copy each field's shortcode.
2. Fill in custom field values in the Custom Meta Fields card in the FluentCart product editor sidebar.
3. Add a GTIN and MPN to every FluentCart variation, next to the SKU, for Google Shopping structured data.
4. Custom field values and the selected variation's GTIN on a FluentCart product page, through shortcodes.

== Changelog ==

= 1.1.1 =
* Tested with FluentCart 1.7.1 and 1.8.0.
* Product structured data keeps the brand you assign in FluentCart's Brands when no brand is set in the Custom Meta Fields card (FluentCart 1.7.1+ adds brand itself; it was being replaced by the store name).

= 1.1.0 =
* Everything from the former Pro edition is now free: Media and Repeater fields with `[upfluent_cmf_repeater]`, Elementor widgets, checkout fields with conditions and file uploads, and GTIN/MPN import from WooCommerce or CSV.
* No licence, no upgrade prompts.
* Sites that still run the Pro edition get a one-click notice to switch to this plugin; nothing is lost.
* A line at the foot of the settings screen: the plugin is free, open source (GPL v2 or later) and comes without warranty.

= 1.0.1 =
* A one-time invitation to review the plugin on WordPress.org, shown on the Custom Fields screen after a week of use. Either button hides it for good.
* No more admin notice about FluentCart's own product schema: the plugin already adds its data to FluentCart's schema, so there is nothing to do.
* Clearer plugin description and FAQ.

= 1.0.0 =
* Initial public release.
* Custom product fields (text, textarea, number, select, checkbox, email, URL, date, color) in a card in the FluentCart product editor sidebar, saved with FluentCart's Update button.
* [upfluent_cmf_field] and [upfluent_cmf_gtin] shortcodes.
* GTIN and MPN per variation and a brand per product, with check-digit validation and duplicate detection.
* Product structured data: brand, identifiers, book details, seller, shipping and return info, added to FluentCart's own product schema on FluentCart 1.7+.
