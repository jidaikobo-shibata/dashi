<?php
/**
 * Dashi\Core\Util
 *
 * @package    part of Kontiki
 * @author     Jidaikobo Inc.
 * @license    The MIT License (MIT)
 * @copyright  Jidaikobo Inc.
 * @link       http://www.jidaikobo.com
 */
namespace Dashi\Core;

class Util
{
	/**
	 * add autoloader path
	 *
	 * @param  String $path
	 * @param  String $namespace
	 * @return Void
	 */
	public static function addAutoloaderPath($path, $namespace = '')
	{
		spl_autoload_register(
			function ($class_name) use ($path, $namespace)
			{
				// check namespace
				$class = $class_name;
				$strlen = strlen($namespace);

				if (substr($class, 0, $strlen) !== $namespace) return;
				$class = substr($class, $strlen + 1);

				// underscores are directories
				$path.= str_replace('\\', '/', $class);

				// require
				$file_path = $path.'.php';
				if (file_exists($file_path))
				{
					require $path.'.php';
				}
				else
				{
					return false;
				}

				// init
				if (method_exists($class_name, '_init') and is_callable($class_name.'::_init'))
				{
					call_user_func($class_name.'::_init');
				}
			}
		);
	}

	/**
	 * get current uri
	 *
	 * @return String
	 */
	public static function uri()
	{
		$uri = static::is_ssl() ? 'https' : 'http';
		$uri.= '://'.$_SERVER["HTTP_HOST"].rtrim($_SERVER["REQUEST_URI"], '/');
		return static::s($uri);
	}

	/**
	 * get root relative
	 *
	 * @return String
	 */
	public static function rootRelative()
	{
		$host = $_SERVER["HTTP_HOST"];
		$offset = strpos(home_url(), $host) + strlen($host);
		return substr(home_url(), 0, $offset);
	}

	/**
	 * add query strings
	 * this medhod doesn't apply sanitizing
	 *
	 * @param  String $uri
	 * @param  Array  $query_strings array(array('key', 'val'),...)
	 * @return String
	 */
	public static function addQueryStrings($uri, $query_strings = array())
	{
		$delimiter = strpos($uri, '?') !== false ? '&amp;' : '?';
		$qs = array();
		foreach ($query_strings as $v)
		{
			// if (is_array($v))
			$qs[] = $v[0].'='.$v[1];
		}
		return $uri.$delimiter.join('&amp;', $qs);
	}

	/**
	 * remove query strings
	 *
	 * @param  String $uri
	 * @param  Array  $query_strings array('key',....)
	 * @return String
	 */
	public static function removeQueryStrings($uri, $query_strings = array())
	{
		if (strpos($uri, '?') !== false)
		{
			// all query strings
			$query_strings = $query_strings ?: array_keys($_GET);

			// replace
			$uri = str_replace('&amp;', '&', $uri);
			$pos = strpos($uri, '?');
			$base_url = substr($uri, 0, $pos);
			$qs = explode('&', substr($uri, $pos + 1));
			foreach ($qs as $k => $v)
			{
				foreach ($query_strings as $vv)
				{
					if (substr($v, 0, strpos($v, '=')) == $vv)
					{
						unset($qs[$k]);
					}
				}
			}
			$uri = $qs ? $base_url.'?'.join('&amp;', $qs) : $base_url;
		}
		return $uri;
	}

