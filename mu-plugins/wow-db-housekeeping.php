<?php
/**
 * Plugin Name: WOW DB Housekeeping
 * Description: One-time, versioned database clean-ups that keep the autoloaded options
 *              small. Every request loads all autoloaded options, and this site had ~2.5MB
 *              of them (89 unused bloat_option_* rows plus the partner feed's raw JSON).
 */

defined('ABSPATH') || exit;

define('WOW_HOUSEKEEPING_VERSION', 1);

add_action('init', function () {
    if ((int) get_option('wow_housekeeping_version', 0) >= WOW_HOUSEKEEPING_VERSION) return;

    global $wpdb;

    // Nothing reads these, but keep the data: just stop loading it on every request.
    $wpdb->query("
        UPDATE {$wpdb->options} SET autoload = 'off'
        WHERE option_name LIKE 'bloat\\_option\\_%'
    ");

    // Raw API payloads from the old partner feed. The feed now stores a small
    // render-ready payload in wow_partner_feed_items, so these are no longer read.
    $wpdb->query("
        UPDATE {$wpdb->options} SET autoload = 'off'
        WHERE option_name LIKE 'wow\\_partner\\_post\\_%'
           OR option_name LIKE 'wow\\_partner\\_media\\_%'
           OR option_name = 'wow_partner_feed_cache'
    ");

    wp_cache_delete('alloptions', 'options');
    update_option('wow_housekeeping_version', WOW_HOUSEKEEPING_VERSION, false);
});
