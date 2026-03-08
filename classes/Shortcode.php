<?php
/**
 * Dashi\Core\Shortcode
 */
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

class Shortcode
{
	protected static $values = array();

	/**
	 * is_user_logged_in
	 *
	 * @return  mixed
	 */
	public static function is_user_logged_in ($params, $content = null)
	{
		if (is_user_logged_in())
		{
			return $content;
		}
		elseif (isset($params['message']))
		{
			return $params['message'];
		}
		return;
	}

	/**
	 * option
	 *
	 * @return null
	 */
	public static function option ()
	{
?>
*is_user_logged_in
[loggedin]...[/loggedin]

*show sitemap
[dashi_sitemap[h=h2]]
<?php echo esc_html__('You may use \\Dashi\\Core\\Posttype\\Sitemap::generate() in your script.', 'dashi') ?>


*public form
[dashi_public_form form=[posttype]]
<?php echo esc_html__('dashi_public_form provides adding content form to guests.', 'dashi') ?>

<?php
	}
}
