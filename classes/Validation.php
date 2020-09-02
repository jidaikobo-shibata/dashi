<?php
namespace Dashi\Core;

class Validation
{
	protected static $messages = array(
		'isNotEmpty'      => '%s is required',
		'isMailaddress'   => '%s is not a valid mail address format',
		'isSb'            => '%s contains multi byte character',
		'isNum'           => '%s is must be composed by numbers',
		'isAlnum'         => '%s is must be composed by single byte alphabetic and numbers',
		'isAlnumplus'     => '%s is must be composed by single byte alphabetic, numbers, hyphen and underbar',
		'isUrl'           => '%s is suspicious url',
		'isAlnumfilename' => '%s is must be composed by single byte alphabetic, numbers, period, hyphen and underbar',
		'isImage'         => '%s is not a image',
		'isKatakana'      => '%s is must be composed by Katakana',
		'isHiragana'      => '%s is must be composed by Hiragana',
		'isUploadable'    => '%s cannot be uploaded',
	);

	/**
	 * getMessage
	 * @return string
	 */
	public static function getMessage ($err)
	{
		if ( ! isset(static::$messages[$err])) return false;
		return static::$messages[$err];
	}

	/**
	 * is not empty
	 * @return Bool
	 */
	public static function isNotEmpty ($str)
	{
		$str = trim($str);
		return strlen($str) ? true : false;
	}

	/**
	 * multi
	 * @param   mixed $strs
	 * @return Bool
	 */
	public static function multi ($validator, $strs)
	{
		if ( ! is_array($strs))
		{
			$strs = explode(',', $strs);
		}
		$is_not_err = true;
		foreach ($strs as $each)
		{
			if ( ! static::$validator($each))
			{
				$is_not_err = false;
				break;
			}
		}
		return $is_not_err;
	}

	/**
	 * is mailaddress
	 * @return Bool
	 */
	public static function isMailaddress ($str)
	{
		//thx http://red.ribbon.to/~php/memo_003.php
		//if( ! preg_match('/^(?:[^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff]+(?![^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff])|"[^\\\\\x80-\xff\n\015"]*(?:\\\\[^\x80-\xff][^\\\\\x80-\xff\n\015"]*)*")(?:\.(?:[^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff]+(?![^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff])|"[^\\\\\x80-\xff\n\015"]*(?:\\\\[^\x80-\xff][^\\\\\x80-\xff\n\015"]*)*"))*@(?:[^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff]+(?![^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff])|\[(?:[^\\\\\x80-\xff\n\015\[\]]|\\\\[^\x80-\xff])*\])(?:\.(?:[^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff]+(?![^(\040)<>@,;:".\\\\\[\]\000-\037\x80-\xff])|\[(?:[^\\\\\x80-\xff\n\015\[\]]|\\\\[^\x80-\xff])*\]))*$/',$mailaddress ) ) {
		return preg_match(
			"/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+([\.][a-z0-9-]+)+$/i",
			$str) ? true : false;
	}

	/**
	 * is single byte
	 * @return Bool
	 */
	public static function isSb($str)
	{
		$len = strlen($str);
		$mblen = mb_strlen($str, mb_internal_encoding());
		return $len !== $mblen;
	}

	/**
	 * is alphabet and number
	 * @return Bool
	 */
	public static function isNum($str)
	{
		return preg_match("/^[0-9]+$/i", $str) ? true : false;
	}

	/**
	 * is alphabet and number
	 * @return Bool
	 */
	public static function isAlnum($str)
	{
		return preg_match("/^[A-Za-z0-9]+$/i", $str) ? true : false;
	}

	/**
	 * is alphabet, number, hyphen and underbar
	 * @return Bool
	 */
	public static function isAlnumplus($str)
	{
		return preg_match("/^[A-Za-z0-9_-]+$/i", $str) ? true : false;
	}

	/**
	 * is single byte filename
	 * @return Bool
	 */
	public static function isAlnumfilename($str)
	{
		return preg_match("/^[A-Za-z0-9_.-]+$/i", $str) ? true : false;
	}

	/**
	 * is katakana
	 * @return Bool
	 */
	public static function isKatakana($str)
	{
		return preg_match("/^[ァ-ヾ　 ー]+$/u", $str) ? true : false;
	}

	/**
	 * is hiragana
	 * @return Bool
	 */
	public static function isHiragana($str)
	{
		return preg_match("/^[ぁ-ゞ　 ー]+$/u", $str) ? true : false;
	}

	/**
	 * is image
	 * @return Bool
	 */
	public static function isImage($filename)
	{
		$arr = array('gif', 'jpg', 'jpeg', 'png');
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$ext = strtolower($ext);
		return in_array($ext, $arr);
	}

	/**
	 * is url
	 * @return Bool
	 */
	public static function isUrl($str)
	{
		return preg_match("/^(https?|ftp)(:\/\/[-_.!~*\'()a-zA-Z0-9;\/?:\@&=+\$,%#]+)$/", $str) ? true : false;
	}

	/**
	 * is uploadable
	 * @return Bool
	 */
	public static function isUploadable()
	{
		return true;
	}
}
