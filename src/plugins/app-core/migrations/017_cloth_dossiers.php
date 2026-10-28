<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 017_cloth_dossiers — tailoring and care dossiers for the cloth catalogue.
 *
 * The product detail page presents a technical dossier (recommended needle and
 * thread, interfacing, grainline discipline, washing and pressing protocol).
 * That is catalogue content, not presentation, so it lives in
 * products.metadata alongside the fibre facts.
 *
 * Additive by design: a product whose metadata already carries a `dossier` is
 * left untouched, so an editor's changes survive a re-run, and 016's brighter
 * seed data wins on a fresh install.
 */
return static function (PDO $pdo): void {
    $products = Db::table('products');

    $dossiers = [
        'normandy-washed-heavy-linen' => [
            'construction' => [
                'needle' => 'Schmetz Universal 80/12 for shirting, 90/14 for structured suiting jackets.',
                'thread' => 'Gütermann Mara 70, or TÈSSILE filament silk 50m for bespoke pick stitching.',
                'interfacing' => 'Horsehair or flax weft — a light wool-canvas floating chest piece.',
                'notes' => 'Cut every panel strictly on the straight grain with freshly honed shears at 20°. Minimum 1.5 cm seam allowances, finished with Hong Kong bias binding in 8mm habotai silk.',
            ],
            'care' => [
                'launder' => 'Machine wash cold at 30°C on a delicate cycle with a mild pH-neutral textile wash. No chlorine, no optical brighteners, spin under 600 RPM.',
                'dry' => 'Never tumble dry. Snap the wet cloth lengthwise and dry suspended horizontally in shade.',
                'press' => 'Press while the fibres remain slightly damp at high linen temperature, then clamp immediately with an untreated beechwood clapper to lock knife-edge creases.',
            ],
            'stability' => 'Aero-washed and tumbler softened; shrinkage < 1.8% warp / < 1.2% weft.',
        ],
        'washed-belgian-linen-slub' => [
            'construction' => [
                'needle' => 'Schmetz Universal 80/12; a 70/10 microtex for the fine slub.',
                'thread' => 'TÈSSILE filament silk or a 50-weight cotton-wrapped polyester.',
                'interfacing' => 'Soft flax weft insertion for unstructured fronts.',
                'notes' => 'The slub is deliberate; do not press the weave flat. Cut single-layer and pin sparingly to avoid needle bruising.',
            ],
            'care' => [
                'launder' => 'Cold wash at 30°C, gentle cycle, no softener.',
                'dry' => 'Line dry in shade to preserve the enzyme-washed hand.',
                'press' => 'Medium steam from the reverse, clapper on the fold line.',
            ],
        ],
        'biella-super-120s-wool-flannel' => [
            'construction' => [
                'needle' => 'Schmetz Universal 90/14.',
                'thread' => 'Gütermann Mara 70 in a matched charcoal.',
                'interfacing' => 'Light wool-canvas floating chest piece with a horsehair lapel roll.',
                'notes' => 'Sponge and press before cutting. Always cut the same direction from the face to avoid shading across panels.',
            ],
            'care' => [
                'launder' => 'Dry clean only; brush with a soft bristle after wear.',
                'dry' => 'Hang on a broad wooden hanger to recover body between wears.',
                'press' => 'Steam from the reverse with a pressing cloth; never press the pile flat.',
            ],
        ],
        'japanese-selvedge-denim-drill' => [
            'construction' => [
                'needle' => 'Schmetz Denim 100/16 for flat-felled seams.',
                'thread' => 'Heavy cotton-wrapped core thread, 40/3.',
                'interfacing' => 'None required — the 340 gsm body is self-supporting.',
                'notes' => 'Pre-shrink before cutting. Grade every seam to reduce bulk and use a hump-jumper over the selvedge crossovers.',
            ],
            'care' => [
                'launder' => 'Cold soak the first time to set indigo; wash rarely and inside out.',
                'dry' => 'Hang dry away from direct sun to preserve the natural dip.',
                'press' => 'Steam the reverse only; the raw face should keep its uncalendered texture.',
            ],
        ],
        'irish-handkerchief-linen' => [
            'construction' => [
                'needle' => 'Schmetz Universal 70/10.',
                'thread' => 'Fine 60-weight cotton.',
                'interfacing' => 'None for handkerchief work; a light fusible collar canvas for shirting.',
                'notes' => 'French seams throughout. Press dry with a hot iron and a clapper for a crisp, heirloom fold.',
            ],
            'care' => [
                'launder' => 'Hot wash is welcome — this cambric whitens with use.',
                'dry' => 'Line dry and press damp.',
                'press' => 'High heat, dry or slightly damp, with a beechwood clapper.',
            ],
        ],
        'heavy-cotton-canvas-14oz' => [
            'construction' => [
                'needle' => 'Schmetz Denim 110/18.',
                'thread' => 'Heavy bonded nylon or cotton 40/3.',
                'interfacing' => 'None; use a double-layer yoke instead of fusible.',
                'notes' => 'Preshrunk but expect 3% movement. Punch hardware holes before setting rivets to avoid yarn shear.',
            ],
            'care' => [
                'launder' => 'Machine wash warm, no bleach.',
                'dry' => 'Tumble dry low to break the duck weave in.',
                'press' => 'Hot iron with steam from the reverse.',
            ],
        ],
        'mulberry-silk-habotai-lining' => [
            'construction' => [
                'needle' => 'Schmetz Microtex 60/8.',
                'thread' => 'Fine silk or 100-weight cotton for a flat seam.',
                'interfacing' => 'None — this cloth is the inner layer.',
                'notes' => 'Cut 1 cm short in the body to allow the outer cloth to move. Use a walking foot; silk shifts under a standard feed dog.',
            ],
            'care' => [
                'launder' => 'Hand wash cool with a silk-specific detergent, or dry clean.',
                'dry' => 'Dry flat in shade — never wring.',
                'press' => 'Cool iron from the reverse; a press cloth is mandatory.',
            ],
        ],
        'english-worsted-flannel' => [
            'construction' => [
                'needle' => 'Schmetz Universal 90/14.',
                'thread' => 'Gütermann Mara 70.',
                'interfacing' => 'Horsehair canvas with a linen stay tape on the roll line.',
                'notes' => 'Clean selvedge with no warp torque — cut to the grain and shrink fullness into the chest with a heavy iron and a tailor’s ham.',
            ],
            'care' => [
                'launder' => 'Dry clean only.',
                'dry' => 'Rest on a shaped hanger for 24 hours after wear.',
                'press' => 'Steam mould the trouser calf and lapel from the reverse over a ham.',
            ],
        ],
        'gifu-silk-basting-thread' => [
            'construction' => [
                'thread' => 'Wax-coated 6A grade silk, 50 m spool.',
                'notes' => 'Baste with a 6–8 mm stitch length; the beeswax coating holds tension through the cloth and pulls cleanly at the final fitting.',
            ],
            'care' => ['launder' => 'Remove basting stitches before cleaning.', 'press' => 'The thread tolerates steam; do not iron directly onto the wax.'],
        ],
        'matte-buffalo-horn-buttons' => [
            'construction' => [
                'needle' => 'Buttonhole twist needle 100/16.',
                'thread' => 'Silk buttonhole twist, waxed.',
                'notes' => 'Countersunk holes seat the thread flush. Always back the shank with a stay button.',
            ],
            'care' => ['notes' => 'Buff with a dry cloth; avoid alcohol-based cleaners on the horn surface.'],
        ],
    ];

    $select = $pdo->prepare('SELECT id, metadata FROM ' . $products . ' WHERE slug = ? LIMIT 1');
    $update = $pdo->prepare('UPDATE ' . $products . ' SET metadata = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?');

    foreach ($dossiers as $slug => $dossier) {
        $select->execute([$slug]);
        $row = $select->fetch();
        if ($row === false) {
            continue;
        }
        $metadata = $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : [];
        if (!\is_array($metadata)) {
            $metadata = [];
        }
        if (isset($metadata['dossier'])) {
            continue;
        }
        $metadata['dossier'] = $dossier;
        $update->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $row['id']]);
    }
};
