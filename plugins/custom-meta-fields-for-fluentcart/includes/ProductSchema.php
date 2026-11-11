<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * JSON-LD structured data for single FluentCart product pages.
 *
 * Emits three nodes in wp_head: a Product (typed as Book as well when the
 * product carries book data), a BreadcrumbList, and an Organization the offer's
 * seller points at. FAQPage is deliberately not emitted; the Elementor accordion
 * on the product template already produces one.
 *
 * FluentCart 1.7+ prints its own Product node (wp_head 10). When it does, our
 * Product data is merged into that node through its fluent_cart/review/json_ld
 * filter instead of printing a second Product block: FluentCart's price, stock
 * and reviews win, everything it lacks (brand, identifiers, book data, shipping,
 * returns, seller, gallery) is added. If FluentCart never prints its node, our
 * own Product block is printed late in wp_head as a fallback.
 *
 * Every value is read live from FluentCart, store settings, and post meta, so
 * nothing is per-product hardcoded. The meta key map and each finished node run
 * through filters, so another site can remap or replace them without editing
 * this class.
 */
class ProductSchema
{
    const SCRIPT_ID_PREFIX = 'fct-cmf-schema-';

    /** FluentCart core's Product JSON-LD filter (FluentCart 1.7+). */
    const CORE_FILTER = 'fluent_cart/review/json_ld';
    const CORE_CLASS  = '\\FluentCart\\App\\Services\\Schema\\ProductSchema';

    /** Offer terms FluentCart owns: it knows about bundles and the stock module. */
    const CORE_OFFER_KEYS = ['price', 'priceCurrency', 'availability'];

    /** Logical name => post meta key. Filter: upfluent_cmf_schema_meta_keys */
    const DEFAULT_META_KEYS = [
        'subtitle'  => 'subtitle',
        'isbn13'    => 'spec_isbn13',
        'isbn10'    => 'spec_isbn10',
        'pages'     => 'spec_pages',
        'format'    => 'spec_format',
        'authors'   => 'spec_authors',
        'publisher' => 'spec_publisher',
        'pub_date'  => 'spec_pub_date',
    ];

    const GALLERY_META = 'fluent-products-gallery-image';
    const CATEGORY_TAX = 'product-categories';

    /** Upper bound on emitted offers, so a pathological product cannot bloat the page. */
    const MAX_OFFERS = 20;

    /** Upper bound on distinct shipping rate / destination pairs. */
    const MAX_SHIPPING_DETAILS = 10;

    /** Set once the nodes are on the page; gates the foreign-schema cleanup. */
    private $emitted = false;

    /** Product node waiting to be merged into FluentCart's: ['post_id' => int, 'node' => array]. */
    private $pending = null;

    /** True once FluentCart's node carried our data onto the page. */
    private $merged = false;


    public function register(): void
    {
        add_action('wp_head', [$this, 'render'], 5);
        add_action('template_redirect', [$this, 'watchOutput']);
        add_filter('rank_math/opengraph/type', [$this, 'openGraphType']);
    }

    // ─── Emission ───

    public function render(): void
    {
        if (!$this->isProductPage()) {
            return;
        }

        $post_id = (int) get_queried_object_id();
        if (!$post_id || post_password_required($post_id) || !apply_filters('upfluent_cmf_schema_enabled', true, $post_id)) {
            return;
        }

        $graph = $this->build($post_id);
        if (!$graph) {
            return;
        }

        if ($this->mergesIntoCore($post_id)) {
            $this->pending = ['post_id' => $post_id, 'node' => $graph['product']];
            unset($graph['product']);
            add_filter(self::CORE_FILTER, [$this, 'mergeIntoCoreProduct'], 20, 2);
            add_action('wp_head', [$this, 'renderFallbackProduct'], 99);
        }

        foreach ($graph as $key => $node) {
            $this->emit($node, $key);
        }

        $this->emitted = true;
    }

    /** FluentCart 1.7+ prints its own Product node; merge into it rather than add a second one. */
    private function mergesIntoCore(int $post_id): bool
    {
        return class_exists(self::CORE_CLASS) && apply_filters('upfluent_cmf_schema_merge_core', true, $post_id);
    }

