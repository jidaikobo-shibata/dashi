<?php
namespace Dashi\Core\Posttype;

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
		$current_page  = $post->ID;
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
				$items[$posttype]['link'] = '<a href="'.$url.'">'.$list->label.'</a>';
			}

			// depth
			$max_depth = $class::get('sitemap_depth');
			$max_depth = $posttype == 'page' ? $page_depth : $max_depth;
			$items[$posttype]['max_depth'] = $max_depth;

			// html
			$items[$posttype]['list_html'] = '';

			// $max_depthがゼロなのは、ポストタイプアーカイブへのリンクのみ返しておしまい
			if ($max_depth == 0) continue;

			// 記事を取得
			$wp_list_pages_args['post_type'] = $posttype;
			$wp_list_pages_args['depth'] = $max_depth;
			$wp_list_pages_args['sort_column'] = 'menu_order';
			$list_html = wp_list_pages($wp_list_pages_args);

			// $list_htmlが空になるのはカスタムポストタイプのhierarchicalが
			// falseの場合なので、最初の階層を取る。
			if (empty($list_html))
			{
				$get_posts_arg = $wp_list_pages_args;
				$get_posts_arg['numberposts'] = -1;
				$eaches = get_posts($get_posts_arg);
				foreach ($eaches as $each)
				{
					$class = 'page_item page-item-'.$each->ID;

					// 祖先
					if (
						isset($_current_page->ancestors) &&
						in_array($each->ID, (array) $_current_page->ancestors)
					)
					{
						$class.= ' current_page_ancestor';
					}

					// 自分自身か親
					if ($each->ID == $current_page)
					{
						$class.= ' current_page_item';
					}
					elseif ($_current_page && $post->ID == $_current_page->post_parent)
					{
						$class.= ' current_page_parent';
					}

					// wp_list_pages()が返すのと同じような構造を返す
					$list_html.= '<li class="'.$class.'">';
					$list_html.= '<a href="'.get_permalink($each->ID).'">'.$each->post_title."</a></li>\n";
				}
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
			$home_str = get_option('dashi_sitemap_home_string') ?: __('Home');
			$items['page']['link'] = '<a href="'.home_url().'">'.$home_str.'</a>';
		}

		return $items;
	}

	/**
	 * shortcode
	 *
	 * @return  mixed
	 */
	public static function shortcode ($params)
	{
		$items = static::generate();
		$h = isset($params['h']) ? $params['h'] : 'h2';

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
