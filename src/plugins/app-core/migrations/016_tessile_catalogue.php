<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 016_tessile_catalogue — the TÈSSILE Atelier editorial catalogue.
 *
 * The storefront design is a textile atelier: cloth sold by the metre,
 * haberdashery, tools and patterns. Rather than hard-coding that copy in the
 * SPA, this migration loads it into the same tables the rest of the app reads
 * (categories, products, cms_settings, cms_pages), so the admin console can
 * edit it and the storefront keeps rendering server-owned content.
 *
 * Every statement is idempotent and additive:
 *  - categories are looked up by slug before inserting;
 *  - products are inserted only when their slug is missing (an edited product
 *    is never overwritten by a later deploy);
 *  - settings/pages are upserted by their unique key, because they ARE the
 *    content this migration is responsible for.
 *
 * Fabric specifications (fiber, origin, mill, gsm, width, reference, unit,
 * swatch price) live in products.metadata JSON — no schema change needed, and
 * the products API already exposes that column.
 */
return static function (PDO $pdo): void {
    $now = 'UTC_TIMESTAMP(3)';
    $categories = Db::table('categories');
    $products = Db::table('products');
    $history = Db::table('price_history');
    $settings = Db::table('cms_settings');
    $pages = Db::table('cms_pages');

    // ---------------------------------------------------------------- images
    // Editorial photography shipped with the design (public CDN, no uploads).
    $img = [
        'hero' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuAab09mQkGNqRu5lXOqErqtOoWoEx-6btBGr9kl5m2SGGwfsF1MDMjqQn2j8r8cr9FWLDLhYa2SOYTEi9C9qRVt1QHz1XDYrvz2KvdIUfxvs5z7GG9iIPL4i0yUCBC1brmNdgoKD3O3ORcTs6FmI5VCr7j9-FLYLUvHfLAkrmdnBGdM8RQQ7N49jc9aAGYppkFJVwRYXzy00CTtHL4xXjKjqgQdZKX0WBBULU0MmU8OQfBes5QmYxa9',
        'normandy' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuA53mnK9UenjfRp-XiGxBbFCdkgOtQY8UDnZBACtrezf7u2kEFvE2M_-0DT4Py4D4gNxwTk4uY3jQ_vYcrauc8ijWReUt6aMpoHMkcr7j1zP1H0mLh07RZi4SMpXx3D42trUHln9IoBX4kKzeIAq0YkPe4P4-uaI7xcfwypccVrG4evd5SajukqjnlVZtIyXl_3e3ry5m-kBg6-oiWtJ-xWxw0OzhKRMworIMKfLbsPBvU0IfCmXAt1',
        'belgian' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBKk_BjjAZtAqUS2BlW-MZIzzEP-xnwJBnuQzI_tyUF1EcMLbhUqEYW0ih4W-CeakYvqvpTzs1Mt5GT30kYzY-RVfePTsHoNmB_0JTgJzyLyQYuRlUsPrMG_Eh-l7ybr374RFCx8C52A6Y10tJ8FS7vdXFLbFFazsGHAG5Om8RbuQyqQstoExr112HAS9Y-dp-_TmBldV4HAKfzr-TU5v4gmkAefuiMklSHbHxwkBS4HjgjHjavi78s',
        'biella' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBIo0DPRIEs4xVXOm-f1kKVl53F0ZdoGqfnwHqD8mZEdzq4_LDDZccNrufvOQfFhL2WkdJ-LTQqliYMuPJM_28FuyCOtyBpuv9ZOEu0kITCdf97Ywk-LVkrKjjY6Z3tX0TXTH31qOpzvXZal-UtO8kddftsCWn-CfxeMb9r0-KQqldawZe1qEgj6X95-o8PG33VBc61GTdoQVC3fxYJ9jrKdcQ1QXTAzvmny1mbisFW92pjofkFk5oD',
        'denim' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC2y0mEtYe6oLFjwiNLvsCGQ9r9InC84VYhNCZJ8j8CRlEB9xYVbuPmn12YTcQIzOPiw2bNOBceP9goKkd-EtgIYepGglg6Ut6CMD_9RfNf-Z2apIjfhMUnqPL36dNcDfXAxqYMskqFXWR4I7Wyc8pxvQP8tQ6-DBsw6wbn733noK7kaw5L-ORMNPAbeaHaMeiOIVbo2yoqQKZaLKHQGlWTGiztvcEz1w4nafFht58WPBO3yNZeKRv0',
        'irish' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuCsplT3Ndn2gShIwEh544ESYjrwGpvthYlZWWJcd4UVSgpCRTqm6AT3aQQ-zufv4kxAsQWyOrNnCeDbBn2uXVPk0fEZ70MOxDFZPmDXl_y-bnPdshfYuQ2D9MYqpWBJK6KjPhpih3PSrbkOMucOy4acbWYjaP7Ta1iPRuOxSQ52pjXtBSjE_SUZW8gBONx_vxKj6LHSqqQ3Z3I9d_8c46OsXboa7QuxJ9XSxaBoUOi6AycPyTR9Kjqz',
        'canvas' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBDuOSryfiN3FeOsiSPChVz8bFQMBVxI4TnMhHj_88TIT0hNDkBQnLlP_YWr27TyUrDlZKoCUPqOA80gk7x6WLOULDRviIuN77HDN6qCsKCnsoY10udZSRgHKgEFzQyAAYsY55yNTBPqw8KzFLhEL5iGPUrwhENHv92N1MPE0QBNnmqnra2MwhzH81SrRXlHr0ltnYnB3K4yIY-07rHdajXgYSqXiIJYg1E3BWv2pBuk5xGD51li5gg',
        'habotai' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC6icusS1MVXxGa4TL077JLfc-CVHPZMCYp5tOMFElfPwhDma_Lkg7WzpGRlkvzZSkR3WGQSCOL7VXf8qS4gPX5LMk3CW7BOXPcKwuhFmfHtGZl6e1YhbEHnxYbpLvdiRu7i-dB8udMlDY2OrL-jEZ8xIYmreyuOBIXBUfVjFTrWwDETlXsWPaK6hPN0T26GAZoEW4RqER53wUG2Tl0zZQyeZh2FGqsHUavoqulZlUZL4W5aVLTxURC',
        'worsted' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuDfZjFGbS6y-L8tU8O0Y9Z0s7a2B5nNBUuE3wvVXPpydQV7m8axdo39a7pB8ui9y5MyZmeYN-R7UtWrSi0Toe8EHXw7mWBJHieUAptXfE9I4A1_Q3I6x76w118YWD9faPFDKMZZMhWY-rJGX4gEekfYSjf36R5JrBU5aAp5vVe6zJrTkTbFdnKyyIrjeQOkSYSG1XDTqt-w9VjzSdsNKWQn3rl9SHTkYftiHayUl34DegOlb38V8DZA',
        'thread' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuCr57CYkLkApilUGzOpYIJRcwAUDHJygpJkdOqbnaTnG5133i4cghqVy8RzsvaOW10S3SdWmzPKFIRf-zuZFaUSK22tEO4viutuKxn6CR4bLBmIANR1cBN5aD3cK5_HH1d5mWbVbjgMXvCouE5stGBjUjXWLRO-NbevnpZ-VXtuzemXR8AKIabR9MthVgkAp6NfWwBxXG8iCAFaqmzOHZOQXncGddARTNZgP5xL_gqU7UOpiMyO9-aV',
        'horn' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuDhaNMJdH1bNjWYf8gt8FiE3VPFfXLCB4PdjNjUUg9Np4-9-cIPoZJI-Qhb2gf4IGVlJ1agaW1mR5-FLRQ6jzg06q8Aq6E9usresEfkilW6ZzyI6gdcDEL5sbiTUKG0C4VKas2UJ9DavNrGzxs3jSLmd73DYDPjOMv6NxjMhP7ZbNnA_jcHOSyIkALTxvZs8Gzuvk4Lqd0F543bDXgu8ZzsSn0I6noZZGHz9X5R8dqWLzmj3JkvcmuW',
        'tools' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC-Xz0NkHtAUUoQnJh-dj-ooluJYVrOtCbeWPPcpWOsBC_VzJPut7yDBwe8GjVAttAcgwJcpp7TAzQRh5_QbP5ZPpMY71MqgZR9CJqO8WgB5Na3bVsW9g9iGHCGSGuSgOOk8dxeyAopa08ao7rq16yaqO8NIRlQZoZ1nuwlxHXIEIuqwpSNT_23-cknwQzKNJv53-bLc3XNLpbxlc9MfIbhos_VBQM8wImXD5vzAXsdjsspyIM44bNe',
        'binder' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuBCZCiTSXn2plZSmNi5PiROperF3ZjwVF6INdJrHq5W0r58LoNiwueH66MnglfojpVNWsBR4NsNSHoShdGlTKpjYx60qv9Npjklkef2pAa-Zerr3YBAXz2tkb8mAFqBXJdv65fBKQfRyYNwBVHRVZdNNym_UkyItRjOTHWWI2qSWFLikOSV3OJdIiPu9ydbFV-_lFJ1QQOE1y2XhMrachfsWy48t8vCNWMSrs4Fy5si4fXhWGeGVoc_',
        'swatch' => 'https://lh3.googleusercontent.com/aida-public/AB6AXuC-Xz0NkHtAUUoQnJh-dj-ooluJYVrOtCbeWPPcpWOsBC_VzJPut7yDBwe8GjVAttAcgwJcpp7TAzQRh5_QbP5ZPpMY71MqgZR9CJqO8WgB5Na3bVsW9g9iGHCGSGuSgOOk8dxeyAopa08ao7rq16yaqO8NIRlQZoZ1nuwlxHXIEIuqwpSNT_23-cknwQzKNJv53-bLc3XNLpbxlc9MfIbhos_VBQM8wImXD5vzAXsdjsspyIM44bNe',
    ];

    // ------------------------------------------------------------ categories
    $categorySeed = [
        ['textiles-cloth', 'Textiles & Cloth'],
        ['haberdashery', 'Haberdashery'],
        ['patterns', 'Patterns'],
        ['atelier-tools', 'Atelier Tools'],
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
            'normandy-washed-heavy-linen', 'TEX-LN-280', 'Normandy Washed Heavy Linen',
            'Wet-spun Normandy flax, enzyme-washed for a liquid drape and a dry, mineral hand. Holds a sculpted lapel roll and softens with every laundering.',
            '38.00', 'textiles-cloth', 142, $img['normandy'],
            ['ref' => 'NMD-280-TC', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Raw Flax', 'fiber' => 'Pure Linen', 'origin' => 'Normandy, France', 'mill' => 'Normandy Flax', 'weight_gsm' => 280, 'weight_label' => '280 gsm (Heavy)', 'width_cm' => 148, 'weave' => 'Double-weft plain weave', 'color' => 'Terre Cuite', 'colorways' => 3, 'swatch_price' => '3.50', 'badge' => 'Atelier Favorite'],
        ],
        [
            'washed-belgian-linen-slub', 'TEX-LN-210', 'Washed Belgian Linen Slub',
            'Pre-softened with enzymes for an earthy, liquid drape. Ideal for unstructured jackets, wide-leg trousers, and summer tunics.',
            '34.00', 'textiles-cloth', 42, $img['belgian'],
            ['ref' => 'LN-210', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => 'Pure Flax', 'fiber' => 'Pure Linen', 'origin' => 'Flanders, Belgium', 'mill' => 'Piedmont Mill', 'weight_gsm' => 210, 'weight_label' => '210 gsm (Medium)', 'width_cm' => 145, 'weave' => 'Slub plain weave', 'color' => 'Natural Slub', 'colorways' => 4, 'swatch_price' => '3.50'],
        ],
        [
            'biella-super-120s-wool-flannel', 'TEX-WL-290', 'Biella Super 120s Wool Flannel',
            'Finely milled combed wool with a soft brushed surface. Superb recovery and body, formulated for bespoke autumnal tailoring.',
            '68.00', 'textiles-cloth', 18, $img['biella'],
            ['ref' => 'WL-290', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Merino', 'fiber' => 'Biella Wool', 'origin' => 'Biella, Italy', 'mill' => 'Piedmont Mill', 'weight_gsm' => 290, 'weight_label' => '290 gsm (Mid-Heavy)', 'width_cm' => 152, 'weave' => 'Brushed twill flannel', 'color' => 'Charcoal Melange', 'colorways' => 6, 'swatch_price' => '4.50', 'badge' => 'Bespoke Mill'],
        ],
        [
            'japanese-selvedge-denim-drill', 'TEX-DN-340', 'Japanese Selvedge Denim Drill',
            'Slow-woven on vintage Toyoda shuttle looms with classic red-line ticker selvedge. Deep natural indigo dip with an uncalendered hand.',
            '42.00', 'textiles-cloth', 65, $img['denim'],
            ['ref' => 'DN-340', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Cotton', 'fiber' => 'Japanese Selvedge Cotton', 'origin' => 'Okayama, Japan', 'mill' => 'Kurashiki Loom', 'weight_gsm' => 340, 'weight_label' => '340 gsm (Heavy)', 'width_cm' => 82, 'weave' => 'Shuttle-loom twill', 'color' => 'Natural Indigo', 'colorways' => 2, 'swatch_price' => '3.50'],
        ],
        [
            'irish-handkerchief-linen', 'TEX-LN-135', 'Irish Handkerchief Linen',
            'Featherweight plain weave with a crisp dry hand that relaxes gracefully with each launder. Essential for heirloom shirting.',
            '29.00', 'textiles-cloth', 54, $img['irish'],
            ['ref' => 'LN-135', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Irish Flax', 'fiber' => 'Irish Linen', 'origin' => 'Belfast, Northern Ireland', 'mill' => 'Ulster Guild', 'weight_gsm' => 135, 'weight_label' => '135 gsm (Featherweight)', 'width_cm' => 140, 'weave' => 'Plain weave cambric', 'color' => 'Bleached White', 'colorways' => 5, 'swatch_price' => '3.50'],
        ],
        [
            'heavy-cotton-canvas-14oz', 'TEX-CV-420', 'Heavy Cotton Canvas 14oz',
            'Dense plied-yarn duck weave built for chore jackets, structured carryalls, and atelier aprons. Weather-resistant tight set.',
            '26.00', 'textiles-cloth', 88, $img['canvas'],
            ['ref' => 'CV-420', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Pima Cotton', 'fiber' => 'Raw Cotton', 'origin' => 'Duxbury, USA', 'mill' => 'Duxbury Mill', 'weight_gsm' => 420, 'weight_label' => '420 gsm (Heavyweight)', 'width_cm' => 150, 'weave' => 'Duck weave', 'color' => 'Unbleached', 'colorways' => 3, 'swatch_price' => '3.00'],
        ],
        [
            'mulberry-silk-habotai-lining', 'TEX-SK-065', 'Mulberry Silk Habotai Lining',
            'Weightless, skin-friendly barrier lining offering effortless slip and breathability. Dyed with OEKO-TEX certified low-impact pigments.',
            '24.00', 'textiles-cloth', 31, $img['habotai'],
            ['ref' => 'SK-065', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Grade 6A Silk', 'fiber' => 'Mulberry Silk', 'origin' => 'Zhejiang, China', 'mill' => 'Hangzhou Silk', 'weight_gsm' => 65, 'weight_label' => '65 gsm (Ultra-light)', 'width_cm' => 112, 'weave' => 'Habotai plain weave', 'color' => 'Ivory', 'colorways' => 7, 'swatch_price' => '4.00'],
        ],
        [
            'english-worsted-flannel', 'TEX-WL-370', 'English Wool Worsted Flannel',
            'Yorkshire-spun worsted flannel with a clean selvedge and no torque across the warp. Takes to steam moulding when shaping trouser calves.',
            '72.00', 'textiles-cloth', 26, $img['worsted'],
            ['ref' => 'BLA-340-CH', 'unit' => 'metre', 'unit_label' => 'm', 'composition' => '100% Worsted Wool', 'fiber' => 'Yorkshire Wool', 'origin' => 'Yorkshire, England', 'mill' => 'Yorkshire Mill', 'weight_gsm' => 370, 'weight_label' => '370 gsm (Heavy)', 'width_cm' => 150, 'weave' => 'Worsted flannel twill', 'color' => 'Charcoal Melange', 'colorways' => 4, 'swatch_price' => '4.50'],
        ],
        [
            'gifu-silk-basting-thread', 'HAB-SK-50', 'Gifu Silk Basting & Thread',
            'Kyoto wax-coated basting silk on a 50-metre spool. Zero snag under high-tension hand basting, with a subdued matte luster that recedes into the cloth.',
            '14.50', 'haberdashery', 120, $img['thread'],
            ['ref' => 'KYO-SLK-04', 'unit' => 'spool', 'unit_label' => 'spool', 'composition' => '100% Mulberry Silk', 'fiber' => 'Grade 6A Silk', 'origin' => 'Gifu Prefecture, Japan', 'mill' => 'Gifu Prefecture', 'weight_label' => '50 m spool • Grade 6A', 'color' => 'Natural Waxed', 'colorways' => 3, 'swatch_price' => null, 'badge' => 'Pure Silk'],
        ],
        [
            'matte-buffalo-horn-buttons', 'HAB-HRN-32L', 'Matte Buffalo Horn Buttons',
            'Hand-finished water-buffalo horn in dark mottled 32-ligne, countersunk four-hole. Organic weight with a silky honed matte surface.',
            '28.00', 'haberdashery', 64, $img['horn'],
            ['ref' => 'HRN-32L-MOT', 'unit' => 'set', 'unit_label' => 'set of 14', 'composition' => 'Natural Water Buffalo Horn', 'fiber' => 'Natural Horn', 'origin' => 'Hand-turned', 'mill' => 'Hand-Finished', 'weight_label' => '24L & 32L suit set', 'color' => 'Dark Mottled', 'colorways' => 3, 'swatch_price' => null, 'badge' => 'Hand-Finished'],
        ],
        [
            'sheffield-carbon-steel-shears', 'TOOL-SHR-10', 'Sheffield Carbon Steel Shears',
            'Forged and hand-set in Sheffield from high-carbon steel. A 10-inch dressmaker blade with a hollow-ground bevel that glides through 14oz canvas without lifting the grain.',
            '96.00', 'atelier-tools', 24, $img['tools'],
            ['ref' => 'SHF-SHR-10', 'unit' => 'piece', 'unit_label' => 'each', 'composition' => 'High-carbon steel', 'fiber' => 'Forged steel', 'origin' => 'Sheffield, England', 'mill' => 'Sheffield Bench', 'weight_label' => '10 in • hollow ground', 'color' => 'Black Oxide', 'colorways' => 1, 'swatch_price' => null],
        ],
        [
            'atelier-swatch-binder', 'PAT-SWB-45', 'Atelier Seasonal Swatch Binder',
            'The complete seasonal swatch folio: 48 tactile 10×10cm cloth leaves with fiber provenance notes, shrinkage reports, and a swatch ring. Credited toward your first bolt dispatch.',
            '45.00', 'patterns', 40, $img['binder'],
            ['ref' => 'SWB-2025-F14', 'unit' => 'folio', 'unit_label' => 'folio', 'composition' => '48 seasonal samples', 'fiber' => 'Mixed archive', 'origin' => 'Milano • Kyoto', 'mill' => 'TÈSSILE Archive', 'weight_label' => 'Contains 48 seasonal samples', 'color' => 'Archive Natural', 'colorways' => 1, 'swatch_price' => null, 'badge' => 'Trade Folio'],
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
    // Storefront copy the SPA reads from /api/settings. Upserted: these keys
    // ARE the content this migration owns.
    $settingsSeed = [
        'store.name' => 'TÈSSILE Atelier',
        'store.tagline' => 'Purveyors of master-grade natural textiles, bespoke haberdashery, and artisan sewing tools for ateliers, tailors, and garment makers worldwide.',
        'store.address' => 'Via Manzoni 14, Milano / Kyoto Studio',
        'announcement.enabled' => '1',
        'announcement.text' => 'Complimentary textile swatch cards with international orders above $120 • Worldwide atelier shipping',
        'home.hero.eyebrow' => 'Collection N° 14 — Autumn / Winter',
        'home.hero.heading' => 'Matières Premières pour Ateliers et Couturiers.',
        'home.hero.subheading' => 'Natural unbleached linens, long-staple Japanese selvedge drills, and hand-cast buffalo horn haberdashery calibrated for bespoke tailoring.',
        'home.hero.image' => $img['hero'],
        'home.hero.note' => 'Directly sourced from Biella, Okayama & Belfast',
        'shop.heading' => 'Tissus & Étoffes de Confection',
        'shop.intro' => 'Curated natural yardage selected for drape, tensile strength, and sewing behaviour. Sold by the quarter-metre and complete bolt for tailors, couturiers, and design studios.',
        'correspondence.heading' => 'Seasonal Correspondence',
        'correspondence.body' => 'Receive strictly limited notifications upon seasonal fabric bolt cuts from Biella and Kyoto. No promotional excess, only textile inventories and treatises.',
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
            'title' => 'Atelier Sourcing Philosophy & Custody',
            'slug' => 'about-the-atelier',
            'excerpt' => 'Documenting pure-strain linen, unadulterated high-twist worsteds, and natural-indigo selvedge textiles curated for artisanal hand-construction.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Archival Ledger № 1894–B'],
                ['type' => 'quote', 'text' => 'Founded on the principle that a garment holds only as much dignity as the cloth from which it was cut. We provide unadulterated botanical and animal fibres directly from heritage European and Japanese mills to the bespoke cutting table.'],
                ['type' => 'heading', 'text' => 'I. The Rejection of Synthetic Blends'],
                ['type' => 'paragraph', 'text' => 'Contemporary fast manufacturing has normalised the insidious inclusion of elastane, polyamide and polyester micro-cores into nominally "luxury" cloths. Synthetic threads do not yield to hot iron pressure; they resist molecular reshaping and deaden the tactile sensation of hand-stitched pickwork.'],
                ['type' => 'paragraph', 'text' => 'We decline every textile blend containing synthetic stabiliser yarns. A garment tailored from 100% natural fibres breathes as an organic diaphragm, patinas over half a century, and can be disassembled and composted without depositing enduring micro-plastic residue.'],
                ['type' => 'heading', 'text' => 'II. Mill Direct Provenance'],
                ['type' => 'paragraph', 'text' => 'True quality is territorial. We bypass fabric jobbers and anonymous showroom distributors in favour of multigenerational partnerships with historic mills located where geography and climate foster exceptional weaving.'],
                ['type' => 'list', 'items' => ['Biella, Italy — glacier-filtered mountain water washes spun wool with zero mineral hardness.', 'Belfast & County Down — rain-drenched flax, slow-retted in cold water for tensile indestructibility.', 'Normandy, France — maritime fields yielding master flax since the seventeenth century.', 'Okayama & Kyoto, Japan — shuttle looms weaving narrow-width selvedge cotton at deliberate cadence.']],
                ['type' => 'heading', 'text' => 'III. The Archival Custody Standard'],
                ['type' => 'paragraph', 'text' => 'Every bolt remains suspended horizontally through its centre on neutral pH, acid-free cardboard cores; they never touch a flat surface until the hour of cutting. Our climate-calibrated vaults in Milan and Kyoto hold an unwavering 54% relative humidity at 18°C.'],
                ['type' => 'list', 'items' => ['Synthetics permitted: 0.00% zero tolerance', 'Average mill-direct relationship: 34 years', 'Vault relative humidity: 54% ± 2%']],
            ],
        ],
        [
            'title' => 'Material Guide & Fabric Glossary',
            'slug' => 'fabric-glossary',
            'excerpt' => 'A tactile reference for tailors, couturiers and makers: tensile grainlines, yarn densities and drape mechanics across pure botanical and animal fibres.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Cahier Technique • Rev. 2025/04'],
                ['type' => 'heading', 'text' => '01 Pure Flax & Linens'],
                ['type' => 'paragraph', 'text' => 'Derived from the sclerenchyma fibres of Linum usitatissimum. Linen possesses unparalleled tensile rigidity, breathability and natural antibacterial properties, developing fluid suppleness across decades of laundering.'],
                ['type' => 'paragraph', 'text' => 'Press linen slightly damp with a heavy dry iron against a tailstock, then clamp immediately with an untreated beechwood clapper for 7–10 seconds to set the fold line razor-sharp.'],
                ['type' => 'list', 'items' => ['Handkerchief cambric — 110–140 gsm, 60L–80L, Victorian neckwear and sheer chemises', 'Artisan shirting linen — 150–190 gsm, 35L–50L, popover tunics and band-collar shirts', 'Sartorial suiting linen — 260–340 gsm, 20L–25L, unstructured jackets and pleat trousers']],
                ['type' => 'heading', 'text' => '02 Super Woolens & Worsteds'],
                ['type' => 'paragraph', 'text' => 'Combed worsteds from Biella and Yorkshire carry long parallel fibres that mould under steam and hold a permanent lapel roll. Brushed flannels trade a little recovery for a softer surface and deeper colour.'],
                ['type' => 'heading', 'text' => '03 Raw Cottons & Twills'],
                ['type' => 'paragraph', 'text' => 'Shuttle-loomed selvedge cotton from Okayama is woven at low speed, leaving an organic slub and a self-finished edge. Expect 3–5% shrinkage on first wash; pre-treat before cutting.'],
                ['type' => 'heading', 'text' => '04 Mulberry Silks'],
                ['type' => 'paragraph', 'text' => 'Grade 6A mulberry silk is reeled continuous, giving habotai linings their effortless slip and basting threads their zero-snag tensile behaviour under tension.'],
            ],
        ],
        [
            'title' => 'Shipping, Carriage & Legal Protocol',
            'slug' => 'shipping-returns',
            'excerpt' => 'The artisanal standard governing handling, secure cylindrical packaging, cross-border transit and inspection criteria for cut yardage and uncut master bolts.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Atelier Protocol • Dispatch 2025/Rev.4'],
                ['type' => 'heading', 'text' => '01. Dedicated Bolt Shipping & Cylindrical Packaging'],
                ['type' => 'paragraph', 'text' => 'TÈSSILE Atelier maintains an absolute ban on folding finished textiles into parcels: folding introduces fibre stress, irreversible cross-grain creases and uneven tensile compression.'],
                ['type' => 'paragraph', 'text' => 'Every order exceeding two linear metres is rolled onto rigid, pH-neutral, acid-free recycled pulp cores, wrapped in archival mulberry tissue, and housed in a double-walled cylindrical mailer sealed with water-activated gummed tape.'],
                ['type' => 'list', 'items' => ['White-glove courier — tracked custody from the Milan cutting floor, signature-only release', 'Complimentary tier — courier fees waived on all shipments above $120', 'Zero-crease cores — 100mm diameter, cut goods arrive ready for the cutting table']],
                ['type' => 'heading', 'text' => '02. Customs, Tariffs & International Delivery (DDP)'],
                ['type' => 'paragraph', 'text' => 'All dispatches to the EU, UK, US, Switzerland and Japan are fulfilled Delivered Duty Paid. Clients encounter no unexpected import surcharges, carrier brokerages or local import VAT on arrival; HS codes 5208, 5309 and 5112 are cleared through our Milan logistics desk.'],
                ['type' => 'heading', 'text' => '03. Cut Textile Return Protocol'],
                ['type' => 'paragraph', 'text' => 'Cut yardage is a bespoke measurement and is not returnable once the shears have passed, except where a physical defect is confirmed under lamp inspection. Uncut bolts may be returned within 14 days in their original archival wrap.'],
                ['type' => 'heading', 'text' => '04. Physical Defect & Lamp Inspection'],
                ['type' => 'paragraph', 'text' => 'Every bolt undergoes a four-point inspection under daylight-calibrated lamps before packing. Should a defect reach your table, photograph the selvedge and reference number; we replace or credit the affected yardage in full.'],
            ],
        ],
        [
            'title' => 'Atelier Protocols & FAQ',
            'slug' => 'faq',
            'excerpt' => 'Precise guidelines on yardage cuts, continuous bolt delivery, sample swatch binders and trade allocations.',
            'body' => [
                ['type' => 'eyebrow', 'text' => 'Client Concierge • Rev. 2025.04'],
                ['type' => 'heading', 'text' => 'Can yardage be delivered as a continuous cut without mid-bolt splicing?'],
                ['type' => 'paragraph', 'text' => 'Every cut up to 25 metres is guaranteed continuous from a single loom run. If a bolt requires a structural join, our cutting master will reach out before the shears touch the cloth. We add a complimentary 3cm cutting margin for every 3-metre cut to account for selvedge alignment during blocking.'],
                ['type' => 'heading', 'text' => 'How are sensitive woolens and silks packaged for international transit?'],
                ['type' => 'paragraph', 'text' => 'We never fold suitings into sharp squares. Orders above 2.0m travel rolled on heavy 120mm non-acidic cores, sealed in water-repellent unbleached wax wrapping with a washi inner interleaf.'],
                ['type' => 'heading', 'text' => 'How does the Trade Guild allowance work?'],
                ['type' => 'paragraph', 'text' => 'Registered ateliers and couture houses receive dedicated yardage allowances, bespoke bolt reservations and a direct line to our cutting leads. Tiered invoicing starts at 50 aggregate metres annually.'],
                ['type' => 'heading', 'text' => 'What is the pre-washing and shrinkage protocol for pure flax?'],
                ['type' => 'paragraph', 'text' => 'Residual warp shrinkage on pure French flax measures under 1.8%. For garment work, sponge and press the cloth before cutting; for household yardage, a cold wash followed by a line dry is sufficient.'],
                ['type' => 'heading', 'text' => 'Can I order a physical swatch before committing to a bolt?'],
                ['type' => 'paragraph', 'text' => 'Yes. Every cloth page offers a 10×10cm swatch for $3.00–$4.50, credited in full against your first bolt of the same reference. The seasonal binder carries all 48 archive leaves.'],
            ],
        ],
    ];

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
