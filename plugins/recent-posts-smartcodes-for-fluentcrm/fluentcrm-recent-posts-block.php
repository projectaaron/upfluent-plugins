<?php
/**
 * Plugin Name: Recent Posts SmartCodes for FluentCRM
 * Plugin URI:  https://upfluent.io/fluentcrm-recent-posts/
 * Description: Adds a "Recent Posts" SmartCode group to FluentCRM so you can insert your latest blog posts, filtered by category, into any email.
 * Version:     2.0.0
 * Author:      UpFluent
 * Author URI:  https://upfluent.io/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: recent-posts-smartcodes-for-fluentcrm
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Update URI:  https://upfluent.io/fluentcrm-recent-posts/
 *
 * Adds a "Recent Posts" group to FluentCRM's smart codes so you can drop your
 * latest blog posts (or the full latest post) into any email.
 *
 * Smart codes:
 *   {{recent_posts.list}}              5 latest posts (thumbnail, title, excerpt, date)
 *   {{recent_posts.list_3}}            3 latest posts
 *   {{recent_posts.list_10}}           10 latest posts
 *   {{recent_posts.latest_title}}      Latest post title
 *   {{recent_posts.latest_excerpt}}    Latest post excerpt
 *   {{recent_posts.latest_url}}        Latest post full URL (https://...)
 *   {{recent_posts.latest_link}}       Latest post URL without https:// (for fields that add it)
 *   {{recent_posts.latest_slug}}       Latest post slug (for building custom URLs)
 *   {{recent_posts.latest_link_html}}  Latest post title as a link
 *   {{recent_posts.latest_button}}     "Read Latest Post" button
 *   {{recent_posts.latest_image}}      Latest post featured image
 *   {{recent_posts.latest_full}}       Latest post as a card
 *   {{recent_posts.latest_body}}       Full content of the latest post
 *
 * Filter by category: add _category_{slug-or-id} to any code, e.g.
 *   {{recent_posts.latest_body_category_news}}
 *   {{recent_posts.list_3_category_12}}
 * Underscores in the slug are converted to hyphens, so _category_press_releases
 * matches the "press-releases" category.
 *
 * Button options: add _text_{label}, _bg_{hex} and/or _color_{hex} to latest_button.
 * Use hyphens for spaces in the label. Hex colors without the #. Example:
 *   {{recent_posts.latest_button_text_Listen-Now_bg_8B4513_color_ffffff_category_news}}
 *
 * Shortcodes (also work inside FluentCRM emails):
 *   [upfluent_recent_posts count="5" category="news" show_image="yes" show_excerpt="yes" show_date="yes" post_type="post"]
 *   [upfluent_button text="Listen Now" bg_color="#0073aa" text_color="#ffffff" category="news" post_type="post"]
 *
 * Install: paste into FluentSnippets (PHP snippet), a code snippets plugin,
 * your theme's functions.php, or a small custom plugin.
 *
 * Customize: see the "Optional customizations" block at the bottom of this file.
 *
 * @package UpFluent_Recent_Posts
 * @version 2.0.0
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Register the smart codes
 * ---------------------------------------------------------------------- */
add_action( 'fluent_crm/after_init', function () {
	$codes = array(
		'list'             => 'Recent Posts List (5 posts)',
		'list_3'           => 'Recent Posts List (3 posts)',
		'list_10'          => 'Recent Posts List (10 posts)',
		'latest_title'     => 'Latest Post Title',
		'latest_excerpt'   => 'Latest Post Excerpt',
		'latest_url'       => 'Latest Post URL (full)',
		'latest_link'      => 'Latest Post URL (no https://)',
		'latest_slug'      => 'Latest Post Slug',
		'latest_link_html' => 'Latest Post Link (clickable)',
		'latest_button'    => 'Latest Post Button',
		'latest_image'     => 'Latest Post Featured Image',
		'latest_full'      => 'Latest Post (Full Card)',
		'latest_body'      => 'Latest Post Full Content',
	);

	FluentCrmApi( 'extender' )->addSmartCode( 'recent_posts', 'Recent Posts', $codes, function ( $code, $value_key, $default, $subscriber ) {
		$category = '';
		if ( preg_match( '/^(.+?)_category_(.+)$/', $value_key, $m ) ) {
			$value_key = $m[1];
			$category  = $m[2];
		}

		// Button options: _text_Listen-Now _bg_8B4513 _color_ffffff
		$button = array();
		if ( preg_match( '/_text_([^_]+)/', $value_key, $m ) ) {
			$button['text'] = str_replace( '-', ' ', $m[1] );
			$value_key      = str_replace( $m[0], '', $value_key );
		}
		if ( preg_match( '/_bg_([a-fA-F0-9]{3,6})/', $value_key, $m ) ) {
			$button['bg'] = '#' . $m[1];
			$value_key    = str_replace( $m[0], '', $value_key );
		}
		if ( preg_match( '/_color_([a-fA-F0-9]{3,6})/', $value_key, $m ) ) {
			$button['color'] = '#' . $m[1];
			$value_key       = str_replace( $m[0], '', $value_key );
		}

		switch ( $value_key ) {
			case 'list':
				return upfluent_list_html( 5, $category );
			case 'list_3':
				return upfluent_list_html( 3, $category );
			case 'list_10':
				return upfluent_list_html( 10, $category );
			case 'latest_title':
			case 'latest_excerpt':
			case 'latest_url':
			case 'latest_link':
			case 'latest_slug':
			case 'latest_link_html':
			case 'latest_image':
				return upfluent_latest_field( substr( $value_key, 7 ), $category );
			case 'latest_button':
				return upfluent_latest_button( $category, $button );
			case 'latest_full':
				return upfluent_latest_card( $category );
			case 'latest_body':
				return upfluent_latest_body( $category );
		}
		return $default;
	} );
} );

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Fetch posts. $context lets filters target one output (list, latest, button, card, body).
 */