    /**
     * fluent_cart/review/json_ld: FluentCart's node plus our data. Our node is
     * the base; FluentCart's price, stock and reviews override it, and any key
     * only FluentCart has is kept.
     */
    public function mergeIntoCoreProduct($core, $args = [])
    {
        $post_id = (int) (is_array($args) ? ($args['post_id'] ?? 0) : 0);
        if (!is_array($core) || !$core || !$this->pending || $post_id !== $this->pending['post_id']) {
            return $core;
        }

        $merged = $this->pending['node'];
        foreach ($core as $key => $value) {
            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }
        // Rating and review list come from the same FluentCart source.
        if (isset($core['aggregateRating'])) {
            $merged['aggregateRating'] = $core['aggregateRating'];
        }

        $core_node   = is_array($core['offers'] ?? null) ? $core['offers'] : [];
        $core_offers = ($core_node['@type'] ?? '') === 'AggregateOffer'
            ? array_values((array) ($core_node['offers'] ?? []))
            : ($core_node ? [$core_node] : []);

        if ($core_offers && !empty($merged['hasVariant'])) {
            foreach ($merged['hasVariant'] as $i => $variant) {
                if (isset($variant['offers'])) {
                    $merged['hasVariant'][$i]['offers'] = $this->withCoreTerms($variant['offers'], $core_offers, (string) ($variant['sku'] ?? ''), $i);
                }
            }
        } elseif ($core_offers && isset($merged['offers'])) {
            if (isset($merged['offers']['@type'])) {
                $merged['offers'] = $this->withCoreTerms($merged['offers'], $core_offers, (string) ($merged['offers']['sku'] ?? ''), 0);
            } else {
                $list = [];
                foreach (array_values($merged['offers']) as $i => $offer) {
                    $list[] = $this->withCoreTerms($offer, $core_offers, (string) ($offer['sku'] ?? ''), $i);
                }
                // Keep FluentCart's AggregateOffer wrapper (price range, count) around our richer offers.
                if (($core_node['@type'] ?? '') === 'AggregateOffer') {
                    $core_node['offers'] = $list;
                    $list = $core_node;
                }
                $merged['offers'] = $list;
            }
        }

        $this->merged = true;

        return apply_filters('upfluent_cmf_merged_product_schema', $merged, $core, $post_id);
    }

    /** Copies FluentCart's price / stock onto one of our offers, matched by SKU, then name, then position. */
    private function withCoreTerms(array $offer, array $core_offers, string $sku, int $index): array
    {
        $match = null;
        foreach ($core_offers as $candidate) {
            if ($sku !== '' && ($candidate['sku'] ?? '') === $sku) {
                $match = $candidate;
                break;
            }
        }
        if (!$match && !empty($offer['name'])) {
            foreach ($core_offers as $candidate) {
                if (($candidate['name'] ?? '') === $offer['name']) {
                    $match = $candidate;
                    break;
                }
            }
        }
        if (!$match) {
            $match = $core_offers[$index] ?? null;
        }
        if (!is_array($match)) {
            return $offer;
        }
        foreach (self::CORE_OFFER_KEYS as $key) {
            if (isset($match[$key])) {
                $offer[$key] = $match[$key];
            }
        }
        return $offer;
    }

    /** FluentCart never printed its node (removed, or it had nothing to say): print ours. */
    public function renderFallbackProduct(): void
    {
        if ($this->merged || !$this->pending) {
            return;
        }
        $this->emit($this->pending['node'], 'product');
        $this->pending = null;
    }

    /**
     * @return array|null  product / breadcrumb / organization, or null when the
     *                     product has nothing purchasable to describe
     */
    public function build(int $post_id): ?array
    {
        $variations = $this->variations($post_id);
        if (!$variations) {
            // A Product node without offers is worse than no Product node.
            return null;
        }

        $url      = (string) get_permalink($post_id);
        $home     = home_url('/');
        $store    = $this->storeSettings();
        $currency = !empty($store['currency']) ? strtoupper((string) $store['currency']) : 'USD';
        $org_id   = $home . '#organization';

        $product      = $this->buildProduct($post_id, $url, $variations, $currency, $org_id, $store);
        $breadcrumb   = $this->buildBreadcrumb($post_id, $url, $home, $store);
        $organization = $this->buildOrganization($org_id, $home, $store);

        return [
            'product'      => apply_filters('upfluent_cmf_product_schema', $product, $post_id),
            'breadcrumb'   => apply_filters('upfluent_cmf_breadcrumb_schema', $breadcrumb, $post_id),
            'organization' => apply_filters('upfluent_cmf_organization_schema', $organization, $post_id),
        ];
    }

    // ─── Product ───

