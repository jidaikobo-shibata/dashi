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
	public static function _addLinkOnTopPosts ($query)
	{
		if (!$query->is_main_query()) return;
		$screen = get_current_screen();
		if (!$screen || $screen->base !== 'edit') return;
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

        global $wp_query;
        $post_type = $wp_query->get('post_type');
        if (!self::canReview($post_type)) return $views;

		// 生成
		$tail = array_slice($views, -1, 1, true);
		$views = array_slice($views, 0, -1, true);

        // 承認待ちと通常の下書きを、対象投稿タイプの権限で閲覧できる利用者に数える。
        $count_args = [
            'post_type' => $post_type,
            'post_status' => 'draft',
            'posts_per_page' => 1,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key' => 'dashi_workflow',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => 'waiting',
        ];
        $waiting = new \WP_Query($count_args);
        $draft = new \WP_Query($count_args + ['meta_compare' => '!=']);

        if ($waiting->found_posts) {
            $url = add_query_arg([
                'post_status' => 'draft',
                'post_type' => $post_type,
                'dashi_workflow' => '1',
            ], admin_url('edit.php'));
            $views['waiting'] = '<a href="' . esc_url($url) . '">' . esc_html__('Approve', 'dashi')
                . '</a> (' . (int) $waiting->found_posts . ')';
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
			if (isset($views['waiting']))
			{
				$views['waiting'] = str_replace('<a ', '<a class="current"', $views['waiting']);
			}
		}

		// draft count
		if (isset($views['draft']))
		{
			$views['draft'] = preg_replace(
				'/\(\d+\)/',
				'('.$draft->found_posts.')',
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
    public static function setQueryShowWaiting ($query)
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_status') !== 'draft') return;
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== $query->get('post_type')) return;

        $workflow = filter_input(INPUT_GET, 'dashi_workflow', FILTER_UNSAFE_RAW);
        $showWaiting = $workflow !== null && $workflow !== false && self::canReview($screen->post_type);

        $condition = [
            'key' => 'dashi_workflow',
            'compare' => $showWaiting ? '=' : '!=',
            'value' => 'waiting',
        ];
        $existing = $query->get('meta_query');
        $query->set('meta_query', $existing ? ['relation' => 'AND', $existing, $condition] : [$condition]);
    }

    private static function canReview($post_type)
    {
        if (!is_string($post_type) || $post_type === '') return false;
        $type = get_post_type_object($post_type);
        return $type && current_user_can($type->cap->edit_posts)
            && current_user_can($type->cap->edit_others_posts);
    }
}
