# Custom Meta Fields for FluentCart

Custom fields for FluentCart in two places, managed from one screen with a **Product** tab and a **Checkout** tab.

**Product fields** add unlimited custom meta fields to FluentCart product edit pages. Define a field ID, type, and title once; the field appears on every product editor. Values are stored as WordPress post meta on the product and registered with `register_post_meta()`, so they are typed, REST-exposed, copied when a product is duplicated, and readable by Elementor dynamic tags. Two Elementor widgets and two shortcodes render them on the front end.

**Checkout fields** add inputs to FluentCart's checkout form, with a placement, a required flag and conditional logic. What the customer enters is validated, saved with the order, shown on the order screen and the thank-you page, and available in every FluentCart email as `{{checkout.FIELD_ID}}`.

Verified against FluentCart 1.7.0.

## Install

Install it from WordPress.org: **Plugins → Add New**, search for "Custom Meta Fields for FluentCart" (https://wordpress.org/plugins/custom-meta-fields-for-fluentcart/). Requires FluentCart to be active.

## Field types

| Type | Stored as | Notes |
|---|---|---|
| Text, Textarea, Number, Select, URL, Email, Date, Color | string | Date is `YYYY-MM-DD`, color is `#rrggbb`, select must be a defined option. |
| Checkbox | `"1"` / `"0"` | |
| **Media** | attachment ID (integer) | Restrict with **Allowed types**: any, image, video, audio, file. The editor shows the standard media modal filtered to that type, a preview, and Replace/Remove. |
| **Repeater** | array of rows | Define the row's columns (sub-fields) with an ID, Title, and any type above including Media. One level only. Rows can be added, removed, and drag-reordered. Unlimited rows. |

Storage: post meta key = the field ID, single value. Every field is registered via `register_post_meta()` with `show_in_rest`, a type (`string`, `integer`, or `array` with a row schema), a sanitize callback, and an auth callback tied to FluentCart's `products/edit` permission.

## Rendering on the front end

Both shortcodes accept `post_id` (defaults to the current post) and output an empty string when the field is empty. A small stylesheet with `cmf-` classes is enqueued only when a shortcode renders something.

```
[upfluent_cmf_field key="FIELD_ID" size="large"]
```
Outputs one field. Media resolves the attachment: images render with `srcset` at the given WordPress image `size`; videos render `<video controls preload="metadata" poster>`; audio renders `<audio>`; other files render a download link.

```
[upfluent_cmf_repeater key="FIELD_ID" layout="list|grid|row" columns="3"]
```
- `list` stacked rows
- `grid` CSS grid, `columns` wide (1 to 6, responsive)
- `row` one horizontal strip with sideways scroll and scroll-snap

## Checkout fields

Defined on the **Checkout** tab of the settings screen. A checkout field has:

| Setting | Meaning |
|---|---|
| Field ID, Title, Type | As for product fields. Types: text, textarea, number, select, checkbox, URL, email, date, **file upload**. |
| Placeholder, Help text | Shown in and under the input. |
| Placement | Where the field renders: before the billing address, after the shipping address (physical orders only), before or after the payment methods, after the place-order button, or in the order summary after the order notes. |
| Enabled | Switch the field off without deleting it. |
| Required | Enforced client-side and server-side, but only while the field is visible. |
| Allowed file types, Max size | File fields only. Extensions default to `jpg, jpeg, png, gif, webp, pdf, doc, docx, txt, zip`; scripts and HTML are always refused. Size defaults to 10 MB and is capped by the server's upload limit. |
| Conditional logic | Show the field only when *all* or *any* rules match. A rule compares another checkout field, or one of FluentCart's own inputs (billing/shipping country and state, ship-to-different, payment method, shipping method, business purchase, create-account), using is / is not / contains / does not contain / is filled / is empty. |

**Every checkout field also appears on FluentCart's own Settings → Store Settings → Checkout Fields screen**, in a *Custom Fields* section with the same on/off switch and Required checkbox as the built-in fields, so the store's checkout configuration stays in one place. That screen has no PHP filter in 1.6.3, so the plugin adds the section at the REST layer: it appends the group to the `checkout-fields/get-fields` response and reads `settings.custom_fields` back out of the `save-fields` request (after FluentCart's own permission check and save have succeeded).

**File uploads go wherever FluentCart keeps its files.** Uploads use FluentCart's storage drivers (`FileManager`): the first remote driver enabled under Settings → Storage (S3, or R2 with FluentCart Pro), otherwise Local, exactly as the store's product files. Override with the `upfluent_cmf_upload_storage_driver` filter. Because FluentCart's checkout script posts the form URL-encoded, a file can't travel with the order; instead the browser uploads it the moment it is chosen (admin-ajax, nonce, active cart required), the stored file gets a random name, and a token goes into a hidden input. Placing the order attaches the file to the order record; unclaimed uploads are deleted by WP-Cron after a day. Local files live in `uploads/fluent-cart/cmf-checkout/` behind an `.htaccess` deny and an `index.php`. Admins download from the order screen through an authenticated endpoint (`orders/view`); remote drivers redirect to a signed URL.

**How it works in FluentCart 1.6.x.** Every placement is a `do_action` inside the checkout `<form>`; FluentCart's checkout script posts the whole form with `FormData`, so the inputs travel with the order. Inputs are named `cmf_FIELD_ID` so they can never collide with FluentCart's own. Validation runs in `fluent_cart/checkout/validate_data` (errors join FluentCart's toast), values are saved in `fluent_cart/checkout/prepare_other_data` to one `fct_order_meta` row (`cmf_checkout_fields`, JSON) via `Order::updateMeta()`. Conditions are evaluated twice with identical rules: in the browser against the live form, and on the server against the submitted data, so a hidden field is never required and never saved. Hidden fields are `disabled`, so the browser leaves them out of the request.

**Where the values show up:**

- **Order screen:** a *Checkout Fields* card, via `fluent_cart/widgets/single_order_page`.
- **Thank-you page:** an *Additional details* block after the order items (`upfluent_cmf_checkout_receipt_enabled` / `upfluent_cmf_checkout_receipt_heading` filters).
- **Emails:** a *Checkout Fields* group in the email editor's code picker. `{{checkout.FIELD_ID}}` prints one value, `{{checkout.all}}` prints a table of everything the customer filled in. Resolved through `fluent_cart/smartcode_fallback`.
- **REST / MCP:** the `fct-custom-meta/get-order-checkout-fields` ability returns the stored values for an order.

Each saved value carries the label and type the field had at the time, so renaming or deleting a field later never changes what an old order shows. Deleting a checkout field keeps existing order values (it is the customer's record); deleting a product field removes its values.

The checkout script (`assets/checkout.js`) also validates the visible fields before FluentCart's own handler runs, showing the message under the field. FluentCart triggers submission with a synthetic event, so native browser validation never fires; this fills that gap.

Filters: `upfluent_cmf_checkout_fields_to_render` ($fields, $placement, $args), `upfluent_cmf_checkout_values_to_save` ($values, $order, $data), `upfluent_cmf_upload_storage_driver` ($slug), `upfluent_cmf_upload_max_bytes` ($bytes, $def). Action: `upfluent_cmf_checkout_fields_saved` ($order, $values).

## Elementor

Two widgets appear in the editor under a **FluentCart Fields** category:

- **Custom Field: Media** renders a Media field. It picks the markup from the attachment itself: images get `srcset` at your chosen size, videos get a player with a poster, audio gets a player, anything else gets a download link. Controls cover image size, link target, caption source, playback (controls, autoplay, loop, muted, inline), width, aspect ratio, border, radius, shadow, CSS filters, and separate hover styling.
- **Custom Field: Repeater** loops a Repeater field's rows in a list, grid, or sideways-scrolling strip. Controls cover which sub-fields to show, row limit, labels, responsive columns and gaps, card background, padding, border, radius and shadow, plus typography for labels and values and sizing for media inside cards.

Both take a **Product ID**, defaulting to the product being displayed, so they work in a Theme Builder product template or on a specific product.

Each widget has a **Field** dropdown rather than there being one widget per field. Elementor instantiates a widget by its PHP class name, so one class can only be one widget type; a picker also means renaming or deleting a field cannot break a page that already uses the widget. When a field is missing the widget renders nothing on the front end and explains itself in the editor.

**Scalar fields need no widget.** Because every value is post meta keyed by the field ID, Elementor Pro's own *Post Custom Field* dynamic tag already reads your text, number, date, colour, URL and email fields into any widget. Use these two widgets for media and repeaters, which dynamic tags cannot express.

The shortcodes below remain available for use inside an Elementor Shortcode widget or anywhere else.

## Use in the admin

- **Enter values:** the scalar field types (text, textarea, number, select, checkbox, url, email, date, color) render as a **Custom Meta Fields** card in the product editor's sidebar through FluentCart's `fluent_cart/widgets/single_product_page` slot (`includes/EditorWidget.php`). They post inside FluentCart's own save as `metaValue.fct_cmf_fields` and are written on `fluent_cart/product_updated`, so one Update button saves everything. The renderer's Select needs `options` as `[{value,label}]` rows, textarea needs `attributes.type = textarea`, and date needs `attributes['value-format'] = 'YYYY-MM-DD'` or it posts an ISO timestamp. Media and Repeater join the same card: `ProDefinitions::widget*` renders each as an `html` mount (`.fct-cmf-pro-mount`) plus a hidden state element and a hidden text input keyed `cmf:pro`; `admin-pro.js` (`sidebar`) fills the mounts with the existing media and repeater inputs and writes every edit as JSON into that input, so it posts inside FluentCart's save and `widgetSaveValues` decodes it. The base widget only exposes the filters (`upfluent_cmf_editor_widget_types`, `upfluent_cmf_editor_widget_field`, `upfluent_cmf_editor_widget_extend`, `upfluent_cmf_editor_widget_save_values`). The injected card below the editor now only holds product identifiers.
- **Define fields:** in FluentCart's sidebar, under **More → Custom Fields**. It is also in the WordPress sidebar under FluentCart. The screen has a **Product Fields** tab and a **Checkout Fields** tab (`#product` / `#checkout` in the URL).
- **FluentHub:** when FluentHub (`fluent-toolkit`) is active with its Unified UI switched on, the screen loads inside the portal as its own **Custom Fields** app — product sidebar, dark mode and all — with *Product Fields* and *Checkout Fields* as sidebar items that follow the tabs. Registered through FluentHub's `fluent_toolkit/unified_apps` filter from `plugins_loaded` (it is applied at `init` priority 10, so anything later is silently ignored), keyed by the page slug with `parent_slug => 'fluent-cart'` so the sub-menu page hooks correctly. Without FluentHub it is a normal WordPress admin page.
- **Look and feel:** the screen is styled on FluentHub's `--fui-*` design tokens with fallbacks to FluentCart's `--fc-*` tokens and then to fixed values, so it matches whichever shell it sits in and follows the FluentHub / FluentCart dark-mode toggles.
- **Edit product values:** open any product; the *Custom Meta Fields* card sits at the end of the main column, below the other boxes, with its own Save button and an **Add New Field** link.
- **Codes are shown in place.** Each field in the product editor displays its shortcode under the label; each row of the Product table has a **Copy Shortcode** button and each row of the Checkout table a **Copy Email Code** button.
- **Permissions:** field definitions need `store/settings`; product values need `products/view` to read and `products/edit` to write; order checkout values need `orders/view`. Administrators have everything. With FluentCart Pro, shop roles work as configured.

## Structured data (JSON-LD)

On single product pages the plugin emits three nodes in `wp_head`: a **Product**, a **BreadcrumbList** (Home, Shop, primary category, product), and an **Organization** that the offer's `seller` points at. No `FAQPage` is emitted, because the Elementor accordion on the product template already produces one.

Everything is read live, nothing is hardcoded per product. Prices, SKU and stock come from `fct_product_variations`, currency and store address from FluentCart's store settings, images from the featured image plus the product gallery, ratings from WP Social Ninja's review table, and book details from post meta.

A product with no variation row emits nothing at all, since a Product without `offers` is worse than no Product.

**Book typing is automatic.** When the product carries any book data the node is typed `["Product","Book"]` and gains `isbn`, `gtin13`, `numberOfPages`, `bookFormat`, `author`, `publisher` and `datePublished`. A product with none of that stays a plain `Product`.

**Meta keys are a filterable map**, defaulting to `subtitle`, `spec_isbn13`, `spec_isbn10`, `spec_pages`, `spec_format`, `spec_authors`, `spec_publisher`, `spec_pub_date`. Values stored by this plugin's own repeater fields are flattened to a comma separated list, so a repeater can feed `author`.

Filters:

| Filter | Purpose |
|---|---|
| `upfluent_cmf_product_schema` | The finished Product array, before output |
| `upfluent_cmf_breadcrumb_schema` / `upfluent_cmf_organization_schema` | The other two nodes |
| `upfluent_cmf_schema_meta_keys` | Remap logical names to post meta keys |
| `upfluent_cmf_product_schema_types` | Override the `@type` array |
| `upfluent_cmf_schema_enabled` | Return false to suppress output for a product |
| `upfluent_cmf_schema_strip_foreign` | Return false to leave other plugins' Product nodes alone |
| `upfluent_cmf_shop_url` | Override the Shop breadcrumb URL |
| `upfluent_cmf_brand_name` | Override the brand and Organization name |
| `upfluent_cmf_author_names` | Override how an author string is split |
| `upfluent_cmf_return_policy` | Override or omit the return policy |
| `upfluent_cmf_shipping_details` | Override or omit the shipping rates |

**Brand name.** The site title is often styled for a logo, so it is only the fallback. Set the public-facing name in `wp-config.php`:

```php
define('FCT_CMF_BRAND_NAME', 'Marriage After God');
```

**Offers carry a return policy and shipping rates**, which Google leans on for Merchant listings.

The return policy is a thirty day window, by mail, with the customer paying return shipping. Adjust with `FCT_CMF_RETURN_DAYS` and `FCT_CMF_RETURN_FEES`. If the customer pays a known flat amount, declare it too, which is what Google asks for alongside `ReturnShippingFees`:

```php
define('FCT_CMF_RETURN_FEE_AMOUNT', 9.95);
```

Shipping rates are read from FluentCart's zones and methods. Only free shipping and per-order flat rates can be stated as one figure, so percentage, per-item, per-price and per-weight methods are skipped rather than guessed at.

**Author names sharing a surname are handled.** "Aaron & Jennifer Smith" becomes two people, both surnamed Smith, rather than someone called "Aaron".

**Descriptions prefer a hand-written excerpt.** The generated one is only a fallback, because `get_the_excerpt()` runs filters that other plugins append to. Entities are decoded, so no `&mdash;` reaches the output.

**Competing Product nodes are removed.** WP Social Ninja emits its own Product node with the site name, empty image and description, and no offers; two Product nodes make Google choose arbitrarily. The page is buffered on product pages only, and any `application/ld+json` block containing `"Product"` but no `"offers"` is dropped. Our own nodes carry an `id` attribute so they are never touched, the cleanup only runs when our schema actually rendered, and any failure returns the page unchanged.

Rank Math's `og:type` is switched to `product` on these pages, which it otherwise reports as `article`.

**Verifying:** open a published product in Google's Rich Results Test. Expect exactly one Product showing price, currency and availability, plus Breadcrumbs, plus the FAQ block from Elementor. Confirm `isbn` and `gtin13` appear, since without a GTIN the product stays ineligible for Merchant listings.

## One edition

Since 1.1.0 there is one free plugin (`custom-meta-fields-for-fluentcart`, WordPress.org) with every feature; the former Pro edition was merged in and the licence code removed. The Media and Repeater types, checkout fields and uploads, CSV identifier import, media/repeater rendering and the Elementor widgets keep their own classes (`ProDefinitions`, `MediaHelper`, `CheckoutFields`, `CheckoutUploads`, `CheckoutSettingsBridge`, `IdentifierImport`, `ProRendering`, `ElementorWidgets/`), loaded by the main file and hooked into the shared services through filters (`upfluent_cmf_contexts`, `upfluent_cmf_field_types`, `upfluent_cmf_sanitize_definition_input`, `upfluent_cmf_normalize_definition`, `upfluent_cmf_validate_definition`, `upfluent_cmf_sanitize_value`, `upfluent_cmf_product_values`, `upfluent_cmf_register_meta_args`, `upfluent_cmf_ability_field_properties`, `upfluent_cmf_ability_definitions`, `upfluent_cmf_admin_config`).

Sites still running the old Pro edition (folder `custom-meta-fields-for-fluentcart-pro`, up to 1.0.1) keep it in charge; this plugin then shows a one-click notice to deactivate Pro. Both use the same data. Pro's final update (1.1.0) installs and activates this plugin and switches itself off.

## Releasing

Releases go to WordPress.org. This repository is a published copy of each release; development happens elsewhere, so pull requests here are not merged directly. Issues are welcome.

## REST API

Namespace `fct-custom-meta/v1`, cookie auth with `X-WP-Nonce` or Application Passwords.

| Method | Route | Permission |
|---|---|---|
| GET / POST | `/fields` | `store/settings` |
| PUT / DELETE | `/fields/{field_id}` | `store/settings` |
| POST | `/fields/reorder` (`{ "order": [ids] }`) | `store/settings` |
| GET | `/product/{product_id}/fields` → `fields`, `values`, `attachments` | `products/view` |
| POST | `/product/{product_id}/fields` (`{ "values": { … } }`) | `products/edit` |

A field definition: `context` (`product` default, or `checkout`; fixed after creation), `field_id`, `field_type`, `field_title`, `options` (select), `placeholder`, `allowed_types` (media), `sub_fields` (repeater: array of `{ field_id, field_title, field_type, options, allowed_types }`). Checkout definitions add `placement`, `enabled`, `required`, `description`, `allowed_extensions` + `max_size_mb` (file type) and `conditions` (`{ enabled, relation: all|any, rules: [ { field, operator, value } ] }`, where `field` is a form input name: `cmf_FIELD_ID` for another checkout field, or a FluentCart input such as `billing_country`).

Values are also readable through core's post-meta REST exposure where the product post type is exposed, and through the WordPress Abilities API (`fct-custom-meta/*`, including `get-order-checkout-fields`) when WordPress 6.9+ is present.

## Data and cleanup

- Definitions: option `fct_custom_meta_field_definitions` (autoload off), both contexts in one list.
- Product values: post meta on the product, key = field ID.
- Checkout values: one row per order in `fct_order_meta`, key `cmf_checkout_fields`, JSON of `field_id → { label, type, value, file? }` where `file` is `{ driver, path, bucket, name, size, mime }` in FluentCart storage.
- Pending uploads: option `fct_cmf_pending_uploads`, pruned by the `fct_cmf_cleanup_uploads` cron event.
- Deleting a product removes its values (WordPress does this). Deleting a product field removes its values from every product; deleting a checkout field keeps order values. Uninstalling removes everything, including the order rows and locally stored uploads (files on a remote driver stay in the bucket).
- Upgrading from an early pre-release build migrates values out of FluentCart's `fct_product_meta` table once, the first time an administrator loads the site.
