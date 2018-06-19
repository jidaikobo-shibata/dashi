<?php
namespace Dashi\Core;

class Alias
{
	/**
	 * forge
	 *
	 * @return  void
	 */
	public static function forge () {}

	/**
	 * _init
	 *
	 * @return  void
	 */
	public static function _init ()
	{
		// lower than php 5.3's class_alias() cannot recognize namespaced class.
		eval("namespace Dashi;class P extends \\Dashi\\Core\\Posttype\\Posttype { }");
		eval("namespace Dashi\\Core\\Posttype;class P extends Posttype { }");

		eval("namespace Dashi;class C extends \\Dashi\\Core\\Posttype\\CustomFields { }");
		eval("namespace Dashi\\Core\\Posttype;class C extends CustomFields { }");

		eval("namespace Dashi;class Util extends \\Dashi\\Core\\Util { }");
		eval("namespace Dashi\\Core\\Posttype;class Util extends \\Dashi\\Core\\Util { }");
	}
}
