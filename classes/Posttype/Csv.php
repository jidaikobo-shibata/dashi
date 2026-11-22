<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Csv
{
    public const POST_TYPES_OPTION = 'dashi_csv_export_post_types';

    /** 設定画面で選べる、Dashiが扱う管理画面付きの投稿タイプ。 */
    public static function getConfigurablePostTypes()
    {
        $post_types = array();
        foreach (Posttype::instances() as $class) {
            $name = Posttype::class2posttype($class);
            $post_type = get_post_type_object($name);
            if ($post_type && $post_type->show_ui) {
                $post_types[$name] = $post_type;
            }
        }
        return $post_types;
    }

    /** 未登録のタイプや配列などの不正な入力は保存対象から除く。 */
    public static function filterPostTypeSelection($selection)
    {
        if (!is_array($selection)) {
            return array();
        }

        return array_values(array_intersect(
            array_keys(self::getConfigurablePostTypes()),
            array_filter($selection, 'is_string')
        ));
    }

    private static function getExportablePostTypes()
    {
        $selection = get_option(self::POST_TYPES_OPTION, array());
        if (!get_option('dashi_show_csv_export_dashboard') || !is_array($selection) || !$selection) {
            return array();
        }

        return array_filter(self::getConfigurablePostTypes(), static function ($post_type) use ($selection) {
            return in_array($post_type->name, $selection, true)
                && current_user_can($post_type->cap->edit_others_posts);
        });
    }

	/**
	 * CSV エクスポートウィジェットで許可する HTML 属性
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function getAllowedWidgetHtml()
	{
		return array(
			'form' => array(
				'action' => true,
				'method' => true,
			),
			'select' => array(
				'id' => true,
				'name' => true,
				'style' => true,
			),
			'option' => array(
				'value' => true,
				'selected' => true,
			),
			'label' => array(
				'for' => true,
				'style' => true,
			),
			'input' => array(
				'type' => true,
				'name' => true,
				'value' => true,
				'class' => true,
				'checked' => true,
			),
			'span' => array(
				'title' => true,
			),
		);
	}

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
			if (!self::getExportablePostTypes()) return;

			wp_add_dashboard_widget(
				'dashi_list_posttype_to_gen_csv',
				'CSV'.__('Export', 'dashi'),
				array('\\Dashi\\Core\\Posttype\\Csv', 'posttypeList')
			);
		});

		// CSV エクスポート処理（init 後で）
		add_action('admin_init', function () {
			if (!get_option('dashi_show_csv_export_dashboard')) return;

			$posttype = filter_input(INPUT_POST, 'dashi_csv_export');
			if (!is_string($posttype) || $posttype === '') return;

			check_admin_referer('dashi_csv_export_action');
			self::export($posttype);
		});
	}

	/**
	 * posttypeList
	 *
	 * @return Void
	 */
	public static function posttypeList()
	{
		$post_types = self::getExportablePostTypes();
		if (!$post_types) return;

		$html = '<form action="" method="post">';
		$html .= '<label for="dashi_csv_export">'.esc_html__('Post type to export', 'dashi').'</label>';
		$html .= '<select id="dashi_csv_export" name="dashi_csv_export" style="width: 100%;">';
		$html .= '<option value="">-</option>';
		foreach ($post_types as $post_type) {
			$html .= '<option value="'.esc_attr($post_type->name).'">'.esc_html($post_type->label).'</option>';
		}
		$html.= '</select>';
		$html.= '<label style="display: block; padding: 10px 0;"><input type="checkbox" name="excel_compati" value="1"><span title="'.esc_attr__('some characters disappear', 'dashi').'">'.esc_html__('Microsoft Excel compatible CSV', 'dashi').'</span></label>';
			$html.= '<input type="submit" class="button button-primary" value="'.esc_attr__('Export', 'dashi').'">';
			$html .= wp_nonce_field('dashi_csv_export_action', '_wpnonce', true, false);
			$html.= '</form>';
			echo wp_kses($html, self::getAllowedWidgetHtml());
	}

	/**
	 * CSV Export
	 *
	 * @param  String $posttype
	 * @return Void
	 */
	private static function export($posttype)
	{
		$post_types = self::getExportablePostTypes();
		if (!isset($post_types[$posttype])) {
			wp_die(esc_html__('You are not allowed to do this action.', 'dashi'), '', array('response' => 403));
		}

		$class = Posttype::posttype2class($posttype);
		$excel_compati = filter_input(INPUT_POST, 'excel_compati');

        $filename = sanitize_file_name($posttype) . '.csv';
        $delimiter = $excel_compati ? ',' : "\t";

        status_header(200);
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Transfer-Encoding: binary');
        header('Content-Disposition: attachment; filename=' . $filename);
        foreach (self::getEditablePosts($posttype) as $post) {
            $line = self::buildRow($post, $class::getFlatCustomFields());
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTMLではなく、数式対策済みのCSVを直接出力する。
            echo self::serializeRow(self::sanitizeCsvLine($line, $excel_compati), $delimiter);
        }
        exit();
    }

    private static function buildRow($post, $fields)
    {
        $row = (array) $post;
        if (!$fields) return $row;

        // この行で初めて読み込む投稿メタだけを一時的にキャッシュする。
        $primeMeta = wp_cache_get($post->ID, 'post_meta') === false;
        try {
            if ($primeMeta) {
                $cacheWasSuspended = wp_suspend_cache_addition();
                wp_suspend_cache_addition(false);
                try {
                    update_postmeta_cache([$post->ID]);
                } finally {
                    wp_suspend_cache_addition($cacheWasSuspended);
                }
            }
            foreach ($fields as $key => $field) {
                if (($field['store_data'] ?? true) == false) {
                    // 旧データが残っていても値を公開せず、既存の列位置は保つ。
                    $row[$key] = '';
                    continue;
                }
                $value = $post->$key ?? '';
                $options = \Dashi\Core\Util::resolveOptions($field['options'] ?? null);
                if (in_array($field['type'], ['select', 'radio', 'checkbox'], true)
                    && (is_string($value) || is_int($value)) && isset($options[$value])) {
                    $value = $options[$value];
                }
                $row[$key] = $value;
            }
        } finally {
            if ($primeMeta) wp_cache_delete($post->ID, 'post_meta');
        }
        return $row;
    }

    private static function getEditablePosts($posttype)
    {
        // 権限判定やカスタムフィールドの取得で投稿キャッシュを増やし続けない。
        $cacheWasSuspended = wp_suspend_cache_addition();
        wp_suspend_cache_addition(true);
        try {
            $page = 1;
            $lastPage = 1;
            do {
                $query = new \WP_Query([
                    'post_type' => $posttype,
                    'post_status' => 'publish',
                    'posts_per_page' => 100,
                    'paged' => $page,
                    'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
                    'ignore_sticky_posts' => true,
                    'suppress_filters' => false,
                    'no_found_rows' => $page > 1,
                    'cache_results' => false,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false,
                ]);
                if ($page === 1) $lastPage = (int) $query->max_num_pages;
                foreach ($query->posts as $post) {
                    if ($post instanceof \WP_Post && $post->post_type === $posttype && $post->post_status === 'publish'
                        && current_user_can('edit_post', $post->ID)
                        && apply_filters('dashi_search_can_show_result', true, $post)) {
                        yield $post;
                    }
                }
                unset($query);
            } while (++$page <= $lastPage);
        } finally {
            wp_suspend_cache_addition($cacheWasSuspended);
        }
    }

	private static function sanitizeCsvLine($line, $excel_compati)
	{
		return array_map(function ($cell) use ($excel_compati)
		{
			// UTF-8 のまま数式対策を行い、その後で Excel 用に変換する。
			$cell = self::sanitizeCsvCell($cell);
			return $excel_compati ? mb_convert_encoding($cell, 'SJIS', 'UTF-8') : $cell;
		}, is_array($line) ? $line : array($line));
	}

    /** 数式対策と文字コード変換を済ませたセルを、引用符の二重化で区切る。 */
    public static function serializeRow(array $cells, $delimiter)
    {
        foreach ($cells as &$cell) {
            if ($cell === '' || strpbrk($cell, $delimiter . "\"\r\n\t ") !== false) {
                $cell = '"' . str_replace('"', '""', $cell) . '"';
            }
        }
        return implode($delimiter, $cells) . "\n";
    }

	public static function sanitizeCsvCell($cell)
	{
		if (is_array($cell) || is_object($cell))
		{
			$cell = (string) wp_json_encode($cell);
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
		if (preg_match('/^\s*[=+\-@\t\r\n]/u', $cell)
			|| preg_match('/^[\x00-\x20]*[=+\-@\t\r\n]/', $cell))
		{
			return "'" . $cell;
		}

		return $cell;
	}
}
