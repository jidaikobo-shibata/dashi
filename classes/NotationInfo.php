<?php
namespace Dashi\Core;

trait NotationInfo
{
	/**
	 * addDashboardGlanceItems
	 * ダッシュボードの概要欄に任意のポストタイプを追加
	 *
	 * @return Array
	 */
	public static function addDashboardGlanceItems($args)
	{
		foreach (\Dashi\P::instances() as $v)
		{
			$posttype = \Dashi\P::class2posttype($v);
			if (in_array($posttype, array('post', 'page'))) continue;

			$obj = get_post_type_object($posttype);
			if (is_object($obj) && ! $obj->show_in_nav_menus) continue;

			$num = wp_count_posts($posttype);
			if (is_object($num) && isset($num->publish) && $num->publish)
			{
				$label = $obj->label;
				$str = $label.'&nbsp;('.number_format_i18n( $num->publish ).')';
				$args[] = '<a href="edit.php?post_type='.$posttype.'" class="'.$posttype.'-count">'.$str.'</a>';
			}
		}
		return $args;
	}

	/**
	 * unseenContentsList
	 * ダッシュボードのpendingやfutureの記事の一覧を表示
	 *
	 * @return Void
	 */
	public static function unseenContentsList()
	{
		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'future',
		);
		$future = get_posts($args);

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'pending',
		);
		$pending = get_posts($args);

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'draft',
		);
		$draft = get_posts($args);

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'private',
		);
		$private = get_posts($args);

		$posts = array_merge($future, $pending, $draft, $private);

		if ( ! $posts) return;

		$html = '';
		$html.= '<table class="dashi_tbl">';
		$html.= '<thead>';
		$html.= '<tr>';
		$html.= '<th class="nowrap">'.__('Title').'</th>';
		$html.= '<th class="nowrap">'.__('Post Type').'</th>';
		$html.= '<th class="nowrap">'.__('Status').'</th>';
		$html.= '</tr>';
		$html.= '</thead>';
		foreach ($posts as $v)
		{
			$class = \Dashi\Core\Posttype\Posttype::posttype2class($v->post_type);
			if ( ! class_exists($class)) continue;

			$html.= '<tr>';
			$edit_str = $v->post_title ? esc_html($v->post_title) : __('(no title)');
			$html.= '<th><a href="'.get_edit_post_link($v->ID).'">'.$edit_str.'</a></th>';

			if (in_array($v->post_type, array('post', 'page')))
			{
				$link =
							$v->post_type == 'post' ?
							admin_url('edit.php') :
							admin_url('edit.php?post_type=page') ;

				$html.= '<td class="nowrap"><a href="'.$link.'">'.__(ucfirst($v->post_type)).'</a></td>';
			}
			else
			{
				$html.= '<td class="nowrap"><a href="'.admin_url('edit.php?post_type='.$v->post_type).'">'.$class::get('name').'</a></td>';
			}
			$html.= '<td class="nowrap">'.__($v->post_status.' item', 'dashi').'</td>';
			$html.= '</tr>';
		}
		$html.= '</table>';
		echo $html;
	}
}
