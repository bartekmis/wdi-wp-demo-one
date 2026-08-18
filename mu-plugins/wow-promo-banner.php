<?php
/**
 * Plugin Name: WOW Promo Banner
 * Description: Promotional banner displayed at the top of every page.
 */

add_action('wp_footer', function () {
    if (is_admin()) return;
    ?>
    <style>
        .wow-promo-banner {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: #fff;
            padding: 14px 20px;
            text-align: center;
            font-size: 15px;
            line-height: 1.4;
            position: relative;
            z-index: 9999;
            display: none;
        }
        .wow-promo-banner a {
            color: #fbbf24;
            font-weight: 700;
            text-decoration: underline;
            margin-left: 8px;
        }
        .wow-promo-banner .wow-promo-close {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #fff;
            font-size: 20px;
            cursor: pointer;
            padding: 4px 8px;
            line-height: 1;
            opacity: 0.7;
        }
        .wow-promo-banner .wow-promo-close:hover {
            opacity: 1;
        }
    </style>
    <script>
        (function() {
            var visited = document.cookie.indexOf('wow_visited=1') !== -1;
            var dismissed = document.cookie.indexOf('wow_banner_closed=1') !== -1;

            if (!visited) {
                document.cookie = 'wow_visited=1; path=/; max-age=' + (60 * 60 * 24 * 30);
                return;
            }

            if (dismissed) return;

            var banner = document.createElement('div');
            banner.className = 'wow-promo-banner';
            banner.innerHTML = '<span>Welcome to the WOW project!</span><button class="wow-promo-close" aria-label="Close">&times;</button>';

            setTimeout(function() {
                document.body.insertBefore(banner, document.body.firstChild);
                banner.style.display = 'block';
                banner.querySelector('.wow-promo-close').addEventListener('click', function() {
                    banner.style.display = 'none';
                    document.cookie = 'wow_banner_closed=1; path=/; max-age=' + (60 * 60 * 24 * 30);
                });
            }, 1500);
        })();
    </script>
    <?php
});