    private function buildProduct(int $post_id, string $url, array $variations, string $currency, string $org_id, array $store): array
    {
        $types = ['Product'];
        if ($this->looksLikeBook($post_id)) {
            $types[] = 'Book';
        }

        $product = [
            '@context'    => 'https://schema.org',
            '@type'       => apply_filters('upfluent_cmf_product_schema_types', $types, $post_id),
            '@id'         => $url . '#product',
            'name'        => $this->plainText((string) get_the_title($post_id)),
            'url'         => $url,
            'description' => $this->description($post_id),
            'brand'       => ['@type' => 'Brand', 'name' => $this->productBrand($post_id)],
            'inLanguage'  => get_bloginfo('language'),
            'offers'      => $this->buildOffers($variations, $currency, $url, $org_id, $store, $post_id),
        ];

        $images = $this->images($post_id);
        if ($images) {
            $product['image'] = $images;
        }

        $primary = $variations[0];
        if (!empty($primary->sku)) {
            $product['sku'] = (string) $primary->sku;
        }

        $this->addBookProperties($product, $post_id);

        $rating = $this->aggregateRating($post_id);
        if ($rating) {
            $product['aggregateRating'] = $rating;
        }

        return $this->applyIdentifiers($product, $post_id, $variations);
    }

    /**
     * Brand set on the product's identifiers, else its first FluentCart brand
     * (the product-brands taxonomy, which FluentCart 1.7.1+ also prints), else
     * the store-wide brand.
     */
    private function productBrand(int $post_id): string
    {
        $brand = class_exists('\\UpfluentCmf\\ProductIdentifiers') ? ProductIdentifiers::stored($post_id)['brand'] : '';
        if ($brand !== '') {
            return $brand;
        }
        $terms = get_the_terms($post_id, 'product-brands');
        if ($terms && !is_wp_error($terms)) {
            $name = trim(wp_strip_all_tags($terms[0]->name));
            if ($name !== '') {
                return $name;
            }
        }
        return self::brandName();
    }

    /**
     * Per-variation GTIN / MPN. One variation: the codes go on the Product.
     * Several with at least one GTIN: the node becomes a ProductGroup whose
     * hasVariant lists one Product (with its own Offer) per variation, which
     * is the shape Google's Merchant listings expect for variants. Books keep
     * their single Product node.
     */
    private function applyIdentifiers(array $product, int $post_id, array $variations): array
    {
        if (!class_exists('\\UpfluentCmf\\ProductIdentifiers')) {
            return $product;
        }
        $variations = array_slice($variations, 0, self::MAX_OFFERS);
        $codes      = [];
        foreach ($variations as $v) {
            $codes[(int) $v->id] = ProductIdentifiers::forVariation($post_id, (int) $v->id);
        }

        if (count($variations) === 1) {
            $ids = reset($codes);
            if ($ids['gtin'] !== '') {
                unset($product['gtin13']);
                $product[ProductIdentifiers::schemaProperty($ids['gtin'])] = $ids['gtin'];
                // A book's 978/979 GTIN-13 is its ISBN.
                if (empty($product['isbn']) && in_array('Book', (array) $product['@type'], true) && preg_match('/^97[89]\d{10}$/', $ids['gtin'])) {
                    $product['isbn'] = $ids['gtin'];
                }
            }
            if ($ids['mpn'] !== '') {
                $product['mpn'] = $ids['mpn'];
            }
            return $product;
        }

        $has_gtin = (bool) array_filter($codes, function ($ids) {
            return $ids['gtin'] !== '';
        });
        $types = (array) $product['@type'];
        if (!$has_gtin || in_array('Book', $types, true) || !apply_filters('upfluent_cmf_schema_product_group', true, $post_id)) {
            return $product;
        }

        $offers = isset($product['offers']['@type']) ? [$product['offers']] : (array) $product['offers'];
        $group  = $product;
        unset($group['offers'], $group['sku']);
        $group['@type']          = 'ProductGroup';
        $group['productGroupID'] = !empty($product['sku']) ? (string) $product['sku'] : (string) $post_id;
        $group['hasVariant']     = [];

        foreach ($variations as $i => $v) {
            $offer = $offers[$i] ?? null;
            if (!$offer) {
                continue;
            }
            // The variant carries name, SKU and codes; its Offer keeps only the price terms.
            unset($offer['name'], $offer['sku'], $offer['mpn'], $offer['gtin8'], $offer['gtin12'], $offer['gtin13'], $offer['gtin14']);
            $variant = [
                '@type'  => 'Product',
                'name'   => trim($product['name'] . ' – ' . $this->plainText((string) $v->variation_title), ' –'),
                'brand'  => $product['brand'],
                'offers' => $offer,
            ];
            if (!empty($v->sku)) {
                $variant['sku'] = (string) $v->sku;
            }
            if (!empty($product['image'])) {
                $variant['image'] = $product['image'];
            }
            $ids = $codes[(int) $v->id];
            if ($ids['gtin'] !== '') {
                $variant[ProductIdentifiers::schemaProperty($ids['gtin'])] = $ids['gtin'];
            }
            if ($ids['mpn'] !== '') {
                $variant['mpn'] = $ids['mpn'];
            }
            $group['hasVariant'][] = $variant;
        }

        return $group;
    }

