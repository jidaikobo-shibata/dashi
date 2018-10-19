<?php
namespace Dashi\Core\Posttype;

class Csv
{
	/**
	 * forge
	 *
	 * @return  Void
	 */
	public static function forge()
	{
		if ( ! is_admin()) return;

		// ダッシュボード判定
		if (
			isset($_SERVER['SCRIPT_NAME']) &&
			substr($_SERVER['SCRIPT_NAME'], -19) == '/wp-admin/index.php' &&
			get_option('dashi_show_csv_export_dashboard')
		)
		{
			// ダッシュボードにCSV生成
			add_action('wp_dashboard_setup', function ()
			{
				wp_add_dashboard_widget (
					'dashi_list_posttype_to_gen_csv',
					'CSV'.__('Export'),
					array('\\Dashi\\Core\\Posttype\\Csv', 'posttypeList')
				);
			});

			if ($posttype = filter_input(INPUT_POST, 'dashi_csv_export'))
			{
				self::export($posttype);
			}
		}
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
		foreach (\Dashi\P::instances() as $v)
		{
			$posttype = \Dashi\P::class2posttype($v);
			$obj = get_post_type_object($posttype);
			if (is_object($obj) && ! $obj->show_in_nav_menus) continue;
			if (empty($obj->label)) continue;
			$html.= '<option value="'.$obj->name.'">'.$obj->label.'</option>';
		}
		$html.= '</select>';
		$html.= '<label style="display: block; padding: 10px 0;"><input type="checkbox" name="excel_compati" value="1">'.__('<span title="some characters disappear">Microsoft Excel compatible CSV</span>', 'dashi').'</label>';
		$html.= '<input type="submit" class="button button-primary" value="'.__('Export').'">';
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
		$class = \Dashi\P::posttype2class($posttype);
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

			foreach ($class::getFlatCustomFields() as $k => $v)
			{
				$value = $post->$k ?: '' ;
				if (
					in_array($v['type'], array('select', 'radio', 'checkbox')) &&
					isset($v['options'][$value])
				)
				{
					$value = $v['options'][$value];
				}
				if ($excel_compati)
				{
					$value = mb_convert_encoding($value, "SJIS", "UTF-8");
				}
				$arr[$n][$k] = $value;
			}
			$n++;
		}

		$filename = $posttype.'.csv';
		$filepath = '/tmp/'.$filename;
		$fp = fopen($filepath, 'w');
		if ($fp === FALSE) throw new Exception('failed to export');

		$delimiter = $excel_compati ? ',' : "\t";

		foreach($arr as $line){
//			mb_convert_variables('SJIS', 'UTF-8', $line);
			fputcsv($fp, $line, $delimiter);
		}
		fclose($fp);

		header("HTTP/1.1 200 OK");
		header('Content-Type: application/octet-stream');
		header('Content-Length: '.filesize($filepath));
		header('Content-Transfer-Encoding: binary');
		header('Content-Disposition: attachment; filename='.$filename);
		readfile($filepath);
		exit();
	}
}
