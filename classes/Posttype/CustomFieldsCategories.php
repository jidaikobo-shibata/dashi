<?php
namespace Dashi\Core\Posttype;

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

		$html = '';
		foreach ($custom_fields as $key => $custom_field)
		{
			$description = isset($custom_field['description']) ? $custom_field['description'] : '';
			$attrs = isset($custom_field['attrs']) ? $custom_field['attrs'] : array();
			$label = isset($custom_field['label']) ? $custom_field['label'] : $key;
			$id = 'upload_field_'.$key;
			$attrs['id'] = $id;

			$html.= '<tr class="form-field">';
			$html.= '<th><label for="'.$id.'">'.esc_html($label).'</label></th>';
			$html.= '<td>';

			switch ($custom_field['type'])
			{
				case 'file':
					$html.= Field::field_file(
						$key,
						$cat_meta[$key],
						$description,
						$attrs,
						'', //template
						true
					);
					break;
			}
			$html.= '</td></tr>';
		}
		echo $html;
	}

	/**
	 * add custom fields
	 *
	 * @param object $tag
	 * @return void
	 */
	public static function saveHook($term_id)
	{
		global $taxonomy;
		$taxonomies = P::taxonomies();
		if ( ! isset($taxonomies[$taxonomy])) return;

		$posttype = P::posttype2class($taxonomies[$taxonomy]);
		$custom_fields_taxonomies = $posttype::get('custom_fields_taxonomies');
		$cat_key = 'cat_'.$term_id;
		$cat_meta = get_option($cat_key);

		// 空値が来たら削除
		foreach ($cat_meta as $k => $v)
		{
			$val = Input::post($k);
			$val = trim($val);
			if (empty($val))
			{
				delete_option($cat_key);
			}
		}
	}
}
