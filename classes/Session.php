<?php
/**
 * Dashi\Core\Session
 *
 * @package    part of Kontiki
 * @author     Jidaikobo Inc.
 * @license    The MIT License (MIT)
 * @copyright  Jidaikobo Inc.
 * @link       http://www.jidaikobo.com
 */
namespace Dashi\Core;

class Session
{
	const DEFAULT_SESSION_NAME = 'DASHISESSID';

	protected static $values = array();

	/**
	 * Create Session
	 *
	 * @return  void
	 */
	public static function forge($session_name = self::DEFAULT_SESSION_NAME)
	{
		static::ensureStarted(true, $session_name);
	}

	/**
	 * started?
	 *
	 * @return bool
	 */
	public static function isStarted()
	{
		$is_session_started = false;
		if (version_compare(phpversion(), '5.4.0', '>='))
		{
			$is_session_started = session_status() === PHP_SESSION_ACTIVE ? TRUE : FALSE;
		}
		else
		{
			$is_session_started = session_id() === '' ? FALSE : TRUE;
		}
		return $is_session_started;
	}

	/**
	 * Destroy Session
	 *
	 * @return  void
	 */
	public static function destroy()
	{
		static::ensureStarted(false);

		$_SESSION = array();
		$session_name = static::getSessionName();
		if (Input::cookie($session_name))
		{
			setcookie($session_name, '', time()-42000, '/');
		}
		if (static::isStarted())
		{
			session_destroy();
		}
	}

	/**
	 * set
	 *
	 * @param   string    $realm
	 * @param   string    $key
	 * @param   mixed     $vals
	 * @return  void
	 */
	public static function set($realm, $key, $vals)
	{
		static::ensureStarted(true);

		// prepare static value
		if ( ! isset(static::$values[$realm]))
		{
			static::$values[$realm] = array();
		}
		if ( ! isset(static::$values[$realm][$key]))
		{
			static::$values[$realm][$key] = array();
		}
		if ( ! isset($_SESSION[$realm]))
		{
			$_SESSION[$realm] = array();
		}
		if ( ! isset($_SESSION[$realm][$key]))
		{
			$_SESSION[$realm][$key] = array();
		}

		// set
		static::$values[$realm][$key] = $vals;
		$_SESSION[$realm][$key] = $vals;
	}

	/**
	 * add
	 *
	 * @param   string    $realm
	 * @param   string    $key
	 * @param   mixed     $vals
	 * @return  void
	 */
	public static function add($realm, $key, $vals)
	{
		static::ensureStarted(true);

		// prepare
		if ( ! isset(static::$values[$realm][$key]))
		{
			static::$values[$realm][$key] = array();
		}

		// add
		static::$values[$realm][$key][] = $vals;

			// if Session which has same key and realm exists, merge.
			if (isset($_SESSION[$realm][$key]))
			{
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- セッション領域の内部データをマージする。
				$session_values = $_SESSION[$realm][$key];
				static::$values[$realm][$key] = array_merge(
					$session_values,
					static::$values[$realm][$key]
				);
			}

		$_SESSION[$realm] = static::$values[$realm];
	}

	/**
	 * remove
	 *
	 * @param   string  $realm
	 * @param   string  $key
	 * @param   int     $c_key
	 * @return  void
	 */
	public static function remove($realm, $key = '', $c_key = '')
	{
		static::ensureStarted(false);

		// remove realm
		if (empty($key) && empty($c_key))
		{
			if (isset($_SESSION[$realm]))
			{
				unset($_SESSION[$realm]);
			}
			if (isset(static::$values[$realm]))
			{
				unset(static::$values[$realm]);
			}
		}
		// remove key
		elseif(empty($c_key))
		{
			if (isset($_SESSION[$realm][$key]))
			{
				unset($_SESSION[$realm][$key]);
			}
			if (isset(static::$values[$realm][$key]))
			{
				unset(static::$values[$realm][$key]);
			}
		}
		// remove each value
		else
		{
			if (isset($_SESSION[$realm][$key][$c_key]))
			{
				unset($_SESSION[$realm][$key][$c_key]);
			}
			if (isset(static::$values[$realm][$key][$c_key]))
			{
				unset(static::$values[$realm][$key][$c_key]);
			}
		}

		if (empty($_SESSION) && empty(static::$values))
		{
			static::destroy();
		}
	}

