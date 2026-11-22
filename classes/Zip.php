<?php
// zip code library
// thx https://github.com/ninton/jquery.jpostal.js
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

class Zip
{
    private const FETCH_RATE_OPTION = 'dashi_postal_fetch_rate';
    private const ADMIN_FETCH_RATE_OPTION = 'dashi_postal_admin_fetch_rate';
    private const PUBLIC_FETCHES_PER_MINUTE = 25;
    private const ADMIN_FETCHES_PER_MINUTE = 5;
    private const CLIENT_FETCHES_PER_MINUTE = 5;
    private const MAX_RESPONSE_BYTES = 1024 * 1024;
    private const ADMIN_NONCE_ACTION = 'dashi_postal_admin_lookup';

    private static $lookupPostTypes = [];

    private static function registerLookup($public)
    {
        add_action('wp_ajax_dashi_postal_lookup', [self::class, 'lookup']);
        if ($public) add_action('wp_ajax_nopriv_dashi_postal_lookup', [self::class, 'lookup']);
    }

    public static function lookup()
    {
        if (Security::requiresHttps()) {
            wp_send_json_error(['message' => __('HTTPS is required.', 'dashi')], 403);
        }

        $type = filter_input(INPUT_GET, 'post_type');
        $postId = filter_input(INPUT_GET, 'post', FILTER_VALIDATE_INT);
        $admin = filter_input(INPUT_GET, 'dashi_postal_admin') === '1';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- canLookup で nonce を検証する。
        $nonce = $admin ? wp_unslash($_SERVER['HTTP_X_DASHI_POSTAL_NONCE'] ?? '') : null;
        if (!self::canLookup($admin, $type, $postId, $nonce)) {
            wp_send_json_error(null, 403);
        }

        $prefix = filter_input(INPUT_GET, 'prefix', FILTER_UNSAFE_RAW);
        if (!is_string($prefix) || !preg_match('/\A[0-9]{3}\z/', $prefix)) {
            wp_send_json_error(null, 400);
        }
        $rows = self::addressRows($prefix, $retryAfter, $admin);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            wp_send_json_error(null, 429);
        }
        if ($rows === false) {
            header('Retry-After: 1');
            wp_send_json_error(null, 503);
        }
        if ($rows === null) wp_send_json_error(null, 502);
        wp_send_json_success($rows);
    }

    private static function canLookup($admin, $type, $postId, $nonce = null)
    {
        if (!$admin) {
            return has_action('wp_ajax_nopriv_dashi_postal_lookup', [self::class, 'lookup']) !== false;
        }
        if (!is_user_logged_in() || !is_string($nonce) || !wp_verify_nonce($nonce, self::ADMIN_NONCE_ACTION)) {
            return false;
        }
        if ($postId) {
            $post = get_post($postId);
            return $post && isset(self::$lookupPostTypes[$post->post_type])
                && current_user_can('edit_post', $post->ID);
        }

        $postType = is_string($type) && isset(self::$lookupPostTypes[$type]) ? get_post_type_object($type) : null;
        return $postType && current_user_can($postType->cap->create_posts);
    }

    private static function addressRows($prefix, &$retryAfter = null, $admin = false)
    {
        $retryAfter = 0;
        $key = 'dashi_postal_' . $prefix;
        // 成功結果は共有し、一時的な取得失敗は用途別に保持する。
        $failureKey = $key . ($admin ? '_admin_error' : '_public_error');
        $cached = self::usableAddressRows(get_transient($key));
        if ($cached) return $cached;
        if (get_transient($failureKey) !== false) return null;

        // 公開側と管理画面側を別々に直列化する。混雑時はPHPの処理枠を待機に使わない。
        global $wpdb;
        // 旧MySQLでもフォームの共通ロックと別の名前を使う。
        $lock = ($admin ? 'dashi_zip_admin_' : 'dashi_zip_') . md5(DB_NAME . ':' . $wpdb->prefix);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- DB接続単位の排他制御。
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) return false;
        try {
            $cached = self::usableAddressRows(get_transient($key));
            if ($cached) return $cached;
            if (get_transient($failureKey) !== false) return null;
            if (!self::reserveExternalFetch($lock, $retryAfter, $admin)) return false;

            $response = self::fetchPostalResponse($prefix);
            $rows = (int) wp_remote_retrieve_response_code($response) === 200
                ? self::decodeAddressRows(wp_remote_retrieve_body($response), $prefix) : null;
            if ($rows === null) {
                set_transient($failureKey, 'unavailable', MINUTE_IN_SECONDS);
            } else {
                set_transient($key, $rows, DAY_IN_SECONDS);
            }
            return $rows;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 同じ接続でロックを解放する。
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** 用途別の外部取得ロックの内側で、通信の前に取得枠を消費する。 */
    private static function reserveExternalFetch($lock, &$retryAfter, $admin = false)
    {
        $now = time();
        $option = $admin ? self::ADMIN_FETCH_RATE_OPTION : self::FETCH_RATE_OPTION;
        $limit = $admin ? self::ADMIN_FETCHES_PER_MINUTE : self::PUBLIC_FETCHES_PER_MINUTE;
        $window = get_option($option);
        if (!is_array($window) || (int) ($window['end'] ?? 0) <= $now) {
            $window = ['end' => intdiv($now, MINUTE_IN_SECONDS) * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS, 'count' => 0];
        }
        if ((int) ($window['count'] ?? 0) >= $limit) {
            $retryAfter = (int) $window['end'] - $now;
            return false;
        }
        $client = self::fetchClient($admin);
        $clientCount = (int) ($window['clients'][$client] ?? 0);
        if ($clientCount >= self::CLIENT_FETCHES_PER_MINUTE) {
            $retryAfter = (int) $window['end'] - $now;
            return false;
        }
        $window['clients'][$client] = $clientCount + 1;
        $window['count'] = (int) ($window['count'] ?? 0) + 1;
        global $wpdb;
        // 再接続後は、取得枠を更新して外部通信に進むことを許可しない。
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $written = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             SELECT %s, %s, 'no' FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
            $option, maybe_serialize($window), $lock
        ));
        if (!$written) return false;
        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');
        if (array_key_exists($option, wp_load_alloptions())) wp_cache_delete('alloptions', 'options');
        return true;
    }

    private static function fetchClient($admin)
    {
        $user = $admin ? get_current_user_id() : 0;
        if ($user) return 'user_' . $user;

        // 接続元には Web サーバーの値を使い、IPv6 は /64 単位で数える。
        $address = self::clientAddress(
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clientAddress で単一の IP アドレスとして検証する。
            wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clientAddress で単一の IP アドレスと信頼済みプロキシを確認する。
            wp_unslash($_SERVER['HTTP_X_DASHI_CLIENT_IP'] ?? ''),
            defined('DASHI_POSTAL_TRUSTED_PROXIES') ? DASHI_POSTAL_TRUSTED_PROXIES : []
        );
        if (substr($address, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $address = substr($address, 12);
        }
        return 'ip_' . hash_hmac('sha256', substr($address, 0, 8), wp_salt('auth'));
    }

    private static function clientAddress($remote, $forwarded, $trusted)
    {
        $remote = filter_var($remote, FILTER_VALIDATE_IP);
        if ($remote === false) return '';
        $address = inet_pton($remote);
        $forwarded = filter_var($forwarded, FILTER_VALIDATE_IP);
        if ($forwarded === false || !is_array($trusted)) return $address;

        foreach ($trusted as $proxy) {
            if (filter_var($proxy, FILTER_VALIDATE_IP) !== false && inet_pton($proxy) === $address) {
                return inet_pton($forwarded);
            }
        }
        return $address;
    }

    private static function fetchPostalResponse($prefix)
    {
        $url = 'https://jpostal-1006.appspot.com/json/' . $prefix . '.json';
        return Util::getUncompressedResponse($url, 5, self::MAX_RESPONSE_BYTES);
    }

    private static function decodeAddressRows($body, $prefix)
    {
        if (strlen($body) >= self::MAX_RESPONSE_BYTES) return null;
        // 旧配布形式の外側の固定コールバックを取り除く。応答のコードは実行しない。
        if (preg_match('/\A\s*jQuery_jpostal_callback\s*\((.*)\)\s*;?\s*\z/s', $body, $parts)) {
            $body = $parts[1];
        }
        $rows = json_decode($body, false, 8);
        if (!is_array($rows) || count($rows) > 10001) return null;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row[0] ?? null)
                || !preg_match('/\A_' . $prefix . '(?:[0-9]{4})?\z/', $row[0]) || count($row) > 9) return null;
            foreach ($row as $value) {
                if (!is_string($value) || strlen($value) > 512) return null;
            }
        }
        return self::usableAddressRows($rows) ?: null;
    }

    private static function usableAddressRows($rows)
    {
        if (!is_array($rows)) return [];
        return array_values(array_filter($rows, static function ($row) {
            if (!is_array($row)) return false;
            foreach (array_slice($row, 1) as $value) {
                if (is_string($value) && preg_match('/[^\s\p{Z}]/u', $value)) return true;
            }
            return false;
        }));
    }

    public static function addHeader($fields, array $context = [], $publicForm = false, $postType = '')
    {
        $groups = [];
        $publicGroups = [];
        foreach ($fields as $name => $field) {
            if (!isset($field['zip_group'])) continue;
            if ($publicForm && empty($field['private_form_only'])) {
                if (isset($field['zip_to'])) {
                    $publicGroups[$field['zip_group']]['postcode'] = true;
                } elseif (isset($field['zip_from'])) {
                    $publicGroups[$field['zip_group']]['address'] = true;
                }
            }
            $group = $field['zip_group'];
            if (isset($field['zip_to'])) {
                $groups[$group]['postcode'][] = '#dashi_' . $name;
            } elseif (isset($field['zip_from'])) {
                $groups[$group]['address']['#dashi_' . $name] = $field['zip_from_type'] ?? '';
            }
        }

        $initializers = [];
        foreach ($groups as $group) {
            if (empty($group['postcode']) || empty($group['address'])) continue;
            $flags = JSON_HEX_TAG | JSON_HEX_AMP;
            $options = wp_json_encode($group, $flags);
            if ($options !== false) {
                $initializers[] = '$(document.querySelectorAll(' . wp_json_encode($group['postcode'][0], $flags)
                    . ')).jpostal(' . $options . ');';
            }
        }
        if (!$initializers) return;
        $publicLookup = array_filter($publicGroups, static function ($group) {
            return isset($group['postcode'], $group['address']);
        });
        if ($postType !== '') self::$lookupPostTypes[$postType] = true;
        self::registerLookup((bool) $publicLookup);

        $enqueue = static function () use ($initializers, $context) {
            $handle = 'dashi_jquery_jpostal_js';
            wp_enqueue_script($handle, plugins_url('assets/js/jquery.jpostal.js', DASHI_FILE), ['jquery'], '1.9', true);
            $options = [];
            if (is_admin()) {
                $context['dashi_postal_admin'] = '1';
                $options['nonce'] = wp_create_nonce(self::ADMIN_NONCE_ACTION);
                $screen = get_current_screen();
                if ($screen && $screen->post_type) $context['post_type'] = $screen->post_type;
            }
            $options['endpoint'] = add_query_arg($context, admin_url('admin-ajax.php', 'relative'));
            wp_localize_script($handle, 'DashiPostal', $options);
            wp_add_inline_script($handle, "jQuery(function($) {\n" . implode("\n", $initializers) . "\n});");
        };
        add_action('admin_enqueue_scripts', $enqueue);
        add_action('wp_enqueue_scripts', $enqueue);
    }
}
