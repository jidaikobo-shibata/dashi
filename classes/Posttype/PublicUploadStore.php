<?php

namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

/** 公開アップロードの受領記録。クライアントのファイル名を認可に使わない。 */
class PublicUploadStore
{
    private const REALM = 'dashi_public_uploads';
    public const POST_META = '_dashi_public_uploads';

    public static function register($form, $field, array $upload, $content = null, ?callable $checkGrant = null)
    {
        if (empty($upload['name']) || !empty($upload['errors'])) return [];
        $type = wp_check_filetype($upload['name']);
        $upload['type'] = $upload['type'] ?? ($type['type'] ?: 'application/octet-stream');
        $upload['upload_id'] = bin2hex(random_bytes(32));
        $upload['form'] = $form;
        $upload['site_id'] = get_current_blog_id();
        $upload['field'] = $field;
        $ttl = (int) apply_filters('dashi_public_form_tmp_file_ttl', DAY_IN_SECONDS);
        $upload['expires'] = time() + ($ttl < 60 ? DAY_IN_SECONDS : $ttl);
        if ($content !== null) {
            $grantRevoked = false;
            $beforeWrite = $checkGrant === null ? null : static function () use ($checkGrant, &$grantRevoked) {
                $grantRevoked = !$checkGrant();
                return !$grantRevoked;
            };
            if (!PublicUploadBlob::put($upload['name'], $form, $upload['type'], $content, $upload['expires'], $beforeWrite)) {
                return ['dashi_uploaded_file' => true, 'errors' => [
                    $grantRevoked ? __('Reload the form before uploading an attachment.', 'dashi')
                        : __('The attachment could not be stored. The upload storage may be full.', 'dashi'),
                ]];
            }
            $upload['storage'] = 'db';
        } elseif (!self::path($upload['name'])) {
            return [];
        }
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
        if (($upload['site_id'] ?? 0) !== get_current_blog_id() || ($upload['form'] ?? '') !== $form || ($upload['field'] ?? '') !== $field) return [];
        if (!empty($upload['post_id']) && (int) $upload['post_id'] !== (int) $post_id) return [];
        if (isset($value['name']) && $value['name'] !== $upload['name']) return [];
        return self::exists($upload, $post_id) ? $upload : [];
    }

    /** 検証済みファイルを一つの投稿へ引き渡す。承認時はセッションを要求しない。 */
    public static function claim($post_id, array $uploads, array $expectedMeta = [])
    {
        $names = [];
        foreach ($uploads as $upload) {
            if (($upload['storage'] ?? '') === 'db') $names[] = $upload['name'];
        }
        // 所有権を切り替える前に一覧を確定する。一覧だけでは配信権限は付与されない。
        $manifest = [];
        foreach ($uploads as $field => $upload) {
            $upload['post_id'] = (int) $post_id;
            $manifest[$field] = array_intersect_key($upload, array_flip([
                'upload_id', 'form', 'field', 'name', 'original_name', 'post_id', 'storage', 'metadata_removed',
            ]));
        }
        if ($manifest) {
            if (!add_post_meta($post_id, self::POST_META, $manifest, true)) return false;
            $expectedMeta[self::POST_META] = [maybe_serialize(wp_unslash($manifest))];
        }
        if (!$names && $expectedMeta && !Save::verifyStoredPostMeta($post_id, $expectedMeta)) return false;
        if (!$manifest) return true;
        if (!PublicUploadBlob::claim($names, $post_id, $expectedMeta)) {
            if (!PublicUploadBlob::hasForPost($post_id)) delete_post_meta($post_id, self::POST_META, $manifest);
            return false;
        }
        foreach ($uploads as $upload) {
            $upload['post_id'] = (int) $post_id;
            Session::set(self::REALM, $upload['upload_id'], $upload);
        }
        return true;
    }

    public static function forPost($post_id, $form, $field, $filename)
    {
        $uploads = get_post_meta($post_id, self::POST_META, true);
        $upload = is_array($uploads) && isset($uploads[$field]) ? $uploads[$field] : [];
        if (!is_array($upload) || (int) ($upload['post_id'] ?? 0) !== (int) $post_id
            || ($upload['form'] ?? '') !== $form || ($upload['field'] ?? '') !== $field
            || ($upload['name'] ?? '') !== $filename) return [];
        return self::isReferenced($post_id, $field, $filename) && self::exists($upload, $post_id) ? $upload : [];
    }

    private static function isReferenced($post_id, $field, $filename)
    {
        $reference = get_post_meta($post_id, $field, true);
        return self::matchesReference($reference, $filename);
    }

