<?php
/**
 * Dashi\Core\Security
 */
namespace Dashi\Core;

class Security
{
	/**
	 * forge
	 *
	 * @return Void
	 */
	public static function forge()
	{
		// ?author=Nを表示しない
		if (get_option('dashi_disactivate_author_page'))
		{
			add_action(
				'template_redirect',
				array('\\Dashi\\Core\\Security', 'nonAuthorPageToGuest')
			);
		}
	}

	/**
	 * nonAuthorPageToGuest
	 *
	 * @return Void
	 */
	public static function nonAuthorPageToGuest()
	{
		global $wp_query;
		if (
			(isset($wp_query->query['author_name']) || isset($_GET['author'])) &&
			! is_user_logged_in()
		)
		{
			wp_redirect(home_url(), 403);
			exit();
		}
	}
}
