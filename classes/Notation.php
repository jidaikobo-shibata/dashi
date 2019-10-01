<?php
namespace Dashi\Core;

class Notation
{
	use NotationDomain;
	use NotationInfo;

	/**
	 * forge
	 *
	 * @return Void
	 */
	public static function forge()
	{
		if ( ! is_admin()) return;

		// ダッシュボード判定 - pagenowではマルチサイトで判定できないため
		// global $pagenow;
		// if ($pagenow == 'index.php' && get_option('dashi_do_environmental_check'))
		if (
			isset($_SERVER['SCRIPT_NAME']) &&
			substr($_SERVER['SCRIPT_NAME'], -19) == '/wp-admin/index.php' &&
			get_option('dashi_do_environmental_check')
		)
		{
			// ダッシュボードに記事数を表示する
			add_filter(
				'dashboard_glance_items',
				array('\\Dashi\\Core\\Notation', 'addDashboardGlanceItems')
			);

			// キャッシュが有効かどうかを表示
			self::isCacheAvairable();

			// 検索エンジンに表示しない設定をしていたら警告する
			self::alertIfAvoidSearchEngine();

			// WordPressによるphp編集を許可しない
			self::disallowFileEdit();

			// wp-config.phpがドキュメントルートにある場合パーミッションを確認する
			// 一階層上にある場合は配慮があるとみなす
			self::checkPermissionOfWpconfig();

			// Just another WordPress siteを放置しない
			self::doNotLeaveDefaultDescrition();

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

			// headのソースチェック
			self::recommendHtmlCheck();

			// その他のページの目視チェック
			self::recommendPageCheck();

			// バックアップ体制の確認
			self::checkBackUp();

			// サーバ側アクセスログの有効性チェック
			self::checkAccesslog();

			// 以降プラグインのチェック
			include_once(ABSPATH.'wp-admin/includes/plugin.php');

			// siteguardのインストールを促す
			self::recommendSiteguard();

			// jwp-a11yのインストールを促す
			self::recommendJwpA11y();

			// query monitorのインストールを促す
			self::recommendQueryMonitor();

			// コメントを受け付ける設定のサイトかどうか確認する
			self::checkAllowComment();

			// Hello Worldの削除を促す
			self::deleteHelloWorld();

			// pendingやfutureの記事の一覧を表示
			self::showPendingAndFuture();

			// Contact Form 7
			if (is_plugin_active('contact-form-7/wp-contact-form-7.php'))
			{
				// Contact Form 7が有効な場合宛先を表示する
				add_action('wp_dashboard_setup', function ()
				{
					wp_add_dashboard_widget (
						'dashi_wpcf7_contact',
						__('Recipients list of Contact Form 7', 'dashi'),
						array('\\Dashi\\Core\\Notation', 'wpcf7ContactList')
					);
				});

					// mail1の送信先、mail2の送信元のドメインが異なっていたら警告を出す
				add_action(
					'admin_init',
					array('\\Dashi\\Core\\Notation', 'wpcf7ChkDomain')
				);
			}

			// dashi_public_formが宛先を持っている場合は表示する
			$dashi_mails = array();
			foreach (\Dashi\P::instances() as $v)
			{
				$sendto = $v::get('sendto');
				if ( ! $sendto) continue;
				$posttype_name = $v::get('name');

				$dashi_mails[$posttype_name]['subject'] = $v::get('subject');
				$dashi_mails[$posttype_name]['re_subject'] = $v::get('re_subject');
				$dashi_mails[$posttype_name]['recipient'] = $sendto;
			}
			static::$dashi_mails = $dashi_mails;
			if ($dashi_mails)
			{
				add_action('wp_dashboard_setup', function ()
				{
					wp_add_dashboard_widget (
						'dashi_public_form_contact',
						__('Recipients list of Dashi Public Forms', 'dashi'),
						array('\\Dashi\\Core\\Notation', 'dashiContactList')
					);
				});
			}
		}

	}

	/**
	 * alertIfAvoidSearchEngine
	 *
	 * @return Void
	 */
	private static function alertIfAvoidSearchEngine()
	{
		if ( ! get_option('blog_public'))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('Now avoid to index search engines.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * isCacheAvairable
	 *
	 * @return Void
	 */
	private static function isCacheAvairable()
	{
		if (get_option('dashi_development_diable_field_cache'))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>'.__('now development mode. cache is disabled', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * disallowFileEdit
	 *
	 * @return Void
	 */
	private static function disallowFileEdit()
	{
		if ( ! defined('DISALLOW_FILE_EDIT') || DISALLOW_FILE_EDIT == false)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('Disallow file edit by WordPress. add wp-config.php to <code>define(\'DISALLOW_FILE_EDIT\', true);</code>', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * checkPermissionOfWpconfig
	 *
	 * @return Void
	 */
	private static function checkPermissionOfWpconfig()
	{
		$wp_config_path = ABSPATH.'wp-config.php';
		if (file_exists($wp_config_path) && is_writable($wp_config_path))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('wp-config.php is writable.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * checkDirectoryPermission
	 *
	 * @return Void
	 */
	private static function checkDirectoryPermission()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
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
	}

	/**
	 * checkSiteMapXml
	 *
	 * @return Void
	 */
	private static function checkSiteMapXml()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check') && ! get_option('dashi_no_need_sitemap_plugin'))
		{
			if ( ! get_transient('dashi_notation_sitemap_exist'))
			{
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
				}
				else
				{
					set_transient('dashi_notation_sitemap_exist', true, 24 * HOUR_IN_SECONDS);
				}
			}
		}
	}

	/**
	 * doNotLeaveDefaultDescrition
	 *
	 * @return Void
	 */
	private static function doNotLeaveDefaultDescrition()
	{
		if (strpos(get_option('blogdescription'), 'WordPress') !== false)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('Now suppose to be using "Just another WordPress site" as a description.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * checkDisplayError
	 *
	 * @return Void
	 */
	private static function checkDisplayError()
	{
			if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
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
					if ( ! is_object($res) && $res['body'])
					{
						if (strpos($res['body'], 'get_header') !== false)
						{
							add_action('admin_notices', function ()
							{
								echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('PHP error reporting is on. check <a href="%s">themes file</a>', 'dashi'), get_stylesheet_directory_uri().'/index.php').'</strong></p></div>';
							});
						}
						else
						{
							set_transient('dashi_notation_display_error_exist', true, 24 * HOUR_IN_SECONDS);
						}
					}
				}
			}
	}

	/**
	 * denyXmlrpc
	 *
	 * @return Void
	 */
	private static function denyXmlrpc()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
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
	}

	/**
	 * denyDirectoryListing
	 *
	 * @return Void
	 */
	private static function denyDirectoryListing()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
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
	}

	/**
	 * denyHttpAccess2WpConfig
	 *
	 * @return Void
	 */
	private static function denyHttpAccess2WpConfig()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
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
	}

