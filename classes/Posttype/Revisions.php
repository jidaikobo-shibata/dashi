<?php
namespace Dashi\Core\Posttype;

class Revisions
{
	/**
	 * forge
	 */
	public static function forge ()
	{
		// revision更新が必要かどうかを判定するhook 1
		add_filter(
			'wp_save_post_revision_check_for_changes',
			array('\\Dashi\\Core\\Posttype\\Revisions', 'wpSavePostRevisionCheckForChanges'),
			10,
			3
		);

		// revision更新が必要かどうかを判定するhook 2
		add_filter(
			'wp_save_post_revision_post_has_changed',
			array('\\Dashi\\Core\\Posttype\\Revisions', 'wpSavePostRevisionPostHasChanged'),
			10,
			3
		);

		// リビジョン比較画面でメタデータを表示させるためにキーを追加する
		add_filter(
			'_wp_post_revision_fields',
			array('\\Dashi\\Core\\Posttype\\Revisions', '_wpPostRevisionFields')
		);

		// リビジョンからの復元
		add_action(
			'wp_restore_post_revision',
			array('\\Dashi\\Core\\Posttype\\Revisions', 'wpRestorePostRevision'),
			10,
			2
		);

		// リビジョン比較画面にメタデータを表示
		// いったんすべてのカスタムフィールドを取得する
		foreach (P::instances() as $class)
		{
			foreach ($class::getCustomFieldsKeys() as $k)
			{
				add_filter(
					'_wp_post_revision_field_'.$k.'_debug-preview',
					array('\\Dashi\\Core\\Posttype\\Revisions', '_wpPostRevisionFieldDebugPreview'),
					10,
					3
				);
			}
		}
	}

	/**
	 * wp_restore_post_revisionへのhook
	 * リビジョンからの復元
	 *
	 * @param int $post_id
	 * @param int $revision_id
	 * @return void
	 */
	static public function wpRestorePostRevision ($post_id, $revision_id)
	{
		$class = P::postid2class($post_id);
		if ( ! $class) return;

		foreach ($class::getCustomFieldsKeys(true) as $key)
		{
			$vals = get_post_meta($revision_id, $key);
			if (is_null($vals)) continue;

			delete_post_meta($post_id, $key);
			if (count($vals) > 1 && $key != 'google_map')
			{
				foreach ($vals as $v)
				{
					add_metadata('post', $post_id, $key, $v);
				}
			}
			else if ($key == 'google_map')
			{
				add_metadata('post', $post_id, $key, $vals);
			}
			else if (isset($vals[0]))
			{
				add_metadata('post', $post_id, $key, $vals[0]);
			}
		}
	}

	/**
	 * _wp_post_revision_field_へのhook
	 * リビジョン比較画面にメタデータを表示
	 *
	 * @param $value
	 * @param $column
	 * @param array $post
	 * @return string
	 */
	static public function _wpPostRevisionFieldDebugPreview ($value, $column, $post)
	{
		$output = '';

		$class = P::postid2class($post->post_parent);
		if ( ! $class) return $output;

		$key = substr($column, 0, -14); // remove _debug-preview

		// 当該ポストタイプのカスタムフィールドのみ表示する
		if ( ! in_array($key, $class::getCustomFieldsKeys())) return $output;

		// 値の取得
		$custom_fields = $class::get('custom_fields');

		// Wordpressはadd(udpate)_post_meta()で配列を渡すとシリアライズした値を保存するが、
		// dashiでは全消し全入れなので、ここでは、まず配列を取る
		$val = '';
		$tmps = get_post_meta($post->ID, $key);
		if (count($tmps) > 1)
		{
			foreach ($tmps as $tmp)
			{
				if (empty($tmp)) continue;
				$val.= '-'.$tmp."\n";
			}
		}
		else if ( ! empty($tmps))
		{
			$val = $tmps[0];
		}

		// $custom_fields[$key]がない場合はfields
		if ( ! isset($custom_fields[$key]))
		{
			foreach ($custom_fields as $field => $v)
			{
				if (isset($v['fields'][$key]))
				{
					$current_custom_field = $custom_fields[$field]['fields'][$key];
					break;
				}
			}
		}
		else
		{
			$current_custom_field = $custom_fields[$key];
		}

		// array
		$type = isset($current_custom_field['type']) ? $current_custom_field['type'] : '';
		$is_multiple = ($type == 'select' && isset($current_custom_field['attrs']['multiple']));
		$is_multiple = $type == 'checkbox' ? true : $is_multiple;

		if ($is_multiple && is_array($val))
		{
			$arrs = array();
			foreach ($val as $v)
			{
				if (isset($current_custom_field['options'][$v]))
				$arrs[] = $current_custom_field['options'][$v];
			}
			$val = join(', ', $arrs);
		}
		// ほぼないケースだと思われるがcheckboxでもselect multipleでもないarray
		else if (is_array($val))
		{
			$val = join(', ', $val);
		}
		// options
		else if (isset($current_custom_field['options'][$val]))
		{
			$val = $current_custom_field['options'][$val];
		}

		$output.= $val;
		return $output;
	}

