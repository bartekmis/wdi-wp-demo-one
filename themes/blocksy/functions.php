<?php
/**
 * Blocksy functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Blocksy
 */

if (version_compare(PHP_VERSION, '5.7.0', '<')) {
	require get_template_directory() . '/inc/php-fallback.php';
	return;
}

require get_template_directory() . '/inc/init.php';

// Load media functions for REST API (fixes FakerPress image sideload)
  add_action('rest_api_init', function() {
      require_once ABSPATH . 'wp-admin/includes/media.php';
      require_once ABSPATH . 'wp-admin/includes/file.php';
      require_once ABSPATH . 'wp-admin/includes/image.php';
  });


add_action('wp_head', function() {
    if (!is_singular()) return;
    ?>
    <style>
        .wow-random-posts {
            margin-top: 50px;
            padding-top: 40px;
            border-top: 1px solid var(--theme-border-color, #e0e5eb);
        }
        .wow-random-posts-title {
            margin-bottom: 30px;
            font-size: 1.5em;
            color: var(--theme-heading-color, #23282d);
        }
        .wow-random-posts .entries[data-layout="grid"] {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
        }
        .wow-random-posts .entry-card {
            background: var(--card-background, #fff);
            border-radius: var(--theme-border-radius, 7px);
            box-shadow: var(--theme-box-shadow, 0 1px 2px rgba(0,0,0,.05), 0 2px 15px rgba(0,0,0,.06));
            overflow: hidden;
        }
        .wow-random-posts .ct-media-container {
            display: block;
            overflow: hidden;
        }
        .wow-random-posts .ct-media-container img {
            width: 100%;
            display: block;
            transition: transform 0.3s ease;
        }
        .wow-random-posts .ct-media-container:hover img {
            transform: scale(1.05);
        }
        .wow-random-posts .card-content {
            padding: var(--card-inner-spacing, 30px);
        }
        .wow-random-posts .entry-title {
            font-size: 1.1em;
            line-height: 1.3;
            margin: 8px 0 12px;
        }
        .wow-random-posts .entry-title a {
            color: var(--theme-heading-color, #23282d);
            text-decoration: none;
        }
        .wow-random-posts .entry-title a:hover {
            color: var(--theme-link-hover-color, var(--theme-palette-color-2, #2563eb));
        }
        .wow-random-posts .entry-excerpt {
            font-size: 0.9em;
            color: var(--theme-text-color, #6b7280);
            margin-bottom: 15px;
            line-height: 1.6;
        }
        .wow-random-posts .entry-meta { font-size: 0.85em; }
        .wow-random-posts .post-meta {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }
        .wow-random-posts .post-meta li {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .wow-random-posts .post-meta li + li::before {
            content: "\00B7";
            margin-right: 3px;
            color: var(--theme-text-color, #6b7280);
        }
        .wow-random-posts .ct-term {
            color: var(--theme-palette-color-1, #3b82f6);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .wow-random-posts .meta-author img {
            border-radius: 50%;
            width: 28px;
            height: 28px;
        }
        .wow-random-posts .meta-author a,
        .wow-random-posts .meta-date {
            color: var(--theme-text-color, #6b7280);
            text-decoration: none;
        }
        @media (max-width: 999px) {
            .wow-random-posts .entries[data-layout="grid"] {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 689px) {
            .wow-random-posts .entries[data-layout="grid"] {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <?php
});