	/**
	 * recommendSetGoogleAnalytics
	 *
	 * @return Void
	 */
	private static function recommendSetGoogleAnalytics()
	{
		if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
		{
			if (
				! get_transient('dashi_notation_check_google_analytics') &&
				! get_option('dashi_no_need_analytics')
			)
			{
				$res = wp_remote_get(home_url(), array('timeout' => 10, 'sslverify' => false,));

				// $res can be Wp_Error object
				if ( ! is_object($res) && $res['body'])
				{
					if (
						strpos($res['body'], 'analytics.js') === false &&
						strpos($res['body'], 'www.googletagmanager.com/gtag/js?id=UA-') === false
					)
					{
						add_action('admin_notices', function ()
						{
							echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('Google Analytics is not implemented. <a href="%s">See How to.</a>', 'dashi'), site_url('/wp-admin/options-general.php?page=dashi_options&help=seo#help_area')).'</strong></p></div>';
						});
					}
					else
					{
						set_transient('dashi_notation_check_google_analytics', true, 24 * HOUR_IN_SECONDS);
					}
				}
			}
		}
	}

	/**
	 * recommendHtmlCheck
	 *
	 * @return Void
	 */
	private static function recommendHtmlCheck()
	{
		if ( ! get_option('dashi_head_html_is_ok'))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('head html is not checked.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * recommendPageCheck
	 *
	 * @return Void
	 */
	private static function recommendPageCheck()
	{
		if ( ! get_option('dashi_utility_pages_are_ok'))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('utility pages are not checked.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * checkBackUp
	 *
	 * @return Void
	 */
	private static function checkBackUp()
	{
		if ( ! get_option('dashi_backup_is_ok'))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('backup availability is not checked.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * checkAccesslog
	 *
	 * @return Void
	 */
	private static function checkAccesslog()
	{
		if ( ! get_option('dashi_server_accesslog_is_ok'))
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.__('access log availability is not checked.', 'dashi').'</strong></p></div>';
			});
		}
	}

	/**
	 * recommendJwpA11y
	 *
	 * @return Void
	 */
	private static function recommendJwpA11y()
	{
		if (
			! is_plugin_active('jwp-a11y/jwp-a11y.php') &&
			! get_option('dashi_no_need_acc_plugin')
		)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('install %s plugin ex: %s', 'dashi'), 'Accessibility', 'jwp-a11y').'</strong></p></div>';
			});
		}
	}

	/**
	 * recommendQueryMonitor
	 *
	 * @return Void
	 */
	private static function recommendQueryMonitor()
	{
		if (
			! is_plugin_active('query-monitor/query-monitor.php') &&
			! get_option('dashi_no_need_dev_plugin')
		)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('install %s plugin ex: %s', 'dashi'), 'development', 'query monitor').'</strong></p></div>';
			});
		}
	}

	/**
	 * recommendSiteguard
	 *
	 * @return Void
	 */
	private static function recommendSiteguard()
	{
		if (
			! is_plugin_active('siteguard/siteguard.php') &&
			! get_option('dashi_no_need_security_plugin')
		)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('install %s plugin ex: %s', 'dashi'), 'security', 'siteguard').'</strong></p></div>';
			});
		}
	}

	/**
	 * checkAllowComment
	 *
	 * @return Void
	 */
	private static function checkAllowComment()
	{
		if (
			! get_option('dashi_allow_comments') &&
			(
				get_option('default_ping_status') == 'open' ||
				get_option('default_comment_status') == 'open'
			)
		)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('If this site is not allowed comments. check please.', 'dashi'), 'jwp-a11y').'</strong></p></div>';
			});
		}
	}

	/**
	 * showPendingAndFuture
	 *
	 * @return Void
	 */
	private static function showPendingAndFuture()
	{
		add_action('wp_dashboard_setup', function ()
		{
			wp_add_dashboard_widget (
				'dashi_list_unseen_content',
				__('Unseen Contents List', 'dashi'),
				array('\\Dashi\\Core\\Notation', 'unseenContentsList')
			);
		});
	}

	/**
	 * deleteHelloWorld
	 *
	 * @return Void
	 */
	private static function deleteHelloWorld()
	{
		$is_hello = get_post(1);
		if (
			$is_hello &&
			$is_hello->post_status == 'publish' &&
			$is_hello->post_title == 'Hello world!'
		)
		{
			add_action('admin_notices', function ()
			{
				echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('Delete "Hello World!".', 'dashi'), 'jwp-a11y').'</strong></p></div>';
			});
		}
	}
}
