<?php
namespace Dashi\Core;

class Notation
{
	use NotationDomain;
	use NotationInfo;
	use NotationHeavey;

	/**
	 * forge
	 *
	 * @return Void
	 */
	public static function forge()
	{
		// ダッシュボード判定 - pagenowではマルチサイトで判定できないため
		if ( ! is_admin()) return;
		if ( ! get_option('dashi_do_environmental_check')) return;
		if (isset($_SERVER['SCRIPT_NAME']) && substr($_SERVER['SCRIPT_NAME'], -19) != '/wp-admin/index.php') return;

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

		// Contact Form 7 and form domains
		self::chkDomains();
	}

	/**
	 * alertIfAvoidSearchEngine
	 *
	 * @return Void
	 */
	private static function alertIfAvoidSearchEngine()
	{
		if (get_option('blog_public')) return;

		add_action('admin_notices', function ()
		{
			echo '<div class="message error dashi_error"><p><strong>'.__('Now avoid to index search engines.', 'dashi').'</strong></p></div>';
		});
	}

	/**
	 * isCacheAvairable
	 *
	 * @return Void
	 */
	private static function isCacheAvairable()
	{
		if ( ! get_option('dashi_development_diable_field_cache')) return;

		add_action('admin_notices', function ()
		{
			echo '<div class="message notice notice-warning dashi_error"><p><strong>'.__('now development mode. cache is disabled', 'dashi').'</strong></p></div>';
		});
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
			! get_option('dashi_no_need_dev_plugin') &&
			! is_plugin_active('query-monitor/query-monitor.php')
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
			! get_option('dashi_no_need_security_plugin') &&
			! is_plugin_active('siteguard/siteguard.php')
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
				echo '<div class="message error dashi_error"><p><strong>'.__('If this site is not allowed comments. check please.', 'dashi').'</strong></p></div>';
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
