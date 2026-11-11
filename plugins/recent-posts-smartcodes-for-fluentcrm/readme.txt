=== Recent Posts SmartCodes for FluentCRM ===
Contributors: upfluent
Tags: fluentcrm, recent posts, newsletter, email marketing, smartcode
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Insert your latest WordPress blog posts into FluentCRM emails, filtered by category, with one SmartCode.

== Description ==

Adds a **Recent Posts** group to the FluentCRM SmartCode dropdown. Drop your latest posts, or the full text of the newest post in a category, into any campaign, sequence, recurring campaign or automation email.

* `{{recent_posts.list}}`, `{{recent_posts.list_3}}`, `{{recent_posts.list_10}}`: post lists with thumbnail, title, excerpt and date
* `{{recent_posts.latest_full}}`: the newest post as a card
* `{{recent_posts.latest_body}}`: the full content of the newest post
* `{{recent_posts.latest_title}}`, `latest_excerpt`, `latest_url`, `latest_link`, `latest_slug`, `latest_link_html`, `latest_image`, `latest_button`
* Filter any code by category: `{{recent_posts.list_3_category_news}}`
* Style the button inline: `{{recent_posts.latest_button_text_Read-More_bg_222222_color_ffffff}}`
* Shortcodes `[upfluent_recent_posts]` and `[upfluent_button]` for custom post types and finer control
* Nine `upfluent_*` filters to change queries, HTML and styles

Email-safe HTML (tables and inline styles). No settings page, no external service, no RSS feed.

Free and open source: https://github.com/projectaaron/upfluent-plugins/tree/main/plugins/recent-posts-smartcodes-for-fluentcrm

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the zip from Plugins → Add New → Upload.
2. Activate it.
3. Open any FluentCRM email and look for **Recent Posts** in the SmartCode dropdown.

== Frequently Asked Questions ==

= Does it work with FluentCRM free? =
Yes. SmartCodes are a core FluentCRM feature.

= Can I show a custom post type? =
Yes, with the `post_type` shortcode attribute or the `upfluent_query_args` filter.

= Can I filter by tag or a custom taxonomy? =
Add a `tax_query` in the `upfluent_query_args` filter.

== Changelog ==

= 2.0.0 =
* Generic `upfluent_` rewrite. Category suffix on every SmartCode, button options, `latest_url`, `latest_slug`, `[upfluent_button]`, filters for every output.

= 1.x =
* Original `fluentcrm_*` snippet.
