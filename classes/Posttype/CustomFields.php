<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

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
						'label'    => __('Sticky', 'dashi'),
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
					'nonce'        => wp_create_nonce('dashi_custom_referencer'),
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
		// duplicateされたフィールドの順番制御用HTMLの準備
		$duped_ctrl = self::addOrdrfield4dup($value);

		// 複数フィールドを持っている場合
		$output = self::addMultiFields($value, $object, $is_use_wp_uploader, $duped_ctrl);

		// カスタムフィールドを作成
		$key = $value['id'];
		$description = isset($value['args']['description']) ? $value['args']['description'] : '';
		$attrs = isset($value['args']['attrs']) ? $value['args']['attrs'] : array();
		$filterable = ! empty($value['args']['filter']);
        $resolved = CustomFieldValueResolver::resolve($object, $value);
        $val = $resolved['value'];
        $tmpkey = $resolved['meta_key'];

        $normalized_attrs = CustomFieldAttributeNormalizer::normalize($key, $attrs);
        $attrs = $normalized_attrs['attrs'];
        $id_str = $normalized_attrs['id_str'];

		$template = isset($value['args']['template']) ? $value['args']['template'] : '';

		// スクリーンリーダ向けラベルの有無
		$is_label = true;

		// form items
		if (isset($value['args']['type']))
		{
			// ★ options の正規化
			$options = $value['args']['options'] ?? null;
			$options = Util::resolveOptions($options);

			$html = '';
			switch ($value['args']['type'])
			{
				case 'textarea':
                    $rendered = CustomFieldTextareaRenderer::render(
                        $key,
                        $val,
                        $description,
                        $attrs,
                        $template,
                        $value,
                        $id_str,
                        $output
                    );
                    $output = $rendered['output'];
                    $html = $rendered['html'];
					break;

				case 'file':
				case 'file_media':
                    $rendered = CustomFieldFileRenderer::render(
                        $value['args']['type'],
                        $key,
                        $val,
                        $description,
                        $attrs,
                        $template,
                        $value,
                        $is_use_wp_uploader
                    );
                    $attrs = $rendered['attrs'];
                    $html = $rendered['html'];
					break;

				default:
                    $rendered = CustomFieldRenderer::renderBasic(
                        $value['args']['type'],
                        $key,
                        $val,
                        $options,
                        $description,
                        $attrs,
                        $template,
                        $filterable
                    );
                    if ($rendered['handled'])
                    {
                        $html = $rendered['html'];
                        $is_label = $rendered['is_label'];
                    }
                    break;
			}

            // 文字数カウント対象の field には、表示用のカウンタ領域を後ろに足す
            $html = CustomFieldMarkupDecorator::appendCharCountArea($html, $attrs);

            // 開発者モードでだけ、キー名の衝突や label 不足の Notice を組み立てる
            $output .= CustomFieldNoticeBuilder::build(
                $key,
                $tmpkey,
                $object,
                static::$custom_fields_flat
            );

			// スクリーンリーダ向けに表題がない場合は、開発者用にエラーを出す
            $output .= CustomFieldNoticeBuilder::buildMissingLabelNotice($is_label, $value);

            // スクリーンリーダ向けの label が必要な場合だけ、描画済み HTML を label で包む
            $html = CustomFieldMarkupDecorator::wrapWithLabel(
                $html,
                $attrs,
                $value,
                $is_label,
                $is_label_hide
            );

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
			static::$custom_fields_flat[$class] = CustomFieldFlattener::flatten($class::get('custom_fields'));
		}
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
			return CustomFieldFlattener::flatten($mod_arr);
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
            static::$expected_keys[$class] = CustomFieldExpectedKeyBuilder::build(
                $class::get('custom_fields'),
                $class::get('is_redirect'),
                $class::get('is_use_sticky'),
                $class::get('is_searchable')
            );
		}

		// すべてのカスタムフィールド候補を取得
		$sql = 'SELECT meta_key FROM '.$wpdb->postmeta.' GROUP BY `meta_key`;';
		$meta_keys = $wpdb->get_results($sql);
        $post_ids_by_meta_key = array();
        $classes_by_post_id = array();
        $is_dashi_by_class = array();

		// ひとつpost_idを取り出し代表とする
		foreach ($meta_keys as $v)
		{
			if ($v->meta_key[0] == '_') continue;
			$sql = $wpdb->prepare(
				'SELECT post_id FROM '.$wpdb->postmeta.' WHERE `meta_key` = %s LIMIT 1;',
				$v->meta_key
			);
            $post_ids_by_meta_key[$v->meta_key] = $wpdb->get_var($sql);
        }

        foreach ($post_ids_by_meta_key as $meta_key => $post_id)
        {
            $class = P::postid2class($post_id);
            $classes_by_post_id[$post_id] = $class;

            if ($class && !array_key_exists($class, $is_dashi_by_class))
            {
                $is_dashi_by_class[$class] = (bool) $class::get('is_dashi');
            }
        }

        $external_expected_keys = CustomFieldExternalKeyCollector::collect(
            $meta_keys,
            $post_ids_by_meta_key,
            $classes_by_post_id,
            $is_dashi_by_class
        );

        foreach ($external_expected_keys as $class => $keys)
        {
            if (!isset(static::$expected_keys[$class]))
            {
                static::$expected_keys[$class] = array();
            }

            static::$expected_keys[$class] = array_merge(static::$expected_keys[$class], $keys);
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
		$nonce_ok = check_ajax_referer('dashi_custom_referencer', '_wpnonce', false);
		if ( ! $nonce_ok)
		{
			wp_send_json_error(array('message' => 'invalid nonce'), 403);
		}

		if ( ! current_user_can('edit_posts'))
		{
			wp_send_json_error(array('message' => 'forbidden'), 403);
		}

		$args = array('posts_per_page' => 20);

		$allowed_post_types = array();
		foreach (\Dashi\Core\Posttype\Posttype::instances() as $class)
		{
			$allowed_post_types[] = \Dashi\Core\Posttype\Posttype::class2posttype($class);
		}

		$post_type = \Dashi\Core\Input::post('post_type', false);
		if ($post_type)
		{
			$post_type = sanitize_key($post_type);
			if ( ! in_array($post_type, $allowed_post_types, true))
			{
				wp_send_json_error(array('message' => 'invalid post_type'), 400);
			}
			$args['post_type'] = $post_type;
		}

		$search = \Dashi\Core\Input::post('search', false);
		if ($search !== false && $search !== null && $search !== '')
		{
			$args['s'] = sanitize_text_field($search);
		}

		$results = get_posts($args);
		wp_send_json_success($results);
	}

}
