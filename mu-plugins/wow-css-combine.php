<?php
/**
 * Plugin Name: WOW CSS Combine
 * Description: Merges the site's ~27 render-blocking local stylesheets into one cached
 *              file (preserving order and per-handle inline CSS).
 * Version: 1.0
 */

defined('ABSPATH') || exit;

define('WOW_CSS_DIR', WP_CONTENT_DIR . '/cache/wow-css');
define('WOW_CSS_URL', content_url('/cache/wow-css'));

function wow_css_active() {
    return !is_admin()
        && !is_customize_preview()
        && !is_feed()
        && !(defined('REST_REQUEST') && REST_REQUEST)
        && !(defined('DOING_AJAX') && DOING_AJAX)
        && !is_user_logged_in();
}

/** Map a local stylesheet URL to a filesystem path, or null if it is not local. */
function wow_css_local_path($src) {
    if (!$src) return null;

    $src = strtok($src, '?');
    if (strpos($src, '//') === 0) {
        $src = (is_ssl() ? 'https:' : 'http:') . $src;
    }
    if (strpos($src, '/') === 0 && strpos($src, '//') !== 0) {
        $src = home_url($src);
    }

    $content_url = content_url();
    $includes_url = includes_url();

    if (strpos($src, $content_url) === 0) {
        $path = WP_CONTENT_DIR . substr($src, strlen($content_url));
    } elseif (strpos($src, $includes_url) === 0) {
        $path = ABSPATH . WPINC . substr($src, strlen($includes_url));
    } else {
        return null;
    }

    $path = realpath($path);
    if (!$path || !is_readable($path) || substr($path, -4) !== '.css') return null;

    return $path;
}

/** Rewrite url(...) and @import so they still resolve from the combined file's location. */
function wow_css_rewrite_urls($css, $source_url) {
    $base = trailingslashit(dirname($source_url));

    return preg_replace_callback(
        '#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
        function ($m) use ($base) {
            $url = trim($m[2]);
            if ($url === '' ||
                strpos($url, 'data:') === 0 ||
                strpos($url, 'http://') === 0 ||
                strpos($url, 'https://') === 0 ||
                strpos($url, '//') === 0 ||
                strpos($url, '#') === 0) {
                return $m[0];
            }
            if (strpos($url, '/') === 0) {
                return 'url("' . $url . '")';
            }
            return 'url("' . $base . $url . '")';
        },
        $css
    );
}

function wow_css_minify($css) {
    $css = preg_replace('#/\*(?!!)[^*]*\*+([^/*][^*]*\*+)*/#', '', $css); // comments
    $css = preg_replace('/\s+/', ' ', $css);                              // whitespace runs
    $css = str_replace(['; ', ' {', '{ ', ' }', '} ', ': ', ', '], [';', '{', '{', '}', '}', ':', ','], $css);
    return trim($css);
}

add_action('wp_print_styles', function () {
    if (!wow_css_active()) return;

    $wp_styles = wp_styles();
    if (empty($wp_styles->queue)) return;

    // Resolve the queue into a fully ordered, dependency-expanded list.
    $wp_styles->all_deps($wp_styles->queue);
    $ordered = $wp_styles->to_do;
    if (empty($ordered)) return;

    $groups = [];   // media => [ ['handle'=>, 'path'=>, 'src'=>, 'ver'=>], ... ]
    $keep    = [];  // handles left alone (external, conditional, etc.)

    foreach ($ordered as $handle) {
        $obj = $wp_styles->registered[$handle] ?? null;
        if (!$obj) { $keep[] = $handle; continue; }

        // Never touch conditional (IE) or alt stylesheets.
        if (!empty($obj->extra['conditional']) || !empty($obj->extra['alt'])) { $keep[] = $handle; continue; }
        if (strpos($handle, 'no-scripts') !== false) { $keep[] = $handle; continue; }

        $src  = $obj->src;
        $path = wow_css_local_path($src);
        if (!$path) { $keep[] = $handle; continue; }

        $css = @file_get_contents($path);
        if ($css === false || stripos($css, '@import') !== false) { $keep[] = $handle; continue; }

        $media = $obj->args ?: 'all';
        $groups[$media][] = [
            'handle' => $handle,
            'path'   => $path,
            'src'    => $src,
            'mtime'  => @filemtime($path),
            'ver'    => $obj->ver,
        ];
    }

    foreach ($groups as $media => $items) {
        if (count($items) < 2) continue; // nothing to gain

        $sig = $media;
        foreach ($items as $i) {
            $sig .= '|' . $i['handle'] . ':' . $i['mtime'] . ':' . $i['ver'];
            $after = $wp_styles->get_data($i['handle'], 'after');
            if ($after) $sig .= ':' . md5(serialize($after));
        }
        $key  = substr(md5($sig), 0, 16);
        $file = WOW_CSS_DIR . '/' . $key . '.css';

        if (!file_exists($file)) {
            if (!is_dir(WOW_CSS_DIR) && !@mkdir(WOW_CSS_DIR, 0755, true) && !is_dir(WOW_CSS_DIR)) continue;

            $out = '';
            foreach ($items as $i) {
                $css = @file_get_contents($i['path']);
                if ($css === false) continue;
                $css = preg_replace('/^\xEF\xBB\xBF/', '', $css);
                $css = preg_replace('/@charset[^;]+;/i', '', $css);

                $abs = $i['src'];
                if (strpos($abs, '/') === 0 && strpos($abs, '//') !== 0) $abs = home_url($abs);
                $out .= "\n/* " . $i['handle'] . " */\n" . wow_css_rewrite_urls($css, strtok($abs, '?'));

                // Keep wp_add_inline_style() output in its original position.
                $after = $wp_styles->get_data($i['handle'], 'after');
                if ($after) {
                    $out .= "\n" . implode("\n", (array) $after);
                }
            }

            $out = wow_css_minify($out);
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $out, LOCK_EX) === false) continue;
            @rename($tmp, $file);
            @file_put_contents($file . '.gz', gzencode($out, 6));
        }

        // Swap the originals out for the single combined file.
        foreach ($items as $i) {
            $wp_styles->done[] = $i['handle'];
            wp_dequeue_style($i['handle']);
            $wp_styles->registered[$i['handle']]->src = false;
            $wp_styles->registered[$i['handle']]->extra['after'] = [];
        }

        $combined = 'wow-combined-' . substr($key, 0, 8);
        wp_register_style($combined, WOW_CSS_URL . '/' . $key . '.css', [], null, $media);
        wp_enqueue_style($combined);
        $wp_styles->all_deps([$combined]);
    }
}, 1);

/* -------------------------------------------------------------- cache purge */

function wow_css_purge() {
    if (!is_dir(WOW_CSS_DIR)) return 0;
    $n = 0;
    foreach (glob(WOW_CSS_DIR . '/*') as $f) { @unlink($f); $n++; }
    return $n;
}

foreach (['switch_theme', 'customize_save_after', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'elementor/core/files/clear_cache'] as $hook) {
    add_action($hook, 'wow_css_purge');
}
