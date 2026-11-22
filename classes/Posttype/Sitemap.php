<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Sitemap
{
	/**
	 * generate
	 *
	 * @return  array
	 */
	public static function generate ($args = array())
	{
		$page_depth = get_option('dashi_sitemap_depth_of_page');
		$ignores = array('post');
		if ($page_depth <= 0)
		{
			$ignores[] = 'page';
		}

		// args
		$defaults = array(
			'ignores' => $ignores,
			'is_menu' => false,
			'wp_list_pages_args' => array(
				'echo' => 0, // don't use false
				'title_li' => '',
			),
		);
		$args = wp_parse_args($args, $defaults);
		extract($args, EXTR_SKIP);

		// currents
		global $post;
		$current_page = (int) ($post->ID ?? 0);
		$_current_page = $post;
		get_post_ancestors($_current_page);
		$current_posttype = get_post_type();

		// loop
		$items = array();
		foreach (\Dashi\Core\Posttype\Posttype::instances() as $class)
		{
			$posttype = \Dashi\Core\Posttype\Posttype::class2posttype($class);

			// ignore
			if (in_array($posttype, $ignores)) continue;

			$list = get_post_type_object($posttype);

			// 表示しないもの
			if ( ! is_object($list) || ! $list->show_in_nav_menus) continue;

			// メインメニュー等で使う場合
			$items[$posttype]['is_current'] = ($list->name == $current_posttype);

			// アーカイブページのaタグ
			if ($posttype != 'page')
			{
				$url = get_post_type_archive_link($list->name);
				$items[$posttype]['label'] = $list->label;
				$items[$posttype]['url'] = $url;
				$items[$posttype]['link'] = '<a href="'.esc_url($url).'">'.esc_html($list->label).'</a>';
			}

			// depth
			$max_depth = $class::get('sitemap_depth');
			$max_depth = $posttype == 'page' ? $page_depth : $max_depth;
			$items[$posttype]['max_depth'] = $max_depth;

			// html
			$items[$posttype]['list_html'] = '';

			// $max_depthがゼロなのは、ポストタイプアーカイブへのリンクのみ返しておしまい
			if ($max_depth == 0 || !is_post_type_viewable($list)) continue;

			// 記事を取得
			$wp_list_pages_args['post_type'] = $posttype;
			$wp_list_pages_args['depth'] = $max_depth;
			$wp_list_pages_args['sort_column'] = 'menu_order';
            if ($list->hierarchical) {
                $items[$posttype]['list_html'] = self::listVisiblePages($wp_list_pages_args, $posttype);
                continue;
            }

            $list_html = '';
            $get_posts_arg = $wp_list_pages_args;
            $get_posts_arg['numberposts'] = -1;
            $get_posts_arg['post_status'] = 'publish';
            $get_posts_arg['suppress_filters'] = false;
            foreach (get_posts($get_posts_arg) as $each) {
                if (!self::canListPost($each, $posttype)) continue;
                $classes = 'page_item page-item-' . (int) $each->ID;
                if (isset($_current_page->ancestors) && in_array($each->ID, (array) $_current_page->ancestors)) {
                    $classes .= ' current_page_ancestor';
                }
                if ($each->ID == $current_page) {
                    $classes .= ' current_page_item';
                } elseif ($_current_page && $post->ID == $_current_page->post_parent) {
                    $classes .= ' current_page_parent';
                }
                $list_html .= '<li class="' . esc_attr($classes) . '">';
                $list_html .= '<a href="' . esc_url(get_permalink($each->ID)) . '">'
                    . esc_html($each->post_title) . "</a></li>\n";
            }
			$items[$posttype]['list_html'] = $list_html;
		}

		// 固定ページを後に表示する
		if ( ! get_option('dashi_sitemap_page_upsidedown'))
		{
			if (isset($items['page']))
			{
				$page = $items['page'];
				unset($items['page']);
				$items['page'] = $page;
			}
		}

		if (isset($items['page']))
		{
			// 固定ページに見出しをつける
				$home_str = get_option('dashi_sitemap_home_string') ?: __('Home', 'dashi');
			$items['page']['link'] = '<a href="'.esc_url(home_url()).'">'.esc_html($home_str).'</a>';
		}

		return $items;
	}

    private static function canListPost($post, $posttype)
    {
        return $post instanceof \WP_Post && $post->post_type === $posttype && $post->post_status === 'publish'
            && !post_password_required($post) && apply_filters('dashi_search_can_show_result', true, $post);
    }

    private static function listVisiblePages(array $args, $posttype)
    {
        // WordPressの階層描画を維持し、この一覧に渡す投稿だけを絞る。
        $marker = new \stdClass();
        $args['dashi_sitemap_marker'] = $marker;
        $args['echo'] = false;
        $args['post_status'] = 'publish';
        $filterPages = static function ($pages, $queryArgs) use ($marker, $posttype) {
            if (($queryArgs['dashi_sitemap_marker'] ?? null) !== $marker) return $pages;

            $visible = [];
            foreach ($pages as $post) {
                if (!self::canListPost($post, $posttype)) continue;
                // Walker_Page はタイトルをそのまま出力するため、共有キャッシュの投稿を変えずに対処する。
                $page = clone $post;
                $page->post_title = esc_html($page->post_title);
                $visible[] = $page;
            }
            return $visible;
        };
        $queryFilters = static function ($queryArgs, $pageArgs) use ($marker) {
            if (($pageArgs['dashi_sitemap_marker'] ?? null) === $marker) $queryArgs['suppress_filters'] = false;
            return $queryArgs;
        };
        add_filter('get_pages_query_args', $queryFilters, 10, 2);
        add_filter('get_pages', $filterPages, PHP_INT_MAX, 2);
        try {
            return wp_list_pages($args);
        } finally {
            remove_filter('get_pages_query_args', $queryFilters, 10);
            remove_filter('get_pages', $filterPages, PHP_INT_MAX);
        }
    }

	/**
	 * shortcode
	 *
	 * @return  mixed
	 */
	public static function shortcode ($params)
	{
		$items = static::generate();
		$h = isset($params['h']) && is_string($params['h']) ? strtolower($params['h']) : 'h2';
		if (!in_array($h, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
			$h = 'h2';
		}

		$html = '';
		foreach ($items as $posttype => $item)
		{
			if (isset($item['link']))
			{
				$html.= '<'.$h.' class="dashi_sitemap_title '.$posttype.'">'.$item['link'].'</'.$h.'>'."\n";
			}
			if (empty($item['list_html'])) continue;
			$html.= '<ul class="dashi_sitemap_ul '.$posttype.'">'.$item['list_html'].'</ul>'."\n";
		}
		return $html;
	}
}
