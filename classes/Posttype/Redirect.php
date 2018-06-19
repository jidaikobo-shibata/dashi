<?php
namespace Dashi\Core\Posttype;

class Redirect
{
	/**
	 * redirection for not showings
	 *
	 * @return  void
	 */
	public static function redirect ()
	{
		$post_type = '';

		// singular
		if (is_singular())
		{
			global $post;
			if ( ! isset($post->post_type)) return;
			$post_type = $post->post_type;
		}
		// archive
		elseif (is_post_type_archive())
		{
			global $wp_query;
			if ( ! isset($wp_query->query['post_type'])) return;
			$post_type = $wp_query->query['post_type'];
		}

		// check
		$class = Posttype::getInstance($post_type);
		if ( ! $class) return;

		// redirect to
		$to = '';
		$redirect_to = $class::get('redirect_to');
		if ( ! empty($redirect_to) && is_singular())
		{
			$to = $class::get('redirect_to');
		}
		elseif($class::get('is_redirect'))
		{
			// redirect address
			if (is_singular())
			{
				$to = get_post_meta($post->ID, 'dashi_redirect_to', TRUE) ?: home_url();
			}
			else
			{
				$to = home_url();
			}
		}
		if ( ! $to) return;

		// redirection
		wp_redirect($to, 301);
		exit();
	}
}