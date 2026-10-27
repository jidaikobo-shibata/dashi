<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Search
{
    /**
     * 元の入力を復活させず、現在のクエリで検証された検索語だけを使う。
     */
    private static function nullBytelessS($query)
    {
        $term = $query->get('s');
        if (!is_string($term) || strlen($term) > 1600) {
            return '';
        }
        return str_replace("\0", '', $term);
    }

    /**
     * 表示用の検索語からヌル文字を除く。SQL全体や元の入力は変更しない。
     */
    public static function postsRequest($sql, $wp_query)
    {
        $term = $wp_query->get('s');
        if (is_string($term)) {
            $wp_query->set('s', str_replace("\0", '', $term));
        }
        return $sql;
    }

    public static function searchDistinct($sql, $wp_query)
    {
        // EXISTSによる検索なので、重複排除の追加は不要。
        return $sql;
    }

    public static function searchJoin($join, $wp_query)
    {
        // メタ条件は相関サブクエリに閉じ込め、投稿行を増やさない。
        return $join;
    }

    /**
     * 検索語ごとに本文とメタを検索し、権限に関わる条件はORの外に置く。
     */
    public static function searchFields($search, $wp_query)
    {
        global $wpdb;

        $term = static::nullBytelessS($wp_query);
        $terms = $wp_query->get('search_terms');
        if (is_admin() || !$wp_query->is_search() || $term === '' || $search === ''
            || !is_array($terms) || !$terms) {
            return $search;
        }

        $ranges = static::searchTermRanges($search);
        if (count($ranges) !== count($terms)) {
            return $search;
        }

        $wildcard = $wp_query->get('exact') ? '' : '%';
        $replacements = [];
        foreach (array_values($terms) as $index => $word) {
            if (!is_string($word) || str_replace("\0", '', $word) === '') {
                return $search;
            }
            [$offset, $length] = $ranges[$index];
            $original = substr($search, $offset, $length);
            $exclude = null;

            // コアが生成するカラム比較だけの構造を照合する。
            // protectedプロパティやフィルターの再実行には依存しない。
            foreach ([false, true] as $negative) {
                $core_word = $negative ? substr($word, 1) : $word;
                $core_like = $wildcard . $wpdb->esc_like($core_word) . $wildcard;
                $operator = $negative ? 'NOT LIKE' : 'LIKE';
                $comparisons = [];
                foreach (['post_title', 'post_excerpt', 'post_content'] as $column) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 固定カラム名と固定演算子のみ。
                    $comparison = $wpdb->prepare("({$wpdb->posts}.{$column} {$operator} %s)", $core_like);
                    $comparisons[] = preg_quote($comparison, '~');
                }
                // 本体にファイル名検索がある場合は、その条件もそのまま保持する。
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 演算子はLIKEまたはNOT LIKEのみ。
                $comparison = $wpdb->prepare("(sq1.meta_value {$operator} %s)", $core_like);
                $comparisons[] = preg_quote($comparison, '~');
                $comparison_pattern = '(?:' . implode('|', $comparisons) . ')';
                $separator = $negative ? ' AND ' : ' OR ';
                $pattern = '~\A\(' . $comparison_pattern
                    . '(?:' . $separator . $comparison_pattern . ')*\)\z~s';
                if (preg_match($pattern, $original) === 1) {
                    $exclude = $negative;
                    break;
                }
            }
            if ($exclude === null) {
                // 未知の構造は拡張しない。既存の制限を破棄・移動しない。
                return $search;
            }

            $word = str_replace("\0", '', $exclude ? substr($word, 1) : $word);
            $like = $wildcard . $wpdb->esc_like($word) . $wildcard;
            $exists = $exclude ? 'NOT EXISTS' : 'EXISTS';
            $meta = $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- EXISTSまたはNOT EXISTSの固定文字列のみ。
                "{$exists} (SELECT 1 FROM {$wpdb->postmeta} AS dashi_search_meta"
                . " WHERE dashi_search_meta.post_id = {$wpdb->posts}.ID"
                . ' AND dashi_search_meta.meta_key = %s AND dashi_search_meta.meta_value LIKE %s)',
                'dashi_search',
                $like
            );
            $replacements[] = [
                $offset,
                $length,
                '(' . $original . ($exclude ? ' AND ' : ' OR ') . $meta . ')',
            ];
        }

        // オフセットがずれないよう後ろから置換。外側の保護条件は変更しない。
        foreach (array_reverse($replacements) as [$offset, $length, $replacement]) {
            $search = substr_replace($search, $replacement, $offset, $length);
        }
        return $search;
    }

    /**
     * コア検索句「AND (語句 AND 語句 ...)」内の語句の位置だけを取得する。
     * SQL文字列・識別子内の括弧は数えず、想定外の構造は受け付けない。
     *
     * @return array<int, array{int, int}>
     */
    private static function searchTermRanges($search)
    {
        if (!preg_match('/\A\s*AND\s*\(/i', $search, $opening)) {
            return [];
        }
        $start = strlen($opening[0]);
        $depth = 1;
        $quote = null;
        $ranges = [];
        $group_start = null;
        $previous_end = $start;
        $size = strlen($search);

        for ($i = $start; $i < $size; ++$i) {
            $char = $search[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    ++$i;
                } elseif ($char === $quote) {
                    if ($i + 1 < $size && $search[$i + 1] === $quote) {
                        ++$i;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                if ($depth < 2) {
                    return [];
                }
                $quote = $char;
            } elseif ($char === '(') {
                if ($depth === 1) {
                    $gap = substr($search, $previous_end, $i - $previous_end);
                    $expected = $ranges ? '/\A\s+AND\s+\z/i' : '/\A\s*\z/';
                    if (!preg_match($expected, $gap)) {
                        return [];
                    }
                    $group_start = $i;
                }
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
                if ($depth === 1 && $group_start !== null) {
                    $ranges[] = [$group_start, $i - $group_start + 1];
                    $previous_end = $i + 1;
                    $group_start = null;
                } elseif ($depth === 0) {
                    return trim(substr($search, $previous_end, $i - $previous_end)) === '' ? $ranges : [];
                }
            }
        }
        return [];
    }

    /**
     * exclude
     *
     * @return string
     */
    public static function searchExclude($search, $wp_query)
    {
        if (!$wp_query->is_search() || static::nullBytelessS($wp_query) === '') {
            return $search;
        }

        // 除外対象の post_type を収集
        $exclude_post_types = [];
        foreach (Posttype::instances() as $class) {
            if (!$class::get('is_searchable')) {
                $exclude_post_types[] = $class::get('post_type');
            }
        }

	        // 除外する post_type があるなら追加条件を SQL に加える
	        if (!empty($exclude_post_types)) {
	            global $wpdb;
	            $quoted_post_types = array_map(
	                static function ($post_type) use ($wpdb) {
	                    return $wpdb->prepare('%s', $post_type);
	                },
	                $exclude_post_types
	            );
	            $sql_not_in = " AND {$wpdb->posts}.post_type NOT IN (" . implode(',', $quoted_post_types) . ')';
	            $search .= $sql_not_in;
	        }

        return $search;

    }

    /**
     * orderby
     *
     * @return string
     */
    public static function searchOrderby($orderby, $wp_query)
    {
        if (!$wp_query->is_search() || static::nullBytelessS($wp_query) === '') {
            return $orderby;
        }

        // 将来のカスタマイズのためにプレースホルダ
        // 例: postmetaの値でソートしたい場合など
        // $orderby = "{$wpdb->postmeta}.meta_value ASC";

        return $orderby;
    }

    /*
     * generate search str
     * include alt text
     * @param strings $str
     * @return strings
     */
    public static function generateSearchStr($html)
    {
        // alt属性とvalue属性の値をプレーンテキストに残す
        $html = preg_replace('/<img [^>]*?alt=["\']([^"\']*?)["\'][^>]*?>/i', '$1', $html);
        $html = preg_replace('/<input [^>]*?value=["\']([^"\']*?)["\'][^>]*?>/i', '$1', $html);

        // script / style を除去
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);

        // タグ除去
        $text = wp_strip_all_tags($html);

        // 改行・全角スペースを半角スペースに
        $text = str_replace(["\n", "\r", '　'], ' ', $text);

        // 多重スペースを1つに
        $text = preg_replace('/\s+/', ' ', $text);

        // トリム
        return trim($text);
    }

    /*
     * store unsearchable pages
     */
    public static function addPages()
    {
         if ( ! is_admin()) return;

        // fetch html
        $res = wp_remote_get(home_url(), array('timeout' => 10, 'sslverify' => true));

        if (isset($res->errors) && $res->errors) return;

        // specified url
        $specified_urls = explode("\n", get_option('dashi_specify_search_index'));
        $specified_urls = array_map('trim', $specified_urls);

        // from html
        $html = $res['body'];
        preg_match_all("/[ \n](?:href|action) *?= *?[\"']([^\"']+?)[\"']/i", $html, $ms);
        if ( ! $ms) return;

        // load function
        if ( ! function_exists('is_user_logged_in'))
        {
            require(ABSPATH.WPINC.'/pluggable.php');

            // suppress error wp-includes/post.php on line 4153
            global $wp_rewrite;
            $wp_rewrite = (object) array('feeds' => array());
        }

        $target_urls = array_merge($ms[1], $specified_urls);

        // for eliminate normal page and assets
        $urls = self::eliminateAssets($target_urls, self::getBlackList());

        // check db
        $posts = get_posts('post_type=crawlsearch&numberposts=-1&post_status=any');
        $titles = array();
        foreach ($posts as $post)
        {
            $titles[$post->post_title] = $post->ID;
        }

        // store to database
        $ids = array();
        foreach ($urls as $url)
        {
            if (strpos($url, home_url()) !== 0) continue;

            // fetch each html
            $res = wp_remote_get($url, array('timeout' => 10, 'sslverify' => true,));
            if (isset($res->errors) && $res->errors) continue;
            $html = $res['body'];

            // non target pages
            preg_match('/\<body ([^\>]+?)\>/', $html, $ms);
            if ( ! $ms || preg_match('/single/', $ms[1])) continue;

            // update database
            $ids = self::updateDatabase($html, $titles, $url, $ids);
        }

        // garbage collector
        foreach ($posts as $post)
        {
            if (in_array($post->ID, $ids)) continue;
            wp_delete_post($post->ID, $force_delete = 1);
        }
    }

    /**
     * updateDatabase
     *
     * @param $html string
     * @param $titles array
     * @param $url string
     * @param $ids array
     * @return array
     */
    private static function updateDatabase($html, $titles, $url, $ids)
    {
        // get page title
        preg_match('/\<title[^\>]*?\>([^\<]+?)\</', $html, $ts);

        // words
        $html = static::generateSearchStr($html);

        // obj
        $item = array();
        $title = isset($ts[1]) ? $ts[1] : $url;
        $item['post_title'] = sanitize_text_field($title);
        $item['post_name']    = 'crawlsearch-'.microtime();
        $item['post_content'] = wp_strip_all_tags($html);
        $item['post_type']    = 'crawlsearch';
        $item['post_status']  = 'publish';

        // update
        if (array_key_exists($title, $titles))
        {
            $id = $titles[$title];
            $item['ID'] = $id;
            wp_update_post($item);
        }
        // add
        else
        {
            $id = wp_insert_post($item);
        }
        $ids[] = $id;

        // add postmeta
        update_post_meta($id, 'dashi_redirect_to', $url);

        return $ids;
    }

    /**
     * getBlackList
     *
     * @return array
     */
	    private static function getBlackList()
	    {
	        global $wpdb;
	        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 既存公開スラッグのブラックリスト生成に使用。
	        $slugs_posttypes = $wpdb->get_results(
	            $wpdb->prepare(
                'SELECT post_name, post_type FROM '.$wpdb->posts.' WHERE post_status = %s',
                'publish'
            )
        );
        $black_list = array();
        foreach ($slugs_posttypes as $slugs_posttype)
        {
            if (in_array($slugs_posttype->post_type, array('crawlsearch', 'pagepart', 'editablehelp'))) continue;
            if (in_array($slugs_posttype->post_type, array('post', 'page')))
            {
                $black_list[] = '/'.$slugs_posttype->post_name;
            }
            else
            {
                $black_list[] = '/'.$slugs_posttype->post_type.'/'.$slugs_posttype->post_name;
            }
        }
        return $black_list;
    }

    /**
     * eliminateAssets
     *
     * @param array $target_urls
     * @param array $black_list
     * @return array
     */
    private static function eliminateAssets($target_urls, $black_list)
    {
        $urls = array(home_url());
        foreach ($target_urls as $v)
        {
            if (strpos($v, 'wp-content') !== false) continue;
            if (strpos($v, wp_login_url()) !== false) continue;
            if (strpos($v, '/wp-json') !== false) continue;
            if (strpos($v, '/feed') !== false) continue;
            if (strpos($v, home_url()) === false) continue;
            if (strpos($v, '?p=') !== false) continue; // indexable page

            // indexable page
            foreach ($black_list as $black)
            {
                if (strpos($v, $black) !== false) continue 2;
            }

            $urls[] = rtrim(trim($v), '/');
        }

        $urls = array_unique($urls);
        return $urls;
    }
}
