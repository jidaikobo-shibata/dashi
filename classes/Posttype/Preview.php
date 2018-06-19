<?php
namespace Dashi\Core\Posttype;

class Preview
{
	/**
	 * forge
	 */
	public static function forge ()
	{
		// プレビュー画面にメタデータを反映
		add_filter(
			'get_post_metadata',
			array('\\Dashi\\Core\\Posttype\\Preview', 'getPostMetadata'),
			10,
			4
		);

		// プレビュー用にメタデータを保存
		add_filter(
			'wp_insert_post',
			array('\\Dashi\\Core\\Posttype\\Preview', 'wpInsertPost')
		);

		// プレビュー時にメタデータを保存するためにキーとなる項目を出力する
		add_action(
			'edit_form_after_title',
			array('\\Dashi\\Core\\Posttype\\Preview', 'editFormAfterTitle')
		);
	}

	/**
	 * editFormAfterTitle
	 * プレビュー時にメタデータを保存するためにキーとなる項目を出力する
	 * @return void
	 */
	static public function editFormAfterTitle()
	{
		global $post;
		$class = P::post2class($post);
		if ( ! $class) return;

		foreach ($class::getCustomFieldsKeys() as $key)
		{
			printf(
				'<input type="hidden" name="%1$s" value="%1$s" />',
				$key . '_debug-preview'
			);
		}
	}

	/**
	 * getPostMetadata
	 * プレビューのときはプレビューのメタデータを返す
	 *
	 * @param mixed $value
	 * @param int $post_id
	 * @param string $meta_key
	 * @param bool $single
	 * @return mixed $value
	 */
	static public function getPostMetadata($value, $post_id, $meta_key, $single)
	{
		if ( ! is_preview())
		{
			return $value;
		}

		$class = P::postid2class($post_id);

		if ( ! $class) return $value;

		$fields = $class::getCustomFieldsKeys();

		if (is_null($fields)) return $value;

		$preview_id = static::get_preview_id($post_id);

		if ($preview_id && $meta_key !== '_thumbnail_id')
		{
			if ($post_id !== $preview_id)
			{
				$value = get_post_meta($preview_id, $meta_key, $single);
			}
		}
		return $value;
	}

	/**
	 * wpInsertPost
	 * プレビュー用にメタデータを保存
	 *
	 * @param int $post_id preview_id
	 * @	return void
	 */
	static public function wpInsertPost ($post_id)
	{
		global $wpdb;

		if (wp_is_post_revision($post_id))
		{
			// プレビューの既存値を削除
			$sql = $wpdb->prepare("DELETE FROM ".$wpdb->postmeta." WHERE post_id = %d", $post_id);
			$wpdb->query($sql);

			// fieldの確保
			$post = get_post($post_id);
			$class = P::postid2class($post->post_parent);
			if ( ! $class) return;
			$fields = $class::getCustomFieldsKeys();

			$post_metas = apply_filters('preview_post_meta_keys', $fields);

			foreach ($post_metas as $post_meta)
			{
				if ( ! isset($_POST[$post_meta])) continue;
				add_metadata('post', $post_id, $post_meta, $_POST[$post_meta]);
			}
			do_action('save_preview_postmeta', $post_id);
		}
	}

	/**
	 * get_preview_id
	 *
	 * @param  int    $post_id
	 * @return int
	 */
	static public function get_preview_id($post_id)
	{
		global $post;
		$preview_id = 0;
		if ($post->ID == $post_id && is_preview() && $preview = wp_get_post_autosave($post->ID))
		{
			$preview_id = $preview->ID;
		}
		return $preview_id;
	}
}
