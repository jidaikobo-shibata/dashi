<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

trait NotationDomain
{
	static $dashi_mails = array();

	/**
	 * chkDomains
	 *
	 * @return Void
	 */
	public static function chkDomains()
	{
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
		foreach (\Dashi\Core\Posttype\Posttype::instances() as $v)
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

	/**
	 * wpcf7ChkDomain
	 * contactform7 の宛先がドメインと異なっていたら警告する
	 *
	 * @return Void
	 */
		public static function wpcf7ChkDomain()
		{
			$wpcf7s = get_posts('post_type=wpcf7_contact_form');
			$host = filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_UNSAFE_RAW);
			$host = is_string($host) ? sanitize_text_field($host) : '';

		foreach ($wpcf7s as $wpcf7)
		{
			$mails = array();
			$mails[1] = get_post_meta($wpcf7->ID, '_mail', TRUE);
			$mails[2] = get_post_meta($wpcf7->ID, '_mail_2', TRUE);
				$post_title = esc_html($wpcf7->post_title);

			// attrs
			foreach ($mails as $mailnum => $mail)
			{
				if ( ! is_array($mail)) continue;
				if ( ! isset($mail['active']) || ! $mail['active']) continue;
				foreach ($mail as $k => $v)
				{
					$v = trim(substr($v, strpos($v, '@') + 1), '>');

					// mail1の送信先、mail2の送信元のドメインが異なっていたら警告を出す
					self::chkMail1($mailnum, $k, $host, $v, $post_title);

					// mail2
					self::chkMail2($mailnum, $k, $host, $v, $post_title);
				}
			}
		}
	}

	/**
	 * chkMail1
	 *
	 * @param $mailnum integer
	 * @param $k string
	 * @param $host string
	 * @param $v string
	 * @param $post_title string
	 * @return Void
	 */
	private static function chkMail1($mailnum, $k, $host, $v, $post_title)
	{
		if ($mailnum == 1 && $k == 'recipient' && $v == '_site_admin_email]') return;
		if (
			($mailnum == 1 && $k == 'recipient' && empty($v)) ||
			($mailnum == 1 && $k == 'recipient' && strpos($host, $v) === false)
		)
		{
				add_action('admin_notices', function () use ($v, $post_title)
				{
					/* translators: 1: recipient setting, 2: CF7 post title. */
					echo '<div class="message notice notice-warning dashi_error"><p><strong>'.sprintf(esc_html__('recipient of mail1 of Contact Form 7 is different from this host. check please: %1$s [%2$s]', 'dashi'), esc_html($v), esc_html($post_title)).'</strong></p></div>';
				});
		}
	}

	/**
	 * chkMail2
	 *
	 * @param $mailnum integer
	 * @param $k string
	 * @param $host string
	 * @param $v string
	 * @param $post_title string
	 * @return Void
	 */
	private static function chkMail2($mailnum, $k, $host, $v, $post_title)
	{
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
						/* translators: 1: sender setting, 2: CF7 post title. */
						echo '<div class="message notice notice-warning dashi_error"><p><strong>'.sprintf(esc_html__('sender of mail2 of Contact Form 7 is different from this host. check please: %1$s [%2$s]', 'dashi'), esc_html($v), esc_html($post_title)).'</strong></p></div>';
					});
			}

			// mail2の送信元のにwordpress@を使っていたら警告を出す
			if (strpos($v, 'wordpress@') !== false)
			{
					add_action('admin_notices', function () use ($v, $post_title)
					{
						/* translators: 1: sender setting, 2: CF7 post title. */
						echo '<div class="message notice notice-warning dashi_error"><p><strong>'.sprintf(esc_html__('sender of mail2 of Contact Form 7 is using wordpress@. check please: %1$s [%2$s]', 'dashi'), esc_html($v), esc_html($post_title)).'</strong></p></div>';
					});
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
			$mail_item_labels = array(
				'subject'            => __('subject', 'dashi'),
				'sender'             => __('sender', 'dashi'),
				'recipient'          => __('recipient', 'dashi'),
				'additional_headers' => __('additional_headers', 'dashi'),
			);
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
						$label = isset($mail_item_labels[$k]) ? $mail_item_labels[$k] : $k;
						$html.= '<tr><th style="white-space: nowrap;">'.esc_html($label).'</th><td>'.esc_html($v).'</td></tr>';
					}
				$html.= '</table></dd>';
			}
		}
		$html.= '</dl>';

			echo wp_kses_post($html);
	}

	/**
	 * dashiContactList
	 * dashi_public_formの宛先を表示するダッシュボードウィジェット
	 *
	 * @return Void
	 */
		public static function dashiContactList()
		{
			$mail_item_labels = array(
				'subject'    => __('subject', 'dashi'),
				're_subject' => __('re_subject', 'dashi'),
				'recipient'  => __('recipient', 'dashi'),
			);
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
					$label = isset($mail_item_labels[$k]) ? $mail_item_labels[$k] : $k;
					$html.= '<tr><th style="white-space: nowrap;">'.esc_html($label).'</th><td>'.esc_html($v).'</td></tr>';
				}
			$html.= '</table></dd>';
		}

		$html.= '</dl>';
			echo wp_kses_post($html);
		}
}
