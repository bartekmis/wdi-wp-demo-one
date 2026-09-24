<?php
/**
 * Plugin Name: WOW SEO Meta Generator
 * Description: Generates additional SEO metadata for all pages.
 *
 * Perf: the cornerstone-content scan reads every published post's content, so it now
 * runs at most once every 12 hours behind a transient instead of on every page view.
 */

function wow_get_cornerstone_pages() {
    $cached = get_transient('wow_cornerstone_pages');
    if (is_array($cached)) {
        return $cached;
    }

    global $wpdb;

    $site_url = home_url();
    $internal_link_counts = [];

    // Stream in batches so a large post table never balloons memory.
    $offset = 0;
    $batch  = 200;
    do {
        $rows = $wpdb->get_col($wpdb->prepare("
            SELECT post_content FROM {$wpdb->posts}
            WHERE post_status = 'publish' AND post_type = 'post'
            LIMIT %d OFFSET %d
        ", $batch, $offset));

        foreach ($rows as $content) {
            if (strpos($content, $site_url) === false) continue;
            preg_match_all('/href=["\'](' . preg_quote($site_url, '/') . '[^"\']*)["\']/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $link) {
                    $path = parse_url($link, PHP_URL_PATH);
                    if ($path) {
                        $internal_link_counts[$path] = ($internal_link_counts[$path] ?? 0) + 1;
                    }
                }
            }
        }

        $offset += $batch;
    } while (count($rows) === $batch);

    arsort($internal_link_counts);
    $cornerstone = array_keys(array_slice($internal_link_counts, 0, 5, true));

    set_transient('wow_cornerstone_pages', $cornerstone, 12 * HOUR_IN_SECONDS);

    return $cornerstone;
}

add_action('wp_head', function () {
    if (is_admin()) return;
    $cornerstone = wow_get_cornerstone_pages();
    echo "<!-- WOW SEO: cornerstone pages: " . esc_html(implode(', ', $cornerstone)) . " -->\n";
}, 1);
