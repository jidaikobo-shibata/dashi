<?php

namespace Dashi\Core\Posttype;

class CustomFieldMarkupDecorator
{
    /**
     * 文字数カウント表示域を必要に応じて追加する
     *
     * @param string $html
     * @param array $attrs
     * @return string
     */
    public static function appendCharCountArea($html, $attrs)
    {
        if (!isset($attrs['class']) || strpos($attrs['class'], 'dashi_chrcount') === false)
        {
            return $html;
        }

        return $html.'<div class="dashi_chrcount_area" id="dashi_chrcount_'.$attrs['id'].'">-</div>';
    }

    /**
     * スクリーンリーダ向け label を必要に応じて付与する
     *
     * @param string $html
     * @param array $attrs
     * @param array $value
     * @param bool $is_label
     * @param bool $is_label_hide
     * @return string
     */
    public static function wrapWithLabel($html, $attrs, $value, $is_label, $is_label_hide)
    {
        if (!$is_label || !isset($value['title']))
        {
            return $html;
        }

        $label_class = 'dashi_custom_fields_label';
        $label_class .= $is_label_hide ? ' screen-reader-text' : '';

        return '<label for="'.$attrs['id'].'" class="'.$label_class.'">'.$value['title'].'</label>'.$html;
    }
}
