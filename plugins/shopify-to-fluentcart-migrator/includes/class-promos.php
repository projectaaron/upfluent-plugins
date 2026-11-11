<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * The sidebar cards for upfluent.io's other FluentCart tools. This plugin is
 * free; these are how it pays for itself. Filter `s2fc_promos` to change them.
 */
class Promos
{
    public static function items(): array
    {
        $items = [
            [
                'id'     => 'meta-fields',
                'title'  => 'Custom Meta Fields for FluentCart',
                'tag'    => __('Free', 'shopify-to-fluentcart-migrator'),
                'text'   => __('Unlimited product fields, GTIN / ISBN / UPC per variation, and Google product structured data. Needed to keep your Shopify barcodes.', 'shopify-to-fluentcart-migrator'),
                'url'    => Gtin::PLUGIN_URL,
                'cta'    => __('Get it free', 'shopify-to-fluentcart-migrator'),
                'status' => 'available',
                'icon'   => 'tag',
            ],
            [
                'id'     => 'mcp',
                'title'  => 'All-In-One MCP for Fluent Suite',
                'tag'    => __('Free for a limited time', 'shopify-to-fluentcart-migrator'),
                'text'   => __('Let an AI assistant run your store, CRM, forms and community: 1,300 safe, confirm-gated tools for FluentCart, FluentCRM, Fluent Forms and FluentCommunity.', 'shopify-to-fluentcart-migrator'),
                'url'    => 'https://upfluent.io/all-in-one-mcp-for-fluent-suite/',
                'cta'    => __('Get the MCP', 'shopify-to-fluentcart-migrator'),
                'status' => 'available',
                'icon'   => 'sparkle',
            ],
            [
                'id'     => 'wholesale',
                'title'  => 'B2B & Wholesale Pricing for FluentCart',
                'text'   => __('Wholesale price lists, minimum quantities, net terms and customer groups.', 'shopify-to-fluentcart-migrator'),
                'url'    => 'https://upfluent.io/',
                'status' => 'soon',
                'icon'   => 'briefcase',
            ],
            [
                'id'     => 'gift-cards',
                'title'  => 'Gift Cards & Store Credit for FluentCart',
                'text'   => __('Sell gift cards, issue store credit for refunds, and let customers pay with a balance.', 'shopify-to-fluentcart-migrator'),
                'url'    => 'https://upfluent.io/',
                'status' => 'soon',
                'icon'   => 'gift',
            ],
            [
                'id'     => 'upsell',
                'title'  => 'Upsells & Cross-sells for FluentCart',
                'text'   => __('Post-purchase one-click upsells and cart cross-sells.', 'shopify-to-fluentcart-migrator'),
                'url'    => 'https://upfluent.io/',
                'status' => 'soon',
                'icon'   => 'trend',
            ],
            [
                'id'     => 'shipfusion',
                'title'  => 'ShipFusion for FluentCart',
                'text'   => __('Send orders to ShipFusion for fulfillment and sync tracking and stock back.', 'shopify-to-fluentcart-migrator'),
                'url'    => 'https://upfluent.io/',
                'status' => 'soon',
                'icon'   => 'truck',
            ],
        ];

        $items = apply_filters('s2fc_promos', $items);
        return is_array($items) ? $items : [];
    }

    /** Add the referral source to outbound links. */
    public static function link(string $url): string
    {
        if (strpos($url, 'upfluent.io') === false) {
            return $url;
        }
        return add_query_arg(['utm_source' => 'shopify-migrator', 'utm_medium' => 'plugin'], $url);
    }

    public static function render(): void
    {
        $items = self::items();
        if (!$items) {
            return;
        }
        $available = array_filter($items, function ($i) { return ($i['status'] ?? '') !== 'soon'; });
        $soon      = array_filter($items, function ($i) { return ($i['status'] ?? '') === 'soon'; });
        ?>
        <aside class="s2fc-promos">
            <?php if ($available) : ?>
                <h3 class="s2fc-promos__heading"><?php esc_html_e('More for your FluentCart store', 'shopify-to-fluentcart-migrator'); ?></h3>
                <?php foreach ($available as $item) : self::card($item); endforeach; ?>
            <?php endif; ?>
            <?php if ($soon) : ?>
                <h3 class="s2fc-promos__heading"><?php esc_html_e('Coming soon from upfluent.io', 'shopify-to-fluentcart-migrator'); ?></h3>
                <ul class="s2fc-soon">
                    <?php foreach ($soon as $item) : ?>
                        <li>
                            <span class="s2fc-icon s2fc-icon--<?php echo esc_attr($item['icon'] ?? 'dot'); ?>" aria-hidden="true"></span>
                            <span>
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span class="s2fc-soon__text"><?php echo esc_html($item['text']); ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="s2fc-btn s2fc-btn--ghost s2fc-btn--block" href="<?php echo esc_url(self::link('https://upfluent.io/')); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('Get notified at upfluent.io', 'shopify-to-fluentcart-migrator'); ?>
                </a>
            <?php endif; ?>
        </aside>
        <?php
    }

    private static function card(array $item): void
    {
        ?>
        <div class="s2fc-card s2fc-promo">
            <div class="s2fc-promo__head">
                <span class="s2fc-icon s2fc-icon--<?php echo esc_attr($item['icon'] ?? 'dot'); ?>" aria-hidden="true"></span>
                <strong><?php echo esc_html($item['title']); ?></strong>
                <?php if (!empty($item['tag'])) : ?>
                    <span class="s2fc-pill s2fc-pill--good"><?php echo esc_html($item['tag']); ?></span>
                <?php endif; ?>
            </div>
            <p><?php echo esc_html($item['text']); ?></p>
            <a class="s2fc-btn s2fc-btn--small" href="<?php echo esc_url(self::link($item['url'])); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($item['cta'] ?? __('Learn more', 'shopify-to-fluentcart-migrator')); ?>
            </a>
        </div>
        <?php
    }
}
