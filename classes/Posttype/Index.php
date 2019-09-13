<?php
namespace Dashi\Core\Posttype;

class Index
{
	/**
	 * addColumn
	 *
	 * @return  array
	 */
	public static function addColumn ($columns)
	{
		if ( ! Input::get('post_type')) return $columns;
		$class = P::posttype2class(Input::get('post_type'));
		if ( ! $class) return $columns;

		// インデクスにカラムを足すものを抽出
		$cs = array();
		foreach ($class::getFlatCustomFields() as $key => $field)
		{
			if (isset($field['add_column']))
			{
				$cs[$key] = $field;
			}
		}
		if ( ! $cs) return $columns;

		// 並び替え
		foreach ($cs as $key => $row)
		{
			$add_column[$key]  = $row['add_column'];
		}
		array_multisort($add_column, SORT_ASC, $cs);

		// 適用
		foreach ($cs as $key => $val)
		{
			$columns[$key] = isset($val['label']) ? $val['label'] : $key;
		}

		return $columns;
	}

	/**
	 * addCustomColumn
	 *
	 * @return  void
	 */
	public static function addCustomColumn ($column_name, $post_id)
	{
		if ( ! Input::get('post_type')) return;
		$class = P::posttype2class(Input::get('post_type'));
		if ( ! $class) return;

		foreach ($class::getFlatCustomFields() as $key => $field)
		{
			if ($column_name != $key) continue;

			if (
				$field['type'] == 'taxonomy'
			)
			{
				$terms = wp_get_post_terms( $post_id, $key, array("fields" => "names") );
				$v = esc_html(join(',', $terms));
			}
			// checkbox or multiple
			else if (
				$field['type'] == 'checkbox' ||
				($field['type'] == 'select' && isset($field['multiple']))
			)
			{
				$v = get_post_meta($post_id, $key);
			}
			// non array
			else
			{
				$v = get_post_meta($post_id, $key, true);
			}

			// 値の表示
			self::displayValue($v, $field);
		}
	}

	/**
	 * displayValue
	 *
	 * @param  string $v
	 * @param  array $field
	 * @return  void
	 */
	private static function displayValue($v, $field)
	{
		// 値がない
		// 文字列の0が来る場合があるのでstrlen()もかける
		if (
			( ! is_array($v) && empty($v) && strlen($v) === 0) ||
			(is_array($v) && isset($v[0]) && strlen($v[0]) === 0)
		)
		{
			echo __('None');
		}
		// そのまま表示
		elseif ( ! isset($field['options']) && $v)
		{
			echo esc_html($v);
		}
		// 配列＆複数
		elseif (isset($field['options']) && is_array($v))
		{
			$arr = array();
			foreach ($v as $vv)
			{
				$arr[] = $field['options'][$vv];
			}
			echo esc_html(join(',', $arr));
		}
		// 選択式
		elseif (isset($field['options']) && ! is_array($v))
		{
			echo esc_html($field['options'][$v]);
		}
	}

	/**
	 * restrictManagePosts
	 *
	 * @return  array
	 */
	public static function restrictManagePosts ()
	{
		if ( ! Input::get('post_type')) return;
		$class = P::posttype2class(Input::get('post_type'));
		if ( ! $class) return;
		global $wpdb;

		// インデクスに抽出を足すもの
		foreach ($class::getFlatCustomFields() as $key => $field)
		{
			if ( ! isset($field['add_restriction']) || ! $field['add_restriction']) continue;

			if (isset($field['type']) && $field['type'] == 'taxonomy')
			{
				$label = isset($field['label']) && $field['label'] ? $field['label'] :'タクソノミー指定なし';
				$html = '<select name="'.$key.'"><option value="">'.$label.'</option>';
				$terms = get_terms($key);
				foreach ($terms as $term)
				{
					$selected = filter_input(INPUT_GET, $key);
					$selected_html = $selected == $term->slug ? 'selected="selected"' : '';
					$html .= '<option value="'.$term->slug.'" '.$selected_html.'>'.$term->name.'</option>';
				}
				$html.= '</select>';
			}
			else
			{
				$options = array();
				if (isset($field['options']))
				{
					$options = $field['options'];
				}
				else
				{
					$sql = 'SELECT '.$wpdb->postmeta.'.`meta_value` FROM '.$wpdb->postmeta.'
						JOIN '.$wpdb->posts.' ON '.$wpdb->postmeta.'.`post_id` = '.$wpdb->posts.'.`ID`
						WHERE '.$wpdb->postmeta.'.`meta_key` = "handle"
							AND '.$wpdb->posts.'.`post_status` IN ("publish", "draft", "future", "private")
						GROUP BY '.$wpdb->postmeta.'.`meta_value`';

					foreach ($wpdb->get_results($sql) as $v)
					{
						$options[$v->meta_value] = $v->meta_value;
					}
				}

				$selected = filter_input(INPUT_GET, $key);
				$html = '';
				$html .= '<select name="' . esc_attr($key) . '">';
				$html .= '<option value="">'.sprintf(__("All of %s", 'dashi'), isset($field['label']) ? $field['label'] : $key).'</option>';
				foreach ($options as $value => $text)
				{
					if (empty($value)) continue;
					$selected_html = selected($selected, $value, false);
					$html .= '<option value="'.esc_attr($value).'"'.$selected_html.'>'.esc_html($text).'</option>';
				}
				$html .= '</select>';
			}

			echo $html;
		}
	}

	/**
	 * preGetPosts
	 *
	 * @return  array
	 */
	public static function preGetPosts ($query)
	{
		if ( ! Input::get('post_type')) return $query;
		$post_type = Input::get('post_type');
		$class = P::posttype2class($post_type);

		if (
			$class &&
			is_admin() &&
			$query->get('post_type') == $post_type &&
			$query->is_main_query()
		)
		{
			foreach ($class::getFlatCustomFields() as $key => $field)
			{
				if (isset($field['type']) && $field['type'] == 'taxonomy') continue;

				if ( ! isset($field['add_restriction']) || ! $field['add_restriction']) continue;
				$value = filter_input(INPUT_GET, $key);
				if (strlen($value))
				{
					$meta_query = $query->get('meta_query');
					if ( ! is_array($meta_query)) $meta_query = array();
					$meta_query['relation'] = 'AND';
					$meta_query[] = array(
						'key' => $key,
						'value' => $value
					);
					$query->set('meta_query', $meta_query);
				}
			}

			return $query;
		}
	}
}