function upfluent_get_posts( $count = 5, $category = '', $context = 'list', $post_type = 'post' ) {
	$args = array(
		'post_type'           => $post_type,
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, (int) $count ),
		'orderby'             => 'date',
		'order'               => 'DESC',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	);

	$category = trim( (string) $category );
	if ( '' !== $category ) {
		if ( is_numeric( $category ) ) {
			$args['cat'] = (int) $category;
		} else {
			$args['category_name'] = sanitize_title( str_replace( '_', '-', $category ) );
		}
	}

	$args = apply_filters( 'upfluent_query_args', $args, $context );
	return get_posts( $args );
}

function upfluent_excerpt( $post, $words ) {
	$text = $post->post_excerpt ? $post->post_excerpt : strip_shortcodes( $post->post_content );
	$text = preg_replace( '/<[^>]+>/', ' ', $text ); // keep a space where a tag was, so headings don't run into paragraphs
	return esc_html( wp_trim_words( $text, $words, '…' ) );
}

function upfluent_empty() {
	return apply_filters( 'upfluent_empty_html', '<p style="color:#666666;font-style:italic;">No recent posts available.</p>' );
}

/**
 * Email-safe list of posts (table layout, inline styles).
 */
function upfluent_list_html( $count = 5, $category = '', $opts = array(), $post_type = 'post' ) {
	$opts  = wp_parse_args( $opts, array( 'image' => true, 'excerpt' => true, 'date' => true ) );
	$posts = upfluent_get_posts( $count, $category, 'list', $post_type );
	if ( empty( $posts ) ) {
		return upfluent_empty();
	}

	$html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:20px 0;">';

	foreach ( $posts as $post ) {
		$url   = esc_url( get_permalink( $post ) );
		$title = esc_html( get_the_title( $post ) );
		$thumb = $opts['image'] ? get_the_post_thumbnail_url( $post, 'thumbnail' ) : '';

		$html .= '<tr><td style="padding:15px 0;border-bottom:1px solid #e5e5e5;vertical-align:top;">';
		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>';

		if ( $thumb ) {
			$html .= '<td width="80" style="padding-right:15px;vertical-align:top;">';
			$html .= '<a href="' . $url . '" style="text-decoration:none;"><img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( get_the_title( $post ) ) . '" width="80" height="80" style="display:block;border-radius:4px;object-fit:cover;" /></a>';
			$html .= '</td>';
		}

		$html .= '<td style="vertical-align:top;">';
		$html .= '<a href="' . $url . '" style="color:#333333;text-decoration:none;font-size:16px;font-weight:600;line-height:1.4;display:block;margin-bottom:5px;">' . $title . '</a>';
		if ( $opts['excerpt'] ) {
			$html .= '<p style="color:#666666;font-size:14px;line-height:1.5;margin:0 0 8px 0;">' . upfluent_excerpt( $post, 20 ) . '</p>';
		}
		if ( $opts['date'] ) {
			$html .= '<span style="color:#999999;font-size:12px;">' . esc_html( get_the_date( 'M j, Y', $post ) ) . '</span>';
		}
		$html .= '</td></tr></table></td></tr>';
	}

	$html .= '</table>';
	return apply_filters( 'upfluent_list_html', $html, $posts, $count );
}

