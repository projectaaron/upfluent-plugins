<?php
/**
 * Runs the CSV parser against tests/sample-export.csv without WordPress.
 * Usage: php tests/run.php
 */
define('ABSPATH', __DIR__ . '/');
define('S2FC_VERSION', 'test');

class WP_Error {
    private $code; private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function __($s, $d = null) { return $s; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function sanitize_title($s) { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9\-]+/', '-', $s); return trim($s, '-'); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function esc_url_raw($s) { return trim((string) $s); }
function wp_kses_post($s) { return (string) $s; }
function class_exists_stub() {}
function apply_filters($tag, $value) { return $value; }
function do_action() {}

require __DIR__ . '/../includes/class-helpers.php';
require __DIR__ . '/../includes/class-csv-parser.php';
require __DIR__ . '/../includes/class-importer.php';

$result = \S2FC\Csv_Parser::parse_file(__DIR__ . '/sample-export.csv');
if (is_wp_error($result)) {
    fwrite(STDERR, "FAIL: " . $result->get_error_message() . "\n");
    exit(1);
}

$fail = 0;
function check($label, $cond) {
    global $fail;
    echo ($cond ? '  ok  ' : '  FAIL') . "  $label\n";
    if (!$cond) { $fail++; }
}

$s = $result['summary'];
$p = [];
foreach ($result['products'] as $prod) { $p[$prod['handle']] = $prod; }

echo "Summary: " . json_encode($s) . "\n";
check('5 products', $s['products'] === 5);
check('7 variants', $s['variants'] === 7);
check('3 images', $s['images'] === 3);
check('3 products with GTIN', $s['gtin_products'] === 3);

$w = $p['wife-after-god'];
check('simple product', $w['is_simple'] === true);
check('price 1599', $w['variants'][0]['price'] === 1599);
check('compare 1999', $w['variants'][0]['compare'] === 1999);
check('cost 325', $w['variants'][0]['cost'] === 325);
check('barcode kept', $w['variants'][0]['barcode'] === '9780986366741');
check('sku apostrophe stripped', $p['quoted-sku']['variants'][0]['sku'] === '9780986366999' && $p['quoted-sku']['variants'][0]['barcode'] === '9780986366999');
check('tracked qty', $w['variants'][0]['tracked'] && $w['variants'][0]['qty'] === 4546);
check('status active', $w['status'] === 'active');
check('excerpt from SEO', $w['excerpt'] === 'A 30-day devotional for wives.');
check('tags split', $w['tags'] === ['devotional', 'wives']);
check('variant title = product title', $w['variants'][0]['title'] === 'Wife After God');
check('category path kept', $w['category'] === 'Media > Books');

$o = $p['olive-tree-print'];
check('variable product', $o['is_simple'] === false);
check('options Size, Color', $o['options'] === ['Size', 'Color']);
check('3 variants', count($o['variants']) === 3);
check('variant title joined', $o['variants'][1]['title'] === '5x7 / Natural');
check('summary has duplicate sku list', is_array($s['duplicate_skus']) && $s['skipped_rows'] === 0);
check('continue selling', $o['variants'][1]['continue_selling'] === true);
check('untracked variant', $o['variants'][2]['tracked'] === false);
check('2 images, ordered', count($o['images']) === 2 && $o['images'][1]['alt'] === 'Second view');
check('image dedup by URL', $o['images'][0]['src'] === 'https://cdn.shopify.com/s/files/1/olive-1.jpg');
check('price range', $o['price_min'] === 900 && $o['price_max'] === 1200);
check('gtin count 1', $o['gtin_count'] === 1);
check('physical', $o['fulfillment'] === 'physical');

$d = $p['prayer-guide-pdf'];
check('digital', $d['fulfillment'] === 'digital');
check('draft', $d['status'] === 'draft');
check('no images', $d['images'] === []);

$g = $p['gift-card'];
check('gift card flagged', $g['gift_card'] === true);
check('archived', $g['status'] === 'archived');
check('title fallback not needed', $g['title'] === 'Gift Card');

$cf = function ($src) use ($w) { return \S2FC\Importer::categories_for($w, $src); };
check('categories: type', $cf('type') === ['Books']);
check('categories: product category leaf', $cf('category') === ['Books']);
check('categories: tags', $cf('tags') === ['devotional', 'wives']);
check('categories: type + category dedupes', $cf('type_category') === ['Books']);
check('categories: both when different', \S2FC\Importer::categories_for($o, 'type_category') === ['Prints', 'Artwork']);
check('categories: none', $cf('none') === []);
check('option: legacy categories=0 maps to none', \S2FC\Importer::sanitize_options(['categories' => 0])['category_source'] === 'none');
check('option: bad source falls back to type', \S2FC\Importer::sanitize_options(['category_source' => 'x'])['category_source'] === 'type');

echo $fail ? "\n$fail check(s) failed\n" : "\nAll checks passed\n";
exit($fail ? 1 : 0);
