<?php
/**
 * Neutralisation stub.
 *
 * The API layer pre-defines ABSPATH to this directory before requiring
 * wp-config.php, so WordPress's final `require_once ABSPATH . 'wp-settings.php'`
 * resolves HERE instead of into wp-includes. Intentionally empty: nothing from
 * WordPress is allowed to boot in the API request path.
 */
