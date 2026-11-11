# UpFluent plugins

Free WordPress plugins for the Fluent suite (FluentCart, FluentCRM and friends) by [UpFluent](https://upfluent.io/).

Every plugin is free, with all its features. Each one lives in its own folder under `plugins/`, and every release has a ready-to-install zip on [Releases](../../releases). **All plugins are provided as is, without warranty, and you use them at your own risk: see [No warranty](#no-warranty).**

## Plugins

| Plugin | Version | What it does |
|---|---|---|
| [Custom Meta Fields for FluentCart](#custom-meta-fields-for-fluentcart) | 1.1.1 | Custom fields for FluentCart (Fluent Cart) products, shown anywhere with a shortcode, plus GTIN and MPN per variation and a brand for Google. |
| [Recent Posts SmartCodes for FluentCRM](#recent-posts-smartcodes-for-fluentcrm) | 2.0.0 | Insert your latest WordPress blog posts into FluentCRM emails, filtered by category, with one SmartCode. |
| [Shopify to FluentCart Migrator](#shopify-to-fluentcart-migrator) | 1.0.0 | Move your Shopify products into FluentCart: export the CSV, upload it, review the list, publish. Free. |

### Custom Meta Fields for FluentCart

Custom Meta Fields for FluentCart adds custom product fields to FluentCart (also written Fluent Cart), the WordPress eCommerce plugin, plus GTIN, EAN, UPC, ISBN and MPN per variation and a brand per product for Google Shopping. Define a field once and it appears on every FluentCart product edit page, in a Custom Meta Fields card in the editor sidebar, saved with FluentCart's own Update button. It also adds your own questions and file uploads to the FluentCart checkout. Every feature is free.

| | |
|---|---|
| Version | 1.1.1 |
| Requires | FluentCart (active) |
| WordPress | 5.9 or later (tested up to 7.1) |
| PHP | 7.4 or later |
| License | GPLv2 or later |

- Download: [Releases](../../releases?q=custom-meta-fields-for-fluentcart&expanded=true) (zip `custom-meta-fields-for-fluentcart.zip`)
- Product page: <https://upfluent.io/fluentcart-custom-meta-fields/>
- Documentation: [plugins/custom-meta-fields-for-fluentcart/README.md](plugins/custom-meta-fields-for-fluentcart/README.md)
- Changelog and FAQ: [plugins/custom-meta-fields-for-fluentcart/readme.txt](plugins/custom-meta-fields-for-fluentcart/readme.txt)
- Source: [plugins/custom-meta-fields-for-fluentcart/](plugins/custom-meta-fields-for-fluentcart/)

What's new in 1.1.1:

* Tested with FluentCart 1.7.1 and 1.8.0.
* Product structured data keeps the brand you assign in FluentCart's Brands when no brand is set in the Custom Meta Fields card (FluentCart 1.7.1+ adds brand itself; it was being replaced by the store name).

### Recent Posts SmartCodes for FluentCRM

Adds a **Recent Posts** group to the FluentCRM SmartCode dropdown. Drop your latest posts, or the full text of the newest post in a category, into any campaign, sequence, recurring campaign or automation email.

| | |
|---|---|
| Version | 2.0.0 |
| Requires | FluentCRM (active) |
| WordPress | 5.0 or later (tested up to 7.1) |
| PHP | 7.4 or later |
| License | GPLv2 or later |

- Download: [Releases](../../releases?q=recent-posts-smartcodes-for-fluentcrm&expanded=true) (zip `recent-posts-smartcodes-for-fluentcrm.zip`)
- Product page: <https://upfluent.io/fluentcrm-recent-posts/>
- Documentation: [plugins/recent-posts-smartcodes-for-fluentcrm/README.md](plugins/recent-posts-smartcodes-for-fluentcrm/README.md)
- Changelog and FAQ: [plugins/recent-posts-smartcodes-for-fluentcrm/readme.txt](plugins/recent-posts-smartcodes-for-fluentcrm/readme.txt)
- Source: [plugins/recent-posts-smartcodes-for-fluentcrm/](plugins/recent-posts-smartcodes-for-fluentcrm/)

What's new in 2.0.0:

* Generic `upfluent_` rewrite. Category suffix on every SmartCode, button options, `latest_url`, `latest_slug`, `[upfluent_button]`, filters for every output.

### Shopify to FluentCart Migrator

Shopify to FluentCart Migrator brings your Shopify catalog into FluentCart in three steps, with a review screen in between so nothing lands in your store until you say so.

| | |
|---|---|
| Version | 1.0.0 |
| Requires | FluentCart (active) |
| WordPress | 5.9 or later (tested up to 7.1) |
| PHP | 7.4 or later |
| License | GPLv2 or later |

- Download: [Releases](../../releases?q=shopify-to-fluentcart-migrator&expanded=true) (zip `shopify-to-fluentcart-migrator.zip`)
- Documentation: [plugins/shopify-to-fluentcart-migrator/README.md](plugins/shopify-to-fluentcart-migrator/README.md)
- Changelog and FAQ: [plugins/shopify-to-fluentcart-migrator/readme.txt](plugins/shopify-to-fluentcart-migrator/readme.txt)
- Source: [plugins/shopify-to-fluentcart-migrator/](plugins/shopify-to-fluentcart-migrator/)

What's new in 1.0.0:

* First release. Tested with FluentCart 1.6.6 and 1.7.0 on WordPress 7.1.

## Installing

1. Download the plugin's zip from [Releases](../../releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and click **Install Now**, then **Activate**.
3. Make sure the Fluent plugin it works with is installed and active.

Back up your site first, and try new plugins and updates on a staging site before your live store.

## Support and contributing

Report bugs and request features in [Issues](../../issues). Pull requests are welcome: this repository is updated automatically from each release, so accepted changes ship in the plugin's next version. More help: [upfluent.io](https://upfluent.io/).

## No warranty

These plugins are free software, provided "as is", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose and non-infringement. **You install and use them at your own risk.** In no event shall UpFluent or the authors be liable for any claim, damages, data loss or other liability, whether in an action of contract, tort or otherwise, arising from, out of or in connection with the plugins or their use. Each plugin is licensed under the GNU General Public License, version 2 or later; see its `readme.txt` and license for the full terms.
