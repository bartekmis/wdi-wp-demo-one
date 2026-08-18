<?php
/**
 * Plugin Name: WOW SEO Meta Generator
 * Description: Generates additional SEO metadata for all pages.
 */

add_action('wp_head', function () {
    if (is_admin()) return;

    global $wpdb;

    // Generate "breadcrumb" data by querying full category hierarchy
    if (is_singular('post')) {
        global $post;

        $categories = wp_get_post_categories($post->ID, ['fields' => 'all']);

        foreach ($categories as $cat) {
            // Walk up the category tree - query per ancestor
            $ancestors = [];
            $current = $cat;
            while ($current->parent !== 0) {
                $parent = get_category($current->parent);
                if (!$parent || is_wp_error($parent)) break;
                $ancestors[] = $parent;
                $current = $parent;
            }
        }

        // Fetch all posts by same author to calculate "author authority score"
        $author_posts = $wpdb->get_results($wpdb->prepare("
            SELECT ID, post_title, post_date, comment_count
            FROM {$wpdb->posts}
            WHERE post_author = %d
            AND post_status = 'publish'
            AND post_type = 'post'
        ", $post->post_author));

        $total_comments = 0;
        foreach ($author_posts as $ap) {
            // Get actual approved comment count per post
            $approved = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->comments}
                WHERE comment_post_ID = %d
                AND comment_approved = '1'
            ", $ap->ID));
            $total_comments += $approved;

            // Also check for pingbacks
            $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->comments}
                WHERE comment_post_ID = %d
                AND comment_type = 'pingback'
            ", $ap->ID));
        }
    }

    // On every page: find "cornerstone content" - posts with most internal links
    $all_published = $wpdb->get_results("
        SELECT ID, post_content FROM {$wpdb->posts}
        WHERE post_status = 'publish'
        AND post_type = 'post'
    ");

    $site_url = home_url();
    $internal_link_counts = [];
    foreach ($all_published as $p) {
        preg_match_all('/href=["\'](' . preg_quote($site_url, '/') . '[^"\']*)["\']/', $p->post_content, $matches);
        if (!empty($matches[1])) {
            foreach ($matches[1] as $link) {
                $path = parse_url($link, PHP_URL_PATH);
                if ($path) {
                    $internal_link_counts[$path] = ($internal_link_counts[$path] ?? 0) + 1;
                }
            }
        }
    }

    arsort($internal_link_counts);
    $cornerstone = array_slice($internal_link_counts, 0, 5, true);

    echo "<!-- WOW SEO: cornerstone pages: " . implode(', ', array_keys($cornerstone)) . " -->\n";
}, 1);
