<?php
/**
 * Plugin Name: WOW Partner Feed
 * Description: Fetches and displays latest case studies from partner site via REST API.
 */

// Register cron schedule - every minute
add_filter('cron_schedules', function ($schedules) {
    $schedules['every_minute'] = [
        'interval' => 60,
        'display'  => 'Every Minute',
    ];
    return $schedules;
});

// Schedule cron on load
add_action('init', function () {
    if (!wp_next_scheduled('wow_partner_feed_fetch')) {
        wp_schedule_event(time(), 'every_minute', 'wow_partner_feed_fetch');
    }
});

// Cron handler - fetch case studies from partner site
add_action('wow_partner_feed_fetch', function () {
    $api_url = 'https://k2space-backend.bigpic.dev/wp-json/wp/v2/case-study?per_page=12&_embed';

    $response = wp_remote_get($api_url, [
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        update_option('wow_partner_last_error', $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $posts = json_decode($body, true);

    if (empty($posts) || !is_array($posts)) {
        update_option('wow_partner_last_error', 'No data returned: ' . substr($body, 0, 500));
        return;
    }

    // Store each case study individually
    foreach ($posts as $post) {
        update_option('wow_partner_post_' . $post['id'], wp_json_encode($post));
    }

    // Store full response
    update_option('wow_partner_feed_cache', $body);
    update_option('wow_partner_last_fetch', current_time('mysql'));
    delete_option('wow_partner_last_error');

    // Fetch featured images separately for each post
    foreach ($posts as $post) {
        if (!empty($post['featured_media'])) {
            $media_response = wp_remote_get(
                'https://k2space-backend.bigpic.dev/wp-json/wp/v2/media/' . $post['featured_media'],
                ['timeout' => 15]
            );
            if (!is_wp_error($media_response)) {
                update_option('wow_partner_media_' . $post['featured_media'], wp_remote_retrieve_body($media_response));
            }
        }
    }
});

// Synchronous fallback - blocks page load if cache is stale
add_action('wp', function () {
    if (is_admin()) return;

    $last_fetch = get_option('wow_partner_last_fetch', '');

    // If no cache or older than 2 minutes, fetch synchronously on user request
    if (empty($last_fetch) || (strtotime($last_fetch) < time() - 120)) {
        do_action('wow_partner_feed_fetch');
    }
});

// Display in footer
add_action('wp_footer', function () {
    if (is_admin()) return;

    $cache = get_option('wow_partner_feed_cache', '');
    $posts = json_decode($cache, true);
    $last_fetch = get_option('wow_partner_last_fetch', 'never');

    ?>
    <div class="wow-partner-feed" style="padding: 40px 20px; background: #f5f5f5; border-top: 1px solid #e0e0e0;">
        <h3 style="text-align: center; font-size: 18px; margin-bottom: 20px;">Partner Case Studies</h3>
        <?php if (!empty($posts) && is_array($posts)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; max-width: 1200px; margin: 0 auto;">
                <?php foreach ($posts as $post):
                    $title = $post['title']['rendered'] ?? 'Untitled';
                    $link = $post['link'] ?? '#';
                    $excerpt = $post['excerpt']['rendered'] ?? '';

                    // Try to get featured image from separately cached media
                    $img_url = '';
                    if (!empty($post['featured_media'])) {
                        $media_cache = get_option('wow_partner_media_' . $post['featured_media'], '');
                        if ($media_cache) {
                            $media = json_decode($media_cache, true);
                            $img_url = $media['source_url'] ?? '';
                        }
                    }
                ?>
                    <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <?php if ($img_url): ?>
                            <img src="<?php echo esc_url($img_url); ?>" alt="<?php echo esc_attr(wp_strip_all_tags($title)); ?>" style="width: 100%; height: 180px; object-fit: cover;" loading="lazy">
                        <?php endif; ?>
                        <div style="padding: 16px;">
                            <h4 style="margin: 0 0 8px; font-size: 15px;">
                                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener" style="color: #1a1a1a; text-decoration: none;">
                                    <?php echo wp_kses_post($title); ?>
                                </a>
                            </h4>
                            <div style="font-size: 13px; color: #666; line-height: 1.4;">
                                <?php echo wp_trim_words(wp_strip_all_tags($excerpt), 20); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #999;">Loading partner content...</p>
        <?php endif; ?>
        <p style="text-align: center; margin-top: 15px; font-size: 12px; color: #aaa;">Last synced <?php echo esc_html($last_fetch); ?></p>
    </div>
    <?php
});
