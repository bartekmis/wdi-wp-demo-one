<?php
/**
 * Plugin Name: WOW Page Cache
 * Description: Disk-based full-page cache for anonymous visitors. Runs at mu-plugin load
 *              time, so a HIT is served before the ~220ms of plugin loading and the rest
 *              of the WordPress bootstrap ever happens.
 * Version: 1.0
 */

defined('ABSPATH') || exit;

if (defined('WOW_PC_LOADED')) return;
define('WOW_PC_LOADED', true);

define('WOW_PC_DIR', WP_CONTENT_DIR . '/cache/wow-page-cache');
define('WOW_PC_TTL', 3600);

/** Query args that only ever identify a campaign, never the content. */
function wow_pc_ignored_args() {
    return [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'ref', '_ga', 'gad_source',
        'gbraid', 'wbraid', 'yclid', 'igshid', 'ttclid', 'li_fat_id',
    ];
}

/** Cookies that mean "this visitor gets personalised HTML". */
function wow_pc_is_private_visitor() {
    foreach (array_keys($_COOKIE) as $name) {
        if (strpos($name, 'wordpress_logged_in_') === 0) return true;
        if (strpos($name, 'comment_author_') === 0) return true;
        if (strpos($name, 'wp-postpass_') === 0) return true;
        if (strpos($name, 'woocommerce_items_in_cart') === 0) return true;
        if (strpos($name, 'wp_woocommerce_session_') === 0) return true;
        if (strpos($name, 'wordpress_sec_') === 0) return true;
    }
    return false;
}

/** Whether this request may be served from, or written to, the cache. */
function wow_pc_is_cacheable_request() {
    if (PHP_SAPI === 'cli') return false;
    if (defined('DOING_CRON') || defined('DOING_AJAX') || defined('WP_CLI')) return false;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    if (!empty($_POST)) return false;
    if (wow_pc_is_private_visitor()) return false;

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    $blocked = [
        '/wp-admin', '/wp-login.php', '/wp-json', '/wp-cron.php', '/xmlrpc.php',
        '/wp-signup.php', '/wp-activate.php', '/wp-trackback.php', '/wp-comments-post.php',
        '/feed', '/robots.txt', '/sitemap',
    ];
    foreach ($blocked as $b) {
        if (strpos($path, $b) === 0) return false;
    }
    if (substr($path, -5) === '/feed' || substr($path, -6) === '/feed/') return false;
    if (preg_match('#\.(php|xml|txt|xsl)$#i', $path)) return false;

    // Any query arg beyond the campaign-tracking ones makes the response request-specific.
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $args);
    $meaningful = array_diff_key($args, array_flip(wow_pc_ignored_args()));
    if (!empty($meaningful)) return false;

    return true;
}

function wow_pc_key() {
    $host   = $_SERVER['HTTP_HOST'] ?? 'default';
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';

    // Some themes branch on wp_is_mobile(), so keep separate buckets.
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $mobile = preg_match('/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi/i', $ua) ? 'm' : 'd';

    return md5($scheme . '|' . $host . '|' . $path . '|' . $mobile);
}

function wow_pc_paths($key) {
    $sub = substr($key, 0, 2) . '/' . substr($key, 2, 2);
    $base = WOW_PC_DIR . '/' . $sub . '/' . $key;
    return ['meta' => $base . '.json', 'html' => $base . '.html', 'gz' => $base . '.html.gz'];
}

function wow_pc_client_accepts_gzip() {
    return strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
}

/**
 * Bump a post's view counter for a request that never reaches WordPress.
 * $wpdb is already available at mu-plugin load time.
 */
function wow_pc_record_view($post_id) {
    global $wpdb;
    if (!$post_id || !isset($wpdb)) return;
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1
         WHERE post_id = %d AND meta_key = '_wow_view_count'",
        $post_id
    ));
    if (!$updated) {
        $wpdb->insert($wpdb->postmeta, [
            'post_id'    => $post_id,
            'meta_key'   => '_wow_view_count',
            'meta_value' => 1,
        ]);
    }
}

/* ---------------------------------------------------------------- serve ---- */

function wow_pc_try_serve() {
    if (!wow_pc_is_cacheable_request()) return false;

    $key   = wow_pc_key();
    $paths = wow_pc_paths($key);

    if (!is_readable($paths['meta']) || !is_readable($paths['html'])) return false;

    $meta = json_decode((string) @file_get_contents($paths['meta']), true);
    if (!is_array($meta) || empty($meta['created'])) return false;
    if ((time() - (int) $meta['created']) > WOW_PC_TTL) return false;

    $use_gz = wow_pc_client_accepts_gzip() && is_readable($paths['gz']);
    $file   = $use_gz ? $paths['gz'] : $paths['html'];
    $size   = @filesize($file);
    if (!$size) return false;

    if (!empty($meta['post_id'])) {
        wow_pc_record_view((int) $meta['post_id']);
    }

    header('Content-Type: ' . ($meta['content_type'] ?? 'text/html; charset=UTF-8'));
    header('X-WOW-Cache: HIT');
    header('X-WOW-Cache-Age: ' . (time() - (int) $meta['created']));
    header('Vary: Accept-Encoding');
    if ($use_gz) header('Content-Encoding: gzip');
    header('Content-Length: ' . $size);

    @readfile($file);
    exit;
}

