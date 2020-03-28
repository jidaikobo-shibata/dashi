<?php
namespace Dashi\Core\Posttype;

class CustomFields
{
	protected static $expected_keys = array();
	protected static $custom_fields_flat = array(); // expose inner fields

	private static $taxonomy_to_radios = array();

	/**
	 * excludes
	 *
	 * @return  array
	 */
	public static function excludes ()
	{
		return array('dashi_sticky', 'dashi_search', 'dashi_original_id');
	}

	/**
	 * add custom fields
	 *
	 * @return  void
	 */
	public static function addCustomFields ()
	{
		$post_id = filter_input(INPUT_GET, 'post');
		$action = filter_input(INPUT_GET, 'action');
		$post_type = filter_input(INPUT_GET, 'post_type');

		if ( ! $post_type && $post_id && $action)
		{
			$post = get_post($post_id);
			if ( ! $post) return;
			$post_type = $post->post_type;
		}
		// non edit page
		elseif ( ! $post_type)
		{
			return;
		}

		foreach (P::instances() as $class)
		{
			// remove from menu
			if ($class::get('is_hidden'))
			{
				remove_menu_page('edit.php?post_type='.$class::get('post_type'));
			}

			// カスタムフィールドの並び順をユーザに変更させない
			if ( ! $class::get('allow_move_meta_boxes'))
			{
				add_filter(
					'get_user_option_meta-box-order_'.P::class2posttype($class),
					'__return_false'
				);
			}

			// カスタムフィールドは当該ポストタイプのときだけ設定すれば良い
			if ($post_type != P::class2posttype($class)) continue;

			// add redirect
			if ($class::get('is_redirect') && $class !== 'Dashi\\Posttype\\Editablehelp')
			{
				$arr = $class::get('custom_fields');
				$arr['dashi_redirect_to'] = array(
					'type'        => 'text',
					'label'       => __('Redirect To', 'dashi'),
					'description' => __('Enter root relative path. empty, move to the top page.', 'dashi'),
				);
				$arr = $class::set('custom_fields', $arr);
			}

			// add sticky
			if (
				$class::get('is_use_sticky') &&
				P::class2posttype($class) != 'page' &&
				P::class2posttype($class) != 'post'
			)
			{
				$arr = $class::get('custom_fields');
				$arr['dashi_sticky'] = array(
					'label'    => __('Sticky'),
					'callback' => array('\\Dashi\\Core\\Posttype\\Sticky', 'metaBox'),
					'context'  => 'side',
					'priority' => 'high',
				);
				$arr = $class::set('custom_fields', $arr);
			}

			// add search
			if ($class::get('is_searchable'))
			{
				$arr = $class::get('custom_fields');
				$arr['dashi_search'] = array(
					'type' => 'hidden',
				);
				$arr = $class::set('custom_fields', $arr);
			}

			// dashi_mod_custom_fields hook
			$mod_arr = apply_filters(
				'dashi_mod_custom_fields',
				array(),
				P::class2posttype($class),
				$class::get('custom_fields')
			);
			if ($mod_arr)
			{
				$class::set('custom_fields', $mod_arr);
			}

			// add custom fields
			foreach ($class::get('custom_fields') as $key => $value)
			{
				// is callback?
				$method = isset($value['callback']) ? $value['callback'] : '';
				$callback_class = $class;
				if (is_array($method))
				{
					$callback_class = $method[0];
					$method = $method[1];
				}
				$callback = method_exists($callback_class, $method) ?
									array($callback_class, $method) :
									array();

				// simple meta fields
				static::addMetaFields($class::get('post_type'), $key, $value, $callback);
			}
		}
	}

	/**
	 * add hidden fields
	 * hidden field must be add firster than admin_menu hook
	 *
	 * @return  void
	 */
	public static function addHiddenFields($posttype)
	{
		foreach ($posttype::get('custom_fields') as $key => $value)
		{
			if ( ! isset($value['type'])) continue;
			if ($value['type'] != 'hidden') continue;

			// value and post_type
			$value = isset($value['value']) ? $value['value'] : '';
			$current_post_type = '';

			// update
			if (Input::get('post'))
			{
				$post_id = intval(Input::get('post'));
				$value = get_post_meta($post_id, $key, TRUE);
				$current_post_type = get_post_type($post_id);
			}
			// insert
			elseif (Input::get('post_type'))
			{
				$current_post_type = esc_html(Input::get('post_type'));
			}

			// in context?
			if ($current_post_type == $posttype::get('post_type'))
			{
				add_action('edit_form_after_title', function () use($key, $value)
				{
					echo \Dashi\Core\Field::field_hidden($key, $value);
				});
			}
		}
	}

