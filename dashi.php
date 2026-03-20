<?php
/*
Plugin Name: Dashi
Plugin URI: https://wordpress.org/plugins/dashi/
Description: Useful classes for creating a custom post type. When you install it, a custom post type called Page Part is created. There is no GUI, it is for engineers who create theme.
Author: Jidaikobo Inc.
Text Domain: dashi
Domain Path: /languages/
Version: 3.4.7
Author URI: http://www.jidaikobo.com/
thx: https://github.com/trentrichardson/jQuery-Timepicker-Addon/tree/master/src
License: GPL2

Copyright 2026 jidaikobo (email: support@jidaikobo.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
*/

if (!defined('ABSPATH')) exit;

if (defined('WP_INSTALLING') && WP_INSTALLING) return;
if (defined('REST_REQUEST') && REST_REQUEST) return;
if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return;

// Composer のオートローダーを前提にする。
$dashi_composer_autoload = __DIR__.'/vendor/autoload.php';
if (!is_readable($dashi_composer_autoload))
{
    add_action(
        'admin_notices',
        function ()
        {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Dashi requires vendor/autoload.php. Run composer install in the plugin directory.', 'dashi');
            echo '</p></div>';
        }
    );
    return;
}
require_once $dashi_composer_autoload;

// session
add_action('template_redirect', array('\\Dashi\\Core\\Session', 'forge'), 10, 0);

// define
define('DASHI_FILE', __FILE__);
define('DASHI_DIR', __DIR__);

$dashi_upload_dir = wp_upload_dir();
define('DASHI_TMP_UPLOAD_DIR', trailingslashit($dashi_upload_dir['basedir']) . 'dashi_uploads/');

// forge to init
\Dashi\Core\Alias::forge();
\Dashi\Core\Security::forge();
\Dashi\Core\Posttype\Posttype::forge();
\Dashi\Core\Posttype\Revisions::forge();
\Dashi\Core\Posttype\Preview::forge();
\Dashi\Core\Posttype\Another::forge();
\Dashi\Core\Posttype\Copy::forge();
\Dashi\Core\Posttype\PublicForm::forge();
\Dashi\Core\Notation::forge();
\Dashi\Core\Posttype\Csv::forge();

// option menu
add_action(
    'admin_menu',
    function ()
    {
        add_options_page(
            __('Dashi Framework', 'dashi'),
            __('Dashi Framework', 'dashi'),
            'manage_options',
            'dashi_options',
            array('\\Dashi\\Core\\Option', 'setting')
        );
    });

// activation hook
register_activation_hook(
    DASHI_FILE,
    function ($network_wide)
    {
        $update = function ()
        {
            // update option - default on
            foreach (array_keys(\Dashi\Core\Option::getOptions()) as $v)
            {
                if ($v == 'dashi_google_map_api_key') continue;
                if ($v == 'dashi_server_accesslog_is_ok') continue;
                if ($v == 'dashi_backup_is_ok') continue;
                if ($v == 'dashi_allow_comments') continue;
                if ($v == 'dashi_allow_xmlrpc') continue;
                if ($v == 'dashi_keep_ssl_connection') continue;
                if ($v == 'dashi_specify_search_index') continue;
                if ($v == 'dashi_no_need_analytics') continue;
                if ($v == 'dashi_no_need_security_plugin') continue;
                if ($v == 'dashi_do_not_heavy_dashboard_check') continue;
                if ($v == 'dashi_head_html_is_ok') continue;
                if ($v == 'dashi_utility_pages_are_ok') continue;
                if ($v == 'dashi_alert_acl') continue;
                if ($v == 'dashi_alert_fileacl') continue;
                if ($v == 'dashi_sitemap_page_upsidedown') continue;
                if ($v == 'dashi_do_eliminate_utf_separation') continue;
                if ($v == 'dashi_sitemap_home_string') continue;
                update_option($v, 1);
            }
        };

	        if (is_multisite() && $network_wide)
	        {
	            global $wpdb;

	            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- マルチサイト全体の一括更新対象を列挙する。
	            foreach ($wpdb->get_col("SELECT blog_id FROM $wpdb->blogs") as $blog_id) {
	                switch_to_blog($blog_id);
	                $update();
                restore_current_blog();
            }
        } else {
            $update();
        }
    }
);

register_activation_hook(
    DASHI_FILE,
    function () {
        flush_rewrite_rules();
        wp_schedule_event(time(), 'daily', 'dashi_cron_hook');
    }
);

register_deactivation_hook(
    DASHI_FILE,
    function () {
        wp_clear_scheduled_hook('dashi_public_form_gc_hook');
    }
);

// Keep SSL connection
if (get_option('dashi_keep_ssl_connection'))
{
    add_action(
        'template_redirect',
	        function()
	        {
            if (is_ssl()) return;

	            $user_agent = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_UNSAFE_RAW);
	            $user_agent = is_string($user_agent) ? sanitize_text_field($user_agent) : '';
            if (strpos($user_agent, 'GuzzleHttp') !== false) return;

	            $request_uri = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW);
            $request_uri = is_string($request_uri) ? wp_sanitize_redirect($request_uri) : '/';
            if ($request_uri === '') $request_uri = '/';
            if ($request_uri[0] !== '/') $request_uri = '/'.ltrim($request_uri, '/');

            $location = home_url($request_uri, 'https');
            if (!is_string($location) || !wp_http_validate_url($location)) return;

            wp_safe_redirect($location, 301); //Moved Permanently
            exit;
        }
    );
}

// add Shortcode - is_user_logged_in area
add_shortcode(
    'loggedin',
    array('\\Dashi\\Core\\Shortcode', 'is_user_logged_in')
);

// sticky
add_action(
    'post_date_column_status',
    array('\\Dashi\\Core\\Posttype\\Sticky', 'column'),
    2,
    10
);

// add class to administration
// thx http://www.warna.info/archives/2593/
add_filter('admin_body_class',
    function ($admin_body_class)
    {
        global $current_user;
        if ( ! $admin_body_class ) {
            $admin_body_class .= ' ';
        }
        $admin_body_class .= 'role-' . urlencode( $current_user->roles[0] );
        return $admin_body_class;
    }
);

// 管理バーにWordPressのバージョンを表示
if (get_option('dashi_show_wp_version'))
{
    add_action(
        'admin_bar_menu',
        function ($wp_admin_bar)
        {
            $title = sprintf(
                '<span class="ab-icon"></span><span class="ab-label">ver. %s</span>',
                get_bloginfo('version')
            );
            $wp_admin_bar->add_menu(array(
                    'id'    => 'dashi_show_wp_version',
                    'meta'  => array(),
                    'title' => $title,
                    'href'  => admin_url('update-core.php')
                ));
        },
        9999
    );
}

// auto update
if (get_option('dashi_auto_update_core'))
{
    add_filter('allow_major_auto_core_updates', '__return_true');
}

if (get_option('dashi_auto_update_theme'))
{
    add_filter('auto_update_theme', '__return_true');
}

if (get_option('dashi_auto_update_plugin'))
{
    add_filter('auto_update_plugin', '__return_true');
}

if (get_option('dashi_auto_update_language'))
{
    add_filter('auto_update_translation', '__return_true');
}

// avoid wp redirect admin location
if (get_option('dashi_do_eliminate_control_codes'))
{
    add_action(
        'init',
        function () {
            remove_action('template_redirect', 'wp_redirect_admin_locations', 1000);
        }
    );
}
