<?php
/**
 * Plugin Name: WOW Partner Feed
 * Description: Fetches and displays latest case studies from partner site via REST API.
 *
 * Perf: the remote fetch never runs during a page render. It is refreshed by WP-Cron
 * only; visitors always render straight from the cached copy.
 */

// Register cron schedule.
add_filter('cron_schedules', function ($schedules) {
    $schedules['wow_partner_interval'] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => 'Every 15 Minutes',
    ];
    return $schedules;
});

// Schedule cron on load.
add_action('init', function () {
    if (!wp_next_scheduled('wow_partner_feed_fetch')) {
        wp_schedule_event(time(), 'wow_partner_interval', 'wow_partner_feed_fetch');
    }
});

// Cron handler - fetch case studies from partner site.
add_action('wow_partner_feed_fetch', function () {
    $api_url = 'https://k2space-backend.bigpic.dev/wp-json/wp/v2/case-study?per_page=12&_embed';

    $response = wp_remote_get($api_url, ['timeout' => 10]);

    if (is_wp_error($response)) {
        update_option('wow_partner_last_error', $response->get_error_message(), false);
        return;
    }

    $body  = wp_remote_retrieve_body($response);
    $posts = json_decode($body, true);

    if (empty($posts) || !is_array($posts)) {
        update_option('wow_partner_last_error', 'No data returned: ' . substr($body, 0, 500), false);
        return;
    }

    // Build the render-ready payload once, here, so the front end does no work.
    $items = [];
    foreach ($posts as $post) {
        $img_url = '';
        if (!empty($post['featured_media'])) {
            // Prefer the embedded media that _embed already gave us: no extra HTTP call.
            if (!empty($post['_embedded']['wp:featuredmedia'][0]['source_url'])) {
                $img_url = $post['_embedded']['wp:featuredmedia'][0]['source_url'];
            } else {
                $media_response = wp_remote_get(
                    'https://k2space-backend.bigpic.dev/wp-json/wp/v2/media/' . $post['featured_media'],
                    ['timeout' => 10]
                );
                if (!is_wp_error($media_response)) {
                    $media   = json_decode(wp_remote_retrieve_body($media_response), true);
                    $img_url = $media['source_url'] ?? '';
                }
            }
        }

        $items[] = [
            'title'   => $post['title']['rendered'] ?? 'Untitled',
            'link'    => $post['link'] ?? '#',
            'excerpt' => wp_trim_words(wp_strip_all_tags($post['excerpt']['rendered'] ?? ''), 20),
            'img'     => $img_url,
        ];
    }

    update_option('wow_partner_feed_items', $items, false);
    update_option('wow_partner_last_fetch', current_time('mysql'), false);
    delete_option('wow_partner_last_error');
});

// Display in footer - reads the prebuilt payload only, never fetches.
add_action('wp_footer', function () {
    if (is_admin()) return;

    $items      = get_option('wow_partner_feed_items', []);
    $last_fetch = get_option('wow_partner_last_fetch', 'never');
    ?>
    <div class="wow-partner-feed" style="padding: 40px 20px; background: #f5f5f5; border-top: 1px solid #e0e0e0; content-visibility: auto; contain-intrinsic-size: auto 600px;">
        <h3 style="text-align: center; font-size: 18px; margin-bottom: 20px;">Partner Case Studies</h3>
        <?php if (!empty($items) && is_array($items)): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; max-width: 1200px; margin: 0 auto;">
                <?php foreach ($items as $item): ?>
                    <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <?php if (!empty($item['img'])): ?>
                            <img src="<?php echo esc_url($item['img']); ?>" alt="<?php echo esc_attr(wp_strip_all_tags($item['title'])); ?>" width="280" height="180" style="width: 100%; height: 180px; object-fit: cover;" loading="lazy" decoding="async">
                        <?php endif; ?>
                        <div style="padding: 16px;">
                            <h4 style="margin: 0 0 8px; font-size: 15px;">
                                <a href="<?php echo esc_url($item['link']); ?>" target="_blank" rel="noopener" style="color: #1a1a1a; text-decoration: none;">
                                    <?php echo wp_kses_post($item['title']); ?>
                                </a>
                            </h4>
                            <div style="font-size: 13px; color: #666; line-height: 1.4;">
                                <?php echo esc_html($item['excerpt']); ?>
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