    /**
     * One Offer per purchasable variation. A single variation stays a plain
     * Offer object; several become a list, which keeps each price, SKU and
     * availability attached to the variation it belongs to.
     */
    private function buildOffers(array $variations, string $currency, string $url, string $org_id, array $store, int $post_id = 0): array
    {
        $valid_until = gmdate('Y-m-d', strtotime('+1 year'));
        $offers      = [];

        // Shared across every offer, so resolve once.
        $return_policy = $this->returnPolicy($store);
        $shipping      = $this->shippingDetails($currency, $store);

        foreach (array_slice($variations, 0, self::MAX_OFFERS) as $variation) {
            $offer = [
                '@type'           => 'Offer',
                'url'             => $url,
                'priceCurrency'   => $currency,
                'price'           => $this->price($variation->item_price, $currency),
                'priceValidUntil' => $valid_until,
                'availability'    => $this->inStock($variation) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition'   => 'https://schema.org/NewCondition',
                'seller'          => ['@id' => $org_id],
            ];

            if ($return_policy) {
                $offer['hasMerchantReturnPolicy'] = $return_policy;
            }
            if ($shipping) {
                $offer['shippingDetails'] = count($shipping) === 1 ? $shipping[0] : $shipping;
            }

            if (!empty($variation->sku)) {
                $offer['sku'] = (string) $variation->sku;
            }
            if (count($variations) > 1 && !empty($variation->variation_title)) {
                $offer['name'] = (string) $variation->variation_title;
            }
            if ($post_id && class_exists('\\UpfluentCmf\\ProductIdentifiers')) {
                $ids = ProductIdentifiers::forVariation($post_id, (int) $variation->id);
                if ($ids['gtin'] !== '') {
                    $offer[ProductIdentifiers::schemaProperty($ids['gtin'])] = $ids['gtin'];
                }
                if ($ids['mpn'] !== '') {
                    $offer['mpn'] = $ids['mpn'];
                }
            }

            $offers[] = $offer;
        }

        return count($offers) === 1 ? $offers[0] : $offers;
    }

