<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

trait NotationDomain
{
	static $dashi_mails = array();

	/**
	 * @param int $postId
	 * @param string $postTitle
	 * @return string
	 */
	private static function getCf7EditLink($postId, $postTitle)
	{
		$url = site_url('/wp-admin/admin.php?page=wpcf7&post='.$postId.'&action=edit');
		return '<a href="'.esc_url($url).'">'.esc_html($postTitle).'</a>';
	}

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
				if (!self::currentUserCanViewEditorDashboardWidgets()) return;

				wp_add_dashboard_widget (
					'dashi_wpcf7_contact',
					__('Recipients list of Contact Form 7', 'dashi'),
					array('\\Dashi\\Core\\Notation', 'wpcf7ContactList')
				);
			});

			// mail1の送信先、mail2の送信元のドメインが異なっていたら警告を出す
			add_action('admin_init', function ()
			{
				if (!self::currentUserCanViewAdminNotices()) return;

				self::wpcf7ChkDomain();
			});

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
				if (!self::currentUserCanViewAdminNotices()) return;

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
				$http_host = filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_UNSAFE_RAW);
				$http_host = is_string($http_host) ? sanitize_text_field($http_host) : '';
				$host = NotationDomainValidator::resolveComparisonHost(home_url(), $http_host);

			foreach ($wpcf7s as $wpcf7)
		{
			$mails = array();
			$mails[1] = get_post_meta($wpcf7->ID, '_mail', TRUE);
			$mails[2] = get_post_meta($wpcf7->ID, '_mail_2', TRUE);
				$post_title = (string) $wpcf7->post_title;
				$post_link = self::getCf7EditLink((int) $wpcf7->ID, $post_title);

			// mail1 の recipient
			$mail1 = isset($mails[1]) && is_array($mails[1]) ? $mails[1] : array();
			if (isset($mail1['active']) && $mail1['active'] && isset($mail1['recipient']))
			{
				$mail1Recipient = (string) $mail1['recipient'];
			}
			else
			{
				$mail1Recipient = '';
			}

			// mail2 の sender
			$mail2 = isset($mails[2]) && is_array($mails[2]) ? $mails[2] : array();
			if (isset($mail2['active']) && $mail2['active'] && isset($mail2['sender']))
			{
				$mail2Sender = (string) $mail2['sender'];
			}
			else
			{
				$mail2Sender = '';
			}

			if ($mail1Recipient !== '')
			{
					self::chkMail1($host, $mail1Recipient, $post_link);
				}
				if ($mail2Sender !== '')
				{
					self::chkMail2($host, $mail2Sender, $post_link);
				}
			}
		}

	/**
	 * chkMail1
	 *
	 * @param $host string
	 * @param $recipient string
	 * @param $post_link string
	 * @return Void
	 */
	private static function chkMail1($host, $recipient, $post_link)
	{
		$recipient = trim($recipient);
		if ($recipient === '' || $recipient === '[_site_admin_email]')
		{
			return;
		}

		$domains = NotationDomainValidator::extractDomainsFromRecipients($recipient);
		if (!$domains)
		{
			return;
		}

		$mismatches = array();
		$trusted = NotationDomainValidator::trustedRecipientDomains();
		foreach ($domains as $domain)
		{
			if (!in_array($domain, $trusted, true) && !NotationDomainValidator::hostMatchesDomain($host, $domain))
			{
				$mismatches[] = $domain;
			}
		}

		if ($mismatches)
		{
			$detail = implode(', ', $mismatches);
			add_action('admin_notices', function () use ($detail, $post_link)
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>';
				$message = esc_html__('recipient of mail1 of Contact Form 7 is different from this host. check please:', 'dashi');
				$message .= ' '.esc_html($detail).' ['.$post_link.']';
				echo wp_kses($message, array('a' => array('href' => true)));
				echo '</strong> ';
				$url = admin_url('options-general.php?page=dashi_options#' . NotationDomainValidator::TRUSTED_RECIPIENT_DOMAINS);
				echo '<a href="' . esc_url($url) . '">'
					. esc_html__('Open trusted recipient domain settings', 'dashi') . '</a>';
				echo '</p></div>';
			});
		}
	}

	/**
	 * chkMail2
	 *
	 * @param $host string
	 * @param $sender string
	 * @param $post_link string
	 * @return Void
	 */
	private static function chkMail2($host, $sender, $post_link)
	{
		$sender = trim($sender);
		$senderDomainList = NotationDomainValidator::extractDomainsFromRecipients($sender);
		$senderDomain = $senderDomainList ? $senderDomainList[0] : '';

		// mail2の送信元のドメインが異なっていたら警告を出す
		if (
			$senderDomain !== '' &&
			!NotationDomainValidator::hostMatchesDomain($host, $senderDomain)
		)
		{
			add_action('admin_notices', function () use ($senderDomain, $post_link)
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>';
				$message = esc_html__('sender of mail2 of Contact Form 7 is different from this host. check please:', 'dashi');
				$message .= ' '.esc_html($senderDomain).' ['.$post_link.']';
				echo wp_kses($message, array('a' => array('href' => true)));
				echo '</strong>';
				echo '</p></div>';
			});
		}

		// mail2の送信元のにwordpress@を使っていたら警告を出す
		if (NotationDomainValidator::isWordpressSender($sender))
		{
			add_action('admin_notices', function () use ($sender, $post_link)
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>';
				$message = esc_html__('sender of mail2 of Contact Form 7 is using wordpress@. check please:', 'dashi');
				$message .= ' '.esc_html($sender).' ['.$post_link.']';
				echo wp_kses($message, array('a' => array('href' => true)));
				echo '</strong>';
				echo '</p></div>';
			});
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
		if (!self::currentUserCanViewEditorDashboardWidgets()) return;

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
			// メール設定パネルと同じフォーム単位の権限を確認する。
			if (!current_user_can('wpcf7_edit_contact_form', $wpcf7->ID)) continue;

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
		if (!self::currentUserCanViewAdminNotices()) return;

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
