<?php
namespace Dashi\Core\Posttype;

class SaveCategories
{
	/**
	 * save
	 *
	 * @return Void
	 */
	public static function save($term_id)
	{
		$term = get_term($term_id);
		$taxonomy = $term->taxonomy;
		$taxonomies = P::taxonomies();
		$posttype = P::posttype2class($taxonomies[$taxonomy]);
		if ( ! $posttype) return;
		$custom_fields_taxonomies = $posttype::get('custom_fields_taxonomies');
		$custom_fields = $custom_fields_taxonomies[$taxonomy];

		$cat_meta = array();
		foreach ($custom_fields as $key => $custom_field)
		{
			$val = filter_input(INPUT_POST, $key);
			if ( ! $val) continue;

			$cat_meta = get_option("cat_$term_id");
			$cat_meta[$key] = $val;
		}
		if (empty($cat_meta)) return;

		update_option("cat_$term_id", $cat_meta);
	}
}
