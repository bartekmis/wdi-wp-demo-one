<?php
/**
 * Plugin Name: WOW Related Posts
 * Description: Shows related posts based on shared categories and tags.
 *
 * Perf: the previous version collected every post sharing a category (625 of them on a
 * typical post) and then ran get_post + thumbnail + author + categories + wp_count_comments
 * for each, which took ~6.8s per uncached request. It now resolves the most relevant posts
 * in one query, primes their caches in a single batch, and caches the rendered markup.
 */

define('WOW_RELATED_LIMIT', 8);

function wow_related_posts_html($post_id) {
    $cache_key = 'wow_related_' . $post_id;
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    global $wpdb;

    $term_ids = array_merge(
        wp_get_post_categories($post_id),
        wp_get_post_tags($post_id, ['fields' => 'ids'])
    );
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));

    if (empty($term_ids)) {
        set_transient($cache_key, '', HOUR_IN_SECONDS);
        return '';
    }

    $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
    $params = array_merge($term_ids, [$post_id, WOW_RELATED_LIMIT]);

    // Rank by how many terms each candidate shares, and only take the top few.
    $related_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         WHERE tt.term_id IN ($placeholders)
           AND p.post_status = 'publish'
           AND p.post_type = 'post'
           AND p.ID != %d
         GROUP BY p.ID
         ORDER BY COUNT(DISTINCT tt.term_id) DESC, p.post_date DESC
         LIMIT %d",
        $params
    ));

    if (empty($related_ids)) {
        set_transient($cache_key, '', HOUR_IN_SECONDS);
        return '';
    }

    // One batched fetch primes the post, meta and term caches for all of them.
    $related_posts = get_posts([
        'post__in'               => $related_ids,
        'orderby'                => 'post__in',
        'posts_per_page'         => count($related_ids),
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
    ]);

    if (empty($related_posts)) {
        set_transient($cache_key, '', HOUR_IN_SECONDS);
        return '';
    }

    $author_ids = array_unique(wp_list_pluck($related_posts, 'post_author'));
    if ($author_ids) {
        cache_users($author_ids);
    }

    $html = '<div class="wow-related-posts"><h3>Related Posts</h3><ul>';
    foreach ($related_posts as $rp) {
        $author = get_the_author_meta('display_name', $rp->post_author);

        $cat_names = [];
        foreach (get_the_category($rp->ID) as $c) {
            $cat_names[] = $c->name;
        }

        $html .= sprintf(
            '<li><a href="%s">%s</a> <span>by %s in %s (%d comments)</span></li>',
            get_permalink($rp->ID),
            esc_html($rp->post_title),
            esc_html($author),
            esc_html(implode(', ', $cat_names)),
            // comment_count already lives on the post row; wp_count_comments was a query each.
            (int) $rp->comment_count
        );
    }
    $html .= '</ul></div>';

    set_transient($cache_key, $html, HOUR_IN_SECONDS);

    return $html;
}

add_filter('the_content', function ($content) {
    if (!is_singular('post') || is_admin() || !in_the_loop() || !is_main_query()) {
        return $content;
    }

    global $post;
    if (!$post) return $content;

    return $content . wow_related_posts_html($post->ID);
}, 99);

// Related lists go stale when posts or their terms change.
add_action('save_post_post', function ($post_id) {
    delete_transient('wow_related_' . $post_id);
});
