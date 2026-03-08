<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

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
		foreach (\Dashi\Core\Posttype\Posttype::instances() as $v)
		{
			$posttype = \Dashi\Core\Posttype\Posttype::class2posttype($v);
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
		$html.= '<th class="nowrap">'.__('Title', 'dashi').'</th>';
		$html.= '<th class="nowrap">'.__('Post Type', 'dashi').'</th>';
		$html.= '<th class="nowrap">'.__('Status', 'dashi').'</th>';
		$html.= '</tr>';
		$html.= '</thead>';
		foreach ($posts as $v)
		{
			$class = \Dashi\Core\Posttype\Posttype::posttype2class($v->post_type);
			if ( ! class_exists($class)) continue;

			$html.= '<tr>';
			$edit_str = $v->post_title ? esc_html($v->post_title) : __('(no title)', 'dashi');
			$html.= '<th><a href="'.get_edit_post_link($v->ID).'">'.$edit_str.'</a></th>';

			if (in_array($v->post_type, array('post', 'page')))
			{
				$post_type_obj = get_post_type_object($v->post_type);
				$post_type_label = $v->post_type;
				if (is_object($post_type_obj) && isset($post_type_obj->labels->singular_name)) {
					$post_type_label = $post_type_obj->labels->singular_name;
				}

				$link =
							$v->post_type == 'post' ?
							admin_url('edit.php') :
							admin_url('edit.php?post_type=page') ;

				$html.= '<td class="nowrap"><a href="'.$link.'">'.esc_html($post_type_label).'</a></td>';
			}
			else
			{
				$html.= '<td class="nowrap"><a href="'.admin_url('edit.php?post_type='.$v->post_type).'">'.$class::get('name').'</a></td>';
			}
			$status_label_map = array(
				'future'  => __('Scheduled item', 'dashi'),
				'pending' => __('Pending item', 'dashi'),
				'draft'   => __('Draft item', 'dashi'),
				'private' => __('Private item', 'dashi'),
			);
			$status_label = isset($status_label_map[$v->post_status]) ? $status_label_map[$v->post_status] : $v->post_status;
			$html.= '<td class="nowrap">'.esc_html($status_label).'</td>';
			$html.= '</tr>';
		}
		$html.= '</table>';
		echo $html;
	}
}