	/**
	 * is ssl
	 *
	 * @return Bool
	 */
	public static function isSsl()
	{
		return isset($_SERVER['HTTP_X_SAKURA_FORWARDED_FOR']) ||
			(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']);
	}

	/**
	 * sanitize html
	 *
	 * @param  String $str
	 * @return String
	 */
	public static function s($str)
	{
		if (is_array($str)) return array_map(array('\\Dashi\\Core\\Util', 's'), $str);
		return htmlentities($str, ENT_QUOTES, 'UTF-8', false);
	}

	/**
	 * truncate
	 *
	 * @param  String  $str
	 * @param  Integer $len
	 * @param  String  $lead
	 * @return String
	 */
	public static function truncate($str, $len, $lead = '...')
	{
		$target_len = mb_strlen($str);
		return $target_len > $len ? mb_substr($str, 0, $len).$lead : $str;
	}

	/**
	 * urlenc
	 *
	 * @param  String $url
	 * @return String
	 */
	public static function urlenc($url)
	{
		$url = str_replace(array("\n", "\r"), '', $url);
		$url = static::s($url); // & to &amp;
		$url = str_replace(' ', '%20', $url);
		if (strpos($url, '%') === false)
		{
			$url = urlencode($url);
		}
		else
		{
			$url = str_replace(
				'://',
				'%3A%2F%2F',
				$url);
		}
		return $url;
	}

	/**
	 * urldec
	 *
	 * @param  String $url
	 * @return String
	 */
	public static function urldec($url)
	{
		$url = str_replace(array("\n", "\r"), '', $url);
		$url = trim($url);
		$url = rtrim($url, '/');
		$url = static::urlenc($url);
		$url = urldecode($url);
		$url = str_replace('&amp;', '&', $url);
		return $url;
	}

	/**
	 * removeHost
	 *
	 * @param  String|Array $val
	 * @return String
	 */
	public static function removeHost($val)
	{
		if (is_array($val))
		{
			return array_map(array('\\Dashi\\Core\\Util', 'removeHost'), $val);
		}
		return str_replace(static::rootRelative(), '', $val);
	}

	/**
	 * eliminateControlCodes
	 *
	 * @param  String|Array $val
	 * @return String
	 * @link https://stackoverflow.com/questions/1497885/remove-control-characters-from-php-string
	 */
	public static function eliminateControlCodes($val)
	{
		if (is_array($val))
		{
			return array_map(array('\\Dashi\\Core\\Util', 'eliminateControlCodes'), $val);
		}
//		return preg_replace('/\p{Cc}/u', '', $val);
		return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $val);
	}

	/**
	 * eliminateUtfSeparation
	 *
	 * @param  String|Array $val
	 * @return String
	 * @link http://blog.sarabande.jp/post/52532110572
	 * @link https://pear.php.net/package/I18N_UnicodeNormalizer
	 */
	public static function eliminateUtfSeparation($val)
	{
		if (is_array($val))
		{
			return array_map(array('\\Dashi\\Core\\Util', 'eliminateUtfSeparation'), $val);
		}

		if (class_exists('Normalizer'))
		{
			if (\Normalizer::isNormalized($val, \Normalizer::FORM_D)) {
				$val = \Normalizer::normalize($val, \Normalizer::FORM_C);
			}
		}
		elseif (class_exists('I18N_UnicodeNormalizer'))
		{
			$normalizer = new \I18N_UnicodeNormalizer();
			$val = $normalizer->normalize($val, 'NFC');
		}

		return $val;
	}

	/**
	 * headers
	 *
	 * @param  String $url
	 * @return Array
	 */
	public static function headers($url)
	{
		// get_headers
		$headers = @get_headers($url);
		if ( ! $headers)
		{
			$headers = static::curl('headers', $url);
		}
		return $headers;
	}

	/**
	 * is_url_exists
	 *
	 * @param  String $url
	 * @param  String $method
	 * @return Bool|Null
	 */
	public static function is_url_exists($url, $method = 'header')
	{
	//	$response = static::curl($method, $url);
		$response = wp_remote_get($url, array('timeout' => 10, 'sslverify' => false,));
		return $response['headers']['status'] == '200 OK';
	}

	/**
	 * error
	 *
	 * @param  String $message
	 * @return Void
	 */
	public static function error($message = '')
	{
		if ( ! headers_sent())
		{
			header('Content-Type: text/plain; charset=UTF-8', true, 403);
		}
		die(Util::s($message));
	}
}
