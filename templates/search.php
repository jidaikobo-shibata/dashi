<?php
if (!defined('ABSPATH')) exit;
    global $wp_query, $post;
    get_header();
?>

<!-- entry -->
<section class="entry-list">
<h1><?php echo esc_html(wp_get_document_title()); ?></h1>
<p><?php
    /* translators: %s: number of matched pages. */
    $dashi_found_pages_message = __('Found %s pages.', 'dashi');
    echo esc_html(
        sprintf($dashi_found_pages_message, have_posts() ? (int) $wp_query->found_posts : 0)
    );
?></p>

<?php
// have_posts start here
if (have_posts()):
?>

<dl class="search_results">
<?php
while (have_posts()): the_post();

// post type
$dashi_additional_information = '';
$dashi_post_type = get_post_type($post);
if ( ! in_array($dashi_post_type, array('post', 'page')))
{
    $dashi_post_type_obj = $dashi_post_type ? get_post_type_object($dashi_post_type) : null;

    if (class_exists('\\Dashi\\Core\\Posttype\\Posttype'))
    {
        $dashi_class = \Dashi\Core\Posttype\Posttype::getInstance($dashi_post_type);
        if (class_exists($dashi_class))
        {
            if ( ! $dashi_class::get('is_redirect') && substr($dashi_post_type, 0, 1) != '_')
            {
                $dashi_additional_information = ' <span class="link2archive">(<a href="'.
                    esc_url(get_post_type_archive_link($dashi_post_type)).'">'.
                    esc_html($dashi_post_type_obj->label).'</a>)</span>';
            }
        }
    }
}

// summary
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook を利用。
$dashi_summary = $post->post_excerpt ?: apply_filters('the_content', $post->post_content);
$dashi_summary = trim($dashi_summary);
?>

<dt><a href="<?php echo esc_url(get_permalink($post->ID)); ?>"><?php echo esc_html($post->post_title ?: __('(No Subject)', 'dashi')); ?></a><?php echo wp_kses_post($dashi_additional_information); ?></dt>
<?php if ($dashi_summary): ?>
<dd><?php echo esc_html(wp_trim_words($dashi_summary)); ?></dd>
<?php
endif;
endwhile;
?>
</dl>

<?php
the_posts_pagination(array(
    'prev_text' => __('Previous', 'dashi'),
    'next_text' => __('Next', 'dashi'),
));

endif;
?>
</section><!-- /entry -->
<?php
// have_posts ends here
get_footer();
