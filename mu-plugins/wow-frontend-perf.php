<?php
/**
 * Plugin Name: WOW Frontend Performance
 * Description: Critical-path fixes for the front end: responsive WebP hero, resource hints,
 *              non-blocking third-party tags and fonts, deferred scripts, right-sized images.
 * Version: 1.0
 */

defined('ABSPATH') || exit;

define('WOW_FP_OPT_DIR', WP_CONTENT_DIR . '/uploads/wow-optimized');
define('WOW_FP_OPT', content_url('/uploads/wow-optimized'));
define('WOW_FP_HERO_SRC', '2026/04/pexels-photo-34150285-scaled.jpeg');

function wow_fp_is_front() {
    return !is_admin() && !is_feed() && !(defined('REST_REQUEST') && REST_REQUEST)
        && !(function_exists('wp_doing_ajax') && wp_doing_ajax());
}

/* ------------------------------------------------------- optimized image set */

/**
 * The derived images live in uploads (not in git), so they are built from the
 * originals whenever one is missing: hero variants per breakpoint, and the client
 * photos re-encoded (the originals are ~110KB each at 455x576).
 */
function wow_fp_image_jobs() {
    $up = WP_CONTENT_DIR . '/uploads/';
    return [
        'hero-mobile.webp'  => [$up . WOW_FP_HERO_SRC, 800, 60],
        'hero-tablet.webp'  => [$up . WOW_FP_HERO_SRC, 1200, 60],
        'hero-desktop.webp' => [$up . WOW_FP_HERO_SRC, 1920, 60],
        'client-1.webp'     => [$up . '2025/08/client-1.webp', 455, 70],
        'client-2.webp'     => [$up . '2025/08/client-2.webp', 455, 70],
        'client-3.webp'     => [$up . '2025/08/client-3.webp', 455, 70],
    ];
}

function wow_fp_build_images($force = false) {
    if (!function_exists('imagewebp')) return [];
    if (!is_dir(WOW_FP_OPT_DIR) && !wp_mkdir_p(WOW_FP_OPT_DIR)) return [];

    $built = [];
    foreach (wow_fp_image_jobs() as $name => list($src, $width, $quality)) {
        $dest = WOW_FP_OPT_DIR . '/' . $name;
        if (!$force && file_exists($dest)) continue;
        if (!is_readable($src)) continue;

        $data = @file_get_contents($src);
        $img  = $data ? @imagecreatefromstring($data) : false;
        if (!$img) continue;

        if (imagesx($img) > $width) {
            $scaled = imagescale($img, $width, -1, IMG_BICUBIC);
            imagedestroy($img);
            $img = $scaled;
        }
        if (imagewebp($img, $dest, $quality)) {
            $built[$name] = filesize($dest);
        }
        imagedestroy($img);
    }
    return $built;
}

function wow_fp_images_ready() {
    foreach (array_keys(wow_fp_image_jobs()) as $name) {
        if (!file_exists(WOW_FP_OPT_DIR . '/' . $name)) return false;
    }
    return true;
}

add_action('admin_init', function () {
    if (!wow_fp_images_ready()) wow_fp_build_images();
});

/* ------------------------------------------------------- WordPress emoji */

/**
 * Every current browser renders emoji natively. The emoji loader's feature test fails
 * on machines without a colour-emoji font (e.g. PageSpeed's Linux runners), which then
 * pulls in wp-emoji-release.js; in traces that stalled the first frame by 1-2s.
 */
add_action('init', function () {
    if (is_admin()) return;
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
    add_filter('emoji_svg_url', '__return_false');
});

/* ------------------------------------------------- resource hints + preload */

add_action('wp_head', function () {
    if (!wow_fp_is_front()) return;

    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link rel="preconnect" href="https://consent.cookiebot.com">' . "\n";

    // The LCP element is a CSS background, so the browser cannot discover it from the
    // markup. Preload the variant this viewport will actually use.
    if (is_front_page() && wow_fp_images_ready()) {
        $o = WOW_FP_OPT;
        echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($o . '/hero-mobile.webp') . '" media="(max-width: 767px)">' . "\n";
        echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($o . '/hero-tablet.webp') . '" media="(min-width: 768px) and (max-width: 1199px)">' . "\n";
        echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url($o . '/hero-desktop.webp') . '" media="(min-width: 1200px)">' . "\n";
    }
}, 1);

