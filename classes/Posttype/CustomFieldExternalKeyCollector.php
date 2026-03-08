<?php

namespace Dashi\Core\Posttype;

class CustomFieldExternalKeyCollector
{
    /**
     * DB 由来の meta_key から、Dashi 由来でない post type 向けの期待キーを集める
     *
     * @param array<int, object> $meta_keys
     * @param array<string, int|string> $post_ids_by_meta_key
     * @param array<int|string, string|bool> $classes_by_post_id
     * @param array<string, bool> $is_dashi_by_class
     * @return array<string, array<int, string>>
     */
    public static function collect(
        $meta_keys,
        $post_ids_by_meta_key,
        $classes_by_post_id,
        $is_dashi_by_class
    ) {
        $expected_keys = [];

        foreach ($meta_keys as $meta_key_row)
        {
            if (
                !isset($meta_key_row->meta_key) ||
                !is_string($meta_key_row->meta_key) ||
                $meta_key_row->meta_key === ''
            ) {
                continue;
            }

            $meta_key = $meta_key_row->meta_key;
            if ($meta_key[0] === '_')
            {
                continue;
            }

            if (!array_key_exists($meta_key, $post_ids_by_meta_key))
            {
                continue;
            }

            $post_id = $post_ids_by_meta_key[$meta_key];
            $class = $classes_by_post_id[$post_id] ?? false;
            if (!$class || !is_string($class))
            {
                continue;
            }

            if (($is_dashi_by_class[$class] ?? true) === true)
            {
                continue;
            }

            $expected_keys[$class][] = $meta_key;
        }

        return $expected_keys;
    }
}
