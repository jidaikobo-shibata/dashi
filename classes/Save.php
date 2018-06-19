<?php
/**
 * Dashi\Core\Save
 */
namespace Dashi\Core;

class Save
{
	/**
	 * hooks
	 *
	 * @param  Integer $post_id
	 * @return Integer
	 */
	public static function hooks ($post_id)
	{
		// 適用するページのみ
		global $pagenow;
		if ($pagenow != 'post.php') return; // 編集ページのみで動作
		if ( ! Input::post()) return;

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id;
		if (wp_is_post_revision($post_id)) return $post_id;
		$post = get_post($post_id);
		$is_filtered = false;

		// posted value
		$post_title   = filter_input(INPUT_POST, 'post_title');
		$post_content = filter_input(INPUT_POST, 'content');

		if ( ! $post_title && ! $post_content) return $post_id;

		// eliminateControlCodes
		if (get_option('dashi_do_eliminate_control_codes'))
		{
			$post_title = \Dashi\Core\Util::eliminateControlCodes($post_title);
			$post_content = \Dashi\Core\Util::eliminateControlCodes($post_content);
			$is_filtered = true;
		}

		// eliminateUtfSeparation
		$e = new \WP_Error();
		if (get_option('dashi_do_eliminate_utf_separation'))
		{
			if ( ! class_exists('Normalizer') && ! class_exists('I18N_UnicodeNormalizer'))
			{
				$e->add('errors', __('There is no functions to normalize unicode separation', 'dashi'));
			}
			else
			{
				$post_title = \Dashi\Core\Util::eliminateUtfSeparation($post_title);
				$post_content = \Dashi\Core\Util::eliminateUtfSeparation($post_content);
				$is_filtered = true;
			}
		}

		// other filters
		$post_title = apply_filters(
			'dashi_save_post_value',
			$post_title,
			$post->post_type,
			'post_title'
		);
		$post_content = apply_filters(
			'dashi_save_post_value',
			$post_content,
			$post->post_type,
			'post_content'
		);

		if ($is_filtered)
		{
			global $wpdb;
			// wp_update_post()を使うと、カスタムフィールド等影響箇所が多いので、SQLで
			$sql = 'UPDATE '.$wpdb->posts;
			$sql.= ' SET `post_title` = %s,';
			$sql.= ' `post_content` = %s';
			$sql.= ' WHERE `ID` = %d;';
			$sql = $wpdb->prepare($sql, $post_title, $post_content, $post->ID);
			$wpdb->query($sql);
		}

		if ($e->get_error_messages())
		{
			set_transient('dashi_errors', $e->get_error_messages(), 10);
		}

		return $post_id;
	}
}
