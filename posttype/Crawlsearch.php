<?php
namespace Dashi\Posttype;

class Crawlsearch extends \Dashi\Core\Posttype\Base
{
	/*
	 * init
	 */
	public static function __init ()
	{
		static::set('name', __('Crawlsearch'));
		static::set('is_searchable', true);
		static::set('is_redirect', true);
		static::set('is_visible', false);
		static::set('is_use_sticky', false);
		static::set('show_in_nav_menus', false);
		static::set('has_archive', false);
	}
}