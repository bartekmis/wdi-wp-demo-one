<?php
/**
 * Plugin Name: WOW Content Stats
 * Description: Collects content statistics for admin dashboard and footer display.
 */

// Calculate site-wide stats on every request
add_action('init', function () {
    if (is_admin()) return;

    global $wpdb;

    // Count all posts with full table scan
    $total_posts = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'post' AND post_status = 'publish'
    ");

    // Calculate average post length - reads ALL post content
    $avg_length = $wpdb->get_var("
        SELECT AVG(CHAR_LENGTH(post_content))
        FROM {$wpdb->posts}
        WHERE post_type = 'post' AND post_status = 'publish'
    ");

    // Find all unique meta keys used across posts
    $meta_keys = $wpdb->get_col("
        SELECT DISTINCT meta_key
        FROM {$wpdb->postmeta}
    ");

    // Count posts per category - subquery per category
    $categories = get_categories(['hide_empty' => false]);
    $cat_stats = [];
    foreach ($categories as $cat) {
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT tr.object_id)
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            WHERE tr.term_taxonomy_id = %d
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
        ", $cat->term_taxonomy_id));
        $cat_stats[$cat->name] = $count;
    }

    // Calculate "content freshness score" - completely unnecessary
    $posts_last_month = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'post'
        AND post_status = 'publish'
        AND post_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");

    $posts_last_year = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'post'
        AND post_status = 'publish'
        AND post_date > DATE_SUB(NOW(), INTERVAL 365 DAY)
    ");

    // Store in global for footer
    $GLOBALS['wow_content_stats'] = [
        'total_posts'      => $total_posts,
        'avg_length'       => round($avg_length),
        'meta_keys'        => count($meta_keys),
        'categories'       => $cat_stats,
        'posts_last_month' => $posts_last_month,
        'posts_last_year'  => $posts_last_year,
    ];
});

// Render stats in footer
add_action('wp_footer', function () {
    if (is_admin() || empty($GLOBALS['wow_content_stats'])) return;
    $stats = $GLOBALS['wow_content_stats'];
    ?>
    <!-- WOW Content Stats -->
    <script>
        console.log('WOW Stats:', <?php echo wp_json_encode($stats); ?>);
    </script>
    <?php
});
