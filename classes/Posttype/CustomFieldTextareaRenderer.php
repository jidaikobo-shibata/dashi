<?php

namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

use Dashi\Core\Field;

class CustomFieldTextareaRenderer
{
    /**
     * textarea 系の描画を行う
     *
     * wysiwyg は WordPress の wp_editor() を使うため、通常 textarea と分けて扱う。
     *
     * @param string $key
     * @param mixed $val
     * @param string $description
     * @param array $attrs
     * @param string $template
     * @param array $value
     * @param string $id_str
     * @param string $output
     * @return array{output: string, html: string}
     */
    public static function render($key, $val, $description, $attrs, $template, $value, $id_str, $output)
    {
        if (!isset($value['args']['wysiwyg']))
        {
            return array(
                'output' => $output,
                'html' => Field::field_textarea(
                    $key,
                    $val,
                    $description,
                    $attrs,
                    $template
                ),
            );
        }

        if (!preg_match('/^[a-zA-Z_]+$/', $key))
        {
            throw new \Exception(
                esc_html__('You can use alphabet and underscore only when use wysiwyg.', 'dashi')
            );
        }

        $output .= '<span class="dashi_description">'.$description.'</span>';

        $opts = is_array($value['args']['wysiwyg']) ? $value['args']['wysiwyg'] : array();
        $opts['textarea_name'] = $key;

        ob_start();
        wp_editor(
            wp_specialchars_decode($val, ENT_QUOTES),
            $id_str,
            $opts
        );
        $html = ob_get_contents();
        ob_end_clean();

        if (isset($value['args']['attrs']))
        {
            $html = static::applyEditorAttrs($html, $value['args']['attrs']);
        }

        return array(
            'output' => $output,
            'html' => $html,
        );
    }

    /**
     * wp_editor が出力した textarea に attrs を反映する
     *
     * @param string $html
     * @param array $attrs
     * @return string
     */
    private static function applyEditorAttrs($html, $attrs)
    {
        if (isset($attrs['class']))
        {
            $html = str_replace(
                'wp-editor-area',
                'wp-editor-area '.esc_html($attrs['class']),
                $html
            );
            unset($attrs['class']);
        }

        unset($attrs['id'], $attrs['name'], $attrs['rows'], $attrs['cols']);

        return str_replace(
            '<textarea ',
            '<textarea '.Field::array_to_attr($attrs).' ',
            $html
        );
    }
}
