<?php

namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

/** セッションや外部キャッシュから独立した短期の受付記録。 */
final class PublicFormState
{
    private const LEGACY_PREFIX = 'dashi_form_state_';
    private const MAX_ROWS = 16384;
    private const MAX_RATE_ROWS = 8192;
    private const MAX_REPLY_RATE_ROWS = 2048;
    public const REPLY_RATE_PREFIX = 'dashi_reply_rate_';
    private static $locks = [];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- テーブル名はWordPressの接頭辞と固定名から構成し、値はprepareで渡す。
    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'dashi_form_state';
    }

    private static function install()
    {
        global $wpdb;
        $table = self::table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            $collation = $wpdb->get_charset_collate();
            if (false === $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
                state_key varchar(100) NOT NULL,
                expires bigint unsigned NOT NULL,
                payload text NOT NULL,
                PRIMARY KEY (state_key),
                KEY expires (expires)
            ) {$collation}")) return false;
        }
        update_option('dashi_form_state_schema', 1, false);
        return true;
    }

    public static function read($key)
    {
        global $wpdb;
        if (get_option('dashi_form_state_schema')) {
            $table = self::table();
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT expires, payload FROM {$table} WHERE state_key = %s", $key
            ));
            if ($wpdb->last_error !== '') return null;
            if ($row) return (int) $row->expires > time() ? json_decode($row->payload, true) : [];
        }

        // 更新直後の再送でも、旧版の完了記録を参照できるようにする。
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LEGACY_PREFIX . $key
        ));
        if ($wpdb->last_error !== '') return null;
        if (!is_string($value)) return [];
        $parts = explode(':', $value, 2);
        return count($parts) === 2 && (int) $parts[0] > time() ? json_decode($parts[1], true) : [];
    }

    public static function write($key, array $data, $expires, &$capacityFull = null)
    {
        return self::writeRecords([$key => [$expires, $data]], $capacityFull);
    }

    public static function writeSubmission($key, array $data, $expires, $requireWorker)
    {
        return self::writeRecords([$key => [$expires, $data]], $capacityFull, $requireWorker ? self::lockName('worker_' . $key) : null);
    }

    public static function writeIPv6Rates($networkKey, array $network, $addressKey, array $address)
    {
        return self::writeRecords([
            $networkKey => [$network['end'], $network],
            $addressKey => [$address['end'], $address],
        ], $capacityFull);
    }

    private static function writeRecords(array $records, &$capacityFull, $workerLock = null)
    {
        global $wpdb;
        $capacityFull = false;
        $lock = self::lock('state_capacity');
        if (!$lock) return false;
        try {
            if (!get_option('dashi_form_state_schema') && !self::install()) return false;
            $table = self::table();
            $keys = array_keys($records);
            $key = $keys[0];
            $otherKey = $keys[1] ?? $key;
            $countExisting = static function () use ($wpdb, $table, $key, $otherKey) {
                return $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE state_key IN (%s, %s)", $key, $otherKey
                ));
            };
            $existing = $countExisting();
            if ($existing === null) return false;
            if ((int) $existing < count($keys)) {
                self::collectCurrent(time());
                // 回収処理が期限切れの対象キーを削除した場合は、その分も新規行として数える。
                $existing = $countExisting();
                if ($existing === null) return false;
                $new = count($keys) - (int) $existing;
                $reply = $wpdb->esc_like(self::REPLY_RATE_PREFIX) . '%';
                if (strpos($key, self::REPLY_RATE_PREFIX) === 0) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE state_key LIKE %s", $reply
                    ));
                    if ($count === null) return false;
                    if ((int) $count + $new > self::MAX_REPLY_RATE_ROWS) {
                        $capacityFull = true;
                        return false;
                    }
                } else {
                    $total = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE state_key NOT LIKE %s", $reply
                    ));
                    if ($total === null || (int) $total + $new > self::MAX_ROWS) return false;
                    if (strpos($key, 'submission_') !== 0) {
                        $rate = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$table} WHERE state_key NOT LIKE %s AND state_key NOT LIKE %s",
                            $wpdb->esc_like('submission_') . '%', $reply
                        ));
                        if ($rate === null || (int) $rate + $new > self::MAX_RATE_ROWS) return false;
                    }
                }
            }
            [$expires, $data] = $records[$key];
            $json = wp_json_encode($data);
            if ($json === false) return false;
            if ($workerLock !== null) {
                // DB が書き込みを再接続後に再試行しても、ロックを失った処理は更新できない。
                $updated = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} (state_key, expires, payload)
                     SELECT %s, %d, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
                     AND IS_USED_LOCK(%s) = CONNECTION_ID()
                     ON DUPLICATE KEY UPDATE expires = VALUES(expires), payload = VALUES(payload)",
                    $key, $expires, $json, $lock, $workerLock
                ));
                if ($updated === false) return false;
                if ($updated > 0) return true;
                // 値が変わらず更新件数が 0 の場合は、同じ値とロック所有を同時に確認する。
                return (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$table} WHERE state_key = %s AND expires = %d AND BINARY payload = %s
                     AND IS_USED_LOCK(%s) = CONNECTION_ID() AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                    $key, $expires, $json, $lock, $workerLock
                )) === 1;
            }
            // 接続が切り替わった場合は、容量の確認に使ったロックを失っている。
            if (count($keys) === 1) {
                $updated = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} (state_key, expires, payload)
                     SELECT %s, %d, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
                     ON DUPLICATE KEY UPDATE expires = VALUES(expires), payload = VALUES(payload)",
                    $key, $expires, $json, $lock
                ));
            } else {
                [$otherExpires, $otherData] = $records[$otherKey];
                $otherJson = wp_json_encode($otherData);
                if ($otherJson === false) return false;
                $updated = $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} (state_key, expires, payload)
                     SELECT %s, %d, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
                     UNION ALL
                     SELECT %s, %d, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
                     ON DUPLICATE KEY UPDATE expires = VALUES(expires), payload = VALUES(payload)",
                    $key, $expires, $json, $lock, $otherKey, $otherExpires, $otherJson, $lock
                ));
            }
            return $updated !== false && ($updated > 0 || self::ownsLock('state_capacity'));
        } finally {
            self::unlock($lock);
        }
    }

    public static function deleteEmptySubmission($key, $requireWorker = false)
    {
        if (strpos($key, 'submission_') !== 0 || !get_option('dashi_form_state_schema')) return;
        global $wpdb;
        // 状態のある行は削除しない。現行の送信では作業ロックも同じ SQL で確認する。
        $table = self::table();
        $workerLock = $requireWorker ? self::lockName('worker_' . $key) : '';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE state_key = %s AND payload IN (%s, %s, %s)
             AND (%s = '' OR IS_USED_LOCK(%s) = CONNECTION_ID())",
            $key, '{"processing":true}', '{"processing":"locked"}', '[]', $workerLock, $workerLock
        ));
    }

    public static function supportsMultipleLocks()
    {
        global $wpdb;
        return version_compare($wpdb->db_version(), '5.7.5', '>=')
            || (method_exists($wpdb, 'db_server_info') && stripos($wpdb->db_server_info(), 'MariaDB') !== false);
    }

    /** 送信結果を他の処理から独立して確定できる接続かを確認する。 */
    public static function canFinalizeSubmission()
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            if ((string) $wpdb->get_var('SELECT @@autocommit') !== '1') return false;

            $status = $wpdb->get_var('SELECT @@in_transaction');
            if ($status !== null) return (string) $status === '0';

            // @@in_transaction がない MySQL では、解放時の 1305 で開いていないことを確認する。
            if ($wpdb->query('SAVEPOINT dashi_submission_probe') === false
                || $wpdb->query('RELEASE SAVEPOINT dashi_submission_probe') !== false) return false;

            // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- 翻訳されるエラー文の代わりにDBのエラー番号を使う。
            return $wpdb->dbh instanceof \mysqli && mysqli_errno($wpdb->dbh) === 1305;
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function lockName($key)
    {
        global $wpdb;
        // 旧MySQLでは2つ目の名前付きロックが1つ目を解除するため、サイトで1つに統合する。
        if (!self::supportsMultipleLocks()) $key = 'site';
        return 'dashi_state_' . md5(DB_NAME . ':' . $wpdb->prefix . ':' . $key);
    }

    public static function ownsLock($key)
    {
        global $wpdb;
        $name = self::lockName($key);
        return isset(self::$locks[$name]) && (int) $wpdb->get_var(
            $wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $name)
        ) === 1;
    }

    public static function lock($key, $wait = 3, $reentrant = true)
    {
        global $wpdb;
        $name = self::lockName($key);
        if (!$reentrant && isset(self::$locks[$name])) return false;
        if (!isset(self::$locks[$name])) {
            if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait)) !== 1) return false;
            self::$locks[$name] = 0;
        }
        ++self::$locks[$name];
        return $name;
    }

    public static function unlock($name)
    {
        global $wpdb;
        if (--self::$locks[$name] > 0) return;
        unset(self::$locks[$name]);
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    private static function collectCurrent($now)
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires <= %d ORDER BY expires LIMIT 500", $now));
    }

    public static function collect()
    {
        global $wpdb;
        if (get_option('dashi_form_state_schema')) {
            self::collectCurrent(time());
            $table = self::table();
            // 旧版の空の受付記録も定期処理で少量ずつ回収する。
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE state_key LIKE %s AND payload = %s ORDER BY state_key LIMIT 500",
                $wpdb->esc_like('submission_') . '%', '[]'
            ));
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s
             AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) <= %d LIMIT 500",
            $wpdb->esc_like(self::LEGACY_PREFIX) . '%', time()
        ));
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
