<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 019_buttonwalay_catalogue — rebrand the storefront to Buttonwalay, a notions
 * house selling sewing materials: threads, elastic, lace, needles, pins,
 * scissors and cutters.
 *
 * Replaces the TÈSSILE textile catalogue from 016–018:
 *  - every existing product is deactivated (never deleted — order history and
 *    its RESTRICT foreign keys must survive);
 *  - legacy categories are removed (products keep their rows with a NULL
 *    category via the FK's ON DELETE SET NULL);
 *  - four new chapters and a fresh catalogue are inserted;
 *  - cms_settings and cms_pages are upserted: they ARE the copy this
 *    migration owns.
 *
 * Idempotent throughout: re-running skips existing slugs and overwrites the
 * settings/pages it owns. The CLI seeder re-activates its own smoke-test
 * fixture products after this migration has deactivated everything.
 */
return static function (PDO $pdo): void {
    $now = 'UTC_TIMESTAMP(3)';
    $categories = Db::table('categories');
    $products = Db::table('products');
    $history = Db::table('price_history');
    $settings = Db::table('cms_settings');
    $pages = Db::table('cms_pages');

    // ------------------------------------------------------------ retirement
    // Park the previous catalogue (TÈSSILE cloth + any legacy seed goods).
    $pdo->exec('UPDATE ' . $products . ' SET is_active = FALSE, updated_at = ' . $now);

    // Drop every category outside the new chapter set; attached products keep
    // their rows with category_id NULLed by the FK.
    $keep = ['threads', 'elastic-lace', 'needles-pins', 'scissors-cutters'];
    $quoted = implode(', ', array_map(static fn (string $s): string => "'" . $s . "'", $keep));
    $pdo->exec('DELETE FROM ' . $categories . ' WHERE slug NOT IN (' . $quoted . ')');

    // ---------------------------------------------------------------- images
    // Editorial photography carried over from the design set (public CDN, no
    // uploads) — the thread, shears and horn-button plates read as notions.
    $img = [
        'thread' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuCr57CYkLkApilUGzOpYIJRcwAUDHJygpJkdOqbnaTnG5133i4cghqVy8RzsvaOW10S3SdWmzPKFIRf-zuZFaUSK22tEO4viutuKxn6CR4bLBmIANR1cBN5aD3cK5_HH1d5mWbVbjgMXvCouE5stGBjUjXWLRO-NbevnpZ-VXtuzemXR8AKIabR9MthVgkAp6NfWwBxXG8iCAFaqmzOHZOQXncGddARTNZgP5xL_gqU7UOpiMyO9-aV',
        'horn' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuDhaNMJdH1bNjWYf8gt8FiE3VPFfXLCB4PdjNjUUg9Np4-9-cIPoZJI-Qhb2gf4IGVlJ1agaW1mR5-FLRQ6jzg06q8Aq6E9usresEfkilW6ZzyI6gdcDEL5sbiTUKG0C4VKas2UJ9DavNrGzxs3jSLmd73DYDPjOMv6NxjMhP7ZbNnA_jcHOSyIkALTxvZs8Gzuvk4Lqd0F543bDXgu8ZzsSn0I6noZZGHz9X5R8dqWLzmj3JkvcmuW',
        'tools' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC-Xz0NkHtAUUoQnJh-dj-ooluJYVrOtCbeWPPcpWOsBC_VzJPut7yDBwe8GjVAttAcgwJcpp7TAzQRh5_QbP5ZPpMY71MqgZR9CJqO8WgB5Na3bVsW9g9iGHCGSGuSgOOk8dxeyAopa08ao7rq16yaqO8NIRlQZoZ1nuwlxHXIEIuqwpSNT_23-cknwQzKNJv53-bLc3XNLpbxlc9MfIbhos_VBQM8wImXD5vzAXsdjsspyIM44bNe',
        'belgian' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBKk_BjjAZtAqUS2BlW-MZIzzEP-xnwJBnuQzI_tyUF1EcMLbhUqEYW0ih4W-CeakYvqvpTzs1Mt5GT30kYzY-RVfePTsHoNmB_0JTgJzyLyQYuRlUsPrMG_Eh-l7ybr374RFCx8C52A6Y10tJ8FS7vdXFLbFFazsGHAG5Om8RbuQyqQstoExr112HAS9Y-dp-_TmBldV4HAKfzr-TU5v4gmkAefuiMklSHbHxwkBS4HjgjHjavi78s',
        'habotai' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC6icusS1MVXxGa4TL077JLfc-CVHPZMCYp5tOMFElfPwhDma_Lkg7WzpGRlkvzZSkR3WGQSCOL7VXf8qS4gPX5LMk3CW7BOXPcKwuhFmfHtGZl6e1YhbEHnxYbpLvdiRu7i-dB8udMlDY2OrL-jEZ8xIYmreyuOBIXBUfVjFTrWwDETlXsWPaK6hPN0T26GAZoEW4RqER53wUG2Tl0zZQyeZh2FGqsHUavoqulZlUZL4W5aVLTxURC',
        'canvas' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBDuOSryfiN3FeOsiSPChVz8bFQMBVxI4TnMhHj_88TIT0hNDkBQnLlP_YWr27TyUrDlZKoCUPqOA80gk7x6WLOULDRviIuN77HDN6qCsKCnsoY10udZSRgHKgEFzQyAAYsY55yNTBPqw8KzFLhEL5iGPUrwhENHv92N1MPE0QBNnmqnra2MwhzH81SrRXlHr0ltnYnB3K4yIY-07rHdajXgYSqXiIJYg1E3BWv2pBuk5xGD51li5gg',
        'irish' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuCsplT3Ndn2gShIwEh544ESYjrwGpvthYlZWWJcd4UVSgpCRTqm6AT3aQQ-zufv4kxAsQWyOrNnCeDbBn2uXVPk0fEZ70MOxDFZPmDXl_y-bnPdshfYuQ2D9MYqpWBJK6KjPhpih3PSrbkOMucOy4acbWYjaP7Ta1iPRuOxSQ52pjXtBSjE_SUZW8gBONx_vxKj6LHSqqQ3Z3I9d_8c46OsXboa7QuxJ9XSxaBoUOi6AycPyTR9Kjqz',
    ];

    // ------------------------------------------------------------ categories
    $categorySeed = [
        ['threads', 'Threads & Spools'],
        ['elastic-lace', 'Elastic, Lace & Ribbon'],
        ['needles-pins', 'Needles, Pins & Buttons'],
        ['scissors-cutters', 'Scissors & Cutters'],
    ];
    $catIds = [];
    foreach ($categorySeed as [$slug, $name]) {
        $stmt = $pdo->prepare('SELECT id FROM ' . $categories . ' WHERE slug = ?');
        $stmt->execute([$slug]);
        $found = $stmt->fetchColumn();
        if ($found !== false) {
            $catIds[$slug] = (int) $found;
            continue;
        }
        $pdo->prepare('INSERT INTO ' . $categories . ' (slug, name, parent_id, created_at, updated_at) VALUES (?, ?, NULL, ' . $now . ', ' . $now . ')')
            ->execute([$slug, $name]);
        $catIds[$slug] = (int) $pdo->lastInsertId();
    }

    // -------------------------------------------------------------- products
    // [slug, sku, name, description, price, category, stock, image, metadata]
    $catalogue = [
        [
            'mercerized-cotton-thread-40wt', 'THR-COT-40', 'Mercerized Cotton Thread 40wt',
            'Long-staple cotton, mercerized for a low-sheen finish and high tensile strength. The everyday construction thread: colour-locked, lint-light, and stable under a fast machine foot.',
            '4.50', 'threads', 220, $img['thread'],
            ['ref' => 'COT-40-200', 'unit' => 'spool', 'unit_label' => '200 m spool', 'composition' => '100% Mercerized Cotton', 'fiber' => 'Long-staple cotton', 'gauge' => 'Tex 27 / 40wt', 'length_label' => '200 m', 'origin' => 'Germany', 'maker' => 'Gütermann-class mill', 'color' => 'Natural White', 'colorways' => 48, 'badge' => 'Everyday Essential',
                'dossier' => [
                    'construction' => [
                        'needle' => 'Pairs with Universal 80/12 or Jeans 90/14 needles.',
                        'tension' => 'Standard top tension; reduce by one dial mark on fine silks.',
                        'notes' => 'Colour-locked with reactive dyes — safe for wash-fast garment work.',
                    ],
                    'care' => ['storage' => 'Store spools away from direct sun to protect dye light-fastness.'],
                ]],
        ],
        [
            'all-purpose-thread-card-24', 'THR-CRD-24', 'All-Purpose Thread Card — 24',
            'Twenty-four machine threads on indexed cards in the core wardrobe palette. Every colour matches a staple fabric family, so reorders never guess at shade names.',
            '26.00', 'threads', 70, $img['thread'],
            ['ref' => 'CRD-24-CORE', 'unit' => 'set', 'unit_label' => 'card of 24', 'composition' => 'Polyester core, cotton wrap', 'fiber' => 'Wrapped polyester', 'length_label' => '24 × 40 m', 'origin' => 'Japan', 'maker' => 'Kuretake Guild', 'color' => 'Core palette', 'colorways' => 24, 'badge' => 'Studio Set',
                'dossier' => [
                    'construction' => [
                        'needle' => 'Universal 70/10–90/14 depending on cloth weight.',
                        'notes' => 'Carded and numbered so a spent spool reorders by index, not memory.',
                    ],
                    'care' => ['storage' => 'Return spools to the card; loose spools collect bench lint.'],
                ]],
        ],
        [
            'silk-buttonhole-twist', 'THR-SLK-BH', 'Silk Buttonhole Twist',
            'Genuine filament silk on a 20-metre spool, lightly waxed for hand buttonholes and pick stitching. Sits flush in a hand-worked bar and takes a dull, honest lustre under the iron.',
            '12.50', 'threads', 85, $img['thread'],
            ['ref' => 'SLK-BH-20', 'unit' => 'spool', 'unit_label' => '20 m spool', 'composition' => '100% Filament Silk', 'fiber' => 'Grade A silk', 'length_label' => '20 m', 'origin' => 'Como, Italy', 'maker' => 'Como Filature', 'color' => 'Natural', 'colorways' => 12,
                'dossier' => [
                    'construction' => [
                        'needle' => 'Worked by hand with a between 10 or a straw 10.',
                        'notes' => 'Wax lightly with beeswax before the first pass; do not over-wax or the twist stiffens.',
                    ],
                    'care' => ['storage' => 'Keep the spool sleeved — silk twist dries brittle in open air.'],
                ]],
        ],
        [
            'woven-waistband-elastic-30mm', 'ELS-WV-30', 'Woven Waistband Elastic 30mm',
            'Dense woven elastic with a stable 1:1.5 stretch ratio and firm recovery. Cut by the metre for waistbands, cuffs and bag openings that must hold their snap season after season.',
            '3.20', 'elastic-lace', 420, $img['belgian'],
            ['ref' => 'WV-30-BLK', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => 'Polyester / Rubber core', 'fiber' => 'Woven elastic', 'gauge' => '30 mm • stretch 1:1.5', 'origin' => 'Switzerland', 'maker' => 'St. Gallen Weave', 'color' => 'Black', 'colorways' => 6, 'badge' => 'Cut to length',
                'dossier' => [
                    'construction' => [
                        'needle' => 'Jeans 100/16 or Stretch 80/12 with a walking foot.',
                        'stitch' => 'Use a narrow zigzag or triple stretch stitch — a straight stitch snaps on extension.',
                        'notes' => 'Cut 10–15% shorter than the finished waist measurement for correct tension.',
                    ],
                    'care' => ['launder' => 'Wash at 40°C; avoid bleach which degrades the rubber core.', 'press' => 'Never iron directly onto the elastic — heat kills recovery.'],
                ]],
        ],
        [
            'delicate-scallop-lace-15mm', 'LCE-SCL-15', 'Delicate Scallop Lace 15mm',
            'Fine cotton-blend edging with a softly wired scallop. Cut by the metre for neckline finishes, sleeve hems and the quiet detail that separates a made garment from a sewn one.',
            '5.80', 'elastic-lace', 240, $img['habotai'],
            ['ref' => 'SCL-15-IVY', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => 'Cotton / Poly blend', 'fiber' => 'Scallop edging', 'origin' => 'Calais, France', 'maker' => 'Calais Leavers', 'color' => 'Ivory', 'colorways' => 9, 'badge' => 'Cut to length',
                'dossier' => [
                    'construction' => [
                        'needle' => 'Microtex 70/10; hand-finish corners with a between 9.',
                        'notes' => 'Baste before machining — the scallop shifts under a standard foot. Curve easing belongs in the notches, not the bias.',
                    ],
                    'care' => ['launder' => 'Hand wash cool or machine on delicate in a mesh bag.', 'press' => 'Press from the reverse on a soft surface so the scallop keeps its relief.'],
                ]],
        ],
        [
            'cotton-bias-binding-20mm', 'TAP-BIA-20', 'Cotton Bias Binding 20mm',
            'True bias-cut cotton strip, pre-folded and ready to bind raw edges. Stable enough for armholes and necklines, soft enough to disappear into the seam.',
            '2.40', 'elastic-lace', 380, $img['canvas'],
            ['ref' => 'BIA-20-NAT', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Cotton', 'fiber' => 'Bias-cut cotton', 'origin' => 'Portugal', 'maker' => 'Porto Tape Mill', 'color' => 'Natural', 'colorways' => 14, 'badge' => 'Cut to length',
                'dossier' => [
                    'construction' => [
                        'needle' => 'Universal 80/12.',
                        'notes' => 'Open the pre-press, stitch the first fold to the raw edge, then turn and stitch in the ditch for an invisible finish.',
                    ],
                    'care' => ['launder' => 'Machine wash with the garment.', 'press' => 'Steam sets the second fold crisply.'],
                ]],
        ],
        [
            'grosgrain-ribbon-10mm', 'TAP-GRS-10', 'Grosgrain Ribbon 10mm',
            'Ribbed nylon grosgrain with a dry hand and clean cut edge. Hanger loops, tab pulls, hat bands and the structural ribbon that must not limp over time.',
            '1.90', 'elastic-lace', 500, $img['irish'],
            ['ref' => 'GRS-10-BLK', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => 'Nylon', 'fiber' => 'Grosgrain', 'origin' => 'France', 'maker' => 'Lyon Ribbon House', 'color' => 'Black', 'colorways' => 20,
                'dossier' => [
                    'construction' => [
                        'needle' => 'Universal 80/12; keep the ribbon taut but not stretched under the foot.',
                        'notes' => 'Seal cut ends with a light flame pass or fray check before the ribbon is turned.',
                    ],
                    'care' => ['launder' => 'Wash with the garment; nylon grosgrain holds its rib through tumble heat.'],
                ]],
        ],
        [
            'universal-needles-assortment', 'NDL-UNI-AS', 'Universal Needles — Assortment Pack',
            'Five sizes of universal machine needles (60/8 through 110/18), fifty pieces total, each size in its own labelled channel. The drawer staple every studio runs through first.',
            '6.50', 'needles-pins', 160, $img['tools'],
            ['ref' => 'UNI-50-5SZ', 'unit' => 'pack', 'unit_label' => 'pack of 50', 'composition' => 'Hardened steel', 'fiber' => 'Machine needles', 'gauge' => '60/8 – 110/18', 'origin' => 'Germany', 'maker' => 'Schmesitz bench', 'color' => 'Nickel', 'colorways' => 1, 'badge' => 'Studio Staple',
                'dossier' => [
                    'construction' => [
                        'notes' => 'Change needles every 6–8 hours of sewing or when a skipped stitch appears — a dull point drags fibre rather than parting it.',
                        'matching' => '60/8 for silk and lining • 80/12 for shirting • 90/14 for coating • 100/16 and 110/18 for denim and canvas.',
                    ],
                    'care' => ['storage' => 'Keep the channels sealed; a bench needle dulls against loose steel.'],
                ]],
        ],
        [
            'glass-head-pins-bottle', 'PIN-GLS-01', 'Glass-Head Pins — Bottle of 100',
            'Hardened steel shafts with heat-proof glass heads, 33mm. They take the iron without melting and leave a hole you cannot see. Supplied in a corked glass bottle.',
            '7.50', 'needles-pins', 120, $img['tools'],
            ['ref' => 'GLS-100-33', 'unit' => 'bottle', 'unit_label' => 'bottle of 100', 'composition' => 'Steel / glass head', 'fiber' => 'Dressmaking pins', 'gauge' => '33 mm shaft', 'origin' => 'Japan', 'maker' => 'Hisago Works', 'color' => 'Assorted', 'colorways' => 6,
                'dossier' => [
                    'construction' => [
                        'notes' => 'Glass heads survive a passing iron; plastic heads weld themselves to your cloth.',
                    ],
                    'care' => ['storage' => 'Return pins to the bottle — a magnet tin demagnetises the shafts over a season.'],
                ]],
        ],
        [
            'corozo-buttons-card-24', 'BTN-COR-24', 'Corozo Buttons — Card of 24',
            'Four-hole corozo in a warm nut brown, 24-ligne, countersunk and honed matte. Tagua nut, carved and tumbled — the button that looks like it belongs on a coat you keep for twenty years.',
            '16.00', 'needles-pins', 95, $img['horn'],
            ['ref' => 'COR-24L-24', 'unit' => 'card', 'unit_label' => 'card of 24', 'composition' => 'Corozo (tagua nut)', 'fiber' => 'Natural corozo', 'gauge' => '24 ligne', 'origin' => 'Ecuador', 'maker' => 'Hand-turned', 'color' => 'Nut Brown', 'colorways' => 4, 'badge' => 'Hand-finished',
                'dossier' => [
                    'construction' => [
                        'needle' => 'Buttonhole twist on a 100/16 or a between hand needle.',
                        'notes' => 'Back heavy coats with a stay button on the underside; corozo takes the strain so the cloth does not.',
                    ],
                    'care' => ['launder' => 'Wash-safe; buff with a dry cloth if the nut dulls.'],
                ]],
        ],
        [
            'dressmaker-shears-9inch', 'SCR-DRS-09', 'Dressmaker Shears — 9 Inch',
            'Forged carbon-steel shears with an offset handle that keeps your lower blade flat on the bench. Hollow-ground edge glides through coating, canvas and eight layers of shirting without lifting the grain.',
            '46.00', 'scissors-cutters', 40, $img['tools'],
            ['ref' => 'DRS-9-CARB', 'unit' => 'piece', 'unit_label' => 'each', 'composition' => 'High-carbon steel', 'fiber' => 'Forged shears', 'gauge' => '9 in / 230 mm', 'origin' => 'Sheffield, England', 'maker' => 'Sheffield bench', 'color' => 'Black Oxide', 'colorways' => 1, 'badge' => 'Sharpenable for life',
                'dossier' => [
                    'construction' => [
                        'notes' => 'Cut cloth, not paper and pins — paper dulls the edge within a season. Open fully on each stroke so the whole blade works.',
                    ],
                    'care' => ['storage' => 'Oil the pivot monthly and store hung or sheathed; never drop onto tile.', 'sharpen' => 'Carbon steel takes a whetstone — service rather than replace.'],
                ]],
        ],
        [
            'thread-snips-precision', 'SCR-SNP-01', 'Precision Thread Snips',
            'Spring-loaded snips with a 45mm blade and a finger guard. One squeeze trims the thread at the needle plate without reaching for the shears. The bench tool you will lose twice and buy again.',
            '14.00', 'scissors-cutters', 85, $img['tools'],
            ['ref' => 'SNP-45-STL', 'unit' => 'piece', 'unit_label' => 'each', 'composition' => 'Stainless steel', 'fiber' => 'Thread snips', 'gauge' => '45 mm blade', 'origin' => 'Taiwan', 'maker' => 'Aitor-class bench', 'color' => 'Steel', 'colorways' => 2,
                'dossier' => [
                    'construction' => [
                        'notes' => 'Keep dedicated to thread and fine trim; the short blade geometry is not for cloth.',
                    ],
                    'care' => ['storage' => 'Rinse lint from the pivot; a drop of oil keeps the spring action clean.'],
                ]],
        ],
        [
            'rotary-cutter-45mm', 'CUT-ROT-45', 'Rotary Cutter — 45mm',
            'A 45mm tungsten-carbon wheel with a guarded retract and a comfort-grip handle. Pair with a mat for strip cutting, bias work and any run where the shears would tire the hand.',
            '24.00', 'scissors-cutters', 60, $img['tools'],
            ['ref' => 'ROT-45-WC', 'unit' => 'piece', 'unit_label' => 'each', 'composition' => 'Tungsten-carbon blade', 'fiber' => 'Rotary cutter', 'gauge' => '45 mm wheel', 'origin' => 'Italy', 'maker' => 'Olfati bench', 'color' => 'Saddle', 'colorways' => 1,
                'dossier' => [
                    'construction' => [
                        'notes' => 'Always cut on a self-healing mat; a hard surface kills the edge in one pass. Replace the blade when it starts to skip rather than pressing harder.',
                    ],
                    'care' => ['storage' => 'Retract the guard before setting the cutter down — the wheel is sharper than it looks.'],
                ]],
        ],
    ];

    $insertProduct = $pdo->prepare(
        'INSERT INTO ' . $products . ' (slug, sku, name, description, price, currency, stock, category_id, image_url, metadata, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, \'USD\', ?, ?, ?, ?, TRUE, ' . $now . ', ' . $now . ')'
    );
    $insertHistory = $pdo->prepare(
        'INSERT INTO ' . $history . ' (product_id, price, currency, effective_at) VALUES (?, ?, \'USD\', ' . $now . ')'
    );

    foreach ($catalogue as [$slug, $sku, $name, $description, $price, $category, $stock, $image, $metadata]) {
        $stmt = $pdo->prepare('SELECT id FROM ' . $products . ' WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() !== false) {
            continue;
        }

        $insertProduct->execute([
            $slug,
            $sku,
            $name,
            $description,
            $price,
            $stock,
            $catIds[$category] ?? null,
            $image,
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $insertHistory->execute([(int) $pdo->lastInsertId(), $price]);
    }

    // -------------------------------------------------------------- settings
    $settingsSeed = [
        'store.name' => 'Buttonwalay',
        'store.tagline' => 'Fine threads, elastics, needles, scissors, cutters, lace and everyday sewing notions for makers, tailors and studios.',
        'store.address' => 'Studio 14, The Notions Quarter',
        'announcement.enabled' => '1',
        'announcement.text' => 'Complimentary thread card with orders above $75 • Worldwide dispatch',
        'home.hero.eyebrow' => 'Catalogue N° 07 — Essentials',
        'home.hero.heading' => 'Essential notions for every stitch.',
        'home.hero.subheading' => 'Mercerized threads, woven elastics, hardened steel shears and scallop lace — curated for people who sew seriously.',
        'home.hero.image' => '',
        'home.hero.note' => 'Sourced from specialist makers worldwide',
        'shop.heading' => 'Threads, Elastic, Needles & More',
        'shop.intro' => 'Curated sewing notions selected for strength, finish and daily reliability — sold by the spool, pack and metre for makers, tailors and studios.',
        'correspondence.heading' => 'Restock Notices',
        'correspondence.body' => 'Restock alerts for thread colourways, seasonal lace trims and new blade arrivals. No excess — just inventory.',
    ];

    $upsertSetting = $pdo->prepare(
        'INSERT INTO ' . $settings . ' (setting_key, setting_value, is_public, updated_at)
         VALUES (?, ?, TRUE, ' . $now . ')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_public = TRUE, updated_at = ' . $now
    );
    foreach ($settingsSeed as $key => $value) {
        $upsertSetting->execute([$key, $value]);
    }

    // ----------------------------------------------------------------- pages
    $pageSeed = [
        [
            'title' => 'About Buttonwalay',
            'slug' => 'about',
            'excerpt' => 'A notions house for people who sew: honest threads, sharpenable steel and trims cut to the metre.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'House Record № 07'],
                ['type' => 'quote', 'text' => 'Buttonwalay exists for the drawer every maker actually opens: the thread that does not shred, the needle that does not snap mid-seam, the shears that can be sharpened rather than replaced.'],
                ['type' => 'heading', 'text' => 'I. Notions, Not Novelties'],
                ['type' => 'paragraph', 'text' => 'We stock a deliberately narrow list. Every thread weight, elastic width, needle size and blade in the catalogue has survived daily use in working studios before it earned a place here. If a notion is decorative rather than useful, it does not ship.'],
                ['type' => 'heading', 'text' => 'II. Steel That Can Be Sharpened'],
                ['type' => 'paragraph', 'text' => 'Our shears and scissors are carbon steel for a reason: they take a whetstone and a service, instead of following plastic siblings into a landfill after one dull season. Buy once, sharpen for decades.'],
                ['type' => 'heading', 'text' => 'III. Cut to the Metre'],
                ['type' => 'paragraph', 'text' => 'Elastic, lace, bias binding and ribbon are cut to your exact length and dispatched rolled, never folded — so the first metre arrives with the same manners as the last.'],
                ['type' => 'list', 'items' => ['Core thread colours restocked continuously', 'Carbon-steel blades serviceable for life', 'Cut trims shipped rolled on rigid cores']],
            ],
        ],
        [
            'title' => 'Notions Guide & Glossary',
            'slug' => 'notions-glossary',
            'excerpt' => 'A working reference for makers: thread weights, elastic stretch ratios, needle sizing and blade care.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Counter Guide • Rev. 2026/10'],
                ['type' => 'heading', 'text' => '01 Thread Weights'],
                ['type' => 'paragraph', 'text' => 'Tex and the old ticket numbers describe the same thing: the mass of the yarn. A 40wt (Tex 27) cotton is the everyday construction thread; 60wt for fine silk and buttonholes; 20–30wt topstitch thread when the stitch itself is the detail.'],
                ['type' => 'list', 'items' => ['60wt / Tex 15 — linings, silk, hand buttonholes', '40wt / Tex 27 — shirting, most woven garment work', '20–30wt — topstitching, denim, canvas']],
                ['type' => 'heading', 'text' => '02 Elastic & Recovery'],
                ['type' => 'paragraph', 'text' => 'Woven elastic is rated by width and stretch ratio. A 1:1.5 ratio means a 40 cm waistband takes a 26–27 cm cut. Knitted elastic is softer but creeps; woven holds its snap through hundreds of washes.'],
                ['type' => 'heading', 'text' => '03 Needles by Fabric'],
                ['type' => 'paragraph', 'text' => 'Universal points suit most wovens. Denim and canvas want a reinforced Jeans needle; knits a Ballpoint that parts the loop instead of piercing it; silk and lining a Microtex with a slim acute point.'],
                ['type' => 'heading', 'text' => '04 Caring for Carbon Steel'],
                ['type' => 'paragraph', 'text' => 'Wipe blades after cutting sticky interfacings, oil the pivot monthly, and never cut paper with your cloth shears. A serviceable pair outlives three disposable ones.'],
            ],
        ],
        [
            'title' => 'Shipping & Returns',
            'slug' => 'shipping-returns',
            'excerpt' => 'How notions are packed, what can be returned, and how cut lengths are handled.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Dispatch Protocol • Rev. 2026/10'],
                ['type' => 'heading', 'text' => '01 Packing'],
                ['type' => 'paragraph', 'text' => 'Spools, packs and scissors ship boxed with moulded inserts. Cut elastic, lace, binding and ribbon travel rolled on rigid cores so they arrive without crease memory.'],
                ['type' => 'list', 'items' => ['Complimentary thread card on orders above $75', 'Tracked dispatch worldwide, duties prepaid (DDP)', 'Cut lengths rolled — never folded into a parcel']],
                ['type' => 'heading', 'text' => '02 Returns'],
                ['type' => 'paragraph', 'text' => 'Unopened packs, spools and tools may be returned within 30 days. Cut elastic, lace, binding and ribbon are cut to your measure and cannot be returned, except where a defect is confirmed.'],
                ['type' => 'heading', 'text' => '03 Defects'],
                ['type' => 'paragraph', 'text' => 'A dead spool, a bent pin, a blade that arrives dull — photograph it with the reference number and we replace or credit it in full. Steel that fails under normal use is on us.'],
            ],
        ],
        [
            'title' => 'Notions FAQ',
            'slug' => 'faq',
            'excerpt' => 'Straight answers on cut lengths, thread matching, blade service and studio restocks.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Counter Notes • Rev. 2026/10'],
                ['type' => 'heading', 'text' => 'Can I order a continuous length of elastic or lace?'],
                ['type' => 'paragraph', 'text' => 'Yes. Any cut up to 50 metres comes from a single roll. If a roll would run out mid-cut, we contact you before packing.'],
                ['type' => 'heading', 'text' => 'How do I match a thread colour I already own?'],
                ['type' => 'paragraph', 'text' => 'Send us a photograph in daylight with a white sheet nearby, or order the 24-card first — the core wardrobe palette covers most working wardrobes.'],
                ['type' => 'heading', 'text' => 'Do you sharpen the shears you sell?'],
                ['type' => 'paragraph', 'text' => 'We do not offer sharpening in-house yet, but our carbon-steel shears are built for a standard wheel-grind service available at most good sharpeners.'],
                ['type' => 'heading', 'text' => 'How do studio restocks work?'],
                ['type' => 'paragraph', 'text' => 'Registered accounts keep a re-order history: one click reorders the exact thread colours, elastic widths and needle sizes from previous consignments.'],
            ],
        ],
    ];

    // Drop the two legacy TÈSSILE pages; the Buttonwalay pages below replace
    // them under the new slugs (about, notions-glossary). Only these known
    // slugs are removed so admin-authored pages survive.
    $pdo->exec("DELETE FROM {$pages} WHERE slug IN ('about-the-atelier', 'fabric-glossary')");

    $upsertPage = $pdo->prepare(
        'INSERT INTO ' . $pages . ' (title, slug, excerpt, body, status, published_at, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, \'published\', ' . $now . ', NULL, ' . $now . ', ' . $now . ')
         ON DUPLICATE KEY UPDATE title = VALUES(title), excerpt = VALUES(excerpt), body = VALUES(body),
             status = \'published\', published_at = COALESCE(published_at, ' . $now . '), updated_at = ' . $now
    );
    foreach ($pageSeed as $page) {
        $upsertPage->execute([
            $page['title'],
            $page['slug'],
            $page['excerpt'],
            json_encode($page['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
};
