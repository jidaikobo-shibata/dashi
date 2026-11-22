<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Save
{
    private static $bulkSearchDeletions = [];
    private static $incompleteBulkSearchDeletions = [];
    public const SEARCH_GENERATION_RETRY = 'dashi_search_generation_retry';
	private static $duplicateds = array();

	/**
	 * update custom fields
	 *
	 * @return Void
	 */
	public static function updateCustomFields($post_id)
	{
		global $pagenow;
		if ($pagenow != 'post.php') return; // 編集ページのみで動作
		$post_values = Input::post();
		if (wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) return;
		$submitted_id = filter_var($post_values['post_ID'] ?? '', FILTER_VALIDATE_INT);
		$nonce = $post_values['_wpnonce'] ?? '';
		if ($submitted_id !== (int) $post_id || !is_string($nonce)
			|| !wp_verify_nonce($nonce, 'update-post_' . $post_id)) return;

		static::_updateCustomFields($post_id, $post_values);

		// メディアの説明文は、編集画面の保存からだけ更新する。
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 値は updateFileMediaText() で検証する。
		$texts = wp_unslash($_POST['dashi_file_media_text'] ?? []);
		if (is_array($texts)) self::updateFileMediaText($texts);
	}

	/**
	 * delete duplicated meta box
	 *
	 * @return Array
	 */
	private static function deleteDuplicatedMetaBox($class, $values)
	{
		// duplicatedなmetaboxの削除制御
		$dashi_dels = filter_input(INPUT_POST, 'dashi_dels', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);

		if ($dashi_dels)
		{
			foreach ($dashi_dels as $dashi_del)
			{
				foreach ($dashi_del as $each)
				{
					$keys = explode('::', $each);
					$idx = array_shift($keys);

					foreach ($keys as $each_key)
					{
						unset($values[$each_key][$idx]);
					}
				}
			}
		}
		return $values;
	}

	/**
	 * fix order of duplicated meta box values
	 * ugly code... :(
	 * @return Array
	 */
	private static function fixOrderDuplicatedMetaBox($class, $values)
	{
		$dashi_odrs = filter_input(INPUT_POST, 'dashi_odrs', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
		if ( ! $dashi_odrs) return $values;

		foreach ($dashi_odrs as $key => $odrs)
		{
			$odrs = array_map('intval', $odrs);

			// 順番のみの配列を作っておく
			$simple_odrs = array();
			foreach ($odrs as $kk => $vv)
			{
				$keys = explode('::', $kk);
				$idx = array_shift($keys);
				$simple_odrs[$idx] = $vv;
			}

			// odrsのキーは (現在のkey)::(フィールドの名前1)::(フィールドの名前2) -> $kk
			// odrsの値は並ぶ順番 -> $vv
			list($values, $new) = self::genNewOrder4DuplicatedMetaBox($odrs, $simple_odrs, $values);

			// あたらしいvalueをつくる
			foreach ($new as $vvv)
			{
				unset($vvv['dashi_field_order']); // 並び終えたので、もお用無し
				// join()して、空文字だったらハツる
				$temp = join($vvv);
				if (empty($temp)) continue;

				foreach ($keys as $each_key)
				{
					$values[$each_key][] = $vvv[$each_key];
				}
			}
		}

		// order用postをPOST
		unset($values['dashi_odrs']);

		return $values;
	}


    /**
     * updateFileMediaText
     *
     * @return void
     */
    private static function updateFileMediaText(array $texts)
    {
        foreach ($texts as $media_id => $text) {
            $media_id = filter_var($media_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$media_id || !is_string($text) || !current_user_can('edit_post', $media_id)) continue;
            $attachment = get_post($media_id);
            if (!$attachment || $attachment->post_type !== 'attachment') continue;

            $value = sanitize_text_field($text);
            // 画像かどうかは、リクエストのファイル名ではなく登録済みの添付から判定する。
            if (wp_attachment_is_image($attachment)) {
                update_post_meta($media_id, '_wp_attachment_image_alt', wp_slash($value));
            } elseif ($attachment->post_title !== $value) {
                wp_update_post(['ID' => $media_id, 'post_title' => wp_slash($value)]);
            }
        }
    }

    /**
	 * genNewOrder4DuplicatedMetaBox
	 *
	 * @param array $ordrs
	 * @param array $simple_odrs
	 * @param array $values
	 * @return array
	 */
	public static function genNewOrder4DuplicatedMetaBox($odrs, $simple_odrs, $values)
	{
		// odrsのキーは (現在のkey)::(フィールドの名前1)::(フィールドの名前2) -> $kk
		// odrsの値は並ぶ順番 -> $vv
		$new = array();
		foreach ($odrs as $kk => $vv)
		{
			$keys = explode('::', $kk);
			array_shift($keys);

			// $valueに戻す前に、まとめて順番を修正する
			// $keysには、フィールドの名前
			foreach ($keys as $each_key)
			{
				// save hookの途中で使う
				static::$duplicateds[] = $each_key;

				if ( ! isset($values[$each_key])) continue;
				foreach ($values[$each_key] as $kkk => $vvv)
				{
					$new[$kkk]['dashi_field_order'] = $simple_odrs[$kkk];
					$new[$kkk][$each_key] = $vvv;
				}
			}
		}

		// array_multisortように作り直し（いかにも泥臭い……）
		$dashi_field_order = array();
		foreach ($new as $kkkk => $row)
		{
			$dashi_field_order[$kkkk] = $row['dashi_field_order'];
		}
		array_multisort($dashi_field_order, SORT_ASC, $new);

		// newができているので、古いvalueをハツる
		// $keysの使い回しがやや気持ち悪いが……
		foreach ($keys as $each_key)
		{
			$values[$each_key] = array();
		}
		return array($values, $new);
	}

	/**
	 * update custom fields
	 *
	 * @return Void
	 */
	public static function _updateCustomFields($post_id, $values, $verify = false)
	{
		if (wp_is_post_revision($post_id)) return $verify ? false : null;
		$post_type = get_post($post_id)->post_type;
		$class = Posttype::getInstance($post_type);
		$is_default = in_array($post_type, array('page', 'post'));
		if ( ! $class && ! $is_default) return $verify ? false : null;
		$e = new \WP_Error();
		$expected = [];
		$write = static function ($key, $value, $serialize = false) use ($post_id, $verify, &$expected) {
			if (!$verify) {
				static::cudPostmeta($post_id, $key, $value, $serialize);
				return;
			}
			$values = is_array($value) && !$serialize ? array_values($value) : [$value];
			$expected[$key] = self::writeVerifiedCustomField($post_id, $key, $values);
		};

		// duplicatedなmetaboxの削除および順序制御
		$values = self::deleteDuplicatedMetaBox($class, $values);
		$values = self::fixOrderDuplicatedMetaBox($class, $values);

		// database
		if ($class)
		{
			$keys = $verify ? CustomFieldExpectedKeyBuilder::build(
				$class::get('custom_fields'),
				$class::get('is_redirect'),
				$class::get('is_use_sticky'),
				$class::get('is_searchable')
			) : $class::getCustomFieldsKeys();
			foreach($keys as $key)
			{
				// search
				if ($key == 'dashi_search') continue;

				// getCustomFieldsKeys()が、配列を返す時、配列形式を改める
				$orig_key = $key;
				if (strpos($key, '[') !== false)
				{
					$key = preg_replace("/\[\d*?\]/", '', $key);
				}

				// クイック編集なのに値が明示的に与えられていなければcontinue
				if (
					! isset($values[$key]) &&
					(isset($values['action']) && $values['action'] == 'inline-save')
				) continue;

				// checkbox問題があるので、isset($values[$key])で追い返せない。
				$val = isset($values[$key]) ? $values[$key] : '';

				// attrs
				$attrtmp = $class::getFlatCustomFields();
				$attrs = isset($attrtmp[$orig_key]) ? $attrtmp[$orig_key] : array();

				if (isset($attrs['type']) && $attrs['type'] === 'google_map')
				{
					$val = self::sanitizeGoogleMapValue($val);
				}

				// do not store data - ex) privacy data from public form
				if (isset($attrs['store_data']) && $attrs['store_data'] == false) continue;

				// dupped_value?
				$is_dupped = in_array($key, static::$duplicateds);

				// label
				$label = isset($attrs['label']) ? $attrs['label'] : $orig_key;
				$label_origi = isset($attrs['label_origi']) ? $attrs['label_origi'] : '';
				$label = $is_dupped ? $label_origi : $label;

				// uploadの場合はルート相対パスを使う
				if (
					isset($attrs['type']) &&
					$attrs['type'] == 'file' &&
					get_option('dashi_remove_host_at_upload_file')
				)
				{
					$val = Util::removeHost($val);
				}

				// add error - validators
				self::aplyValidation($attrs, $val, $is_dupped, $label, $e);

				// filters
				$val = self::applyFilter($attrs, $val, $is_dupped);

				// add error - require
				self::addError($attrs, $val, $e, $label);

				// eliminateControlCodes
				if (get_option('dashi_do_eliminate_control_codes'))
				{
					$val = \Dashi\Core\Util::eliminateControlCodes($val);
				}

				// eliminateUtfSeparation
				if (get_option('dashi_do_eliminate_utf_separation'))
				{
					$val = \Dashi\Core\Util::eliminateUtfSeparation($val);
				}

				// original filter
				$val = apply_filters('dashi_save_post_value', $val, $post_type, $key);

				// serialize
				$is_serialize = false;
				if (
					isset($attrs['type']) &&
					in_array($attrs['type'], array('google_map')) &&
					is_array($val)
				)
				{
					$write($key.'_lat',   @$val['lat'], false);
					$write($key.'_lng',   @$val['lng'], false);
					$write($key.'_zoom',  @$val['zoom'], false);
					$write($key.'_place', @$val['place'], false);
					$is_serialize = true;
				}

				// creat update and delete
				$write($key, $val, $is_serialize);
			}
		}

		$messages = $e->get_error_messages();
		if (!$verify && $messages && get_current_user_id() && current_user_can('edit_post', $post_id)) {
			set_transient(self::saveNoticeKey($post_id), $messages, 10);
		}

		if ($verify) {
			// 受け取った入力が保存対象のキー一覧から漏れた場合も送信失敗とする。
			if (array_diff_key($values, $expected, ['dashi_search' => true])
				|| !self::verifyStoredPostMeta($post_id, $expected)) return false;
		}

		// search
		self::updateSearch($class, $is_default, $post_id);
		if ($verify) return true;
	}


    private static function writeVerifiedCustomField($postId, $key, array $values)
    {
        delete_post_meta($postId, $key);

        $written = [];
        // WordPress がサニタイズして保存した値を、再サニタイズせずに取得する。
        $capture = static function ($metaId, $objectId, $metaKey, $value) use ($postId, $key, &$written) {
            if ((int) $objectId === (int) $postId && $metaKey === $key) {
                $value = maybe_serialize($value);
                $written[$metaId] = $value === null ? null : (string) $value;
            }
        };
        add_action('added_post_meta', $capture, PHP_INT_MIN, 4);
        try {
            $expected = [];
            foreach ($values as $value) {
                $metaId = add_post_meta($postId, $key, $value);
                if (!$metaId || !array_key_exists($metaId, $written)) return null;
                $expected[] = $written[$metaId];
            }
            return $expected;
        } finally {
            remove_action('added_post_meta', $capture, PHP_INT_MIN);
        }
    }

    /** キーごとに、DB上の文字列または NULL の一覧を照合する。 */
    public static function verifyStoredPostMeta($postId, array $expected, $lockRows = false)
    {
        global $wpdb;
        $lock = $lockRows ? ' FOR UPDATE' : '';
        // 読み取りフィルターやキャッシュを経由せず、実際に書き込まれた行を照合する。
        // SQL の末尾に追加できるのは固定の FOR UPDATE 句だけ。
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC{$lock}",
            $postId
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($wpdb->last_error !== '' || !is_array($rows)) return false;
        $stored = [];
        foreach ($rows as $row) {
            if (array_key_exists($row['meta_key'], $expected)) {
                $stored[$row['meta_key']][] = $row['meta_value'];
            }
        }
        foreach ($expected as $key => $values) {
            if ($values !== ($stored[$key] ?? [])) return false;
        }
        return true;
    }


	/**
	 * Google Map の値を保存前に型・範囲検証する。
	 *
	 * @param mixed $value
	 * @return array<string, mixed>
	 */
	private static function sanitizeGoogleMapValue($value)
	{
		if (!is_array($value)) return array();

		$lat = isset($value['lat']) && is_numeric($value['lat']) ? (float) $value['lat'] : null;
		$lng = isset($value['lng']) && is_numeric($value['lng']) ? (float) $value['lng'] : null;
		$zoom = isset($value['zoom']) ? (int) $value['zoom'] : 13;

		return array(
			'place' => sanitize_text_field((string) ($value['place'] ?? '')),
			'lat' => $lat !== null && is_finite($lat) && $lat >= -90 && $lat <= 90 ? $lat : '',
			'lng' => $lng !== null && is_finite($lng) && $lng >= -180 && $lng <= 180 ? $lng : '',
			'zoom' => min(21, max(1, $zoom)),
		);
	}

	/**
	 * aplyValidation
	 *
	 * @param  array $attrs
	 * @param  mixed $val
	 * @param  bool $is_dupped
	 * @param  string $label
	 * @param  object $e
	 * @return Void
	 */
	private static function aplyValidation($attrs, $val, $is_dupped, $label, $e)
	{
		if ( ! isset($attrs['validations']) || empty($attrs['validations'])) return;

		$is_err = false;
		foreach ($attrs['validations'] as $validator)
		{
			$tmp = is_array($val) ? $val : trim($val);
			if ( ! $tmp) continue;
			$method = 'validate'.ucfirst($validator);

			// duppedな値は配列なので、個別にvalidate
			if ($is_dupped && is_array($val))
			{
				foreach ($val as $k => $each)
				{
					$err = Validation::$method($each);
					if ($err !== true)
					{
						$is_err = true;
						$idx = $k+1;
							$e->add('errors', sprintf($err, $label.' ('.$idx.')'));
					}
				}
			}
			// 非array
			else
			{
				$err = Validation::$method($val);
				if ($err !== true)
				{
					$is_err = true;
						$e->add('errors', sprintf($err, $label));
				}
			}
		}
		if ($is_err && isset($attrs['filters']) && $attrs['filters'])
		{
			$e->add('errors', __('some of errors are automatically fixed by filters. confirm please.', 'dashi'));
		}
	}

	/**
	 * applyFilter
	 *
	 * @param  array $attrs
	 * @param  mixed $val
	 * @param  bool $is_dupped
	 * @return mixed
	 */
	private static function applyFilter($attrs, $val, $is_dupped)
	{
		if (isset($attrs['filters']) && $attrs['filters'])
		{
			foreach ($attrs['filters'] as $filter)
			{
				$filter = strtolower($filter);

				// duppedな値は配列なので、個別にfilter
				if ($is_dupped && is_array($val))
				{
					foreach ($val as $k => $each)
					{
						$val[$k] = \Dashi\Core\Filter::$filter($each);
					}
				}
				// 非array
				else
				{
					$val = \Dashi\Core\Filter::$filter($val);
				}
			}
		}
		return $val;
	}

	/**
	 * addError
	 *
	 * @param  array $attrs
	 * @param  mixed $val
	 * @param  object $e
	 * @param  string $label
	 * @return Void
	 */
	private static function addError($attrs, $val, $e, $label)
	{
		// dupではarrayがくるが、duppedはいつも空の値があるので、要一考。
		if (isset($attrs['attrs']) && $attrs['attrs'])
		{
			// checkbox, multiple select
			$tmp = is_array($val) ? $val : trim($val);
			if (
				! (isset($attrs['public_form_only']) && $attrs['public_form_only'] === true) &&
				isset($attrs['attrs']['required']) &&
				(
					(is_array($tmp) && empty($tmp)) ||
					( ! is_array($tmp) && strlen($tmp) == 0)
				)
			)
			{
					/* translators: %s: required field label. */
					$e->add('errors', sprintf(__("%s is required", 'dashi'), $label));
			}
		}
	}

	/**
	 * updateSearch
	 *
	 * @param  object $class
	 * @param  bool $is_default
	 * @param  integer $post_id
	 * @return Void
	 */
	private static function updateSearch($class, $is_default, $post_id)
	{
		if (($class && $class::get('is_searchable')) || $is_default)
		{
			$post = get_post($post_id);
			if (!$post) return;
			// フィールドを読む前の世代を索引に記録する。書き込み順が逆転しても旧世代は一致しない。
			$generation = get_post_meta($post_id, Search::FIELD_GENERATION_META, true);
			$fields = self::publicSearchFields($class, $post->post_name);
			if ($fields === null) return;
			$policy = self::publicSearchPolicy($class, $post->post_name, $fields);
			$epoch = Search::savedIndexEpoch();
			$str = static::searchStr($class, $post, $fields);
			wp_cache_delete($post_id, 'post_meta');
			if ($generation !== get_post_meta($post_id, Search::FIELD_GENERATION_META, true)) return;

			if ($str)
			{
				static::cudPostmeta(
					$post_id,
					'dashi_search',
					$str
				);
                $source = $post->post_title . "\0" . $post->post_content . "\0" . $generation . $policy . $epoch;
                update_post_meta($post_id, Search::TEXT_INDEX_META, wp_slash(Search::packIndexValue($source, $str)));
			}
			else
			{
				delete_post_meta($post_id, 'dashi_search');
                delete_post_meta($post_id, Search::TEXT_INDEX_META);
			}
		}
	}

    public static function refreshSearchIndex($post)
    {
        $class = P::post2class($post);
        self::updateSearch($class, in_array($post->post_type, ['page', 'post'], true), $post->ID);
    }

    public static function refreshSearchAfterMetaChange($metaId, $postId, $key)
    {
        if (!self::canAffectSearchGeneration($key)) return;
        if (!$postId) {
            $batch = get_current_blog_id() . ':' . $key;
            $posts = self::$bulkSearchDeletions[$batch] ?? [];
            unset(self::$bulkSearchDeletions[$batch]);
            if (isset(self::$incompleteBulkSearchDeletions[$batch])) {
                unset(self::$incompleteBulkSearchDeletions[$batch]);
                Search::invalidateSavedIndexEpoch();
            }
            foreach ($posts as $id) self::refreshSearchAfterMetaChange(null, $id, $key);
            return;
        }
        $post = get_post($postId);
        if (!$post || $post->post_type === 'revision') return;
        $class = P::post2class($post);
        if (!$class) return;
        // 定義から外れているキーも、再登録されると旧索引に一致し得る。
        self::rotateSearchGeneration($post);
    }

    private static function canAffectSearchGeneration($key)
    {
        return strpos($key, '_dashi_search_') !== 0
            && !in_array($key, ['dashi_search', 'dashi_redirect_to', 'dashi_sticky', 'dashi_workflow',
                'dashi_original_id', '_edit_lock', '_edit_last'], true);
    }

    public static function captureBulkSearchDeletion($delete, $postId, $key, $value, $deleteAll)
    {
        if ($delete !== null || !$deleteAll || !self::canAffectSearchGeneration($key)) {
            return $delete;
        }
        global $wpdb;
        $batch = get_current_blog_id() . ':' . $key;
        unset(self::$bulkSearchDeletions[$batch]);
        unset(self::$incompleteBulkSearchDeletions[$batch]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 削除前の所有投稿の照合とDBエラーの検出。
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $key
        ));
        if ($wpdb->last_error !== '' || !is_array($rows)) return false;
        self::$bulkSearchDeletions[$batch] = array_column($rows, 'post_id', 'meta_id');
        return $delete;
    }

    public static function matchBulkSearchDeletion($metaIds, $postId, $key)
    {
        if ($postId || !self::canAffectSearchGeneration($key)) return;
        $batch = get_current_blog_id() . ':' . $key;
        $known = self::$bulkSearchDeletions[$batch] ?? [];
        $posts = [];
        foreach ($metaIds as $metaId) {
            $id = $known[$metaId] ?? null;
            if (!$id) {
                $row = get_metadata_by_mid('post', $metaId);
                if (!$row) {
                    // 削除対象を確認できないときは旧補助索引を一括で無効化する。
                    self::$incompleteBulkSearchDeletions[$batch] = true;
                    continue;
                }
                $id = $row->post_id;
            }
            $posts[$id] = $id;
        }
        self::$bulkSearchDeletions[$batch] = $posts;
        if (isset(self::$incompleteBulkSearchDeletions[$batch])) Search::invalidateSavedIndexEpoch();
    }

    public static function publicSearchPolicy($class, $slug = null, $fields = null, $slugFields = null)
    {
        $fields = $fields ?? self::publicSearchFields($class, $slug, $slugFields);
        if ($fields === null) return null;
        $policy = [];
        foreach ($fields as $key => $field) {
            $policy[$key] = [isset($field['type']), $field['label'] ?? '', $field['options']];
        }
        ksort($policy, SORT_STRING);
        return hash('sha256', $policy ? serialize($policy) : '');
    }

    public static function retrySearchGeneration($postId)
    {
        $post = get_post($postId);
        if ($post && get_option(Search::FIELD_BLOCK_OPTION_PREFIX . $post->ID)) {
            self::rotateSearchGeneration($post);
        }
    }

    private static function rotateSearchGeneration($post)
    {
        $option = Search::FIELD_BLOCK_OPTION_PREFIX . $post->ID;
        $blocked = get_option($option);
        $args = [(int) $post->ID];
        if (!update_post_meta($post->ID, Search::FIELD_GENERATION_META, wp_generate_uuid4())) {
            update_option($option, wp_generate_uuid4(), false);
            delete_post_meta($post->ID, Search::TEXT_INDEX_META);
            if (!wp_next_scheduled(self::SEARCH_GENERATION_RETRY, $args)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_GENERATION_RETRY, $args);
            }
            return;
        }

        self::refreshSearchIndex($post);
        if (!$blocked) return;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 並行処理が追加した新しい停止状態は解除しない。
        $cleared = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option, $blocked
        ));
        if ($cleared) wp_cache_delete($option, 'options');
        if (!$cleared && !wp_next_scheduled(self::SEARCH_GENERATION_RETRY, $args)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SEARCH_GENERATION_RETRY, $args);
        }
    }

	/**
	 * update custom_fields
	 *
	 * @return Void
	 */
	public static function cudPostmeta($post_id, $key, $val, $is_serialize = false)
	{
		// delete all
		delete_post_meta($post_id, $key) ;

		// 配列を個別にadd_post_meta()する。シリアライズされた配列を一つのキーに保存しないため
		if (is_array($val) && ! $is_serialize)
		{
			foreach($val as $each_val)
			{
				add_post_meta($post_id, $key, $each_val, FALSE) ;
			}
			return ;
		}

		// 更新
		update_post_meta($post_id, $key, $val) ;
		return;
	}

	/**
	 * generate search str
	 *
	 * @return Void
	 */
	private static function searchStr($class, $post, $fields = null)
	{
		$post_id = $post->ID;
		// closure
		$genStr = function ($post_id, $field_name, $fields, $strs)
		{
			// stored value
			$val = get_post_meta($post_id, $field_name);

			// type
			if (isset($fields['type']))
			{
				if (count($val) > 1)
				{
					foreach ($val as $v)
					{
						if ((is_int($v) || is_string($v)) && isset($fields['options'][$v]))
						{
							$strs[] = $fields['options'][$v];
						}
					}
				}
				else
				{
					if ((is_int($val[0] ?? null) || is_string($val[0] ?? null)) && isset($fields['options'][$val[0]]))
					{
						$strs[] = $fields['options'][$val[0]];
					}
					elseif (isset($val[0]))
					{
						$strs[] = is_array($val[0]) ? join(' ', array_filter($val[0], 'is_scalar'))
							: (is_scalar($val[0]) ? $val[0] : '');
					}
				}
			}

			// label
			if (isset($fields['label']))
			{
				$strs[] = $fields['label'];
			}
			return $strs;
		};

		// main loop
		$strs = array();
		foreach (($fields ?? self::publicSearchFields($class, $post->post_name)) ?? [] as $field_name => $attributes) {
			$strs = $genStr($post_id, $field_name, $attributes, $strs);
		}
		// add title
		$strs[] = $post->post_title;

        // 索引には保存済みテキストだけを使い、ショートコードを実行しない。
        $strs[] = Search::savedText($post->post_content);

		$ret = join(' ', $strs);
		$ret = Search::generateSearchStr($ret);

		return $ret;
	}

	private static function publicSearchFields($class, $slug = null, $slugFields = null)
	{
		$definitions = $class ? CustomFields::searchFieldsForSlug($class, $slug, $slugFields) : [];
		if ($definitions === null) return null;
		$result = [];
		foreach ($definitions as $key => $fields) {
			if (!self::canAffectSearchGeneration($key) || !self::isPublicSearchField($fields)) continue;
			foreach ($fields['fields'] ?? [$key => $fields] as $field => $attributes) {
				if (!self::canAffectSearchGeneration($field) || !self::isPublicSearchField($attributes)) continue;
				$options = $attributes['options'] ?? null;
				// 動的な選択肢は、公開検索で実行せずにラベルの公開範囲を検証できない。
				if (is_callable($options)) continue;
				$attributes['options'] = is_array($options) ? array_filter($options, 'is_scalar') : [];
				if (isset($attributes['label']) && !is_scalar($attributes['label'])) unset($attributes['label']);
				ksort($attributes['options'], SORT_STRING);
				$result[$field] = $attributes;
			}
		}
		return $result;
	}

	private static function isPublicSearchField(array $field)
	{
		$searchable = $field['is_public_searchable'] ?? null;
		return ($field['store_data'] ?? true) != false && $searchable !== false
			&& (empty($field['private_form_only']) || $searchable === true);
	}

	/**
	 * show error message
	 *
	 * @return Void
	 */
	public static function showMessages()
	{
		$post_id = filter_var(Input::get('post'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if (!$post_id || !get_current_user_id() || !current_user_can('edit_post', $post_id)) return;

		$key = self::saveNoticeKey($post_id);
		$messages = get_transient($key);
		if ($messages)
		{
			$html = '<div class="notice error is-dismissible"><section><ul>';
			foreach ($messages as $message)
			{
				$html.= '<li>'.$message.'</li>';
			}
			$html.= '</ul></section></div>';
				echo wp_kses_post($html);
			delete_transient($key);
		}
	}

	private static function saveNoticeKey($post_id)
	{
		return 'dashi_errors_' . get_current_user_id() . '_' . (int) $post_id;
	}

	/*
	 * auto_post_slug()
	 * thx http://wordpress-obo.egaki.net/wordpress-all/plugin/post-82/
	 *
	 * @param  string  $slug
	 * @param  int     $post_ID
	 * @param  string  $post_status
	 * @param  string  $post_type
	 * @return String
	 */
	public static function autoPostSlug ($override_slug, $slug, $post_ID, $post_status, $post_type, $post_parent)
	{
		static $checking = false;
		if ($checking || $override_slug !== null) return $override_slug;
		$class = Posttype::getInstance($post_type);
		if (!$class || !$class::get('is_use_force_ascii_slug')) return $override_slug;

		$candidate = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 候補は sanitize_title() に通す。
		$bound = wp_unslash($_POST['dashi_bind_slug'] ?? '');
		if (is_string($bound) && $bound !== '' && is_admin() && current_user_can('edit_post', $post_ID)) {
			$request = Input::post();
			$nonce = $request['_wpnonce'] ?? '';
			if (filter_var($request['post_ID'] ?? '', FILTER_VALIDATE_INT) === (int) $post_ID
				&& is_string($nonce) && wp_verify_nonce($nonce, 'update-post_' . $post_ID)) {
				$candidate = sanitize_title($bound);
			}
		}
		if ($candidate === '' && preg_match('/(%[0-9a-f]{2})+/', $slug)) {
			$candidate = utf8_uri_encode($post_type) . '-' . $post_ID;
		}
		if ($candidate === '' || $candidate === $slug) return $override_slug;

		// 候補を差し替えた後も、WordPressの重複・予約語チェックを通す。
		$checking = true;
		try {
			return wp_unique_post_slug($candidate, $post_ID, $post_status, $post_type, $post_parent);
		} finally {
			$checking = false;
		}
	}
}
