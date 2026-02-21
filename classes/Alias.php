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
        class_alias('\\Dashi\\Core\\Posttype\\Posttype', 'Dashi\\P');
        class_alias('\\Dashi\\Core\\Posttype\\Posttype', 'Dashi\\Core\\Posttype\\P');

        class_alias('\\Dashi\\Core\\Posttype\\CustomFields', 'Dashi\\C');
        class_alias('\\Dashi\\Core\\Posttype\\CustomFields', 'Dashi\\Core\\Posttype\\C');

        class_alias('\\Dashi\\Core\\Util', 'Dashi\\Util');
        class_alias('\\Dashi\\Core\\Util', 'Dashi\\Core\\Posttype\\Util');

        // lower than php 5.3's class_alias() cannot recognize namespaced class.
        // eval("namespace Dashi;class P extends \\Dashi\\Core\\Posttype\\Posttype { }");
        // eval("namespace Dashi\\Core\\Posttype;class P extends Posttype { }");

        // eval("namespace Dashi;class C extends \\Dashi\\Core\\Posttype\\CustomFields { }");
        // eval("namespace Dashi\\Core\\Posttype;class C extends CustomFields { }");

        // eval("namespace Dashi;class Util extends \\Dashi\\Core\\Util { }");
        // eval("namespace Dashi\\Core\\Posttype;class Util extends \\Dashi\\Core\\Util { }");
    }
}
