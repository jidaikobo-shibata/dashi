<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

trait NotationHeavey
{
	/**
	 * forge
	 *
	 * @return Void
	 */
	public static function heavyCheck()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check')) return;
		if ( ! Notation::currentUserCanViewAdminNotices()) return;

		// ディレクトリのパーミッションが開きすぎていないかチェック
		self::checkDirectoryPermission();

		// themes/XXX/index.phpでエラー表示を確認する
		self::checkDisplayError();

		// xmlrpc.phpを拒否する
		self::denyXmlrpc();

		// ディレクトリリスティングを拒否する
		self::denyDirectoryListing();

		// wp-config.phpへのhttpアクセスを拒否する
		self::denyHttpAccess2WpConfig();

		// Google Analyticsの設置を促す
		self::recommendSetGoogleAnalytics();
	}

	/**
	 * checkDirectoryPermission
	 *
	 * @return Void
	 */
	private static function checkDirectoryPermission()
	{
		$dirs = array(
			'wp-admin',
			'wp-content',
			'wp-includes',
		);
		foreach ($dirs as $dir)
		{
			if (
				substr(sprintf('%o', fileperms(ABSPATH.$dir)), -4) == '777' ||
				substr(sprintf('%o', fileperms(ABSPATH.$dir)), -4) == '666'
			)
			{
					add_action('admin_notices', function () use ($dir)
					{
						/* translators: %s: directory name. */
						echo '<div class="message error dashi_error"><p><strong>'.esc_html(sprintf(__('directory "%s" permission too open. 705 or 755 is better.', 'dashi'), $dir)).'</strong></p></div>';
					});
				}
			}
	}

	/**
	 * checkDisplayError
	 *
	 * @return Void
	 */
	private static function checkDisplayError()
	{
		if (
			! get_transient('dashi_notation_display_error_exist') &&
			file_exists(get_stylesheet_directory().'/index.php')
		)
		{
            $body = self::diagnosticBody(get_stylesheet_directory_uri() . '/index.php');
            if ($body === null) return;
			if (strpos($body, 'get_header') !== false)
			{
					add_action('admin_notices', function ()
					{
						/* translators: %s: theme index.php URL. */
						$link = sprintf(
							/* translators: %s: theme index.php URL. */
							__('PHP error reporting is on. check <a href="%s">themes file</a>', 'dashi'),
							esc_url(get_stylesheet_directory_uri().'/index.php')
						);
						echo '<div class="message error dashi_error"><p><strong>'.wp_kses_post($link).'</strong></p></div>';
					});
				return;
			}

			set_transient('dashi_notation_display_error_exist', true, 24 * HOUR_IN_SECONDS);
		}
	}

	/**
	 * denyXmlrpc
	 *
	 * @return Void
	 */
	private static function denyXmlrpc()
	{
		if ( ! get_option('dashi_allow_xmlrpc') || ! get_transient('dashi_notation_xmlrpc_denied'))
		{
			$result = Util::is_url_exists(site_url('xmlrpc.php'), 'post');
            if ($result === null) {
                self::unknownDiagnostic();
                return;
            }
            if ($result)
			{
					add_action('admin_notices', function ()
					{
						echo '<div class="message error dashi_error"><p><strong>'.esc_html__('Disallow xmlrpc.php', 'dashi').'</strong></p></div>';
					});
			}
			else
			{
				set_transient('dashi_notation_xmlrpc_denied', true, 24 * HOUR_IN_SECONDS);
			}
		}
	}

	/**
	 * denyDirectoryListing
	 *
	 * @return Void
	 */
	private static function denyDirectoryListing()
	{
		if ( ! get_transient('dashi_notation_directory_listing_denied'))
		{
			$result = Util::is_url_exists(site_url('wp-admin/includes'));
            if ($result === null) {
                self::unknownDiagnostic();
                return;
            }
            if ($result)
			{
					add_action('admin_notices', function ()
					{
						echo '<div class="message error dashi_error"><p><strong>'.esc_html__('Disallow directory listing.', 'dashi').'</strong></p></div>';
					});
			}
			else
			{
				set_transient('dashi_notation_directory_listing_denied', true, 24 * HOUR_IN_SECONDS);
			}
		}
	}

	/**
	 * denyHttpAccess2WpConfig
	 *
	 * @return Void
	 */
	private static function denyHttpAccess2WpConfig()
	{
		if ( ! get_transient('dashi_notation_http_wpconfig_denied'))
		{
			$result = Util::is_url_exists(site_url('wp-config.php'));
            if ($result === null) {
                self::unknownDiagnostic();
                return;
            }
            if ($result)
			{
					add_action('admin_notices', function ()
					{
						echo '<div class="message error dashi_error"><p><strong>'.esc_html__('Disallow wp-config.php', 'dashi').'</strong></p></div>';
					});
			}
			else
			{
				set_transient('dashi_notation_http_wpconfig_denied', true, 24 * HOUR_IN_SECONDS);
			}
		}
	}

	/**
	 * recommendSetGoogleAnalytics
	 *
	 * @return Void
	 */
	private static function recommendSetGoogleAnalytics()
	{
		if (
			! get_transient('dashi_notation_check_google_analytics') &&
			! get_option('dashi_no_need_analytics')
		)
		{
            $body = self::diagnosticBody(home_url());
            if ($body === null) return;
			// check
			if (
				strpos($body, 'analytics.js') === false &&
				strpos($body, 'www.googletagmanager.com/gtag/js?id=UA-') === false
			)
			{
					add_action('admin_notices', function ()
					{
						/* translators: %s: help page URL. */
						$link = sprintf(
							/* translators: %s: help page URL. */
							__('Google Analytics is not implemented. <a href="%s">See How to.</a>', 'dashi'),
							esc_url(site_url('/wp-admin/options-general.php?page=dashi_options&help=seo#help_area'))
						);
						echo '<div class="message error dashi_error"><p><strong>'.wp_kses_post($link).'</strong></p></div>';
					});
				return;
			}

			set_transient('dashi_notation_check_google_analytics', true, 24 * HOUR_IN_SECONDS);
		}
	}
    private static function diagnosticBody($url)
    {
        $response = Util::diagnosticGet($url);
        $status = (int) wp_remote_retrieve_response_code($response);
        if (is_wp_error($response) || $status < 200 || $status >= 300) {
            self::unknownDiagnostic();
            return null;
        }
        return wp_remote_retrieve_body($response);
    }

    private static function unknownDiagnostic()
    {
        static $scheduled = false;
        if ($scheduled) return;
        $scheduled = true;
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('Some Dashi checks could not be completed. Check the site address and HTTPS configuration.', 'dashi')
                . '</p></div>';
        });
    }

}
