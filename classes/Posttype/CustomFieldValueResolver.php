<?php

namespace Dashi\Core\Posttype;

class CustomFieldValueResolver
{
    /**
     * カスタムフィールド描画用の値を決める
     *
     * @param object $object
     * @param array $value
     * @return array{value:mixed, meta_key:string}
     */
    public static function resolve($object, $value)
    {
        $key = $value['id'];
        $meta_key = $key;
        $resolved = isset($value['args']['value']) ? $value['args']['value'] : '';

        if (static::usesArrayValue($key, $value))
        {
            $resolved = static::resolveArrayValue($object, $key, $resolved);
            if (preg_match("/\[(\d*?)\]/", $key))
            {
                $meta_key = preg_replace("/\[\d*?\]/", '', $key);
            }
        }
        elseif (isset($object->{$key}) && ! is_null($object->{$key}))
        {
            $resolved = $object->{$key};
        }

	        return array(
	            'value' => $resolved,
	            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- SQL用途ではなく配列キー名。
	            'meta_key' => $meta_key,
	        );
    }

    /**
     * 配列値として扱うべき定義かを確認する
     *
     * @param string $key
     * @param array $value
     * @return bool
     */
    private static function usesArrayValue($key, $value)
    {
        if (!isset($value['args']['type']))
        {
            return false;
        }

        return
            $value['args']['type'] == 'checkbox' ||
            ($value['args']['type'] == 'select' && isset($value['args']['attrs']['multiple'])) ||
            strpos($key, '[') !== false;
    }

    /**
     * 配列系フィールドの値を決める
     *
     * @param object $object
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function resolveArrayValue($object, $key, $default)
    {
        $tmp = '';
        $meta_key = $key;
        $tmps = array();

	        if (preg_match("/\[(\d*?)\]/", $key, $ms))
	        {
	            $meta_key = preg_replace("/\[\d*?\]/", '', $key);
            $original = Copy::requestedOriginal(isset($object->post_type) ? $object->post_type : '');
            if ($original) {
                $tmps = get_post_meta($original->ID, $meta_key, false);
            }
	            elseif (is_object($object) && isset($object->ID))
	            {
                $tmps = get_post_meta($object->ID, $meta_key, false);
            }

            $tmp = isset($tmps[$ms[1]]) ? $tmps[$ms[1]] : '';
        }
        else
        {
            $tmp = is_object($object) ? get_post_meta($object->ID, $meta_key, false) : '';
        }

        return ! empty($tmp) ? $tmp : $default;
    }
}