/* --------------------------------------------------- responsive hero background */

add_action('wp_head', function () {
    if (!wow_fp_is_front() || !is_front_page() || !wow_fp_images_ready()) return;

    $o   = WOW_FP_OPT;
    $sel = 'body .elementor-1257 .elementor-element.elementor-element-6e6d30e:not(.elementor-motion-effects-element-type-background),'
         . 'body .elementor-1257 .elementor-element.elementor-element-6e6d30e > .elementor-motion-effects-container > .elementor-motion-effects-layer';

    echo "<style id=\"wow-hero-perf\">\n";
    echo $sel . "{background-image:url('" . esc_url($o . '/hero-mobile.webp') . "');}\n";
    echo "@media (min-width:768px){" . $sel . "{background-image:url('" . esc_url($o . '/hero-tablet.webp') . "');}}\n";
    echo "@media (min-width:1200px){" . $sel . "{background-image:url('" . esc_url($o . '/hero-desktop.webp') . "');}}\n";
    echo "</style>\n";
}, 20);

/* ------------------------------------------------------------ defer scripts */

/**
 * Defer EVERY first-party script, not just the head ones.
 *
 * Deferring jQuery alone is a trap: its dependents (Elementor, Blocksy, WPForms,
 * FluentForms) are parser-blocking in the footer, so they would execute *before* the
 * deferred jQuery and throw "jQuery is not defined". Deferred scripts run in document
 * order, so deferring the whole set preserves the dependency chain.
 *
 * The page's inline scripts are *-js-extra / *-js-before config assignments
 * (elementorFrontendConfig, ct_localizations, wpforms_settings). They only define
 * variables and never call into these libraries, so running them first is safe.
 */
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    if (!wow_fp_is_front()) return $tag;

    // Only our own origin: third-party tags keep whatever loading strategy they ship with.
    $host  = wp_parse_url(home_url(), PHP_URL_HOST);
    $shost = wp_parse_url($src, PHP_URL_HOST);
    if ($shost && $shost !== $host) return $tag;

    if (strpos($tag, ' defer') !== false || strpos($tag, ' async') !== false) return $tag;
    if (strpos($tag, 'type=\'module\'') !== false || strpos($tag, 'type="module"') !== false) return $tag;

    // On the home page (audited: no inline script there calls into these libraries)
    // even deferred scripts are too early: they run before the first frame, and ~600ms
    // of jQuery/Elementor/Blocksy slider work held first paint. Park the whole tag,
    // inline config included, and run it in order right after first paint.
    if (is_front_page()) {
        return preg_replace_callback('#<script\b([^>]*)>#i', function ($m) {
            $attrs = preg_replace('#\s(?:type|defer|async)(?:=(["\'])[^"\']*\1)?#i', '', $m[1]);
            return '<script type="wow/after-paint"' . $attrs . '>';
        }, $tag);
    }

    // $tag can also carry inline "before" scripts, so target the external one.
    return preg_replace('#<script(?=[^>]*\ssrc=)#', '<script defer', $tag, 1);
}, 10, 3);

function wow_fp_after_paint_runtime() {
    return <<<'JS'
<script id="wow-after-paint-runtime">
(function(){
  var D=document, W=window;
  // Listeners added after an event has already fired would never run; call them instead.
  function late(target, name, passed){
    var add=target.addEventListener;
    target.addEventListener=function(type, cb, opts){
      if(type===name && passed() && cb){
        setTimeout(function(){
          var ev=new Event(name);
          try{ typeof cb==='function' ? cb.call(target, ev) : cb.handleEvent(ev); }catch(e){ console.error(e); }
        });
        return;
      }
      return add.call(this, type, cb, opts);
    };
  }
  function run(){
    var ready=function(){return D.readyState!=='loading';};
    late(D,'DOMContentLoaded',ready); late(W,'DOMContentLoaded',ready);
    late(W,'load',function(){return D.readyState==='complete';});
    D.querySelectorAll('script[type="wow/after-paint"]').forEach(function(old){
      var s=D.createElement('script');
      for(var i=0;i<old.attributes.length;i++){var a=old.attributes[i]; if(a.name!=='type') s.setAttribute(a.name,a.value);}
      // Inline code goes through a Blob URL so it keeps its place in the ordered queue.
      if(!old.hasAttribute('src')) s.src=URL.createObjectURL(new Blob([old.text],{type:'text/javascript'}));
      s.async=old.hasAttribute('data-wow-async');
      old.parentNode.replaceChild(s,old);
    });
  }
  function afterPaint(){ requestAnimationFrame(function(){ setTimeout(run,0); }); }
  if(D.readyState==='loading') D.addEventListener('DOMContentLoaded',afterPaint); else afterPaint();
})();
</script>
JS;
}

