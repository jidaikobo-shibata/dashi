<?php
namespace Dashi\Core\Posttype;

class Save
{
	private static $duplicateds = array();

	/**
	 * update custom fields
	 *
	 * @return Void
	 */
	public static function updateCustomFields($post_id)
	{
		if ( ! Input::post()) return;
		if (wp_is_post_revision($post_id)) return $post_id;
		$post_values = Input::post();

		// _dashi_pubic_form_pending_process の際は pendingToPublish で file の値が update
		// されているので file は update された値を使用する
		if(get_post_meta($post_id, '_dashi_pubic_form_pending_process', true))
		{
			$post = get_post($post_id);
			$class = \Dashi\P::posttype2class($post->post_type);
			foreach ($class::getFlatCustomFields() as $k => $v)
			{
				if ( ! isset($v['type'])) continue;
				if ($v['type'] != 'file') continue;

				$post_values[$k] = $post->{$k};
				error_log($post->{$k});
			}

			delete_post_meta($post_id, '_dashi_pubic_form_pending_process');
		}

		static::_updateCustomFields($post_id, $post_values);
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
	public static function _updateCustomFields($post_id, $values)
	{
		if (wp_is_post_revision($post_id)) return;
		$post_type = get_post($post_id)->post_type;
		$class = Posttype::getInstance($post_type);
		$is_default = in_array($post_type, array('page', 'post'));
		if ( ! $class && ! $is_default) return;
		$e = new \WP_Error();

		// duplicatedなmetaboxの削除および順序制御
		$values = self::deleteDuplicatedMetaBox($class, $values);
		$values = self::fixOrderDuplicatedMetaBox($class, $values);

		// database
		if ($class)
		{
			foreach($class::getCustomFieldsKeys() as $key)
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

				// do not store data - ex) privacy data from public form
				if (isset($attrs['store_data']) && $attrs['store_data'] == false) continue;

				// dupped_value?
				$is_dupped = in_array($key, static::$duplicateds);

				// label
				$label = isset($attrs['label']) ? $attrs['label'] : $orig_key;
				$label = $is_dupped ? $attrs['label_origi'] : $label;

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
				self::applyFilter($attrs, $val, $is_dupped);

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
					in_array($attrs['type'], array('google_map'))
				)
				{
					static::cudPostmeta($post_id, $key.'_lat',   @$val['lat'], false);
					static::cudPostmeta($post_id, $key.'_lng',   @$val['lng'], false);
					static::cudPostmeta($post_id, $key.'_zoom',  @$val['zoom'], false);
					static::cudPostmeta($post_id, $key.'_place', @$val['place'], false);
					$is_serialize = true;
				}

				// creat update and delete
				static::cudPostmeta($post_id, $key, $val, $is_serialize);
			}
		}

		if ($e->get_error_messages())
		{
			set_transient('dashi_errors', $e->get_error_messages(), 10);
		}

		// search
		self::updateSearch($class, $is_default, $post_id);
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
						$e->add('errors', sprintf(__($err, 'dashi'), $label.' ('.$idx.')'));
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
					$e->add('errors', sprintf(__($err, 'dashi'), $label));
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
	 * @return Void
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
			$str = static::searchStr($class, $post_id);
			if ($str)
			{
				static::cudPostmeta(
					$post_id,
					'dashi_search',
					$str
				);
			}
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
	private static function searchStr($class, $post_id)
	{
		$custom_fields = $class ? $class::get('custom_fields') : array();

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
						if (isset($fields['options'][$v]))
						{
							$strs[] = $fields['options'][$v];
						}
					}
				}
				else
				{
					if (isset($val[0]) && isset($fields['options'][$val[0]]))
					{
						$strs[] = $fields['options'][$val[0]];
					}
					elseif (isset($val[0]))
					{
						$strs[] = is_array($val[0]) ? join(' ', $val[0]) : $val[0];
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
		foreach ($custom_fields as $field_name => $fields)
		{
			if (in_array($field_name, array('dashi_search', 'dashi_redirect_to', 'dashi_sticky', 'dashi_workflow'))) continue;
			if (isset($fields['is_public_searchable']) && $fields['is_public_searchable'] === false) continue;

			if (isset($fields['fields']))
			{
				foreach ($fields['fields'] as $inner_field_name => $inner_fields)
				{
					$strs = $genStr($post_id, $inner_field_name, $inner_fields, $strs);
				}
			}
			else
			{
				$strs = $genStr($post_id, $field_name, $fields, $strs);
			}
		}

		// expose shortcode
		$post = get_post($post_id);
		if (preg_match_all('/'.get_shortcode_regex().'/s', $post->post_content, $ms))
		{
			$strs[] = Search::generateSearchStr(apply_filters('the_content', $post->post_content));
		}

		$ret = join(' ', $strs);
		$ret = Search::generateSearchStr($ret);

		return $ret;
	}

	/**
	 * show error message
	 *
	 * @return Void
	 */
	public static function showMessages()
	{
		$messages = get_transient('dashi_errors');
		if ($messages)
		{
			$html = '<div class="notice error is-dismissible"><section><ul>';
			foreach ($messages as $message)
			{
				$html.= '<li>'.$message.'</li>';
			}
			$html.= '</ul></section></div>';
			echo $html;
			delete_transient('dashi_errors');
		}
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
//	public static function autoPostSlug ($slug, $post_ID, $post_status, $post_type)
	{
		$class = Posttype::getInstance($post_type);
		if ( ! $class || ! $class::get('is_use_force_ascii_slug')) return $slug;

		if (Input::post('dashi_bind_slug'))
		{
			$slug = esc_html(Input::post('dashi_bind_slug'));
		}
		elseif (preg_match('/(%[0-9a-f]{2})+/', $slug))
		{
			$slug = utf8_uri_encode($post_type) . '-' . $post_ID;
		}
		return $slug;
	}
}
