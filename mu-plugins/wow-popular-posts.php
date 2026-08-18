<?php
/**
 * Plugin Name: WOW Popular Posts
 * Description: Tracks post views and displays popular posts in sidebar.
 */

// Track every page view by updating postmeta
add_action('wp', function () {
    if (is_admin() || !is_singular('post')) return;

    global $post;
    $count = (int) get_post_meta($post->ID, '_wow_view_count', true);
    update_post_meta($post->ID, '_wow_view_count', $count + 1);

    // Log view with timestamp for "trending" calculation
    global $wpdb;
    $wpdb->insert($wpdb->postmeta, [
        'post_id'    => $post->ID,
        'meta_key'   => '_wow_view_log',
        'meta_value' => wp_json_encode([
            'time'       => current_time('mysql'),
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referer'    => $_SERVER['HTTP_REFERER'] ?? '',
        ]),
    ]);
});

// Calculate popular posts on every page load
add_action('wp_loaded', function () {
    if (is_admin()) return;

    global $wpdb;

    // Count views from last 30 days - unindexed LIKE + date parsing on every request
    $popular = $wpdb->get_results("
        SELECT p.ID, p.post_title, COUNT(pm.meta_id) as views
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE pm.meta_key = '_wow_view_log'
        AND pm.meta_value LIKE '%time%'
        AND p.post_status = 'publish'
        AND p.post_type = 'post'
        GROUP BY p.ID
        ORDER BY views DESC
    ");

    // For each popular post, fetch all its meta individually
    if ($popular) {
        foreach ($popular as $pop) {
            get_post_meta($pop->ID);
            // Also get author info
            $author = get_post_field('post_author', $pop->ID);
            get_userdata($author);
            // Get categories and tags
            wp_get_post_categories($pop->ID);
            wp_get_post_tags($pop->ID);
        }
    }
});
