<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Workflow
{
	/**
	 * forge
	 *
	 * @return  void
	 */
	public static function forge ()
	{
		if ( ! is_admin()) return;
		$post_type = filter_input(INPUT_GET, 'post_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		$post_type = is_string($post_type) ? sanitize_key(wp_unslash($post_type)) : '';
		if ($post_type === '') return;

		$class = P::posttype2class($post_type);
		if (!$class) return;

		if ( ! $class::get('is_use_workflow')) return;

		// hooks
		add_action(
			'pre_get_posts',
			array('\\Dashi\\Core\\Posttype\\Workflow', 'setQueryShowWaiting')
		);

		add_action(
			'pre_get_posts',
			array('\\Dashi\\Core\\Posttype\\Workflow', '_addLinkOnTopPosts')
		);
	}

	/**
	 * _addLinkOnTopPosts
	 *
	 * @return  void
	 */
	public static function _addLinkOnTopPosts ()
	{
		$screen = get_current_screen();
		add_filter(
			'views_'.$screen->id,
			array('\\Dashi\\Core\\Posttype\\Workflow', 'addLinkOnTopPosts')
		);
	}

	/**
	 * addLinkOnTopPosts
	 *
	 * @return  void
	 */
	public static function addLinkOnTopPosts ($views)
	{
		// 管理画面のみ
		if ( ! is_admin()) return $views;
		if (count($views) == 0) return $views;

		global $wp_query, $wpdb;

		if ( ! isset($wp_query->query['post_type'])) return $views;

		$post_type = $wp_query->query['post_type'];

		// edit_others_postsかそれに準じたhas_cap
		if (
			! current_user_can('edit_others_posts') &&
			! current_user_can('edit_others_'.$post_type)
		) return $views;

		// 生成
		$tail = array_slice($views, -1, 1, true);
		$views = array_slice($views, 0, -1, true);

		// count
			$waiting = new \Wp_Query(array(
				'post_type'  => $post_type,
				'status'     => 'draft',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => 'dashi_workflow',
				'compare'    => '=',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => 'waiting',
			));

			$draft = new \Wp_Query(array(
				'post_type'  => $post_type,
				'status'     => 'draft',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => 'dashi_workflow',
				'compare'    => '!=',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => 'waiting',
			));

		// link for approve
		if ($waiting->post_count)
		{
			$views['waiting'] = '<a href="edit.php?post_status=draft&amp;post_type='.$post_type.'&amp;dashi_workflow=1">'.__('Approve', 'dashi').'</a> ('.$waiting->post_count.')';
		}

		// recover
		$views[key($tail)] = $tail[key($tail)];

		// draftの見え方
		$dashi_workflow = filter_input(INPUT_GET, 'dashi_workflow', FILTER_UNSAFE_RAW);
		if ($dashi_workflow !== null && $dashi_workflow !== false)
		{
			// currentを取り除く
			if (isset($views['draft']))
			{
				$views['draft'] = str_replace(' class="current"', '', $views['draft']);
			}

			// currentを与える
			if (isset($views['draft']))
			{
				$views['waiting'] = str_replace('<a ', '<a class="current"', $views['waiting']);
			}
		}

		// draft count
		if (isset($views['draft']))
		{
			$views['draft'] = preg_replace(
				'/\(\d+\)/',
				'('.$draft->post_count.')',
				$views['draft']
			);
		}

		return $views;
	}

	/**
	 * setQueryShowWaiting
	 *
	 * @return  void
	 */
	public static function setQueryShowWaiting ()
	{
		if ( ! is_admin()) return;
		global $wp_query, $wpdb;

		if ( ! isset($wp_query->query['post_type'])) return;
		$post_type = $wp_query->query['post_type'];

		// dashi_workflowの有無でdraftの表示内容を変更する
		if(isset($wp_query->query['post_status']) && $wp_query->query['post_status'] == 'draft')
		{
				$dashi_workflow = filter_input(INPUT_GET, 'dashi_workflow', FILTER_UNSAFE_RAW);
				$comparison_operator = ($dashi_workflow !== null && $dashi_workflow !== false) ? '=' : '!=';
			$wp_query->set('meta_query', array(
				array(
					'key'     => 'dashi_workflow',
					'compare' => $comparison_operator,
					'value'   => 'waiting',
				),
			));
		}
	}



}
