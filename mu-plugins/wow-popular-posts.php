<?php
/**
 * Plugin Name: WOW Popular Posts
 * Description: Tracks post views and displays popular posts in sidebar.
 *
 * Perf: view logging is a single bounded write on single-post views only, and the
 * "popular posts" aggregate is cached rather than recomputed on every request.
 */

// Track post views (single posts only).
add_action('wp', function () {
    if (is_admin() || !is_singular('post')) return;

    global $post;
    $count = (int) get_post_meta($post->ID, '_wow_view_count', true);
    update_post_meta($post->ID, '_wow_view_count', $count + 1);
});

/**
 * Popular posts, cached. Nothing calls this during a normal page render, so the
 * aggregate no longer runs on wp_loaded for every visitor.
 */
function wow_get_popular_posts() {
    $cached = get_transient('wow_popular_posts');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    $popular = $wpdb->get_results("
        SELECT p.ID, p.post_title, CAST(pm.meta_value AS UNSIGNED) AS views
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE pm.meta_key = '_wow_view_count'
          AND p.post_status = 'publish'
          AND p.post_type = 'post'
        ORDER BY views DESC
        LIMIT 10
    ");

    set_transient('wow_popular_posts', $popular, HOUR_IN_SECONDS);

    return $popular;
}
