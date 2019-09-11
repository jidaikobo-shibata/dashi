<?php
namespace Dashi\Core;

class Input
{
	/**
	 * Return's the referrer
	 *
	 * @return  String|Array
	 */
	public static function referrer($default = '')
	{
		return static::server('HTTP_REFERER', $default);
	}

	/**
	 * Return's the user agent
	 *
	 * @return  string|array
	 */
	public static function user_agent($default = '')
	{
		return static::server('HTTP_USER_AGENT', $default);
	}

	/**
	 * Gets the specified GET variable.
	 *
	 * @param   string  $index    The index to get
	 * @param   string  $default  The default value
	 * @return  string|array
	 */
	public static function get($index = null, $default = null)
	{
		$get = Util::s($_GET);

		if (func_num_args() === 0)
		{
			return $get;
		}

		if ($index && isset($get[$index]))
		{
			return $get[$index];
		}

		return $default;
	}

	/**
	 * Fetch an item from the POST array
	 *
	 * @param   string  The index key
	 * @param   mixed   The default value
	 * @return  string|array
	 */
	public static function post($index = null, $default = null)
	{
		$post = Util::s($_POST);

		if (func_num_args() === 0)
		{
			return $post;
		}

		if ($index && isset($post[$index]))
		{
			return $post[$index];
		}

		return $default;
	}

	/**
	 * Fetch an item from either the GET or POST array
	 *
	 * @param   string  The index key
	 * @param   mixed   The default value
	 * @return  string|array
	 */
	public static function param($index = null, $default = null)
	{
		return static::post($index, static::get($index, $default));
	}

	/**
	 * Fetch an item from the FILE array
	 *
	 * @param   string  The index key
	 * @param   mixed   The default value
	 * @return  string|array
	 */
	public static function file($index = null, $default = null)
	{
		$files = Util::s($_FILES);

		if (func_num_args() === 0)
		{
			return $files;
		}

		if ($index && isset($files[$index]))
		{
			return $files[$index];
		}

		return $default;
	}

	/**
	 * Fetch an item from the COOKIE array
	 *
	 * @param    string  The index key
	 * @param    mixed   The default value
	 * @return   string|array
	 */
	public static function cookie($index = null, $default = null)
	{
		$cookies = Util::s($_COOKIES);

		if (func_num_args() === 0)
		{
			return $cookies;
		}

		if ($index && isset($cookies[$index]))
		{
			return $cookies[$index];
		}

		return $default;
	}

	/**
	 * Fetch an item from the SERVER array
	 *
	 * @param   string  The index key
	 * @param   mixed   The default value
	 * @return  string|array
	 */
	public static function server($index = null, $default = null)
	{
		$server = Util::s($_SERVER);

		if (func_num_args() === 0)
		{
			return $server;
		}

		if ($index && isset($server[$index]))
		{
			return $server[$index];
		}

		return $default;
	}
}
