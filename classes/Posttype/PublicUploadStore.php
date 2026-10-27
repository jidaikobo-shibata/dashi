<?php

namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

/** 公開アップロードの受領記録。クライアントのファイル名を認可に使わない。 */
class PublicUploadStore
{
    private const REALM = 'dashi_public_uploads';
    public const POST_META = '_dashi_public_uploads';

    public static function register($form, $field, array $upload)
    {
        if (empty($upload['name']) || !empty($upload['errors']) || !self::path($upload['name'])) return [];
        $type = wp_check_filetype($upload['name']);
        $upload['type'] = $type['type'] ?: 'application/octet-stream';
        $upload['upload_id'] = bin2hex(random_bytes(32));
        $upload['form'] = $form;
        $upload['field'] = $field;
        $ttl = (int) apply_filters('dashi_public_form_tmp_file_ttl', DAY_IN_SECONDS);
        $upload['expires'] = time() + ($ttl < 60 ? DAY_IN_SECONDS : $ttl);
        $upload['dashi_uploaded_file'] = true;
        Session::set(self::REALM, $upload['upload_id'], $upload);
        return $upload;
    }

    /** 受領IDは、このセッション・フォーム・入力欄で発行したものだけを認める。 */
    public static function resolve($form, $field, $value, $post_id = 0)
    {
        if (!is_array($value) || empty($value['upload_id']) || !is_string($value['upload_id'])) return [];
        $id = $value['upload_id'];
        if (!preg_match('/\A[a-f0-9]{64}\z/', $id)) return [];
        $upload = Session::show(self::REALM, $id);
        if (!is_array($upload) || ($upload['expires'] ?? 0) < time()) return [];
        if (($upload['form'] ?? '') !== $form || ($upload['field'] ?? '') !== $field) return [];
        if (!empty($upload['post_id']) && (int) $upload['post_id'] !== (int) $post_id) return [];
        if (isset($value['name']) && $value['name'] !== $upload['name']) return [];
        return self::path($upload['name']) ? $upload : [];
    }

    /** 検証済みファイルを一つの投稿へ引き渡す。承認時はセッションを要求しない。 */
    public static function claim($post_id, array $uploads)
    {
        foreach ($uploads as $field => &$upload) {
            $upload['post_id'] = (int) $post_id;
            Session::set(self::REALM, $upload['upload_id'], $upload);
        }
        unset($upload);
        // 承認に不要なEXIF等は投稿へ保存しない。
        $manifest = [];
        foreach ($uploads as $field => $upload) {
            $manifest[$field] = array_intersect_key($upload, array_flip([
                'upload_id', 'form', 'field', 'name', 'original_name', 'post_id',
            ]));
        }
        if ($manifest) update_post_meta($post_id, self::POST_META, $manifest);
    }

    public static function forPost($post_id, $form, $field, $filename)
    {
        $uploads = get_post_meta($post_id, self::POST_META, true);
        $upload = is_array($uploads) && isset($uploads[$field]) ? $uploads[$field] : [];
        if (!is_array($upload) || (int) ($upload['post_id'] ?? 0) !== (int) $post_id
            || ($upload['form'] ?? '') !== $form || ($upload['field'] ?? '') !== $field
            || ($upload['name'] ?? '') !== $filename) return [];
        return self::path($filename) ? $upload : [];
    }

    public static function discardForm($form)
    {
        foreach ((array) Session::show(self::REALM) as $id => $upload) {
            if (is_array($upload) && ($upload['form'] ?? '') === $form) Session::remove(self::REALM, $id);
        }
    }

    /** 一時領域直下の通常ファイルだけを受け付ける。 */
    public static function path($name)
    {
        if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9._-]+\z/', $name)) return false;
        $base = realpath(DASHI_TMP_UPLOAD_DIR);
        $candidate = DASHI_TMP_UPLOAD_DIR . $name;
        $path = realpath($candidate);
        if (!$base || !$path || is_link($candidate) || !is_file($path)
            || dirname($path) !== $base) return false;
        return $path;
    }

    /** 排他的に作成し、既存ファイルは上書きしない。元ファイルは添付登録後に削除する。 */
    public static function copyToFinal($name, $directory)
    {
        $source = self::path($name);
        if (!$source || !is_dir($directory)) return new \WP_Error('dashi_upload_missing');
        $filename = wp_unique_filename($directory, $name);
        $destination = trailingslashit($directory) . $filename;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- xモードで既存ファイルの上書きを原子的に防ぐ。
        $output = @fopen($destination, 'xb');
        if (!$output) return new \WP_Error('dashi_upload_destination');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 検証済みの一時ファイルをストリーム転送する。
        $input = @fopen($source, 'rb');
        $copied = $input ? stream_copy_to_stream($input, $output) : false;
        $flushed = fflush($output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 排他的に開いたストリームを必ず閉じる。
        if ($input) fclose($input);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 排他的に開いたストリームを必ず閉じる。
        fclose($output);
        if ($copied === false || !$flushed || $copied !== filesize($source)) {
            wp_delete_file($destination);
            return new \WP_Error('dashi_upload_copy');
        }
        return ['name' => $filename, 'path' => $destination, 'source' => $source];
    }
}