	/**
	 * add meta fields
	 *
	 * @return  void
	 */
	private static function addMetaFields($post_type, $key, $value, $callback = array())
	{
		// hidden
		if (isset($value['type']) && $value['type'] == 'hidden') return;

		// required, context, priority
		$required = '';
		if (isset($value['attrs']['required']) && $value['attrs']['required'])
		{
			$required = '&nbsp;<span class="required">'.__('Required', 'dashi').'</span>';
		}
		$label    = isset($value['label'])  ? $value['label'] : $key;
		$context  = isset($value['context'])  ? $value['context'] : 'normal';
		$priority = isset($value['priority']) ? $value['priority'] : 'high';

		// TODO radio taxonomy_meta_box
		if (isset($value['type']) && $value['type'] == 'taxonomy')
		{
			if ( isset($value['radio']) && $value['radio'] === true) // TODO taxonomy 存在チェック
			{
				return static::taxonomy_meta_box($key, $post_type);
			}
			return;
		}

		// Google Map
		if (isset($value['type']) && $value['type'] == 'google_map')
		{
			$callback = array('\\Dashi\\Core\\Posttype\\CustomFieldsGoogleMap', 'draw');
		}

		// set callback
		$callback = $callback ?: array('\\Dashi\\Core\\Posttype\\CustomFields', 'addMetaFieldsCallback');

		// $keyは要素のidに使われるので、配列のようでなくす
		// $key = preg_replace('/^(.+?)\[(\d+?)\]/', '$1_$2', $key);

		// public form only
		if (isset($value['public_form_only']) && $value['public_form_only'] == true) return;

		if (isset($value['referencer']) && $value['referencer'])
		{
			if (isset($value['attr']['class']) && $value['attr']['class'])
			{
				$value['attrs']['class'] = $value['attr']['class'].' dashi_referencer';
			}
			else
			{
				$value['attrs']['class'] = 'dashi_referencer';
			}
			if (is_string($value['referencer']))
			{
				$value['attrs']['data-dashi_referencer'] = $value['referencer'];
			}

			wp_enqueue_script(
				'dashi_js_referencer',
				plugins_url('assets/js/referencer.js', DASHI_FILE)
			);
			wp_localize_script(
				'dashi_js_referencer',
				'Params',
				array(
					'home_url'     => home_url(),
					'ajax_url'     => admin_url('admin-ajax.php'),
					'action'       => 'custom_referencer',
				)
			);
		}

		// add fields
		add_meta_box(
			$key,
			$label.$required,
			$callback,
			$post_type,
			$context,
			$priority,
			$value
		);
	}

	/**
	 * add meta fields callback
	 *
	 * @param object $object
	 * @param array $value
	 * @param bool $is_label_hide
	 * @param bool $is_use_wp_uploader
	 * @return  void
	 */
	public static function addMetaFieldsCallback(
		$object,
		$value,
		$is_label_hide = true,
		$is_use_wp_uploader = true
	)
	{
		global $pagenow;
		if ( ! in_array($pagenow, array('post-new.php', 'post.php'))) return; // 編集ページのみで動作
		echo static::_addMetaFieldsCallback($object, $value, $is_label_hide, $is_use_wp_uploader);
	}

