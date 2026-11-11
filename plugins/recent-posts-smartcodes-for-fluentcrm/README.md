# Recent Posts SmartCodes for FluentCRM

**Insert your latest WordPress blog posts into FluentCRM emails, filtered by category, with one SmartCode.**

A free, single-file PHP snippet that adds a **Recent Posts** group to the FluentCRM SmartCode dropdown. Use it to build automated newsletters, "new post" notifications, weekly digests, or a daily devotional/email that always contains the newest post from one category. No RSS feed, no third-party service, no extra plugin dependencies. Works with FluentCRM free and Pro, campaigns, email sequences, recurring campaigns, and automations.

```
{{recent_posts.list_3_category_news}}
{{recent_posts.latest_body_category_devotionals}}
{{recent_posts.latest_button_text_Read-More_bg_222222}}
```

## Why this exists

FluentCRM's built-in "Latest Posts" block only works in the visual block editor, can't be dropped into a plain text/classic email as a SmartCode, and gives you no way to insert the *full content* of the newest post from a specific category. This snippet fills that gap:

- SmartCodes work anywhere FluentCRM parses SmartCodes: campaigns, sequences, recurring campaigns, automation emails, and the classic/raw HTML editors.
- Filter any code by category slug or ID, so one site can send different category-based newsletters.
- Get the full post body, a card, a list, a button, an image, a title, a link, or a slug, whichever fits your template.
- Email-safe HTML (tables + inline styles) that renders in Gmail, Outlook, and Apple Mail.

## Installation

Pick one:

1. **FluentSnippets** (or the Code Snippets plugin): create a new PHP snippet, paste the contents of `fluentcrm-recent-posts-block.php`, save and activate.
2. **Plugin**: download `fluentcrm-recent-posts-block.php`, upload it to `wp-content/plugins/`, and activate "Recent Posts SmartCodes for FluentCRM" in the WordPress Plugins screen.
3. **Theme**: paste the code into your theme's `functions.php`.

No settings page. Everything is configured through the SmartCode name, shortcode attributes, or the filters below.

## SmartCodes

Open the SmartCode dropdown in the FluentCRM email editor and look for **Recent Posts**.

| SmartCode | Output |
|-----------|--------|
| `{{recent_posts.list}}` | 5 latest posts (thumbnail, title, excerpt, date) |
| `{{recent_posts.list_3}}` | 3 latest posts |
| `{{recent_posts.list_10}}` | 10 latest posts |
| `{{recent_posts.latest_title}}` | Latest post title |
| `{{recent_posts.latest_excerpt}}` | Latest post excerpt |
| `{{recent_posts.latest_url}}` | Latest post full URL (`https://...`) |
| `{{recent_posts.latest_link}}` | Latest post URL without `https://` (for FluentCRM link fields that add it themselves) |
| `{{recent_posts.latest_slug}}` | Latest post slug, for building custom URLs |
| `{{recent_posts.latest_link_html}}` | Latest post title as a clickable link |
| `{{recent_posts.latest_button}}` | Styled "Read Latest Post" button |
| `{{recent_posts.latest_image}}` | Latest post featured image |
| `{{recent_posts.latest_full}}` | Latest post as a card (image, title, date, author, excerpt, button) |
| `{{recent_posts.latest_body}}` | Full content of the latest post |

### Filter by category

Append `_category_{slug-or-id}` to any SmartCode:

```
{{recent_posts.list_3_category_news}}
{{recent_posts.latest_body_category_12}}
{{recent_posts.latest_full_category_press_releases}}
```

Underscores in the slug are converted to hyphens, so `press_releases` matches the `press-releases` category. Numeric values are treated as category IDs.

### Button options

`latest_button` accepts inline options. Use hyphens for spaces in the label and hex colors without the `#`. Options can be combined with `_category_`.

```
{{recent_posts.latest_button_text_Listen-Now}}
{{recent_posts.latest_button_bg_8B4513_color_ffffff}}
{{recent_posts.latest_button_text_Read-More_bg_222222_category_news}}
```

## Shortcodes

For finer control (post type, hiding images, button sizing) use the shortcodes. They work in posts, pages, and inside FluentCRM emails.

### `[upfluent_recent_posts]`