	/**
	 * _wp_post_revision_fieldsへのhook
	 * リビジョン比較画面でメタデータを表示させるために保存時にキーを追加する
	 *
	 * @param array $fields
	 * @return array $fields
	 */
	static public function _wpPostRevisionFields ($fields)
	{
		global $post;
		$class = false;
		// normal revision request
		if (isset($_GET['revision']) && is_numeric($_GET['revision']))
		{
			$revision = get_post($_GET['revision']);
			$class = P::postid2class($revision->post_parent);
		}
		// ajax request
		elseif (isset($_POST['post_id']))
		{
			$revision = get_post((int)$_POST['post_id']);
			$class = P::postid2class($revision);
		}
		elseif (isset($post->ID))
		{
			$class = P::postid2class($post->ID);
		}

		if ( ! $class) return $fields;

		foreach ($class::get('custom_fields') as $k => $v)
		{
			if (isset($v['fields']))
			{
				foreach ($v['fields'] as $kk => $vv)
				{
					$fields[$kk.'_debug-preview'] = isset($vv['label']) ? $vv['label'] : $kk ;
				}
			}
			else
			{
				$fields[$k.'_debug-preview'] = isset($v['label']) ? $v['label'] : $k ;
			}
		}

		return $fields;
	}

	/**
	 * wp_save_post_revision_post_has_changed
	 * false ならリビジョンとして保存される1
	 *
	 * @param  bool    $check_for_changes
	 * @param  WP_Post $last_revision
	 * @param  WP_Post $post
	 * @return bool
	 */
	public static function wpSavePostRevisionPostHasChanged (
		$post_has_changed,
		$last_revision,
		$post
	)
	{
		$c = static::wpSavePostRevisionCheckForChanges($post_has_changed, $last_revision, $post);
		return ( ! $c);
	}

	/**
	 * wp_save_post_revision_check_for_changesへのhook
	 * false ならリビジョンとして保存される2
	 *
	 * @param  bool    $check_for_changes
	 * @param  WP_Post $last_revision
	 * @param  WP_Post $post
	 * @return bool
	 */
	public static function wpSavePostRevisionCheckForChanges (
		$check_for_changes,
		$last_revision,
		$post
	)
	{
/*
		$post_meta = array();
		$posted_data = array();
		$class = P::post2class($post);
		if ( ! $class) return true;

		$fields = $class::get('custom_fields');

		foreach ($fields as $key => $value)
		{
			if (in_array($key, array('dashi_sticky', 'dashi_search'))) continue;

			// current data
			if (isset($value['fields']))
			{
				foreach (array_keys($value['fields']) as $inner_key)
				{
					$v = get_post_meta($post->ID, $inner_key, TRUE);
					if ( ! is_null($v))
					{
						$post_meta[$inner_key][] = $v;
					}

					// posted data
					if (isset($_POST[$inner_key]))
					{
						$posted_data[$inner_key][] = $_POST[$inner_key];
					}
				}
			}
			else
			{
				$v = $class::getPostMeta($post->ID, $key);

				if ( ! is_null($v))
				{
					$post_meta[$key][] = $v;
				}

				// posted data
				if (isset($_POST[$key]))
				{
					$posted_data[$key][] = $_POST[$key];
				}
			}
		}
*/

		$serialized_revision_meta = serialize(get_post_meta($last_revision->ID));
		$serialized_post_meta = serialize(get_post_meta($post->ID));

		$is_not_custom_fields_update = $serialized_revision_meta == $serialized_post_meta;
		$is_not_content_updated =
												$last_revision->post_content.$last_revision->post_title.$last_revision->post_excerpt ==
												$post->post_content.$post->post_title.$post->post_excerpt;

		// 値が同じなら、trueが返り、revisionは更新されない
		return $is_not_content_updated && $is_not_custom_fields_update;
	}
}
