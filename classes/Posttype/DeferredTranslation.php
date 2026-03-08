<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class DeferredTranslation
{
	/**
	 * @var string
	 */
	private $key;

	/**
	 * @var string
	 */
	private $domain;

	/**
	 * @param string $key
	 * @param string $domain
	 */
	public function __construct($key, $domain = 'dashi')
	{
		$this->key = (string) $key;
		$this->domain = (string) $domain;
	}

	/**
	 * @return string
	 */
	public function key()
	{
		return $this->key;
	}

	/**
	 * @return string
	 */
	public function domain()
	{
		return $this->domain;
	}
}
