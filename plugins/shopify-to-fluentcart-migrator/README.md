# Shopify to FluentCart Migrator

A free, lightweight WordPress plugin that moves a Shopify product catalog into [FluentCart](https://fluentcart.com/?by=272). Tested against FluentCart 1.6.6 and 1.7.0 on WordPress 7.1 with a real 35-product export, through the screen in a real browser and through WP-CLI checks of every row it writes.

**Three steps:** export the CSV from Shopify → upload it → review the list and publish.

Nothing is written to the store until the review screen, where every product is listed with its image, variants, price range, stock, Shopify status and any notes (barcode present, SKU already exists, no images, already imported). Untick what you don't want, set the options, import. Each product goes in its own request with a progress bar, so a large catalog never hits a PHP time limit, and the page can be reloaded mid-way without losing the results.

## Install

Download `shopify-to-fluentcart-migrator.zip` from this repository's Releases (tag `shopify-to-fluentcart-migrator-v<version>`), then upload it through **Plugins → Add New → Upload**. FluentCart must be active. The screen is at **FluentCart → Shopify Migrator** (also in FluentCart's own sidebar under *More*).

## What is migrated

| Shopify | FluentCart |
|---|---|
| Title, Body (HTML), Handle | Product title, description, slug |
| SEO Title / SEO Description | Short description; Rank Math or Yoast meta when either is active |
| Variants (Option1–3), Variant SKU, Price, Compare At Price, Cost per item | One `fct_product_variations` row per variant (`simple` when the only variant is *Default Title*, `simple_variations` otherwise) |
| Variant Grams | Weight in the unit you pick (oz, lb, g, kg) |
| Variant Inventory Tracker / Qty / Policy | `manage_stock`, `total_stock`, `available`, `backorders`, stock status |
| Variant Requires Shipping | Physical or digital fulfillment |
| Variant Taxable | Tax exempt flag |
| Image Src / Image Position / Image Alt Text | Media Library attachments; first is the featured image, all go into the FluentCart gallery. HEIC, TIFF and extension-less files are fetched as JPEG through Shopify's CDN |
| Variant Image | The variation's own image (`media_id` plus the `product_thumbnail` row FluentCart's admin and cart read) |
| Variant Packed Length / Width / Height | Dimensions in the variation's `other_info` |
| Google Shopping / MPN | MPN per variation through Custom Meta Fields |
| `product.metafields.*` columns | Kept in the product's `_s2fc_shopify_source` meta |
| Type, Product Category (last segment) or Tags | `product-categories` terms. An Import option picks the source (Type by default, or Product Category, Tags, both Type and Product Category, or none) and the review list shows the resulting categories per product before anything is written |
| Vendor | `product-brands` term |
| Tags | `product-tags` when that taxonomy exists, otherwise kept in post meta |
| Status (active / draft / archived) | publish / draft / private, or everything as draft |
| Variant Barcode (or *Variant Barcodes*) | GTIN per variation through Custom Meta Fields for FluentCart (see below). Shopify's leading apostrophe is stripped |

Every imported product also carries `_s2fc_shopify_handle` and `_s2fc_shopify_source` (the original handle, vendor, tags, per-variant SKU and barcode, image URLs) so later tools can find what came from where.

### Barcodes

FluentCart has no GTIN field and Shopify's *Variant Barcode* is exactly that. The review screen counts the products that have one and, if [Custom Meta Fields for FluentCart](https://wordpress.org/plugins/custom-meta-fields-for-fluentcart/) is not active, shows a one-click install link. With it active, each barcode is validated (check digit, ISBN-10 → GTIN-13) and saved on its variation, where it is emitted as `gtin` in the product's JSON-LD.

### Not migrated

Orders, customers, discount codes, collections (categories are built from *Type* and *Product Category* instead), subscription products. Newer Shopify exports have no *Variant Inventory Qty* column; those products are imported with stock management off and the screen says so.

**Run the import as an account you will keep.** Imported images are ordinary Media Library attachments owned by the user who ran the import. If that WordPress user is later deleted without reassigning their content, WordPress deletes the attachments with them.

## How it writes to FluentCart

FluentCart has no public "create product" PHP API, so the importer writes the rows its own REST controller would write: the `fluent-products` post, one `fct_product_details` row, one `fct_product_variations` row per variant (prices in cents, `other_info` JSON with weight, tax and bundle keys), the `fluent-products-gallery-image` meta and `_thumbnail_id`. Verified against FluentCart 1.6.x. Two filters let you adjust the rows before they are inserted:

```php
add_filter('s2fc_variation_row', function (array $row, array $variant, array $product, array $options) {
    $row['shipping_class'] = 3;
    return $row;
}, 10, 4);

add_filter('s2fc_detail_row', function (array $row, array $product, array $options) { return $row; }, 10, 3);
add_action('s2fc_product_imported', function (int $post_id, array $product, array $variation_ids, array $options) {}, 10, 4);
add_filter('s2fc_promos', function (array $items) { return $items; });
```

## Security notes

- Every entry point checks a nonce and the capability (`manage_options`, or FluentCart's `products/create` + `products/edit` when FluentCart can answer). Nothing is exposed to logged-out users.
- The uploaded CSV is stored under a random name in a protected folder and deleted the moment it has been parsed; the parsed products live as JSON Lines in the same folder and are removed on "Start over", on deactivation and on uninstall.
- Each import session belongs to the user who uploaded the file. One product is imported per request, under a per-handle lock, and a product only carries the handle marker once everything for it is written, so an interrupted request is redone rather than skipped.
- Images are fetched with `download_url()` (which refuses private hosts) and pass WordPress' file-type checks; the file is verified on disk before it is used.
- Output is escaped everywhere; SQL goes through `$wpdb->prepare()`; the code passes the WordPress coding-standard security sniffs and PHPCompatibility for PHP 7.4+.

## Development

Plain PHP and vanilla JavaScript, no build step. `includes/class-csv-parser.php` turns the export into one array per product; `includes/class-importer.php` writes it; `includes/class-admin.php` renders the screen; `includes/class-ajax.php` handles the upload and the per-product import requests. `tests/run.php` runs the parser against a sample export without WordPress:

```
php tests/run.php
```

## License

GPLv2 or later. Built by [upfluent.io](https://upfluent.io). Not affiliated with WPManageNinja or Shopify.
