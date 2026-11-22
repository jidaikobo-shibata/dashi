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
			// リビジョンから復元されても、編集者に許可されたHTMLの範囲を守る。
			return current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
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

		if ( ! in_array($meta_key, Revisions::storedFieldKeys($class, $fields), true))
		{
			return $value;
		}

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
    public static function wpInsertPost($post_id)
    {
        $parent_id = wp_is_post_revision($post_id);
        if (!$parent_id || !is_admin() || !current_user_can('edit_post', $parent_id)) return;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下で親投稿の保存nonceを検証し、各値は保存時に処理する。
        $submitted = $_POST;
        $id_key = 'post_ID';
        if (wp_doing_ajax()) {
            if (($submitted['action'] ?? '') !== 'heartbeat') return;
            $submitted = $submitted['data']['wp_autosave'] ?? [];
            if (!is_array($submitted)) return;
            $id_key = 'post_id';
        }

        $nonce = $submitted['_wpnonce'] ?? '';
        if (filter_var($submitted[$id_key] ?? '', FILTER_VALIDATE_INT) !== (int) $parent_id
            || !is_string($nonce) || !wp_verify_nonce($nonce, 'update-post_' . $parent_id)) return;
        $class = P::postid2class($parent_id);
        if (!$class) return;

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 既存互換のカスタムフック名。
        $keys = (array) apply_filters('preview_post_meta_keys', $class::getCustomFieldsKeys(true));
        foreach (Revisions::storedFieldKeys($class, $keys) as $key) {
            $value = $submitted[$key] ?? null;
            if (!is_string($value) && !is_array($value)) continue;

            // 実際に送信された対象フィールドだけを更新する。
            delete_metadata('post', $post_id, $key);
            if ($value === '') continue;
            $value = self::sanitizePreviewMetaValue($key, $value, $class);
            foreach (is_array($value) && $key !== 'google_map' ? $value : [$value] as $item) {
                add_metadata('post', $post_id, $key, wp_slash($item));
            }
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 既存互換のカスタムフック名。
        do_action('save_preview_postmeta', $post_id);
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
        if (!is_preview() || !is_user_logged_in() || !isset($post->ID)
            || (int) $post->ID !== (int) $post_id || !current_user_can('edit_post', $post_id)) {
            return 0;
        }
        $requested_id = Input::get('preview_id');
        $nonce = Input::get('preview_nonce');
        if (!is_scalar($requested_id) || (int) $requested_id !== (int) $post_id
            || !is_string($nonce) || !wp_verify_nonce($nonce, 'post_preview_' . (int) $post_id)) {
            return 0;
        }
        // 他の編集者の自動保存をプレビューへ混ぜない。
        $preview = wp_get_post_autosave($post_id, get_current_user_id());
        return $preview ? (int) $preview->ID : 0;
    }
}
