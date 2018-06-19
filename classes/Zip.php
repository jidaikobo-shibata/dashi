<?php
// zip code library
// thx https://github.com/ninton/jquery.jpostal.js
namespace Dashi\Core;

class Zip
{
	/**
	 * addHeader
	 *
	 * @param fields  array
	 * @return  void
	 */
	public static function addHeader ($fields)
	{
		// 郵便番号関係のフィールドの有無の確認
		$zips = array();
		foreach ($fields as $key => $field)
		{
			if (isset($field['zip_group']) && isset($field['zip_to']))
			{
				$zips[$field['zip_group']]['zip_to'][$key] = $field;
				continue;
			}
			if (isset($field['zip_group']) && isset($field['zip_from']))
			{
				$zips[$field['zip_group']]['zip_from'][$key] = $field;
				continue;
			}
		}
		if ( ! $zips) return;

		// wp_enqueue_script
		$wp_enqueue_script = function ()
		{
				wp_enqueue_script(
					'dashi_jquery_jpostal_js',
//					'//jpostal-1006.appspot.com/jquery.jpostal.js',
					plugins_url('assets/js/jquery.jpostal.js', DASHI_FILE),
					array('jquery')
				);
		};
		add_action('admin_enqueue_scripts', $wp_enqueue_script);
		add_action('wp_enqueue_scripts', $wp_enqueue_script);

		// js
		$js = "<script>\n";
		$js.= "jQuery( function($) {\n";
		foreach ($zips as $zip)
		{
			// まず郵便番号
			$n = 0;
			$ids = array();
			foreach ($zip['zip_to'] as $name => $zip_to)
			{
				$id = 'dashi_'.$name;
				if ($n == 0)
				{
					$js.= "jQuery('#{$id}').jpostal({\n";
					$js.= "postcode : [\n";
					$n++;
				}
				$ids[] = "	'#{$id}'";
			}
			$js.= join(',', $ids);
			$js.= "\n],\n";

			// 住所欄
			$n = 0;
			$ids = array();
			foreach ($zip['zip_from'] as $name => $zip_from)
			{
				$id = 'dashi_'.$name;
				if ($n == 0)
				{
					$js.= "address : {\n";
					$n++;
				}
				$ids[] = "	'#{$id}' : '{$zip_from['zip_from_type']}'";
			}
			$js.= join(',', $ids);
			$js.= "\n}\n";
		}
		$js.= "});\n});\n</script>\n";

		// 管理画面用
		add_action('admin_print_footer_scripts', function () use ($js)
		{
			echo $js;
		});

		// フォーム用
		add_action('wp_print_footer_scripts', function () use ($js)
		{
			echo $js;
		});
	}
}
