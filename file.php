<?php
// WordPress のルートパスを指定して読み込む
require_once dirname(__FILE__, 4) . '/wp-load.php';

// アップロードディレクトリのパスを取得（WordPressの /uploads/dashi_uploads/ 配下に限定）
$upload_dir = wp_upload_dir();
$base_dir = trailingslashit($upload_dir['basedir']) . 'dashi_uploads/';

// ファイル名を取得（basename でパストラバーサル防止）
$filename = basename($_GET['path'] ?? '');

// 許可拡張子をチェック
$allowed_ext = ['jpg', 'jpeg', 'pdf'];
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_ext, true)) {
    status_header(403);
    exit('Forbidden: Invalid file type.');
}

// 絶対パスを組み立てて検証
$filepath = realpath($base_dir . $filename);
if (
    $filepath === false || // ファイルが存在しない
    strpos($filepath, realpath($base_dir)) !== 0 || // アップロードディレクトリ外を指している
    !file_exists($filepath)
) {
    status_header(404);
    exit('File not found.');
}

// 適切な Content-Type を送信
$content_types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'pdf' => 'application/pdf',
];
header('Content-Type: ' . $content_types[$ext]);
header('Content-Length: ' . filesize($filepath));

// ファイル出力
readfile($filepath);
exit;
