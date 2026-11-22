<?php

namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- 仮添付の専用テーブルを使い、容量・有効期限・引き継ぎはDBの状態を直接確認する。
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL文に埋め込むのはWordPressが検証済みのテーブル接頭辞と固定の接尾辞のみ。値はprepareで渡す。
/** 公開フォームの仮添付本体。セッションと投稿メタにはファイル本体を入れない。 */
class PublicUploadBlob
{
    public const MAX_FILE_BYTES = 1048576;
    private const MAX_FORM_BYTES = 4194304;
    private const MAX_SITE_BYTES = 134217728;
    private const MAX_TEMP_BYTES = 16777216;
    private const MAX_PENDING_BYTES = 50331648;
    private const AWAITING_REVIEW_SQL = "COALESCE(p.post_status, '') NOT IN ('publish', 'private', 'trash')";

    public static function usage()
    {
        if (!self::available()) return null;
        global $wpdb;
        $table = self::table();
        $approved = "p.post_status IN ('publish', 'private')";
        $awaitingReview = self::AWAITING_REVIEW_SQL;
        return $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS files, COALESCE(SUM(a.bytes), 0) AS bytes,
            COALESCE(SUM(CASE WHEN a.post_id = 0 THEN a.bytes END), 0) AS temporary,
            COALESCE(SUM(CASE WHEN a.post_id > 0 AND {$awaitingReview} THEN a.bytes END), 0) AS pending,
            COALESCE(SUM(CASE WHEN {$approved} THEN a.bytes END), 0) AS retained,
            COUNT(CASE WHEN a.post_id = 0 THEN 1 END) AS temporary_files,
            COUNT(CASE WHEN a.post_id > 0 AND {$awaitingReview} THEN 1 END) AS pending_files
            FROM {$table} a LEFT JOIN {$wpdb->posts} p ON p.ID = a.post_id
            WHERE a.post_id > 0 OR a.expires > %d", time()));
    }

    /** レビュー待ちの添付から、担当者が確認する投稿を取得する。 */
    public static function oldestPendingPosts()
    {
        if (!self::available()) return [];
        global $wpdb;
        $table = self::table();
        $awaitingReview = self::AWAITING_REVIEW_SQL;
        return $wpdb->get_col("SELECT a.post_id FROM {$table} a
            LEFT JOIN {$wpdb->posts} p ON p.ID = a.post_id
            WHERE a.post_id > 0 AND {$awaitingReview}
            GROUP BY a.post_id ORDER BY MIN(a.expires) LIMIT 10");
    }

    private const SCHEMA_OPTION = 'dashi_public_upload_blob_schema';

    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'dashi_public_uploads';
    }

    private static function available()
    {
        return (int) get_option(self::SCHEMA_OPTION) === 1;
    }