	public static function _addMetaFieldsCallback(
		$object,
		$value,
		$is_label_hide = true,
		$is_use_wp_uploader = true
	)
	{
		$err_msg = '<strong class="dashi_err_msg">Notice: set "label" attribute for label.</strong>';

		// duplicateされたフィールドの順番制御用HTMLの準備
		$duped_ctrl = self::addOrdrfield4dup($value);

		// 複数フィールドを持っている場合
		$output = self::addMultiFields($value, $object, $is_use_wp_uploader, $duped_ctrl);

		// カスタムフィールドを作成
		$key = $value['id'];
		$tmpkey = $key;
		$description = isset($value['args']['description']) ? $value['args']['description'] : '';
		$attrs = isset($value['args']['attrs']) ? $value['args']['attrs'] : array();

		// default or population value
		$val = isset($value['args']['value']) ? $value['args']['value'] : '';
		if (
			isset($value['args']['type']) &&
			(
				$value['args']['type'] == 'checkbox' ||
				($value['args']['type'] == 'select' && isset($value['args']['attrs']['multiple'])) ||
				strpos($key, '[') !== false // duplicateによる配列の場合
			)
		)
		{
			// duplicateによる配列の場合
			if (preg_match("/\[(\d*?)\]/", $tmpkey, $ms))
			{
				$tmpkey = preg_replace("/\[\d*?\]/", '', $key);
				if (
					isset($_GET['dashi_copy_original_id']) &&
					is_numeric($_GET['dashi_copy_original_id'])
				)
				{
					$tmps = get_post_meta($_GET['dashi_copy_original_id'], $tmpkey, false);
				}
				else if (is_object($object) && isset($object->ID))
				{
					$tmps = get_post_meta($object->ID, $tmpkey, false);
				}
				$tmp = isset($tmps[$ms[1]]) ? $tmps[$ms[1]] : '';
			}
			else
			{
				$tmp = is_object($object) ? get_post_meta($object->ID, $tmpkey, false) : '';
			}
			$val = ! empty($tmp) ? $tmp : $val;
		}
		else
		{
			if (isset($object->{$key}) && ! is_null($object->{$key}))
			{
				$val = $object->{$key};
			}
		}

		// add_meta_boxとおなじidにならないようにする
		$id_str = 'dashi_'.$key;
		if ( ! isset($attrs['id']) || $attrs['id'] != $key) $attrs['id'] = $id_str;

		// 配列の形のidがきたら直す
		if (strpos($attrs['id'], '[') !== false)
		{
			$attrs['id'] = str_replace(array('[', ']'), array('_', ''), $attrs['id']);
		}

		$template = isset($value['template']) ? $value['template'] : '';

		// スクリーンリーダ向けラベルの有無
		$is_label = true;

		// form items
		if (isset($value['args']['type']))
		{
			$html = '';
			switch ($value['args']['type'])
			{
				case 'text':
					$html = Field::field_text(
						$key,
						$val,
						$description,
						$attrs,
						$template
					);
					break;

				case 'password':
					$html = Field::field_password(
						$key,
						$val,
						$description,
						$attrs,
						$template
					);
					break;

				case 'textarea':
					// wysiwyg
					// wp_editor()はechoしちゃうので分岐
					if (isset($value['args']['wysiwyg']))
					{
						if ( ! preg_match('/^[a-zA-Z_]+$/', $key)) throw new \Exception (__('You can use alphabet and underscore only when use wysiwyg.', 'dashi'));

						$output.= '<span class="dashi_description">'.$description.'</span>';

						// この値はboolとarrayでくる
						$opts = is_array($value['args']['wysiwyg']) ? $value['args']['wysiwyg'] : array();

						// name属性値は固定
						$opts['textarea_name'] = $key;

						// echoしちゃうので出力バッファ
						ob_start();
						wp_editor(
							wp_specialchars_decode($val, ENT_QUOTES), // html前提なので
							$id_str, // add_meta_boxのidを避ける
							$opts
						);
						$html = ob_get_contents();
						ob_end_clean();

						// 文字数カウントなど
						if(isset($value['args']['attrs']))
						{
							if (isset($value['args']['attrs']['class']))
							{
								$html = str_replace(
									'wp-editor-area',
									'wp-editor-area '.esc_html($value['args']['attrs']['class']),
									$html
								);
								unset($value['args']['attrs']['class']);
							}
							if (isset($value['args']['attrs']['id'])) unset($value['args']['attrs']['id']);
							if (isset($value['args']['attrs']['name'])) unset($value['args']['attrs']['name']);
							if (isset($value['args']['attrs']['rows'])) unset($value['args']['attrs']['rows']);
							if (isset($value['args']['attrs']['cols'])) unset($value['args']['attrs']['cols']);
								$html = str_replace(
									'<textarea ',
									'<textarea '.\Dashi\Core\Field::array_to_attr($value['args']['attrs']).' ',
									$html
								);
						}
					}
					// non wysiwyg
					else
					{
						$html = Field::field_textarea(
							$key,
							$val,
							$description,
							$attrs,
							$template
						);
					}
					break;

				case 'select':
					$html = Field::field_select(
						$key,
						$val,
						$value['args']['options'],
						$description,
						$attrs,
						$template
					);
					break;

				case 'radio':
					$html = Field::field_radio(
						$key,
						$val,
						$value['args']['options'],
						$description,
						$attrs,
						$template
					);
					$is_label = false;
					break;

				case 'checkbox':
					$html = Field::field_checkbox(
						$key,
						$val,
						$value['args']['options'],
						$description,
						$attrs,
						$template
					);
					$is_label = false;
					break;

				case 'file':
					$attrs['id'] = 'upload_field_'.$attrs['id'];
					$html = Field::field_file(
						$key,
						$val,
						$description,
						$attrs,
						$template,
						$is_use_wp_uploader
					);
					break;
			}

			// 文字数カウント表示域
			if (isset($attrs['class']) && strpos($attrs['class'], 'dashi_chrcount') !== false)
			{
				$html.= '<div class="dashi_chrcount_area" id="dashi_chrcount_'.$attrs['id'].'">-</div>';
			}

			if (get_option('dashi_development_mode'))
			{
				// 予約語と既存ポストタイプと一致する名称をキーにしている場合、開発者向けにエラーを出す
				if (
					in_array($tmpkey, P::$banned) ||
					in_array($tmpkey, array_map(array('\\Dashi\\P', 'class2posttype'), P::instances()))
				)
				{
					$output.= '<strong class="dashi_err_msg">Notice: '.sprintf(__('"%s" is cannot use as custom field name.', 'dashi'), $key).'</strong>';
				}

				$current_class = P::posttype2class($object->post_type);
				// 既存のカスタムフィールド名（他のポストタイプを含む）をカブっていたら、エラー
				foreach (static::$custom_fields_flat as $each_class => $each_custom_fields)
				{
					if($current_class != '\\'.$each_class && in_array($tmpkey, array_keys($each_custom_fields)))
					{
						$output.= '<strong class="dashi_err_msg">Notice: '.sprintf(__('"%s" is already used other custom post type. This field cannot use "add_column" attribute at administration screen.', 'dashi'), $key).'</strong>';
					}
				}
			}

			// スクリーンリーダ向けに表題がない場合は、開発者用にエラーを出す
			if ($is_label && ! isset($value['title']) && current_user_can('administrator'))
			{
				$output.= $err_msg;
			}

			// スクリーンリーダ向けテキスト
			if ($is_label && isset($value['title']))
			{
				$label_class = 'dashi_custom_fields_label';
				$label_class.= $is_label_hide ? ' screen-reader-text' : '';
				$html = '<label for="'.$attrs['id'].'" class="'.$label_class.'">'.$value['title'].'</label>'.$html;
			}

			$output.= $html.$duped_ctrl;
		}

		return $output;
	}

