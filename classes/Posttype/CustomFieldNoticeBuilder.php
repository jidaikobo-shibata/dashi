<?php

namespace Dashi\Core\Posttype;

class CustomFieldNoticeBuilder
{
    /**
     * 開発者向け Notice を組み立てる
     *
     * @param string $key
     * @param string $tmpkey
     * @param object $object
     * @param array<string, array> $custom_fields_flat
     * @return string
     */
    public static function build($key, $tmpkey, $object, $custom_fields_flat)
    {
        if (!get_option('dashi_development_mode'))
        {
            return '';
        }

        $output = '';

        if (
            in_array($tmpkey, Posttype::$banned) ||
            in_array($tmpkey, array_map(array('\\Dashi\\P', 'class2posttype'), Posttype::instances()))
        ) {
            $output .= '<strong class="dashi_err_msg">Notice: '.
                sprintf(
                    /* translators: %s: custom field key */
                    __('"%s" is cannot use as custom field name.', 'dashi'),
                    $key
                ).
                '</strong>';
        }

        $current_class = Posttype::posttype2class($object->post_type);
        foreach ($custom_fields_flat as $each_class => $each_custom_fields)
        {
            if (
                $current_class != '\\'.$each_class &&
                in_array($tmpkey, array_keys($each_custom_fields))
            ) {
                $output .= '<strong class="dashi_err_msg">Notice: '.
                    sprintf(
                        /* translators: %s: custom field key */
                        __('"%s" is already used other custom post type. This field cannot use "add_column" attribute at administration screen.', 'dashi'),
                        $key
                    ).
                    '</strong>';
            }
        }

        return $output;
    }

    /**
     * スクリーンリーダ向け label 不足の Notice を返す
     *
     * @param bool $is_label
     * @param array $value
     * @return string
     */
    public static function buildMissingLabelNotice($is_label, $value)
    {
        if (!$is_label || isset($value['title']) || !current_user_can('administrator'))
        {
            return '';
        }

        return '<strong class="dashi_err_msg">Notice: set "label" attribute for label.</strong>';
    }
}
