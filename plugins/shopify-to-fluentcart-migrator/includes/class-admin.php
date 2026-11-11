<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * One screen: FluentCart → Shopify Migrator. Three steps down the page
 * (export, upload, review & publish) with the promo cards beside them.
 */
class Admin
{
    const MENU_KEY = 's2fc_migrator';

    /** FluentCart's own submenu order, captured before we add to it. */
    private $fluentcart_order = [];

    public function register(): void
    {
        add_filter('fluent_cart/global_admin_menu_more_items', [$this, 'fluentcart_menu_item'], 10, 2);
        add_action('admin_menu', [$this, 'menu'], 99);
        add_action('admin_menu', [$this, 'restore_submenu_order'], PHP_INT_MAX);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_notices', [$this, 'notices']);
        add_filter('plugin_action_links_' . plugin_basename(S2FC_FILE), [$this, 'action_links']);
    }

    public function fluentcart_menu_item($items, $args = [])
    {
        if (!is_array($items)) {
            return $items;
        }
        $items[self::MENU_KEY] = [
            'label'      => __('Shopify Migrator', 'shopify-to-fluentcart-migrator'),
            'link'       => Helpers::admin_url(),
            'permission' => ['products/create'],
        ];
        return $items;
    }

    public function menu(): void
    {
        global $submenu;
        $parent = Helpers::fluentcart_ready() ? 'fluent-cart' : 'tools.php';
        $this->fluentcart_order = array_keys($submenu['fluent-cart'] ?? []);

        add_submenu_page(
            $parent,
            __('Shopify to FluentCart Migrator', 'shopify-to-fluentcart-migrator'),
            __('Shopify Migrator', 'shopify-to-fluentcart-migrator'),
            Helpers::menu_capability(),
            S2FC_PAGE,
            [$this, 'render']
        );
    }

    /** add_submenu_page() ksort()s FluentCart's menu; put its items back first. */
    public function restore_submenu_order(): void
    {
        global $submenu;
        if (!$this->fluentcart_order || empty($submenu['fluent-cart']) || !is_array($submenu['fluent-cart'])) {
            return;
        }
        $items  = $submenu['fluent-cart'];
        $sorted = [];
        foreach ($this->fluentcart_order as $key) {
            if (isset($items[$key])) {
                $sorted[$key] = $items[$key];
                unset($items[$key]);
            }
        }
        foreach ($items as $key => $item) {
            $sorted[$key] = $item;
        }
        $submenu['fluent-cart'] = $sorted; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring FluentCart's own order after add_submenu_page() sorted it.
    }

