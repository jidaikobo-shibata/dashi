<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class CustomFieldsCategories
{
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
            $description = isset($custom_field['description']) ? $custom_field['description'] : '';
            $attrs = isset($custom_field['attrs']) ? $custom_field['attrs'] : array();
            $filterable = ! empty($custom_field['filter']);
            // $options = isset($custom_field['options']) ? $custom_field['options'] : array();
            $options = [];
            if (isset($custom_field['options'])) {
                $options = \Dashi\Core\Util::resolveOptions($custom_field['options']);
            }
            $label = isset($custom_field['label']) ? $custom_field['label'] : $key;
            $id = 'upload_field_'.$key;
            $attrs['id'] = $id;
            $value = isset($cat_meta[$key]) ? $cat_meta[$key] : '';

            $html.= '<tr class="form-field">';
            $html.= '<th><label for="'.$id.'">'.esc_html($label).'</label></th>';
            $html.= '<td>';

            switch ($custom_field['type'])
            {
                case 'file':
                    $html.= Field::field_file(
                        $key,
                        $value,
                        $description,
                        $attrs,
                        '', //template
                        true
                    );
                    break;
                case 'radio':
                    $html.= Field::field_radio(
                        $key,
                        $value,
                        $options,
                        $description,
                        $attrs,
                        '', //template
                        $filterable
                    );
                    break;
                case 'checkbox':
                    $html.= Field::field_checkbox(
                        $key,
                        maybe_unserialize($value),
                        $options,
                        $description,
                        $attrs,
                        '', //template
                        $filterable
                    );
                    break;
                case 'text':
                    $html.= Field::field_text(
                        $key,
                        $value,
                        $description,
                        $attrs,
                        '', //template
                        true
                    );
                    break;
                default:
                    $html.= 'not supported';
            }
            $html.= '</td></tr>';
        }
        wp_nonce_field(basename(__FILE__), 'term_order_nonce');
        echo wp_kses_post($html);
    }

    /**
     * add custom fields
     *
     * @param object $tag
     * @return void
     */
	    public static function saveHook($term_id)
	    {
	        $term_order_nonce = filter_input(INPUT_POST, 'term_order_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
	        $term_order_nonce = is_string($term_order_nonce) ? sanitize_text_field(wp_unslash($term_order_nonce)) : '';
	        if ($term_order_nonce === '' || !wp_verify_nonce($term_order_nonce, basename(__FILE__))) {
	            return;
	        }

        global $taxonomy;

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