/**
 * A single field from the latest post.
 */
function upfluent_latest_field( $field, $category = '' ) {
	$posts = upfluent_get_posts( 1, $category, 'latest' );
	if ( empty( $posts ) ) {
		return '';
	}
	$post  = $posts[0];
	$url   = get_permalink( $post );
	$title = get_the_title( $post );

	switch ( $field ) {
		case 'title':
			return esc_html( $title );
		case 'excerpt':
			return upfluent_excerpt( $post, 30 );
		case 'url':
			return esc_url( $url );
		case 'link':
			// Some FluentCRM link fields prepend http:// themselves; this avoids a doubled protocol.
			return preg_replace( '#^https?://#', '', $url );
		case 'slug':
			return $post->post_name;
		case 'link_html':
			return '<a href="' . esc_url( $url ) . '" style="color:#0073aa;text-decoration:underline;">' . esc_html( $title ) . '</a>';
		case 'button':
			return upfluent_latest_button( $category );
		case 'image':
			$img = get_the_post_thumbnail_url( $post, 'medium' );
			return $img ? '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" style="max-width:100%;height:auto;border-radius:4px;display:block;" />' : '';
	}
	return '';
}

/**
 * Styled button linking to the latest post. $opts: text, bg, color, padding, radius, font_size, font_weight.
 */
function upfluent_latest_button( $category = '', $opts = array(), $post_type = 'post' ) {
	$opts = wp_parse_args( array_filter( $opts ), apply_filters( 'upfluent_button_defaults', array(
		'text'        => 'Read Latest Post',
		'bg'          => '#0073aa',
		'color'       => '#ffffff',
		'padding'     => '12px 24px',
		'radius'      => '4px',
		'font_size'   => '16px',
		'font_weight' => '600',
	) ) );
	$opts['text'] = apply_filters( 'upfluent_button_label', $opts['text'] );

	$posts = upfluent_get_posts( 1, $category, 'button', $post_type );
	if ( empty( $posts ) ) {
		return '';
	}

	$style = sprintf(
		'display:inline-block;background-color:%s;color:%s;text-decoration:none;padding:%s;border-radius:%s;font-size:%s;font-weight:%s;',
		esc_attr( $opts['bg'] ),
		esc_attr( $opts['color'] ),
		esc_attr( $opts['padding'] ),
		esc_attr( $opts['radius'] ),
		esc_attr( $opts['font_size'] ),
		esc_attr( $opts['font_weight'] )
	);

	$html = '<a href="' . esc_url( get_permalink( $posts[0] ) ) . '" style="' . $style . '">' . esc_html( $opts['text'] ) . '</a>';
	return apply_filters( 'upfluent_button_html', $html, $posts[0], $opts );
}

/**
 * Latest post as a card: image, title, date, author, excerpt, button.
 */
function upfluent_latest_card( $category = '' ) {
	$posts = upfluent_get_posts( 1, $category, 'card' );
	if ( empty( $posts ) ) {
		return upfluent_empty();
	}
	$post   = $posts[0];
	$url    = esc_url( get_permalink( $post ) );
	$title  = esc_html( get_the_title( $post ) );
	$author = get_the_author_meta( 'display_name', $post->post_author );
	$img    = get_the_post_thumbnail_url( $post, 'large' );

	$html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:20px 0;background-color:#ffffff;border:1px solid #e5e5e5;border-radius:8px;overflow:hidden;">';

	if ( $img ) {
		$html .= '<tr><td style="padding:0;"><a href="' . $url . '" style="text-decoration:none;"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( get_the_title( $post ) ) . '" width="100%" style="display:block;max-width:100%;height:auto;" /></a></td></tr>';
	}

	$html .= '<tr><td style="padding:20px;">';
	$html .= '<a href="' . $url . '" style="color:#333333;text-decoration:none;font-size:22px;font-weight:700;line-height:1.3;display:block;margin-bottom:10px;">' . $title . '</a>';
	$html .= '<p style="color:#999999;font-size:13px;margin:0 0 12px 0;">' . esc_html( get_the_date( 'F j, Y', $post ) );
	if ( $author ) {
		$html .= ' &bull; By ' . esc_html( $author );
	}
	$html .= '</p>';
	$html .= '<p style="color:#555555;font-size:15px;line-height:1.6;margin:0 0 15px 0;">' . upfluent_excerpt( $post, 40 ) . '</p>';
	$html .= '<a href="' . $url . '" style="display:inline-block;background-color:#0073aa;color:#ffffff;text-decoration:none;padding:10px 20px;border-radius:4px;font-size:14px;font-weight:600;">Read More &rarr;</a>';
	$html .= '</td></tr></table>';

	return apply_filters( 'upfluent_card_html', $html, $post );
}