/* ---------------------------------------------------------------- store ---- */

function wow_pc_should_store($html) {
    if (!wow_pc_is_cacheable_request()) return false;
    if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) return false;
    if (function_exists('is_user_logged_in') && is_user_logged_in()) return false;
    if (function_exists('is_404') && did_action('template_redirect') && is_404()) return false;
    if (function_exists('is_search') && did_action('template_redirect') && is_search()) return false;

    $code = function_exists('http_response_code') ? http_response_code() : 200;
    if ($code !== 200) return false;

    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) return false;
        if (stripos($h, 'Set-Cookie:') === 0) return false;
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) return false;
    }

    if (strlen($html) < 500) return false;
    if (stripos($html, '</html>') === false) return false;

    return true;
}

function wow_pc_store($html) {
    if (!wow_pc_should_store($html)) return $html;

    $key   = wow_pc_key();
    $paths = wow_pc_paths($key);
    $dir   = dirname($paths['html']);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return $html;

    $post_id = 0;
    if (function_exists('is_singular') && did_action('template_redirect') && is_singular('post')) {
        $post_id = (int) get_queried_object_id();
    }

    $stamped = $html . "\n<!-- WOW Page Cache: generated " . gmdate('Y-m-d H:i:s') . " UTC -->";

    // Write to a temp file first so a concurrent reader never sees a half-written page.
    $tmp = $paths['html'] . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $stamped, LOCK_EX) !== false) {
        @rename($tmp, $paths['html']);
    }

    $gztmp = $paths['gz'] . '.' . getmypid() . '.tmp';
    $gz = @gzencode($stamped, 6);
    if ($gz !== false && @file_put_contents($gztmp, $gz, LOCK_EX) !== false) {
        @rename($gztmp, $paths['gz']);
    }

    $meta = [
        'created'      => time(),
        'url'          => ($_SERVER['HTTP_HOST'] ?? '') . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
        'content_type' => 'text/html; charset=UTF-8',
        'post_id'      => $post_id,
    ];
    $mtmp = $paths['meta'] . '.' . getmypid() . '.tmp';
    if (@file_put_contents($mtmp, wp_json_encode($meta), LOCK_EX) !== false) {
        @rename($mtmp, $paths['meta']);
    }

    if (!headers_sent()) {
        header('X-WOW-Cache: MISS');
    }

    return $html;
}

/* ---------------------------------------------------------------- purge ---- */

function wow_pc_purge_all() {
    if (!is_dir(WOW_PC_DIR)) return 0;
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(WOW_PC_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) { @rmdir($f->getPathname()); }
        else { @unlink($f->getPathname()); $count++; }
    }
    if (function_exists('wow_pc_schedule_warm')) wow_pc_schedule_warm();

    return $count;
}

foreach ([
    'save_post', 'deleted_post', 'trashed_post', 'untrashed_post',
    'switch_theme', 'customize_save_after', 'wp_update_nav_menu',
    'update_option_blogname', 'update_option_blogdescription',
    'update_option_sidebars_widgets', 'activated_plugin', 'deactivated_plugin',
    'elementor/core/files/clear_cache', 'upgrader_process_complete',
    'comment_post', 'edit_comment', 'wp_set_comment_status',
] as $hook) {
    add_action($hook, 'wow_pc_purge_all', 10, 0);
}

/* ------------------------------------------------------------------ warm ---- */

/**
 * Regenerate the hot pages ourselves so a real visitor never lands on an empty cache
 * and pays the full ~1.3s render.
 */
function wow_pc_warm() {
    $urls = apply_filters('wow_pc_warm_urls', [home_url('/')]);

    $agents = [
        'm' => 'Mozilla/5.0 (Linux; Android 11; moto g power) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'd' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    foreach ($urls as $url) {
        foreach ($agents as $ua) {
            wp_remote_get($url, [
                'timeout'     => 15,
                'blocking'    => true,
                'sslverify'   => false,
                'user-agent'  => $ua,
                'headers'     => ['Accept-Encoding' => 'gzip', 'X-WOW-Warm' => '1'],
            ]);
        }
    }
}

// Warm right after a purge, but on shutdown so we never re-enter mid-request.
function wow_pc_schedule_warm() {
    $GLOBALS['wow_pc_do_warm'] = true;
}

add_action('shutdown', function () {
    if (empty($GLOBALS['wow_pc_do_warm'])) return;
    $GLOBALS['wow_pc_do_warm'] = false;
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    wow_pc_warm();
}, 100);

// Keep the cache hot so entries are refreshed before the TTL expires.
add_filter('cron_schedules', function ($s) {
    $s['wow_pc_warm_interval'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 Minutes (cache warm)'];
    return $s;
});

add_action('init', function () {
    if (!wp_next_scheduled('wow_pc_warm_event')) {
        wp_schedule_event(time() + 60, 'wow_pc_warm_interval', 'wow_pc_warm_event');
    }
});

add_action('wow_pc_warm_event', 'wow_pc_warm');

/* ----------------------------------------------------------------- boot ---- */

wow_pc_try_serve();

if (wow_pc_is_cacheable_request()) {
    ob_start('wow_pc_store');
}
