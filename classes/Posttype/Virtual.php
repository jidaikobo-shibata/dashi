<?php

namespace Dashi\Core\Posttype;

class Virtual extends Base
{
    public static function __init()
    {
        static::set('is_dashi', false);
    }
}