	/**
	 * addMultiFields
	 *
	 * @param  array $value
	 * @param  object $object
	 * @param  bool $is_use_wp_uploader
	 * @param  string $duped_ctrl
	 * @return  string
	 */
	private static function addMultiFields($value, $object, $is_use_wp_uploader, $duped_ctrl)
	{
		$output = '';
		if (isset($value['args']['fields']) && is_array($value['args']['fields']))
		{
			if (isset($value['args']['description']))
			{
				$output.= '<span class="dashi_description dashi_fields_description">'.$value['args']['description'].'</span>';
			}

			foreach ($value['args']['fields'] as $key => $field)
			{
				// public form only
				if (isset($field['public_form_only']) && $field['public_form_only'] == true) continue;

				$required = '';
				if (isset($field['attrs']['required']) && $field['attrs']['required'])
				{
					$required = '&nbsp;<span class="required">'.__('Required', 'dashi').'</span>';
				}

				$output.= '<div class="dashi_custom_fields_in_fields">';
				if (
					isset($field['type']) &&
					in_array($field['type'], array('checkbox', 'radio'))
				)
				{
					$output.= isset($field['label']) ?
						'<h3 class="dashi_custom_fields_label">'.$field['label'].$required.'</h3>' :
						$err_msg;
				}

				$output.= static::_addMetaFieldsCallback(
					$object,
					array(
						'id' => $key,
						'title' => isset($field['label']) ? $field['label'].$required : '',
						'args' => $field
					),
					false,
					$is_use_wp_uploader
				);
				$output.= '</div>';
			}
			$output.= $duped_ctrl;
		}
		return $output;
	}

