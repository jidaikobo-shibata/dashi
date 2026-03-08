<?php

namespace Dashi\Core\Posttype;

class CustomFieldExpectedKeyBuilder
{
    /**
     * Dashi 定義から期待キー一覧を組み立てる
     *
     * 現行実装どおり、fields を持つ項目は子要素のキーを採用し、
     * 後から足される redirect / sticky / search 系のキーもここで補います。
     *
     * @param array $custom_fields
     * @param bool $is_redirect
     * @param bool $is_use_sticky
     * @param bool $is_searchable
     * @return array
     */
    public static function build($custom_fields, $is_redirect, $is_use_sticky, $is_searchable)
    {
        $expected_keys = [];

        foreach ($custom_fields as $key => $value)
        {
            if (isset($value['fields']) && is_array($value['fields']))
            {
                foreach ($value['fields'] as $field_key => $field)
                {
                    $expected_keys[] = $field_key;
                }
                continue;
            }

            $expected_keys[] = $key;
        }

        if ($is_redirect)
        {
            $expected_keys[] = 'dashi_redirect_to';
        }
        if ($is_use_sticky)
        {
            $expected_keys[] = 'dashi_sticky';
        }
        if ($is_searchable)
        {
            $expected_keys[] = 'dashi_search';
        }

        return $expected_keys;
    }
}
