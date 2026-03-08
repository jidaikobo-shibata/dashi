<?php

namespace Dashi\Core\Posttype;

class CustomFieldFlattener
{
    /**
     * fields を持つ定義を 1 階層に展開する
     *
     * 現行実装どおり、親要素自体は残しつつ、その fields 配下を
     * 同じ配列へ展開して返します。
     *
     * @param array $custom_fields
     * @return array
     */
    public static function flatten($custom_fields)
    {
        foreach ($custom_fields as $key => $field)
        {
            if (!isset($field['fields']) || !is_array($field['fields']))
            {
                continue;
            }

            foreach ($field['fields'] as $field_key => $field_value)
            {
                $custom_fields[$field_key] = $field_value;
                unset($custom_fields[$key]['fields'][$field_key]);
            }
        }

        return $custom_fields;
    }
}
