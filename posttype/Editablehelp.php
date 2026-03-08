<?php
namespace Dashi\Posttype;

class Editablehelp extends \Dashi\Core\Posttype\Base
{
    public static function __init ()
    {
        static::set('name', static::t('posttype.editablehelp.name'));
        static::set('is_searchable', false);
        static::set('is_redirect', true);
        static::set('show_ui', true);
        static::set('is_hidden', true); // メニューからは隠す
        static::set('is_use_force_ascii_slug', true);
        static::set('exclude_from_search', true);
        static::set('is_use_sticky', false);
        static::set('show_in_nav_menus', false);
        static::set('has_archive', false);
        static::set('custom_fields', array(
                'dashi_bind_slug' => array(
                    'type' => 'hidden',
                    'value' => esc_html(\Dashi\Core\Input::get('slug')),
            )));
    }
}