    public function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(Helpers::admin_url()) . '">' . esc_html__('Start migration', 'shopify-to-fluentcart-migrator') . '</a>');
        return $links;
    }

    /** Only on the Plugins screen: the migrator's own page explains itself. */
    public function notices(): void
    {
        if (Helpers::fluentcart_ready() || !current_user_can('activate_plugins')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . wp_kses_post(sprintf(
            /* translators: %s: link to FluentCart */
            __('<strong>Shopify to FluentCart Migrator</strong> needs %s installed and active before it can import anything.', 'shopify-to-fluentcart-migrator'),
            '<a href="https://fluentcart.com/?by=272" target="_blank" rel="noopener">FluentCart</a>'
        )) . '</p></div>';
    }

    public function assets(string $hook): void
    {
        if (strpos($hook, S2FC_PAGE) === false) {
            return;
        }
        // Version plus file time: a page cache or CDN never serves stale assets after an update.
        $css_ver = S2FC_VERSION . '.' . (string) @filemtime(S2FC_DIR . 'assets/admin.css');
        $js_ver  = S2FC_VERSION . '.' . (string) @filemtime(S2FC_DIR . 'assets/admin.js');
        wp_enqueue_style('s2fc-admin', S2FC_URL . 'assets/admin.css', [], $css_ver);
        wp_enqueue_script('s2fc-admin', S2FC_URL . 'assets/admin.js', [], $js_ver, true);
        wp_localize_script('s2fc-admin', 's2fcData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('s2fc_import'),
            'i18n'    => [
                'importing'   => __('Importing…', 'shopify-to-fluentcart-migrator'),
                'uploading'   => __('Uploading and reading the file…', 'shopify-to-fluentcart-migrator'),
                'imported'    => __('Imported', 'shopify-to-fluentcart-migrator'),
                'skipped'     => __('Skipped', 'shopify-to-fluentcart-migrator'),
                'failed'      => __('Failed', 'shopify-to-fluentcart-migrator'),
                'edit'        => __('Edit', 'shopify-to-fluentcart-migrator'),
                'view'        => __('View', 'shopify-to-fluentcart-migrator'),
                /* translators: 1: imported count, 2: skipped count, 3: failed count */
                'done'        => __('Done. %1$d imported, %2$d skipped, %3$d failed.', 'shopify-to-fluentcart-migrator'),
                /* translators: 1: products done so far, 2: total selected */
                'progress'    => __('%1$d of %2$d', 'shopify-to-fluentcart-migrator'),
                'none'        => __('Tick at least one product to import.', 'shopify-to-fluentcart-migrator'),
                /* translators: %d: number of selected products */
                'confirm'     => __('Import %d products into FluentCart now?', 'shopify-to-fluentcart-migrator'),
                /* translators: %d: number of selected products */
                'selected'    => __('%d selected', 'shopify-to-fluentcart-migrator'),
                'leave'       => __('An import is running. Leave anyway?', 'shopify-to-fluentcart-migrator'),
                'stopped'     => __('Stopped. Products already imported stay in FluentCart.', 'shopify-to-fluentcart-migrator'),
                'network'     => __('Network error; retrying…', 'shopify-to-fluentcart-migrator'),
                'expired'     => __('Your session has expired. Reload the page and import again; products already imported are skipped.', 'shopify-to-fluentcart-migrator'),
                /* translators: %d: HTTP status code */
                'serverError' => __('The server returned an error (HTTP %d). Check the PHP error log.', 'shopify-to-fluentcart-migrator'),
                'openProducts'=> __('Open FluentCart products', 'shopify-to-fluentcart-migrator'),
            ],
            'productsUrl' => admin_url('admin.php?page=fluent-cart#/products'),
        ]);
    }

    // ─── Page ───

    public function render(): void
    {
        if (!Helpers::user_can_migrate()) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('You are not allowed to run the migrator.', 'shopify-to-fluentcart-migrator') . '</p></div></div>';
            return;
        }

        $ready    = Helpers::fluentcart_ready();
        $session  = Session::get();
        $active   = Session::active();
        $finished = $active && !empty($session['finished']);
        ?>
        <div class="wrap s2fc-app">
            <header class="s2fc-header">
                <div>
                    <h1><?php esc_html_e('Shopify to FluentCart Migrator', 'shopify-to-fluentcart-migrator'); ?></h1>
                    <p class="s2fc-header__sub"><?php esc_html_e('Export from Shopify, upload the file, review the list, publish. That is the whole thing.', 'shopify-to-fluentcart-migrator'); ?></p>
                </div>
                <?php if ($active) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="s2fc-inline-form" data-s2fc-reset>
                        <input type="hidden" name="action" value="s2fc_reset">
                        <?php wp_nonce_field('s2fc_reset', Ajax::NONCE); ?>
                        <button type="submit" class="s2fc-btn s2fc-btn--ghost"><?php esc_html_e('Start over', 'shopify-to-fluentcart-migrator'); ?></button>
                    </form>
                <?php endif; ?>
            </header>

            <?php $this->flash(); ?>

            <div class="s2fc-layout">
                <main class="s2fc-main">
                    <?php if (!$ready) : ?>
                        <div class="s2fc-card s2fc-card--warn">
                            <h2><?php esc_html_e('FluentCart is not active', 'shopify-to-fluentcart-migrator'); ?></h2>
                            <p><?php echo wp_kses_post(sprintf(
                                /* translators: %s: link to FluentCart */
                                __('Install and activate %s first. You can still read the export steps below.', 'shopify-to-fluentcart-migrator'),
                                '<a href="https://fluentcart.com/?by=272" target="_blank" rel="noopener">FluentCart</a>'
                            )); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($active) : ?>
                        <?php $this->step_review($session, $finished); ?>
                    <?php else : ?>
                        <?php $this->step_export(); ?>
                        <?php $this->step_upload($ready); ?>
                    <?php endif; ?>
                </main>
                <?php Promos::render(); ?>
            </div>
        </div>
        <?php
    }

    private function flash(): void
    {
        $flash = Ajax::take_flash();
        if (!$flash) {
            return;
        }
        $code = (string) ($flash['code'] ?? '');
        $msg  = (string) ($flash['detail'] ?? '');
        $map  = [
            'no_fluentcart' => __('FluentCart is not active.', 'shopify-to-fluentcart-migrator'),
            'no_file'       => __('Choose the CSV file first.', 'shopify-to-fluentcart-migrator'),
            'upload_1'      => __('The file is bigger than your server allows. Ask your host to raise upload_max_filesize, or split the export in Shopify.', 'shopify-to-fluentcart-migrator'),
            'upload_2'      => __('The file is bigger than the form allows.', 'shopify-to-fluentcart-migrator'),
            'upload_4'      => __('No file was uploaded.', 'shopify-to-fluentcart-migrator'),
            'upload_failed' => __('The upload failed.', 'shopify-to-fluentcart-migrator'),
            'parse'         => __('The file could not be read.', 'shopify-to-fluentcart-migrator'),
        ];
        $text = $map[$code] ?? __('Something went wrong.', 'shopify-to-fluentcart-migrator');
        echo '<div class="s2fc-notice s2fc-notice--error" role="alert"><strong>' . esc_html($text) . '</strong>' . ($msg ? ' ' . esc_html($msg) : '') . '</div>';
    }

    private function step_export(): void
    {
        ?>
        <section class="s2fc-card">
            <div class="s2fc-step"><span class="s2fc-step__num">1</span><h2><?php esc_html_e('Export your products from Shopify', 'shopify-to-fluentcart-migrator'); ?></h2></div>
            <ol class="s2fc-steps">
                <li><?php echo wp_kses_post(__('In Shopify admin open <strong>Products</strong>.', 'shopify-to-fluentcart-migrator')); ?></li>
                <li><?php echo wp_kses_post(__('Click <strong>Export</strong> at the top right.', 'shopify-to-fluentcart-migrator')); ?></li>
                <li><?php echo wp_kses_post(__('Choose <strong>All products</strong> (or a filtered selection if you only want some).', 'shopify-to-fluentcart-migrator')); ?></li>
                <li><?php echo wp_kses_post(__('Pick <strong>CSV for Excel, Numbers, or other spreadsheet programs</strong>, then click <strong>Export products</strong>.', 'shopify-to-fluentcart-migrator')); ?></li>
                <li><?php echo wp_kses_post(__('Small stores download right away. Bigger stores get the file by email from Shopify within a few minutes. Save the <code>.csv</code> file; do not open and re-save it in Excel, which can break the formatting.', 'shopify-to-fluentcart-migrator')); ?></li>
            </ol>
            <p class="s2fc-muted"><?php esc_html_e('Shopify puts every variant and every extra image on its own row. That is expected; this tool groups them back into products.', 'shopify-to-fluentcart-migrator'); ?></p>
        </section>
        <?php
    }

    private function step_upload(bool $ready): void
    {
        $max = size_format(wp_max_upload_size());
        ?>
        <section class="s2fc-card">
            <div class="s2fc-step"><span class="s2fc-step__num">2</span><h2><?php esc_html_e('Upload the export', 'shopify-to-fluentcart-migrator'); ?></h2></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="s2fc-upload" data-s2fc-upload>
                <input type="hidden" name="action" value="s2fc_upload">
                <?php wp_nonce_field('s2fc_upload', Ajax::NONCE); ?>
                <label class="s2fc-drop" for="s2fc_csv">
                    <input type="file" id="s2fc_csv" name="s2fc_csv" accept=".csv,text/csv" required <?php disabled(!$ready); ?>>
                    <span class="s2fc-drop__icon" aria-hidden="true"></span>
                    <span class="s2fc-drop__text"><strong><?php esc_html_e('Choose the CSV file', 'shopify-to-fluentcart-migrator'); ?></strong> <?php esc_html_e('or drop it here', 'shopify-to-fluentcart-migrator'); ?></span>
                    <span class="s2fc-drop__name" data-s2fc-filename></span>
                    <span class="s2fc-muted"><?php echo esc_html(sprintf(
                        /* translators: %s: size */
                        __('Up to %s. Nothing is written to your store yet; the next step lets you review first.', 'shopify-to-fluentcart-migrator'),
                        $max
                    )); ?></span>
                </label>
                <button type="submit" class="s2fc-btn s2fc-btn--primary" <?php disabled(!$ready); ?> data-s2fc-upload-btn>
                    <?php esc_html_e('Upload and review', 'shopify-to-fluentcart-migrator'); ?>
                </button>
            </form>
        </section>
        <?php
    }

    private function step_review(array $session, bool $finished): void
    {
        $products = Session::products();
        $results  = $session['results'] ?? [];
        $options  = Importer::sanitize_options($session['options'] ?? []);
        $skus     = [];
        foreach ($products as $p) {
            foreach ($p['variants'] as $v) {
                if ($v['sku'] !== '') {
                    $skus[] = $v['sku'];
                }
            }
        }
        $sku_clash = Importer::existing_skus($skus);
        $sku_dupes = array_fill_keys((array) ($session['duplicate_skus'] ?? []), true);
        $existing  = Importer::existing_handles(array_column($products, 'handle'));
        $gtin_state = Gtin::state();
        $gtin_products = (int) ($session['gtin_products'] ?? 0);

        $done_count = $skip_count = $fail_count = 0;
        foreach ($results as $r) {
            if (($r['status'] ?? '') === 'imported') {
                $done_count++;
            } elseif (($r['status'] ?? '') === 'skipped') {
                $skip_count++;
            } else {
                $fail_count++;
            }
        }
        ?>
        <section class="s2fc-card">
            <div class="s2fc-step"><span class="s2fc-step__num">3</span><h2><?php esc_html_e('Review and publish', 'shopify-to-fluentcart-migrator'); ?></h2></div>

            <div class="s2fc-stats">
                <div class="s2fc-stat"><span class="s2fc-stat__n"><?php echo esc_html(number_format_i18n((int) $session['products'])); ?></span><span><?php esc_html_e('products', 'shopify-to-fluentcart-migrator'); ?></span></div>
                <div class="s2fc-stat"><span class="s2fc-stat__n"><?php echo esc_html(number_format_i18n((int) $session['variants'])); ?></span><span><?php esc_html_e('variants', 'shopify-to-fluentcart-migrator'); ?></span></div>
                <div class="s2fc-stat"><span class="s2fc-stat__n"><?php echo esc_html(number_format_i18n((int) $session['images'])); ?></span><span><?php esc_html_e('images', 'shopify-to-fluentcart-migrator'); ?></span></div>
                <?php if (!empty($session['variant_images'])) : ?>
                    <div class="s2fc-stat"><span class="s2fc-stat__n"><?php echo esc_html(number_format_i18n((int) $session['variant_images'])); ?></span><span><?php esc_html_e('variant images', 'shopify-to-fluentcart-migrator'); ?></span></div>
                <?php endif; ?>
                <div class="s2fc-stat"><span class="s2fc-stat__n"><?php echo esc_html(number_format_i18n($gtin_products)); ?></span><span><?php esc_html_e('with barcodes (GTIN)', 'shopify-to-fluentcart-migrator'); ?></span></div>
                <div class="s2fc-stat s2fc-stat--file"><span class="s2fc-muted"><?php echo esc_html($session['source_name'] ?? ''); ?></span></div>
            </div>

            <?php if (!empty($session['skipped_rows'])) : ?>
                <div class="s2fc-notice s2fc-notice--warn">
                    <?php echo esc_html(sprintf(
                        /* translators: %d: row count */
                        _n('%d row in the file could not be read and was skipped. If products are missing below, re-export the CSV from Shopify without opening it in a spreadsheet first.', '%d rows in the file could not be read and were skipped. If products are missing below, re-export the CSV from Shopify without opening it in a spreadsheet first.', (int) $session['skipped_rows'], 'shopify-to-fluentcart-migrator'),
                        (int) $session['skipped_rows']
                    )); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($session['has_qty'])) : ?>
                <div class="s2fc-notice s2fc-notice--info">
                    <strong><?php esc_html_e('This export has no inventory quantities.', 'shopify-to-fluentcart-migrator'); ?></strong>
                    <?php esc_html_e('Newer Shopify exports leave the "Variant Inventory Qty" column out. Products are imported with stock management off, so they can be bought right away. Set quantities in FluentCart afterwards, or use FluentCart\'s inventory screen.', 'shopify-to-fluentcart-migrator'); ?>
                </div>
            <?php endif; ?>

            <?php if ($gtin_products > 0) : ?>
                <?php if ($gtin_state === 'active') : ?>
                    <div class="s2fc-notice s2fc-notice--good">
                        <strong><?php esc_html_e('Barcodes will be kept.', 'shopify-to-fluentcart-migrator'); ?></strong>
                        <?php echo esc_html(sprintf(
                            /* translators: 1: product count, 2: variant count */
                            __('%1$d products (%2$d variants) have a GTIN, UPC, EAN or ISBN. Custom Meta Fields for FluentCart is active, so each code is saved on its variation and goes into Google product structured data.', 'shopify-to-fluentcart-migrator'),
                            $gtin_products,
                            (int) ($session['gtin_variants'] ?? 0)
                        )); ?>
                    </div>
                <?php else : ?>
                    <div class="s2fc-notice s2fc-notice--warn">
                        <strong><?php echo esc_html(sprintf(
                            /* translators: %d: product count */
                            _n('%d of your products has a barcode (GTIN, UPC, EAN or ISBN) that will not be migrated.', '%d of your products have barcodes (GTIN, UPC, EAN or ISBN) that will not be migrated.', $gtin_products, 'shopify-to-fluentcart-migrator'),
                            $gtin_products
                        )); ?></strong>
                        <p><?php esc_html_e('FluentCart has no field for barcodes yet. Install the free Custom Meta Fields for FluentCart plugin before importing and the codes are saved on each variation automatically (and go into Google product structured data). Without it, the barcodes are only kept in a hidden note on each product.', 'shopify-to-fluentcart-migrator'); ?></p>
                        <p>
                            <a class="s2fc-btn s2fc-btn--primary s2fc-btn--small" href="<?php echo esc_url(Gtin::install_url()); ?>">
                                <?php echo $gtin_state === 'installed'
                                    ? esc_html__('Activate Custom Meta Fields', 'shopify-to-fluentcart-migrator')
                                    : esc_html__('Install the free plugin', 'shopify-to-fluentcart-migrator'); ?>
                            </a>
                            <a class="s2fc-btn s2fc-btn--ghost s2fc-btn--small" href="<?php echo esc_url(Helpers::admin_url(['s2fc_step' => 'review'])); ?>"><?php esc_html_e('I installed it, check again', 'shopify-to-fluentcart-migrator'); ?></a>
                        </p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($results) : ?>
                <div class="s2fc-notice s2fc-notice--info" data-s2fc-summary>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: imported, 2: skipped, 3: failed */
                        __('So far: %1$d imported, %2$d skipped, %3$d failed.', 'shopify-to-fluentcart-migrator'),
                        $done_count,
                        $skip_count,
                        $fail_count
                    )); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=fluent-cart#/products')); ?>"><?php esc_html_e('Open FluentCart products', 'shopify-to-fluentcart-migrator'); ?></a>
                </div>
            <?php endif; ?>

            <details class="s2fc-options" <?php echo $finished ? '' : 'open'; ?>>
                <summary><?php esc_html_e('Import options', 'shopify-to-fluentcart-migrator'); ?></summary>
                <div class="s2fc-options__grid" data-s2fc-options>
                    <label>
                        <span><?php esc_html_e('Product status after import', 'shopify-to-fluentcart-migrator'); ?></span>
                        <select name="status">
                            <option value="draft" <?php selected($options['status'], 'draft'); ?>><?php esc_html_e('Draft (recommended: check each one, then publish)', 'shopify-to-fluentcart-migrator'); ?></option>
                            <option value="shopify" <?php selected($options['status'], 'shopify'); ?>><?php esc_html_e('Same as Shopify (active → published, draft → draft, archived → private)', 'shopify-to-fluentcart-migrator'); ?></option>
                            <option value="publish" <?php selected($options['status'], 'publish'); ?>><?php esc_html_e('Publish everything now', 'shopify-to-fluentcart-migrator'); ?></option>
                        </select>
                    </label>
                    <label>
                        <span><?php esc_html_e('Weight unit in FluentCart', 'shopify-to-fluentcart-migrator'); ?></span>
                        <select name="weight_unit">
                            <?php foreach (['oz' => 'oz', 'lb' => 'lb', 'g' => 'g', 'kg' => 'kg'] as $u => $label) : ?>
                                <option value="<?php echo esc_attr($u); ?>" <?php selected($options['weight_unit'], $u); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="s2fc-check"><input type="checkbox" name="images" value="1" <?php checked($options['images']); ?>> <?php esc_html_e('Download images into the Media Library', 'shopify-to-fluentcart-migrator'); ?></label>
                    <label>
                        <span><?php esc_html_e('Create FluentCart product categories from', 'shopify-to-fluentcart-migrator'); ?></span>
                        <select name="category_source" data-s2fc-category-source>
                            <?php foreach (Importer::category_sources() as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($options['category_source'], $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="s2fc-check"><input type="checkbox" name="vendor" value="1" <?php checked($options['vendor']); ?>> <?php esc_html_e('Create product brands from Shopify Vendor', 'shopify-to-fluentcart-migrator'); ?></label>
                    <label class="s2fc-check"><input type="checkbox" name="tags" value="1" <?php checked($options['tags']); ?>> <?php esc_html_e('Import tags', 'shopify-to-fluentcart-migrator'); ?></label>
                    <label class="s2fc-check"><input type="checkbox" name="skip_done" value="1" <?php checked($options['skip_done']); ?>> <?php esc_html_e('Skip products already imported from this Shopify handle', 'shopify-to-fluentcart-migrator'); ?></label>
                </div>
            </details>

            <div class="s2fc-toolbar">
                <label class="s2fc-check"><input type="checkbox" data-s2fc-select-all checked> <?php esc_html_e('Select all', 'shopify-to-fluentcart-migrator'); ?></label>
                <input type="search" class="s2fc-search" placeholder="<?php esc_attr_e('Filter by title, SKU or vendor…', 'shopify-to-fluentcart-migrator'); ?>" data-s2fc-filter>
                <span class="s2fc-muted" data-s2fc-selected-count></span>
                <button type="button" class="s2fc-btn s2fc-btn--primary" data-s2fc-import><?php esc_html_e('Import selected into FluentCart', 'shopify-to-fluentcart-migrator'); ?></button>
                <button type="button" class="s2fc-btn s2fc-btn--ghost" data-s2fc-stop hidden><?php esc_html_e('Stop', 'shopify-to-fluentcart-migrator'); ?></button>
            </div>

            <div class="s2fc-progress" data-s2fc-progress hidden>
                <div class="s2fc-progress__bar"><span data-s2fc-progress-bar></span></div>
                <div class="s2fc-progress__text" data-s2fc-progress-text></div>
            </div>

            <div class="s2fc-table-wrap">
                <table class="s2fc-table" data-s2fc-table>
                    <thead>
                        <tr>
                            <th class="s2fc-col-check"><span class="screen-reader-text"><?php esc_html_e('Select', 'shopify-to-fluentcart-migrator'); ?></span></th>
                            <th class="s2fc-col-img"><span class="screen-reader-text"><?php esc_html_e('Image', 'shopify-to-fluentcart-migrator'); ?></span></th>
                            <th><?php esc_html_e('Product', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Variants', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Price', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Stock', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Categories', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Shopify status', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Notes', 'shopify-to-fluentcart-migrator'); ?></th>
                            <th><?php esc_html_e('Result', 'shopify-to-fluentcart-migrator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $i => $p) : $this->row($i, $p, $results[$i] ?? null, $sku_clash, $sku_dupes, $gtin_state, (int) ($existing[$p['handle']] ?? 0), $options['category_source']); endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php
    }

    private function row(int $i, array $p, $result, array $sku_clash, array $sku_dupes, string $gtin_state, int $existing, string $category_source): void
    {
        $category_options = [];
        foreach (array_keys(Importer::category_sources()) as $source) {
            $category_options[$source] = Importer::categories_for($p, $source);
        }
        $notes    = [];
        $flags    = [];

        $bad_codes = [];
        foreach ($p['variants'] as $v) {
            if ($v['barcode'] !== '' && !preg_match('/^(\d{8}|\d{12,14}|\d{9}[\dX])$/i', $v['barcode'])) {
                $bad_codes[] = $v['barcode'];
            }
        }
        if ($bad_codes) {
            $notes[] = esc_html(sprintf(
                /* translators: %s: barcode list */
                _n('Barcode %s is not a valid GTIN and will be kept only in the hidden source note.', 'Barcodes %s are not valid GTINs and will be kept only in the hidden source note.', count($bad_codes), 'shopify-to-fluentcart-migrator'),
                implode(', ', $bad_codes)
            ));
        }
        if ($p['gtin_count'] > 0) {
            $flags[] = $gtin_state === 'active'
                ? '<span class="s2fc-pill s2fc-pill--good" title="' . esc_attr__('Barcodes will be saved as GTINs', 'shopify-to-fluentcart-migrator') . '">GTIN</span>'
                : '<span class="s2fc-pill s2fc-pill--warn" title="' . esc_attr__('Barcode present; needs Custom Meta Fields to be migrated', 'shopify-to-fluentcart-migrator') . '">GTIN</span>';
        }
        if ($p['fulfillment'] === 'digital') {
            $flags[] = '<span class="s2fc-pill">' . esc_html__('Digital', 'shopify-to-fluentcart-migrator') . '</span>';
        }
        if ($p['gift_card']) {
            $flags[] = '<span class="s2fc-pill s2fc-pill--warn">' . esc_html__('Gift card', 'shopify-to-fluentcart-migrator') . '</span>';
        }
        if ($existing) {
            $notes[] = sprintf(
                '<a href="%s">%s</a>',
                esc_url(Helpers::edit_url($existing)),
                esc_html__('Already imported', 'shopify-to-fluentcart-migrator')
            );
        }
        $clashes = [];
        foreach ($p['variants'] as $v) {
            if ($v['sku'] !== '' && isset($sku_clash[$v['sku']])) {
                $clashes[] = $v['sku'];
            }
        }
        if ($clashes && !$existing) {
            $notes[] = esc_html(sprintf(
                /* translators: %s: SKU list */
                _n('SKU %s already exists in FluentCart; that variant will import without a SKU.', 'SKUs %s already exist in FluentCart; those variants will import without a SKU.', count($clashes), 'shopify-to-fluentcart-migrator'),
                implode(', ', $clashes)
            ));
        }
        $dupes = [];
        foreach ($p['variants'] as $v) {
            if ($v['sku'] !== '' && isset($sku_dupes[$v['sku']])) {
                $dupes[] = $v['sku'];
            }
        }
        if ($dupes) {
            $notes[] = esc_html(sprintf(
                /* translators: %s: SKU list */
                _n('SKU %s is used by more than one product in this file; only the first keeps it.', 'SKUs %s are used by more than one product in this file; only the first keeps them.', count($dupes), 'shopify-to-fluentcart-migrator'),
                implode(', ', array_unique($dupes))
            ));
        }
        if (!$p['images']) {
            $notes[] = esc_html__('No images', 'shopify-to-fluentcart-migrator');
        }
        if ($p['price_max'] === 0) {
            $notes[] = esc_html__('Price is 0', 'shopify-to-fluentcart-migrator');
        }

        $thumb = $p['images'][0]['src'] ?? '';
        $price = $p['price_min'] === $p['price_max']
            ? Helpers::format_money($p['price_min'])
            : Helpers::format_money($p['price_min']) . ' – ' . Helpers::format_money($p['price_max']);

        if ($p['is_simple']) {
            $variant_text = esc_html__('Simple', 'shopify-to-fluentcart-migrator');
        } elseif ($p['options']) {
            $variant_text = esc_html(sprintf(
                /* translators: 1: count, 2: option names */
                __('%1$d (%2$s)', 'shopify-to-fluentcart-migrator'),
                count($p['variants']),
                implode(', ', $p['options'])
            ));
        } else {
            $variant_text = esc_html(sprintf(
                /* translators: %d: count */
                _n('%d variant', '%d variants', count($p['variants']), 'shopify-to-fluentcart-migrator'),
                count($p['variants'])
            ));
        }

        $tracked = false;
        foreach ($p['variants'] as $v) {
            if ($v['tracked']) {
                $tracked = true;
            }
        }
        $stock = $tracked ? number_format_i18n($p['stock']) : '<span class="s2fc-muted">' . esc_html__('Not tracked', 'shopify-to-fluentcart-migrator') . '</span>';

        $status_label = [
            'active'   => __('Active', 'shopify-to-fluentcart-migrator'),
            'draft'    => __('Draft', 'shopify-to-fluentcart-migrator'),
            'archived' => __('Archived', 'shopify-to-fluentcart-migrator'),
        ][$p['status']] ?? $p['status'];

        $checked = !$existing && !($result && ($result['status'] ?? '') === 'imported');
        $search  = mb_strtolower($p['title'] . ' ' . $p['vendor'] . ' ' . implode(' ', array_column($p['variants'], 'sku')) . ' ' . $p['handle'], 'UTF-8');
        ?>
        <tr data-s2fc-row data-index="<?php echo esc_attr((string) $i); ?>" data-search="<?php echo esc_attr($search); ?>">
            <td class="s2fc-col-check"><input type="checkbox" data-s2fc-pick <?php checked($checked); ?>></td>
            <td class="s2fc-col-img"><?php if ($thumb) : ?><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy" width="44" height="44"><?php else : ?><span class="s2fc-noimg"></span><?php endif; ?></td>
            <td>
                <strong><?php echo esc_html($p['title']); ?></strong>
                <div class="s2fc-muted s2fc-small">
                    <?php echo esc_html(implode(' · ', array_filter([$p['vendor'], $p['type']]))); ?>
                    <?php if ($p['is_simple'] && $p['variants'][0]['sku'] !== '') : ?> · <code><?php echo esc_html($p['variants'][0]['sku']); ?></code><?php endif; ?>
                </div>
                <?php if ($flags) : ?><div class="s2fc-flags"><?php echo wp_kses_post(implode(' ', $flags)); ?></div><?php endif; ?>
            </td>
            <td><?php echo $variant_text; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            <td class="s2fc-nowrap"><?php echo esc_html($price); ?></td>
            <td><?php echo $stock; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
            <td class="s2fc-small s2fc-cats" data-s2fc-cats="<?php echo esc_attr(wp_json_encode($category_options)); ?>"><?php echo esc_html(implode(', ', $category_options[$category_source]) ?: '—'); ?></td>
            <td><span class="s2fc-pill s2fc-pill--<?php echo esc_attr($p['status']); ?>"><?php echo esc_html($status_label); ?></span></td>
            <td class="s2fc-small s2fc-notes"><?php echo wp_kses_post(implode('<br>', $notes)); ?></td>
            <td class="s2fc-result" data-s2fc-result>
                <?php if ($result) : $this->result_cell($result); endif; ?>
            </td>
        </tr>
        <?php
    }

    private function result_cell(array $r): void
    {
        $status = $r['status'] ?? 'failed';
        $labels = [
            'imported' => __('Imported', 'shopify-to-fluentcart-migrator'),
            'skipped'  => __('Skipped', 'shopify-to-fluentcart-migrator'),
            'failed'   => __('Failed', 'shopify-to-fluentcart-migrator'),
        ];
        $class = ['imported' => 'good', 'skipped' => '', 'failed' => 'bad'][$status] ?? 'bad';
        echo '<span class="s2fc-pill s2fc-pill--' . esc_attr($class) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
        if (!empty($r['post_id'])) {
            echo ' <a href="' . esc_url(Helpers::edit_url((int) $r['post_id'])) . '">' . esc_html__('Edit', 'shopify-to-fluentcart-migrator') . '</a>';
        }
        $warnings = (array) ($r['warnings'] ?? []);
        if (!empty($r['message'])) {
            array_unshift($warnings, $r['message']);
        }
        if ($warnings) {
            echo '<div class="s2fc-small s2fc-muted">' . esc_html(implode(' ', $warnings)) . '</div>';
        }
    }
}
