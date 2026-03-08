<?php
// WordPress のルートパスを指定して読み込む
if (!defined('ABSPATH'))
{
	require_once dirname(__FILE__, 4) . '/wp-load.php';
}
if (!defined('ABSPATH')) exit;

// アップロードディレクトリのパスを取得（WordPressの /uploads/dashi_uploads/ 配下に限定）
$dashi_upload_dir = wp_upload_dir();
$dashi_base_dir = trailingslashit($dashi_upload_dir['basedir']) . 'dashi_uploads/';
$dashi_base_realpath = realpath($dashi_base_dir);

// ファイル名を取得（basename でパストラバーサル防止）
$dashi_raw_path = filter_input(INPUT_GET, 'path', FILTER_UNSAFE_RAW);
$dashi_raw_path = is_string($dashi_raw_path) ? wp_unslash($dashi_raw_path) : '';
$dashi_filename = sanitize_file_name(wp_basename(rawurldecode($dashi_raw_path)));

$dashi_exp = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT);
$dashi_exp = is_int($dashi_exp) ? $dashi_exp : 0;
$dashi_sig = filter_input(INPUT_GET, 'sig', FILTER_UNSAFE_RAW);
$dashi_sig = is_string($dashi_sig) ? strtolower(trim(wp_unslash($dashi_sig))) : '';

if ($dashi_filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $dashi_filename)) {
    status_header(403);
    exit('Forbidden: Invalid file path.');
}

if ($dashi_exp < time() || !preg_match('/^[a-f0-9]{64}$/', $dashi_sig)) {
    status_header(403);
    exit('Forbidden: Link expired.');
}

$dashi_expected_sig = hash_hmac('sha256', $dashi_filename . '|' . $dashi_exp, wp_salt('auth'));
if (!hash_equals($dashi_expected_sig, $dashi_sig)) {
    status_header(403);
    exit('Forbidden: Invalid signature.');
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

// 絶対パスを組み立てて検証
$dashi_filepath = realpath($dashi_base_dir . $dashi_filename);
if (
    $dashi_base_realpath === false ||
    $dashi_filepath === false || // ファイルが存在しない
    strpos($dashi_filepath, $dashi_base_realpath) !== 0 || // アップロードディレクトリ外を指している
    !file_exists($dashi_filepath)
) {
    status_header(404);
    exit('File not found.');
}

// 適切な Content-Type を送信
$dashi_wp_filetype = wp_check_filetype($dashi_filename);
$dashi_content_type = !empty($dashi_wp_filetype['type']) ? $dashi_wp_filetype['type'] : 'application/octet-stream';
header('Content-Type: ' . $dashi_content_type);
header('X-Content-Type-Options: nosniff');
if (!preg_match('/^(image\/|video\/|audio\/|application\/pdf$)/i', $dashi_content_type)) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($dashi_filename) . '"');
}
header('Content-Length: ' . filesize($dashi_filepath));

// ファイル出力
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- ダウンロード応答としてストリーム出力する。
readfile($dashi_filepath);
exit;