	/**
	 * addOrdrfield4dup
	 *
	 * @param  array $value
	 * @return  string
	 */
	private static function addOrdrfield4dup($value)
	{
		$duped_ctrl = '';
		if (isset($value['args']['duplicated']))
		{
			preg_match("/\[(\d+?)\]/", $value['id'], $idx);

			// fieldsのセットを取得
			if (isset($value['args']['fields']) && is_array($value['args']['fields']))
			{
				$fields = array_map(
					function ($v)
					{
						return preg_replace('/\[.*?\]/', '', $v);
					},
					array_keys($value['args']['fields'])
				);
				$idx_str = join('::', $fields);
			}
			else
			{
				$idx_str = $value['args']['original_key'];
			}
			$idx_str = intval($idx[1]).'::'.$idx_str;
			$order = intval($idx[1]) + 1;
			$odr_key = 'dashi_odrs['.$value['args']['original_key'].']['.$idx_str.']';
			$del_key = 'dashi_dels['.$value['args']['original_key'].']['.$order.']';

			$duped_ctrl.= '<div class="dashi_duped_ctrl_fields">';
			$duped_ctrl.= '<label>'.__('Order', 'dashi');
			$duped_ctrl.= ' <input type="text" name="'.$odr_key.'" size="5" value="'.$order.'">';
			$duped_ctrl.= '</label>';
			$duped_ctrl.= '<label>';
			$duped_ctrl.= ' <input type="checkbox" name="'.$del_key.'" value="'.$idx_str.'">';
			$duped_ctrl.= __('Delete', 'dashi').'</label>';
			$duped_ctrl.= '</div>';
		}
		return $duped_ctrl;
	}

	/**
	 * setFlattenedCustomFields
	 *
	 * @return  void
	 */
	public static function setFlattenedCustomFields ()
	{
		foreach (P::instances() as $class)
		{
			static::$custom_fields_flat[$class] = self::_setFlattenedCustomFields($class::get('custom_fields'));
		}
	}

	/**
	 * _setFlattenedCustomFields
	 *
	 * @return  void
	 */
	private static function _setFlattenedCustomFields ($custom_fields)
	{
		foreach ($custom_fields as $key => $field)
		{
			if (isset($field['fields']) && is_array($field['fields']))
			{
				foreach ($field['fields'] as $kk => $vv)
				{
					$custom_fields[$kk] = $vv;
					unset($custom_fields[$key]['fields'][$kk]);
				}
			}
		}
		return $custom_fields;
	}

	/**
	 * getFlattenedCustomFields
	 *
	 * @param  string $class
	 * @return  array()
	 */
	public static function getFlattenedCustomFields ($class)
	{
		if ( ! array_key_exists($class, static::$custom_fields_flat)) return array();

		// dashi_mod_custom_fields hook
		$mod_arr = apply_filters(
			'dashi_mod_custom_fields',
			array(),
			P::class2posttype($class),
			$class::get('custom_fields')
		);
		if ($mod_arr)
		{
			return self::_setFlattenedCustomFields($mod_arr);
		}

		return static::$custom_fields_flat[$class];
	}

	/**
	 * setExpectedKeys
	 *
	 * @return  void
	 */
	public static function setExpectedKeys ()
	{
		// group byがslow queryになるのでtransientを使う（5分）
		$value = get_transient('dashi_expected_custom_field_keys');

		// 開発者モードでは、無効にする
		$dashi_development_diable_field_cache = get_option('dashi_development_diable_field_cache');
		if ($value && $dashi_development_diable_field_cache != 1)
		{
			static::$expected_keys = $value;
			return;
		}

		global $wpdb;

		// 出汁由来のposttypeを取得
		foreach (P::instances() as $class)
		{
//			if ( ! $class::get('is_dashi')) continue;
			foreach($class::get('custom_fields') as $key => $value)
			{
				if (isset($value['fields']) && is_array($value['fields']))
				{
					foreach ($value['fields'] as $field_key => $field)
					{
						static::$expected_keys[$class][] = $field_key;
					}
				}
				else
				{
					static::$expected_keys[$class][] = $key;
				}
			}

			// 後から足しているぶん
			if ($class::get('is_redirect'))
			{
				static::$expected_keys[$class][] = 'dashi_redirect_to';
			}
			if ($class::get('is_use_sticky'))
			{
				static::$expected_keys[$class][] = 'dashi_sticky';
			}
			if ($class::get('is_searchable'))
			{
				static::$expected_keys[$class][] = 'dashi_search';
			}
		}

		// すべてのカスタムフィールド候補を取得
		$sql = 'SELECT meta_key FROM '.$wpdb->postmeta.' GROUP BY `meta_key`;';
		$meta_keys = $wpdb->get_results($sql);

		// ひとつpost_idを取り出し代表とする
		foreach ($meta_keys as $v)
		{
			if ($v->meta_key[0] == '_') continue;
			$sql = $wpdb->prepare(
				'SELECT post_id FROM '.$wpdb->postmeta.' WHERE `meta_key` = %s LIMIT 1;',
				$v->meta_key
			);
			$id = $wpdb->get_var($sql);
			$class = P::postid2class($id);
			if ( ! $class || $class::get('is_dashi')) continue;
			static::$expected_keys[$class][] = $v->meta_key;
		}

		set_transient('dashi_expected_custom_field_keys', static::$expected_keys, 300);
	}

