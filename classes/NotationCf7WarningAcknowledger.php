<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

class NotationCf7WarningAcknowledger
{
	/**
	 * @param int $postId
	 * @param string $mail1Recipient
	 * @param string $mail2Sender
	 * @return string
	 */
	public static function buildHash($postId, $mail1Recipient, $mail2Sender)
	{
		$normalizedPostId = max(0, (int) $postId);
		$normalizedMail1 = static::normalizeValue($mail1Recipient);
		$normalizedMail2 = static::normalizeValue($mail2Sender);

		return sha1($normalizedPostId.'|'.$normalizedMail1.'|'.$normalizedMail2);
	}

	/**
	 * @param string $hash
	 * @return bool
	 */
	public static function isValidHash($hash)
	{
		return is_string($hash) && (bool) preg_match('/^[a-f0-9]{40}$/', $hash);
	}

	/**
	 * @param string $hash
	 * @return string
	 */
	public static function transientKeyFromHash($hash)
	{
		return 'dashi_cf7_ack_'.$hash;
	}

	/**
	 * @return int
	 */
	public static function ttl()
	{
		if (defined('DAY_IN_SECONDS')) return 30 * DAY_IN_SECONDS;
		return 60 * 60 * 24 * 30;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	private static function normalizeValue($value)
	{
		$value = strtolower(trim((string) $value));
		$value = preg_replace('/\s+/', ' ', $value);
		return is_string($value) ? $value : '';
	}
}
