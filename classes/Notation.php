<?php
namespace Dashi\Core;

class Notation
{
	static $dashi_mails = array();

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
			$dashi_development_diable_field_cache = get_option('dashi_development_diable_field_cache');
			if ($dashi_development_diable_field_cache)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message notice notice-warning dashi_error"><p><strong>'.__('now development mode. cache is disabled', 'dashi').'</strong></p></div>';
				});
			}

			// 検索エンジンに表示しない設定をしていたら警告する
			if ( ! get_option('blog_public'))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('Now avoid to index search engines.', 'dashi').'</strong></p></div>';
				});
			}

			// WordPressによるphp編集を許可しない
			if ( ! defined('DISALLOW_FILE_EDIT') || DISALLOW_FILE_EDIT == false)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('Disallow file edit by WordPress. add wp-config.php to <code>define(\'DISALLOW_FILE_EDIT\', true);</code>', 'dashi').'</strong></p></div>';
				});
			}

			// wp-config.phpがドキュメントルートにある場合パーミッションを確認する
			// 一階層上にある場合は配慮があるとみなす
			$wp_config_path = ABSPATH.'wp-config.php';
			if (file_exists($wp_config_path) && is_writable($wp_config_path))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('wp-config.php is writable.', 'dashi').'</strong></p></div>';
				});
			}

			// Just another WordPress siteを放置しない
			if (strpos(get_option('blogdescription'), 'WordPress') !== false)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('Now suppose to be using "Just another WordPress site" as a description.', 'dashi').'</strong></p></div>';
				});
			}

			// ディレクトリのパーミッションが開きすぎていないかチェック
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

			// sitemap.xmlの設置を促す
			// see laterhttps://technote.space/blog/archives/1195
			if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
			{
				if ( ! get_transient('dashi_notation_sitemap_exist'))
				{
					// redirect loopなどでsitemap.xmlの存在を確認できなくても、
					// XML sitemap プラグインを特別扱いする
					$xmlsf_sitemaps = get_option('xmlsf_sitemaps');
					if (
						! Util::is_url_exists(home_url('sitemap.xml')) &&
						! (isset($xmlsf_sitemaps['sitemap']) && $xmlsf_sitemaps['sitemap'] == 'sitemap.xml') &&
						! get_option('dashi_no_need_sitemap_plugin')
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

			// themes/XXX/index.phpでエラー表示を確認する
//			if ( ! get_option('dashi_do_not_heavy_dashboard_check'))
			if (1)
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

			// xmlrpc.phpを拒否する
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

			// ディレクトリリスティングを拒否する
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

			// wp-config.phpへのhttpアクセスを拒否する
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

			// Google Analyticsの設置を促す
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

			// headのソースチェック
			if ( ! get_option('dashi_head_html_is_ok'))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('head html is not checked.', 'dashi').'</strong></p></div>';
				});
			}

			// その他のページの目視チェック
			if ( ! get_option('dashi_utility_pages_are_ok'))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('utility pages are not checked.', 'dashi').'</strong></p></div>';
				});
			}

			// バックアップ体制の確認
			if ( ! get_option('dashi_backup_is_ok'))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('backup availability is not checked.', 'dashi').'</strong></p></div>';
				});
			}

			// サーバ側アクセスログの有効性チェック
			if ( ! get_option('dashi_server_accesslog_is_ok'))
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.__('access log availability is not checked.', 'dashi').'</strong></p></div>';
				});
			}

			// 以降プラグインのチェック
			include_once(ABSPATH.'wp-admin/includes/plugin.php');

			// siteguardのインストールを促す
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

			// jwp-a11yのインストールを促す
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

			// query monitorのインストールを促す
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

			// WP Multibyte Patchの有効化を促す
			if (
				get_bloginfo('language') != 'en' &&
				! is_plugin_active('wp-multibyte-patch/wp-multibyte-patch.php')
			)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('install plugin %s', 'dashi'), 'WP Multibyte Patch').'</strong></p></div>';
				});
			}

			// XML Sitemap & Google News feedsのインストールを促す
			// 別途sitemap.xmlがあるならスルーする
			if (
				! is_plugin_active('xml-sitemap-feed/xml-sitemap.php') &&
				! get_transient('dashi_notation_sitemap_exist')
			)
			{
				add_action('admin_notices', function ()
				{
					echo '<div class="message error dashi_error"><p><strong>'.sprintf(__('install %s plugin ex: %s', 'dashi'), 'sitemap.xml', 'XML Sitemap & Google News feeds').'</strong></p></div>';
				});
			}

			// コメントを受け付ける設定のサイトかどうか確認する
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

			// Hello Worldの削除を促す
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

			// pendingやfutureの記事の一覧を表示
			add_action('wp_dashboard_setup', function ()
			{
				wp_add_dashboard_widget (
					'dashi_list_unseen_content',
					__('Unseen Contents List', 'dashi'),
					array('\\Dashi\\Core\\Notation', 'unseenContentsList')
				);
			});

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
	 * wpcf7ChkDomain
	 * contactform7 の宛先がドメインと異なっていたら警告する
	 *
	 * @return Void
	 */
	public static function wpcf7ChkDomain()
	{
		$wpcf7s = get_posts('post_type=wpcf7_contact_form');
		$host = isset($_SERVER['HTTP_HOST']) ? esc_html($_SERVER['HTTP_HOST']) : '';

		foreach ($wpcf7s as $wpcf7)
		{
			$mails = array();
			$mails[1] = get_post_meta($wpcf7->ID, '_mail', TRUE);
			$mails[2] = get_post_meta($wpcf7->ID, '_mail_2', TRUE);
			$post_title = '<a href="'.site_url('/wp-admin/admin.php?page=wpcf7&post='.$wpcf7->ID.'&action=edit').'">'.esc_html($wpcf7->post_title).'</a>';

			// attrs
			foreach ($mails as $mailnum => $mail)
			{
				if ( ! is_array($mail)) continue;
				if ( ! isset($mail['active']) || ! $mail['active']) continue;
				foreach ($mail as $k => $v)
				{
					$v = trim(substr($v, strpos($v, '@') + 1), '>');

					// mail1の送信先、mail2の送信元のドメインが異なっていたら警告を出す
					if (
						$mailnum == 1 &&
						$k == 'recipient' &&
						strpos($host, $v) === false
					)
					{
						add_action('admin_notices', function () use ($v, $post_title)
						{
							echo '<div class="message notice notice-warning dashi_error"><p><strong>'.sprintf(__('recipient of mail1 of Contact Form 7 is different from this host. check please: %s [%s]', 'dashi'), $v, $post_title).'</strong></p></div>';
						});
					}

					// mail2
					if (
						$mailnum == 2 &&
						$k == 'sender'
					)
					{
						// mail2の送信元のドメインが異なっていたら警告を出す
						if (strpos($host, $v) === false)
						{
							add_action('admin_notices', function () use ($v, $post_title)
							{
								echo '<div class="message notice notice-warning dashi_error"><p><strong>'.sprintf(__('sender of mail2 of Contact Form 7 is different from this host. check please: %s [%s]', 'dashi'), $v, $post_title).'</strong></p></div>';
							});
						}

						// mail2の送信元のにwordpress@を使っていたら警告を出す
						if (strpos($v, 'wordpress@') !== false)
						{
							add_action('admin_notices', function () use ($v, $post_title)
							{
								echo '<div class="message notice notice-warning dashi_error"><p><strong>'.sprintf(__('sender of mail2 of Contact Form 7 is using wordpress@. check please: %s [%s]', 'dashi'), $v, $post_title).'</strong></p></div>';
							});
						}

					}

				}
			}
		}
	}

	/**
	 * wpcf7ContactList
	 * contactform7 の宛先を表示するダッシュボードウィジェット
	 *
	 * @return Void
	 */
	public static function wpcf7ContactList()
	{
		$wpcf7s = get_posts('post_type=wpcf7_contact_form');
		$html = '';
		$html.= '<dl>';
		foreach ($wpcf7s as $wpcf7)
		{
			// post_meta
			$mails = array();
			$mails[1] = get_post_meta($wpcf7->ID, '_mail', TRUE);
			$mails[2] = get_post_meta($wpcf7->ID, '_mail_2', TRUE);

			// html
			$html.= '<dt><a href="'.site_url('/wp-admin/admin.php?page=wpcf7&post='.$wpcf7->ID.'&action=edit').'">'.esc_html($wpcf7->post_title).'</a></dt>';

			// attrs
			foreach ($mails as $mailnum => $mail)
			{
				if ( ! is_array($mail)) continue;
				if ( ! isset($mail['active']) || ! $mail['active']) continue;
				$html.= '<dd style="margin: 0;"><table class="dashi_tbl">';
				$html.= '<caption>mail '.$mailnum.'</caption>';
				foreach ($mail as $k => $v)
				{
					if ( ! in_array($k, array('subject', 'sender', 'recipient', 'additional_headers'))) continue;
					$html.= '<tr><th style="white-space: nowrap;">'.__($k, 'dashi').'</th><td>'.esc_html($v).'</td></tr>';
				}
				$html.= '</table></dd>';
			}
		}
		$html.= '</dl>';

		echo $html;
	}

	/**
	 * dashiContactList
	 * dashi_public_formの宛先を表示するダッシュボードウィジェット
	 *
	 * @return Void
	 */
	public static function dashiContactList()
	{
		$html = '';
		$html.= '<dl>';
		foreach (static::$dashi_mails as $posttype_name => $mails)
		{
			// html
			$html.= '<dt>'.esc_html($posttype_name).'</dt>';

			// attrs
			if ( ! is_array($mails)) continue;
			$html.= '<dd style="margin:0;"><table class="dashi_tbl">';
			foreach ($mails as $k => $v)
			{
				if ( ! in_array($k, array('subject', 're_subject', 'recipient'))) continue;
				$html.= '<tr><th style="white-space: nowrap;">'.__($k, 'dashi').'</th><td>'.esc_html($v).'</td></tr>';
			}
			$html.= '</table></dd>';
		}

		$html.= '</dl>';
		echo $html;
	}

	/**
	 * addDashboardGlanceItems
	 * ダッシュボードの概要欄に任意のポストタイプを追加
	 *
	 * @return Array
	 */
	public static function addDashboardGlanceItems($args)
	{
		foreach (\Dashi\P::instances() as $v)
		{
			$posttype = \Dashi\P::class2posttype($v);
			if (in_array($posttype, array('post', 'page'))) continue;

			$obj = get_post_type_object($posttype);
			if (is_object($obj) && ! $obj->show_in_nav_menus) continue;

			$num = wp_count_posts($posttype);
			if (is_object($num) && isset($num->publish) && $num->publish)
			{
				$label = $obj->label;
				$str = $label.'&nbsp;('.number_format_i18n( $num->publish ).')';
				$args[] = '<a href="edit.php?post_type='.$posttype.'" class="'.$posttype.'-count">'.$str.'</a>';
			}
		}
		return $args;
	}

	/**
	 * unseenContentsList
	 * ダッシュボードのpendingやfutureの記事の一覧を表示
	 *
	 * @return Void
	 */
	public static function unseenContentsList()
	{
		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'future',
		);
		$future = get_posts($args);

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'pending',
		);
		$pending = get_posts($args);

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'draft',
		);
		$draft = get_posts($args);

		$args = array(
			'posts_per_page' => -1,
			'post_type' => 'any',
			'post_status' => 'private',
		);
		$private = get_posts($args);

		$posts = array_merge($future, $pending, $draft, $private);

		if ( ! $posts) return;

		$html = '';
		$html.= '<table class="dashi_tbl">';
		$html.= '<thead>';
		$html.= '<tr>';
		$html.= '<th class="nowrap">'.__('Title').'</th>';
		$html.= '<th class="nowrap">'.__('Post Type').'</th>';
		$html.= '<th class="nowrap">'.__('Status').'</th>';
		$html.= '</tr>';
		$html.= '</thead>';
		foreach ($posts as $v)
		{
			$class = \Dashi\Core\Posttype\Posttype::posttype2class($v->post_type);

			$html.= '<tr>';
			$edit_str = $v->post_title ? esc_html($v->post_title) : __('(no title)');
			$html.= '<th><a href="'.get_edit_post_link($v->ID).'">'.$edit_str.'</a></th>';

			if (in_array($v->post_type, array('post', 'page')))
			{
				$link =
							$v->post_type == 'post' ?
							admin_url('edit.php') :
							admin_url('edit.php?post_type=page') ;

				$html.= '<td class="nowrap"><a href="'.$link.'">'.__(ucfirst($v->post_type)).'</a></td>';
			}
			else
			{
				$html.= '<td class="nowrap"><a href="'.admin_url('edit.php?post_type='.$v->post_type).'">'.$class::get('name').'</a></td>';
			}
			$html.= '<td class="nowrap">'.__($v->post_status.' item', 'dashi').'</td>';
			$html.= '</tr>';
		}
		$html.= '</table>';
		echo $html;
	}

	/**
	 * env check - prepare
	 *
	 * @return Void
	 */
	// public static function ajax()
	// {
	// 	echo 10;
	// }
}