    /** Book identifiers and details, added only when present. */
    private function addBookProperties(array &$product, int $post_id): void
    {
        $isbn13 = $this->meta($post_id, 'isbn13');
        $isbn10 = $this->meta($post_id, 'isbn10');

        if ($isbn13 !== '') {
            $product['isbn'] = $isbn13;
            $digits = preg_replace('/\D/', '', $isbn13);
            if (strlen((string) $digits) === 13) {
                // Without a GTIN, Merchant listings stay ineligible.
                $product['gtin13'] = $digits;
            }
        } elseif ($isbn10 !== '') {
            $product['isbn'] = $isbn10;
        }

        $pages = $this->meta($post_id, 'pages');
        if ($pages !== '' && (int) $pages > 0) {
            $product['numberOfPages'] = (int) $pages;
        }

        $format = $this->meta($post_id, 'format');
        if ($format !== '') {
            $product['bookFormat'] = $this->bookFormat($format);
        }

        $authors = $this->meta($post_id, 'authors');
        if ($authors !== '') {
            $names = $this->authorNames($authors);
            if ($names) {
                $product['author'] = array_map(function ($name) {
                    return ['@type' => 'Person', 'name' => $name];
                }, $names);
            }
        }

        $publisher = $this->meta($post_id, 'publisher');
        if ($publisher !== '') {
            $product['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }

        $pub_date = $this->meta($post_id, 'pub_date');
        if ($pub_date !== '') {
            $timestamp = strtotime($pub_date);
            if ($timestamp) {
                $product['datePublished'] = gmdate('Y-m-d', $timestamp);
            }
        }
    }

    /** True when the product carries any book-specific data. */
    private function looksLikeBook(int $post_id): bool
    {
        foreach (['isbn13', 'isbn10', 'pages', 'authors', 'publisher', 'pub_date', 'format'] as $key) {
            if ($this->meta($post_id, $key) !== '') {
                return true;
            }
        }

        return false;
    }

    private function bookFormat(string $format): string
    {
        $format = strtolower($format);

        if (strpos($format, 'hard') !== false) {
            return 'https://schema.org/Hardcover';
        }
        if (strpos($format, 'audio') !== false) {
            return 'https://schema.org/AudiobookFormat';
        }
        if (strpos($format, 'graphic') !== false || strpos($format, 'comic') !== false) {
            return 'https://schema.org/GraphicNovel';
        }
        if (strpos($format, 'ebook') !== false || strpos($format, 'e-book') !== false
            || strpos($format, 'kindle') !== false || strpos($format, 'digital') !== false) {
            return 'https://schema.org/EBook';
        }

        return 'https://schema.org/Paperback';
    }

    // ─── Breadcrumb and organization ───

    private function buildBreadcrumb(int $post_id, string $url, string $home, array $store): array
    {
        $crumbs = [
            [__('Home', 'custom-meta-fields-for-fluentcart'), $home],
            [__('Shop', 'custom-meta-fields-for-fluentcart'), $this->shopUrl($store, $home)],
        ];

        $terms = get_the_terms($post_id, self::CATEGORY_TAX);
        if ($terms && !is_wp_error($terms)) {
            $term = reset($terms);
            $link = get_term_link($term);
            if (!is_wp_error($link)) {
                $crumbs[] = [$term->name, $link];
            }
        }

        $crumbs[] = [get_the_title($post_id), $url];

        $items = [];
        foreach ($crumbs as $index => $crumb) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $index + 1,
                'name'     => $crumb[0],
                'item'     => $crumb[1],
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    private function buildOrganization(string $org_id, string $home, array $store): array
    {
        $organization = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => $org_id,
            'name'     => self::brandName(),
            'url'      => $home,
        ];

        if (!empty($store['store_address1'])) {
            $organization['address'] = array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => (string) $store['store_address1'],
                'addressLocality' => (string) ($store['store_city'] ?? ''),
                'addressRegion'   => (string) ($store['store_state'] ?? ''),
                'postalCode'      => (string) ($store['store_zip'] ?? ''),
                'addressCountry'  => (string) ($store['store_country'] ?? ''),
            ], 'strlen');
        }

        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id) {
            $src = wp_get_attachment_image_url($logo_id, 'full');
            if ($src) {
                $organization['logo'] = $src;
            }
        }

