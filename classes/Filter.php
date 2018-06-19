<?php
namespace Dashi\Core;

class Filter
{
	/**
	 * filetr alnum
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function alnum($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'alnum'), $val);
		return mb_convert_kana($val, "as");
	}

	/**
	 * filetr lower
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function lower($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'lower'), $val);
		return strtolower($val);
	}

	/**
	 * filetr upper
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function upper($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'upper'), $val);
		return strtoupper($val);
	}

	/**
	 * filetr trim
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function trim($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'trim'), $val);
		return trim($val);
	}

	/**
	 * filetr int
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function int($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'int'), $val);
		return intval($val);
	}

	/**
	 * filetr date
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function date($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'date'), $val);
		return $val ? date('Y-m-d', strtotime($val)) : '';
	}

	/**
	 * filetr datetime
	 *
	 * @param   mixed
	 * @return  string
	 */
	public static function datetime($val)
	{
		if (is_array($val)) return array_map(array('\\Dashi\\Core\\Filter', 'datetime'), $val);
		return $val ? date('Y-m-d H:i:s', strtotime($val)) : '';
	}
}