	/**
	 * fetch
	 * fetch data from SESSION and static value.
	 * after fetching data will be deleted (default).
	 *
	 * @param   string  $realm
	 * @param   string  $key
	 * @param   bool    $is_once
	 * @return  mixed
	 */
	public static function fetch($realm, $key = '', $is_once = 1)
	{
		static::ensureStarted(false);

		$vals = array();

		// return by realm
		if (empty($key))
		{
				if (isset($_SESSION[$realm]))
				{
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- セッション領域の内部データを取得する。
					$vals = $_SESSION[$realm];
				}
			if (isset(static::$values[$realm]))
			{
				$vals = static::mergeValues($vals, static::$values[$realm]);
			}
			if ($is_once) static::remove($realm);
		}
		// return by key
		elseif (
			isset(static::$values[$realm][$key]) ||
			isset($_SESSION[$realm][$key])
		)
		{
				if (isset($_SESSION[$realm][$key]))
				{
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- セッション領域の内部データを取得する。
					$vals = $_SESSION[$realm][$key];
				}
			if (isset(static::$values[$realm][$key]))
			{
				$vals = static::mergeValues($vals, static::$values[$realm][$key]);
			}
			if ($is_once) static::remove($realm, $key);
		}
		return $vals ?: false;
	}

	/**
	 * Merge mixed values safely for session fetch.
	 *
	 * @param mixed $left
	 * @param mixed $right
	 * @return mixed
	 */
	private static function mergeValues($left, $right)
	{
		// array + array
		if (is_array($left) && is_array($right))
		{
			return array_replace($left, $right);
		}

		// object/array mixed
		if ((is_object($left) || is_array($left)) && (is_object($right) || is_array($right)))
		{
			$merged = array_replace((array) $left, (array) $right);
			return (is_object($left) || is_object($right)) ? (object) $merged : $merged;
		}

		// scalar fallback
		return $right ?: $left;
	}

	/**
	 * show
	 *
	 * @param   string  $realm
	 * @param   string  $key
	 * @return  mixed
	 */
	public static function show($realm = '', $key = '')
	{
		static::ensureStarted(false);

		if (empty($realm))
		{
			return array_replace(static::$values, $_SESSION);
		}
		return static::fetch($realm, $key, false) ?: false;
	}

	/**
	 * セッション名を返す
	 *
	 * @return string
	 */
	private static function getSessionName()
	{
		$name = session_name();
		if (!is_string($name) || $name === '' || $name === 'PHPSESSID')
		{
			return self::DEFAULT_SESSION_NAME;
		}

		return $name;
	}

	/**
	 * セッション cookie の有無を返す
	 *
	 * @param string|null $session_name セッション名
	 * @return bool
	 */
	private static function hasSessionCookie($session_name = null)
	{
		$session_name = is_string($session_name) && $session_name !== ''
			? $session_name
			: static::getSessionName();

		return (bool) Input::cookie($session_name);
	}

	/**
	 * 必要なときだけセッションを開始する
	 *
	 * @param bool        $force_create  cookie がなくても開始するか
	 * @param string|null $session_name  セッション名
	 * @return bool
	 */
	private static function ensureStarted($force_create = false, $session_name = null)
	{
		$session_name = is_string($session_name) && $session_name !== ''
			? $session_name
			: static::getSessionName();

		if (static::isStarted())
		{
			return true;
		}

		if (!$force_create && !static::hasSessionCookie($session_name))
		{
			return false;
		}

		if (headers_sent())
		{
			return false;
		}

		// SESSION disabled
		$is_session_disabled = false;
		if (defined('PHP_SESSION_DISABLED') && version_compare(phpversion(), '5.4.0', '>='))
		{
			$is_session_disabled = session_status() === PHP_SESSION_DISABLED ? TRUE : FALSE;
		}
		if ($is_session_disabled)
		{
			Util::error('couldn\'t start session.');
		}

		$has_cookie = static::hasSessionCookie($session_name);

		if (Util::isSsl())
		{
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- セッション cookie を secure 化する。
			ini_set('session.cookie_secure', 1);
		}
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- セッション cookie の安全設定。
		ini_set('session.cookie_httponly', true);
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- セッション設定の明示。
		ini_set('session.use_trans_sid', 0);
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- セッション設定の明示。
		ini_set('session.use_only_cookies', 1);
//		session_save_path('/var/tmp');
		session_name($session_name);
		session_start();

		if (!$has_cookie)
		{
			session_regenerate_id(true);
		}

		return true;
	}
}
