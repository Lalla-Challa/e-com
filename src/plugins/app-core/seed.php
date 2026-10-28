<?php

declare(strict_types=1);

/**
 * CLI seeder — NOT web accessible. Run inside the PHP container:
 *   php /var/www/html/wp-content/plugins/app-core/seed.php
 *
 * Runs migrations, creates an admin + customer, and maintains the small
 * sewing-notions catalogue used by scripts/smoke-test.sh:
 *
 *   polyester-thread-cone        guest browse / product page fixture
 *   tailors-marking-chalk-set    cart price-change fixture (SKU TLS-CHK-02)
 *   seam-ripper-precision        low-stock drain fixture (stock 20)
 *   hand-sewing-needles-case     guest product fixture
 *   cotton-piping-cord-5mm       cut-to-length fixture
 *   elastic-thread-spool         out-of-stock fixture (stock 0)
 *
 * Migration 019 deactivates every product when it first runs; this seeder
 * re-activates (and restocks) its own fixtures afterwards. cms_settings and
 * cms_pages are owned by migration 019 and are NOT touched here.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/http.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validate.php';
require_once __DIR__ . '/includes/auth-utils.php';
require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/includes/money.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/mailer.php';

use AppCore\Auth;
use AppCore\Db;
use AppCore\Migrations;

// Money + Pricing are only pulled in so the seed can exercise them; nothing
// here computes a price.

$ran = Migrations::run();
echo 'migrations: ' . ($ran === [] ? 'up-to-date' : implode(', ', $ran)) . PHP_EOL;

$pdo = Db::pdo();

$ensureUser = static function (string $email, string $name, string $password, string $role) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT id FROM ' . Db::table('users') . ' WHERE email = ?');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $pdo->prepare('INSERT INTO ' . Db::table('users') . ' (email, password_hash, full_name, role, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$email, Auth::hashPassword($password), $name, $role]);

    return (int) $pdo->lastInsertId();
};

$adminId = $ensureUser('admin@appstore.test', 'Store Admin', 'admin-pass-123', 'admin');
$customerId = $ensureUser('customer@appstore.test', 'Demo Customer', 'customer-pass-123', 'customer');

// ---- Chapters (created by migration 019; ensured here for safety) --------
$categories = [
    ['threads', 'Threads & Spools'],
    ['elastic-lace', 'Elastic, Lace & Ribbon'],
    ['needles-pins', 'Needles, Pins & Buttons'],
    ['scissors-cutters', 'Scissors & Cutters'],
];
$catIds = [];
foreach ($categories as [$slug, $name]) {
    $stmt = $pdo->prepare('SELECT id FROM ' . Db::table('categories') . ' WHERE slug = ?');
    $stmt->execute([$slug]);
    $found = $stmt->fetchColumn();
    if ($found !== false) {
        $catIds[$slug] = (int) $found;
        continue;
    }
    $pdo->prepare('INSERT INTO ' . Db::table('categories') . ' (slug, name, parent_id, created_at, updated_at) VALUES (?, ?, NULL, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$slug, $name]);
    $catIds[$slug] = (int) $pdo->lastInsertId();
}

// ---- Smoke-test fixture products -----------------------------------------
// Editorial plates carried over from migration 016/019 (public CDN).
$plate = [
    'thread' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuCr57CYkLkApilUGzOpYIJRcwAUDHJygpJkdOqbnaTnG5133i4cghqVy8RzsvaOW10S3SdWmzPKFIRf-zuZFaUSK22tEO4viutuKxn6CR4bLBmIANR1cBN5aD3cK5_HH1d5mWbVbjgMXvCouE5stGBjUjXWLRO-NbevnpZ-VXtuzemXR8AKIabR9MthVgkAp6NfWwBxXG8iCAFaqmzOHZOQXncGddARTNZgP5xL_gqU7UOpiMyO9-aV',
    'tools' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC-Xz0NkHtAUUoQnJh-dj-ooluJYVrOtCbeWPPcpWOsBC_VzJPut7yDBwe8GjVAttAcgwJcpp7TAzQRh5_QbP5ZPpMY71MqgZR9CJqO8WgB5Na3bVsW9g9iGHCGSGuSgOOk8dxeyAopa08ao7rq16yaqO8NIRlQZoZ1nuwlxHXIEIuqwpSNT_23-cknwQzKNJv53-bLc3XNLpbxlc9MfIbhos_VBQM8wImXD5vzAXsdjsspyIM44bNe',
    'canvas' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBDuOSryfiN3FeOsiSPChVz8bFQMBVxI4TnMhHj_88TIT0hNDkBQnLlP_YWr27TyUrDlZKoCUPqOA80gk7x6WLOULDRviIuN77HDN6qCsKCnsoY10udZSRgHKgEFzQyAAYsY55yNTBPqw8KzFLhEL5iGPUrwhENHv92N1MPE0QBNnmqnra2MwhzH81SrRXlHr0ltnYnB3K4yIY-07rHdajXgYSqXiIJYg1E3BWv2pBuk5xGD51li5gg',
];

// [slug, sku, name, description, price, category, stock, image, metadata]
$products = [
    [
        'polyester-thread-cone', 'THR-POL-C1', 'Polyester Thread Cone 1000m',
        'Core-spun polyester on a 1000-metre cone. The high-mileage workhorse for quilting, topstitching and long seam runs — low lint, high tensile, colour-locked.',
        '9.00', 'threads', 140, $plate['thread'],
        ['ref' => 'POL-C1-1000', 'unit' => 'cone', 'unit_label' => '1000 m cone', 'composition' => 'Core-spun Polyester', 'gauge' => 'Tex 27 / 40wt', 'length_label' => '1000 m', 'origin' => 'Germany', 'maker' => 'Core Mill', 'color' => 'Natural White', 'colorways' => 32, 'badge' => 'High Mileage'],
    ],
    [
        'tailors-marking-chalk-set', 'TLS-CHK-02', "Tailor's Chalk Set — 6 Pieces",
        'Clay marking chalk in six working colours with a fold-out tray. Marks that brush away with a finger or steam — never a permanent line on finished cloth.',
        '5.50', 'needles-pins', 90, $plate['tools'],
        ['ref' => 'CHK-6-AS', 'unit' => 'set', 'unit_label' => 'set of 6', 'composition' => 'Clay chalk', 'origin' => 'Japan', 'maker' => 'Hisago Works', 'color' => 'Assorted', 'colorways' => 6, 'badge' => 'Bench Staple'],
    ],
    [
        'seam-ripper-precision', 'SCR-RIP-01', 'Precision Seam Ripper',
        'Hardened steel ripper with a 22mm tungsten point and a felt-lined handle. Opens a stubborn seam in one pass without chewing the cloth beneath it.',
        '8.00', 'scissors-cutters', 20, $plate['tools'],
        ['ref' => 'RIP-115-STL', 'unit' => 'piece', 'unit_label' => 'each', 'composition' => 'Stainless steel / tungsten point', 'gauge' => '115 mm', 'origin' => 'Taiwan', 'maker' => 'Aitor-class bench', 'color' => 'Steel', 'colorways' => 1],
    ],
    [
        'hand-sewing-needles-case', 'NDL-HND-20', 'Hand Sewing Needles — Case of 20',
        'Twenty between, sharps, betweens and straw needles in a steel slip case, each channel labelled by size. The hand-work drawer staple that never bends under a thimble.',
        '4.80', 'needles-pins', 130, $plate['tools'],
        ['ref' => 'HND-20-AS', 'unit' => 'case', 'unit_label' => 'case of 20', 'composition' => 'Hardened steel', 'gauge' => 'Assorted sizes 3–9', 'origin' => 'Germany', 'maker' => 'Schmesitz bench', 'color' => 'Nickel', 'colorways' => 1, 'badge' => 'Studio Staple'],
    ],
    [
        'cotton-piping-cord-5mm', 'TAP-PIP-05', 'Cotton Piping Cord 5mm',
        'Twisted cotton cord for cushion piping, bag handles and tailored welts. Cut by the metre — consistent diameter through the whole roll, no flat spots.',
        '2.10', 'elastic-lace', 300, $plate['canvas'],
        ['ref' => 'PIP-5-NAT', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Cotton', 'gauge' => '5 mm', 'origin' => 'Portugal', 'maker' => 'Porto Cord Mill', 'color' => 'Natural', 'colorways' => 4, 'badge' => 'Cut to length'],
    ],
    [
        'elastic-thread-spool', 'THR-ELA-01', 'Elastic Thread Spool 32m',
        'Fine elastic thread for shirring and smocking. Wind onto the bobbin by hand and work over a washed fabric — the gathers hold through hundreds of wears.',
        '3.90', 'threads', 0, $plate['thread'],
        ['ref' => 'ELA-1-WHT', 'unit' => 'spool', 'unit_label' => '32 m spool', 'composition' => 'Cotton / rubber core', 'gauge' => '1.2 mm', 'length_label' => '32 m', 'origin' => 'Switzerland', 'maker' => 'St. Gallen Spool', 'color' => 'White', 'colorways' => 3],
    ],
];

$insertProduct = $pdo->prepare(
    'INSERT INTO ' . Db::table('products') . ' (slug, sku, name, description, price, currency, stock, category_id, image_url, metadata, is_active, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, \'USD\', ?, ?, ?, ?, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
);
$reactivateProduct = $pdo->prepare(
    'UPDATE ' . Db::table('products') . ' SET is_active = TRUE, stock = ?, updated_at = UTC_TIMESTAMP(3) WHERE slug = ?'
);
$insertPrice = $pdo->prepare('INSERT INTO ' . Db::table('price_history') . ' (product_id, price, currency, effective_at) VALUES (?, ?, \'USD\', UTC_TIMESTAMP(3))');

$productIds = [];
foreach ($products as [$slug, $sku, $name, $desc, $price, $cat, $stock, $image, $metadata]) {
    $stmt = $pdo->prepare('SELECT id FROM ' . Db::table('products') . ' WHERE slug = ?');
    $stmt->execute([$slug]);
    $found = $stmt->fetchColumn();
    if ($found !== false) {
        $productIds[$slug] = (int) $found;
        // Migration 019 deactivates the whole catalogue — bring our own
        // fixtures back (and restore their smoke-test stock levels).
        $reactivateProduct->execute([$stock, $slug]);
        continue;
    }
    $insertProduct->execute([
        $slug, $sku, $name, $desc, $price, $stock, $catIds[$cat], $image,
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $id = (int) $pdo->lastInsertId();
    $productIds[$slug] = $id;
    $insertPrice->execute([$id, $price]);
}

$reviews = [
    ['polyester-thread-cone', $customerId, 5, 'The cone that never jams', 'Four quilts in and the lint build-up is still half what my old thread left behind.'],
    ['tailors-marking-chalk-set', $customerId, 4, 'Marks and brushes away', 'The clay chalk wipes off with steam. White shows up on dark wool, unlike chalk pencil.'],
];
$insertReview = $pdo->prepare(
    'INSERT IGNORE INTO ' . Db::table('reviews') . ' (product_id, user_id, rating, title, body, is_verified_purchase, is_approved, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, TRUE, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
);
foreach ($reviews as [$slug, $userId, $rating, $title, $body]) {
    $insertReview->execute([$productIds[$slug], $userId, $rating, $title, $body]);
}

// ---- Unit costs + low-stock thresholds (margin & inventory reporting) -----
$costs = [
    'polyester-thread-cone' => ['3.80', 20],
    'tailors-marking-chalk-set' => ['1.90', 15],
    'seam-ripper-precision' => ['2.75', 8],
    'hand-sewing-needles-case' => ['1.40', 20],
    'cotton-piping-cord-5mm' => ['0.85', 40],
    'elastic-thread-spool' => ['1.60', 10],
];
$updateCost = $pdo->prepare('UPDATE ' . Db::table('products') . ' SET cost = ?, low_stock_threshold = ? WHERE slug = ?');
foreach ($costs as $slug => [$cost, $threshold]) {
    $updateCost->execute([$cost, $threshold, $slug]);
}

// ---- Child categories, so the tree endpoint has something to nest ---------
$childCategories = [
    ['machine-thread', 'Machine Thread', 'threads'],
    ['hand-embroidery', 'Hand & Embroidery Thread', 'threads'],
    ['shears', 'Shears', 'scissors-cutters'],
    ['snips-cutters', 'Snips & Cutters', 'scissors-cutters'],
];
foreach ($childCategories as [$slug, $name, $parent]) {
    $stmt = $pdo->prepare('SELECT id FROM ' . Db::table('categories') . ' WHERE slug = ?');
    $stmt->execute([$slug]);
    if ($stmt->fetchColumn() !== false) {
        continue;
    }
    $pdo->prepare('INSERT INTO ' . Db::table('categories') . ' (slug, name, parent_id, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$slug, $name, $catIds[$parent]]);
}

// ---- Tax rates -----------------------------------------------------------
$taxRates = [
    ['US', null, 'Sales Tax', '0.0000'],
    ['US', 'CA', 'CA Sales Tax', '0.0875'],
    ['US', 'NY', 'NY Sales Tax', '0.0800'],
    ['GB', null, 'VAT', '0.2000'],
    ['DE', null, 'MwSt', '0.1900'],
];
foreach ($taxRates as [$country, $region, $name, $rate]) {
    $exists = $pdo->prepare('SELECT 1 FROM ' . Db::table('tax_rates') . ' WHERE country = ? AND (region <=> ?) LIMIT 1');
    $exists->execute([$country, $region]);
    if ($exists->fetchColumn() !== false) {
        continue;
    }
    $pdo->prepare('INSERT INTO ' . Db::table('tax_rates') . ' (country, region, name, rate, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$country, $region, $name, $rate]);
}

// ---- Shipping methods ----------------------------------------------------
$shipping = [
    ['standard', 'Standard Shipping', 'Arrives in 4-6 business days.', '5.95', '1.50', '150.00', 4, 6, null],
    ['express', 'Express Shipping', 'Arrives in 1-2 business days.', '14.95', '2.50', null, 1, 2, null],
    ['freight', 'Pallet Freight', 'Large items, kerbside delivery.', '89.00', '0.00', null, 7, 14, ['US', 'CA']],
];
foreach ($shipping as [$code, $name, $desc, $base, $perItem, $freeOver, $dMin, $dMax, $countries]) {
    $exists = $pdo->prepare('SELECT 1 FROM ' . Db::table('shipping_methods') . ' WHERE code = ? LIMIT 1');
    $exists->execute([$code]);
    if ($exists->fetchColumn() !== false) {
        continue;
    }
    $pdo->prepare('INSERT INTO ' . Db::table('shipping_methods') . ' (code, name, description, base_rate, per_item, free_over_subtotal, estimated_days_min, estimated_days_max, countries, is_active, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, 0, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$code, $name, $desc, $base, $perItem, $freeOver, $dMin, $dMax, $countries === null ? null : json_encode($countries)]);
}

// ---- Coupons -------------------------------------------------------------
$coupons = [
    ['WELCOME10', 'percent', '10.00', '0.00', null, null, null, null],
    ['SAVE20', 'fixed', '20.00', '100.00', null, null, null, null],
    ['ONCEONLY', 'percent', '25.00', '0.00', 1, null, null, null],
    ['SUMMER26', 'percent', '15.00', '50.00', 100, 2, '2026-06-01 00:00:00', '2026-09-01 00:00:00'],
];
foreach ($coupons as [$code, $type, $value, $minSubtotal, $maxUses, $perUser, $starts, $ends]) {
    $exists = $pdo->prepare('SELECT 1 FROM ' . Db::table('coupons') . ' WHERE code = ? LIMIT 1');
    $exists->execute([$code]);
    if ($exists->fetchColumn() !== false) {
        continue;
    }
    $pdo->prepare('INSERT INTO ' . Db::table('coupons') . ' (code, type, value, min_subtotal, max_uses, used_count, per_user_limit, starts_at, ends_at, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$code, $type, $value, $minSubtotal, $maxUses, $perUser, $starts, $ends]);
}

// ---- A saved address for the demo customer -------------------------------
$addrExists = $pdo->prepare('SELECT 1 FROM ' . Db::table('customer_addresses') . ' WHERE user_id = ? LIMIT 1');
$addrExists->execute([$customerId]);
if ($addrExists->fetchColumn() === false) {
    $pdo->prepare('INSERT INTO ' . Db::table('customer_addresses') . ' (user_id, label, full_name, line1, line2, city, region, postal_code, country, phone, is_default_shipping, is_default_billing, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
        ->execute([$customerId, 'Home', 'Demo Customer', '482 Linden Avenue', 'Apt 3B', 'Oakland', 'CA', '94607', 'US', '+1 510 555 0142']);
}

echo 'seeded: admin@appstore.test / admin-pass-123' . PHP_EOL;
echo '        customer@appstore.test / customer-pass-123' . PHP_EOL;
echo '        ' . count($products) . ' fixture products across ' . (count($categories) + count($childCategories)) . " categories\n";
echo '        ' . count($coupons) . " coupons, " . count($shipping) . " shipping methods, " . count($taxRates) . " tax rates\n";
echo '        cms_settings + cms_pages owned by migration 019 (Buttonwalay)' . PHP_EOL;
