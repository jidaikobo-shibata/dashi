<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Preview
{
	/**
	 * プレビュー保存時の値をフィールド型に応じて整形
	 *
	 * @param string $post_meta
	 * @param mixed $value
	 * @param string|bool $class
	 * @return mixed
	 */
	private static function sanitizePreviewMetaValue($post_meta, $value, $class)
	{
		if (is_array($value))
		{
			return array_map(
				static function ($item) use ($post_meta, $class)
				{
					return self::sanitizePreviewMetaValue($post_meta, $item, $class);
				},
				$value
			);
		}

		if (! is_string($value))
		{
			return $value;
		}

		$value = wp_unslash($value);

		if (! $class || ! method_exists($class, 'getFlatCustomFields'))
		{
			return sanitize_text_field($value);
		}

		$flat_fields = $class::getFlatCustomFields();
		$field = isset($flat_fields[$post_meta]) && is_array($flat_fields[$post_meta])
			? $flat_fields[$post_meta]
			: [];
		$field_type = isset($field['type']) ? (string) $field['type'] : '';

		if (in_array($field_type, ['textarea', 'wysiwyg'], true))
		{
			return $value;
		}

		return sanitize_text_field($value);
	}

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
					esc_attr($key . '_debug-preview')
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
	 * プレビュー用にメタデータを保存（リビジョンでも保存されることが判明）
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
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- プレビュー用リビジョンのメタを明示削除。
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->postmeta} WHERE post_id = %d",
						$post_id
					)
				);

			// fieldの確保
			$post = get_post($post_id);
			$class = P::postid2class($post->post_parent);
			if ( ! $class) return;
			$fields = $class::getCustomFieldsKeys(true);

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 既存互換のカスタムフック名。
				$post_metas = apply_filters('preview_post_meta_keys', $fields);

				foreach ($post_metas as $post_meta)
				{
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- post 編集系の保存導線で呼ばれる。
					$post_val = filter_input(INPUT_POST, $post_meta, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- post 編集系の保存導線で呼ばれる。
					$post_scalar = filter_input(INPUT_POST, $post_meta, FILTER_UNSAFE_RAW);
					$vals = is_array($post_val) ? $post_val : $post_scalar;
					if ($vals === null || $vals === false || $vals === '') continue;
					$vals = self::sanitizePreviewMetaValue($post_meta, $vals, $class);
					if (is_array($vals) && $post_meta != 'google_map')
					{
						foreach ($vals as $v)
						{
							add_metadata('post', $post_id, $post_meta, $v);
						}
					}
					else
					{
						add_metadata('post', $post_id, $post_meta, $vals);
					}
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 既存互換のカスタムフック名。
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
