<?php
namespace Dashi\Core\Posttype;

class Search
{
    private static $null_byte_deleted_s = '';

    /**
     * Remove null bytes from string safely
     *
     * @param string $str
     * @return string
     */
    private static function removeNullBytes($str)
    {
        return str_replace(["\0"], '', $str);
    }

    /**
     * delete null byte form string
     *
     * @return string
     */
    private static function nullBytelessS()
    {
        if (static::$null_byte_deleted_s !== '') return static::$null_byte_deleted_s;

        global $wp_query;

        if (isset($wp_query->query['s'])) {
            $cleaned = static::removeNullBytes($wp_query->query['s']);
            $wp_query->query['s'] = $cleaned; // sanitize in place
            static::$null_byte_deleted_s = $cleaned;
        }

        return static::$null_byte_deleted_s;
    }

    /**
     * posts_request Hook
     * Note: for cosmetic reasons only. Prevents visual artifact of null byte in search box.
     * @return string
     */
    public static function postsRequest($sql, $wp_query)
    {
        global $wp_query;

        // delete null-byte string from preset input field text
        if (isset($wp_query->query_vars['s']))
        {
            $wp_query->query_vars['s'] = static::removeNullBytes($wp_query->query_vars['s']);
        }

        $nullBytelessS = static::nullBytelessS();
        if ($nullBytelessS)
        {
            return static::removeNullBytes($sql);
        }

        if (empty($nullBytelessS) && $wp_query->is_search)
        {
            return '';
        }

        return $sql;
    }

    /**
     * distinct
     *
     * @return string
     */
    public static function searchDistinct($sql, $wp_query)
    {
        if (!is_search() || !static::nullBytelessS()) {
            return $sql;
        }

        // 正常に返す（WordPress expects string）
        return 'DISTINCT';
/*
        global $wpdb;

        // query_string exists
        if (isset($wp_query->query['s'])) $wp_query->is_search = true;
        if ( ! static::nullBytelessS()) return;
        if ( ! $wp_query->is_search) return;

        // modify sql
        return $sql.'DISTINCT';
*/
    }

    /**
     * join
     *
     * @return string
     */
    public static function searchJoin($join, $wp_query)
    {
        global $wpdb;

        // 管理画面では無効
        if (is_admin()) return $join;

        // 検索クエリが空なら追加しない
        $search_term = static::nullBytelessS();
        if (empty($search_term) || !$wp_query->is_search) {
            return $join;
        }

        // すでにJOINしていたら再JOINを避けたい（簡易チェック）
        if (strpos($join, 'post_metas') !== false) {
            return $join;
        }

        // JOIN句を追加
        $join .= " LEFT JOIN {$wpdb->postmeta} AS post_metas ON ";
        $join .= "({$wpdb->posts}.ID = post_metas.post_id)";
        return $join;

/*
        global $wp_query, $wpdb;

        if (is_admin()) return $join;

        // query_string exists
        if (isset($wp_query->query['s'])) $wp_query->is_search = true;
        if ( ! static::nullBytelessS()) return $join;
        if ( ! isset($wp_query->is_search) || ! $wp_query->is_search) return $join;
        if ( ! isset($obj->query['s'])) return $join;

        $join .= " LEFT OUTER JOIN {$wpdb->postmeta} AS post_metas ON ({$wpdb->posts}.ID = post_metas.post_id)";
        return $join;
*/
    }

    /**
     * add fields
     *
     * @return string
     */
    public static function searchFields($search, $wp_query)
    {
        global $wpdb;

        // 無効条件
        $search_term = static::nullBytelessS();
        if (empty($search_term) || !$wp_query->is_search) {
            return $search;
        }

        // 追加条件を組み立て
        $meta_search = $wpdb->prepare(
            " OR (post_metas.meta_key = %s AND post_metas.meta_value LIKE %s)",
            'dashi_search',
            '%' . $search_term . '%'
        );

        // 既存の WHERE に追加（やや安全な方法）
        if (preg_match('/\(\(\((.+?)\)\)\)/s', $search, $matches)) {
            // 複雑な構造の中に追加したい場合
            $search = str_replace($matches[0], '((' . $matches[1] . $meta_search . '))', $search);
        } else {
            // 通常の構造に単純追加
            $search .= $meta_search;
        }

        return $search;

/*
        global $wpdb;

        // query_string exists
        if (isset($wp_query->query['s'])) $wp_query->is_search = true;
        if ( ! static::nullBytelessS()) return $search;
        if ( ! $wp_query->is_search) return $search;

        // modify sql
        $sql = $wpdb->prepare(
            ") OR (post_metas.meta_key = 'dashi_search' AND post_metas.meta_value LIKE %s",
            '%'.static::nullBytelessS().'%');
        $search = str_replace(')))', $sql.')))', $search);

        return $search;
*/
    }

    /**
     * exclude
     *
     * @return string
     */
    public static function searchExclude($search, $wp_query)
    {
        if (!is_search() || !static::nullBytelessS()) {
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
            $placeholders = implode(',', array_fill(0, count($exclude_post_types), '%s'));
            $sql_not_in = $wpdb->prepare(" AND {$wpdb->posts}.post_type NOT IN ($placeholders)", ...$exclude_post_types);
            $search .= $sql_not_in;
        }

        return $search;
/*
        // query_string exists
        if (isset($wp_query->query['s'])) $wp_query->is_search = true;
        if ( ! static::nullBytelessS()) return $search;
        if ( ! $wp_query->is_search) return $search;

        foreach (Posttype::instances() as $class)
        {
            if ($class::get('is_searchable')) continue;
            $post_type = $class::get('post_type');
            $search = str_replace(", '{$post_type}'", '', $search);
        }
        return $search;
*/
    }

    /**
     * orderby
     *
     * @return string
     */
    public static function searchOrderby($orderby, $wp_query)
    {
        if (!is_search() || !static::nullBytelessS()) {
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
        $text = strip_tags($html);

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
        $sql = 'select post_name, post_type from '.$wpdb->posts.' where `post_status` = "publish"';
        $slugs_posttypes = $wpdb->get_results($sql);
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
