<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Env;

/**
 * 018_appraisal_ledger — trainer appraisals for the textile catalogue.
 *
 * The review ledger is only meaningful with cloth in it: POST /api/reviews is
 * gated to verified purchasers, so the demonstration appraisals are written
 * here with is_approved = TRUE. The reviewer accounts they need get the shared
 * demo password ONLY when DEMO_ACCOUNTS=1 (local development); otherwise they
 * receive a random hash, so the rows are inert in any deployed environment.
 *
 * Idempotent: a product that already carries an approved review is skipped.
 */
return static function (PDO $pdo): void {
    $users = Db::table('users');
    $reviews = Db::table('reviews');
    $products = Db::table('products');

    $demoPassword = Env::get('DEMO_ACCOUNTS') === '1'
        ? 'customer-pass-123'
        : Auth::randomToken(24);

    $appraisals = [
        [
            'email' => 'h.vonessen@tailors-zurich.test',
            'name' => 'H. von Essen',
            'slug' => 'normandy-washed-heavy-linen',
            'rating' => 5,
            'title' => '280 gsm holds a sculptured lapel roll',
            'body' => 'The heft of this 280 gsm linen is extraordinary. It resists the flimsy, paper-thin wrinkling typical of commercial shirting linen and instead folds with a sculptured, rolling drape. It pressed out like a dream under the beechwood clapper.',
        ],
        [
            'email' => 'alasdair@saverow.test',
            'name' => 'Alasdair Campbell',
            'slug' => 'english-worsted-flannel',
            'rating' => 5,
            'title' => 'Clean selvedge, no torque across the warp',
            'body' => 'Took to steam moulding effortlessly when shaping the trouser calves. Paired with the matching silk basting thread the resulting garment holds structural authority even in humid weather.',
        ],
        [
            'email' => 'claire.dupont@couture-lyon.test',
            'name' => 'Claire Dupond',
            'slug' => 'gifu-silk-basting-thread',
            'rating' => 5,
            'title' => 'The wax coating does not fray under tension',
            'body' => 'Does not split under high-tension hand basting, and the sheen recedes into the cloth rather than glowing synthetically. Basting pulls clean at the final fitting without leaving a mark.',
        ],
        [
            'email' => 'mateo.rossi@bespoke-milano.test',
            'name' => 'Mateo Rossi',
            'slug' => 'matte-buffalo-horn-buttons',
            'rating' => 4,
            'title' => 'Substantial organic weight',
            'body' => 'The natural rim carving lets the buttonhole twist sit flush against the horn without fraying during long wear. Two of the set had a slightly warmer mottle than the rest, which the client liked and I did not.',
        ],
        [
            'email' => 'julian.vance@patron.test',
            'name' => 'Julian Vance',
            'slug' => 'biella-super-120s-wool-flannel',
            'rating' => 5,
            'title' => 'Superb recovery after a season of wear',
            'body' => 'Brushed surface has softened exactly as promised and the body has not collapsed at the elbows. Ordered a second length for a matching waistcoat.',
        ],
    ];

    $findUser = $pdo->prepare('SELECT id FROM ' . $users . ' WHERE email = ? LIMIT 1');
    $insertUser = $pdo->prepare(
        'INSERT INTO ' . $users . ' (email, password_hash, full_name, role, created_at, updated_at)
         VALUES (?, ?, ?, \'customer\', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
    );
    $findProduct = $pdo->prepare('SELECT id FROM ' . $products . ' WHERE slug = ? LIMIT 1');
    $hasReview = $pdo->prepare('SELECT COUNT(*) FROM ' . $reviews . ' WHERE product_id = ? AND is_approved = TRUE');
    $insertReview = $pdo->prepare(
        'INSERT INTO ' . $reviews . ' (product_id, user_id, rating, title, body, is_verified_purchase, is_approved, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, TRUE, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
    );

    foreach ($appraisals as $row) {
        $findProduct->execute([$row['slug']]);
        $productId = $findProduct->fetchColumn();
        if ($productId === false) {
            continue;
        }
        $productId = (int) $productId;

        $hasReview->execute([$productId]);
        if ((int) $hasReview->fetchColumn() > 0) {
            continue;
        }

        $findUser->execute([$row['email']]);
        $userId = $findUser->fetchColumn();
        if ($userId === false) {
            $insertUser->execute([$row['email'], Auth::hashPassword($demoPassword), $row['name']]);
            $userId = (int) $pdo->lastInsertId();
        }

        $insertReview->execute([$productId, (int) $userId, $row['rating'], $row['title'], $row['body']]);
    }
};
