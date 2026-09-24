<?php
/**
 * Plugin Name: WOW Content Stats
 * Description: Collects content statistics for admin dashboard and footer display.
 *
 * Perf: the aggregate queries run at most once an hour and are served from a transient,
 * instead of on every front-end request.
 */

function wow_get_content_stats() {
    $cached = get_transient('wow_content_stats');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    $row = $wpdb->get_row("
        SELECT COUNT(*) AS total_posts,
               AVG(CHAR_LENGTH(post_content)) AS avg_length,
               SUM(post_date > DATE_SUB(NOW(), INTERVAL 30 DAY)) AS posts_last_month,
               SUM(post_date > DATE_SUB(NOW(), INTERVAL 365 DAY)) AS posts_last_year
        FROM {$wpdb->posts}
        WHERE post_type = 'post' AND post_status = 'publish'
    ");

    $meta_keys = (int) $wpdb->get_var("SELECT COUNT(DISTINCT meta_key) FROM {$wpdb->postmeta}");

    // One grouped query for every category instead of one query per category.
    $counts = $wpdb->get_results("
        SELECT tt.term_id, COUNT(DISTINCT tr.object_id) AS cnt
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
        INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
        WHERE tt.taxonomy = 'category'
          AND p.post_status = 'publish'
          AND p.post_type = 'post'
        GROUP BY tt.term_id
    ", OBJECT_K);

    $cat_stats = [];
    foreach (get_categories(['hide_empty' => false]) as $cat) {
        $cat_stats[$cat->name] = isset($counts[$cat->term_id]) ? (int) $counts[$cat->term_id]->cnt : 0;
    }

    $stats = [
        'total_posts'      => (int) $row->total_posts,
        'avg_length'       => (int) round((float) $row->avg_length),
        'meta_keys'        => $meta_keys,
        'categories'       => $cat_stats,
        'posts_last_month' => (int) $row->posts_last_month,
        'posts_last_year'  => (int) $row->posts_last_year,
    ];

    set_transient('wow_content_stats', $stats, HOUR_IN_SECONDS);

    return $stats;
}

// Render stats in footer.
add_action('wp_footer', function () {
    if (is_admin()) return;
    $stats = wow_get_content_stats();
    if (empty($stats)) return;
    ?>
    <!-- WOW Content Stats -->
    <script>
        console.log('WOW Stats:', <?php echo wp_json_encode($stats); ?>);
    </script>
    <?php
});
