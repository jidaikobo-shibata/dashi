<?php
namespace Dashi\Posttype;

if (!defined('ABSPATH')) exit;

class Pagepart extends \Dashi\Core\Posttype\Base
{
    /*
     * init
     */
    public static function __init ()
    {
        // settings
        static::set('name', 'Page Part');
        static::set('description', static::t('posttype.pagepart.description'));
        static::set('order', 2);
        static::set('is_searchable', true);
        static::set('is_redirect', true);
        static::set('is_use_force_ascii_slug', true);
        static::set('is_use_sticky', false);
        static::set('show_in_nav_menus', false);
        static::set('has_archive', false);
        static::set('custom_fields', array(
                'dashi_bind_slug' => array(
                    'type' => 'hidden',
                    'value' => esc_html(\Dashi\Core\Input::get('slug')),
            )));

        // supports
        static::set('supports', array(
                'title',
                'editor',
                'author',
                'thumbnail',
                'revisions',
            ));

        // shortcode
        add_shortcode("get_pagepart", array('\\Dashi\\Posttype\\Pagepart', 'get_pagepart'));

        // pagepart assets
        add_action(
            'wp_enqueue_scripts',
            function ()
            {
	                wp_enqueue_style(
	                    'dashi_css_pagepart',
	                    plugins_url('assets/css/pagepart.css', DASHI_FILE),
	                    array(),
	                    '1.1'
	                );
	                wp_enqueue_script(
	                    'dashi_js_pagepart',
	                    plugins_url('assets/js/pagepart.js', DASHI_FILE),
	                    array('jquery'),
	                    '1.1',
	                    true
	                );
	            }
	        );
    }

    /*
     * get_pagepart
     */
    public static function get_pagepart($attrs)
    {
        // musts
        $musts = array(
            'slug' => __('slug', 'dashi'),
        );

        // error
        $errors = array();
        foreach ($musts as $key => $must)
        {
            if (array_key_exists($key, $attrs) && empty($attrs[$key]) || ! isset($attrs[$key]))
            {
                $errors[] = '「'.$musts[$key].'」';
            }
        }

        if ($errors)
        {
            $retval = join($errors).' is missing.<br />';
            $retval.= 'sample: <code>[get_pagepart slug=home]</code>';
            return $retval;
        }

        $slug = esc_html($attrs['slug']);

        $item = get_page_by_path($slug, object, 'pagepart');
        $html = '';
        $html.= '<div class="dashi_pagepart_wrapper">';
        if ($item && $item->post_status=='publish')
        {
            // ignore comment out
            $content = preg_replace("/\<!--[^-]+?--\>/is", '', $item->post_content);

	            if ( ! post_password_required($item->ID)):
	                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook を利用。
	                $html.= @apply_filters('the_content', $content);
	            else:
                $html.= get_the_password_form();
            endif;

            if (current_user_can('edit_post', $item->ID))
            {
                $edit_url = get_edit_post_link($item->ID, 'raw');
                if ($edit_url)
                {
                    $html.= '<a class="edit_link" href="'.esc_url($edit_url).'">[EDIT "'.esc_html($item->post_title).'"]</a>';
                }
            }
        }
        else
        {
            if (current_user_can('edit_posts'))
            {
                // 新規作成
                // \Dashi\Hooks::auto_post_slug()に依存
                $create_url = add_query_arg(
                    array(
                        'post_type' => 'pagepart',
                        'slug' => $slug,
                    ),
                    admin_url('post-new.php')
                );
                $html.= '<a class="edit_link" href="'.esc_url($create_url).'">[CREATE "'.esc_html($slug).'"]</a>';
            }
        }
        $html.= '</div>';

        return $html;
    }
}
