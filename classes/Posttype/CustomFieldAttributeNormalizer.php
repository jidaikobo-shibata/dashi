<?php

namespace Dashi\Core\Posttype;

class CustomFieldAttributeNormalizer
{
    /**
     * 描画前の attrs を正規化する
     *
     * @param string $key
     * @param array $attrs
     * @return array{attrs:array, id_str:string}
     */
    public static function normalize($key, $attrs)
    {
        $id_str = 'dashi_' . $key;

        if (!isset($attrs['id']) || $attrs['id'] != $key)
        {
            $attrs['id'] = $id_str;
        }

        if (strpos($attrs['id'], '[') !== false)
        {
            $attrs['id'] = str_replace(array('[', ']'), array('_', ''), $attrs['id']);
        }

        return array(
            'attrs' => $attrs,
            'id_str' => $id_str,
        );
    }
}