	/**
	 * getExpectedKeys
	 *
	 * @param  string $class
	 * @return  array
	 */
	public static function getExpectedKeys ($class)
	{
		if ( ! array_key_exists($class, static::$expected_keys)) return array();

		// dashi_mod_custom_fields hook
		$mod_arr = apply_filters(
			'dashi_mod_custom_fields',
			array(),
			P::class2posttype($class),
			$class::get('custom_fields')
		);
		if ($mod_arr)
		{
			return array_keys($mod_arr);
		}
		return static::$expected_keys[$class];
	}

	/**
	 * taxonomy_meta_box
	 *
	 * @param  string $taxonomy
	 * @param  string $post_type
	 * @return void
	 */
	public static function taxonomy_meta_box($taxonomy, $post_type)
	{
		static::$taxonomy_to_radios[$post_type][] = $taxonomy;

		global $wp_filter;
		if (! isset($wp_filter['admin_print_footer_scripts']['taxonomy_to_radio']))
		{
			add_action(
				'admin_print_footer_scripts',
				array('\\Dashi\\Core\\Posttype\\CustomFields', 'taxonomy_to_radio')
			);
		}
	}

	/**
	 * taxonomy_to_radio
	 *
	 * @return Void
	 */
	public static function taxonomy_to_radio()
	{
		static::$taxonomy_to_radios;
		global $post_type;
		if (! isset(static::$taxonomy_to_radios[$post_type])) return;

		?>
		<script type="text/javascript">
		jQuery( function( $ )
		{
			var taxonomies = [];
	<?php foreach (static::$taxonomy_to_radios[$post_type] as $radio): ?>
			taxonomies.push("<?php echo $radio ?>");
	<?php endforeach; ?>
			for (var key in taxonomies)
			{
				// 投稿画面
				$( '#taxonomy-' + taxonomies[key] + ' input[type=checkbox]' ).each( function() {
					$( this ).replaceWith( $( this ).clone().attr( 'type', 'radio' ) );
				} );

				// 一覧画面
				var taxonomy_checklist = $( '.' + taxonomies[key] + '-checklist input[type=checkbox]' );
				taxonomy_checklist.click( function()
				{
					$( this ).parents( '.cat-checklist ' ).find( ' input[type=checkbox]' ).attr( 'checked', false );
					$( this ).attr( 'checked', true );
				});

				// 新規カテゴリーを追加を消す
				$('#' + taxonomies[key] + '-adder').hide();
				$('#' + taxonomies[key] + '-tabs .hide-if-no-js').hide();
			}

		});
		</script>
	<?php

	}

	/**
	 * referencer_ajax_handler
	 *
	 * @return Void
	 */
	public static function referencer_ajax_handler()
	{
		// post_type
		// page
		// search
		$args = array('posts_per_page' => '20',);

		if ($post_type = \Dashi\Core\Input::post('post_type', false))
		{
			$args['post_type'] = $post_type;
		}

		if ($search = \Dashi\Core\Input::post('search', false))
		{
			$args['s'] = $search;
			/*
			$args['meta_query'] = array(
				// 'relation' => 'OR',
				array('compare' => 'like', 'key' => 'dashi_search', 'value' => $search),
			);
			 */
		}

		$results = get_posts($args);

		wp_send_json_success($results);
	}

}
