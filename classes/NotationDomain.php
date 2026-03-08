<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

trait NotationDomain
{
	static $dashi_mails = array();

	/**
	 * @param string $hash
	 * @return bool
	 */
	private static function isCf7WarningAcknowledged($hash)
	{
		if (!NotationCf7WarningAcknowledger::isValidHash($hash))
		{
			return false;
		}

		$key = NotationCf7WarningAcknowledger::transientKeyFromHash($hash);
		return (bool) get_transient($key);
	}

	/**
	 * @param string $hash
	 * @return string
	 */
	private static function getCf7WarningAcknowledgeLink($hash)
	{
		if (!NotationCf7WarningAcknowledger::isValidHash($hash))
		{
			return '';
		}

		$url = add_query_arg(
			array(
				'action' => 'dashi_cf7_ack_warning',
				'hash' => $hash,
			),
			admin_url('admin-post.php')
		);
		$url = wp_nonce_url($url, 'dashi_cf7_ack_'.$hash);

		return '<a href="'.esc_url($url).'">'.esc_html__('Confirmed (hide for 30 days)', 'dashi').'</a>';
	}

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
	 * @return void
	 */
	public static function handleCf7WarningAcknowledge()
	{
		if (!current_user_can('manage_options'))
		{
			wp_die(esc_html__('You are not allowed to do this action.', 'dashi'), 403);
		}

		$hash = filter_input(INPUT_GET, 'hash', FILTER_UNSAFE_RAW);
		$hash = is_string($hash) ? sanitize_text_field($hash) : '';
		if (!NotationCf7WarningAcknowledger::isValidHash($hash))
		{
			wp_die(esc_html__('Invalid acknowledge token.', 'dashi'), 400);
		}

		check_admin_referer('dashi_cf7_ack_'.$hash);
		$key = NotationCf7WarningAcknowledger::transientKeyFromHash($hash);
		set_transient($key, 1, NotationCf7WarningAcknowledger::ttl());

		$redirect = wp_get_referer();
		if (!$redirect)
		{
			$redirect = admin_url();
		}

		wp_safe_redirect($redirect);
		exit;
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

			$ackHash = NotationCf7WarningAcknowledger::buildHash(
				(int) $wpcf7->ID,
				$mail1Recipient,
				$mail2Sender
			);
			if (self::isCf7WarningAcknowledged($ackHash))
			{
				continue;
			}

			if ($mail1Recipient !== '')
			{
					self::chkMail1($host, $mail1Recipient, $post_link, $ackHash);
				}
				if ($mail2Sender !== '')
				{
					self::chkMail2($host, $mail2Sender, $post_link, $ackHash);
				}
			}
		}

	/**
	 * chkMail1
	 *
	 * @param $host string
	 * @param $recipient string
	 * @param $post_link string
	 * @param $ackHash string
	 * @return Void
	 */
	private static function chkMail1($host, $recipient, $post_link, $ackHash)
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
		foreach ($domains as $domain)
		{
			if (!NotationDomainValidator::hostMatchesDomain($host, $domain))
			{
				$mismatches[] = $domain;
			}
		}

		if ($mismatches)
		{
			$detail = implode(', ', $mismatches);
			$ackLink = self::getCf7WarningAcknowledgeLink($ackHash);
			add_action('admin_notices', function () use ($detail, $post_link, $ackLink)
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>';
				$message = esc_html__('recipient of mail1 of Contact Form 7 is different from this host. check please:', 'dashi');
				$message .= ' '.esc_html($detail).' ['.$post_link.']';
				echo wp_kses($message, array('a' => array('href' => true)));
				echo '</strong>';
				if ($ackLink) echo ' '.wp_kses_post($ackLink);
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
	 * @param $ackHash string
	 * @return Void
	 */
	private static function chkMail2($host, $sender, $post_link, $ackHash)
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
			$ackLink = self::getCf7WarningAcknowledgeLink($ackHash);
			add_action('admin_notices', function () use ($senderDomain, $post_link, $ackLink)
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>';
				$message = esc_html__('sender of mail2 of Contact Form 7 is different from this host. check please:', 'dashi');
				$message .= ' '.esc_html($senderDomain).' ['.$post_link.']';
				echo wp_kses($message, array('a' => array('href' => true)));
				echo '</strong>';
				if ($ackLink) echo ' '.wp_kses_post($ackLink);
				echo '</p></div>';
			});
		}

		// mail2の送信元のにwordpress@を使っていたら警告を出す
		if (NotationDomainValidator::isWordpressSender($sender))
		{
			$ackLink = self::getCf7WarningAcknowledgeLink($ackHash);
			add_action('admin_notices', function () use ($sender, $post_link, $ackLink)
			{
				echo '<div class="message notice notice-warning dashi_error"><p><strong>';
				$message = esc_html__('sender of mail2 of Contact Form 7 is using wordpress@. check please:', 'dashi');
				$message .= ' '.esc_html($sender).' ['.$post_link.']';
				echo wp_kses($message, array('a' => array('href' => true)));
				echo '</strong>';
				if ($ackLink) echo ' '.wp_kses_post($ackLink);
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
