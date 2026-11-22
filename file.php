<?php
// WordPress のルートパスを指定して読み込む
if (!defined('ABSPATH'))
{
	require_once dirname(__FILE__, 4) . '/wp-load.php';
}
if (!defined('ABSPATH')) exit;

if (class_exists(\Dashi\Core\Security::class) && \Dashi\Core\Security::requiresHttps()) {
    nocache_headers();
    status_header(403);
    exit(esc_html__('HTTPS is required.', 'dashi'));
}

// ファイル名を取得（basename でパストラバーサル防止）
$dashi_raw_path = filter_input(INPUT_GET, 'path', FILTER_UNSAFE_RAW);
$dashi_raw_path = is_string($dashi_raw_path) ? wp_unslash($dashi_raw_path) : '';
$dashi_filename = sanitize_file_name(wp_basename(rawurldecode($dashi_raw_path)));

$dashi_exp = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT);
$dashi_exp = is_int($dashi_exp) ? $dashi_exp : 0;
$dashi_post_id = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$dashi_post_id = is_int($dashi_post_id) ? $dashi_post_id : 0;
$dashi_sig = filter_input(INPUT_GET, 'sig', FILTER_UNSAFE_RAW);
$dashi_sig = is_string($dashi_sig) ? strtolower(trim(wp_unslash($dashi_sig))) : '';

if ($dashi_filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $dashi_filename)) {
    status_header(403);
    exit('Forbidden: Invalid file path.');
}

nocache_headers();
header('Cache-Control: no-store, private');
$dashi_scope = filter_input(INPUT_GET, 'scope', FILTER_UNSAFE_RAW);
$dashi_mail = $dashi_scope === 'mail' && !$dashi_post_id;
if ($dashi_scope !== null && !$dashi_mail) {
    status_header(403);
    exit('Forbidden: Invalid attachment scope.');
}
$dashi_signed = $dashi_mail || filter_has_var(INPUT_GET, 'sig') || filter_has_var(INPUT_GET, 'exp') || filter_has_var(INPUT_GET, 'post');
if ($dashi_signed) {
    $dashi_context = $dashi_post_id ? '|' . $dashi_post_id : ($dashi_mail ? '|mail' : '');
    $dashi_expected_sig = hash_hmac('sha256', $dashi_filename . '|' . $dashi_exp . $dashi_context, wp_salt('auth'));
    if ($dashi_exp < time() || !hash_equals($dashi_expected_sig, $dashi_sig)) {
        status_header(403);
        exit('Forbidden: Invalid or expired signature.');
    }
}

if (!$dashi_signed && empty($dashi_routed_attachment)) {
    wp_safe_redirect(add_query_arg([
        'dashi_attachment' => '1', 'path' => rawurlencode($dashi_filename),
    ], home_url('/')));
    exit;
}

if (class_exists(\Dashi\Core\Posttype\PublicUploadBlob::class)) {
    $dashi_blob = \Dashi\Core\Posttype\PublicUploadBlob::get($dashi_filename);
    if ($dashi_blob) {
        $dashi_owner = (int) $dashi_blob->post_id;
        $dashi_parent = $dashi_owner ? get_post($dashi_owner) : null;
        if (!$dashi_signed) {
            // 親投稿のクエリーを閲覧制限が処理した後で、Dashi側の条件も照合する。
            $dashi_allowed = $dashi_parent
                && (int) get_queried_object_id() === $dashi_owner
                && \Dashi\Core\Posttype\PublicForm::canDownloadPublicAttachment($dashi_parent, $dashi_filename);
        } else {
            $dashi_allowed = !$dashi_mail && ($dashi_owner
                ? (!$dashi_post_id || $dashi_post_id === $dashi_owner)
                    && $dashi_parent && current_user_can('edit_post', $dashi_owner)
                    && \Dashi\Core\Posttype\PublicUploadStore::hasDownloadReference($dashi_parent, $dashi_filename, 'db')
                : !$dashi_post_id
                    && \Dashi\Core\Posttype\PublicUploadBlob::belongsToCurrentSession($dashi_filename));
            if ($dashi_mail) {
                $dashi_allowed = !$dashi_owner && current_user_can('manage_options');
            }
        }
        if ($dashi_allowed && $dashi_owner && $dashi_signed) {
            if (empty($dashi_routed_attachment)) {
                dashi_redirect_signed_attachment($dashi_filename, $dashi_exp, $dashi_sig, $dashi_post_id);
            }
            $dashi_allowed = (int) get_queried_object_id() === $dashi_owner;
        }
        if ($dashi_allowed) {
            $dashi_blob = \Dashi\Core\Posttype\PublicUploadBlob::get($dashi_filename, true);
            if ($dashi_blob && (int) $dashi_blob->post_id === $dashi_owner
                && dashi_recheck_attachment_access($dashi_filename, $dashi_owner, $dashi_signed, $dashi_mail, 'db')
                && (!$dashi_signed || $dashi_exp >= time())) {
                $dashi_type = sanitize_mime_type($dashi_blob->mime) ?: 'application/octet-stream';
                dashi_send_temporary_attachment_headers($dashi_filename, $dashi_type, (int) $dashi_blob->bytes);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 添付のバイナリーをそのまま返す。
                echo $dashi_blob->content;
                exit;
            }
        }
        status_header(403);
        exit('Forbidden: Attachment access denied.');
    }
}

