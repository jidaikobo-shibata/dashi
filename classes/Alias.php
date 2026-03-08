<?php
namespace Dashi\Core;

class Alias
{
    /**
     * forge
     *
     * @return  void
     */
    public static function forge ()
    {
        static::_init();
    }

    /**
     * _init
     *
     * @return  void
     */
    public static function _init ()
    {
        if (!class_exists('Dashi\\P', false))
        {
            class_alias('\\Dashi\\Core\\Posttype\\Posttype', 'Dashi\\P');
        }
        if (!class_exists('Dashi\\Core\\Posttype\\P', false))
        {
            class_alias('\\Dashi\\Core\\Posttype\\Posttype', 'Dashi\\Core\\Posttype\\P');
        }

        if (!class_exists('Dashi\\C', false))
        {
            class_alias('\\Dashi\\Core\\Posttype\\CustomFields', 'Dashi\\C');
        }
        if (!class_exists('Dashi\\Core\\Posttype\\C', false))
        {
            class_alias('\\Dashi\\Core\\Posttype\\CustomFields', 'Dashi\\Core\\Posttype\\C');
        }

        if (!class_exists('Dashi\\Util', false))
        {
            class_alias('\\Dashi\\Core\\Util', 'Dashi\\Util');
        }
        if (!class_exists('Dashi\\Core\\Posttype\\Util', false))
        {
            class_alias('\\Dashi\\Core\\Util', 'Dashi\\Core\\Posttype\\Util');
        }
    }
}
