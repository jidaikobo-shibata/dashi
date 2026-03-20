<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class CustomFieldsCategories
{
    /**
     * タクソノミー編集画面のカスタムフィールドで必要なHTMLのみ許可する
     *
     * @return array<string, array<string, bool>>
     */
    private static function allowedFieldHtml()
    {
        $allowed_html = wp_kses_allowed_html('post');

        $allowed_html['tr'] = [
            'class' => true,
        ];
        $allowed_html['th'] = [];
        $allowed_html['td'] = [];
        $allowed_html['label'] = [
            'for' => true,
            'class' => true,
            'data-dashi-filter-text' => true,
        ];
        $allowed_html['input'] = [
            'type' => true,
            'name' => true,
            'value' => true,
            'id' => true,
            'class' => true,
            'style' => true,
            'checked' => true,
            'placeholder' => true,
            'data-dashi-filter-target' => true,
        ];
        $allowed_html['div'] = [
            'class' => true,
            'data-dashi-filter-group' => true,
        ];
        $allowed_html['a'] = [
            'href' => true,
            'target' => true,
        ];
        $allowed_html['img'] = [
            'src' => true,
            'alt' => true,
            'width' => true,
        ];

        return $allowed_html;
    }

    /**
     * カスタムフィールド本体のHTMLを組み立てる
     *
     * @param string $key
     * @param array<string, mixed> $custom_field
     * @param mixed $value
     * @return string
     */
    private static function buildFieldHtml($key, $custom_field, $value)
    {
        $description = isset($custom_field['description']) ? $custom_field['description'] : '';
        $attrs = isset($custom_field['attrs']) ? $custom_field['attrs'] : array();
        $filterable = ! empty($custom_field['filter']);
        $options = [];

        if (isset($custom_field['options'])) {
            $options = \Dashi\Core\Util::resolveOptions($custom_field['options']);
        }

        $id = 'upload_field_'.$key;
        $attrs['id'] = $id;

        switch ($custom_field['type'])
        {
            case 'file':
                return Field::field_file(
                    $key,
                    $value,
                    $description,
                    $attrs,
                    '', //template
                    true
                );
            case 'radio':
                return Field::field_radio(
                    $key,
                    $value,
                    $options,
                    $description,
                    $attrs,
                    '', //template
                    $filterable
                );
            case 'checkbox':
                return Field::field_checkbox(
                    $key,
                    maybe_unserialize($value),
                    $options,
                    $description,
                    $attrs,
                    '', //template
                    $filterable
                );
            case 'text':
                return Field::field_text(
                    $key,
                    $value,
                    $description,
                    $attrs,
                    '', //template
                    true
                );
            default:
                return 'not supported';
        }
    }

    /**
     * add custom fields
     *
     * @param object $tag
     * @return void
     */
    public static function addCustomFields ($tag)
    {
        $taxonomies = P::taxonomies();
        if ( ! isset($taxonomies[$tag->taxonomy])) return;

        $posttype = P::posttype2class($taxonomies[$tag->taxonomy]);
        $custom_fields_taxonomies = $posttype::get('custom_fields_taxonomies');
        $custom_fields = $custom_fields_taxonomies[$tag->taxonomy];

        $t_id = $tag->term_id;
        $cat_meta = get_option("cat_$t_id");
        $cat_meta = $cat_meta === FALSE ? [] : $cat_meta;

        $html = '';
        foreach ($custom_fields as $key => $custom_field)
        {
            $label = isset($custom_field['label']) ? $custom_field['label'] : $key;
            $value = isset($cat_meta[$key]) ? $cat_meta[$key] : '';

            $html.= '<tr class="form-field">';
            $html.= '<th><label for="upload_field_'.$key.'">'.esc_html($label).'</label></th>';
            $html.= '<td>';
            $html.= self::buildFieldHtml($key, $custom_field, $value);
            $html.= '</td></tr>';
        }
        wp_nonce_field(basename(__FILE__), 'term_order_nonce');
        echo wp_kses($html, self::allowedFieldHtml());
    }

    /**
     * add custom fields for add screen
     *
     * @param string $taxonomy
     * @return void
     */
    public static function addCustomFieldsForNew($taxonomy)
    {
        $taxonomies = P::taxonomies();
        if ( ! isset($taxonomies[$taxonomy])) return;

        $posttype = P::posttype2class($taxonomies[$taxonomy]);
        $custom_fields_taxonomies = $posttype::get('custom_fields_taxonomies');
        $custom_fields = $custom_fields_taxonomies[$taxonomy];

        $html = '';
        foreach ($custom_fields as $key => $custom_field)
        {
            $label = isset($custom_field['label']) ? $custom_field['label'] : $key;

            $html .= '<div class="form-field term-dashi-field-wrap">';
            $html .= '<label for="upload_field_'.$key.'">'.esc_html($label).'</label>';
            $html .= self::buildFieldHtml($key, $custom_field, '');
            $html .= '</div>';
        }

        wp_nonce_field(basename(__FILE__), 'term_order_nonce');
        echo wp_kses($html, self::allowedFieldHtml());
    }

    /**
     * save custom fields
     *
     * @param int $term_id
     * @param int $tt_id
     * @param string $taxonomy
     * @return void
     */
    public static function saveHook($term_id, $tt_id = 0, $taxonomy = '')
    {
        unset($tt_id);

        $term_order_nonce = filter_input(INPUT_POST, 'term_order_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $term_order_nonce = is_string($term_order_nonce) ? sanitize_text_field(wp_unslash($term_order_nonce)) : '';
        if ($term_order_nonce === '' || !wp_verify_nonce($term_order_nonce, basename(__FILE__))) {
            return;
        }

        $taxonomies = P::taxonomies();
        if ( ! isset($taxonomies[$taxonomy])) return;

        $posttype = P::posttype2class($taxonomies[$taxonomy]);
        $custom_fields_taxonomies = $posttype::get('custom_fields_taxonomies');

        $cat_key = 'cat_'.$term_id;
        $old_values = get_option($cat_key);

        $new_values = [];
        foreach ($custom_fields_taxonomies as $custom_fields) {
            foreach ($custom_fields as $key => $val) {
                $new_value = Input::post($key);
                if (is_array($new_value)) {
                    $new_value = array_map('sanitize_text_field', $new_value);
                } else {
                    $new_value = sanitize_text_field($new_value);
                }

                // $new_value = maybe_serialize($new_value);
                if ($new_value === '' || $new_value === []) {
                    $new_values[$key] = '';
                } else {
                    $new_values[$key] = maybe_serialize($new_value);
                }

                // $old_value = isset($old_values[$key]) ? $old_values[$key] : '';
                // if (trim($new_value) === '') {
                //     $new_values[$key] = '';
                // } else {
                //     $new_values[$key] = $new_value;
                // }
            }
        }

        update_option($cat_key, $new_values);
    }
}
