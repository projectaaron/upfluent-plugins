=== Shopify to FluentCart Migrator ===
Contributors: projectaaron
Tags: fluentcart, shopify, migration, import, products
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Move your Shopify products into FluentCart: export the CSV, upload it, review the list, publish. Free.

== Description ==

Shopify to FluentCart Migrator brings your Shopify catalog into FluentCart in three steps, with a review screen in between so nothing lands in your store until you say so.

1. **Export** – the screen walks you through Shopify's Products → Export.
2. **Upload** – drop the CSV on the page. Nothing is written yet.
3. **Review and publish** – every product in a clean list with its image, variants, price range, stock and Shopify status. Untick what you don't want, pick the options, and import. Each product is imported in its own request with a progress bar, so a big catalog never times out.

= What comes across =

* Title, description, handle (kept as the URL slug), SEO title and description
* Variants with options, SKU, price, compare-at price, cost, weight, inventory, backorder policy, taxable and shipping flags
* All product images into the Media Library, the first one as the featured image, the rest in the FluentCart gallery; variant images on their variations
* Packed dimensions, MPN and Shopify metafields (kept on the product for later tools)
* Product categories from Shopify Type (default), Product Category, Tags, or both Type and Product Category, previewed per product before import; Vendor as product brand; tags
* Shopify status (active, draft, archived), or import everything as draft to check first

= Barcodes (GTIN, UPC, EAN, ISBN) =

FluentCart has no barcode field. The migrator detects which of your products have one and tells you. Install the free [Custom Meta Fields for FluentCart](https://wordpress.org/plugins/custom-meta-fields-for-fluentcart/) before importing and every code is saved on its variation and emitted in Google product structured data. Without it, the codes are kept in a hidden note on each product so nothing is lost.

= What does not come across (yet) =

Orders, customers, discount codes, collections as collections (categories are created from Type and Product Category instead), and subscription products. The importer is deliberately small.

= Safe to re-run =

Products already imported from the same Shopify handle are shown as such and skipped by default. A SKU that already exists in FluentCart is detected before you import.

= Links =

Links to FluentCart in this plugin carry upfluent.io's affiliate reference. Buying through them costs you nothing extra and helps fund this free plugin. The plugin is independent and not affiliated with WPManageNinja or Shopify.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it through Plugins → Add New.
2. Activate it. FluentCart must be active.
3. Go to FluentCart → Shopify Migrator.

== Frequently Asked Questions ==

= Will it change my existing FluentCart products? =

No. It only creates new products. Products already imported from the same Shopify handle are skipped.

= Why are my products drafts? =

That is the default so you can look each one over. Choose "Same as Shopify" or "Publish everything now" under Import options.

= My product images disappeared later. =

Run the migration as the WordPress account you intend to keep. WordPress attributes every downloaded image to the user who ran the import, and deleting that user (without attributing their content to someone else) deletes those images along with it. That is standard WordPress behaviour for any upload.

= The upload says the file is too big. =

Your host limits upload size. Ask them to raise `upload_max_filesize`, or export a filtered selection from Shopify in a few batches.

== Changelog ==

= 1.0.0 =
* First release. Tested with FluentCart 1.6.6 and 1.7.0 on WordPress 7.1.
