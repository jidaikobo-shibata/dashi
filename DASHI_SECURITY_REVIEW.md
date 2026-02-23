# Dashi Security Review

- 対象: `wordpress/wp-content/plugins/dashi`
- 実施方法: `Serena` による静的レビュー + ローカル動作確認
- 初回実施日: 2026-02-23
- 最終更新日: 2026-02-23

## Summary

- 対応済み: 高 1件
- 未対応: 中 3件 / 低 1件

## Remediation Update (2026-02-23)

### 対応済み: 公開 AJAX アップロードに nonce / レート制御がない（高）

- 実装内容:
  - 公開アップローダーに nonce を埋め込み
    - `wordpress/wp-content/plugins/dashi/classes/Posttype/PublicForm.php`
    - `wordpress/wp-content/plugins/dashi/assets/js/public_uploader.js`
  - サーバー側で nonce 検証を必須化
    - `wordpress/wp-content/plugins/dashi/classes/Posttype/PublicForm.php`
  - IP単位レート制限を追加（60秒で10回）
    - `wordpress/wp-content/plugins/dashi/classes/Posttype/PublicForm.php` (`enforceUploadRateLimit()`)
  - エラー時のレスポンスを `wp_send_json_error(..., 400/403/429)` に統一
    - `wordpress/wp-content/plugins/dashi/classes/Posttype/PublicForm.php`
  - クライアント側でサイズ超過を事前検知し、理由を表示
    - `wordpress/wp-content/plugins/dashi/assets/js/public_uploader.js`

- 動作確認:
  - 新規環境（`/home/shibata/Internal/dev/WordPress/Docker/base`）で `publicform` のアップロードが期待どおり動作。
  - nonce なしリクエストが拒否されることを確認。
  - レート制限の実装を確認（超過時 `429` を返す）。

### 関連改善（運用安定化）

- `exif` / `Imagick` 非存在時に `die()` しないよう修正（アップロード継続）
  - `wordpress/wp-content/plugins/dashi/classes/Posttype/PublicForm.php`
- 失敗時の原因を画面に出せるよう JS 側のエラーメッセージ処理を改善
  - `wordpress/wp-content/plugins/dashi/assets/js/public_uploader.js`
- `file.php` の拡張子許可をアップロード側定義と整合
  - `wordpress/wp-content/plugins/dashi/file.php`
  - `X-Content-Type-Options: nosniff`、非メディア系の `Content-Disposition: attachment` を追加
- `pending -> publish` 時に残っていたデバッグ `error_log` を停止
  - `wordpress/wp-content/plugins/dashi/classes/Posttype/Save.php`

## Findings (Open)

### 1. 一時アップロード掃除ロジックが未使用（中）

- 影響:
  - 一時ファイルが残留し続け、ディスク肥大化リスク。
- 根拠:
  - `garbageCollection()` が定義されているが呼び出しが存在しない  
    `wordpress/wp-content/plugins/dashi/classes/Posttype/PublicForm.php:42`
- 推奨対応:
  - Cron などで定期実行するフックを追加。
  - 保存期限（TTL）と最大容量を設定。

### 2. `file.php` 経由の配信が認可なし（中）

- 影響:
  - ファイル名が推測/流出した場合、認可なしで取得される。
  - `dashi_uploads` の保護を `file.php` がバイパスする設計。
- 根拠:
  - 受信パラメータ `path` をもとに `readfile()`  
    `wordpress/wp-content/plugins/dashi/file.php`
  - 認可判定（ログイン/権限/署名URL）がない
- 推奨対応:
  - 署名付きURL（期限付き）へ変更。
  - 必要に応じてログイン/権限チェックを追加。
  - 公開不要ファイルは WordPress 添付URLへ移管して `file.php` を段階的廃止。

### 3. `custom_referencer` AJAX に nonce / capability 明示チェックなし（中）

- 影響:
  - ログイン中ユーザー経由の CSRF で不要クエリが実行される可能性。
  - 将来の機能追加時に権限境界が曖昧なまま拡張されるリスク。
- 根拠:
  - AJAX 登録: `wp_ajax_custom_referencer`  
    `wordpress/wp-content/plugins/dashi/classes/Posttype/Posttype.php:304`
  - ハンドラ内に `check_ajax_referer` / `current_user_can` がない  
    `wordpress/wp-content/plugins/dashi/classes/Posttype/CustomFields.php:899`
- 推奨対応:
  - `check_ajax_referer` を必須化。
  - `current_user_can('edit_posts')` 等で最低権限を明示。
  - `post_type` を許可リストで検証。

### 4. CSV エクスポート値の Spreadsheet Formula Injection 対策なし（低）

- 影響:
  - エクスポートCSVを表計算ソフトで開いた際、`=`, `+`, `-`, `@` から始まるセルが式として実行されるリスク。
- 根拠:
  - 投稿/カスタムフィールド値をそのまま `fputcsv()`  
    `wordpress/wp-content/plugins/dashi/classes/Posttype/Csv.php:145`
- 推奨対応:
  - 先頭が `=+-@` のセルは `'` プレフィックス付与などで無害化。

## Notes

- SQL 組み立て箇所（`Search`）は `prepare()` 利用が確認でき、今回レビューでは高優先の SQLi は未検出。
  - 参照: `wordpress/wp-content/plugins/dashi/classes/Posttype/Search.php:154`
- `file.php` は `basename` / `realpath` によるパストラバーサル対策自体は実装済み。
  - 参照: `wordpress/wp-content/plugins/dashi/file.php`

## Baseline Tag

- 高優先課題の改修前ベースラインとして、以下のタグを作成済み。
  - リポジトリ: `/home/shibata/Internal/dev/WordPress/plugins/dashi.github.src`
  - タグ名: `baseline-before-security-fix-2026-02-23`
