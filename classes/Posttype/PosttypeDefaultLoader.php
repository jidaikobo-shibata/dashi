<?php

namespace Dashi\Core\Posttype;

class PosttypeDefaultLoader
{
    /**
     * プラグイン同梱の既定 post type を読み込んで返す
     *
     * @param array<int, string> $known_classes
     * @param string|null $default_dir
     * @param bool|null $is_pagepart_enabled
     * @return array<int, string>
     */
    public static function load($known_classes, $default_dir = null, $is_pagepart_enabled = null)
    {
        $loaded = [];
        $dir = is_string($default_dir) && $default_dir !== ''
            ? rtrim($default_dir, '/')
            : DASHI_DIR . '/posttype';
        $is_pagepart_enabled = is_null($is_pagepart_enabled)
            ? (bool) get_option('dashi_activate_pagepart')
            : (bool) $is_pagepart_enabled;

        foreach (glob($dir . '/*.php') as $filename)
        {
            $post_type = substr(basename($filename), 0, -4);
            $class = '\\Dashi\\Posttype\\' . ucfirst($post_type);

            if (in_array($class, $known_classes, true) || in_array($class, $loaded, true))
            {
                continue;
            }

            if ($post_type === 'pagepart' && ! $is_pagepart_enabled)
            {
                continue;
            }

            include_once($filename);
            $loaded[] = $class;
        }

        return $loaded;
    }
}