        return $organization;
    }

    /** FluentCart knows its own shop page; fall back to the conventional path. */
    private function shopUrl(array $store, string $home): string
    {
        $page_id = (int) ($store['shop_page_id'] ?? 0);
        if ($page_id) {
            $permalink = get_permalink($page_id);
            if ($permalink) {
                return apply_filters('upfluent_cmf_shop_url', $permalink);
            }
        }

        return apply_filters('upfluent_cmf_shop_url', $home . 'shop/');
    }

    // ─── Data ───

    /** Variations in display order. Empty means nothing purchasable. */
    private function variations(int $post_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, item_price, sku, stock_status, variation_title
               FROM {$wpdb->prefix}fct_product_variations
              WHERE post_id = %d
              ORDER BY serial_index ASC, id ASC",
            $post_id
        ));

        return is_array($rows) ? $rows : [];
    }

    private function inStock($variation): bool
    {
        $status = strtolower((string) ($variation->stock_status ?? ''));

        // FluentCart writes the hyphenated form; the Status helper uses the
        // unhyphenated one. Treat either as out of stock.
        return !in_array($status, ['out-of-stock', 'outofstock'], true);
    }

    /**
     * FluentCart stores money in cents, including in the double-typed variation
     * columns. Its own helper also knows which currencies have no minor unit.
     */
    private function price($cents, string $currency): string
    {
        $helper = '\FluentCart\App\Helpers\CurrenciesHelper';

        if (class_exists($helper) && method_exists($helper, 'centsToDecimal')) {
            $value = call_user_func([$helper, 'centsToDecimal'], $cents, $currency);
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
        }

        return number_format(((float) $cents) / 100, 2, '.', '');
    }

    /** Merged store settings, via FluentCart's helper when it is loaded. */
    private function storeSettings(): array
    {
        $helper = '\FluentCart\App\Helpers\Helper';

        if (class_exists($helper) && method_exists($helper, 'shopConfig')) {
            $config = call_user_func([$helper, 'shopConfig']);
            if (is_array($config) && $config) {
                return $config;
            }
        }

        $option = get_option('fluent_cart_store_settings', []);

        return is_array($option) ? $option : [];
    }

    /** Featured image first, then the gallery, de-duplicated. */
    private function images(int $post_id): array
    {
        $images = [];

        if (has_post_thumbnail($post_id)) {
            $images[] = get_the_post_thumbnail_url($post_id, 'full');
        }

        $gallery = get_post_meta($post_id, self::GALLERY_META, true);
        if (is_array($gallery)) {
            foreach ($gallery as $item) {
                if (is_array($item) && !empty($item['url'])) {
                    $images[] = (string) $item['url'];
                } elseif (is_numeric($item)) {
                    $url = wp_get_attachment_image_url((int) $item, 'full');
                    if ($url) {
                        $images[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($images)));
    }

    /**
     * Subtitle plus the excerpt.
     *
     * A hand-written excerpt is preferred over a generated one. get_the_excerpt()
     * is deliberately not used: it runs the excerpt filters, and other plugins
     * append to those, which is how a reviews prompt ends up inside the product
     * description.
     */
    private function description(int $post_id): string
    {
        $post     = get_post($post_id);
        $subtitle = $this->meta($post_id, 'subtitle');

        $excerpt = ($post && trim((string) $post->post_excerpt) !== '')
            ? (string) $post->post_excerpt
            : $this->excerptFromContent($post);

        return $this->plainText(trim($subtitle . ' ' . $excerpt));
    }

    /** @param \WP_Post|null $post */
    private function excerptFromContent($post): string
    {
        if (!$post) {
            return '';
        }

        $content = (string) $post->post_content;

        if (function_exists('excerpt_remove_blocks')) {
            $content = excerpt_remove_blocks($content);
        }
        $content = strip_shortcodes($content);

        return wp_trim_words(wp_strip_all_tags($content), 40);
    }

    /** Tags out, entities decoded, whitespace collapsed. */
    private function plainText(string $text): string
    {
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    /**
     * Splits an author string into full names.
     *
     * "Aaron & Jennifer Smith" is two people sharing a surname, not someone
     * called "Aaron". A fragment with no surname of its own inherits the surname
     * of the next fragment that has one.
     */
    private function authorNames(string $raw): array
    {
        $parts = preg_split('/\s*(?:&|,|\+|\band\b)\s*/i', $raw);
        $parts = array_values(array_filter(array_map('trim', (array) $parts), 'strlen'));

        $surname = '';
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $words = preg_split('/\s+/', $parts[$i]);

            if (is_array($words) && count($words) > 1) {
                // Everything after the given name, so "van der Berg" survives.
                $surname = implode(' ', array_slice($words, 1));
            } elseif ($surname !== '') {
                $parts[$i] .= ' ' . $surname;
            }
        }

        $parts = array_map([$this, 'plainText'], $parts);

        return array_values(array_filter(apply_filters('upfluent_cmf_author_names', $parts, $raw), 'strlen'));
    }

    /**
     * The public-facing brand. The site title is often styled for a logo, in
     * caps or with punctuation, so it is only the fallback.
     */
    public static function brandName(): string
    {
        $name = defined('FCT_CMF_BRAND_NAME') ? trim((string) FCT_CMF_BRAND_NAME) : '';

        if ($name === '') {
            $name = (string) get_bloginfo('name');
        }

        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) apply_filters('upfluent_cmf_brand_name', trim($name));
    }

    /** @return array|null AggregateRating node */
    private function aggregateRating(int $post_id): ?array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpsr_reviews';
        if (!$this->tableExists($table)) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT COUNT(*) AS cnt, AVG(rating) AS avg_rating
               FROM {$wpdb->prefix}wpsr_reviews
              WHERE platform_name   = 'fluent-cart'
                AND source_id       = %s
                AND review_approved = 1
                AND rating > 0",
            (string) $post_id
        ));

        if (!$row || (int) $row->cnt < 1) {
            return null;
        }

        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => number_format((float) $row->avg_rating, 1, '.', ''),
            'reviewCount' => (int) $row->cnt,
            'bestRating'  => 5,
            'worstRating' => 1,
        ];
    }

    private function tableExists(string $table): bool
    {
        static $checked = [];

        if (!isset($checked[$table])) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $checked[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        }

        return $checked[$table];
    }

    /**
     * Return policy attached to every offer. Google leans on this for Merchant
     * listings, and misstating it is worse than omitting it.
     *
     * The customer pays return shipping by default. Override the window with
     * FCT_CMF_RETURN_DAYS and the fee basis with FCT_CMF_RETURN_FEES. When the
     * customer pays a known flat amount, set FCT_CMF_RETURN_FEE_AMOUNT and it is
     * declared too, which is what Google asks for alongside ReturnShippingFees.
     * Return anything falsy from upfluent_cmf_return_policy to omit the node.
     */
    private function returnPolicy(array $store): ?array
    {
        $days    = defined('FCT_CMF_RETURN_DAYS') ? (int) FCT_CMF_RETURN_DAYS : 30;
        $country = !empty($store['store_country']) ? (string) $store['store_country'] : 'US';
        $fees    = defined('FCT_CMF_RETURN_FEES')
            ? (string) FCT_CMF_RETURN_FEES
            : 'https://schema.org/ReturnShippingFees';

        $policy = [
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => $country,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => max(0, $days),
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => $fees,
        ];

        if ($fees === 'https://schema.org/ReturnShippingFees' && defined('FCT_CMF_RETURN_FEE_AMOUNT')) {
            $policy['returnShippingFeesAmount'] = [
                '@type'    => 'MonetaryAmount',
                'value'    => number_format((float) FCT_CMF_RETURN_FEE_AMOUNT, 2, '.', ''),
                'currency' => !empty($store['currency']) ? strtoupper((string) $store['currency']) : 'USD',
            ];
        }

        $policy = apply_filters('upfluent_cmf_return_policy', $policy, $store);

        return (is_array($policy) && $policy) ? $policy : null;
    }

    /**
     * Shipping rates read from FluentCart's zones and methods.
     *
     * Only rates that are a fixed amount per order can be stated as a single
     * figure, so free shipping and per-order flat rates are described and
     * percentage, per-item, per-price and per-weight methods are skipped rather
     * than guessed at.
     *
     * Note the unit: fct_shipping_methods.amount is in currency units, unlike
     * product prices, which are in cents.
     */
    private function shippingDetails(string $currency, array $store): array
    {
        global $wpdb;

        $zones   = $wpdb->prefix . 'fct_shipping_zones';
        $methods = $wpdb->prefix . 'fct_shipping_methods';

        if (!$this->tableExists($zones) || !$this->tableExists($methods)) {
            return (array) apply_filters('upfluent_cmf_shipping_details', [], $currency, $store);
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are $wpdb->prefix constants
        $rows = $wpdb->get_results(
            "SELECT m.type, m.amount, m.settings, z.region, z.meta AS zone_meta
               FROM {$methods} m
         INNER JOIN {$zones} z ON z.id = m.zone_id
              WHERE m.is_enabled = 1
           ORDER BY z.`order` ASC, m.`order` ASC"
        );
        // phpcs:enable

        $details = [];
        $seen    = [];

        foreach ((array) $rows as $row) {
            $rate = $this->fixedShippingRate($row);
            if ($rate === null) {
                continue;
            }

            $countries = $this->zoneCountries($row, $store);
            if (!$countries) {
                continue;
            }

            $key = $rate . '|' . implode(',', $countries);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $details[] = [
                '@type'               => 'OfferShippingDetails',
                'shippingRate'        => [
                    '@type'    => 'MonetaryAmount',
                    'value'    => $rate,
                    'currency' => $currency,
                ],
                'shippingDestination' => [
                    '@type'          => 'DefinedRegion',
                    'addressCountry' => count($countries) === 1 ? $countries[0] : $countries,
                ],
            ];

            if (count($details) >= self::MAX_SHIPPING_DETAILS) {
                break;
            }
        }

        return (array) apply_filters('upfluent_cmf_shipping_details', $details, $currency, $store);
    }

    /**
     * @return string|null  the rate as a decimal string, or null when the method
     *                      cannot be expressed as one fixed figure
     */
    private function fixedShippingRate($row): ?string
    {
        $type = (string) ($row->type ?? '');

        if ($type === 'free_shipping') {
            return '0.00';
        }
        if ($type === 'percentage') {
            return null;
        }

        $settings = json_decode((string) ($row->settings ?? ''), true);
        $settings = is_array($settings) ? $settings : [];

        // Anything but per_order depends on the cart contents.
        if (($settings['configure_rate'] ?? 'per_order') !== 'per_order') {
            return null;
        }

        return number_format((float) ($row->amount ?? 0), 2, '.', '');
    }

    /** Country codes a zone covers. */
    private function zoneCountries($row, array $store): array
    {
        $region   = (string) ($row->region ?? '');
        $fallback = !empty($store['store_country']) ? [(string) $store['store_country']] : [];

        if ($region === '' || $region === 'all') {
            // No schema value means "everywhere", so state the home market.
            return $fallback;
        }

        if ($region === 'selection') {
            $meta = json_decode((string) ($row->zone_meta ?? ''), true);
            $meta = is_array($meta) ? $meta : [];

            if (($meta['selection_type'] ?? 'included') !== 'included') {
                return $fallback;
            }

            $countries = array_values(array_filter(array_map('strval', (array) ($meta['countries'] ?? []))));

            return $countries ?: $fallback;
        }

        return [$region];
    }

    /** @return array<string,string> logical name => meta key */
    private function metaKeys(): array
    {
        $keys = apply_filters('upfluent_cmf_schema_meta_keys', self::DEFAULT_META_KEYS);

        return is_array($keys) ? $keys : self::DEFAULT_META_KEYS;
    }

    /**
     * Reads a mapped meta value as a string. Arrays, which is what this
     * plugin's own repeater fields store, are flattened to a comma separated
     * list so they can still feed the schema.
     */
    private function meta(int $post_id, string $logical_key): string
    {
        $keys = $this->metaKeys();
        if (empty($keys[$logical_key])) {
            return '';
        }

        $value = get_post_meta($post_id, $keys[$logical_key], true);

        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($item) use (&$flat) {
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $flat[] = trim((string) $item);
                }
            });
            $value = implode(', ', $flat);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    // ─── Foreign schema cleanup ───

    /**
     * WP Social Ninja emits its own Product node on these pages with the site
     * name, empty image and description, and no offers. Two Product nodes on one
     * page makes Google pick arbitrarily, so the incomplete one is dropped.
     *
     * Uses WordPress's template enhancement output buffer (WordPress 6.9+),
     * which WordPress opens and closes itself; this plugin opens no output
     * buffer of its own. On older WordPress versions the cleanup is skipped.
     */
    public function watchOutput(): void
    {
        if (!function_exists('wp_start_template_enhancement_output_buffer') || !$this->isProductPage()) {
            return;
        }
        if (is_feed() || is_embed() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        if (!apply_filters('upfluent_cmf_schema_strip_foreign', true)) {
            return;
        }

        // Added only on product pages, so WordPress buffers nothing elsewhere on our account.
        add_filter('wp_template_enhancement_output_buffer', [$this, 'stripForeignProductSchema']);
    }

    /** wp_template_enhancement_output_buffer callback. Any failure returns the page untouched. */
    public function stripForeignProductSchema($html)
    {
        if (!is_string($html) || !$this->emitted) {
            return $html;
        }
        if (stripos($html, 'application/ld+json') === false) {
            return $html;
        }

        $filtered = preg_replace_callback(
            '#<script\b[^>]*type=(["\'])application/ld\+json\1[^>]*>(.*?)</script\s*>#is',
            function ($matches) {
                $tag  = $matches[0];
                $json = $matches[2];

                // Never touch our own nodes.
                if (stripos($tag, self::SCRIPT_ID_PREFIX) !== false) {
                    return $tag;
                }

                $is_product = stripos($json, '"Product"') !== false;
                $has_offers = stripos($json, '"offers"') !== false;

                return ($is_product && !$has_offers) ? '' : $tag;
            },
            $html
        );

        // preg_replace_callback returns null on backtrack limits or bad input.
        return is_string($filtered) ? $filtered : $html;
    }

    // ─── Misc ───

    /** Rank Math otherwise reports article, because no rich snippet type is set. */
    public function openGraphType($type)
    {
        return $this->isProductPage() ? 'product' : $type;
    }

    private function isProductPage(): bool
    {
        return is_singular(ProductValuesService::PRODUCT_CPT);
    }

    /**
     * JSON-LD is pre-escaped here: the angle brackets and ampersand are encoded
     * so no value can close the script element early.
     */
    private function emit(array $node, string $key): void
    {
        // HEX_TAG and HEX_AMP encode < > and & at the JSON level, so no value
        // can close the script element early. Slashes stay readable in URLs.
        $json = wp_json_encode(
            $node,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
        if (!$json) {
            return;
        }


        echo "\n" . '<script type="application/ld+json" id="' . esc_attr(self::SCRIPT_ID_PREFIX . $key) . '">'
            . $json // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- encoded above
            . '</script>' . "\n";
    }
}