/**
 * Full content of the latest post, wrapped in a styled container.
 */
function upfluent_latest_body( $category = '' ) {
	$posts = upfluent_get_posts( 1, $category, 'body' );
	if ( empty( $posts ) ) {
		return '';
	}
	$post    = $posts[0];
	$content = apply_filters( 'the_content', $post->post_content );
	$styles  = apply_filters( 'upfluent_body_styles', 'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;font-size:16px;line-height:1.6;color:#333333;' );

	return apply_filters( 'upfluent_body_html', '<div style="' . esc_attr( $styles ) . '">' . $content . '</div>', $post );
}

/* -------------------------------------------------------------------------
 * Shortcode: [upfluent_recent_posts]
 * ---------------------------------------------------------------------- */
add_shortcode( 'upfluent_recent_posts', function ( $atts ) {
	$atts = shortcode_atts( array(
		'count'        => 5,
		'category'     => '',
		'show_image'   => 'yes',
		'show_excerpt' => 'yes',
		'show_date'    => 'yes',
		'post_type'    => 'post',
	), $atts, 'upfluent_recent_posts' );

	return upfluent_list_html(
		(int) $atts['count'],
		$atts['category'],
		array(
			'image'   => 'yes' === $atts['show_image'],
			'excerpt' => 'yes' === $atts['show_excerpt'],
			'date'    => 'yes' === $atts['show_date'],
		),
		sanitize_key( $atts['post_type'] )
	);
} );

/* -------------------------------------------------------------------------
 * Shortcode: [upfluent_button]
 * ---------------------------------------------------------------------- */
add_shortcode( 'upfluent_button', function ( $atts ) {
	$atts = shortcode_atts( array(
		'text'        => '',
		'bg_color'    => '',
		'text_color'  => '',
		'padding'     => '',
		'radius'      => '',
		'font_size'   => '',
		'font_weight' => '',
		'category'    => '',
		'post_type'   => 'post',
	), $atts, 'upfluent_button' );

	return upfluent_latest_button(
		$atts['category'],
		array(
			'text'        => $atts['text'],
			'bg'          => $atts['bg_color'],
			'color'       => $atts['text_color'],
			'padding'     => $atts['padding'],
			'radius'      => $atts['radius'],
			'font_size'   => $atts['font_size'],
			'font_weight' => $atts['font_weight'],
		),
		sanitize_key( $atts['post_type'] )
	);
} );

// Run the shortcodes inside FluentCRM emails.
function upfluent_do_email_shortcodes( $content ) {
	if ( has_shortcode( $content, 'upfluent_recent_posts' ) || has_shortcode( $content, 'upfluent_button' ) ) {
		$content = do_shortcode( $content );
	}
	return $content;
}
add_filter( 'fluent_crm/parse_campaign_email_text', 'upfluent_do_email_shortcodes', 10, 1 );
add_filter( 'fluent_crm/email-body-text', 'upfluent_do_email_shortcodes', 10, 1 );

/* -------------------------------------------------------------------------
 * Optional customizations (uncomment and edit)
 * ---------------------------------------------------------------------- */

// Always pull {{recent_posts.latest_body}} from one category (use the category slug):
// add_filter( 'upfluent_query_args', function ( $args, $context ) {
// 	if ( 'body' === $context ) {
// 		$args['category_name'] = 'news';
// 	}
// 	return $args;
// }, 10, 2 );

// Exclude specific posts from every output:
// add_filter( 'upfluent_query_args', function ( $args ) {
// 	$args['post__not_in'] = array( 123, 456 );
// 	return $args;
// } );

// Change the look of the full-content email:
// add_filter( 'upfluent_body_styles', function () {
// 	return 'font-family:Georgia,serif;font-size:18px;line-height:1.8;color:#222222;background:#ffffff;padding:30px;';
// } );

// Change the button text:
// add_filter( 'upfluent_button_label', function () {
// 	return 'Read today\'s post';
// } );

// Change the default button colors/size (brand the button once, everywhere):
// add_filter( 'upfluent_button_defaults', function ( $d ) {
// 	$d['bg']    = '#8B4513';
// 	$d['color'] = '#ffffff';
// 	return $d;
// } );

// Change the "no posts" message:
// add_filter( 'upfluent_empty_html', function () {
// 	return '';
// } );