/* ------------------------------------------- drop Font Awesome where unused */

/**
 * Elementor renders its icons as inline SVG (e-font-icon-svg), so the Font Awesome
 * webfont CSS and the v4 shim are dead weight on the front page.
 */
function wow_fp_drop_font_awesome() {
    if (!wow_fp_is_front() || !is_front_page()) return;

    foreach (wp_styles()->queue as $handle) {
        if (strpos($handle, 'font-awesome') !== false || strpos($handle, 'elementor-icons-fa') !== false) {
            wp_dequeue_style($handle);
        }
    }
    foreach (wp_scripts()->queue as $handle) {
        if (strpos($handle, 'font-awesome') !== false) {
            wp_dequeue_script($handle);
        }
    }
}
add_action('wp_print_styles', 'wow_fp_drop_font_awesome', 0);
add_action('wp_print_scripts', 'wow_fp_drop_font_awesome', 0);

/* ------------------------------------------------------------------ fonts */

/**
 * Elementor requests the full Roboto and Roboto Slab families (18 styles each) with
 * display=block. The theme already loads the Roboto weights the page uses, and Roboto
 * Slab only appears in unused kit variables, so Elementor's copy is dropped entirely.
 */
add_filter('elementor/frontend/print_google_fonts', '__return_false');

add_filter('style_loader_src', function ($src) {
    if (strpos((string) $src, 'fonts.googleapis.com') === false) return $src;
    return str_replace('display=block', 'display=swap', $src);
}, 10, 1);

/**
 * The Google Fonts stylesheet is inlined instead of linked. As a render-blocking link it
 * adds a cross-origin round trip before first paint; loaded "async" (preload + onload
 * rel swap) it made Chrome hold the first frame for ~1s in PageSpeed runs. The
 * @font-face rules themselves are tiny, so they are fetched once server-side, trimmed
 * to the latin subsets and cached. The font files still come from fonts.gstatic.com
 * with font-display:swap.
 */
function wow_fp_webfont_css($href) {
    $href = html_entity_decode($href);
    $key  = 'wow_webfonts_' . md5($href);
    $css  = get_transient($key);
    if (is_string($css)) return $css;

    $response = wp_remote_get($href, [
        'timeout'    => 3,
        // A current Chrome UA makes Google serve woff2 with unicode-range subsets.
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
    ]);
    $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);
    if (wp_remote_retrieve_response_code($response) !== 200 || strpos($body, '@font-face') === false) {
        set_transient($key, '', 10 * MINUTE_IN_SECONDS); // retry later, link normally meanwhile
        return '';
    }

    // Google prefixes every @font-face with a /* subset */ comment.
    preg_match_all('#/\*\s*([a-z-]+)\s*\*/\s*(@font-face\s*\{[^}]*\})#i', $body, $m, PREG_SET_ORDER);
    $css = '';
    foreach ($m as $face) {
        if (in_array(strtolower($face[1]), ['latin', 'latin-ext'], true)) {
            $css .= str_replace('</', '', preg_replace('/\s+/', ' ', $face[2]));
        }
    }

    set_transient($key, $css, WEEK_IN_SECONDS);
    return $css;
}

add_filter('style_loader_tag', function ($tag, $handle, $href, $media) {
    if (!wow_fp_is_front() || strpos($href, 'fonts.googleapis.com') === false) return $tag;

    $css = wow_fp_webfont_css($href);
    if ($css === '') return $tag;

    return '<style id="' . esc_attr($handle) . '-inline">' . $css . "</style>\n";
}, 10, 4);

/* ------------------------------------------------ delayed third-party tags */

