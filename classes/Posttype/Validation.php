<?php
namespace Dashi\Core\Posttype;

class Validation extends \Dashi\Core\Validation
{
	private static function translatedMessage($key)
	{
			switch ($key)
			{
				case 'isMailaddress':
					/* translators: %s: field label. */
					return __('%s is not a valid mail address format', 'dashi');
				case 'isSb':
					/* translators: %s: field label. */
					return __('%s contains multi byte character', 'dashi');
				case 'isNum':
					/* translators: %s: field label. */
					return __('%s is must be composed by numbers', 'dashi');
				case 'isAlnum':
					/* translators: %s: field label. */
					return __('%s is must be composed by single byte alphabetic and numbers', 'dashi');
				case 'isAlnumplus':
					/* translators: %s: field label. */
					return __('%s is must be composed by single byte alphabetic, numbers, hyphen and underbar', 'dashi');
				case 'isAlnumfilename':
					/* translators: %s: field label. */
					return __('%s is must be composed by single byte alphabetic, numbers, period, hyphen and underbar', 'dashi');
				case 'isImage':
					/* translators: %s: field label. */
					return __('%s is not a image', 'dashi');
				case 'isKatakana':
					/* translators: %s: field label. */
					return __('%s is must be composed by Katakana', 'dashi');
				case 'isHiragana':
					/* translators: %s: field label. */
					return __('%s is must be composed by Hiragana', 'dashi');
				case 'isUploadable':
					/* translators: %s: field label. */
					return __('%s cannot be uploaded', 'dashi');
				case 'isUrl':
					/* translators: %s: field label. */
					return __('%s is suspicious url', 'dashi');
			default:
				return '';
		}
	}

	/**
	 * validate mailaddress
	 * @return Mixed|Bool|String
	 */
	public static function validateMailaddress ($val)
	{
		$is_valid = static::isMailaddress($val);
		return $is_valid ? true : static::translatedMessage('isMailaddress');
	}

	/**
	 * validate single byte
	 * @return Mixed|Bool|String
	 */
	public static function validateSb ($val)
	{
		$is_valid = static::isSb($val);
		return $is_valid ? true : static::translatedMessage('isSb');
	}

	/**
	 * validate alphabet and number
	 * @return Mixed|Bool|String
	 */
	public static function validateAlnum ($val)
	{
		$is_valid = static::isAlnum($val);
		return $is_valid ? true : static::translatedMessage('isAlnum');
	}

	/**
	 * validate alphabet, number, hyphen and underbar
	 * @return Mixed|Bool|String
	 */
	public static function validateAlnumplus ($val)
	{
		$is_valid = static::isAlnumplus($val);
		return $is_valid ? true : static::translatedMessage('isAlnumplus');
	}

	/**
	 * validate single byte filename
	 * @return Mixed|Bool|String
	 */
	public static function validateAlnumfilename ($val)
	{
		$is_valid = static::isAlnumfilename($val);
		return $is_valid ? true : static::translatedMessage('isAlnumfilename');
	}

	/**
	 * validate katakana
	 * @return Mixed|Bool|String
	 */
	public static function validateKatakana ($val)
	{
		$is_valid = static::isKatakana($val);
		return $is_valid ? true : static::translatedMessage('isKatakana');
	}

	/**
	 * validate hiragana
	 * @return Mixed|Bool|String
	 */
	public static function validateHiragana ($val)
	{
		$is_valid = static::isHiragana($val);
		return $is_valid ? true : static::translatedMessage('isHiragana');
	}

	/**
	 * validate image
	 * @return Mixed|Bool|String
	 */
	public static function validateImage ($val)
	{
		$is_valid = static::isImage($val);
		return $is_valid ? true : static::translatedMessage('isImage');
	}

	/**
	 * validate url
	 * @return Mixed|Bool|String
	 */
	public static function validateUrl ($val)
	{
		$is_valid = static::isUrl($val);
		return $is_valid ? true : static::translatedMessage('isUrl');
	}

	/**
	 * validate uploadable
	 * @return Mixed|Bool|String
	 */
	public static function validateUploadable ($val)
	{
		$is_valid = static::isUploadable($val);
		return $is_valid ? true : static::translatedMessage('isUploadable');
	}
}
