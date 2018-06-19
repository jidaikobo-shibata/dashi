<?php
namespace Dashi\Core;

class Mail
{
	public static $errors = array();

	/**
	 * mailFormat
	 *
	 * @return  string
	 */
	private static function mailFormat ($message)
	{
		$message = mb_convert_kana ($message, "KV"); // kill half width kana.
		$message = str_replace ('&amp;', '&', $message);
		$message = str_replace ("\r", "\n", str_replace ("\r\n", "\r", $message));
		if (get_locale() != 'en_US')
		{
			$message = mb_convert_encoding ($message, 'ISO-2022-JP', 'UTF-8');
		}
		return $message;
	}

	/**
	 * subjectFormat
	 *
	 * @return  string
	 */
	private static function subjectFormat ($subject)
	{

		if (get_locale() != 'en_US')
		{
			//	$subject = mb_convert_encoding ($subject, 'ISO-2022-JP', 'auto');
			$subject = mb_convert_encoding ($subject, 'ISO-2022-JP', 'UTF-8');
			$subject = base64_encode ($subject);
			$subject = '=?iso-2022-jp?B?'.$subject.'?=';
		}
		return $subject;
	}

	/**
	 * header
	 *
	 * @return  string
	 */
	private static function header ()
	{
		return get_locale() == 'en_US' ?
												'Content-Type: text/plain;charset=UTF-8'."\n" :
												'Content-Type: text/plain;charset=iso-2022-jp'."\n";
	}

	/**
	 * headerFormat
	 *
	 * @return  string
	 */
	private static function headerFormat ($str)
	{
		mb_internal_encoding ('UTF-8');
		if (get_locale() != 'en_US') {
			mb_language ('ja');
			$str = mb_encode_mimeheader ($str);
		}
		return $str;
	}

	/**
	 * send
	 *
	 * @return  bool
	 */
	public static function send (
		$to,
		$subject,
		$message,
		$additional_headers = '',
		$additional_parameters = ''
	)
	{
//		$header = static::header();
		$subject = static::subjectFormat($subject);
		$message = static::mailFormat($message);

		//sendmail
		if ( ! apply_filters('dashi_mail', false, $to, $subject, $message, $additional_headers, $additional_parameters))
		{
			if ( ! mail ($to, $subject, $message, $additional_headers, $additional_parameters))
			{
				static::$errors[] =  __('failed to send mail for some reason. Sorry to trouble you, but by other means, please contact the site administrator' , 'dashi');
			}
		}

		return static::$errors ? false : true;
	}
}
