<?php
/**
 * Dashi\Core\Security
 */
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

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
	                function () {
	                    if(is_author())
	                    {
	                        wp_safe_redirect(home_url());
	                        exit;
	                    }
	                }
	            );
        }
    }
}
