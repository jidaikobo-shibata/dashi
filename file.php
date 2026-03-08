<?php
// WordPress のルートパスを指定して読み込む
if (!defined('ABSPATH'))
{
	require_once dirname(__FILE__, 4) . '/wp-load.php';
}
if (!defined('ABSPATH')) exit;

// アップロードディレクトリのパスを取得（WordPressの /uploads/dashi_uploads/ 配下に限定）
$upload_dir = wp_upload_dir();
$base_dir = trailingslashit($upload_dir['basedir']) . 'dashi_uploads/';
$base_realpath = realpath($base_dir);

// ファイル名を取得（basename でパストラバーサル防止）
$raw_path = filter_input(INPUT_GET, 'path', FILTER_UNSAFE_RAW);
$raw_path = is_string($raw_path) ? wp_unslash($raw_path) : '';
$filename = sanitize_file_name(wp_basename(rawurldecode($raw_path)));

$exp = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT);
$exp = is_int($exp) ? $exp : 0;
$sig = filter_input(INPUT_GET, 'sig', FILTER_UNSAFE_RAW);
$sig = is_string($sig) ? strtolower(trim(wp_unslash($sig))) : '';

if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    status_header(403);
    exit('Forbidden: Invalid file path.');
}

if ($exp < time() || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
    status_header(403);
    exit('Forbidden: Link expired.');
}

$expected_sig = hash_hmac('sha256', $filename . '|' . $exp, wp_salt('auth'));
if (!hash_equals($expected_sig, $sig)) {
    status_header(403);
    exit('Forbidden: Invalid signature.');
}

// 公開フォームの既定許可拡張子と整合させる
$allowed_patterns = [
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
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$is_allowed = false;
foreach ($allowed_patterns as $pattern) {
    $re = '/^(?:' . str_replace('\\|', '|', preg_quote($pattern, '/')) . ')$/i';
    if (preg_match($re, $ext)) {
        $is_allowed = true;
        break;
    }
}
if (!$is_allowed) {
    status_header(403);
    exit('Forbidden: Invalid file type.');
}

// 絶対パスを組み立てて検証
$filepath = realpath($base_dir . $filename);
if (
    $base_realpath === false ||
    $filepath === false || // ファイルが存在しない
    strpos($filepath, $base_realpath) !== 0 || // アップロードディレクトリ外を指している
    !file_exists($filepath)
) {
    status_header(404);
    exit('File not found.');
}

// 適切な Content-Type を送信
$wp_filetype = wp_check_filetype($filename);
$content_type = !empty($wp_filetype['type']) ? $wp_filetype['type'] : 'application/octet-stream';
header('Content-Type: ' . $content_type);
header('X-Content-Type-Options: nosniff');
if (!preg_match('/^(image\/|video\/|audio\/|application\/pdf$)/i', $content_type)) {
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
}
header('Content-Length: ' . filesize($filepath));

// ファイル出力
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- ダウンロード応答としてストリーム出力する。
readfile($filepath);
exit;
