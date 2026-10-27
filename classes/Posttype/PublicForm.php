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
        add_action('admin_notices', [self::class, 'uploadNotice']);
        add_action('init', [self::class, 'resumeSession'], 0);

		add_action(
			'dashi_public_form_gc_hook',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'runGarbageCollection')
		);

		if ( ! wp_next_scheduled('dashi_public_form_gc_hook'))
		{
			wp_schedule_event(time(), 'hourly', 'dashi_public_form_gc_hook');
		}
	}

    /** HTML出力前に既存セッションを再開し、初回の正当なPOSTでは作成する。 */
    public static function resumeSession()
    {
        Session::show('dashi_public_uploads');
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        if ($method === 'POST' && self::isPublicFormNonceValid()) Session::forge();
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
				'post_type'      => $post_type,
                'post_status' => ['pending', 'draft', 'future', 'private', 'publish'],
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 承認移動が未完了の投稿だけを対象に、添付データの誤削除を防ぐ。
                'meta_query' => [['key' => '_dashi_pubic_form_pending', 'compare' => 'EXISTS']],
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

        $req_method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        $is_public_form_back = self::postValue('dashi_public_form_back') === '1';
        $is_final = self::postValue('dashi_public_form_do_final') === '1' && !$is_public_form_back;
        if ($req_method === 'POST') {
            $nonce = self::postValue('_wpnonce');
            $action = ($is_final || $is_public_form_back) ? 'dashi_public_form_do_final' : 'dashi_public_form';
            $has_action = $is_final || $is_public_form_back || self::postValue('dashi_public_form_send') === '1';
            if (!$has_action || !is_string($nonce) || !wp_verify_nonce($nonce, $action)) {
                return '<p class="dashi_public_form_error">' . esc_html__('Invalid form request. Please reload the form.', 'dashi') . '</p>';
            }
            if ($is_final && !Session::show('dashi_public_form_confirmed', $form)) {
                return '<p class="dashi_public_form_error">' . esc_html__('Please confirm the form before submitting.', 'dashi') . '</p>';
            }
        } elseif ($req_method === 'GET') {
            PublicUploadStore::discardForm($form);
            Session::remove('dashi_public_form_confirmed', $form);
            Session::remove('dashi_public_form', $form);
        } else {
            return '';
        }

			// set value
			$value_req_method = $is_public_form_back ? 'BACK' : $req_method;
			$vals = self::setValue($class, $form, $value_req_method);

			// 確認画面から戻る場合は、セッション値を維持したまま入力画面を再描画する。
			if ($is_public_form_back)
			{
				return self::form($class, $form, $vals, array());
			}

		// posted value
		$errors = array();
		if ($req_method == 'POST')
		{
            $vals = self::normalizeFileValues($class, $form, $vals);
			// apply filters
			$vals = self::applyFilters($class, $vals);

			// validate
			$errors = self::validate($class, $vals);
		}

		// routing
		if ( ! $errors && $req_method == 'POST')
		{
			// 最終処理＋確認画面
			if (self::postValue('dashi_public_form_do_final') === '1')
			{
				return self::finalize($class, $vals);
			}

            Session::set('dashi_public_form_confirmed', $form, true);
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
		if (self::postValue('dashi_public_form_do_final') === '1') return $val;

		if (!static::isPublicFormNonceValid()) return array();

			// 途中段階では$_FILESの内容を確認する
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 直前で isPublicFormNonceValid() を検証済み。
			if (empty($_FILES) || !isset($_FILES[$key]) || !is_array($_FILES[$key])) return array();

				// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 直前で isPublicFormNonceValid() を検証済み。
				$file = wp_unslash($_FILES[$key]);
		if (!is_array($file) || empty($file['name'])) return array();

		// ファイルをDASHI_TMP_UPLOAD_DIRにアップする
		$val = static::handleUpload($class, $file);
        if (empty($val['errors']) && !empty($val['name'])) {
            $val = PublicUploadStore::register($form, $key, $val);
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

			$tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
			$file_name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
			$wp_filetype = wp_check_filetype_and_ext($tmp_name, $file_name);
			if (!$wp_filetype['ext'] || !$wp_filetype['type']) {
				$retVal['errors'][] = __('This file type is not allowed.', 'dashi');
				return $retVal;
			}
				$file['tmp_name'] = $tmp_name;
				$file['name'] = $file_name;
				$file['type'] = $wp_filetype['type'];

		// 拡張子
		$ext = substr($file['name'], strrpos($file['name'], '.'), strlen($file['name']));
		$ext_dotless = substr($ext, 1);

		// アップロードを許可されたファイルかどうか
		$is_uploadable = false;
		foreach ($class::get('public_form_allowed_mimes') as $allowed_ext => $allowed_mime)
		{
				$allowed_extensions = array_map('strtolower', explode('|', $allowed_ext));
				if (
					! $is_uploadable &&
					in_array(strtolower($ext_dotless), $allowed_extensions, true) &&
					$allowed_mime === $file['type']
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
		$name = wp_unique_filename(DASHI_TMP_UPLOAD_DIR, bin2hex(random_bytes(16)) . $ext);

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
        $urls = [];
        foreach ((array) Session::show('dashi_public_form', $form) as $field => $item) {
            $upload = PublicUploadStore::resolve($form, $field, $item);
            if ($upload) $urls[$field] = self::signedUploadUrl($upload['name']);
        }
        return $urls;
    }

    private static function sessionFiles($form)
    {
        $files = [];
        foreach ((array) Session::show('dashi_public_form', $form) as $field => $item) {
            $upload = PublicUploadStore::resolve($form, $field, $item);
            if ($upload) $files[$field] = array_intersect_key($upload, array_flip(['upload_id', 'name', 'original_name', 'type']));
        }
        return [$form => $files];
    }

    /** POST値の型を保持する。表示時に用途別のエスケープを行う。 */
    private static function postValue($key)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 呼び出し側でnonceと用途別の型・所有者を検証する。
        return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : null;
    }

    private static function normalizeFileValues($class, $form, $vals)
    {
        foreach ($class::getFlatCustomFields() as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'file' || !empty($attrs['private_form_only'])) continue;
            $value = $vals->$field ?? null;
            if ($value === null || $value === '' || $value === []) {
                $vals->$field = [];
                continue;
            }
            $upload = PublicUploadStore::resolve($form, $field, $value);
            $vals->$field = $upload ?: [
                'dashi_uploaded_file' => true,
                'errors' => [__('The uploaded file is no longer available. Please upload it again.', 'dashi')],
            ];
        }
        return $vals;
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

			$filter_input = self::postValue($k);

			if ($req_method == 'POST' && self::postValue('dashi_public_form_do_final') !== '1')
			{
				$vals->$k = $filter_input;
			}

			// 配列の場合
			if (
				! $filter_input && $req_method == 'POST' &&
				self::postValue('dashi_public_form_do_final') !== '1'
			)
			{
				$vals->$k = self::postValue($k);
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

        $vals = self::normalizeFileValues($class, $form, $vals);
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
				$vals->$k = \Dashi\Core\Filter::$filter($vals->$k);
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
			if (!is_array($v) || !isset($v['dashi_uploaded_file'])) continue;
			if ( empty($v['errors'])) continue;
			if (!empty($errors[$k]))
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
					(string) filemtime(DASHI_DIR . '/assets/js/public_uploader.js'),
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
					'session'      => self::sessionFiles($form),
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
        $form = self::postValue('form');
        $form = is_string($form) ? sanitize_key($form) : '';

		$class = \Dashi\Core\Posttype\Posttype::posttype2class($form);

		if (empty($file['name']) || !$class)
		{
			wp_send_json_error(array('message' => 'invalid request'), 400);
		}

        $field = self::postValue('field');
        $fields = $class::getFlatCustomFields();
        if (!is_string($field) || !isset($fields[$field]) || ($fields[$field]['type'] ?? '') !== 'file'
            || !empty($fields[$field]['private_form_only'])) {
            wp_send_json_error(['message' => 'invalid field'], 400);
        }
        $result = static::handleUpload($class, $file);
        if (empty($result['errors']) && !empty($result['name'])) {
            $result = PublicUploadStore::register($form, $field, $result);
        }
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
        $vals = self::normalizeFileValues($class, P::class2posttype($class), $vals);
		$html.= '<dl class="dashi_public_form_confirm">';
		foreach (static::getFieldsInDisplayOrder($class::get('custom_fields')) as $k => $v)
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
					elseif (($v['type'] ?? '') === 'file' && isset($vals->{$k}['dashi_uploaded_file']))
					{
						$file_name = isset($vals->{$k}['name']) ? (string) $vals->{$k}['name'] : '';
						$signed_url = $file_name !== '' ? static::signedUploadUrl($file_name) : '';
                        if ($signed_url === '') {
                            $html .= esc_html__('Please upload the file again.', 'dashi');
                            $html .= '</dd>';
                            continue;
                        }
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

		$buttons = '<button class="dashi_button" type="submit" name="dashi_public_form_back" value="1">'.esc_html__('Back', 'dashi').'</button>';
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
	 * 入力画面と同じ並びで、確認画面用にフィールド定義を展開する。
	 *
	 * 共通の getFlatCustomFields() は既存処理との互換性を維持するため変更せず、
	 * グループ配下のフィールドを親の直後へ展開する。
	 *
	 * @param array $fields カスタムフィールド定義。
	 * @return array
	 */
	private static function getFieldsInDisplayOrder ($fields)
	{
		$ordered_fields = array();

		foreach ($fields as $key => $field)
		{
			$child_fields = array();
			if (isset($field['fields']) && is_array($field['fields']))
			{
				$child_fields = $field['fields'];
				$field['fields'] = array();
			}

			$ordered_fields[$key] = $field;

			if ($child_fields)
			{
				$ordered_fields = array_merge(
					$ordered_fields,
					static::getFieldsInDisplayOrder($child_fields)
				);
			}
		}

		return $ordered_fields;
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
            PublicUploadStore::discardForm($post_type);
            Session::remove('dashi_public_form_confirmed', $post_type);
            Session::remove('dashi_public_form', $post_type);
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
        $form = P::class2posttype($class);
        $uploads = [];
        foreach ($fields as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'file' || !empty($attrs['private_form_only'])) continue;
            $value = $vals->$field ?? null;
            if ($value === null || $value === '' || $value === []) continue;
            $upload = PublicUploadStore::resolve($form, $field, $value);
            if (!$upload) return false;
            $uploads[$field] = $upload;
        }

        $post_title_field = $class::get('public_form_post_title_field');
		$new->post_title = isset($vals->$post_title_field) ?
								$vals->$post_title_field :
								'';

		$post_content_field = $class::get('public_form_post_content_field');
		$new->post_content = isset($vals->$post_content_field) ?
								$vals->$post_content_field :
								'';

		$new->post_status = 'pending';
		$new->post_type = $form;

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
            $save_values = (array) $vals;
            foreach ($uploads as $field => $upload) $save_values[$field] = self::uploadUrl() . $upload['name'];
			Save::_updateCustomFields($post_id, $save_values);
			add_post_meta($post_id, '_dashi_pubic_form_pending', true);

            PublicUploadStore::claim($post_id, $uploads);
			// file path
			foreach ($uploads as $k => $v)
			{
				if (!is_array($v) || !isset($v['dashi_uploaded_file'])) continue;
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
		$wpnonce = self::postValue('_wpnonce');
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
		$admin_mail = self::body($class, $vals, true, (int) $post_id);
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
	public static function body($class, $vals, $is_admin = false, $post_id = 0)
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
				else if (($field['type'] ?? '') === 'file' && isset($v['dashi_uploaded_file']))
				{
					if ($is_admin)
					{
						$upload = PublicUploadStore::resolve(P::class2posttype($class), $k, $v, $post_id);
                        if (!$upload && $post_id && is_string($v['name'] ?? null)) {
                            $upload = PublicUploadStore::forPost($post_id, P::class2posttype($class), $k, $v['name']);
                        }
                        $signed_upload_url = $upload ? static::signedUploadUrl($upload['name']) : '';
						$csv[] = esc_html($signed_upload_url);
						$csv[] = isset($upload['exif']) ? $upload['exif'] : '' ;
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
				// PHP 8.1以降では null を str_replace() に渡すと非推奨警告になる。
				// PHP 7.4との互換性を維持しつつ、未入力値は空文字として扱う。
				$csv[] = static::normalizeCsvScalar($v);
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
	 * メール本文へ付加するTSVの単一値を文字列へ正規化する。
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function normalizeCsvScalar ($value)
	{
		return str_replace(array("\n", "\r"), ' ', (string) $value);
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
        if (!get_post_meta($post->ID, '_dashi_pubic_form_pending', true)) return;
        $class = P::posttype2class($post->post_type);
        if (!$class) return;
        $uploads = get_post_meta($post->ID, PublicUploadStore::POST_META, true);
        $uploads = is_array($uploads) ? $uploads : [];
        $failed = false;
        $has_thumbnail = false;

        foreach ($class::getFlatCustomFields() as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'file' || empty($post->$field)) continue;
            if (isset($uploads[$field]['final_url']) && $post->$field === $uploads[$field]['final_url']) continue;
            $filename = self::extractUploadedFilename($post->$field);
            $upload = PublicUploadStore::forPost($post->ID, $post->post_type, $field, $filename);
            // 旧形式や手入力のファイル名を、受領済みのファイルとして復活させない。
            if (!$upload) {
                $failed = true;
                continue;
            }
            $copy = PublicUploadStore::copyToFinal($filename, self::uploadDir());
            if (is_wp_error($copy)) {
                $failed = true;
                continue;
            }
            $dirs = wp_upload_dir();
            $url = dirname($dirs['url']) . '/files/' . $copy['name'];
            $type = wp_check_filetype($copy['name']);
            $attachment = [
                'guid' => $url,
                'post_mime_type' => $type['type'],
                'post_title' => pathinfo($upload['original_name'] ?? $copy['name'], PATHINFO_FILENAME),
                'post_content' => '',
                'post_status' => 'inherit',
            ];
            $attachment_id = wp_insert_attachment($attachment, $copy['path'], $post->ID, true);
            if (is_wp_error($attachment_id) || !$attachment_id) {
                wp_delete_file($copy['path']);
                $failed = true;
                continue;
            }
            if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata($attachment_id, $copy['path']);
            wp_update_attachment_metadata($attachment_id, $metadata);
            update_post_meta($post->ID, $field, $url);
            $uploads[$field]['final_url'] = $url;
            update_post_meta($post->ID, PublicUploadStore::POST_META, $uploads);
            if (!$has_thumbnail) {
                update_post_meta($post->ID, '_thumbnail_id', $attachment_id);
                $has_thumbnail = true;
            }
            // 保存フックで古い一時URLを上書きされないよう、既存の引継ぎを維持する。
            update_post_meta($post->ID, '_dashi_pubic_form_pending_process', 1);
            wp_delete_file($copy['source']);
        }
        if ($failed) {
            update_post_meta($post->ID, '_dashi_public_upload_error', 1);
        } else {
            delete_post_meta($post->ID, '_dashi_public_upload_error');
            delete_post_meta($post->ID, '_dashi_pubic_form_pending');
            delete_post_meta($post->ID, PublicUploadStore::POST_META);
        }
    }

    /** 所有者を確認できない旧アップロード等は、自動移動せず編集者へ知らせる。 */
    public static function uploadNotice()
    {
        $id = Input::get('post');
        if (!is_scalar($id) || !ctype_digit((string) $id) || !current_user_can('edit_post', (int) $id)) return;
        if (!get_post_meta((int) $id, '_dashi_public_upload_error', true)) return;
        echo '<div class="notice notice-error"><p>'
            . esc_html__('An uploaded file could not be verified or stored. Check the attachment and upload it again before publishing.', 'dashi')
            . '</p></div>';
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
