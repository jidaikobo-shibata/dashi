<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Csv
{
	/**
	 * forge
	 *
	 * @return  Void
	 */
	public static function forge()
	{
		if (!is_admin()) return;

		// ダッシュボード表示時にウィジェット追加
		add_action('wp_dashboard_setup', function () {
			if (!get_option('dashi_show_csv_export_dashboard')) return;

			wp_add_dashboard_widget(
				'dashi_list_posttype_to_gen_csv',
				'CSV'.__('Export', 'dashi'),
				array('\\Dashi\\Core\\Posttype\\Csv', 'posttypeList')
			);
		});

		// CSV エクスポート処理（init 後で）
		add_action('admin_init', function () {
			if (!get_option('dashi_show_csv_export_dashboard')) return;

			if (
				$posttype = filter_input(INPUT_POST, 'dashi_csv_export') and
				check_admin_referer('dashi_csv_export_action')
			) {
				self::export($posttype);
			}
		});
	}

	/**
	 * posttypeList
	 *
	 * @return Void
	 */
	public static function posttypeList()
	{
		$html = '';
		$html.= '<form action="" method="post">';
		$html.= '<select name="dashi_csv_export" style="width: 100%;">';
		$html.= '<option value="">-</option>';
		foreach (\Dashi\Core\Posttype\Posttype::instances() as $v)
		{
			$posttype = \Dashi\Core\Posttype\Posttype::class2posttype($v);
			$obj = get_post_type_object($posttype);
			if (is_object($obj) && ! $obj->show_in_nav_menus) continue;
			if (empty($obj->label)) continue;
			$html.= '<option value="'.$obj->name.'">'.$obj->label.'</option>';
		}
		$html.= '</select>';
		$html.= '<label style="display: block; padding: 10px 0;"><input type="checkbox" name="excel_compati" value="1"><span title="'.esc_attr__('some characters disappear', 'dashi').'">'.esc_html__('Microsoft Excel compatible CSV', 'dashi').'</span></label>';
		$html.= '<input type="submit" class="button button-primary" value="'.__('Export', 'dashi').'">';
		$html .= wp_nonce_field('dashi_csv_export_action', '_wpnonce', true, false);
		$html.= '</form>';
		echo $html;
	}

	/**
	 * CSV Export
	 *
	 * @param  String $posttype
	 * @return Void
	 */
	private static function export($posttype)
	{
		// 安全な posttype だけ許可
		$allowed_posttypes = array_map(
			['\Dashi\Core\Posttype\Posttype', 'class2posttype'],
			\Dashi\Core\Posttype\Posttype::instances()
		);
		if (!in_array($posttype, $allowed_posttypes, true)) {
			wp_die(__('Invalid post type', 'dashi'), 403);
		}

		$class = \Dashi\Core\Posttype\Posttype::posttype2class($posttype);
		$excel_compati = filter_input(INPUT_POST, 'excel_compati');

		$args = array(
			'post_type' => $posttype,
			'numberposts' => -1,
		);
		$posts = get_posts($args);

		// csv
		$arr = array();
		$n = 0;
		foreach ($posts as $post)
		{
			$arr[$n] = array();

			$tmp = (array) $post;
			if ($excel_compati)
			{
				mb_convert_variables('SJIS', 'UTF-8', $tmp);
			}
			$arr[$n] = $tmp;

/*
// 考え中
			foreach (get_post_meta($post->ID) as $k => $v)
			{
				if (strpos($k, 'dashi') !== false) continue;
				if ($k[0] == '_') continue;
				$arr[$n][] = $v[0];
			}
*/

			foreach ($class::getFlatCustomFields() as $k => $v)
			{
				$value = $post->$k ?: '' ;

				// ★ options の解決（Closure / array / null）
				$options = \Dashi\Core\Util::resolveOptions($v['options'] ?? null);

				if (
					in_array($v['type'], array('select', 'radio', 'checkbox')) &&
					isset($options[$value])
				)
				{
					$value = $options[$value];
				}
				if ($excel_compati)
				{
					$value = mb_convert_encoding($value, "SJIS", "UTF-8");
				}
				$arr[$n][$k] = $value;
			}
			$n++;
		}

		$filename = sanitize_file_name($posttype) . '.csv';
		$tmpfile = wp_tempnam($filename);
		$fp = fopen($tmpfile, 'w');
		// $filepath = '/tmp/'.$filename;
		// $fp = fopen($filepath, 'w');
		if ($fp === FALSE) throw new Exception('failed to export');

		$delimiter = $excel_compati ? ',' : "\t";

		foreach($arr as $line){
//			mb_convert_variables('SJIS', 'UTF-8', $line);
			fputcsv($fp, self::sanitizeCsvLine($line), $delimiter);
		}
		fclose($fp);

		header("HTTP/1.1 200 OK");
		header('Content-Type: application/octet-stream');
		header('Content-Length: '.filesize($tmpfile));
		header('Content-Transfer-Encoding: binary');
		header('Content-Disposition: attachment; filename='.$filename);
		readfile($tmpfile);
		@unlink($tmpfile);
		exit();
	}
	private static function sanitizeCsvLine($line)
	{
		if (!is_array($line))
		{
			return array(self::sanitizeCsvCell($line));
		}

		return array_map(function ($cell)
		{
			return self::sanitizeCsvCell($cell);
		}, $line);
	}

	private static function sanitizeCsvCell($cell)
	{
		if (is_array($cell) || is_object($cell))
		{
			$cell = wp_json_encode($cell);
		}
		elseif (is_bool($cell))
		{
			$cell = $cell ? '1' : '0';
		}
		elseif ($cell === null)
		{
			$cell = '';
		}
		else
		{
			$cell = (string) $cell;
		}

		// Prevent spreadsheet formula execution when a cell starts with a formula marker.
		if ($cell !== '' && preg_match('/^\s*[=+\-@\t\r\n]/u', $cell))
		{
			return "'" . $cell;
		}

		return $cell;
	}
}
