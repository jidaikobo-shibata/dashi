<?php
namespace Dashi\Posttype;

class Pagepart extends \Dashi\Core\Posttype\Base
{
	/*
	 * init
	 */
	public static function __init ()
	{
		// settings
		static::set('name', __('Page Part', 'dashi'));

		static::set('description', __('Page Part can not be displayed by itself.<br />If you describe <code>[get_pagepart slug=slug_name]</code>, page part is called to that place.<br />you can not change the slug created from the shortcode.', 'dashi'));

		static::set('order', 2);

		static::set('is_searchable', true);

		static::set('is_redirect', true);

		static::set('is_use_force_ascii_slug', true);

		static::set('is_use_sticky', false);

		static::set('show_in_nav_menus', false);

		static::set('has_archive', false);

		static::set('custom_fields', array(
				'dashi_bind_slug' => array(
					'type' => 'hidden',
					'value' => esc_html(\Dashi\Core\Input::get('slug'), ''),
			)));

		// shortcode
		add_shortcode("get_pagepart", array('\\Dashi\\Posttype\\Pagepart', 'get_pagepart'));

		// pagepart assets
		add_action(
			'wp_enqueue_scripts',
			function ()
			{
				wp_enqueue_style(
					'dashi_css_pagepart',
					plugins_url('assets/css/pagepart.css', DASHI_FILE)
				);
				wp_enqueue_script(
					'dashi_js_pagepart',
					plugins_url('assets/js/pagepart.js', DASHI_FILE),
					array('jquery')
				);
			}
		);
	}

	/*
	 * get_pagepart
	 */
	public static function get_pagepart($attrs, $content = null)
	{
		global $current_user;

		// musts
		$musts = array(
			'slug' => __('slug'),
		);

		// error
		$errors = array();
		foreach ($musts as $key => $must)
		{
			if (array_key_exists($key, $attrs) && empty($attrs[$key]) || ! isset($attrs[$key]))
			{
				$errors[] = '「'.$musts[$key].'」';
			}
		}

		if ($errors)
		{
			$retval = join($errors).' is missing.<br />';
			$retval.= 'sample: <code>[get_pagepart slug=home]</code>';
			return $retval;
		}

		$slug = esc_html($attrs['slug']);

		$item = get_page_by_path($slug, object, 'pagepart');
		$html = '';
		$html.= '<div class="dashi_pagepart_wrapper">';
		if ($item && $item->post_status=='publish')
		{
			// ignore comment out
			$content = preg_replace("/\<!--[^-]+?--\>/is", '', $item->post_content);

			if ( ! post_password_required($item->ID)):
				$html.= apply_filters('the_content', $content);
			else:
				$html.= get_the_password_form();
			endif;

			if (isset($current_user->roles[0]) && $current_user->roles[0]=='administrator')
			{
				$html.= '<a class="edit_link" href="'.site_url('/wp-admin/post.php?post='.$item->ID.'&action=edit').'">[EDIT "'.$item->post_title.'"]</a>';
			}
		}
		else
		{
			if (isset($current_user->roles[0]) && $current_user->roles[0]=='administrator')
			{
				// 新規作成
				// \Dashi\Hooks::auto_post_slug()に依存
				$html.= '<a class="edit_link" href="'.site_url('/wp-admin/post-new.php?post_type=pagepart').'&amp;slug='.$slug.'">[CREATE "'.$slug.'"]</a>';
			}
		}
		$html.= '</div>';

		return $html;
	}
}