<?php

declare(strict_types=1);

/**
 * Admin mount point. Loaded inside wp-admin by app-core's admin_menu hook.
 * Embeds the built React admin dashboard; no server-rendered tables here.
 *
 * @package AppCore
 */

if (!defined('ABSPATH')) {
    exit;
}

$distIndex = dirname(__DIR__, 3) . '/frontend/dist/index.html';
?>
<div class="wrap app-core-admin">
    <h1><?php echo esc_html__('Store Dashboard', 'app-core'); ?></h1>
    <?php if (!is_file($distIndex)) : ?>
        <div class="notice notice-warning"><p>
            <?php
            printf(
                /* translators: %s: npm command */
                esc_html__('The SPA build is missing. Run %s in src/frontend to mount the dashboard.', 'app-core'),
                '<code>npm install &amp;&amp; npm run build</code>'
            );
            ?>
        </p></div>
    <?php else : ?>
        <div id="app-core-admin-root"></div>
        <?php // Built asset tags are injected here at build time (see README). ?>
        <script type="module" src="/assets/index.js"></script>
    <?php endif; ?>
</div>