    public static function matchesReference($reference, $filename)
    {
        if (!is_string($reference) || PublicForm::extractUploadedFilename($reference) !== $filename) return false;
        $path = wp_parse_url($reference, PHP_URL_PATH);
        if (is_string($path) && substr($path, -strlen('/dashi/file.php')) === '/dashi/file.php') return true;
        parse_str((string) wp_parse_url($reference, PHP_URL_QUERY), $query);
        return ($query['dashi_attachment'] ?? '') === '1';
    }

    public static function hasDownloadReference($post, $filename, $storage)
    {
        $manifest = get_post_meta($post->ID, self::POST_META, true);
        foreach ((array) $manifest as $field => $upload) {
            if (!is_array($upload) || ($upload['storage'] ?? 'legacy') !== $storage) continue;
            if (self::forPost($post->ID, $post->post_type, $field, $filename)) return true;
        }
        return false;
    }

    /** 保存完了後、参照を外したDB添付だけを削除する。 */
    public static function removeDetached($post)
    {
        $manifest = get_post_meta($post->ID, self::POST_META, true);
        if (!is_array($manifest)) return;
        $remaining = $manifest;
        foreach ($manifest as $field => $upload) {
            // 旧形式の実ファイルは参照の確認と一体で削除できないため保持する。
            if (!is_array($upload) || ($upload['storage'] ?? '') !== 'db' || !empty($upload['final_url'])
                || (int) ($upload['post_id'] ?? 0) !== (int) $post->ID
                || ($upload['form'] ?? '') !== $post->post_type || ($upload['field'] ?? '') !== $field) continue;
            $filename = $upload['name'] ?? '';
            if (!is_string($filename) || $filename === '' || self::isReferenced($post->ID, $field, $filename)) continue;

            if (!PublicUploadBlob::deleteDetached($filename, $post->ID, $post->post_type)) continue;
            unset($remaining[$field]);
        }
        if ($remaining !== $manifest) update_post_meta($post->ID, self::POST_META, wp_slash($remaining), $manifest);
    }

    private static function exists(array $upload, $post_id)
    {
        if (($upload['storage'] ?? '') !== 'db') return (bool) self::path($upload['name']);
        $blob = PublicUploadBlob::get($upload['name']);
        return $blob && (int) $blob->post_id === (int) $post_id;
    }

    private static function formReceipts($form)
    {
        return array_filter((array) Session::show(self::REALM), static function ($upload) use ($form) {
            return is_array($upload) && ($upload['site_id'] ?? 0) === get_current_blog_id()
                && ($upload['form'] ?? '') === $form;
        });
    }

    public static function hasUnsubmitted($form)
    {
        foreach (self::formReceipts($form) as $upload) {
            if (empty($upload['post_id']) && ($upload['expires'] ?? 0) > time()) return true;
        }
        return false;
    }

    public static function discardForm($form, $deleteUnsubmitted = false, array $mailReceipts = [])
    {
        $complete = true;
        foreach (self::formReceipts($form) as $id => $upload) {
            if ($deleteUnsubmitted && empty($upload['post_id']) && !in_array($id, $mailReceipts, true)
                && ($upload['storage'] ?? '') === 'db'
                && !PublicUploadBlob::deleteUnsubmitted($upload['name'], $form)) {
                $complete = false;
                continue;
            }
            Session::remove(self::REALM, $id);
        }
        return $complete;
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
    public static function copyToFinal($name, $directory, array $upload = [])
    {
        $blob = null;
        $source = false;
        if (($upload['storage'] ?? '') === 'db') {
            $blob = PublicUploadBlob::get($name, true);
            if (!$blob || (int) $blob->post_id !== (int) $upload['post_id']) {
                return new \WP_Error('dashi_upload_missing');
            }
        } else {
            $source = self::path($name);
        }
        if ((!$blob && !$source) || !is_dir($directory)) return new \WP_Error('dashi_upload_missing');
        $filename = wp_unique_filename($directory, $name);
        $destination = trailingslashit($directory) . $filename;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- xモードで既存ファイルの上書きを原子的に防ぐ。
        $output = @fopen($destination, 'xb');
        if (!$output) return new \WP_Error('dashi_upload_destination');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- 検証済みの一時ファイルをストリーム転送する。
        $input = $source ? @fopen($source, 'rb') : false;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- 排他的に開いた公開先と同じストリームに書き込む。
        $copied = $blob ? fwrite($output, $blob->content) : ($input ? stream_copy_to_stream($input, $output) : false);
        $size = $blob ? (int) $blob->bytes : filesize($source);
        $flushed = fflush($output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 排他的に開いたストリームを必ず閉じる。
        if ($input) fclose($input);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- 排他的に開いたストリームを必ず閉じる。
        fclose($output);
        if ($copied === false || !$flushed || $copied !== $size) {
            wp_delete_file($destination);
            return new \WP_Error('dashi_upload_copy');
        }
        return ['name' => $filename, 'path' => $destination, 'source' => $source];
    }
}
