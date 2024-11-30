<?php
namespace Dashi\Core;

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

		// ディレクトリのパーミッションが開きすぎていないかチェック
		self::checkDirectoryPermission();

		// sitemap.xmlの設置を促す
		// see laterhttps://technote.space/blog/archives/1195
		self::checkSiteMapXml();

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
					echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('directory "%s" permission too open. 705 or 755 is better.', 'dashi'), $dir).'</strong></p></div>';
				});
			}
		}
	}

	/**
	 * checkSiteMapXml
	 *
	 * @return Void
	 */
	private static function checkSiteMapXml()
	{
		if (
			get_option('dashi_no_need_sitemap_plugin') ||
			get_transient('dashi_notation_sitemap_exist')
		) return;

		// redirect loopなどでsitemap.xmlの存在を確認できなくても、
		// XML sitemap プラグインを特別扱いする
		$xmlsf_sitemaps = get_option('xmlsf_sitemaps');
		if (
			! Util::is_url_exists(home_url('sitemap.xml')) &&
			! (isset($xmlsf_sitemaps['sitemap']) && $xmlsf_sitemaps['sitemap'] == 'sitemap.xml')
		)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('sitemap.xml is not exist.', 'dashi').'</strong></p></div>';
			});
			return;
		}

		set_transient('dashi_notation_sitemap_exist', true, 24 * HOUR_IN_SECONDS);
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
			$res = wp_remote_get(
				get_stylesheet_directory_uri().'/index.php',
				array('timeout' => 0, 'sslverify' => false,)
			);

			// $res can be Wp_Error object
			if (is_object($res) || empty($res['body'])) return;

			if (strpos($res['body'], 'get_header') !== false)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('PHP error reporting is on. check <a href="%s">themes file</a>', 'dashi'), get_stylesheet_directory_uri().'/index.php').'</strong></p></div>';
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
			if (Util::is_url_exists(site_url('xmlrpc.php'), 'post'))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('Disallow xmlrpc.php', 'dashi').'</strong></p></div>';
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
			if (Util::is_url_exists(site_url('wp-admin/includes')))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('Disallow directory listing.', 'dashi').'</strong></p></div>';
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
			if (Util::is_url_exists(site_url('wp-config.php')))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('Disallow wp-config.php', 'dashi').'</strong></p></div>';
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
			$res = wp_remote_get(home_url(), array('timeout' => 10, 'sslverify' => false,));

			// $res can be Wp_Error object
			if (is_object($res) || empty($res['body'])) return;

			// check
			if (
				strpos($res['body'], 'analytics.js') === false &&
				strpos($res['body'], 'www.googletagmanager.com/gtag/js?id=UA-') === false
			)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('Google Analytics is not implemented. <a href="%s">See How to.</a>', 'dashi'), site_url('/wp-admin/options-general.php?page=dashi_options&help=seo#help_area')).'</strong></p></div>';
				});
				return;
			}

			set_transient('dashi_notation_check_google_analytics', true, 24 * HOUR_IN_SECONDS);
		}
	}
}
