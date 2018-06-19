<?php
namespace Dashi\Core\Posttype;

class Validation extends \Dashi\Core\Validation
{
	/**
	 * validate mailaddress
	 * @return Mixed|Bool|String
	 */
	public static function validateMailaddress ($val)
	{
		$is_valid = static::isMailaddress($val);
		return $is_valid ? true : static::$messages['isMailaddress'];
	}

	/**
	 * validate single byte
	 * @return Mixed|Bool|String
	 */
	public static function validateSb ($val)
	{
		$is_valid = static::isSb($val);
		return $is_valid ? true : static::$messages['isSb'];
	}

	/**
	 * validate alphabet and number
	 * @return Mixed|Bool|String
	 */
	public static function validateAlnum ($val)
	{
		$is_valid = static::isAlnum($val);
		return $is_valid ? true : static::$messages['isAlnum'];
	}

	/**
	 * validate alphabet, number, hyphen and underbar
	 * @return Mixed|Bool|String
	 */
	public static function validateAlnumplus ($val)
	{
		$is_valid = static::isAlnumplus($val);
		return $is_valid ? true : static::$messages['isAlnumplus'];
	}

	/**
	 * validate single byte filename
	 * @return Mixed|Bool|String
	 */
	public static function validateAlnumfilename ($val)
	{
		$is_valid = static::isAlnumfilename($val);
		return $is_valid ? true : static::$messages['isAlnumfilename'];
	}

	/**
	 * validate katakana
	 * @return Mixed|Bool|String
	 */
	public static function validateKatakana ($val)
	{
		$is_valid = static::isKatakana($val);
		return $is_valid ? true : static::$messages['isKatakana'];
	}

	/**
	 * validate hiragana
	 * @return Mixed|Bool|String
	 */
	public static function validateHiragana ($val)
	{
		$is_valid = static::isHiragana($val);
		return $is_valid ? true : static::$messages['isHiragana'];
	}

	/**
	 * validate image
	 * @return Mixed|Bool|String
	 */
	public static function validateImage ($val)
	{
		$is_valid = static::isImage($val);
		return $is_valid ? true : static::$messages['isImage'];
	}

	/**
	 * validate url
	 * @return Mixed|Bool|String
	 */
	public static function validateUrl ($val)
	{
		$is_valid = static::isUrl($val);
		return $is_valid ? true : static::$messages['isUrl'];
	}

	/**
	 * validate uploadable
	 * @return Mixed|Bool|String
	 */
	public static function validateUploadable ($val)
	{
		$is_valid = static::isUploadable($val);
		return $is_valid ? true : static::$messages['isUploadable'];
	}
}