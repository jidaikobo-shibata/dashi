<?php

namespace Dashi\Core\Posttype;

use Dashi\Core\Field;

class CustomFieldFileRenderer
{
    /**
     * file 系の描画を行う
     *
     * @param string $type
     * @param string $key
     * @param mixed $val
     * @param string $description
     * @param array $attrs
     * @param string $template
     * @param array $value
     * @param bool $is_use_wp_uploader
     * @return array{handled: bool, html: string, attrs: array}
     */
    public static function render(
        $type,
        $key,
        $val,
        $description,
        $attrs,
        $template,
        $value,
        $is_use_wp_uploader
    ) {
        if ($type !== 'file' && $type !== 'file_media')
        {
            return array(
                'handled' => false,
                'html' => '',
                'attrs' => $attrs,
            );
        }

        $attrs['id'] = 'upload_field_'.$attrs['id'];

        if ($type === 'file')
        {
            return array(
                'handled' => true,
                'html' => Field::field_file(
                    $key,
                    $val,
                    $description,
                    $attrs,
                    $template,
                    $is_use_wp_uploader
                ),
                'attrs' => $attrs,
            );
        }

        $is_image = isset($value['args']['is_image']) ?
            intval($value['args']['is_image']) :
            true;

        return array(
            'handled' => true,
            'html' => Field::field_file_media(
                $key,
                $val,
                $description,
                $attrs,
                $template,
                $is_image
            ),
            'attrs' => $attrs,
        );
    }
}