/**
 * Analytics, chat and RUM tags do not need to run before the page is usable. They are
 * parked with an inert type (the browser neither fetches nor runs them) and started
 * on the first interaction, or 5s after the load event for visitors who never interact.
 */
function wow_fp_delayed_markers() {
    return [
        'googletagmanager.com/gtm.js',
        'rum.corewebvitals.io',
        'chatbase.co/embed',
        'termsfeedtest.com',
    ];
}

function wow_fp_delay_third_parties($html) {
    $delayed = 0;
    $html = preg_replace_callback(
        '#<script\b([^>]*)>(.*?)</script>#is',
        function ($m) use (&$delayed) {
            $attrs = $m[1];
            if (preg_match('#\btype=["\'](?!text/javascript|module)[^"\']+["\']#i', $attrs)) return $m[0];

            $hit = false;
            foreach (wow_fp_delayed_markers() as $marker) {
                if (stripos($m[0], $marker) !== false) { $hit = true; break; }
            }
            if (!$hit) return $m[0];

            $delayed++;
            $attrs = preg_replace('#\s(?:type|async|defer)(?:=(["\'])[^"\']*\1)?#i', '', $attrs);
            return '<script type="wow/delay"' . $attrs . '>' . $m[2] . '</script>';
        },
        $html
    );

    if ($delayed) {
        $html = preg_replace('#</body>#i', wow_fp_runtime_script() . '</body>', $html, 1);
    }
    return $html;
}

function wow_fp_runtime_script() {
    return <<<'JS'
<script id="wow-delay-runtime">
(function(){
  var started=false, evs=['pointerdown','keydown','touchstart','scroll','mousemove','wheel'];
  function start(){
    if(started) return; started=true;
    evs.forEach(function(e){removeEventListener(e,start,{passive:true});});
    document.querySelectorAll('script[type="wow/delay"]').forEach(function(old){
      var s=document.createElement('script');
      for(var i=0;i<old.attributes.length;i++){var a=old.attributes[i]; if(a.name!=='type') s.setAttribute(a.name,a.value);}
      if(!old.hasAttribute('src')) s.text=old.text; else s.async=true;
      old.parentNode.replaceChild(s,old);
    });
  }
  evs.forEach(function(e){addEventListener(e,start,{passive:true});});
  if(document.readyState==='complete') setTimeout(start,5000);
  else addEventListener('load',function(){setTimeout(start,5000);});
})();
</script>
JS;
}

/* ----------------------------------------------------- lazy CSS backgrounds */

add_action('wp_head', function () {
    if (!wow_fp_is_front() || !is_front_page()) return;
    echo '<style id="wow-lazy-bg">.wow-lazy-bg:not(.wow-bg-in){background-image:none!important}</style>' . "\n";
}, 21);

add_action('wp_footer', function () {
    if (!wow_fp_is_front() || !is_front_page()) return;
    ?>
<script id="wow-lazy-bg-js">
(function(){
  var els=document.querySelectorAll('.wow-lazy-bg, img[data-wow-src]');
  function show(el){
    if(el.tagName==='IMG'){
      if(el.dataset.wowSrcset){el.srcset=el.dataset.wowSrcset;el.removeAttribute('data-wow-srcset');}
      el.src=el.dataset.wowSrc; el.removeAttribute('data-wow-src');
    } else el.classList.add('wow-bg-in');
  }
  if(!('IntersectionObserver' in window)){els.forEach(show);return;}
  var io=new IntersectionObserver(function(entries){
    entries.forEach(function(en){if(en.isIntersecting){show(en.target);io.unobserve(en.target);}});
  },{rootMargin:'300px 0px'});
  els.forEach(function(el){io.observe(el);});
})();
</script>
    <?php
}, 99);

/* ------------------------------------------------------- HTML output rewrites */

