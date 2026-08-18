<?php
/*
 * The template for displaying all single posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package Blocksy
 */

if (have_posts()) {
	the_post();
}

if (
	function_exists('blocksy_companion_get_content_block_that_matches')
	&&
	blocksy_companion_get_content_block_that_matches([
		'template_type' => 'single',
		'template_subtype' => 'canvas'
	])
) {
	echo blocksy_companion_render_content_block(
		blocksy_companion_get_content_block_that_matches([
			'template_type' => 'single',
			'template_subtype' => 'canvas'
		])
	);
	have_posts();
	wp_reset_query();
	return;
}

/**
 * Note to code reviewers: This line doesn't need to be escaped.
 * Function blocksy_output_hero_section() used here escapes the value properly.
 */
if (apply_filters('blocksy:single:has-default-hero', true)) {
	echo blocksy_output_hero_section([
		'type' => 'type-2'
	]);
}

$page_structure = blocksy_get_page_structure();

$container_class = 'ct-container-full';
$data_container_output = '';

if ($page_structure === 'none' || blocksy_post_uses_vc()) {
	$container_class = 'ct-container';

	if ($page_structure === 'narrow') {
		$container_class = 'ct-container-narrow';
	}
} else {
	$data_container_output = 'data-content="' . $page_structure . '"';
}


?>

	<div
		class="<?php echo trim($container_class) ?>"
		<?php echo wp_kses_post(blocksy_sidebar_position_attr()); ?>
		<?php echo $data_container_output; ?>
		<?php echo blocksy_get_v_spacing() ?>>

		<?php do_action('blocksy:single:container:top'); ?>

		<?php
			/**
			 * Note to code reviewers: This line doesn't need to be escaped.
			 * Function blocksy_single_content() used here escapes the value properly.
			 */
			echo blocksy_single_content();
		?>

		<?php
		$current_post_id = get_the_ID();
		$random_posts = new WP_Query([
			'post_type'      => 'post',
			'posts_per_page' => 3,
			'orderby'        => 'rand',
			'post__not_in'   => [$current_post_id],
			'post_status'    => 'publish',
		]);

		if ($random_posts->have_posts()) : ?>
			<div class="wow-random-posts">
				<h3 class="wow-random-posts-title">You might be interested in</h3>
				<div class="entries" data-layout="grid" data-cards="boxed">
					<?php while ($random_posts->have_posts()) : $random_posts->the_post(); ?>
						<article class="entry-card post">
							<?php if (has_post_thumbnail()) : ?>
								<a href="<?php the_permalink(); ?>" class="ct-media-container boundless-image" aria-label="<?php echo esc_attr(get_the_title()); ?>">
									<?php the_post_thumbnail('medium_large', ['loading' => 'eager']); ?>
								</a>
							<?php endif; ?>
							<div class="card-content">
								<?php
								$categories = get_the_category();
								if (!empty($categories)) : ?>
									<div class="entry-meta">
										<ul class="post-meta">
											<li class="meta-categories">
												<?php foreach ($categories as $cat) : ?>
													<a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>" class="ct-term"><?php echo esc_html($cat->name); ?></a>
												<?php endforeach; ?>
											</li>
										</ul>
									</div>
								<?php endif; ?>
								<h2 class="entry-title">
									<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
								</h2>
								<div class="entry-excerpt">
									<?php echo wp_trim_words(get_the_excerpt(), 20); ?>
								</div>
								<div class="entry-meta">
									<ul class="post-meta">
										<li class="meta-author">
											<?php echo get_avatar(get_the_author_meta('ID'), 28); ?>
											<a href="<?php echo esc_url(get_author_posts_url(get_the_author_meta('ID'))); ?>"><?php the_author(); ?></a>
										</li>
										<li class="meta-date">
											<?php echo get_the_date(); ?>
										</li>
									</ul>
								</div>
							</div>
						</article>
					<?php endwhile; ?>
				</div>
			</div>
		<?php
		endif;
		wp_reset_postdata();
		?>

		<?php get_sidebar(); ?>

		<?php do_action('blocksy:single:container:bottom'); ?>
	</div>

<?php

blocksy_display_page_elements('separated');

have_posts();
wp_reset_query();


