<?php
namespace Dashi\Core\Posttype;

class Hook
{
	/**
	 * show description
	 *
	 * @return  void
	 */
	public static function showDescription ()
	{
		global $wp_query;

		$query_posttype = isset($wp_query->query['post_type']) ? $wp_query->query['post_type'] : '';
		$current_post_type = esc_html(Input::get('post_type', $query_posttype));
		$current_post_type = $current_post_type ?: get_post_type(intval(Input::get('post')));

		foreach (Posttype::instances() as $posttype)
		{
			if ($current_post_type != $posttype::get('post_type')) continue;
			$description = $posttype::get('description');
			if ( ! $description) continue;
			echo '<div class="notice notice-info is-dismissible"><p>'.$description.'</p></div>';
		}
	}
}
