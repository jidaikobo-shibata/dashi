<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class PublicForm
{
    private static $lateSessionForm;
    private const UPLOAD_GRANT_TTL = 30 * MINUTE_IN_SECONDS;
    private const UPLOAD_PAGE_GENERATION_META = '_dashi_upload_page_generation';

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

		add_action(
			'wp_ajax_public_uploader_ajax',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'uploader_ajax_handler')
		);
		add_action(
			'wp_ajax_nopriv_public_uploader_ajax',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'uploader_ajax_handler')
		);

        add_action('deleted_post', [PublicUploadBlob::class, 'deleteForPost']);
        // 編集画面でカスタムフィールドに加えた変更を保存し終えてから添付を処理する。
        $saved_hook = function_exists('wp_after_insert_post') ? 'wp_after_insert_post' : 'save_post';
        add_action($saved_hook, [self::class, 'afterPostSaved'], 20, 2);
        add_action('post_updated', [self::class, 'rotateUploadPageGeneration'], 0, 3);
        add_action('admin_notices', [self::class, 'uploadNotice']);
        add_action('init', [self::class, 'resumeSession'], 0);
        add_action('template_redirect', [self::class, 'startFormSession']);
        add_action('parse_request', [self::class, 'queryAttachmentParent']);
        add_filter('redirect_canonical', [self::class, 'attachmentCanonical']);
        add_action('template_redirect', [self::class, 'serveAttachment'], PHP_INT_MAX);
        add_action('admin_notices', [self::class, 'storageNotice']);
        add_action('admin_init', [self::class, 'protectLegacyDirectory']);

		add_action(
			'dashi_public_form_gc_hook',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'runGarbageCollection')
		);

		if ( ! wp_next_scheduled('dashi_public_form_gc_hook'))
		{
			wp_schedule_event(time(), 'hourly', 'dashi_public_form_gc_hook');
		}
	}

    /** initでは既存セッションだけを再開する。 */
    public static function resumeSession()
    {
        if (!\Dashi\Core\Security::requiresHttps()) Session::resume();
    }

    public static function startFormSession()
    {
        if (\Dashi\Core\Security::requiresHttps() || Session::isStarted()) return;
        $post = get_queried_object();
        if ($post instanceof \WP_Post && post_password_required($post)) return;
        $method = sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'post') return;

        // まず対象フォームと署名を照合し、受付枠を確保してからCookieを発行する。
        $form = self::postValue('dashi_public_form_name');
        if (!is_string($form) || sanitize_key($form) !== $form || !P::posttype2class($form)
            || self::postValue('dashi_public_form_send') !== '1'
            || self::postValue('dashi_public_form_discard') === '1') return;
        $nonce = self::postValue('_wpnonce');
        $token = self::postValue('dashi_public_form_token');
        $page = (int) ($post->ID ?? ($GLOBALS['post']->ID ?? 0));
        if (is_string($nonce) && wp_verify_nonce($nonce, 'dashi_public_form')
            && self::isLateFormToken($form, $page, $token) && !self::formRateRetry('confirm')) {
            Session::forge();
            if (Session::isStarted()) self::$lateSessionForm = $form;
        }
    }

    private static function isAttachmentRequest()
    {
        return filter_input(INPUT_GET, 'dashi_attachment', FILTER_UNSAFE_RAW) === '1';
    }

    public static function queryAttachmentParent($request)
    {
        if (!self::isAttachmentRequest() || \Dashi\Core\Security::requiresHttps()
            || filter_has_var(INPUT_GET, 'scope')) return;
        $path = filter_input(INPUT_GET, 'path', FILTER_UNSAFE_RAW);
        $name = is_string($path) ? sanitize_file_name(wp_basename(rawurldecode(wp_unslash($path)))) : '';
        if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $name)) return;

        $signed = filter_has_var(INPUT_GET, 'sig') || filter_has_var(INPUT_GET, 'exp') || filter_has_var(INPUT_GET, 'post');
        $candidate = 0;
        if ($signed) {
            $candidate = (int) filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $expiry = (int) filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT);
            $sig = filter_input(INPUT_GET, 'sig', FILTER_UNSAFE_RAW);
            $context = $candidate ? '|' . $candidate : '';
            if ($expiry < time() || !is_string($sig)
                || !hash_equals(hash_hmac('sha256', $name . '|' . $expiry . $context, wp_salt('auth')), strtolower(trim(wp_unslash($sig))))) return;
        }

        $blob = PublicUploadBlob::get($name);
        $id = $blob ? (int) $blob->post_id : $candidate;
        $parent = $id > 0 ? get_post($id) : null;
        if (!$parent) return;
        if ($signed) {
            if (($candidate && $candidate !== $id) || !current_user_can('edit_post', $id)
                || !PublicUploadStore::hasDownloadReference($parent, $name, $blob ? 'db' : 'legacy')) return;
        } elseif (!self::canDownloadPublicAttachment($parent, $name, true)) {
            return;
        }

        // Dashi側で許可できる添付だけを親投稿の閲覧制限へ渡す。
        $request->query_vars = ['p' => $parent->ID, 'post_type' => $parent->post_type];
        if ($signed) $request->query_vars['post_status'] = $parent->post_status;
    }

    public static function attachmentCanonical($url)
    {
        return self::isAttachmentRequest() ? false : $url;
    }

    public static function serveAttachment()
    {
        if (!self::isAttachmentRequest()) return;
        $dashi_routed_attachment = true;
        require DASHI_DIR . '/file.php';
    }

    public static function protectLegacyDirectory()
    {
        $path = DASHI_TMP_UPLOAD_DIR . '.htaccess';
        if (!is_dir(DASHI_TMP_UPLOAD_DIR) || file_exists($path)) return;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- xモードで既存設定を上書きしない。
        $stream = @fopen($path, 'xb');
        if (!$stream) return;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 同じ排他的ストリームへ書き込む。
        fwrite($stream, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ストリームを閉じる。
        fclose($stream);
    }

    public static function storageNotice()
    {
        $screen = get_current_screen()->id ?? '';
        if (!current_user_can('manage_options') || !in_array($screen, ['dashboard', 'settings_page_dashi_options'], true)) return;
        // 保管状況は設定画面だけに表示し、旧形式の添付に関する警告はダッシュボードにも残す。
        $usage = $screen === 'settings_page_dashi_options' ? PublicUploadBlob::usage() : null;
        if ($usage) {
            echo '<div class="notice notice-info"><p>' . esc_html(sprintf(
                /* translators: 1: unsubmitted file count, 2: unsubmitted size, 3: files awaiting review, 4: size awaiting review, 5: size retained after approval. */
                __('Dashi attachments: %1$d unsubmitted (%2$s), %3$d awaiting review (%4$s), %5$s retained after approval.', 'dashi'),
                $usage->temporary_files, size_format($usage->temporary) ?: '0 B', $usage->pending_files, size_format($usage->pending) ?: '0 B', size_format($usage->retained) ?: '0 B'
            )) . '</p>';
            $links = [];
            foreach (PublicUploadBlob::oldestPendingPosts() as $post_id) {
                if (!current_user_can('edit_post', (int) $post_id)) continue;
                $url = get_edit_post_link((int) $post_id, 'raw');
                if (!$url) continue;
                $links[] = '<a href="' . esc_url($url) . '">' . esc_html(get_the_title((int) $post_id)) . '</a>';
            }
            if ($links) {
                echo '<p>' . esc_html__('Oldest retained attachments', 'dashi') . '</p><ul>';
                foreach ($links as $link) echo '<li>' . wp_kses($link, ['a' => ['href' => true]]) . '</li>';
                echo '</ul>';
            }
            echo '</div>';
        }
        if (is_dir(DASHI_TMP_UPLOAD_DIR) && glob(DASHI_TMP_UPLOAD_DIR . '*', GLOB_NOSORT)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__(
                'Dashi found legacy temporary attachments. Keep direct HTTP access to their directory blocked at the web server until those files have been reviewed.',
                'dashi'
            ) . '</p></div>';
        }
    }

    private static function uploadGrant($form, array $fields, $page)
    {
        $revision = self::uploadPageRevision($page, $form);
        if ($revision === null) return '';
        if (!Session::isStarted()) {
            // 初回表示では、実際に描画したフォームの入力欄に限定した署名を返す。
            $payload = base64_encode(wp_json_encode([$form, $fields, time() + self::UPLOAD_GRANT_TTL, $page, $revision]));
            return $payload . '.' . self::signUploadGrant($payload);
        }
        $key = self::formSessionKey($form) . ':' . $page;
        $grant = Session::show('dashi_public_upload_grants', $key);
        if (!self::isLiveSessionUploadGrant($grant) || $grant['revision'] !== $revision) {
            $grant = [
                'token' => $page . ':' . bin2hex(random_bytes(32)), 'expires' => time() + self::UPLOAD_GRANT_TTL,
                'revision' => $revision,
            ];
        }
        $grant['fields'] = $fields;
        Session::set('dashi_public_upload_grants', $key, $grant);
        return $grant['token'];
    }

    private static function signUploadGrant($payload)
    {
        return hash_hmac('sha256', 'dashi-upload|' . get_current_blog_id() . '|' . $payload, wp_salt('nonce'));
    }

    private static function isLiveSessionUploadGrant($grant)
    {
        return is_array($grant) && is_string($grant['token'] ?? null) && $grant['token'] !== ''
            && is_array($grant['fields'] ?? null)
            && is_string($grant['revision'] ?? null)
            && is_int($grant['expires'] ?? null) && $grant['expires'] > time();
    }

    /** 本文や公開状態を戻しても、発行済みの添付許可を復活させない。 */
    public static function rotateUploadPageGeneration($id, $after, $before)
    {
        if (($before->post_status !== 'publish' || !is_post_type_viewable($before->post_type))
            && ($after->post_status !== 'publish' || !is_post_type_viewable($after->post_type))) return;

        if ($before->post_content === $after->post_content && $before->post_status === $after->post_status
            && $before->post_password === $after->post_password && $before->post_type === $after->post_type) return;

        update_post_meta($id, self::UPLOAD_PAGE_GENERATION_META, wp_generate_uuid4());
    }

    private static function uploadPageRevision($id, $form, $fresh = false)
    {
        if ($id > 0 && $fresh) {
            // ファイル処理中に別リクエストが変更した投稿とメタを読み直す。
            wp_cache_delete($id, 'posts');
            wp_cache_delete($id, 'post_meta');
        }
        $page = $id > 0 ? get_post($id) : null;
        if (!$page instanceof \WP_Post || $page->post_status !== 'publish' || !is_post_type_viewable($page->post_type)) {
            return null;
        }

        // AJAXでも、サイトが投稿クエリーに追加する閲覧制限を通す。
        $matches = get_posts([
            'p' => $id,
            'post_type' => $page->post_type,
            'post_status' => 'publish',
            'numberposts' => 1,
            'suppress_filters' => false,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $visible = $matches[0] ?? null;
        if (!$visible instanceof \WP_Post || (int) $visible->ID !== $id
            || $visible->post_type !== $page->post_type || $visible->post_status !== 'publish') return null;

        return \Dashi\Core\Util::withSingularPostQuery($visible, static function () use ($visible, $form) {
            if (post_password_required($visible) || !apply_filters('dashi_search_can_show_result', true, $visible)) return null;
            // テーマや別パーツで出力するフォームでは、サイト側で構成の変更も失効条件にできる。
            $sourceRevision = apply_filters('dashi_public_form_upload_revision', '', $visible, $form);
            if (!is_string($sourceRevision)) return null;
            $generation = get_post_meta($visible->ID, self::UPLOAD_PAGE_GENERATION_META, false);
            return hash('sha256', serialize([$visible->post_content, $sourceRevision, $generation]));
        });
    }

    private static function hasUploadGrant($form, $field, $expectedPage = null, $fresh = false)
    {
        $provided = self::postValue('dashi_upload_grant');
        if (!is_string($provided) || strlen($provided) > 8192) return false;
        if (preg_match('/\A([1-9][0-9]*):[a-f0-9]{64}\z/', $provided, $match)) {
            $page = (int) $match[1];
            $grant = Session::show('dashi_public_upload_grants', self::formSessionKey($form) . ':' . $page);
            return self::isLiveSessionUploadGrant($grant) && hash_equals($grant['token'], $provided)
                && in_array($field, $grant['fields'], true)
                && ($expectedPage === null || $expectedPage === $page)
                && $grant['revision'] === self::uploadPageRevision($page, $form, $fresh);
        }

        $parts = explode('.', $provided, 2);
        if (count($parts) !== 2) return false;
        [$payload, $signature] = $parts;
        if (!hash_equals(self::signUploadGrant($payload), $signature)) return false;
        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data) || count($data) !== 5 || $data[0] !== $form
            || !is_array($data[1]) || !in_array($field, $data[1], true)
            || !is_int($data[2]) || $data[2] <= time() || !is_int($data[3]) || !is_string($data[4])) return false;

        return ($expectedPage === null || $expectedPage === $data[3])
            && $data[4] === self::uploadPageRevision($data[3], $form, $fresh);
    }

    private static function rateLimitMessage($seconds)
    {
        /* translators: %d: seconds before retrying a public form request. */
        return sprintf(__('Too many requests. Please wait %d seconds and try again.', 'dashi'), $seconds);
    }

	public static function runGarbageCollection()
	{
        PublicUploadBlob::collect();
        PublicFormState::collect();
        if (is_dir(DASHI_TMP_UPLOAD_DIR)) {
            self::protectLegacyDirectory();
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
        if (\Dashi\Core\Security::requiresHttps()) {
            if (!headers_sent()) {
                status_header(403);
                nocache_headers();
            }
            return '<p class="dashi_public_form_error">' . esc_html__('HTTPS is required.', 'dashi') . '</p>';
        }
		$class = \Dashi\Core\Posttype\Posttype::posttype2class($form);
		if ( ! $class || ! class_exists($class))
		{
			return 'error: invalid form "' . esc_html($form) . '"';
		}

        global $post;

        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
        $confirmationKey = self::formSessionKey($form);
        if ($method === 'GET') {
            return self::form($class, $form, self::setValue($class, $form, 'GET'), []);
        }
        if ($method !== 'POST') return '';

        $back = self::postValue('dashi_public_form_back') === '1';
        $final = self::postValue('dashi_public_form_do_final') === '1' && !$back;
        $discard = !$final && !$back && self::postValue('dashi_public_form_discard') === '1';
        $nonce = self::postValue('_wpnonce');
        $action = ($final || $back) ? 'dashi_public_form_do_final' : 'dashi_public_form';
        if ((!$final && !$back && self::postValue('dashi_public_form_send') !== '1')
            || !is_string($nonce) || !wp_verify_nonce($nonce, $action)) {
            return '<p class="dashi_public_form_error">' . esc_html__('Invalid form request. Please reload the form.', 'dashi') . '</p>';
        }

        if ($final || $back) {
            $confirmation = Session::show('dashi_public_form_confirmed', $confirmationKey);
            $provided = self::postValue('dashi_confirmation_id');
            if (!is_array($confirmation) || !is_string($provided) || strlen($provided) !== 64
                || $confirmation['page'] !== (int) ($post->ID ?? 0) || $confirmation['expires'] <= time()
                || !hash_equals($confirmation['id'], $provided)) {
                return '<p class="dashi_public_form_error">' . esc_html__('Please confirm the form before submitting.', 'dashi') . '</p>';
            }
            if ($back) {
                Session::remove('dashi_public_form_confirmed', $confirmationKey);
                return self::form($class, $form, self::setValue($class, $form, 'BACK'), []);
            }
            $snapshot = (object) $confirmation['values'];
            if (!PublicFormState::canFinalizeSubmission()) return self::retrySubmission($class, $snapshot);
            if ($retry = self::formRateRetry('submit')) return self::rateLimitResponse($retry);

            $key = 'submission_' . $confirmation['id'];
            $worker = PublicFormState::lock('worker_' . $key, 0, false);
            if (!$worker) return self::retrySubmission($class, $snapshot);
            try {
                $lock = PublicFormState::lock($key);
                if (!$lock) return self::retrySubmission($class, $snapshot);
                try {
                    $journal = PublicFormState::read($key);
                    if ($journal === null) return self::retrySubmission($class, $snapshot);
                    // 旧版の処理中フラグと、開始済みの副作用は自動再試行しない。
                    if (($journal['processing'] ?? null) === true || ($journal['post'] ?? null) === 'started'
                        || self::hasUncertainAcceptanceMail($journal)) return self::uncertainSubmission();

                    // 作業ロックを取れた場合、残ったフラグを前回の中断として扱える。
                    $journal['processing'] = 'locked';
                    if (!self::saveSubmission($key, $journal)) return self::retrySubmission($class, $snapshot);
                } finally {
                    PublicFormState::unlock($lock);
                }

                try {
                    $postId = (int) ($journal['post'] ?? 0);
                    $vals = self::normalizeFileValues($class, $form, $snapshot, $postId);
                    $errors = self::validate($class, $vals);
                    if (!$errors || $postId) return self::finalize($class, $vals, $key);
                } finally {
                    if (PublicFormState::canFinalizeSubmission() && PublicFormState::ownsLock('worker_' . $key)) {
                        self::releaseSubmission($key, true);
                    }
                }
            } finally {
                PublicFormState::unlock($worker);
            }
            Session::remove('dashi_public_form_confirmed', $confirmationKey);
            return self::form($class, $form, self::setValue($class, $form, 'BACK'), $errors);
        }

        if (!self::hasFormToken($form, (int) ($post->ID ?? 0), $discard)) {
            return '<p class="dashi_public_form_error">' . esc_html__('Invalid form request. Please reload the form.', 'dashi') . '</p>';
        }
        if (self::$lateSessionForm !== $form && ($retry = self::formRateRetry('confirm'))) {
            return self::rateLimitResponse($retry);
        }
        if ($discard) {
            if (!PublicUploadStore::discardForm($form, true)) {
                return '<p class="dashi_public_form_error">' . esc_html__('The submission could not be completed. Please try again.', 'dashi') . '</p>';
            }
            Session::remove('dashi_public_form_confirmed', $confirmationKey);
            Session::remove('dashi_public_form', self::formSessionKey($form));
            return self::form($class, $form, (object) ['post_type' => $form], []);
        }
        if (!self::confirmationInputFits($class)) {
            status_header(400);
            return '<p class="dashi_public_form_error">' . esc_html__('Invalid form request. Please reload the form.', 'dashi') . '</p>';
        }
        Session::remove('dashi_public_form_confirmed', $confirmationKey);
        $vals = self::applyFilters($class, clone self::setValue($class, $form, 'POST'));
        $errors = self::validate($class, $vals);
        if ($errors) return self::form($class, $form, $vals, $errors);

        $values = (array) $vals;
        foreach ($class::getFlatCustomFields() as $field => $attrs) {
            if (($attrs['type'] ?? '') === 'file' && isset($values[$field]['upload_id'])) {
                $values[$field] = ['upload_id' => $values[$field]['upload_id']];
            }
        }
        Session::set('dashi_public_form_confirmed', $confirmationKey, [
            'id' => bin2hex(random_bytes(32)),
            'page' => (int) ($post->ID ?? 0),
            'expires' => time() + 30 * MINUTE_IN_SECONDS,
            'values' => $values,
        ]);
        return self::confirm($class, $vals);
    }

    private static function formSessionKey($form)
    {
        return get_current_blog_id() . ':' . $form;
    }

    private static function formToken($form, $page)
    {
        if (!Session::isStarted()) {
            // 初回表示ではセッションを作らず、同一オリジンでの送信に使う署名を返す。
            $expires = time() + 30 * MINUTE_IN_SECONDS;
            return $expires . '.' . self::signLateFormToken($form, $page, $expires);
        }

        $key = self::formSessionKey($form) . ':' . $page;
        $token = Session::show('dashi_public_form_tokens', $key);
        if (!$token) {
            $token = bin2hex(random_bytes(32));
            Session::set('dashi_public_form_tokens', $key, $token);
        }
        return $token;
    }

    private static function signLateFormToken($form, $page, $expires)
    {
        // 投稿IDを持たないテンプレートでも、発行先のパスに限定する。
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- パスは署名入力の照合にのみ使う。
        $path = $page ? '' : wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return hash_hmac('sha256', 'dashi-late-form|' . self::formSessionKey($form)
            . '|' . $page . '|' . $path . '|' . $expires, wp_salt('nonce'));
    }

    private static function hasFormToken($form, $page, $discard)
    {
        $provided = self::postValue('dashi_public_form_token');
        if (!is_string($provided)) return false;
        $token = Session::show('dashi_public_form_tokens', self::formSessionKey($form) . ':' . $page);
        if (is_string($token) && hash_equals($token, $provided)) return true;
        return !$discard && Session::isStarted() && self::isLateFormToken($form, $page, $provided);
    }

    private static function isLateFormToken($form, $page, $provided)
    {
        if (!is_string($provided) || !preg_match('/\A([0-9]{1,12})\.([a-f0-9]{64})\z/', $provided, $parts)) return false;

        $url = wp_parse_url(home_url('/'));
        if (!isset($url['scheme'], $url['host'])) return false;
        $origin = $url['scheme'] . '://' . $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- オリジンを加工せず完全一致で照合する。
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        return $origin === $requestOrigin && (int) $parts[1] > time()
            && hash_equals(self::signLateFormToken($form, $page, $parts[1]), $parts[2]);
    }

    private static function rateLimitResponse($seconds)
    {
        status_header(429);
        return '<p class="dashi_public_form_error">' . esc_html(self::rateLimitMessage($seconds)) . '</p>';
    }

    private static function confirmationInputFits($class)
    {
        $bytes = 0;
        $entries = 0;
        foreach ($class::getFlatCustomFields() as $field => $attrs) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- nonce検証後、wp_unslash()より先に生データの大きさを確認する。
            if (!empty($attrs['private_form_only']) || !isset($_POST[$field])) continue;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- 上と同じ。
            $value = $_POST[$field];
            $type = $attrs['type'] ?? '';
            if (is_array($value) && !in_array($type, ['checkbox', 'select', 'taxonomy', 'google_map', 'file'], true)) {
                return false;
            }
            // 複数選択、地図、添付の受領IDは、いずれも1階層の配列で送られる。
            foreach (is_array($value) ? $value : [$field => $value] as $key => $item) {
                if (!is_string($item) || strlen($item) > 16 * 1024) return false;
                $bytes += strlen((string) $key) + strlen($item);
                if (++$entries > 256 || $bytes > 64 * 1024) return false;
            }
        }
        return true;
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

        if (!self::hasUploadGrant($form, $key, (int) ($GLOBALS['post']->ID ?? 0))) {
            return ['dashi_uploaded_file' => true, 'errors' => [__('Reload the form before uploading an attachment.', 'dashi')]];
        }
        if ($retry = self::formRateRetry('upl')) {
            return ['dashi_uploaded_file' => true, 'errors' => [self::rateLimitMessage($retry)]];
        }
        return static::handleUpload($class, $file, $form, $key);
	}

	/**
	 * handleUpload
	 * POST のアップロード、とAjax のアップロード両方を処理
	 *
	 * @param String $class
	 * @param String $file
	 */
	protected static function handleUpload($class, $file, $form, $field)
	{
        $providedGrant = self::postValue('dashi_upload_grant');
		$retVal = array();
		$retVal['dashi_uploaded_file'] = true;

			$tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
			$file_name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
            $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($uploadError !== UPLOAD_ERR_OK) {
                if ($uploadError === UPLOAD_ERR_INI_SIZE) {
                    /* translators: %s: php.ini upload_max_filesize value. */
                    $retVal['errors'][] = sprintf(__('This file is too large. (allowed: %s)', 'dashi'), ini_get('upload_max_filesize'));
                } else {
                    /* translators: %s: uploaded file name. */
                    $retVal['errors'][] = sprintf(__('%s cannot be uploaded', 'dashi'), esc_html($file_name));
                }
                return $retVal;
            }
            // PHPが受け取った一時ファイルの実サイズを、形式判定より先に確認する。
            $max_bytes = min(wp_max_upload_size(), PublicUploadBlob::MAX_FILE_BYTES);
            $bytes = is_uploaded_file($tmp_name) ? filesize($tmp_name) : false;
            if ($bytes === false || $bytes > $max_bytes) {
                $retVal['errors'][] = __('The upload failed or exceeds the 1 MiB attachment limit.', 'dashi');
                return $retVal;
            }
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
		$name = bin2hex(random_bytes(16)) . $ext;

		$retVal['name'] = $name;
        $retVal['type'] = $file['type'];
        $retVal['ext'] = $ext_dotless;
        Session::forge();
        if (!Session::isStarted()) {
            $retVal['errors'][] = __('Reload the form before uploading an attachment.', 'dashi');
            return $retVal;
        }

        $retVal['exif'] = '';
        $content = null;
        if (strpos($file['type'], 'image/') === 0) {
            if ($class::get('public_form_remove_exif')) {
                $slot = PublicFormState::lock('image_processing', 0);
                if (!$slot) {
                    $retVal['errors'][] = __('Image processing is busy. Please try again shortly.', 'dashi');
                    return $retVal;
                }
                try {
                    // 変換後のサイズはまだ分からないため、空きがあるかだけを検査する。
                    if (!PublicUploadBlob::canAccept($form, 1)) {
                        $retVal['errors'][] = __('The attachment could not be stored. The upload storage may be full.', 'dashi');
                        return $retVal;
                    }
                    $content = self::imageWithoutMetadata($tmp_name, $max_bytes);
                } finally {
                    PublicFormState::unlock($slot);
                }
                if ($content === false || $content === '') {
                    $retVal['errors'][] = __('The attachment metadata could not be removed.', 'dashi');
                    return $retVal;
                }
                $retVal['metadata_removed'] = true;
            } elseif ($class::get('is_send_exif') && function_exists('exif_read_data')) {
                $exif = @exif_read_data($tmp_name, 0, true);
                if (is_array($exif)) $retVal['exif'] = wp_json_encode($exif);
            }
        }

        // PHP が受け取った一時ファイルから読み、公開ディレクトリには書かない。
        $content = $content ?? file_get_contents($tmp_name, false, null, 0, $max_bytes + 1);
        if ($content === false || $content === '' || strlen($content) > $max_bytes) {
            $retVal['errors'][] = __('The upload failed or exceeds the 1 MiB attachment limit.', 'dashi');
            return $retVal;
        }
        return PublicUploadStore::register($form, $field, $retVal, $content, static function () use ($form, $field, $providedGrant) {
            return self::postValue('dashi_upload_grant') === $providedGrant && self::hasUploadGrant($form, $field, null, true);
        });
	}

    private static function imageWithoutMetadata($path, $maxBytes)
    {
        if (!class_exists('Imagick')) return false;

        $limits = [
            'MEMORY' => 64 * 1024 * 1024,
            'MAP' => 64 * 1024 * 1024,
            'DISK' => 0,
            'THREAD' => 1,
            'TIME' => 10,
            'LISTLENGTH' => 32,
        ];
        $restore = [];
        try {
            foreach ($limits as $name => $limit) {
                $constant = 'Imagick::RESOURCETYPE_' . $name;
                if (!defined($constant)) continue;
                $type = constant($constant);
                $previous = \Imagick::getResourceLimit($type);
                if ($previous <= $limit) continue;
                $restore[$type] = $previous;
                if (!\Imagick::setResourceLimit($type, $limit)) return false;
            }

            $header = @getimagesize($path);
            if (!$header || !self::imageDimensionsFit($header[0], $header[1])) return false;

            $image = new \Imagick();
            if (!$image->pingImage($path) || !self::imageFramesFit($image)) return false;
            $image->clear();
            if (!$image->readImage($path) || !self::imageFramesFit($image)) return false;
            // アニメーション画像はすべてのフレームからメタデータを除去する。
            foreach ($image as $frame) {
                if (!$frame->stripImage()) return false;
            }
            $image->setFirstIterator();
            return self::readEncodedImage($image, $maxBytes);
        } catch (\Throwable $e) {
            return false;
        } finally {
            if (isset($image)) $image->clear();
            foreach ($restore as $type => $previous) {
                \Imagick::setResourceLimit($type, $previous);
            }
        }
    }

    private static function readEncodedImage($image, $maxBytes)
    {
        // PHPの文字列に展開する前に、一時ファイル上で変換後のサイズを確認する。
        $output = tmpfile();
        if (!$output) return false;
        try {
            if (!$image->writeImagesFile($output, $image->getImageFormat()) || !fflush($output)) return false;
            $size = fstat($output)['size'] ?? false;
            if ($size === false || $size < 1 || $size > $maxBytes || !rewind($output)) return false;
            $content = stream_get_contents($output, $maxBytes + 1);
            return is_string($content) && strlen($content) === $size ? $content : false;
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Imagickに渡した一時ストリームを閉じる。
            fclose($output);
        }
    }

    private static function imageDimensionsFit($width, $height)
    {
        return $width > 0 && $height > 0 && $width <= 4096 && $height <= 4096;
    }

    private static function imageFramesFit($image)
    {
        if ($image->getNumberImages() < 1 || $image->getNumberImages() > 32) return false;
        $pixels = 0;
        foreach ($image as $frame) {
            $width = $frame->getImageWidth();
            $height = $frame->getImageHeight();
            $canvas = $frame->getImagePage();
            if (!self::imageDimensionsFit($width, $height)
                || !self::imageDimensionsFit(max($width, $canvas['width']), max($height, $canvas['height']))) return false;
            $pixels += $width * $height;
            if ($pixels > 16777216) return false;
        }
        return true;
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

	private static function signedUploadUrl($filename, $ttl = null, $post_id = 0, $mail = false, $expiresAt = null)
	{
		$filename = sanitize_file_name(wp_basename((string) $filename));
		if ($filename === '')
		{
			return '';
		}

        $exp = $expiresAt ?? self::uploadUrlExpiry($ttl);
        $context = $post_id ? '|' . (int) $post_id : ($mail ? '|mail' : '');
        $sig = hash_hmac('sha256', $filename . '|' . $exp . $context, wp_salt('auth'));

        if ($post_id) {
            return add_query_arg([
                'dashi_attachment' => '1', 'path' => rawurlencode($filename), 'exp' => $exp,
                'sig' => $sig, 'post' => (int) $post_id,
            ], home_url('/'));
        }
        return self::uploadUrl() . rawurlencode($filename) . '&exp=' . $exp . '&sig=' . $sig
            . ($mail ? '&scope=mail' : '');
	}

    private static function uploadUrlExpiry($ttl = null)
    {
        if ($ttl === null) $ttl = (int) apply_filters('dashi_public_upload_url_ttl', 1800);
        return time() + ($ttl < 60 ? 1800 : $ttl);
    }

	public static function extractUploadedFilename($value)
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
			if (isset($params['path']) && is_string($params['path']) && $params['path'] !== '')
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
        foreach ((array) Session::show('dashi_public_form', self::formSessionKey($form)) as $field => $item) {
            $upload = PublicUploadStore::resolve($form, $field, $item);
            if ($upload) $urls[$field] = self::signedUploadUrl($upload['name']);
        }
        return $urls;
    }

    private static function sessionFiles($form)
    {
        $files = [];
        foreach ((array) Session::show('dashi_public_form', self::formSessionKey($form)) as $field => $item) {
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

    private static function normalizeFileValues($class, $form, $vals, $postId = 0)
    {
        foreach ($class::getFlatCustomFields() as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'file' || !empty($attrs['private_form_only'])) continue;
            $value = $vals->$field ?? null;
            if ($value === null || $value === '' || $value === []) {
                $vals->$field = [];
                continue;
            }
            $upload = PublicUploadStore::resolve($form, $field, $value, $postId);
            if (!$upload && $postId && is_array($value)) {
                $manifest = get_post_meta($postId, PublicUploadStore::POST_META, true);
                $record = $manifest[$field] ?? [];
                if (isset($record['name'], $record['upload_id']) && $record['upload_id'] === ($value['upload_id'] ?? '')) {
                    $upload = PublicUploadStore::forPost($postId, $form, $field, $record['name']);
                }
                if (!$upload) $upload = PublicUploadStore::resolve($form, $field, $value);
            }
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
		$sess = Session::show('dashi_public_form', self::formSessionKey($form));
		$vals = $sess ? clone (object) $sess : $vals;

		// set post when it exists
		$vals->post_type = $form;
        $upload_errors = [];

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

			if ($req_method !== 'POST') continue;
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
                    $upload = self::upload($class, $form, $vals->$k, $k);
                    if (!empty($upload['errors'])) $upload_errors[$k] = $upload;
                    $vals->$k = $upload;
				}
		}

        $vals = self::normalizeFileValues($class, $form, $vals);
        foreach ($upload_errors as $key => $upload) $vals->$key = $upload;
		// set back to session
		if ($req_method === 'POST') Session::set('dashi_public_form', self::formSessionKey($form), $vals);

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

        foreach ($fields as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'taxonomy' || !empty($attrs['private_form_only'])
                || !isset($vals->$field)) continue;
            $terms = self::existingTermIds($field, $vals->$field);
            if ($terms === null) {
                /* translators: %s: field label. */
                $errors[$field][] = sprintf(__('Select an existing option for %s.', 'dashi'), $attrs['label']);
            }
        }

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

    private static function existingTermIds($taxonomy, $value)
    {
        if ($value === '' || $value === null || $value === []) return [];
        $ids = [];
        foreach ((array) $value as $term) {
            if ((!is_string($term) && !is_int($term)) || !ctype_digit((string) $term)) return null;
            $id = (int) $term;
            if ($id <= 0 || ltrim((string) $term, '0') !== (string) $id) return null;
            $ids[$id] = $id;
        }
        $existing = get_terms([
            'taxonomy' => $taxonomy,
            'include' => array_values($ids),
            'hide_empty' => false,
            'fields' => 'ids',
        ]);
        if (is_wp_error($existing) || count($existing) !== count($ids)) return null;
        return array_values($ids);
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

        $visible_fields = array_filter($fields, static function ($attrs) {
            return empty($attrs['private_form_only']);
        });
        $upload_fields = array_keys(array_filter(CustomFieldFlattener::flatten($visible_fields), static function ($attrs) {
            return ($attrs['type'] ?? '') === 'file' && empty($attrs['private_form_only']);
        }));
        $is_file_exist = !empty($upload_fields);

		// form
		global $post;
		$enctype = $is_file_exist ? ' enctype="multipart/form-data"' : '';
		$beg = '<form class="dashi_form" action="'.esc_url(get_permalink($post->ID)).'" method="POST"'.$enctype.'>';

		$end = '<input class="dashi_button-primary" type="submit" value="'.esc_attr__('Send', 'dashi').'">';
		$end = apply_filters('dashi_public_form_submit_button', $end, $post_type);
		$end.= '<input type="hidden" name="dashi_public_form_send" value="1" />';
		$end.= wp_nonce_field(
			'dashi_public_form',
			'_wpnonce',
			true,
			false
		);
        $end .= '<input type="hidden" name="dashi_public_form_name" value="' . esc_attr($form) . '" />';
        $end .= '<input type="hidden" name="dashi_public_form_token" value="'
            . esc_attr(self::formToken($form, (int) $post->ID)) . '" />';
        if (Session::isStarted() && ($is_file_exist
            || Session::show('dashi_public_form', self::formSessionKey($form))
            || PublicUploadStore::hasUnsubmitted($form))) {
            $end .= '<button class="dashi_button" type="submit" name="dashi_public_form_discard" value="1" formnovalidate>'
                . esc_html__('Discard draft', 'dashi') . '</button>';
        }
        if ($is_file_exist) {
            $end .= '<input type="hidden" name="dashi_upload_grant" value="' . esc_attr(self::uploadGrant($form, $upload_fields, (int) $post->ID)) . '" />';
            $end .= '<input type="hidden" name="dashi_upload_form" value="' . esc_attr($form) . '" />';
        }
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
					'max_upload_size' => min(wp_max_upload_size(), PublicUploadBlob::MAX_FILE_BYTES),
					'form'         => $form,
					'session'      => self::sessionFiles($form),
				));
			}

		return $beg.$html.$end;
	}

	public static function uploader_ajax_handler()
	{
        if (\Dashi\Core\Security::requiresHttps()) {
            wp_send_json_error(['message' => __('HTTPS is required.', 'dashi')], 403);
        }

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
        if (!self::hasUploadGrant($form, $field)) {
            wp_send_json_error(['message' => __('Reload the form before uploading an attachment.', 'dashi')], 403);
        }
        if ($retry = self::formRateRetry('upl')) {
            wp_send_json_error(['message' => self::rateLimitMessage($retry), 'retry_after' => $retry], 429);
        }
        $result = static::handleUpload($class, $file, $form, $field);
        if (!empty($result['errors'])) {
            wp_send_json_error($result + ['message' => $result['errors'][0]], 400);
        }
        if ($result) {
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
        $form = P::class2posttype($class);
        $confirmation = Session::show('dashi_public_form_confirmed', self::formSessionKey($form));
        $journal = !empty($confirmation['id']) ? PublicFormState::read('submission_' . $confirmation['id']) : [];
        $postId = is_int($journal['post'] ?? null) ? $journal['post'] : 0;
        $vals = self::normalizeFileValues($class, $form, $vals, $postId);
		$html.= '<dl class="dashi_public_form_confirm">';
		foreach (static::getFieldsInDisplayOrder($class::get('custom_fields')) as $k => $v)
		{
			if (isset($v['private_form_only']) && $v['private_form_only'] == true) continue;

			if (isset($v['options'])) {
				$v['options'] = \Dashi\Core\Util::resolveOptions($v['options']);
			}

			$html.= '<dt>'.esc_html($v['label'] ?? $k).'</dt>';
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
							$html.= join(', ', array_map('esc_html', $val_strs));
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
							if (!is_wp_error($options)) $html .= join(', ', array_map('esc_html', $options));
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
						$html.= esc_html($v['options'][$vals->$k]);
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
						if (!is_wp_error($options)) $html .= join(', ', array_map('esc_html', $options));
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
		$html.= '<form id="dashi_public_form_ctrls" method="POST" action="'.esc_url(get_permalink($post->ID)).'">';
		$html.= '<input type="hidden" name="dashi_public_form_do_final" value="1" />';
        $confirmation = Session::show('dashi_public_form_confirmed', self::formSessionKey(P::class2posttype($class)));
        if (is_array($confirmation)) {
            $html .= '<input type="hidden" name="dashi_confirmation_id" value="' . esc_attr($confirmation['id']) . '" />';
        }

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
            if (!empty($field['private_form_only'])) continue;
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
	private static function finalize ($class, $vals, $journalKey)
	{
		global $post;
		$post_type = \Dashi\Core\Posttype\Posttype::class2posttype($class);
        $journal = PublicFormState::read($journalKey);
        if ($journal === null) return self::retrySubmission($class, $vals);
        $post_id = $journal['post'] ?? false;
        $success = false;

		// 投稿内容を登録する
		$allow_post_by_public_form = $class::get('allow_post_by_public_form');
        if ($post_id === 'started') return self::uncertainSubmission();
        if (!PublicFormState::canFinalizeSubmission()) {
            return self::retrySubmission($class, $vals);
        }
        if ($allow_post_by_public_form && !$post_id) {
            $journal['post'] = 'started';
            if (!self::saveSubmission($journalKey, $journal)) return self::retrySubmission($class, $vals);
            $post_id = self::insertPost($class, $vals);
            if ($post_id === null || !PublicFormState::canFinalizeSubmission()) return self::uncertainSubmission();
            $journal['post'] = $post_id;
            if (!self::saveSubmission($journalKey, $journal)) return self::uncertainSubmission();
            if (!$post_id) return self::retrySubmission($class, $vals);
        }

		// メール送信がある？
		$sendto = $class::get('sendto');
		if ($sendto)
		{
            $success = static::sendmail($class, $vals, $post_id, $journalKey);
            if ($success === null) return self::retrySubmission($class, $vals);
		}
        if (!$success && $sendto) {
            $latest = PublicFormState::read($journalKey);
            if (self::hasUncertainAcceptanceMail($latest ?? [])) return self::uncertainSubmission();
        }
        if (!PublicFormState::canFinalizeSubmission()) return self::uncertainSubmission();
        if (!$post_id && !$success) return self::retrySubmission($class, $vals);

		// sessionをクリアする
		if (
			($sendto && $success) ||
			($allow_post_by_public_form && $post_id)
		)
		{
            // メールで案内した添付は、署名URLの配信に必要なため期限まで保持する。
            $mailReceipts = [];
            if ($sendto) {
                foreach ($class::getFlatCustomFields() as $field => $attrs) {
                    if (($attrs['type'] ?? '') === 'file' && !empty($vals->$field['upload_id'])
                        && ($attrs['public_form_allow_send_by_mail'] ?? true) !== false) {
                        $mailReceipts[] = $vals->$field['upload_id'];
                    }
                }
            }
            PublicUploadStore::discardForm($post_type, true, $mailReceipts);
            Session::remove('dashi_public_form_confirmed', self::formSessionKey($post_type));
            Session::remove('dashi_public_form', self::formSessionKey($post_type));
		}

		// 終了画面
		$message = $class::get('public_form_final_message');
		$html = '';
		$html.= apply_filters('dashi_public_form_final_message', $message, $post_type);
		$html.= '<div id="dashi_public_form_final">';
		$html.= '<a href="'.esc_url(get_permalink($post->ID)).'" class="dashi_button">'.esc_html__('Back', 'dashi').'</a>';
		$html.= '</div>';

		return $html;
	}

    private static function releaseSubmission($key, $requireWorker = false)
    {
        $lock = PublicFormState::lock($key);
        if (!$lock) return;
        try {
            $state = PublicFormState::read($key);
            if ($state === null) return;
            unset($state['processing']);
            if ($state === []) {
                PublicFormState::deleteEmptySubmission($key, $requireWorker);
            } else {
                self::saveSubmission($key, $state, $requireWorker);
            }
        } finally {
            PublicFormState::unlock($lock);
        }
    }

    private static function saveSubmission($key, array $state, $requireWorker = false)
    {
        $requireWorker = $requireWorker || ($state['processing'] ?? null) === 'locked';
        return PublicFormState::writeSubmission($key, $state, time() + DAY_IN_SECONDS, $requireWorker);
    }

    private static function uncertainSubmission()
    {
        return '<p class="dashi_public_form_error" role="alert">'
            . esc_html__('The submission result could not be verified. Please contact the site administrator before submitting again.', 'dashi')
            . '</p>';
    }

    private static function retrySubmission($class, $vals)
    {
        return '<p class="dashi_public_form_error" role="alert">'
            . esc_html__('The submission could not be completed. Please try again.', 'dashi')
            . '</p>' . self::confirm($class, $vals);
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
        $persisted = array_intersect_key((array) $vals, array_filter($fields, static function ($attrs) {
            return ($attrs['store_data'] ?? true) != false && empty($attrs['private_form_only']);
        }));
        $uploads = [];
        foreach ($fields as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'file' || !empty($attrs['private_form_only'])) continue;
            $value = $persisted[$field] ?? null;
            if ($value === null || $value === '' || $value === []) continue;
            $upload = PublicUploadStore::resolve($form, $field, $value);
            if (!$upload) return false;
            $uploads[$field] = $upload;
        }

        $title = $persisted[$class::get('public_form_post_title_field')] ?? '';
        $content = $persisted[$class::get('public_form_post_content_field')] ?? '';
        $new->post_title = is_scalar($title) ? (string) $title : '';
        // 投稿表示時も、送信者が入力したタグやショートコードは文章として扱う。
        $new->post_content = is_scalar($content)
            ? strtr(esc_html((string) $content), ['[' => '&#91;', ']' => '&#93;'])
            : '';
        if ($new->post_title === '' && $new->post_content === '') {
            $new->post_title = __('Public form submission', 'dashi');
        }

		$new->post_status = 'pending';
		$new->post_type = $form;

		$post_id = wp_insert_post(wp_slash((array) $new));

        if (!$post_id) return false;

        foreach ($persisted as $field => $value) {
            if (($fields[$field]['type'] ?? '') === 'taxonomy') {
                $terms = self::existingTermIds($field, $value);
                if ($terms === null || !is_array(wp_set_post_terms($post_id, $terms, $field))) {
                    return wp_delete_post($post_id, true) ? false : null;
                }
            }
        }

        $save_values = $persisted;
        foreach ($uploads as $field => $upload) {
            $save_values[$field] = ($upload['storage'] ?? '') === 'db'
                ? add_query_arg(['dashi_attachment' => '1', 'path' => rawurlencode($upload['name'])], home_url('/'))
                : self::uploadUrl() . $upload['name'];
        }
        if (!Save::_updateCustomFields($post_id, $save_values, true)) {
            return wp_delete_post($post_id, true) ? false : null;
        }
        $expectedMeta = ['_dashi_pubic_form_pending' => ['1']];
        update_post_meta($post_id, '_dashi_pubic_form_pending', '1');
        foreach ($uploads as $field => $upload) {
            $expectedMeta[$field] = [$save_values[$field]];
            update_post_meta($post_id, $field, $save_values[$field]);
        }
        // 添付一覧と一緒にDB上の投稿メタを確認してから所有権を移す。
        if (!PublicUploadStore::claim($post_id, $uploads, $expectedMeta)) {
            if (PublicUploadBlob::hasForPost($post_id)) {
                // DBエラーで所有権を戻せなかった場合、投稿削除に伴う添付の削除を避ける。
                update_post_meta($post_id, '_dashi_public_upload_error', 1);
                return null;
            }
            return wp_delete_post($post_id, true) ? false : null;
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
    private static function formRateRetry($stage)
    {
        // 接続元はWebサーバーが提供する値から取得する。転送ヘッダーは使わない。
        $ip = filter_var(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP);
        $address = $ip === false ? false : inet_pton($ip);
        // IPv4-mapped IPv6はIPv4として数える。
        if (is_string($address) && substr($address, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $address = substr($address, 12);
        }
        $prefix = 'dashi_' . $stage . '_rate_';
        $key = $prefix . md5($address === false ? 'unknown' : inet_ntop($address));
        if ($address === false || strlen($address) !== 16) {
            return self::rateRetry($key, 10, MINUTE_IN_SECONDS) ?? 3;
        }

        $networkKey = $prefix . 'v6_' . bin2hex(substr($address, 0, 8));
        $lock = PublicFormState::lock($networkKey);
        if (!$lock) return 3;
        try {
            $now = time();
            $states = [];
            // ネットワーク枠を先に確認し、両方を通過した場合だけ一括で更新する。
            foreach ([$networkKey => 60, $key => 10] as $rateKey => $limit) {
                $state = self::currentRateWindow($rateKey, $now, MINUTE_IN_SECONDS);
                if ($state === null) return 3;
                if ($state['count'] >= $limit) return $state['end'] - $now;
                ++$state['count'];
                $states[$rateKey] = $state;
            }
            return PublicFormState::writeIPv6Rates($networkKey, $states[$networkKey], $key, $states[$key]) ? 0 : 3;
        } finally {
            PublicFormState::unlock($lock);
        }
    }

    private static function currentRateWindow($key, $now, $window)
    {
        $state = PublicFormState::read($key);
        if ($state === null) return null;
        return ($state['end'] ?? 0) <= $now ? ['count' => 0, 'end' => $now + $window] : $state;
    }

    private static function rateRetry($key, $limit, $window, &$capacityFull = null)
    {
        $capacityFull = false;
        $lock = PublicFormState::lock($key);
        if (!$lock) return null;
        try {
            $now = time();
            $state = self::currentRateWindow($key, $now, $window);
            if ($state === null) return null;
            $remaining = $state['end'] - $now;
            if ($state['count'] >= $limit) return $remaining;
            ++$state['count'];
            return PublicFormState::write($key, $state, $state['end'], $capacityFull) ? 0 : null;
        } finally {
            PublicFormState::unlock($lock);
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

    private static function autoReplyAddress($value)
    {
        if (!is_string($value) || preg_match('/[\r\n,;]/', $value)) return '';
        return is_email(trim($value)) ?: '';
    }

	private static function adminMailHeaders($class, $client_mail)
	{
        $from = self::normalizeMailAddress($class::get('public_form_admin_mail_from'))
            ?: self::normalizeMailAddress(get_option('admin_email'));
        $client = self::autoReplyAddress($client_mail);
        return ($from !== '' ? 'From: ' . $from : '')
            . ($client !== '' ? ($from !== '' ? "\r\n" : '') . 'Reply-To: ' . $client : '');
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

    private static function hasUncertainAcceptanceMail(array $state)
    {
        $mail = $state['mail'] ?? [];
        return ($mail['hook'] ?? null) === 'started' || ($mail['admin'] ?? null) === 'started';
    }

	/**
	 * sendmail
	 *
	 * @param string $class
	 * @param object $vals
	 * @param int $post_id
	 * @return  string
	 */
    public static function sendmail($class, $vals, $post_id, $journalKey = null)
    {
        $postType = P::class2posttype($class);
        static::precheckSendmail($class);
        $client = $vals->{$class::get('auto_reply_field')} ?? '';
        $state = $journalKey === null ? [] : PublicFormState::read($journalKey);
        if ($state === null || self::hasUncertainAcceptanceMail($state)) return false;
        $beforeNativeSend = $journalKey === null ? null : static function () use (&$state, $journalKey) {
            return PublicFormState::canFinalizeSubmission()
                && (($state['processing'] ?? null) !== 'locked' || PublicFormState::ownsLock('worker_' . $journalKey));
        };
        $mark = static function ($step, $result) use (&$state, $journalKey, $beforeNativeSend) {
            if ($beforeNativeSend !== null && !$beforeNativeSend()) return false;
            $state['mail'][$step] = $result;
            return $journalKey === null || (self::saveSubmission($journalKey, $state) && $beforeNativeSend());
        };

        if (empty($state['mail']['hook'])) {
            if (!$mark('hook', 'started')) return false;
            apply_filters('dashi_public_form_mail_hook', $vals, $postType);
            if (!$mark('hook', true)) return false;
        }
        if (!get_option('dashi_public_form_done_sendmail')) return false;

        $adminSent = !empty($state['mail']['admin']);
        if (!$adminSent) {
            $mailAttachments = [];
            $message = self::body($class, $vals, true, (int) $post_id, $mailAttachments);
            $editUrl = self::publicFormEditPostUrl($post_id);
            if ($editUrl) $message = "\n\n================\n\n" . $editUrl . "\n\n================\n\n" . $message;
            $message = __('Inquiries from the website form.', 'dashi') . "\n\n" . $message;
            $message = apply_filters('dashi_public_form_admin_mail_body', $message, $postType);
            $headers = self::adminMailHeaders($class, $client);
            if (!$mark('admin', 'started')) return false;
            $adminSent = \Dashi\Core\Mail::send(
                $class::get('sendto'), $class::get('subject'), $message, $headers, '', $beforeNativeSend
            );
            if (!$mark('admin', $adminSent) || !$adminSent) return false;
            foreach ($mailAttachments as $name => $expires) {
                // リンク自体の有効期限は署名で検証する。保存期限の更新に失敗しても通知を再送しない。
                PublicUploadBlob::shortenUnsubmittedExpiry($name, $postType, $expires);
            }
        }

        $address = self::autoReplyAddress($client);
        if ($class::get('is_auto_reply') && $address !== '' && empty($state['mail']['reply'])) {
            self::sendAutoReply($class, $postType, $address, $mark, $beforeNativeSend);
        }
        return true;
    }

    private static function sendAutoReply($class, $postType, $address, callable $mark, ?callable $beforeNativeSend)
    {
        // 自動返信は受付完了後の付随処理。再送防止の記録は送信前に残す。
        try {
            $key = PublicFormState::REPLY_RATE_PREFIX . hash_hmac('sha256', strtolower($address), wp_salt('nonce'));
            $retry = self::rateRetry($key, 3, HOUR_IN_SECONDS, $replyCapacityFull);
            if ($replyCapacityFull || $retry !== 0) {
                $mark('reply', $replyCapacityFull ? 'capacity_full' : ($retry === null ? 'unavailable' : 'throttled'));
                return;
            }
            $from = self::normalizeMailAddress($class::get('replyto'))
                ?: self::normalizeMailAddress(get_option('admin_email'));
            $message = apply_filters('dashi_public_form_reply_mail_body', __('This mail is auto reply.', 'dashi'), $postType);
            if (!$mark('reply', 'started')) return;
            $sent = \Dashi\Core\Mail::send(
                $address, $class::get('re_subject'), $message, $from !== '' ? 'From: ' . $from : '', '', $beforeNativeSend
            );
            $mark('reply', $sent ? true : 'failed');
        } catch (\Throwable $error) {
            // 送信を始めていたら記録を残し、配信結果が不明なメールを再送しない。
        }
    }

	/**
	 * body
	 *
	 * @param string $class
	 * @param object $vals
	 * @param bool $is_admin
	 * @return  string
	 */
	public static function body($class, $vals, $is_admin = false, $post_id = 0, &$mailAttachments = null)
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
			if (isset($fields[$k]['public_form_allow_send_by_mail']) && $fields[$k]['public_form_allow_send_by_mail'] === false) continue;

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
                        $signed_upload_url = '';
                        if ($upload && !empty($upload['post_id'])) {
                            $signed_upload_url = self::signedUploadUrl($upload['name'], null, $upload['post_id']);
                        } elseif ($upload && ($upload['storage'] ?? '') === 'db') {
                            $row = PublicUploadBlob::get($upload['name']);
                            if ($row && !(int) $row->post_id) {
                                $expires = min((int) $row->expires, self::uploadUrlExpiry());
                                $signed_upload_url = self::signedUploadUrl($upload['name'], null, 0, true, $expires);
                                if (is_array($mailAttachments)) $mailAttachments[$upload['name']] = $expires;
                            }
                        }
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
            $body .= Csv::serializeRow(array_map([Csv::class, 'sanitizeCsvCell'], $csv), "\t");
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

    public static function pendingToPublish($post)
    {
        if ($post->post_status === 'publish') PublicUploadStore::removeDetached($post);
    }

    public static function afterPostSaved($post_id, $post)
    {
        if ($post->post_type !== 'revision') PublicUploadStore::removeDetached($post);
    }

    public static function pendingAttachmentUrl($post, $field, $value)
    {
        if (!($post instanceof \WP_Post) || !current_user_can('edit_post', $post->ID)) return '';
        $filename = self::extractUploadedFilename($value);
        $upload = PublicUploadStore::forPost($post->ID, $post->post_type, $field, $filename);
        return $upload ? self::signedUploadUrl($filename, null, $post->ID) : '';
    }

    public static function canDownloadPublicAttachment($post, $filename, $beforeQuery = false)
    {
        if (!$post || $post->post_status !== 'publish' || $post->post_password !== ''
            || !is_post_type_viewable($post->post_type) || has_filter('dashi_search_can_show_result')
            || has_filter('post_password_required')) return false;
        $class = P::posttype2class($post->post_type);
        if (!$class || !$class::get('public')) return false;

        foreach ($class::getFlatCustomFields() as $field => $attrs) {
            if (($attrs['type'] ?? '') !== 'file' || !empty($attrs['private_form_only'])
                || ($attrs['store_data'] ?? true) == false
                || ($attrs['public_form_publish_attachment'] ?? false) !== true) continue;
            $upload = PublicUploadStore::forPost($post->ID, $post->post_type, $field, $filename);
            if (($upload['storage'] ?? '') !== 'db') continue;
            $mime = wp_check_filetype($filename)['type'];
            if ($class::get('public_form_remove_exif') && strpos($mime ?: '', 'image/') === 0
                && empty($upload['metadata_removed'])) continue;
            if (self::filterAttachmentPublication($post, $field, $beforeQuery)) return true;
        }
        return false;
    }

    private static function filterAttachmentPublication($post, $field, $beforeQuery)
    {
        if (!$beforeQuery) return apply_filters('dashi_public_form_can_publish_attachment', true, $post, $field);

        return \Dashi\Core\Util::withSingularPostQuery($post, static function () use ($post, $field) {
            return apply_filters('dashi_public_form_can_publish_attachment', true, $post, $field);
        });
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

}