```
[upfluent_recent_posts count="5" category="news" show_image="yes" show_excerpt="yes" show_date="yes" post_type="post"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `count` | `5` | Number of posts |
| `category` | (all) | Category slug or ID |
| `show_image` | `yes` | `yes` / `no` |
| `show_excerpt` | `yes` | `yes` / `no` |
| `show_date` | `yes` | `yes` / `no` |
| `post_type` | `post` | Any public post type |

### `[upfluent_button]`

```
[upfluent_button text="Listen Now" bg_color="#0073aa" text_color="#ffffff" category="news"]
```

| Attribute | Default | Description |
|-----------|---------|-------------|
| `text` | `Read Latest Post` | Button label |
| `bg_color` | `#0073aa` | Background color |
| `text_color` | `#ffffff` | Text color |
| `padding` | `12px 24px` | CSS padding |
| `radius` | `4px` | Border radius |
| `font_size` | `16px` | Font size |
| `font_weight` | `600` | Font weight |
| `category` | (all) | Category slug or ID |
| `post_type` | `post` | Any public post type |

## Common recipes

**Daily email that sends today's post from one category.** Create a FluentCRM recurring campaign, set the schedule, and put `{{recent_posts.latest_body_category_daily}}` in the body. Add a "send only if there is a new post" condition in the recurring campaign settings if you don't want repeats.

**Weekly digest of the last 5 posts.** Recurring campaign, weekly, body contains `{{recent_posts.list}}`.

**"New post" automation.** Trigger an automation on post publish (FluentCRM Pro) and use `{{recent_posts.latest_full}}` in the email.

**Latest podcast episode button.** If episodes are posts in a `podcast` category: `{{recent_posts.latest_button_text_Listen-Now_category_podcast}}`.

**Custom post type.** Use the shortcode: `[upfluent_recent_posts count="4" post_type="product"]`, or set `post_type` through the `upfluent_query_args` filter.

## Customization (filters)

All filters are prefixed `upfluent_`. Ready-to-uncomment examples are at the bottom of the PHP file.

| Filter | Arguments | Purpose |
|--------|-----------|---------|
| `upfluent_query_args` | `$args, $context` | Change the `WP_Query` args. `$context` is `list`, `latest`, `button`, `card`, or `body` so you can target one output. |
| `upfluent_list_html` | `$html, $posts, $count` | Modify the posts list HTML |
| `upfluent_card_html` | `$html, $post` | Modify the latest-post card HTML |
| `upfluent_body_html` | `$html, $post` | Modify the full-content HTML |
| `upfluent_body_styles` | `$css` | Inline CSS for the full-content wrapper |
| `upfluent_button_label` | `$label` | Text of the `latest_button` |
| `upfluent_button_defaults` | `$defaults` | Default button text, colors, padding, radius, font size/weight |
| `upfluent_button_html` | `$html, $post, $opts` | Modify the button HTML |
| `upfluent_empty_html` | `$html` | Output when no posts are found |

Example: always pull `latest_body` from one category.

```php
add_filter( 'upfluent_query_args', function ( $args, $context ) {
	if ( 'body' === $context ) {
		$args['category_name'] = 'news';
	}
	return $args;
}, 10, 2 );
```

## FAQ

**Does this work with FluentCRM free or only Pro?**
Both. SmartCodes are a core FluentCRM feature. Automations triggered on post publish require Pro, but recurring campaigns and manual campaigns work on free.

**Can I use it in FluentCRM automations and sequences?**
Yes. Anywhere FluentCRM parses SmartCodes.

**Can I show posts from a custom post type?**
Yes, via the `post_type` shortcode attribute or the `upfluent_query_args` filter.

**Can I filter by tag or a custom taxonomy?**
Not through the SmartCode name, but you can add a `tax_query` in the `upfluent_query_args` filter.

**Why does `latest_link` strip `https://`?**
Some FluentCRM button and image link fields prepend `http://` to whatever you enter. Use `latest_link` in those fields and `latest_url` everywhere else.

**Does it send an RSS feed?**
No. It reads posts directly from your WordPress database, so there's no feed to configure and no caching delay.

**Is the HTML email-safe?**
Yes. Table-based layouts with inline styles, tested in Gmail, Outlook, and Apple Mail.

## Requirements

- WordPress 5.0+
- FluentCRM 2.5+ (free or Pro)
- PHP 7.4+

## Contributing

Issues and pull requests are welcome. Keep changes email-client-safe (tables, inline styles) and prefix any new function or filter with `upfluent_`.

## License

GPL v2 or later. See [LICENSE](LICENSE).

---

*Keywords: FluentCRM recent posts, FluentCRM latest post SmartCode, FluentCRM blog posts in email, FluentCRM newsletter from blog posts, FluentCRM category posts email, FluentCRM RSS to email alternative, WordPress latest posts email, FluentCRM dynamic content, FluentCRM post digest, FluentCRM automation latest post, FluentCRM shortcode recent posts, FluentCRM smart code custom.*
