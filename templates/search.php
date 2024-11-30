<?php
	global $wp_query, $post;
	get_header();
?>

<!-- entry -->
<section class="entry-list">
<h1><?php echo wp_get_document_title() ?></h1>
<p><?php echo sprintf(__('Found %s pages.', 'dashi'), have_posts() ? $wp_query->found_posts : 0); ?></p>

<?php
// have_posts start here
if (have_posts()):
?>

<dl class="search_results">
<?php
while (have_posts()): the_post();

// post type
$additional_information = '';
$post_type = get_post_type($post);
if ( ! in_array($post_type, array('post', 'page')))
{
	$post_type_obj = $post_type ? get_post_type_object($post_type) : null;

	if (class_exists('\\Dashi\\Core\\Posttype\\Posttype'))
	{
		$class = \Dashi\Core\Posttype\Posttype::getInstance($post_type);
		if (class_exists($class))
		{
			if ( ! $class::get('is_redirect') && substr($post_type, 0, 1) != '_')
			{
				$additional_information = ' (<a href="'.get_post_type_archive_link($post_type).'">'.$post_type_obj->label.'</a>)';
			}
		}
	}
}

// summary
$summary = $post->post_excerpt ?: apply_filters('the_content', $post->post_content);
$summary = trim($summary);
?>

<dt><a href="<?php echo get_permalink($post->ID); ?>"><?php echo $post->post_title ?: __('(No Subject)', 'dashi'); ?></a><?php echo $additional_information ?></dt>
<?php if ($summary): ?>
<dd><?php echo wp_trim_words($summary); ?></dd>
<?php
endif;
endwhile;
?>
</dl>

<?php
the_posts_pagination(array(
  'prev_text' => __('Previous'),
	'next_text' => __('Next'),
));

endif;
?>
</section><!-- /entry -->
<?php
// have_posts ends here
get_footer();
