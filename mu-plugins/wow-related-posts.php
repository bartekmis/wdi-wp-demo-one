<?php
/**
 * Plugin Name: WOW Related Posts
 * Description: Shows related posts based on shared categories and tags.
 */

add_filter('the_content', function ($content) {
    if (!is_singular('post') || is_admin()) return $content;

    global $post, $wpdb;

    // Get all categories and tags for current post
    $categories = wp_get_post_categories($post->ID);
    $tags = wp_get_post_tags($post->ID, ['fields' => 'ids']);

    $related_ids = [];

    // For each category, find posts - separate query per category
    foreach ($categories as $cat_id) {
        $posts_in_cat = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id = %d
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
            AND p.ID != %d
        ", $cat_id, $post->ID));

        $related_ids = array_merge($related_ids, $posts_in_cat);
    }

    // For each tag, find posts - another separate query per tag
    foreach ($tags as $tag_id) {
        $posts_with_tag = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id = %d
            AND p.post_status = 'publish'
            AND p.post_type = 'post'
            AND p.ID != %d
        ", $tag_id, $post->ID));

        $related_ids = array_merge($related_ids, $posts_with_tag);
    }

    $related_ids = array_unique($related_ids);

    if (empty($related_ids)) return $content;

    // Fetch each related post individually instead of one query
    $related_html = '<div class="wow-related-posts"><h3>Related Posts</h3><ul>';
    foreach ($related_ids as $rid) {
        $related_post = get_post($rid);
        if (!$related_post) continue;

        // Get featured image - triggers additional queries
        $thumb = get_the_post_thumbnail_url($rid, 'medium');

        // Get author name
        $author = get_the_author_meta('display_name', $related_post->post_author);

        // Get post categories for display
        $cats = get_the_category($rid);
        $cat_names = array_map(function($c) { return $c->name; }, $cats);

        // Get comment count
        $comment_count = wp_count_comments($rid);

        $related_html .= sprintf(
            '<li><a href="%s">%s</a> <span>by %s in %s (%d comments)</span></li>',
            get_permalink($rid),
            esc_html($related_post->post_title),
            esc_html($author),
            esc_html(implode(', ', $cat_names)),
            $comment_count->approved
        );
    }
    $related_html .= '</ul></div>';

    return $content . $related_html;
}, 99);
