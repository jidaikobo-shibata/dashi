<?php

namespace Dashi\Core\Posttype;

use Dashi\Core\Field;

class CustomFieldRenderer
{
    /**
     * 通常系フィールドの描画を行う
     *
     * textarea(wysiwyg) や file 系のような特殊処理はここでは扱わず、
     * `text/password/textarea/select/radio/checkbox` の通常描画だけを受け持つ。
     *
     * @param string $type
     * @param string $key
     * @param mixed $val
     * @param array $options
     * @param string $description
     * @param array $attrs
     * @param string $template
     * @param bool $filterable
     * @return array{handled: bool, html: string, is_label: bool}
     */
    public static function renderBasic(
        $type,
        $key,
        $val,
        $options,
        $description,
        $attrs,
        $template,
        $filterable
    ) {
        switch ($type)
        {
            case 'text':
                return array(
                    'handled' => true,
                    'html' => Field::field_text($key, $val, $description, $attrs, $template),
                    'is_label' => true,
                );

            case 'password':
                return array(
                    'handled' => true,
                    'html' => Field::field_password($key, $val, $description, $attrs, $template),
                    'is_label' => true,
                );

            case 'textarea':
                return array(
                    'handled' => true,
                    'html' => Field::field_textarea($key, $val, $description, $attrs, $template),
                    'is_label' => true,
                );

            case 'select':
                return array(
                    'handled' => true,
                    'html' => Field::field_select(
                        $key,
                        $val,
                        $options,
                        $description,
                        $attrs,
                        $template,
                        $filterable
                    ),
                    'is_label' => true,
                );

            case 'radio':
                return array(
                    'handled' => true,
                    'html' => Field::field_radio(
                        $key,
                        $val,
                        $options,
                        $description,
                        $attrs,
                        $template,
                        $filterable
                    ),
                    'is_label' => false,
                );

            case 'checkbox':
                return array(
                    'handled' => true,
                    'html' => Field::field_checkbox(
                        $key,
                        $val,
                        $options,
                        $description,
                        $attrs,
                        $template,
                        $filterable
                    ),
                    'is_label' => false,
                );
        }

        return array(
            'handled' => false,
            'html' => '',
            'is_label' => true,
        );
    }
}
