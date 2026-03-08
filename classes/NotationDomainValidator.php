<?php
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

class NotationDomainValidator
{
	/**
	 * 比較用ホストを決定する（home_url 優先、HTTP_HOST フォールバック）
	 *
	 * @param string $homeUrl
	 * @param string $httpHost
	 * @return string
	 */
	public static function resolveComparisonHost($homeUrl, $httpHost = '')
	{
		$homeHost = '';
		if (is_string($homeUrl) && $homeUrl !== '')
		{
			$parsedHost = wp_parse_url($homeUrl, PHP_URL_HOST);
			$homeHost = is_string($parsedHost) ? $parsedHost : '';
		}

		$homeHost = static::normalizeHost($homeHost);
		if ($homeHost !== '')
		{
			return $homeHost;
		}

		return static::normalizeHost($httpHost);
	}

	/**
	 * @param string $host
	 * @return string
	 */
	public static function normalizeHost($host)
	{
		$host = strtolower(trim((string) $host));
		$host = trim($host, '.');

		// IPv6 の角括弧を除去
		if (strlen($host) > 2 && $host[0] === '[' && substr($host, -1) === ']')
		{
			$host = substr($host, 1, -1);
		}

		// host:port を分離（IPv6 は除外）
		if (strpos($host, ':') !== false && substr_count($host, ':') === 1)
		{
			$parts = explode(':', $host, 2);
			$host = $parts[0];
		}

		return $host;
	}

	/**
	 * @param string $host
	 * @param string $domain
	 * @return bool
	 */
	public static function hostMatchesDomain($host, $domain)
	{
		$host = static::normalizeHost($host);
		$domain = static::normalizeHost($domain);
		if ($host === '' || $domain === '')
		{
			return false;
		}

		if ($host === $domain)
		{
			return true;
		}

		// www.example.com と example.com のような関係を許容
		if (substr($host, -strlen('.'.$domain)) === '.'.$domain)
		{
			return true;
		}
		if (substr($domain, -strlen('.'.$host)) === '.'.$host)
		{
			return true;
		}

		return false;
	}

	/**
	 * @param string $token
	 * @return string
	 */
	public static function extractEmailFromToken($token)
	{
		$token = trim((string) $token);
		if ($token === '')
		{
			return '';
		}

		if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $token, $matches))
		{
			return strtolower($matches[0]);
		}

		return '';
	}

	/**
	 * @param string $recipientField
	 * @return array<int, string>
	 */
	public static function extractDomainsFromRecipients($recipientField)
	{
		$domains = array();
		$tokens = preg_split('/[\r\n,]+/', (string) $recipientField);
		if (!is_array($tokens))
		{
			return $domains;
		}

		foreach ($tokens as $token)
		{
			$email = static::extractEmailFromToken((string) $token);
			if ($email === '')
			{
				continue;
			}

			$pos = strrpos($email, '@');
			if ($pos === false)
			{
				continue;
			}

			$domain = static::normalizeHost(substr($email, $pos + 1));
			if ($domain === '')
			{
				continue;
			}

			$domains[$domain] = $domain;
		}

		return array_values($domains);
	}

	/**
	 * @param string $senderField
	 * @return bool
	 */
	public static function isWordpressSender($senderField)
	{
		$email = static::extractEmailFromToken($senderField);
		if ($email === '')
		{
			return false;
		}

		$localPart = substr($email, 0, strrpos($email, '@'));
		return strpos($localPart, 'wordpress') === 0;
	}
}
