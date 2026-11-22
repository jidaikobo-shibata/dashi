<?php
namespace Dashi\Posttype;

if (!defined('ABSPATH')) exit;

class Pagepart extends \Dashi\Core\Posttype\Base
{
    private const MAX_EMBED_DEPTH = 16;
    private const MAX_EMBEDS_PER_REQUEST = 128;
    private static $rendering = [];
    private static $renderCount = 0;

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
        $slug = $attrs['slug'] ?? '';
        if (!is_string($slug) || $slug === '') {
            return '「' . esc_html__('slug', 'dashi') . '」 is missing.<br />sample: <code>[get_pagepart slug=home]</code>';
        }

        if (self::$renderCount >= self::MAX_EMBEDS_PER_REQUEST) return '';

        $item = get_page_by_path($slug, OBJECT, ['pagepart']);
        $html = '<div class="dashi_pagepart_wrapper">';
        if ($item && $item->post_status === 'publish') {
            $id = (int) $item->ID;
            if (isset(self::$rendering[$id]) || count(self::$rendering) >= self::MAX_EMBED_DEPTH) return '';
            ++self::$renderCount;
            self::$rendering[$id] = true;
            $hadPost = array_key_exists('post', $GLOBALS);
            $previousPost = $GLOBALS['post'] ?? null;
            try {
                // パスから特定した投稿にも、サイトの投稿クエリーによる閲覧制限を適用する。
                $matches = get_posts([
                    'p' => $id,
                    'post_type' => 'pagepart',
                    'post_status' => 'publish',
                    'numberposts' => 1,
                    'suppress_filters' => false,
                    'cache_results' => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]);
                $item = $matches[0] ?? null;
                if (!$item instanceof \WP_Post || (int) $item->ID !== $id
                    || $item->post_type !== 'pagepart' || $item->post_status !== 'publish') return '';

                // 閲覧制限や入れ子のショートコードが、処理中のパーツを参照できるようにする。
                $GLOBALS['post'] = $item;
                $rendered = \Dashi\Core\Util::withSingularPostQuery($item, static function () use ($item) {
                    if (!apply_filters('dashi_search_can_show_result', true, $item)) return null;
                    if (post_password_required($item)) return get_the_password_form($item);
                    $content = preg_replace("/\<!--[^-]+?--\>/is", '', $item->post_content);
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook を利用。
                    return (string) apply_filters('the_content', $content);
                });
                if ($rendered === null) return '';
                $html .= $rendered;
            } finally {
                if ($hadPost) {
                    $GLOBALS['post'] = $previousPost;
                } else {
                    unset($GLOBALS['post']);
                }
                unset(self::$rendering[$id]);
            }

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