    private static function install()
    {
        global $wpdb;
        $table = self::table();
        $collation = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            name varchar(80) NOT NULL,
            owner char(64) NOT NULL,
            form varchar(20) NOT NULL,
            mime varchar(100) NOT NULL,
            bytes int unsigned NOT NULL,
            expires bigint unsigned NOT NULL,
            post_id bigint unsigned NOT NULL DEFAULT 0,
            content mediumblob NOT NULL,
            PRIMARY KEY  (name),
            KEY expiry (post_id,expires),
            KEY owner_form (owner,form,post_id)
        ) ENGINE=InnoDB {$collation};");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false;
        update_option(self::SCHEMA_OPTION, 1, false);
        return true;
    }

    public static function canAccept($form, $bytes)
    {
        if (!self::available()) return true;
        if (!Session::isStarted()) return false;
        global $wpdb;
        $table = self::table();
        $site = self::usage();
        $usage = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes FROM {$table}
             WHERE owner = %s AND form = %s AND post_id = 0 AND expires > %d",
            hash_hmac('sha256', session_id(), wp_salt('auth')), $form, time()
        ));
        return $site && $usage && $site->files < 4096 && $usage->files < 16
            && $site->temporary_files < 1024 && $site->temporary + $bytes <= self::MAX_TEMP_BYTES
            && $site->bytes + $bytes <= self::MAX_SITE_BYTES
            && $site->pending + $site->temporary + $bytes <= self::MAX_PENDING_BYTES
            && $site->pending_files + $site->temporary_files < 3072
            && $usage->bytes + $bytes <= self::MAX_FORM_BYTES;
    }

    public static function put($name, $form, $mime, $content, $expires, ?callable $beforeWrite = null)
    {
        global $wpdb;

        Session::forge();
        $bytes = strlen($content);
        if (!Session::isStarted() || !$bytes || $bytes > self::MAX_FILE_BYTES) return false;
        $owner = hash_hmac('sha256', session_id(), wp_salt('auth'));
        $table = self::table();

        // 同時にアップロードされても、サイト容量の確認と書き込みを直列化する。
        $lock = PublicFormState::lock('upload_capacity');
        if (!$lock) return false;
        try {
            if (!self::available() && !self::install()) return false;

            self::collect();
            if (!self::canAccept($form, $bytes)) return false;
            if ($beforeWrite !== null && !$beforeWrite()) return false;

            // バイナリーをSQL接続の文字コード変換に通さない。
            // 再接続でSQLが再実行されても、容量確認時のロックがなければ保存しない。
            return 1 === $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (name,owner,form,mime,bytes,expires,content)
                 SELECT %s,%s,%s,%s,%d,%d,UNHEX(%s) FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()",
                $name, $owner, $form, $mime, $bytes, $expires, bin2hex($content), $lock
            ));
        } finally {
            PublicFormState::unlock($lock);
        }
    }

    public static function get($name, $with_content = false)
    {
        if (!self::available()) return null;
        global $wpdb;
        $table = self::table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT mime, bytes, post_id, expires, IF(%d, content, NULL) AS content
             FROM {$table} WHERE name = %s AND (post_id > 0 OR expires > %d)",
            (int) $with_content,
            $name,
            time()
        ));
    }

    public static function belongsToCurrentSession($name)
    {
        if (!self::available() || !Session::resume()) return false;
        global $wpdb;
        $table = self::table();
        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT owner FROM {$table} WHERE name = %s AND post_id = 0 AND expires > %d",
            $name, time()
        ));
        return is_string($owner) && hash_equals($owner, hash_hmac('sha256', session_id(), wp_salt('auth')));
    }

    public static function claim(array $names, $post_id, array $expectedMeta = [])
    {
        if (!$names) return true;
        if (!self::available() || !Session::isStarted()) return false;
        global $wpdb;
        $table = self::table();
        $engines = $wpdb->get_col($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s, %s)',
            $table,
            $wpdb->posts,
            $wpdb->postmeta
        ));
        if (count($engines) !== 3 || array_diff($engines, ['InnoDB'])) return false;

        $lock = PublicFormState::lock('upload_capacity');
        if (!$lock) return false;
        try {
            return self::claimInTransaction($names, $post_id, $expectedMeta, $lock);
        } finally {
            PublicFormState::unlock($lock);
        }
    }

    private static function claimInTransaction(array $names, $postId, array $expectedMeta, $lock)
    {
        global $wpdb;
        $table = self::table();
        if (!PublicFormState::canFinalizeSubmission()) return false;

        // 再接続したセッションにはこの識別子も取得済みの名前付きロックもない。
        $token = wp_generate_uuid4();
        if ($wpdb->query($wpdb->prepare('SET @dashi_upload_claim_token = %s', $token)) === false
            || $wpdb->query('START TRANSACTION') === false) return false;

        $saved = false;
        try {
            $postType = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d AND post_status = 'pending' FOR UPDATE",
                $postId
            ));
            if (!$postType
                || ($expectedMeta && !Save::verifyStoredPostMeta($postId, $expectedMeta, true))) return false;

            $usage = self::usage();
            if (!$usage || $usage->pending_files + count($names) > 3072) return false;
            $owner = hash_hmac('sha256', session_id(), wp_salt('auth'));
            $placeholders = implode(', ', array_fill(0, count($names), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- 添付名の数に合わせてプレースホルダーを追加する。
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT bytes FROM {$table} WHERE owner = %s AND form = %s AND post_id = 0 AND expires > %d
                    AND name IN ($placeholders) FOR UPDATE",
                array_merge([$owner, $postType, time()], $names)
            ));
            if ($wpdb->last_error !== '' || count($rows) !== count($names)
                || $usage->pending + array_sum($rows) > self::MAX_PENDING_BYTES) return false;

            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- 添付名の数に合わせてプレースホルダーを追加する。
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET post_id = %d WHERE owner = %s AND form = %s AND post_id = 0 AND expires > %d
                    AND @dashi_upload_claim_token = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()
                    AND name IN ($placeholders)",
                array_merge([$postId, $owner, $postType, time(), $token, $lock], $names)
            ));
            if ($updated !== count($names)) return false;

            $saved = false !== $wpdb->query('COMMIT')
                && $wpdb->get_var('SELECT @dashi_upload_claim_token') === $token;
            return $saved;
        } finally {
            if (!$saved) $wpdb->query('ROLLBACK');
            $wpdb->query('SET @dashi_upload_claim_token = NULL');
        }
    }

    public static function release($post_id)
    {
        if (!self::available()) return;
        global $wpdb;
        $wpdb->update(self::table(), ['post_id' => 0], ['post_id' => (int) $post_id], ['%d'], ['%d']);
    }

    public static function shortenUnsubmittedExpiry($name, $form, $expires)
    {
        if (!self::available() || !Session::isStarted() || $expires < 1) return false;
        global $wpdb;
        $table = self::table();
        return false !== $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET expires = LEAST(expires, %d)
             WHERE name = %s AND owner = %s AND form = %s AND post_id = 0",
            $expires, $name, hash_hmac('sha256', session_id(), wp_salt('auth')), $form
        ));
    }

    public static function deleteUnsubmitted($name, $form)
    {
        if (!self::available() || !Session::isStarted()) return false;
        global $wpdb;
        return false !== $wpdb->delete(self::table(), [
            'name' => $name,
            'owner' => hash_hmac('sha256', session_id(), wp_salt('auth')),
            'form' => $form,
            'post_id' => 0,
        ], ['%s', '%s', '%s', '%d']);
    }

    public static function deleteForPost($post_id)
    {
        if (!self::available()) return;
        global $wpdb;
        $wpdb->delete(self::table(), ['post_id' => (int) $post_id], ['%d']);
    }

    public static function hasForPost($post_id)
    {
        if (!self::available()) return false;
        global $wpdb;
        $table = self::table();
        $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE post_id = %d LIMIT 1", $post_id));
        return $name !== null || $wpdb->last_error !== '';
    }

    public static function collect()
    {
        if (!self::available()) return;
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE post_id = 0 AND expires <= %d ORDER BY expires LIMIT 200",
            time()
        ));
        // 親投稿が存在しない添付を、期限経過後に少量ずつ回収する。
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE post_id > 0 AND expires <= %d
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = {$table}.post_id)
             ORDER BY expires LIMIT 200",
            time()
        ));
        self::collectDetached(time());
    }

    private static function collectDetached($now)
    {
        global $wpdb;
        $table = self::table();
        $cursorOption = 'dashi_upload_gc_cursor';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT name, post_id, form FROM {$table}
             WHERE name > %s AND post_id > 0 AND expires <= %d ORDER BY name LIMIT 100",
            (string) get_option($cursorOption, ''), $now
        ));
        if ($wpdb->last_error !== '' || !is_array($rows)) return;
        foreach ($rows as $row) {
            self::deleteDetached($row->name, $row->post_id, $row->form, $now);
        }
        // 保持対象が先頭を占めても、その先を次の回で確認する。
        update_option($cursorOption, count($rows) === 100 ? end($rows)->name : '', false);
    }

    /** 投稿保存と定期回収で、削除直前の参照確認を共有する。 */
    public static function deleteDetached($name, $postId, $form, $expiresBy = null)
    {
        if (!self::available()) return false;
        global $wpdb;
        $manifestKey = PublicUploadStore::POST_META;
        $manifests = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2",
            $postId, $manifestKey
        ));
        if ($wpdb->last_error !== '' || count($manifests) > 1) return false;
        if (!$manifests) {
            if ($expiresBy === null) return false;
            // DBに台帳がなくても、配信時にフィルターが補う台帳から参照できる場合がある。
            $post = get_post((int) $postId);
            if (!$post || PublicUploadStore::hasDownloadReference($post, $name, 'db')) return false;

            $table = self::table();
            return 1 === $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE name = %s AND post_id = %d AND expires <= %d
                 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = %d AND m.meta_key = %s)",
                $name, $postId, $expiresBy, $postId, $manifestKey
            ));
        }
        $manifest = $manifests[0];
        $uploads = maybe_unserialize($manifest->meta_value);
        if (!is_array($uploads)) return false;
        $fields = [];
        foreach ($uploads as $field => $upload) {
            if (!is_array($upload) || ($upload['name'] ?? '') !== $name) continue;
            if (($upload['storage'] ?? '') !== 'db' || !empty($upload['final_url'])
                || (int) ($upload['post_id'] ?? 0) !== (int) $postId
                || ($upload['form'] ?? '') !== $form || ($upload['field'] ?? '') !== $field) return false;
            $fields[] = $field;
        }
        if (count($fields) !== 1) return false;
        $field = $fields[0];
        $references = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2",
            $postId, $field
        ));
        if ($wpdb->last_error !== '' || count($references) > 1) return false;
        $reference = $references[0] ?? null;
        if ($reference && PublicUploadStore::matchesReference(maybe_unserialize($reference->meta_value), $name)) return false;

        // 配信時に WordPress のメタデータフィルターが補う参照も保持する。
        $post = get_post((int) $postId);
        if ($post && PublicUploadStore::hasDownloadReference($post, $name, 'db')) return false;

        // 台帳や入力欄が読み取り後に変わった場合は、削除せず次回に再判定する。
        $table = self::table();
        return 1 === $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE name = %s AND post_id = %d AND form = %s AND (%d = 0 OR expires <= %d)
             AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.meta_id = %d AND BINARY m.meta_value = %s)
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = %d AND m.meta_key = %s AND m.meta_id <> %d)
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = %d AND m.meta_key = %s
                 AND (m.meta_id <> %d OR NOT (BINARY m.meta_value <=> %s)))",
            $name, $postId, $form, $expiresBy ?? 0, $expiresBy ?? 0, $manifest->meta_id, $manifest->meta_value,
            $postId, $manifestKey, $manifest->meta_id,
            $postId, $field, $reference->meta_id ?? 0, $reference->meta_value ?? ''
        ));
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