// 旧ファイルは投稿に対応する管理画面のリンクだけで配信する。
$dashi_parent = $dashi_post_id ? get_post($dashi_post_id) : null;
$dashi_legacy_allowed = $dashi_parent && current_user_can('edit_post', $dashi_post_id)
    && class_exists(\Dashi\Core\Posttype\PublicUploadStore::class)
    && \Dashi\Core\Posttype\PublicUploadStore::hasDownloadReference($dashi_parent, $dashi_filename, 'legacy');
if (!$dashi_legacy_allowed) {
    nocache_headers();
    status_header(403);
    exit('Forbidden: Attachment access denied.');
}

if (empty($dashi_routed_attachment)) {
    dashi_redirect_signed_attachment($dashi_filename, $dashi_exp, $dashi_sig, $dashi_post_id);
}
if ((int) get_queried_object_id() !== $dashi_post_id) {
    status_header(403);
    exit('Forbidden: Attachment access denied.');
}

function dashi_redirect_signed_attachment($name, $expires, $signature, $postId)
{
    $args = ['dashi_attachment' => '1', 'path' => rawurlencode($name), 'exp' => $expires, 'sig' => $signature];
    if ($postId) $args['post'] = $postId;
    wp_safe_redirect(add_query_arg($args, home_url('/')));
    exit;
}

// 公開フォームの既定許可拡張子と整合させる
$dashi_allowed_patterns = [
    'jpg|jpeg|jpe',
    'gif',
    'png',
    'mp4|m4v',
    'txt|asc|c|cc|h|srt',
    'csv',
    'tsv',
    'wav',
    'pdf',
    'zip',
    'doc',
    'pot|pps|ppt',
    'xla|xls|xlt|xlw',
    'docx',
    'xlsx',
    'pptx',
];
$dashi_ext = strtolower(pathinfo($dashi_filename, PATHINFO_EXTENSION));
$dashi_is_allowed = false;
foreach ($dashi_allowed_patterns as $dashi_pattern) {
    $dashi_re = '/^(?:' . str_replace('\\|', '|', preg_quote($dashi_pattern, '/')) . ')$/i';
    if (preg_match($dashi_re, $dashi_ext)) {
        $dashi_is_allowed = true;
        break;
    }
}
if (!$dashi_is_allowed) {
    status_header(403);
    exit('Forbidden: Invalid file type.');
}

// 実体が旧アップロードディレクトリ直下にある通常ファイルだけを配信する。
$dashi_upload_dir = wp_upload_dir();
$dashi_base_dir = trailingslashit($dashi_upload_dir['basedir']) . 'dashi_uploads/';
$dashi_base_realpath = realpath($dashi_base_dir);
$dashi_candidate = $dashi_base_dir . $dashi_filename;
$dashi_filepath = realpath($dashi_candidate);
if ($dashi_base_realpath === false || $dashi_filepath === false || is_link($dashi_candidate)
    || dirname($dashi_filepath) !== $dashi_base_realpath || !is_file($dashi_filepath)) {
    status_header(404);
    exit('File not found.');
}

$dashi_wp_filetype = wp_check_filetype($dashi_filename);
$dashi_content_type = $dashi_wp_filetype['type'] ?: 'application/octet-stream';
if (!dashi_recheck_attachment_access($dashi_filename, $dashi_post_id, true, false, 'legacy') || $dashi_exp < time()) {
    status_header(403);
    exit('Forbidden: Attachment access denied.');
}
dashi_send_temporary_attachment_headers($dashi_filename, $dashi_content_type, filesize($dashi_filepath));
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- 旧添付をストリーム出力する。
readfile($dashi_filepath);
exit;

/** 本文取得中の変更も反映するため、親投稿と参照を送信直前に読み直す。 */
function dashi_recheck_attachment_access($name, $owner, $signed, $mail, $storage)
{
    if (!$owner) {
        if (!$mail) return $signed && \Dashi\Core\Posttype\PublicUploadBlob::belongsToCurrentSession($name);
        if (!current_user_can('manage_options')) return false;
        $blob = \Dashi\Core\Posttype\PublicUploadBlob::get($name);
        return $blob && (int) $blob->post_id === 0 && (int) $blob->expires > time();
    }

    if ((int) get_queried_object_id() !== $owner) return false;
    wp_cache_delete($owner, 'posts');
    wp_cache_delete($owner, 'post_meta');
    $parent = get_post($owner);
    if (!$parent) return false;

    if (!$signed) {
        return \Dashi\Core\Posttype\PublicForm::canDownloadPublicAttachment($parent, $name);
    }
    return !$mail && current_user_can('edit_post', $owner)
        && \Dashi\Core\Posttype\PublicUploadStore::hasDownloadReference($parent, $name, $storage);
}

/** 非公開の仮添付を共有キャッシュへ残さない。 */
function dashi_send_temporary_attachment_headers($filename, $mime, $size)
{
    nocache_headers();
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . $size);
}