function wow_fp_filter_html($html) {
    if (stripos($html, '</html>') === false) return $html;

    // 1. Third-party tags injected through WPCode's header/body boxes. The Cookiebot tag
    //    was parser-blocking in <head> and alone held first paint ~1.7s. It is the
    //    consent tool, so it is not delayed: async everywhere, and on the home page
    //    first in the queue that starts right after first paint.
    $html = str_replace(
        '<script id="Cookiebot" src="https://consent.cookiebot.com/uc.js"',
        is_front_page()
            ? '<script id="Cookiebot" type="wow/after-paint" data-wow-async src="https://consent.cookiebot.com/uc.js"'
            : '<script id="Cookiebot" async src="https://consent.cookiebot.com/uc.js"',
        $html
    );
    $html = str_replace(
        'data-blockingmode="auto" type="text/javascript"',
        'data-blockingmode="auto"',
        $html
    );
    $html = wow_fp_delay_third_parties($html);

    // 2. Right-sized client photos (105-122KB each -> re-encoded copies).
    if (wow_fp_images_ready()) {
        foreach (['client-1', 'client-2', 'client-3'] as $c) {
            $html = str_replace(
                content_url('/uploads/2025/08/' . $c . '.webp'),
                WOW_FP_OPT . '/' . $c . '.webp',
                $html
            );
        }
    }

    if (!is_front_page()) return $html;

    if (strpos($html, 'type="wow/after-paint"') !== false) {
        $html = preg_replace('#</body>#i', wow_fp_after_paint_runtime() . '</body>', $html, 1);
    }

    // 3. Elementor blanks container backgrounds (background-image:none !important) until
    //    its JS adds .e-lazyloaded, so the hero (the LCP element) waited on jQuery and
    //    Elementor init. Opt the hero out.
    $html = preg_replace_callback(
        '#class="([^"]*\belementor-element-6e6d30e\b[^"]*)"#',
        function ($m) {
            if (strpos($m[1], 'e-no-lazyload') !== false) return $m[0];
            return 'class="' . $m[1] . ' e-no-lazyload"';
        },
        $html
    );

    // 4. The YouTube video widgets have no lazy_load, so their iframes (and ~900KB of
    //    player JS) load on page load. Turn lazy_load on.
    $html = preg_replace_callback(
        '#data-settings="(\{[^"]*&quot;video_type&quot;:&quot;youtube&quot;[^"]*\})"#',
        function ($m) {
            if (strpos($m[1], 'lazy_load') !== false) return $m[0];
            return 'data-settings="' . str_replace(
                '&quot;video_type&quot;:&quot;youtube&quot;',
                '&quot;video_type&quot;:&quot;youtube&quot;,&quot;lazy_load&quot;:&quot;yes&quot;',
                $m[1]
            ) . '"';
        },
        $html
    );

    // 5. Below-the-fold backgrounds that Elementor's own lazy-loading misses: a child
    //    container and the video overlay (an inline style). Both were fetched at high
    //    priority next to the hero.
    $html = preg_replace(
        '#class="((?:elementor-custom-embed-image-overlay|[^"]*\belementor-element-aa610d1\b[^"]*))"#',
        'class="$1 wow-lazy-bg"',
        $html
    );

    // 6. On the home page the hero fills the first viewport, so every <img> is below the
    //    fold (or inside the closed off-canvas menu) and should not compete with the hero
    //    for bandwidth. Native loading=lazy still fetched them before first paint (its
    //    distance threshold is ~1250px and the off-canvas panel counts as "near"), so
    //    they are loaded by an IntersectionObserver instead. The header logo stays eager.
    $html = preg_replace_callback(
        '#<img\s[^>]*>#i',
        function ($m) {
            $tag = $m[0];
            if (stripos($tag, 'default-logo') !== false || stripos($tag, 'logo-light') !== false) return $tag;
            // TranslatePress clones its switcher in JS, so the observed node is replaced.
            if (stripos($tag, '/translatepress-multilingual/assets/flags/') !== false) return $tag;

            $tag = preg_replace('#\s(?:loading|fetchpriority)=(["\'])[^"\']*\1#i', '', $tag);
            $tag = preg_replace('#\s(src|srcset)=#i', ' data-wow-$1=', $tag);
            $tag = preg_replace('#<img\s#i', '<img loading="lazy" ', $tag, 1);
            if (stripos($tag, 'decoding=') === false) {
                $tag = preg_replace('#<img\s#i', '<img decoding="async" ', $tag, 1);
            }
            return $tag . '<noscript>' . $m[0] . '</noscript>';
        },
        $html
    );

    return $html;
}

add_action('template_redirect', function () {
    if (!wow_fp_is_front()) return;
    ob_start('wow_fp_filter_html');
}, 1);
