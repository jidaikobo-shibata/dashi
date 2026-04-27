<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

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

		add_action(
			'wp_ajax_public_uploader_ajax',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'uploader_ajax_handler')
		);
		add_action(
			'wp_ajax_nopriv_public_uploader_ajax',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'uploader_ajax_handler')
		);

		static::createUploadDir();

		add_action(
			'dashi_public_form_gc_hook',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'runGarbageCollection')
		);

		if ( ! wp_next_scheduled('dashi_public_form_gc_hook'))
		{
			wp_schedule_event(time(), 'hourly', 'dashi_public_form_gc_hook');
		}
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

		$files = array();

		foreach ($posttypes as $post_type)
		{
			$class = P::posttype2class($post_type);
			if ( ! $class || ! class_exists($class))
			{
				continue;
			}

			$fields = $class::getFlatCustomFields();
			$pendings = get_posts(array(
				'post_type'      => $posttypes,
				'post_status'    => 'pending',
				'posts_per_page' => -1,
			));

			foreach ($pendings as $pending)
			{
				foreach ($fields as $field => $attr)
				{
					if ( ! isset($attr['type']) || $attr['type'] != 'file')
					{
						continue;
					}

					$name = static::extractUploadedFilename($pending->{$field});
					if ($name && file_exists(DASHI_TMP_UPLOAD_DIR.$name))
					{
						$files[] = DASHI_TMP_UPLOAD_DIR.$name;
					}
				}
			}

			if ( ! file_exists(DASHI_TMP_UPLOAD_DIR.'.htaccess'))
			{
				file_put_contents(DASHI_TMP_UPLOAD_DIR.'.htaccess', 'deny from all');
			}
		}

		$ttl = intval(apply_filters('dashi_public_form_tmp_file_ttl', DAY_IN_SECONDS));
		if ($ttl < 60)
		{
			$ttl = DAY_IN_SECONDS;
		}

		$files = array_unique($files);
		foreach (glob(DASHI_TMP_UPLOAD_DIR."*") as $filename)
		{
			if (in_array($filename, $files)) continue;

				if (filectime($filename) <= time() - $ttl)
				{
					wp_delete_file($filename);
				}
			}
		}

	public static function runGarbageCollection()
	{
		static::garbageCollection();
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
		if ( ! $class || ! class_exists($class))
		{
			return 'error: invalid form "' . esc_html($form) . '"';
		}

		// post_id
		global $post;

			// req_method
			$req_method = filter_input(INPUT_SERVER, "REQUEST_METHOD");
			$req_method = $req_method ? $req_method : filter_input(INPUT_ENV, "REQUEST_METHOD");

			// nonce check (public form submit only)
			$is_public_form_submit = (
				filter_input(INPUT_POST, "dashi_public_form_do_final") ||
				filter_input(INPUT_POST, "dashi_public_form_send")
			);
			if ($req_method == 'POST' && $is_public_form_submit)
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
			elseif ($req_method == 'GET')
			{
				// Initial page load should not revive stale upload/session values.
				Session::remove('dashi_public_form', $form);
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

		if (!static::isPublicFormNonceValid()) return array();

			// 途中段階では$_FILESの内容を確認する
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 直前で isPublicFormNonceValid() を検証済み。
			if (empty($_FILES) || !isset($_FILES[$key]) || !is_array($_FILES[$key])) return array();

				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 直前で isPublicFormNonceValid() を検証済み。
				$file = wp_unslash($_FILES[$key]);
		if (!is_array($file) || empty($file['name'])) return array();

		// ファイルをDASHI_TMP_UPLOAD_DIRにアップする
		$val = static::handleUpload($class, $file);

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

			$tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
			$file_name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
			$wp_filetype = wp_check_filetype_and_ext($tmp_name, $file_name);
			if (!$wp_filetype['ext'] || !$wp_filetype['type']) {
				$retVal['errors'][] = __('This file type is not allowed.', 'dashi');
				return $retVal;
			}
			$file['tmp_name'] = $tmp_name;
			$file['name'] = $file_name;

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
				/* translators: %s: comma-separated allowed mime extensions. */
				$retVal['errors'][] = sprintf(
					/* translators: %s: comma-separated allowed mime extensions. */
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
					/* translators: %s: php.ini upload_max_filesize value. */
					$retVal['errors'][] = sprintf(
						/* translators: %s: php.ini upload_max_filesize value. */
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
				/* translators: %s: uploaded file name. */
				$retVal['errors'][] = sprintf(
					/* translators: %s: uploaded file name. */
					__('%s cannot be uploaded', 'dashi'),
					esc_html($file['name'])
				);
			return $retVal;
		}

		// もとのファイル名を格納
		$retVal['original_name'] = $file['name'];

		// ファイル名を予測が難しいものにする
		$name = wp_unique_filename( DASHI_TMP_UPLOAD_DIR, $file['name']);

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
					$retVal['exif'] = array();
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
					// Skip EXIF stripping when Imagick is unavailable.
				}
			}

		// uploadする
			// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- PHP upload temp からの移動には move_uploaded_file が必要。
			if (@move_uploaded_file($file['tmp_name'], $upload_path))
			{
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- アップロード後の最小権限を強制。
				chmod($upload_path, 0644);
			}
		else
		{
			$retVal['errors'][] = __('Failed to store uploaded file.', 'dashi');
			return $retVal;
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
				wp_mkdir_p($upload_path);
			}
			return $upload_path;
		}

	public static function uploadUrl()
	{
		return plugins_url().'/dashi/file.php?path=';
	}

	private static function signedUploadUrl($filename, $ttl = null)
	{
		$filename = sanitize_file_name(wp_basename((string) $filename));
		if ($filename === '')
		{
			return '';
		}

		if ($ttl === null)
		{
			$ttl = intval(apply_filters('dashi_public_upload_url_ttl', 1800));
		}
		if ($ttl < 60)
		{
			$ttl = 1800;
		}

		$exp = time() + $ttl;
		$sig = hash_hmac('sha256', $filename.'|'.$exp, wp_salt('auth'));

		return plugins_url().'/dashi/file.php?path='.rawurlencode($filename).'&exp='.$exp.'&sig='.rawurlencode($sig);
	}

	private static function extractUploadedFilename($value)
	{
		if (!is_string($value) || $value === '')
		{
			return '';
		}

		$query = wp_parse_url($value, PHP_URL_QUERY);
		if ($query)
		{
			$params = array();
			parse_str($query, $params);
			if (!empty($params['path']))
			{
				return sanitize_file_name(wp_basename(rawurldecode((string) $params['path'])));
			}
		}

		if (strpos($value, static::uploadUrl()) === 0)
		{
			return sanitize_file_name(wp_basename(substr($value, strlen(static::uploadUrl()))));
		}

		return sanitize_file_name(wp_basename($value));
	}

	private static function buildSessionSignedUrls($form)
	{
		$all = Session::show('dashi_public_form');
		if (!is_array($all) || !isset($all[$form]) || !is_array($all[$form]))
		{
			return array();
		}

		$urls = array();
		foreach ($all[$form] as $field => $item)
		{
			if (is_array($item) && !empty($item['name']))
			{
				$url = static::signedUploadUrl($item['name']);
				if ($url)
				{
					$urls[$field] = $url;
				}
			}
		}

		return $urls;
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
					if (
						isset($v['type']) &&
						$v['type'] == 'file' &&
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 条件式内で isPublicFormNonceValid() を検証。
						isset($_FILES[$k]) &&
						static::isPublicFormNonceValid()
					)
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
					/* translators: %s: required field label. */
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
						$errors[$k][] = sprintf($err, $fields[$k]['label']);
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
					$html.= '<li><a href="#dashi_'.$k.'">'.esc_html($error).'</a></li>';
				}
			}
			$html.= '</ul>';
			$html.= '</section>';
		}

			$require_str = '&nbsp;<span class="required">'.__('Required', 'dashi').'</span>';
			$allowed_label_tags = array(
				'span' => array('class' => true),
				'strong' => array(),
				'em' => array(),
				'br' => array(),
			);

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
				$safe_title = wp_kses((string) $attrs['title'], $allowed_label_tags);
				$label = $safe_title.$required;
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
						$options = get_terms(array(
							'taxonomy' => $a_key,
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
					$options = get_terms(array(
						'taxonomy' => $field,
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

				wp_enqueue_script(
					'dashi_js_pubic_uploader',
					plugins_url('assets/js/public_uploader.js', DASHI_FILE),
					array(),
					defined('DASHI_VERSION') ? DASHI_VERSION : false,
					true
				);
					wp_localize_script('dashi_js_pubic_uploader', 'DashiUpload', array(
					'home_url'     => home_url(),
					'ajax_url'     => admin_url('admin-ajax.php'),
					'upload_url'   => static::uploadUrl(),
					'session_urls' => static::buildSessionSignedUrls($form),
					'action'       => 'public_uploader_ajax',
					'upload_nonce' => wp_create_nonce('dashi_public_uploader'),
					'max_upload_size' => wp_max_upload_size(),
					'form'         => $form,
					'session'      => Session::show('dashi_public_form') ?: array(),
				));
			}

		return $beg.$html.$end;
	}

	public static function uploader_ajax_handler()
	{
		static::enforceUploadRateLimit();

		$nonce_ok = check_ajax_referer('dashi_public_uploader', '_wpnonce', false);
		if (!$nonce_ok)
		{
			wp_send_json_error(array('message' => 'invalid nonce'), 403);
		}

		if (!isset($_FILES['file_data']) || !is_array($_FILES['file_data']))
		{
			wp_send_json_error(array('message' => 'invalid request'), 400);
		}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 直後に各要素を個別サニタイズする。
			$file_raw = wp_unslash($_FILES['file_data']);
		$file = array(
			'name'     => isset($file_raw['name']) ? sanitize_file_name((string) $file_raw['name']) : '',
			'type'     => isset($file_raw['type']) ? sanitize_mime_type((string) $file_raw['type']) : '',
			'tmp_name' => isset($file_raw['tmp_name']) ? (string) $file_raw['tmp_name'] : '',
			'error'    => isset($file_raw['error']) ? absint($file_raw['error']) : UPLOAD_ERR_NO_FILE,
			'size'     => isset($file_raw['size']) ? absint($file_raw['size']) : 0,
		);
		$form = filter_input(INPUT_POST, 'form', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		if (!is_string($form) && isset($_POST['form']))
		{
			$form = sanitize_text_field(wp_unslash((string) $_POST['form']));
		}
		$form = is_string($form) ? sanitize_key($form) : '';

		$class = \Dashi\Core\Posttype\Posttype::posttype2class($form);

		if (empty($file['name']) || !$class)
		{
			wp_send_json_error(array('message' => 'invalid request'), 400);
		}

		$result = static::handleUpload($class, $file);
		if (isset($result['errors']) && is_array($result['errors']) && !empty($result['errors']))
		{
			wp_send_json_error($result, 400);
		}
		if ($result)
		{
			$wp_filetype = wp_check_filetype_and_ext(DASHI_TMP_UPLOAD_DIR.$result['name'],DASHI_TMP_UPLOAD_DIR.$result['name']);
			$result['ext']  = empty( $wp_filetype['ext'] ) ? '' : $wp_filetype['ext'];
			$result['type'] = empty( $wp_filetype['type'] ) ? '' : $wp_filetype['type'];
			$result['path'] = static::signedUploadUrl($result['name']);
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

			if (isset($v['options'])) {
				$v['options'] = \Dashi\Core\Util::resolveOptions($v['options']);
			}

			$html.= '<dt>'.esc_html($v['label']).'</dt>';
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
					// ファイルの場合（画像のみプレビュー、それ以外はリンク）
					elseif (isset($vals->{$k}['dashi_uploaded_file']))
					{
						$file_name = isset($vals->{$k}['name']) ? (string) $vals->{$k}['name'] : '';
						$signed_url = static::signedUploadUrl($file_name);
						$wp_filetype = wp_check_filetype($file_name);
						$is_image = !empty($wp_filetype['type']) && strpos($wp_filetype['type'], 'image/') === 0;

						if ($is_image)
						{
							$html.= '<img width="200" src="' . esc_url($signed_url) . '" alt="uploaded file" />';
						}
						else
						{
							$html.= '<a href="' . esc_url($signed_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($file_name) . '</a>';
						}
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
								$options = get_terms(array(
									'taxonomy' => $k,
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
							$html.= join(', ', array_map('esc_html', $vals->$k));
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
							$options = get_terms(array(
								'taxonomy' => $k,
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
		$post_type = \Dashi\Core\Posttype\Posttype::class2posttype($class);
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
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
				throw new \Exception(__('Mail form require valid "send to mailaddress".', 'dashi'));
		}

		// mail address check. sendto is allowed comma separated value
		if ( ! $class::get('sendto') || ! Validation::multi('isMailaddress', $class::get('sendto')))
		{
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
				throw new \Exception(__('Mail form require valid "send to mailaddress".', 'dashi'));
		}

		// reply to
		if ($class::get('replyto') && ! Validation::isMailaddress($class::get('replyto')))
		{
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
				throw new \Exception(__('Mail form require valid "reply to mailaddress".', 'dashi'));
		}

		if ($class::get('public_form_admin_mail_from') && ! Validation::isMailaddress($class::get('public_form_admin_mail_from')))
		{
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
				throw new \Exception(__('Mail form require valid "from mailaddress".', 'dashi'));
		}

		// subjectless
		if ( ! $class::get('subject'))
		{
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
				throw new \Exception(__('set "subject".', 'dashi'));
		}

		// auto reply?
		if ($class::get('is_auto_reply'))
		{
			if ( ! $class::get('auto_reply_field'))
			{
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
					throw new \Exception(__('set "auto_reply_field" when use auto_reply.', 'dashi'));
			}
			if ( ! $class::get('re_subject'))
			{
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- 例外メッセージ用途。
					throw new \Exception(__('set "re_subject" when use auto_reply.', 'dashi'));
			}
		}
	}

	/**
	 * Simple IP-based rate limiting for public uploader.
	 *
	 * @return void
	 */
	private static function enforceUploadRateLimit()
	{
		$limit = 10;
		$window_seconds = 60;

		$remote_addr = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP);
		$ip = is_string($remote_addr) && $remote_addr !== '' ? $remote_addr : 'unknown';
		$key = 'dashi_upl_rate_' . md5($ip);
		$now = time();

		$state = get_transient($key);
		if (!is_array($state) || !isset($state['count']) || !isset($state['start']))
		{
			$state = array(
				'count' => 0,
				'start' => $now,
			);
		}

		// Reset the bucket when the window has passed.
		if (($now - intval($state['start'])) >= $window_seconds)
		{
			$state = array(
				'count' => 0,
				'start' => $now,
			);
		}

		$state['count'] = intval($state['count']) + 1;
		set_transient($key, $state, $window_seconds);

		if ($state['count'] > $limit)
		{
			$retry_after = max(1, $window_seconds - ($now - intval($state['start'])));
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: retry-after seconds. */
							__('Too many upload requests. Please wait %d seconds and try again.', 'dashi'),
							$retry_after
						),
					'retry_after' => $retry_after,
				),
				429
			);
		}
	}

	private static function isPublicFormNonceValid()
	{
		$wpnonce = filter_input(INPUT_POST, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		if (!is_string($wpnonce) && isset($_POST['_wpnonce']))
		{
			$wpnonce = sanitize_text_field(wp_unslash((string) $_POST['_wpnonce']));
		}
		if (!is_string($wpnonce) || $wpnonce === '') return false;

		return (bool) (
			wp_verify_nonce($wpnonce, 'dashi_public_form') ||
			wp_verify_nonce($wpnonce, 'dashi_public_form_do_final')
		);
	}

	private static function normalizeMailAddress($mail_address)
	{
		if (!is_string($mail_address)) return '';

		$mail_address = trim(str_replace(array("\r", "\n"), '', $mail_address));
		return Validation::isMailaddress($mail_address) ? $mail_address : '';
	}

	private static function adminMailHeaders($class, $client_mail)
	{
		$admin_mail_from = static::normalizeMailAddress($class::get('public_form_admin_mail_from'));
		$client_mail = static::normalizeMailAddress($client_mail);

		if (!$admin_mail_from)
		{
			$from = $client_mail ?: $class::get('sendto');
			return 'From: '.$from;
		}

		$headers = 'From: '.$admin_mail_from;
		if ($client_mail)
		{
			$headers.= "\n".'Reply-To: '.$client_mail;
		}

		return $headers;
	}

	private static function publicFormEditPostUrl($post_id)
	{
		$post_id = absint($post_id);
		if (!$post_id || !get_post($post_id))
		{
			return '';
		}

		return admin_url('post.php?post='.$post_id.'&action=edit');
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
		$edit_post_url = static::publicFormEditPostUrl($post_id);
		if ($edit_post_url)
		{
			$admin_mail = "\n\n================\n\n".$edit_post_url."\n\n================\n\n".$admin_mail;
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
		$success = \Dashi\Core\Mail::send(
			$class::get('sendto'),
			$class::get('subject'),
			$admin_mail,
			static::adminMailHeaders($class, $client_mail)
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
			if ($is_admin && isset($fields[$k]['public_form_allow_send_by_mail']) && $fields[$k]['public_form_allow_send_by_mail'] === false) continue;

			$field = $fields[$k];
			if (isset($field['options'])) {
				$field['options'] = \Dashi\Core\Util::resolveOptions($field['options']);
			}

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
						$signed_upload_url = static::signedUploadUrl($v['name']);
						$csv[] = esc_html($signed_upload_url);
						$csv[] = isset($v['exif']) ? $v['exif'] : '' ;
						$body.= esc_html($signed_upload_url)."\n\n";
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
						if (isset($field['options']) && isset($field['options'][$vv]))
						{
							$tmps[] = esc_html($field['options'][$vv]).'('.esc_html($vv).')';
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
				if (isset($field['options']) && isset($field['options'][$v]))
				{
					$body.= esc_html($field['options'][$v]).'('.esc_html($v).")\n\n";
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
		$class = \Dashi\Core\Posttype\Posttype::posttype2class($post->post_type);

		foreach ($class::getFlatCustomFields() as $k => $v)
		{
			if ( ! isset($v['type'])) continue;
			if ($v['type'] != 'file') continue;
			if ( ! isset($post->$k) || empty($post->$k)) continue;
			$eyecatched = false;

			// ファイル名
			$file = static::extractUploadedFilename($post->$k);

			// 年単位のディレクトリに保管する
			$upload_dirs = wp_upload_dir();
			$upload_path = self::uploadDir();

			// 元のファイルの位置
			$tmp_path = DASHI_TMP_UPLOAD_DIR.$file;

			if ( ! file_exists($tmp_path)) continue;

			// 移動
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- 同一ホスト内の一時領域から確定領域へ原子的に移動する。
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
			if ( ! file_exists(DASHI_TMP_UPLOAD_DIR)) wp_mkdir_p(DASHI_TMP_UPLOAD_DIR);

		if ( ! file_exists(DASHI_TMP_UPLOAD_DIR.'.htaccess'))
		{
			file_put_contents(DASHI_TMP_UPLOAD_DIR.'.htaccess', 'deny from all');
		}
	}
}
