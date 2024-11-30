<?php
namespace Dashi\Core\Posttype;
class Help
{
	/*
	 * hook
	 *
	 * @param  object $screen
	 * @return  void
	 */
	public static function hook (\WP_Screen $screen)
	{
		// get post_type
		$post_type = '';
		$post_id = false;

		// at add-new
		if (Input::get('post_type'))
		{
			$post_type = esc_html(Input::get('post_type'));
		}
		// at edit
		elseif (Input::get('post'))
		{
			$post_id = intval(Input::get('post'));
			$post_type = get_post_type($post_id);
		}

		// valid post_type?
		if ( ! post_type_exists($post_type)) return;

		// message
		$helpless = __('Help is not exist yet.', 'dashi');

		// help for post_type
		$helps = array();
		$slug4post_type = strtolower('editablehelp-pt-'.$post_type);
		$help4post_type = get_page_by_path($slug4post_type, "OBJECT", 'editablehelp');
		if ($help4post_type)
		{
			$helps['post_type']['title'] = $help4post_type->post_title;
			$helps['post_type']['body'] = apply_filters('the_content', $help4post_type->post_content).'<p><a href="'.admin_url('post.php').'?post='.$help4post_type->ID.'&amp;action=edit">'.__('Edit this Help', 'dashi').'</a></p>';
		}
		else
		{
			$helps['post_type']['title'] = __('Common Help', 'dashi');
			$helps['post_type']['body'] = $helpless.'<p><a href="'.admin_url('post-new.php').'?post_type=editablehelp&slug='.$slug4post_type.'">'.__('Add Help of this post_type', 'dashi').'</a></p>';
		}

		// help for this post
		if ($post_id)
		{
			$slug4id = strtolower('editablehelp-id-'.$post_id);
			$help4id = get_page_by_path($slug4id, "OBJECT", 'editablehelp');
			if ($help4id)
			{
				$helps['id']['title'] = $help4id->post_title;
				$helps['id']['body'] = apply_filters('the_content', $help4id->post_content).'<p><a href="'.admin_url('post.php').'?post='.$help4id->ID.'&amp;action=edit">'.__('Edit this Help', 'dashi').'</a></p>';
			}
			else
			{
				$helps['id']['title'] = __('Individual Help', 'dashi');
				$helps['id']['body'] = $helpless.'<p><a href="'.admin_url('post-new.php').'?post_type=editablehelp&slug='.$slug4id.'">'.__('Add Help of this page', 'dashi').'</a></p>';
			}
		}

		// loop
		foreach ($helps as $key => $help)
		{
			$screen->add_help_tab(array(
					'title' => $help['title'],
					'id' => 'custom-help-'.$key,
					'content' => $help['body'],
					'callback' => false,
				));
		}
	}
}