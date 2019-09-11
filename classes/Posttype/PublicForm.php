<?php
namespace Dashi\Core\Posttype;

class PublicForm
{
	/**
	 * forge
	 *
	 * @return Void
	 */
	public static function forge ()
	{
		foreach (P::instances() as $class)
		{
			$posttypes[] = P::class2posttype($class);
		}

		// 承認時のフック
		add_filter(
			'pending_to_publish',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'pendingToPublish'),
			20,
			2
		);

		// static::garbageCollection();
		/*
		// TODO 別・カプセル化
		// 1/10の確率でメンテナンス
		$r = rand(1, 10);
		if ($r == 1)
		{
			// ガベコレ
			foreach (glob(DASHI_TMP_UPLOAD_DIR."*") as $filename)
			{
				if (filectime($filename) <= time() - 86400 * 7)
				{
					unlink($filename);
				}
			}

			// 安全性の確認
			if ( ! file_exists(DASHI_TMP_UPLOAD_DIR.'.htaccess'))
			{
				file_put_contents(DASHI_TMP_UPLOAD_DIR.'.htaccess', 'deny from all');
			}
		}
		 */

		add_action(
			'wp_ajax_public_uploader_ajax',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'uploader_ajax_handler')
		);
		add_action(
			'wp_ajax_nopriv_public_uploader_ajax',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'uploader_ajax_handler')
		);

		static::createUploadDir();
	}

	/**
	 * TODO get_posts が管理画面で走らせると死ぬ、
	 * TODO pending がなくなった時点で全削除でもいいのでは?
	 */
	protected static function garbageCollection()
	{
		$posttypes = array();
		foreach (P::instances() as $class)
		{
			$posttypes[] = P::class2posttype($class);
		}

		foreach ($posttypes as $post_type)
		{

			$class = P::posttype2class($post_type);
			$fields = $class::getFlatCustomFields();

			$pendings = get_posts(array(
				'post_type'   => $posttypes,
				'post_status' => 'pending',
				'posts_per_page' => -1,
			));

			$files = array();

			foreach ($pendings as $pending)
			{
				foreach ($fields as $field => $attr)
				{
					if ($attr['type'] == 'file')
					{
						$name = str_replace(static::uploadUrl(), '', $pending->{$field});

						if ($name && file_exists(DASHI_TMP_UPLOAD_DIR.$name))
						{
							$files[] = DASHI_TMP_UPLOAD_DIR.$name;
						}
					}
				}
			}

			// 安全性の確認
			if ( ! file_exists(DASHI_TMP_UPLOAD_DIR.'.htaccess'))
			{
				file_put_contents(DASHI_TMP_UPLOAD_DIR.'.htaccess', 'deny from all');
			}
		}

		$files = array_unique($files);
		foreach (glob(DASHI_TMP_UPLOAD_DIR."*") as $filename)
		{
			if (in_array($filename, $files)) continue;

			// 投稿したてのものを消さないように時間もみる
			if (filectime($filename) <= time() - 100)//86400)
			{
				unlink($filename);
			}
		}
	}

	/**
	 * shortcode
	 *
	 * @return String
	 */
	public static function shortcode ($attrs, $content = null)
	{
		// search posttype
		$form = isset($attrs['form']) ? $attrs['form'] : '';
		if ( ! $form) return 'error: specify valid form';
		if (is_admin()) return;
		$class = \Dashi\Core\Posttype\Posttype::posttype2class($form);

		// post_id
		global $post;

		// req_method
		$req_method = filter_input(INPUT_SERVER, "REQUEST_METHOD");
		$req_method = $req_method ? $req_method : filter_input(INPUT_ENV, "REQUEST_METHOD");

		// nonce check
		if ($req_method == 'POST')
		{
			$valid_post = false;
			$wpnonce = filter_input(INPUT_POST, "_wpnonce");
			if ($wpnonce)
			{
				if (filter_input(INPUT_POST, "dashi_public_form_do_final"))
				{
					$valid_post = wp_verify_nonce($wpnonce, 'dashi_public_form_do_final');
				}
				if (filter_input(INPUT_POST, "dashi_public_form_send"))
				{
					$valid_post = wp_verify_nonce($wpnonce, 'dashi_public_form');
				}
			}

			if ( ! $valid_post)
			{
				// タイミング的にリダイレクトできないのでexit()のみ
				// wp_safe_redirect(home_url());
				exit();
			}
		}

		// set value
		$vals = self::setValue($class, $form, $req_method);

		// posted value
		$errors = array();
		if ($req_method == 'POST')
		{
			// apply filters
			$vals = self::applyFilters($class, $vals);

			// validate
			$errors = self::validate($class, $vals);
		}

		// routing
		if ( ! $errors && $req_method == 'POST')
		{
			// 最終処理＋確認画面
			if (filter_input(INPUT_POST, "dashi_public_form_do_final"))
			{
				return self::finalize($class, $vals);
			}

			// 内容確認画面
			return self::confirm($class, $vals);
		}

		// show form
		return self::form($class, $form, $vals, $errors);
	}

	/**
	 * upload
	 *
	 * @return object
	 */
	private static function upload ($class, $form, $val, $key)
	{
		// 最終段階ではセッションを返す
		if (filter_input(INPUT_POST, "dashi_public_form_do_final")) return $val;

		// 途中段階では$_FILESの内容を確認する
		if ( ! isset($_FILES[$key]) || empty($_FILES)) return array();

		// ファイルをDASHI_TMP_UPLOAD_DIRにアップする
		$val = array();
		foreach ($_FILES as $k => $v)
		{
			if ($k != $key) continue;
			if (empty($v['name'])) continue;

			$val = static::handleUpload($class, $v);
		}

		return $val;
	}

	/**
	 * handleUpload
	 * POST のアップロード、とAjax のアップロード両方を処理
	 *
	 * @param String $class
	 * @param String $file
	 */
	protected static function handleUpload($class, $file)
	{
		$retVal = array();
		$retVal['dashi_uploaded_file'] = true;

		// 拡張子
		$ext = substr($file['name'], strrpos($file['name'], '.'), strlen($file['name']));
		$ext_dotless = substr($ext, 1);

		// アップロードを許可されたファイルかどうか
		$is_uploadable = false;
		foreach ($class::get('public_form_allowed_mimes') as $allowed_ext => $allowed_mime)
		{
			if (
				! $is_uploadable &&
				preg_match("/{$allowed_ext}/i", $ext_dotless) &&
				$allowed_mime == $file['type']
			)
			{
				$is_uploadable = true;
			}
		}

		// アップロードを許可されていないファイル
		$mime_keys = array_keys($class::get('public_form_allowed_mimes'));
		$mime_keys = array_map(function ($v)
		{
			return str_replace('jpg|jpeg|jpe', 'JPEG', $v);
		},
			$mime_keys);
		$retVal['errors'] = array();
		if ( ! $is_uploadable)
		{
			$retVal['errors'][] = sprintf(
				__('This file type is not allowed. (allowed: %s)', 'dashi'),
				join(', ', $mime_keys)
			);
		}

		// 何かエラーがある
		switch ($file['error'])
		{
			// エラーなし
			case 0:
				break;

			// ファイルサイズが大きい
			case 1:
				$retVal['errors'][] = sprintf(
					__('This file is too large. (allowed: %s)', 'dashi'),
					ini_get('upload_max_filesize')
				);
				$is_uploadable = false;
				break;

			// なんらかの理由による失敗（もしかしたら将来書く）
			default:
				$is_uploadable = false;
				break;
		}

		// not upload
		if ( ! $is_uploadable)
		{
			$retVal['errors'][] = sprintf(
				__('%s cannot be uploaded', 'dashi'),
				esc_html($file['name'])
			);
			return $retVal;
		}

		// もとのファイル名を格納
		$retVal['original_name'] = $file['name'];

		// ファイル名を予測が難しいものにする
		$name = wp_unique_filename( DASHI_TMP_UPLOAD_DIR, $name );

		$retVal['name'] = $name;
		$upload_path = DASHI_TMP_UPLOAD_DIR.$name;

		// EXIFを送信する場合ここで確保
		$retVal['exif'] = array();
		if ($class::get('is_send_exif'))
		{
			if (function_exists('exif_read_data'))
			{
				$retVal['exif'] = $class::get('is_send_exif') ?
												@exif_read_data($file["tmp_name"], 0, true) : '';
			}
			else
			{
				if (is_user_logged_in()) die('function exif_read_data is not exist.');
			}

			// ajax でも処理するので json 形式に
			if (is_array($retVal['exif']))
			{
				$retVal['exif'] = json_encode($retVal['exif']);
			}
		}

		// EXIFを削除
		if ($class::get('public_form_remove_exif'))
		{
			if (class_exists('Imagick'))
			{
				$imagick = new \Imagick($file["tmp_name"]);
				$imagick->stripimage();
				$imagick->writeimage($file["tmp_name"]);
			}
			else
			{
				if (is_user_logged_in()) die('class Imagick is not exist.');
			}
		}

		// uploadする
		if (@move_uploaded_file($file['tmp_name'], $upload_path))
		{
			chmod($upload_path, 0644);
		}
		return $retVal;
	}

	/**
	 * uploadDir
	 *
	 * @return string
	 */
	public static function uploadDir()
	{
		$upload_dirs = wp_upload_dir();
		$upload_path = dirname($upload_dirs['path']).'/files/';
		if ( ! file_exists($upload_path))
		{
			mkdir($upload_path);
		}
		return $upload_path;
	}

	public static function uploadUrl()
	{
		return plugins_url().'/dashi/file.php?path=';
	}

	/**
	 * setValue
	 *
	 * @return array
	 */
	private static function setValue ($class, $form, $req_method)
	{
		$vals = (object) array();

		// get session first
		$sess = Session::show('dashi_public_form', $form);
		$vals = $sess ? (object) $sess : $vals;

		// set post when it exists
		$vals->post_type = $form;

		foreach ($class::getFlatCustomFields() as $k => $v)
		{
			// ここで unset することで validate と apply filter に適用されないようにする
			if (isset($v['private_form_only']) && $v['private_form_only'] == true)
			{
				if (isset($vals->$k))
				{
					unset($vals->$k);
				}
				continue;
			}

			$filter_input = filter_input(INPUT_POST, $k);

			if ($req_method == 'POST' && ! filter_input(INPUT_POST, "dashi_public_form_do_final"))
			{
				$vals->$k = $filter_input;
			}

			// 配列の場合
			if (
				! $filter_input && $req_method == 'POST' &&
				! filter_input(INPUT_POST, "dashi_public_form_do_final")
			)
			{
				$vals->$k = filter_input(INPUT_POST, $k, FILTER_DEFAULT ,FILTER_REQUIRE_ARRAY);
			}

			// ファイルの場合
			if (isset($v['type']) && $v['type'] == 'file' && isset($_FILES[$k]))
			{
				$vals->$k = self::upload($class, $form, $vals->$k, $k);
			}
		}

		// set back to session
		Session::set('dashi_public_form', $form, $vals);

		return $vals;
	}

	/**
	 * applyFilters
	 *
	 * @param  String $class
	 * @param  Object $vals
	 * @return Array
	 */
	private static function applyFilters ($class, $vals)
	{
		$fields = $class::getFlatCustomFields();
		foreach ($vals as $k => $v)
		{
			if ($k == 'post_type') continue;
			if ( ! isset($fields[$k]) || ! isset($fields[$k]['filters'])) continue;

			// filters
			foreach ($fields[$k]['filters'] as $filter)
			{
				$filter = strtolower($filter);
				$vals->$k = \Dashi\Core\Filter::$filter($v);
			}
		}

		return $vals;
	}

	/**
	 * validate
	 *
	 * @param  String $class
	 * @param  Object $vals
	 * @return Array
	 */
	private static function validate ($class, $vals)
	{
		$fields = $class::getFlatCustomFields();
		$errors = array();

		// required
		foreach ($vals as $k => $v)
		{
			if ($k == 'post_type') continue;
			if ( ! isset($fields[$k]) || ! isset($fields[$k]['attrs']['required'])) continue;
			if ( ! $fields[$k]['attrs']['required']) continue;

			// checkbox, multiple select
			$tmp = is_array($v) ? $v : trim($v);
			if ((is_array($tmp) && empty($tmp)) || ( ! is_array($tmp) && strlen($tmp) == 0))
			{
				$errors[$k][] = sprintf(__("%s is required", 'dashi'), $fields[$k]['label']);
			}
		}

		// validate
		foreach ($vals as $k => $v)
		{
			if ($k == 'post_type') continue;
			if ( ! isset($fields[$k]) || ! isset($fields[$k]['validations'])) continue;

			foreach ($fields[$k]['validations'] as $validator)
			{
				$method = 'validate'.ucfirst($validator);
				$err = Validation::$method($v);

				if ($err !== true)
				{
					$errors[$k][] = sprintf(__($err, 'dashi'), $fields[$k]['label']);
				}
			}
		}

		// file errors
		foreach ($vals as $k => $v)
		{
			if ($k == 'post_type') continue;
			if ( ! isset($v['dashi_uploaded_file'])) continue;
			if ( empty($v['errors'])) continue;
			if ($errors[$k])
			{
				$errors[$k] = array_merge($errors[$k], $v['errors']);
			}
			else
			{
				$errors[$k] = $v['errors'];
			}
		}

		return $errors;
	}

	/**
	 * form
	 *
	 * @param   String $class
	 * @param   String $form
	 * @param   Object $vals
	 * @param   Array  $errors
	 * @return  String
	 */
	private static function form ($class, $form, $vals, $errors)
	{
		// set fields
		$fields = $class::get('custom_fields');
		$post_type = P::class2posttype($class);
		if (empty($fields)) return 'empty fields';
		$html = '';

		// errors
		if ($errors)
		{
			$html.= '<section id="dashi_public_form_error">';
			$html.= '<h1>'.__('Some errors exists. Check please.', 'dashi').'</h1>';
			$html.= '<ul>';
			foreach ($errors as $k => $each_errors)
			{
				foreach ($each_errors as $error)
				{
					$html.= '<li><a href="#dashi_'.$k.'">'.$error.'</a></li>';
				}
			}
			$html.= '</ul>';
			$html.= '</section>';
		}

		$require_str = '&nbsp;<span class="required">'.__('Required', 'dashi').'</span>';

		// fetch forms from ob
		$is_file_exist = false;
		// ob_start();
		foreach ($fields as $field => $attrs)
		{
			if (isset($attrs['private_form_only']) && $attrs['private_form_only'] == true) continue;

			$attrs['id'] = $field;
			$attrs['title'] = $attrs['label'];
			$attrs['args'] = $attrs;

			// require string
			$required = '';
			if (isset($attrs['attrs']['required']) && $attrs['attrs']['required'])
			{
				$required = $require_str;
			}

			// label
			$label = $attrs['title'].$required;
			if (isset($attrs['type']) && ! in_array($attrs['type'], array('checkbox', 'radio')))
			{
				$label = '<label for="dashi_'.$field.'">'.$label.'</label>';
			}

			// 複数フィールドの場合の taxonomy 設定
			if (isset($attrs['args']['fields']) && is_array($attrs['args']['fields']))
			{
				foreach ($attrs['args']['fields'] as $a_key => $a_attr)
				{
					// taxonomy だったら option 追加する
					if ($attrs['args']['fields'][$a_key]['type'] != 'taxonomy') continue;
					$options = get_terms($a_key, array(
						'hide_empty' => false,
						'fields' => 'id=>name',
						'orderby' => 'slug',
						'order' => 'ASC',
					));

					$attrs['args']['fields'][$a_key]['options'] = $options; // 階層が違う TODO
					if (isset($vals->{$a_key})) $attrs['args']['fields'][$a_key]['value'] = $vals->{$a_key}; // 階層が違う TODO
					if (isset($attrs['args']['fields'][$a_key]['radio']) && $attrs['args']['fields'][$a_key]['radio']) {
						$attrs['args']['fields'][$a_key]['type'] = 'radio'; // 階層が違う TODO
					} else {
						$attrs['args']['fields'][$a_key]['type'] = 'checkbox'; // 階層が違う TODO
					}
				}
			}

			if (isset($attrs['type']) && $attrs['type'] == 'taxonomy')
			{
				// taxonomy だったら option 追加する
				$options = get_terms($field, array(
					'hide_empty' => false,
					'fields' => 'id=>name',
					'orderby' => 'slug',
					'order' => 'ASC',
				));
				$attrs['args']['options'] = $options;
				if (isset($vals->{$field})) $attrs['args']['value'] = $vals->{$field};
				if (isset($attrs['radio']) && $attrs['radio']) {
					$attrs['args']['type'] = 'radio';
				} else {
					$attrs['args']['type'] = 'checkbox';
				}
			}

			// Google Mapの場合とそれ以外を描画する
			// ここでファイルアップロードも確認する
			if (isset($attrs['type']) && $attrs['type'] == 'google_map')
			{
				ob_start();
				\Dashi\Core\Posttype\CustomFieldsGoogleMap::draw($vals, $attrs, true);
				$field = ob_get_contents();
				ob_end_clean();
			}
			else
			{
				$field = \Dashi\Core\Posttype\CustomFields::_addMetaFieldsCallback($vals, $attrs, true, false);
			}

			$template = isset($attrs['fieldset_template']) ?
								$attrs['fieldset_template'] :
				"<fieldset class=\"dashi_public_form_fieldset\">
				<legend>{label}</legend>
				{field}
				</fieldset>\n";

			$html.=str_replace(
				array('{field}', '{label}'),
				array($field, $label),
				$template);
		}
		//$html.= ob_get_contents();
		//ob_end_clean();

		// アップロードが存在する？
		foreach ($class::getFlatCustomFields() as $v)
		{
			if (isset($v['type']) && $v['type'] == 'file')
			{
				$is_file_exist = true;
				break;
			}
		}

		// form
		global $post;
		$enctype = $is_file_exist ? ' enctype="multipart/form-data"' : '';
		$beg = '<form class="dashi_form" action="'.get_permalink($post->ID).'" method="POST"'.$enctype.'>';

		$end = '<input class="dashi_button-primary" type="submit" value="'.__('Send', 'dashi').'">';
		$end = apply_filters('dashi_public_form_submit_button', $end, $post_type);
		$end.= '<input type="hidden" name="dashi_public_form_send" value="1" />';
		$end.= wp_nonce_field(
			'dashi_public_form',
			'_wpnonce',
			true,
			false
		);
		$end.= '</form>';

		// enque js etc
		if ($is_file_exist)
		{

			wp_enqueue_script('dashi_js_pubic_uploader',
				plugins_url('assets/js/public_uploader.js', DASHI_FILE));
			wp_localize_script('dashi_js_pubic_uploader', 'DashiUpload', array(
				'home_url'     => home_url(),
				'ajax_url'     => admin_url('admin-ajax.php'),
				'upload_url'   => static::uploadUrl(),
				'action'       => 'public_uploader_ajax',
				'form'         => $form,
				'session'      => Session::show('dashi_public_form'),
			));
		}

		return $beg.$html.$end;
	}

	public static function uploader_ajax_handler()
	{
		$file = $_FILES['file_data'];

		$class = \Dashi\P::posttype2class($_POST['form']);

		if (!$file || !$class)
		{
			wp_die();
		}

		$result = static::handleUpload($class, $file);
		if ($result)
		{
			$wp_filetype = wp_check_filetype_and_ext(DASHI_TMP_UPLOAD_DIR.$result['name'],DASHI_TMP_UPLOAD_DIR.$result['name']);
			$result['ext']  = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
			$result['type'] = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
			$result['path'] = static::uploadUrl().$result['name'];
			wp_send_json_success($result);
		}
	}

	/**
	 * confirm
	 *
	 * @param string $class
	 * @param object $vals
	 * @return  string
	 */
	private static function confirm ($class, $vals)
	{
		global $post;

		$html = '';
		$html.= '<dl class="dashi_public_form_confirm">';
		foreach ($class::getFlatCustomFields() as $k => $v)
		{
			if (isset($v['private_form_only']) && $v['private_form_only'] == true) continue;

			$html.= '<dt>'.$v['label'].'</dt>';
			$html.= '<dd>';
			if (isset($vals->$k))
			{
				if (is_array($vals->$k))
				{
					// google map
					if (isset($vals->{$k}['lat']) && isset($vals->{$k}['lng']))
					{
						$html.= esc_html($vals->{$k}['place']).'<br />';
						$html.= '<iframe title="Google Map" style="width:100%;min-height:200px;" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="https://maps.google.co.jp/maps/place?q='.esc_html($vals->{$k}['lat']).','.esc_html($vals->{$k}['lng']).'&output=embed&t=m&z='.intval($vals->{$k}['zoom']).'"></iframe>';
					}
					// 画像の場合
					elseif (isset($vals->{$k}['dashi_uploaded_file']))
					{
						$html.= '<img width="200" src="'.static::uploadUrl().urlencode(esc_html($vals->{$k}['name'])).'" alt="uploaded file" />';
					}
					// その他の配列
					else
					{
						// checkbox
						if (
							isset($v['type']) &&
							($v['type'] == 'checkbox' || $v['type'] == 'select')
						)
						{
							$val_strs = array();
							foreach ($vals->$k as $vals_value)
							{
								if (isset($v['options'][$vals_value])) $val_strs[] = $v['options'][$vals_value];
							}
							$html.= join(', ', $val_strs);
						}
						else if ($v['type'] == 'taxonomy')
						{
							// taxonomy
							$options = get_terms($k, array(
								'hide_empty' => false,
								'fields' => 'id=>name',
								'orderby' => 'slug',
								'order' => 'ASC',
								'include' => $vals->$k,
							));
							$html.= join(', ', $options);
						}
						else
						{
							$html.= join(', ', $vals->$k);
						}
					}
				}
				else
				{
					// radio or select
					if (
						isset($v['type']) &&
						($v['type'] == 'radio' || $v['type'] == 'select') &&
						isset($v['options'][$vals->$k])
					)
					{
						$html.= $v['options'][$vals->$k];
					}
					else if ($v['type'] == 'taxonomy') // taxonomy radio
					{
						$options = get_terms($k, array(
							'hide_empty' => false,
							'fields' => 'id=>name',
							'orderby' => 'slug',
							'order' => 'ASC',
							'include' => array($vals->$k),
						));
						$html.= join(', ', $options);
					}
					else
					{
						$html.= esc_html($vals->$k);
					}
				}
			}
			$html.= '</dd>';
		}
		$html.= '</dl>';
		$html.= '<form id="dashi_public_form_ctrls" method="POST" action="'.get_permalink($post->ID).'">';
		$html.= '<input type="hidden" name="dashi_public_form_do_final" value="1" />';

		$buttons = '<a href="'.get_permalink($post->ID).'" class="dashi_button">'.__('Back', 'dashi').'</a>';
		$buttons.= '<input class="dashi_button-primary" type="submit" value="'.__('Send', 'dashi').'">';

		$buttons = apply_filters('dashi_public_form_confirm_button', $buttons, $post->post_type);

		$html.= $buttons;

		$html.= wp_nonce_field(
			'dashi_public_form_do_final',
			'_wpnonce',
			true,
			false
		);;
		$html.= '</form>';

		return $html;
	}

	/**
	 * finalize
	 *
	 * @param string $class
	 * @param object $vals
	 * @return  string
	 */
	private static function finalize ($class, $vals)
	{
		global $post;
		$post_type = \Dashi\P::class2posttype($class);
		$post_id = false;
		$success = false;

		// 投稿内容を登録する
		$allow_post_by_public_form = $class::get('allow_post_by_public_form');
		if ($allow_post_by_public_form)
		{
			$post_id = self::insertPost($class, $vals);
		}

		// メール送信がある？
		$sendto = $class::get('sendto');
		if ($sendto)
		{
			$success = static::sendmail($class, $vals, $post_id);
		}

		// sessionをクリアする
		if (
			($sendto && $success) ||
			($allow_post_by_public_form && $post_id)
		)
		{
//			Session::remove('dashi_public_form', $form);
		}

		// 終了画面
		$message = $class::get('public_form_final_message');
		$html = '';
		$html.= apply_filters('dashi_public_form_final_message', $message, $post_type);
		$html.= '<div id="dashi_public_form_final">';
		$html.= '<a href="'.get_permalink($post->ID).'" class="dashi_button">'.__('Back', 'dashi').'</a>';
		$html.= '</div>';

		return $html;
	}

	/**
	 * insertPost
	 *
	 * @param string $class
	 * @param object $vals
	 * @return  string
	 */
	private static function insertPost ($class, $vals)
	{
		$new = (object) array();

		$fields = $class::getFlatCustomFields();

		$post_title_field = $class::get('public_form_post_title_field');
		$new->post_title = isset($vals->$post_title_field) ?
								$vals->$post_title_field :
								'';

		$post_content_field = $class::get('public_form_post_content_field');
		$new->post_content = isset($vals->$post_content_field) ?
								$vals->$post_content_field :
								'';

		$new->post_status = 'pending';
		$new->post_type = $vals->post_type;

		$post_id = wp_insert_post($new);

		if ($post_id)
		{
			// taxonomy のフィールドがあれば
			foreach ($vals as $k => $v)
			{
				if (isset($fields[$k]['type']) && $fields[$k]['type'] == 'taxonomy')
				{
					wp_set_post_terms($post_id, $v, $k);
					// unset()しなくても_updateCustomFields()ではさほど問題が起こらないので、unset()しない
					// unset($vals->{$k});
				}
			}

			// custom_fields
			Save::_updateCustomFields($post_id, (array) $vals);
			add_post_meta($post_id, '_dashi_pubic_form_pending', true);

			// file path
			foreach ($vals as $k => $v)
			{
				if ( ! isset($v['dashi_uploaded_file'])) continue;
				update_post_meta($post_id, $k, static::uploadUrl().$v['name']);
			}
		}

		return $post_id;
	}

	/**
	 * precheckSendmail
	 *
	 * @return  string
	 */
	public static function precheckSendmail($class)
	{
		// mail address check. sendto is allowed comma separated value
		if ( ! $class::get('sendto') || ! Validation::multi('isMailaddress', $class::get('sendto')))
		{
			throw new \Exception (__('Mail form require valid "send to mailaddress".', 'dashi'));
		}

		// mail address check. sendto is allowed comma separated value
		if ( ! $class::get('sendto') || ! Validation::multi('isMailaddress', $class::get('sendto')))
		{
			throw new \Exception (__('Mail form require valid "send to mailaddress".', 'dashi'));
		}

		// reply to
		if ($class::get('replyto') && ! Validation::isMailaddress($class::get('sendto')))
		{
			throw new \Exception (__('Mail form require valid "reply to mailaddress".', 'dashi'));
		}

		// subjectless
		if ( ! $class::get('subject'))
		{
			throw new \Exception (__('set "subject".', 'dashi'));
		}

		// auto reply?
		if ($class::get('is_auto_reply'))
		{
			if ( ! $class::get('auto_reply_field'))
			{
				throw new \Exception (__('set "auto_reply_field" when use auto_reply.', 'dashi'));
			}
			if ( ! $class::get('re_subject'))
			{
				throw new \Exception (__('set "re_subject" when use auto_reply.', 'dashi'));
			}
		}
	}

	/**
	 * sendmail
	 *
	 * @param string $class
	 * @param object $vals
	 * @param int $post_id
	 * @return  string
	 */
	public static function sendmail($class, $vals, $post_id)
	{
		$post_type = P::class2posttype($class);
		static::precheckSendmail($class);

		// client mail address
		$client_mail_field = $class::get('auto_reply_field');
		$client_mail = isset($vals->$client_mail_field) ? $vals->$client_mail_field : '';

		// body
		$admin_mail = self::body($class, $vals, true);
		if ($post_id)
		{
			$admin_mail = "\n\n================\n\n".get_edit_post_link($post_id)."\n\n================\n\n".$admin_mail;
		}
		$admin_mail = __('Inquiries from the website form.', 'dashi')."\n\n".$admin_mail;
		$admin_mail = apply_filters('dashi_public_form_admin_mail_body', $admin_mail, $post_type);

		$reply_mail = self::body($class, $vals);
		$reply_mail = __('This mail is auto reply.', 'dashi')."\n\n".$reply_mail;
		$reply_mail = apply_filters('dashi_public_form_reply_mail_body', $reply_mail, $post_type);

		// メール送信時のフック
		apply_filters('dashi_public_form_mail_hook', $vals, $post_type);

		// is send mail?
		$dashi_public_form_done_sendmail = get_option('dashi_public_form_done_sendmail');
		if ( ! $dashi_public_form_done_sendmail) return;

		// send
		$from = $client_mail ?: $class::get('sendto');
		$success = \Dashi\Core\Mail::send(
			$class::get('sendto'),
			$class::get('subject'),
			$admin_mail,
			'From: '.$from
		);

		// auto reply
		$success_re = true;
		if ($class::get('is_auto_reply'))
		{
			$success_re = \Dashi\Core\Mail::send(
				$client_mail,
				$class::get('re_subject'),
				$reply_mail,
				'From: '.$class::get('replyto')
			);
		}

		return $success && $success_re;
	}

	/**
	 * body
	 *
	 * @param string $class
	 * @param object $vals
	 * @param bool $is_admin
	 * @return  string
	 */
	public static function body($class, $vals, $is_admin = false)
	{
		// body
		$fields = $class::getFlatCustomFields();
		$taxonomies = $class::get('taxonomies');

		$body = '';
		$csv = array();

		$body.= "\n\n================\n\n";

		foreach ($vals as $k => $v)
		{
			if ($k == 'post_type') continue;
			if ( ! isset($fields[$k])) continue;

			// taxonomies
			$terms = array();
			if (isset($taxonomies[$k]))
			{
				foreach (get_terms($k) as $term)
				{
					$terms[$term->term_id] = $term->name;
				};
			}

			// body
			$body.= '['.$fields[$k]['label']."]\n";

			// checkbox, multiple select, Google Map, File
			if (is_array($v))
			{
				// google map
				if (isset($v['lat']) && isset($v['lng']))
				{
					$tmp = 'https://maps.google.co.jp/maps?q='.rawurlencode(esc_html($v['place'])).'@'.esc_html($v['lat']).','.esc_html($v['lng']).'&z='.intval($v['zoom']).'z';

					$body.= esc_html($v['place'])."\n\n".$tmp."\n\n";
					$csv[] = esc_html($v['place'])." ".$tmp;
				}
				else if (isset($v['dashi_uploaded_file']))
				{
					if ($is_admin)
					{
						$csv[] = esc_html(static::uploadUrl().$v['name']);
						$csv[] = isset($v['exif']) ? $v['exif'] : '' ;
						$body.= esc_html(static::uploadUrl().$v['name'])."\n\n";
					}
					else
					{
						$body.= esc_html($v['name'])."\n\n";
					}
				}
				else
				{
					$tmps = array();
					foreach ($v as $vv)
					{
						if (isset($fields[$k]['options']) && isset($fields[$k]['options'][$vv]))
						{
							$tmps[] = esc_html($fields[$k]['options'][$vv]).'('.esc_html($vv).')';
						}
						elseif (isset($terms[$vv]))
						{
							$tmps[] = esc_html($terms[$vv]);
						}
						else
						{
							$tmps[] = esc_html($vv);
						}
					}
					$tmp = join(' ', $tmps);
					$body.= $tmp;
					$csv[] = str_replace(array("\n", "\r"), ' ', $tmp);
				}
			}

			// ordinary values
			else
			{
				if (isset($fields[$k]['options']) && isset($fields[$k]['options'][$v]))
				{
					$body.= esc_html($fields[$k]['options'][$v]).'('.esc_html($v).")\n\n";
				}
				elseif (isset($terms[$v]))
				{
					$body.= esc_html($terms[$v])."\n\n";
				}
				else
				{
					$body.= esc_html($v)."\n\n";
				}
				$csv[] = str_replace(array("\n", "\r"), ' ', $v);
			}
		}
		$body.= "================\n\n";

		if ($is_admin)
		{
			ob_start();
			fputcsv(fopen('php://output', 'w'), $csv, "\t");
			$body.= ob_get_clean();
		}

		$body.= "================\n\n";
		$body.= "-- \n";
		$body.= get_option('blogname');
		$body.= "\n";

		return $body;
	}

	/**
	 * pendingToPublish
	 *
	 * @param  Integer $post_id
	 * @param  Object  $post
	 * @return Void
	 */
	public static function pendingToPublish($post)
	{
		if ( ! get_post_meta($post->ID, '_dashi_pubic_form_pending')) return;
		add_post_meta($post->ID, '_dashi_pubic_form_pending_process', 1);

		// 画像をメディアディレクトリに移動する
		$class = \Dashi\P::posttype2class($post->post_type);

		foreach ($class::getFlatCustomFields() as $k => $v)
		{
			if ( ! isset($v['type'])) continue;
			if ($v['type'] != 'file') continue;
			if ( ! isset($post->$k) || empty($post->$k)) continue;
			$eyecatched = false;

			// ファイル名
			$file = substr($post->$k, strripos($post->$k, '=') + 1);

			// 年単位のディレクトリに保管する
			$upload_dirs = wp_upload_dir();
			$upload_path = self::uploadDir();

			// 元のファイルの位置
			$tmp_path = DASHI_TMP_UPLOAD_DIR.$file;

			if ( ! file_exists($tmp_path)) continue;

			// 移動
			rename($tmp_path, $upload_path.$file);

			// あたらしいURL
			$newurl = dirname($upload_dirs['url']).'/files/'.$file;

			// ファイルの登録
			$wp_filetype = wp_check_filetype($upload_path.$file, null);
			$attachment = array(
				'guid'  => $newurl,
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => preg_replace('/\.[^.]+$/', '', $file),
				'post_content' => '',
				'post_status' => 'inherit'
			);
			$attach_id = wp_insert_attachment($attachment, $upload_path.$file, $post->ID);

			// メディアライブラリの画像サムネイルを生成する
			$attach_data = wp_generate_attachment_metadata($attach_id, $upload_path.$file);
			wp_update_attachment_metadata($attach_id,  $attach_data);

			if ( ! $eyecatched)
			{
				update_post_meta($post->ID, '_thumbnail_id', $attach_id);
			}

			// update post meta
			update_post_meta($post->ID, $k ,$newurl);
		}

		// 正常に処理が終わったっら削除
		delete_post_meta($post->ID, '_dashi_pubic_form_pending');
	}

	/**
	 * createUploadDir
	 *
	 * @return Void
	 */
	private static function createUploadDir()
	{
		// do nothing
		if ( ! file_exists(DASHI_TMP_UPLOAD_DIR)) mkdir(DASHI_TMP_UPLOAD_DIR);

		if ( ! file_exists(DASHI_TMP_UPLOAD_DIR.'.htaccess'))
		{
			file_put_contents(DASHI_TMP_UPLOAD_DIR.'.htaccess', 'deny from all');
		}
	}
}
