<?php
namespace Dashi\Core\Posttype;

use Dashi\Core\Option;

if (!defined('ABSPATH')) exit;

class Search
{
    public const CRAWL_CONTINUATION = 'dashi_crawl_continue_hook';
    public const CRAWL_QUEUE = 'dashi_crawl_queue';
    private const CRAWL_RECOVERY = 'dashi_crawl_recovery';
    private const CRAWL_WITHDRAWALS = 'dashi_crawl_withdrawals';
    private const CRAWL_ERROR = 'dashi_crawl_error';
    private const CRAWL_LAST_RUN = 'dashi_crawl_last_run';
    private const CRAWL_NOTICE_GRACE = 30 * MINUTE_IN_SECONDS;
    private const CRAWL_BATCH_SIZE = 12;
    private const CRAWL_BATCH_SECONDS = 15;
    private const INDEX_MAX_AGE = 7 * DAY_IN_SECONDS;
    private const CRAWL_DISCOVERY = 'dashi_crawl_discovery';
    private const CRAWL_FORMAT_META = '_dashi_crawl_format';
    private const CRAWL_VERIFIED_META = '_dashi_crawl_verified_at';
    private const CRAWL_FORMAT = '8';
    private const CRAWL_HOME_GENERATION = 'dashi_crawl_home_generation';
    private const CRAWL_HOME_BLOCK = 'dashi_crawl_home_block';
    private const CRAWL_DISCOVERY_FORMAT = 3;
    private const CRAWL_RETRY_REFRESH = 'retry-refresh';
    private const CRAWL_RETRY_PROBE = 'retry-probe';
    private const CRAWL_RETRY_JOBS = [self::CRAWL_RETRY_REFRESH, self::CRAWL_RETRY_PROBE];
    private const PAGE_PART_SEARCH_IDS = 'dashi_page_part_search_ids';
    private const NATIVE_SEARCH_COUNT = 'dashi_use_native_search_count';

    public const TEXT_INDEX_META = '_dashi_search_public_text_v10';
    public const FIELD_GENERATION_META = '_dashi_search_field_generation';
    public const FIELD_BLOCK_OPTION_PREFIX = 'dashi_search_blocked_';
    public const PAGE_PART_INDEX_META = '_dashi_search_pagepart_v10';
    public const PAGE_PART_TEXT_META = '_dashi_search_pagepart_text_v10';
    private const CONTENT_INDEX_META = '_dashi_search_content_v10';
    private const EXCERPT_INDEX_META = '_dashi_search_excerpt_v10';
    private const SAVED_INDEX_COMPLETE = 'dashi_search_saved_v10_complete';
    private const SAVED_INDEX_EPOCH = 'dashi_search_saved_epoch';
    private const SAVED_INDEX_EPOCH_BLOCK = 'dashi_search_saved_epoch_block';
    public const SAVED_INDEX_EPOCH_RETRY = 'dashi_search_saved_epoch_retry';
    private const SAVED_INDEX_EPOCH_META = '_dashi_search_saved_epoch';

    /** 公開検索用に、表示を実行せず通常の Page Part 参照だけを抽出する。 */
    public static function pagePartSlugs($content)
    {
        $content = self::visibleMarkup($content);
        global $shortcode_tags;
        $tags = array_unique(array_merge(['get_pagepart'], array_keys($shortcode_tags)));
        preg_match_all('/' . get_shortcode_regex($tags) . '/s', wp_strip_all_tags($content), $matches, PREG_SET_ORDER);
        $slugs = [];
        foreach ($matches as $match) {
            if ($match[2] !== 'get_pagepart' || ($match[1] === '[' && $match[6] === ']')) continue;
            $atts = shortcode_parse_atts($match[3]);
            $slug = $atts['slug'] ?? '';
            if (!is_string($slug) || !preg_match('/\A[a-z0-9_-]{1,200}\z/i', $slug)) continue;
            $slug = strtolower($slug);
            $slugs[$slug] = $slug;
            if (count($slugs) >= 32) break;
        }
        return array_values($slugs);
    }

    public static function updatePagePartIndex($post_id, $post)
    {
        if ($post->post_type === 'revision') return;
        $epoch = self::savedIndexEpoch();
        $text = self::savedText($post->post_content);
        update_post_meta($post_id, self::CONTENT_INDEX_META, wp_slash(self::packIndexValue($post->post_content, $text)));
        update_post_meta($post_id, self::EXCERPT_INDEX_META, wp_slash(self::packIndexValue(
            $post->post_excerpt, self::savedText($post->post_excerpt)
        )));
        update_post_meta($post_id, self::SAVED_INDEX_EPOCH_META, $epoch);
        if ($post->post_type === 'pagepart') {
            if ($text === '' || $post->post_status !== 'publish' || $post->post_password !== '') {
                delete_post_meta($post_id, self::PAGE_PART_TEXT_META);
            } else {
                update_post_meta($post_id, self::PAGE_PART_TEXT_META, wp_slash(self::packIndexValue($post->post_content, $text)));
            }
        }
        $slugs = array_map(static function ($slug) use ($post) {
            return self::packIndexValue($post->post_content, $slug);
        }, self::pagePartSlugs($post->post_content));
        if ($slugs === get_post_meta($post_id, self::PAGE_PART_INDEX_META)) return;
        delete_post_meta($post_id, self::PAGE_PART_INDEX_META);
        foreach ($slugs as $slug) add_post_meta($post_id, self::PAGE_PART_INDEX_META, $slug);
    }

    public static function packIndexValue($source, $value)
    {
        // 出典と値を同じメタ行に保存し、並行更新でも組が崩れないようにする。
        return hash('sha256', $source) . $value;
    }

    public static function savedIndexEpoch()
    {
        return (string) get_option(self::SAVED_INDEX_EPOCH, 'initial');
    }

    public static function invalidateSavedIndexEpoch()
    {
        $blocked = get_option(self::SAVED_INDEX_EPOCH_BLOCK);
        $epoch = wp_generate_uuid4();
        if (!update_option(self::SAVED_INDEX_EPOCH, $epoch, false) || self::savedIndexEpoch() !== $epoch) {
            update_option(self::SAVED_INDEX_EPOCH_BLOCK, wp_generate_uuid4(), false);
            wp_cache_set('last_changed', microtime(), 'posts');
            self::scheduleSavedIndexEpochRetry();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- DB障害で停止状態も保存できない場合に記録する。
            error_log('Dashi: could not save the search index epoch.');
            return;
        }
        if (!$blocked) return;

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 並行処理が追加した停止状態は解除しない。
        $cleared = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::SAVED_INDEX_EPOCH_BLOCK,
            $blocked
        ));
        if ($cleared) {
            wp_cache_delete(self::SAVED_INDEX_EPOCH_BLOCK, 'options');
            wp_cache_set('last_changed', microtime(), 'posts');
        } else {
            self::scheduleSavedIndexEpochRetry();
        }
    }

    public static function retrySavedIndexEpoch()
    {
        self::invalidateSavedIndexEpoch();
    }

    private static function scheduleSavedIndexEpochRetry()
    {
        if (!wp_next_scheduled(self::SAVED_INDEX_EPOCH_RETRY)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::SAVED_INDEX_EPOCH_RETRY);
        }
    }

    private static function publicSearchPolicySql($query)
    {
        global $wpdb;
        $default = Save::publicSearchPolicy(null);
        $cases = [];
        $slugs = Base::customFieldSlugs();
        if ($slugs === null) return 'NULL';
        $definitions = [];
        $types = (array) $query->get('post_type');
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 固定カラムとprepare済みの分岐。
        foreach ($slugs as $slug) {
            $fields = Base::slugCustomFields($slug);
            if ($fields === null) {
                $cases[] = $wpdb->prepare("WHEN BINARY {$wpdb->posts}.post_name = %s THEN NULL", $slug);
            } elseif ($fields) {
                $definitions[$slug] = $fields;
            }
        }
        foreach (Posttype::instances() as $class) {
            $type = $class::get('post_type');
            if ($types && !in_array('any', $types, true) && !in_array($type, $types, true)) continue;
            if (!$class::get('is_searchable')) continue;
            $policy = Save::publicSearchPolicy($class, null, null, []);
            foreach ($definitions as $slug => $fields) {
                $specific = Save::publicSearchPolicy($class, $slug, null, $fields);
                if ($specific !== $policy) {
                    $cases[] = $wpdb->prepare(
                        "WHEN {$wpdb->posts}.post_type = %s AND BINARY {$wpdb->posts}.post_name = %s THEN %s",
                        $type, $slug, $specific
                    );
                }
            }
            if ($policy !== $default) {
                $cases[] = $wpdb->prepare("WHEN {$wpdb->posts}.post_type = %s THEN %s", $type, $policy);
            }
        }
        $fallback = $wpdb->prepare('%s', $default);
        return $cases ? '(CASE ' . implode(' ', $cases) . " ELSE {$fallback} END)" : $fallback;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public static function savedText($content)
    {
        return self::generateSearchStr(strip_shortcodes($content));
    }

    public static function resultSummary($post)
    {
        if (!apply_filters('dashi_search_can_show_result', true, $post)) return null;
        if (post_password_required($post)) return '';
        $excerpt = self::savedText($post->post_excerpt);
        return $excerpt !== '' ? $excerpt : self::savedText($post->post_content);
    }

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
        if ((is_admin() && !wp_doing_ajax()) || !$wp_query->is_search() || $term === '' || $search === ''
            || !is_array($terms) || !$terms) {
            return $search;
        }

        $ranges = static::searchTermRanges($search);
        if (count($ranges) !== count($terms)) return $search;

        // PHP側の閲覧判定はSQLの相関サブクエリでは保証できない。
        static $resolvingPolicy = false;
        $expand = !$resolvingPolicy && !$wp_query->get('search_columns')
            && !has_filter('dashi_search_can_show_result') && !has_filter('post_password_required');
        $policySql = '';
        $pagePartIds = self::pagePartIdSql($wp_query);
        if ($expand) {
            // フィールド定義のフィルターが検索を行っても、定義の解決を再帰させない。
            $resolvingPolicy = true;
            try {
                $policySql = self::publicSearchPolicySql($wp_query);
            } finally {
                $resolvingPolicy = false;
            }
        }
        $epoch = self::savedIndexEpoch();

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
            foreach (['post_content' => self::CONTENT_INDEX_META, 'post_excerpt' => self::EXCERPT_INDEX_META] as $column => $key) {
                $operator = $exclude ? 'NOT LIKE' : 'LIKE';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 固定されたカラムと演算子。
                $core = $wpdb->prepare("({$wpdb->posts}.{$column} {$operator} %s)", $like);
                $legacyJoin = $exclude ? 'AND NOT' : 'OR';
                // phpcs:disable WordPress.DB.PreparedSQL -- 補間は固定カラムと固定演算子のみ。検索語とメタキーはプレースホルダーで渡す。
                $comparison = $wpdb->prepare(
                    "({$exists} (SELECT 1 FROM {$wpdb->postmeta} AS visible_text
                        WHERE visible_text.post_id = {$wpdb->posts}.ID AND visible_text.meta_key = %s
                        AND LEFT(visible_text.meta_value, 64) = SHA2({$wpdb->posts}.{$column}, 256)
                        AND SUBSTRING(visible_text.meta_value, 65) LIKE %s)
                    {$legacyJoin} (NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} AS indexed_text
                        WHERE indexed_text.post_id = {$wpdb->posts}.ID AND indexed_text.meta_key = %s)
                        AND LOCATE('<', {$wpdb->posts}.{$column}) = 0 AND {$wpdb->posts}.{$column} LIKE %s))",
                    $key, $like, $key, $like
                );
                // phpcs:enable WordPress.DB.PreparedSQL
                $original = str_replace($core, $comparison, $original);
            }
            if (!$expand) {
                $replacements[] = [$offset, $length, $original];
                continue;
            }
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 固定演算子とprepare済みの公開設定を使用する。
            $meta = $wpdb->prepare(
                "{$exists} (SELECT 1 FROM {$wpdb->postmeta} AS dashi_search_meta"
                . " WHERE dashi_search_meta.post_id = {$wpdb->posts}.ID"
                . ' AND dashi_search_meta.meta_key = %s'
                . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->options} AS field_block
                    WHERE field_block.option_name = CONCAT(%s, {$wpdb->posts}.ID))"
                . " AND NOT EXISTS (SELECT 1 FROM {$wpdb->options} AS epoch_block WHERE epoch_block.option_name = %s)"
                . " AND COALESCE((SELECT option_value FROM {$wpdb->options} WHERE option_name = %s), 'initial') = %s"
                . " AND LEFT(dashi_search_meta.meta_value, 64) = SHA2(CONCAT(
                    {$wpdb->posts}.post_title, CHAR(0), {$wpdb->posts}.post_content, CHAR(0),
                    COALESCE((SELECT CASE WHEN COUNT(DISTINCT field_generation.meta_value) > 1
                        THEN 'invalid' ELSE MAX(field_generation.meta_value) END
                        FROM {$wpdb->postmeta} AS field_generation
                        WHERE field_generation.post_id = {$wpdb->posts}.ID AND field_generation.meta_key = %s), ''),
                    {$policySql}, %s
                    ), 256)"
                . ' AND SUBSTRING(dashi_search_meta.meta_value, 65) LIKE %s)',
                self::TEXT_INDEX_META,
                self::FIELD_BLOCK_OPTION_PREFIX,
                self::SAVED_INDEX_EPOCH_BLOCK,
                self::SAVED_INDEX_EPOCH,
                $epoch,
                self::FIELD_GENERATION_META,
                $epoch,
                $like
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $clauses = [$original, $meta];
            if ($pagePartIds) {
                // 参照先の現在の公開状態を使う。参照元には Page Part の本文を保存しない。
                $clauses[] = $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- EXISTSまたはNOT EXISTSの固定演算子と検証済みの整数IDのみ。
                    "{$exists} (SELECT 1 FROM {$wpdb->postmeta} AS dashi_page_ref"
                    . " INNER JOIN {$wpdb->posts} AS dashi_page_part ON dashi_page_part.post_name = SUBSTRING(dashi_page_ref.meta_value, 65)"
                    . " INNER JOIN {$wpdb->postmeta} AS dashi_page_text ON dashi_page_text.post_id = dashi_page_part.ID"
                    . " WHERE dashi_page_ref.post_id = {$wpdb->posts}.ID AND dashi_page_ref.meta_key = %s"
                    . " AND dashi_page_part.post_type = %s AND dashi_page_part.post_status = %s"
                    . " AND dashi_page_part.ID IN ({$pagePartIds})" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 検証済みの整数IDのみ。
                    . " AND dashi_page_part.post_parent = 0 AND dashi_page_part.post_password = ''"
                    . " AND LEFT(dashi_page_ref.meta_value, 64) = SHA2({$wpdb->posts}.post_content, 256)"
                    . ' AND dashi_page_text.meta_key = %s'
                    . ' AND LEFT(dashi_page_text.meta_value, 64) = SHA2(dashi_page_part.post_content, 256)'
                    . ' AND SUBSTRING(dashi_page_text.meta_value, 65) LIKE %s)',
                    self::PAGE_PART_INDEX_META,
                    'pagepart',
                    'publish',
                    self::PAGE_PART_TEXT_META,
                    $like
                );
            }
            $separator = $exclude ? ' AND ' : ' OR ';
            $replacements[] = [
                $offset,
                $length,
                '(' . implode($separator, $clauses) . ')',
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
        $html = self::visibleMarkup($html);
        // 画像の代替テキストを残す。入力値には非表示のトークンなどが含まれる。
        $html = preg_replace('/<img [^>]*?alt=["\']([^"\']*?)["\'][^>]*?>/i', '$1', $html);

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

    private static function visibleMarkup($html)
    {
        if (!preg_match('/<template\b|\bhidden\b/i', $html)) return $html;
        // DOMを使えない環境では、非表示の可能性がある断片を索引に入れない。
        if (!class_exists('\DOMDocument')) return '';
        $document = new \DOMDocument('1.0', 'UTF-8');
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return '';
        }
        $hidden = (new \DOMXPath($document))->query('//*[@hidden or self::template]');
        if (!$hidden->length) return $html;
        foreach ($hidden as $element) {
            $element->parentNode->removeChild($element);
        }
        return $document->documentElement ? $document->saveHTML($document->documentElement) : '';
    }

    /*
     * store unsearchable pages
     */
    private static function crawlHome()
    {
        // 管理画面やCronの接続方式で巡回範囲が変わらないよう、設定上のスキームを使う。
        $scheme = wp_parse_url((string) get_option('home'), PHP_URL_SCHEME) === 'https' ? 'https' : 'http';
        return home_url('', $scheme);
    }

    private static function crawlScope($home, $generation = null)
    {
        $scope = rtrim($home, '/') . "\0" . ($generation ?? self::observeCrawlHome());
        if (defined('DASHI_CRAWL_CONFIG_EPOCH')) {
            $scope .= "\0" . (string) DASHI_CRAWL_CONFIG_EPOCH;
        }
        return hash('sha256', $scope);
    }

    public static function guardCrawlHomeChange($newHome, $oldHome)
    {
        if ($newHome === $oldHome) return $newHome;

        // home を書き換える前に、現在の巡回世代を永続的に無効化する。
        $state = ['home' => rtrim((string) $newHome, '/'), 'generation' => wp_generate_uuid4()];
        if (update_option(self::CRAWL_HOME_GENERATION, $state, false)) return $newHome;

        update_option(self::CRAWL_ERROR, 1, false);
        if (is_admin() && function_exists('add_settings_error')) {
            add_settings_error('home', 'dashi_crawl_home_generation', __(
                'The site address was not changed because Dashi could not invalidate its search index.',
                'dashi'
            ));
        }
        return $oldHome;
    }

    public static function trackCrawlHome()
    {
        self::observeCrawlHome();
    }

    private static function observeCrawlHome()
    {
        // WP_HOME による上書きも反映し、リクエスト固有のURLフィルターには世代を変更させない。
        static $pending = [];
        $blogId = get_current_blog_id();
        $home = rtrim((string) get_option('home'), '/');
        if (($pending[$blogId]['home'] ?? null) === $home) {
            return $pending[$blogId]['generation'];
        }
        $previous = get_option(self::CRAWL_HOME_GENERATION, []);
        $blocked = get_option(self::CRAWL_HOME_BLOCK);
        if (!$blocked && is_array($previous) && ($previous['home'] ?? null) === $home
            && !empty($previous['generation'])) {
            return $previous['generation'];
        }

        $generation = wp_generate_uuid4();
        $state = ['home' => $home, 'generation' => $generation];
        $pending[$blogId] = $state;
        if (update_option(self::CRAWL_HOME_GENERATION, $state, false)) {
            unset($pending[$blogId]);
            if ($blocked) self::clearCrawlHomeBlock($blocked);
        } else {
            // 非表示の再試行キューは残し、旧世代を公開側から外す。
            update_option(self::CRAWL_HOME_BLOCK, wp_generate_uuid4(), false);
            delete_post_meta_by_key(self::CRAWL_FORMAT_META);
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 読み取りエラーと削除失敗を検出する。
            $remaining = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
                self::CRAWL_FORMAT_META
            ));
            if ($remaining !== null || $wpdb->last_error !== '') {
                update_option(self::CRAWL_ERROR, 1, false);
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- DB障害のため索引を削除できない場合に記録する。
                error_log('Dashi: could not invalidate previous crawl snapshots.');
            }
        }
        return $generation;
    }

    private static function clearCrawlHomeBlock($token)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 並行処理が設定した新しい停止状態は解除しない。
        $cleared = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::CRAWL_HOME_BLOCK,
            $token
        ));
        if ($cleared) wp_cache_delete(self::CRAWL_HOME_BLOCK, 'options');
    }
    private static function isInCrawlScope($url)
    {
        $site = wp_parse_url(self::crawlHome());
        $target = wp_parse_url($url);
        if (!$site || !$target || empty($site['host']) || empty($target['host'])
            || isset($target['user']) || isset($target['pass'])
            || !in_array($target['scheme'] ?? '', ['http', 'https'], true)
            || $target['scheme'] !== ($site['scheme'] ?? '')
            || strcasecmp($site['host'], $target['host']) !== 0) return false;
        $default_port = $target['scheme'] === 'https' ? 443 : 80;
        if (($target['port'] ?? $default_port) !== ($site['port'] ?? $default_port)) return false;
        $sitePath = self::crawlScopePath($site['path'] ?? '/');
        $path = self::crawlScopePath($target['path'] ?? '/');
        if ($sitePath === null || $path === null) return false;
        $root = rtrim($sitePath, '/');
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    private static function crawlScopePath($path)
    {
        // サーバーごとに解釈の異なる区切りや複数回の復号によるパス移動を許可しない。
        if (preg_match('/%2f|%5c|%25|[\x00-\x20\x7f]/i', $path)) return null;
        $path = rawurldecode($path);
        if (strpos($path, '\\') !== false || preg_match('~[\x00-\x1f\x7f]|(?:^|/)\.{1,2}(?:/|$)~', $path)) return null;
        return $path;
    }

    /** クローラーのHTTPリクエストはホームURLのオリジンとパス配下に限定する。 */
    private static function fetchIndexPage($url, &$accessDenied = null, $timeout = 10, &$retryable = null)
    {
        $accessDenied = false;
        $retryable = false;
        if (!self::isInCrawlScope($url)) return null;

        $response = \Dashi\Core\Util::getUncompressedResponse(
            $url, $timeout, 2 * 1024 * 1024, ['Cache-Control' => 'no-cache']
        );
        $mitigation = wp_remote_retrieve_header($response, 'cf-mitigated');
        if (is_string($mitigation) && strcasecmp(trim($mitigation), 'challenge') === 0) {
            $retryable = true;
            return null;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $accessDenied = in_array($status, [401, 403, 404, 410], true);
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_string($location) && trim($location) !== '') {
                $destination = \WP_Http::make_absolute_url(trim($location), $url);
                $accessDenied = apply_filters(
                    'dashi_crawl_access_denied_redirect',
                    self::isLoginDestination($destination),
                    $url,
                    $destination,
                    $status
                ) === true;
            }
        }
        $headers = wp_remote_retrieve_headers($response);
        // Requestsの連結済みの値では、クローラー指定が次のヘッダー行に及んでしまう。
        $robotsHeaders = is_object($headers) && is_callable([$headers, 'getValues'])
            ? $headers->getValues('x-robots-tag')
            : wp_remote_retrieve_header($response, 'x-robots-tag');
        $accessDenied = $accessDenied || self::hasNoindexHeader($robotsHeaders);
        if ($status !== 200) {
            $retryable = !$accessDenied && ($status === 0 || $status === 408 || $status === 429
                || ($status >= 500 && $status < 600));
            return null;
        }
        $cached = self::isCachedCrawlResponse($response);
        $retryable = $cached;
        $contentType = wp_remote_retrieve_header($response, 'content-type');
        $mediaType = is_string($contentType) ? strtolower(trim(explode(';', $contentType, 2)[0])) : '';
        if (!in_array($mediaType, ['text/html', 'application/xhtml+xml'], true)) {
            // キャッシュされていない応答でHTML以外に変わったと確認できた場合は、既存の索引も隠す。
            $accessDenied = $accessDenied || !$cached;
            return null;
        }
        $html = wp_remote_retrieve_body($response);
        $accessDenied = $accessDenied || self::hasNoindexMeta($html);
        // noindex はこのURLの索引だけを止める。新鮮なHTMLからのリンク発見には使える。
        return $cached ? null : $html;
    }

    private static function hasNoindexHeader($headers)
    {
        foreach ((array) $headers as $header) {
            $applies = true;
            foreach (explode(',', strtolower($header)) as $directive) {
                if (preg_match('/^\s*([a-z0-9_*-]+)\s*:\s*(.*)$/s', $directive, $parts)) {
                    if (in_array($parts[1], ['unavailable_after', 'max-snippet', 'max-image-preview', 'max-video-preview'], true)) {
                        continue;
                    }
                    $applies = $parts[1] === '*';
                    $directive = $parts[2];
                }
                if ($applies && self::hasNoindexDirective($directive)) return true;
            }
        }
        return false;
    }

    private static function hasNoindexMeta($html)
    {
        // コメントや非表示のテンプレート内に書かれたタグは指示として扱わない。
        $html = preg_replace('~<!--.*?(?:-->|$)|<(script|style|title|template)\b[^>]*>.*?(?:</\1\s*>|$)~is', '', $html);
        if ($html === null) return true;
        $offset = 0;
        $pattern = '~<(/?)([a-z][a-z0-9:_-]*)(?=[\s/>])((?:[^>"\x27]|"[^"]*"|\x27[^\x27]*\x27)*)>~i';
        while (($matched = preg_match($pattern, $html, $tag, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $offset = $tag[0][1] + strlen($tag[0][0]);
            $element = strtolower($tag[2][0]);
            if (($element === 'head' && $tag[1][0] === '/') || $element === 'body') break;
            if ($element !== 'meta' || $tag[1][0] !== '') continue;
            $attributes = wp_kses_hair($tag[3][0], []);
            $name = strtolower(trim($attributes['name']['value'] ?? ''));
            $content = html_entity_decode($attributes['content']['value'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name === 'robots' && self::hasNoindexDirective($content)) return true;
        }
        return $matched === false;
    }

    private static function hasNoindexDirective($directives)
    {
        return (bool) preg_match('/(?:^|[\s,])(?:noindex|none)(?=$|[\s,])/i', $directives);
    }

    private static function isCachedCrawlResponse($response)
    {
        // キャッシュから返った応答では取得元の公開状態を確認できない。
        foreach ((array) wp_remote_retrieve_header($response, 'cache-status') as $value) {
            // RFC 9211 の hit パラメーターを、引用文字列の中身と区別する。
            $parameters = preg_replace('~"(?:[^"\\\\]|\\\\.)*"~', '""', $value);
            if ($parameters === null || preg_match('/;[ \t]*hit(?:=\?1)?(?=[ \t]*(?:[;,]|$))/', $parameters)) return true;
        }
        foreach (['age', 'cf-cache-status', 'x-cache'] as $name) {
            foreach ((array) wp_remote_retrieve_header($response, $name) as $value) {
                // CDNによっては TCP_HIT や TCP_REFRESH_FAIL_HIT のように下線で区切る。
                if ($name === 'age' ? (int) $value > 0 : preg_match('/(?:^|[^a-z0-9])(?:hit|stale|updating)(?![a-z0-9])/i', $value)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function isLoginDestination($url)
    {
        $target = wp_parse_url($url);
        $scheme = $target['scheme'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || empty($target['host'])
            || isset($target['user']) || isset($target['pass'])) return false;

        // ログインをHTTPSへ切り替える構成でも、アドレスと必要なクエリーが一致する場合だけ判定する。
        $loginUrl = \WP_Http::make_absolute_url(wp_login_url(), home_url('/'));
        $login = wp_parse_url(set_url_scheme($loginUrl, $scheme));
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if (empty($login['host']) || strcasecmp($target['host'], $login['host']) !== 0
            || ($target['port'] ?? $defaultPort) !== ($login['port'] ?? $defaultPort)
            || rtrim($target['path'] ?? '/', '/') !== rtrim($login['path'] ?? '/', '/')) return false;

        parse_str($login['query'] ?? '', $loginQuery);
        parse_str($target['query'] ?? '', $targetQuery);
        foreach ($loginQuery as $key => $value) {
            if (!array_key_exists($key, $targetQuery) || $targetQuery[$key] !== $value) return false;
        }
        return true;
    }

    public static function filterPublicIndexQueries($query)
    {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron()) return;
        $query->set(self::NATIVE_SEARCH_COUNT, false);
        $query->set(self::PAGE_PART_SEARCH_IDS, $query->is_search() ? self::pagePartSearchIds() : []);
        $includesCrawl = self::queryMayIncludeCrawlIndexes($query);
        if ($query->is_search() || $includesCrawl) $query->set('suppress_filters', false);
        // 巡回索引を含み得る検索では SQL の公開停止照合を毎回実行する。
        if ($includesCrawl) $query->set('cache_results', false);
    }

    private static function pagePartSearchIds()
    {
        static $resolving = false;
        if ($resolving || has_filter('dashi_search_can_show_result') || has_filter('post_password_required')) return [];
        $resolving = true;
        try {
            $ids = apply_filters('dashi_search_indexed_pagepart_ids', []);
        } finally {
            $resolving = false;
        }
        return $ids;
    }

    private static function pagePartIdSql($query)
    {
        if (has_filter('dashi_search_can_show_result') || has_filter('post_password_required')) return '';
        $ids = $query->get(self::PAGE_PART_SEARCH_IDS);
        if (!is_array($ids)) return '';
        $ids = array_filter($ids, static function ($id) {
            return is_int($id) && $id > 0;
        });
        return implode(', ', array_unique($ids));
    }

    private static function queryMayIncludeCrawlIndexes($query)
    {
        $type = $query->get('post_type');
        if ($type) return (bool) array_intersect(['crawlsearch', 'any'], (array) $type);
        // 投稿タイプを省略した検索やカスタム分類のクエリは複数の投稿タイプを対象にする。
        return $query->is_search() || $query->is_tax();
    }

    public static function excludeExternalCrawlIndexes($posts, $query)
    {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron()) return $posts;

        $protected = $query->is_search() || self::queryMayIncludeCrawlIndexes($query);
        if (!$protected) {
            if (!is_array($posts)) return $posts;
            // 公開検索以外でも、外部結果に巡回索引が混入した場合は SQL に戻す。
            $allowed = array_filter($posts, static function ($post) {
                if (is_object($post) && ($post->post_type ?? '') === 'crawlsearch') return false;
                $id = is_object($post) ? ($post->ID ?? 0) : $post;
                if (!is_int($id) && (!is_string($id) || !preg_match('/^[0-9]+$/D', $id))) return false;
                $type = (int) $id > 0 ? get_post_type((int) $id) : false;
                return $type && $type !== 'crawlsearch';
            });
            if (count($allowed) === count($posts)) return $posts;
        }

        // 外部検索が設定した件数を破棄し、公開条件を含む WordPress の SQL で集計する。
        $query->set(self::NATIVE_SEARCH_COUNT, $query->request ?: false);
        $query->set('cache_results', false);
        $query->found_posts = 0;
        $query->max_num_pages = 0;
        return null;
    }

    public static function skipSearchCountInResultQuery($sql, $query)
    {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron()
            || (!$query->is_search() && !self::queryMayIncludeCrawlIndexes($query))) return $sql;

        return preg_replace('/^(\s*SELECT\s+)SQL_CALC_FOUND_ROWS\s+/i', '$1', $sql) ?? $sql;
    }

    public static function keepNativeSearchCountQuery($sql, $query)
    {
        $request = $query->get(self::NATIVE_SEARCH_COUNT);
        if (!$request) return $sql;

        $unpaged = preg_replace('/\s+LIMIT\s+\d+(?:\s*,\s*\d+|\s+OFFSET\s+\d+)?\s*;?\s*$/iD', '', $request, 1, $removed);
        if (!$removed || $unpaged === null) return 'SELECT 0';
        $unpaged = preg_replace('/^(\s*SELECT\s+)SQL_CALC_FOUND_ROWS\s+/i', '$1', $unpaged);
        if ($unpaged === null) return 'SELECT 0';

        $unpaged = self::uniqueSearchCountColumns($unpaged);
        if ($unpaged === null) return 'SELECT 0';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- WordPress が確定した一覧 SQL の投影名とページ分割だけを変更する。
        return "SELECT COUNT(*) FROM ($unpaged) AS dashi_search_count";
    }

    private static function uniqueSearchCountColumns($sql)
    {
        global $wpdb;
        if (!preg_match('/^\s*SELECT\s+(?:DISTINCT(?:ROW)?\s+)?/i', $sql, $select)) return null;
        $start = strlen($select[0]);
        // 文字列とコメントに含まれる区切りや、関数・サブクエリ内のカンマを飛ばす。
        $tokens = <<<'REGEX'
~'(?:[^'\\]|\\.|'')*'|"(?:[^"\\]|\\.|"")*"|`(?:[^`]|\`\`)*`|/\*.*?\*/|--\s[^\r\n]*|\#[^\r\n]*|[(),]|\bFROM\b~is
REGEX;
        if (preg_match_all($tokens, $sql, $matches, PREG_OFFSET_CAPTURE, $start) === false) return null;
        $fields = [];
        $depth = 0;
        $offset = $start;
        $from = null;
        foreach ($matches[0] as [$token, $position]) {
            if ($token === '(') ++$depth;
            if ($token === ')') --$depth;
            if ($depth !== 0) continue;
            if ($token !== ',' && strcasecmp($token, 'FROM') !== 0) continue;
            $fields[] = substr($sql, $offset, $position - $offset);
            $offset = $position + 1;
            if ($token !== ',') {
                $from = $position;
                break;
            }
        }
        if ($from === null) return null;

        $names = [];
        // WordPress の標準投稿列。通常の posts.* はそのまま利用する。
        $postColumns = array_diff(array_keys(get_class_vars(\WP_Post::class)), ['filter']);
        $identifier = '(?:`(?:[^`]|\`\`)+`|[a-zA-Z0-9_$]+)';
        $quotedAlias = <<<'REGEX'
'(?:[^'\\]|\\.|'')+'|"(?:[^"\\]|\\.|"")+"
REGEX;
        $aliasName = '(?:' . $identifier . '|' . $quotedAlias . ')';
        foreach ($fields as $index => $field) {
            $field = trim($field);
            if ($field === $wpdb->posts . '.*' || $field === '`' . $wpdb->posts . '`.*') {
                foreach ($postColumns as $column) $names[strtolower($column)][] = [$index, null];
                continue;
            }

            $expression = $field;
            $name = $field;
            if (preg_match('/^(.+)\s+AS\s+(' . $aliasName . ')$/isD', $field, $alias)
                || preg_match('/^((?:' . $identifier . '\.)?' . $identifier . '|.+\))\s+(' . $aliasName . ')$/sD', $field, $alias)) {
                $expression = $alias[1];
                $name = $alias[2];
            } elseif (preg_match('/^(?:' . $identifier . '\.)?(' . $identifier . ')$/D', $field, $column)) {
                $name = $column[1];
            }
            $quote = $name[0] ?? '';
            if (in_array($quote, ["'", '"', '`'], true) && substr($name, -1) === $quote) {
                $name = str_replace($quote . $quote, $quote, substr($name, 1, -1));
            }
            $name = strtolower($name);
            $names[$name][] = [$index, $expression];
        }

        foreach ($names as $columns) {
            if (count($columns) < 2) continue;
            // ワイルドカードの列名を優先して残し、追加された同名の列だけを変更する。
            $keep = $columns[0][0];
            foreach ($columns as [$index, $expression]) {
                if ($expression === null) $keep = $index;
            }
            foreach ($columns as [$index, $expression]) {
                if ($index === $keep || $expression === null) continue;
                $alias = 'dashi_count_column_' . $index;
                while (isset($names[$alias])) $alias .= '_';
                $fields[$index] = ' ' . $expression . ' AS ' . $alias . ' ';
            }
        }

        return $select[0] . implode(',', $fields) . substr($sql, $from);
    }

    public static function keepNativeSearchCount($count, $query)
    {
        // この時点では WordPress が集計結果を found_posts に格納している。
        return $query->get(self::NATIVE_SEARCH_COUNT) ? $query->found_posts : $count;
    }

    public static function excludeStaleIndexes($where, $query)
    {
        if ((is_admin() && !wp_doing_ajax()) || wp_doing_cron()) return $where;
        global $wpdb;
        if ($query->is_search()) {
            $ids = self::pagePartIdSql($query);
            $allowed = $wpdb->prepare("{$wpdb->posts}.post_type <> %s", 'pagepart');
            if ($ids) $allowed .= " OR ({$wpdb->posts}.ID IN ({$ids}) AND {$wpdb->posts}.post_password = '')";
            $where .= " AND ({$allowed})";
        }
        if (!self::queryMayIncludeCrawlIndexes($query)) return $where;

        $query->set('cache_results', false);
        $now = time();
        $cutoff = intdiv($now, 60) * 60 - self::INDEX_MAX_AGE;
        $home = self::crawlHome();
        $generation = self::observeCrawlHome();
        $scope = self::crawlScope($home, $generation);
        $generationState = [
            'home' => rtrim((string) get_option('home'), '/'),
            'generation' => $generation,
        ];
        // 巡回キューとリンク一覧にある URL => false を SQL 実行時に照合する。
        return $where . $wpdb->prepare(
            " AND ({$wpdb->posts}.post_type <> %s OR (EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} AS crawl_format
                INNER JOIN {$wpdb->postmeta} AS crawl_verified ON crawl_verified.post_id = crawl_format.post_id
                WHERE crawl_format.post_id = {$wpdb->posts}.ID
                AND NOT EXISTS (SELECT 1 FROM {$wpdb->options} WHERE option_name = %s)
                AND EXISTS (SELECT 1 FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s)
                AND NOT EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} AS live_source
                    INNER JOIN {$wpdb->options} AS live_block ON live_block.option_name IN (%s, %s, %s, %s)
                    WHERE live_source.post_id = {$wpdb->posts}.ID AND live_source.meta_key = %s
                    AND (live_block.option_name <> %s OR LOCATE(BINARY %s, BINARY live_block.option_value) > 0)
                    AND LOCATE(BINARY CONCAT('s:', OCTET_LENGTH(live_source.meta_value), ':', CHAR(34),
                        live_source.meta_value, CHAR(34), ';b:0;'), BINARY live_block.option_value) > 0
                )
                AND crawl_verified.meta_key = %s AND CAST(crawl_verified.meta_value AS UNSIGNED) BETWEEN %d AND %d
                AND crawl_format.meta_key = %s AND crawl_format.meta_value = CONCAT(%s, SHA2(CONCAT(
                    SHA2((SELECT CASE WHEN COUNT(*) = 1 THEN MAX(crawl_source.meta_value) END
                        FROM {$wpdb->postmeta} AS crawl_source
                        WHERE crawl_source.post_id = {$wpdb->posts}.ID AND crawl_source.meta_key = %s), 256),
                    SHA2({$wpdb->posts}.post_title, 256), SHA2({$wpdb->posts}.post_content, 256),
                    SHA2(crawl_verified.meta_value, 256)
                ), 256))
            )))",
            'crawlsearch',
            self::CRAWL_HOME_BLOCK,
            self::CRAWL_HOME_GENERATION,
            maybe_serialize($generationState),
            self::CRAWL_WITHDRAWALS,
            self::CRAWL_QUEUE,
            self::CRAWL_RECOVERY,
            self::CRAWL_DISCOVERY,
            'dashi_redirect_to',
            self::CRAWL_DISCOVERY,
            serialize('scope') . serialize($scope),
            self::CRAWL_VERIFIED_META,
            $cutoff,
            $now,
            self::CRAWL_FORMAT_META,
            self::CRAWL_FORMAT . ':' . $scope . ':',
            'dashi_redirect_to'
        );
    }

    private static $crawlLock;

    private static function ownsCrawlLock()
    {
        if (self::$crawlLock === null) return true;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::$crawlLock
        )) === 1;
    }

    /** 再接続後に再実行されたSQLも、古い巡回の状態を書き込めないようにする。 */
    private static function writeCrawlOption($name, $value)
    {
        if (self::$crawlLock === null) return update_option($name, $value, false);
        global $wpdb;
        $old = get_option($name);
        $value = apply_filters("pre_update_option_{$name}", $value, $old, $name);
        $value = apply_filters('pre_update_option', $value, $name, $old);
        if ($value === $old) return false;
        $written = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             SELECT %s, %s, 'no' FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
            $name, maybe_serialize($value), self::$crawlLock
        ));
        if ($written) self::clearCrawlOptionCache($name);
        return (bool) $written;
    }

    private static function clearCrawlOptionCache($name)
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        if (array_key_exists($name, wp_load_alloptions())) wp_cache_delete('alloptions', 'options');
    }

    public static function addPages()
    {
        if (!is_admin() && (!wp_doing_cron()
            || (!doing_action('dashi_cron_hook') && !doing_action(self::CRAWL_CONTINUATION)))) return;
        self::runCrawl(doing_action('dashi_cron_hook'));
    }

    private static function runCrawl($daily)
    {
        if (self::$crawlLock !== null) return 'busy';

        global $wpdb;
        $lock = 'dashi_crawl_' . md5(DB_NAME . ':' . $wpdb->prefix);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DB接続単位の排他制御。
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock));
        if ((string) $acquired !== '1') return (string) $acquired === '0' ? 'busy' : 'failed';
        self::$crawlLock = $lock;
        try {
            update_option(self::CRAWL_LAST_RUN, time(), false);
            if (get_option(self::SAVED_INDEX_EPOCH_BLOCK)) self::retrySavedIndexEpoch();
            return self::crawlBatch($daily) ? 'processed' : 'failed';
        } finally {
            if (!self::ownsCrawlLock() && !wp_next_scheduled(self::CRAWL_CONTINUATION)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRAWL_CONTINUATION);
            }
            self::$crawlLock = null;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 取得したロックを解放する。
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function crawlBatch($daily = false)
    {
        $deadline = microtime(true) + self::CRAWL_BATCH_SECONDS;
        $configured = (string) get_option('dashi_specify_search_index');
        $state = self::readCrawlState();
        $home = self::crawlHome();
        $scope = self::crawlScope($home);
        $discovery = get_option(self::CRAWL_DISCOVERY, []);
        $links = ($discovery['scope'] ?? null) === $scope ? ($discovery['scoped_links'] ?? null) : null;
        $links = is_array($links) ? $links : null;
        $sameDiscovery = ($state['discovery_format'] ?? null) === self::CRAWL_DISCOVERY_FORMAT
            && ($state['scope'] ?? null) === $scope
            && ($state['discovery_revision'] ?? null) === self::crawlDiscoveryRevision($links);
        $queue = $sameDiscovery && ($state['configured'] ?? null) === $configured ? ($state['jobs'] ?? []) : [];
        $backoff = array_intersect_key($state['refresh_backoff'] ?? [], $queue);
        // 設定が変わっても、失敗済みの非表示処理は保持する。
        $withdrawals = ($state['withdrawals'] ?? []) + (array) get_option(self::CRAWL_WITHDRAWALS, []);
        $started = (!$queue && (!$withdrawals || $daily || !$sameDiscovery || ($state['configured'] ?? null) !== $configured))
            || ($daily && !array_diff($queue, self::CRAWL_RETRY_JOBS));
        if (!$daily && !$queue && $sameDiscovery && !empty($state['reindex_pending']) && ($state['configured'] ?? null) === $configured) {
            $started = false;
        }
        $homeDue = ($backoff[$home]['due'] ?? 0) <= time();
        $retryHome = !$started && $homeDue && !isset($withdrawals[$home])
            && ($queue[$home] ?? null) === self::CRAWL_RETRY_REFRESH;
        $homeHtml = null;
        if ($started || $retryHome) {
            // トップページは新規候補の発見に使う。失敗時にも明示URLと既存索引を確認する。
            $homeDenied = false;
            $homeRetryable = false;
            if ($homeDue) $homeHtml = self::fetchIndexPage($home, $homeDenied, 10, $homeRetryable);
            if (!self::ownsCrawlLock()) return;
        }
        if ($started || ($retryHome && $homeHtml !== null)) {
            // 復旧したトップページのリンクを、残りの巡回より先に反映する。
            $discovered = self::crawlUrls($home, $scope, $homeHtml, $configured, $links);
            if (!self::ownsCrawlLock()) return;
            if ($discovered === null) {
                if (!wp_next_scheduled(self::CRAWL_CONTINUATION)) {
                    wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRAWL_CONTINUATION);
                }
                return;
            }
            foreach ($queue as $url => $job) {
                if (!in_array($job, self::CRAWL_RETRY_JOBS, true)) continue;
                if (($discovered[$url] ?? null) === true) {
                    $discovered[$url] = $job === self::CRAWL_RETRY_PROBE ? self::CRAWL_RETRY_REFRESH : $job;
                } elseif (array_key_exists($url, $discovered) && $discovered[$url] === null) {
                    $discovered[$url] = self::CRAWL_RETRY_PROBE;
                }
            }
            $queue = $discovered;
        }
        // 再試行は1ジョブあたり2件・約2秒を上限にし、通常巡回の枠を残す。
        $retryDeadline = microtime(true) + 2;
        $retried = 0;
        foreach (array_slice($withdrawals, 0, 2, true) as $url => $unused) {
            if ($retried && microtime(true) >= $retryDeadline) break;
            unset($withdrawals[$url], $queue[$url]);
            if (!self::refreshIndex($url, null, true, $scope)) $withdrawals[$url] = false;
            if (!self::ownsCrawlLock()) return;
            ++$retried;
        }

        $processed = 0;
        foreach (array_diff_key($queue, $withdrawals) as $url => $eligible) {
            if (in_array($eligible, self::CRAWL_RETRY_JOBS, true) && ($backoff[$url]['due'] ?? 0) > time()) continue;
            $remaining = (int) floor($deadline - microtime(true));
            if ($processed + $retried >= self::CRAWL_BATCH_SIZE || ($remaining < 1 && $processed > 0)) break;

            unset($queue[$url]);
            $complete = true;
            $probeOnly = $eligible === null || $eligible === self::CRAWL_RETRY_PROBE;
            if ($eligible === false) {
                $complete = self::refreshIndex($url, null, true, $scope);
            } else {
                if (($started || $retryHome) && $url === $home) {
                    $html = $homeHtml;
                    $accessDenied = $homeDenied;
                    $retryable = $homeRetryable;
                } else {
                    $html = self::fetchIndexPage($url, $accessDenied, max(1, min(10, $remaining)), $retryable);
                }
                if (!self::ownsCrawlLock()) return;
                if ($html === null && !$accessDenied
                    && ($eligible === self::CRAWL_RETRY_REFRESH || $retryable)) {
                    $complete = null;
                } elseif (!$probeOnly || $accessDenied) {
                    $complete = self::refreshIndex($url, $html, $accessDenied, $scope);
                }
            }
            if (!self::ownsCrawlLock()) return;
            if ($complete === false) {
                $withdrawals[$url] = false;
                self::persistCrawlWithdrawal($url);
            }
            if ($complete === null) {
                $queue[$url] = $probeOnly ? self::CRAWL_RETRY_PROBE : self::CRAWL_RETRY_REFRESH;
                $attempt = min(7, ($backoff[$url]['attempt'] ?? 0) + 1);
                $delay = $attempt === 1 ? 0 : min(HOUR_IN_SECONDS, (2 ** ($attempt - 1)) * MINUTE_IN_SECONDS);
                $backoff[$url] = ['attempt' => $attempt, 'due' => time() + $delay];
            } else {
                unset($backoff[$url]);
            }
            ++$processed;
        }

        $queue = array_diff_key($queue, $withdrawals);
        return self::finishCrawlBatch([
            'configured' => $configured, 'jobs' => $queue, 'withdrawals' => $withdrawals,
            'discovery_format' => self::CRAWL_DISCOVERY_FORMAT,
            'discovery_revision' => self::crawlDiscoveryRevision($links),
            'scope' => $scope,
            'refresh_backoff' => array_intersect_key($backoff, $queue),
            'reindex_pending' => self::reindexSavedPosts($deadline),
        ]);
    }

    private static function reindexSavedPosts($deadline)
    {
        $epoch = self::savedIndexEpoch();
        if (get_option(self::SAVED_INDEX_COMPLETE) === $epoch) return false;
        if (microtime(true) >= $deadline) return true;

        $types = array_diff(get_post_types(['public' => true]), ['crawlsearch']);
        // phpcs:disable WordPress.DB.SlowDBQuery -- 旧形式の公開投稿を一度だけ小分けで移行する。
        $posts = get_posts([
            'post_type' => array_values(array_unique(array_merge($types, ['pagepart']))),
            'post_status' => 'publish',
            'numberposts' => 20,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::CONTENT_INDEX_META, 'compare' => 'NOT EXISTS'],
                ['key' => self::SAVED_INDEX_EPOCH_META, 'compare' => 'NOT EXISTS'],
                ['key' => self::SAVED_INDEX_EPOCH_META, 'value' => $epoch, 'compare' => '!='],
            ],
        ]);
        // phpcs:enable WordPress.DB.SlowDBQuery
        foreach ($posts as $post) {
            if (microtime(true) >= $deadline) break;
            wp_cache_delete($post->ID, 'posts');
            $post = get_post($post->ID);
            if (!$post || $post->post_status !== 'publish') continue;
            Save::refreshSearchIndex($post);
            self::updatePagePartIndex($post->ID, $post);
        }
        if ($posts) return true;
        update_option(self::SAVED_INDEX_COMPLETE, $epoch, false);
        return false;
    }

    private static function readCrawlState()
    {
        $queue = get_option(self::CRAWL_QUEUE, []);
        return self::latestCrawlState($queue, get_option(self::CRAWL_RECOVERY, []));
    }

    private static function latestCrawlState($queue, $recovery)
    {
        if ($recovery && ($recovery['generation'] ?? 0) >= ($queue['generation'] ?? 0)) {
            return $recovery;
        }
        return $queue;
    }

    private static function updateCrawlWithdrawal($url, $pending)
    {
        $blocked = (array) get_option(self::CRAWL_WITHDRAWALS, []);
        if (isset($blocked[$url]) === $pending) return true;
        if ($pending) {
            $blocked[$url] = false;
        } else {
            unset($blocked[$url]);
        }
        return self::writeCrawlOption(self::CRAWL_WITHDRAWALS, $blocked);
    }

    private static function persistCrawlWithdrawal($url)
    {
        $state = self::readCrawlState();
        // 後続の巡回や再索引が中断しても、公開検索から除外できるよう先に保存する。
        if (isset($state['withdrawals'][$url])) return;
        $state['withdrawals'][$url] = false;
        self::saveCrawlState($state);
        if (!wp_next_scheduled(self::CRAWL_CONTINUATION)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRAWL_CONTINUATION);
        }
        if (!isset(self::readCrawlState()['withdrawals'][$url]) || !wp_next_scheduled(self::CRAWL_CONTINUATION)) {
            update_option(self::CRAWL_ERROR, 1, false);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- DB障害で通知も保存できない場合に記録する。
            error_log('Dashi: could not save or schedule an urgent search index withdrawal.');
        }
    }

    private static function saveCrawlState(array $state)
    {
        $state['generation'] = (self::readCrawlState()['generation'] ?? 0) + 1;
        // 古い退避が残っても、完了を示す新しい空状態を先に保存する。
        self::writeCrawlOption(self::CRAWL_QUEUE, $state);
        $saved = get_option(self::CRAWL_QUEUE, false) === $state;
        if (!$saved) {
            self::writeCrawlOption(self::CRAWL_RECOVERY, $state);
            return false;
        }

        if (!self::ownsCrawlLock()) return false;
        if (self::$crawlLock === null) {
            if (delete_option(self::CRAWL_RECOVERY)) return true;
        } else {
            global $wpdb;
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                self::CRAWL_RECOVERY, self::$crawlLock
            ));
            if ($deleted) {
                self::clearCrawlOptionCache(self::CRAWL_RECOVERY);
                return true;
            }
        }

        // 削除に失敗した場合と、退避キューが最初からない場合を区別する。
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
            self::CRAWL_RECOVERY
        ));
        return $remaining !== null && (int) $remaining === 0;
    }

    private static function finishCrawlBatch(array $state)
    {
        $pending = $state['jobs'] || $state['withdrawals'] || !empty($state['reindex_pending']);
        if (!self::ownsCrawlLock()) return;
        $saved = self::saveCrawlState($state);
        if (!self::ownsCrawlLock()) return;
        $scheduled = true;
        if ($pending || !$saved) {
            $next = time() + MINUTE_IN_SECONDS;
            if ($saved && $state['jobs'] && !$state['withdrawals'] && empty($state['reindex_pending'])
                && !array_diff($state['jobs'], self::CRAWL_RETRY_JOBS)) {
                $due = [];
                foreach ($state['jobs'] as $url => $job) {
                    $due[] = $state['refresh_backoff'][$url]['due'] ?? 0;
                }
                $next = max($next, min($due));
            }
            if (!wp_next_scheduled(self::CRAWL_CONTINUATION)) {
                wp_schedule_single_event($next, self::CRAWL_CONTINUATION);
            }
            $scheduled = (bool) wp_next_scheduled(self::CRAWL_CONTINUATION);
        } else {
            wp_clear_scheduled_hook(self::CRAWL_CONTINUATION);
        }

        if ($saved && $scheduled) {
            delete_option(self::CRAWL_ERROR);
        } else {
            update_option(self::CRAWL_ERROR, 1, false);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- DB障害で管理画面用の状態も保存できない場合に記録する。
            error_log('Dashi: could not save the search crawl queue or schedule its continuation.');
        }
        return $saved && $scheduled;
    }

    public static function handleManualCrawl()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this action.', 'dashi'), '', ['response' => 403]);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- HTTPメソッドを固定値との完全一致で判定する。
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Submit the search crawl form to start crawling.', 'dashi'), '', ['response' => 405]);
        }
        check_admin_referer('dashi_start_search_crawl');
        $result = self::runCrawl(true);
        set_transient('dashi_manual_crawl_' . get_current_user_id(), $result, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('options-general.php?page=dashi_options#dashi-manual-crawl'), 303);
        exit;
    }

    public static function manualCrawlForm()
    {
        if (!current_user_can('manage_options')) return;
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="dashi_start_search_crawl">';
        wp_nonce_field('dashi_start_search_crawl');
        echo '<p><button type="submit" class="button">'
            . esc_html__('Start search crawl now', 'dashi') . '</button></p></form>';
    }

    public static function manualCrawlResult()
    {
        if (!current_user_can('manage_options')) return;
        $key = 'dashi_manual_crawl_' . get_current_user_id();
        $result = get_transient($key);
        if (!is_string($result)) return;
        delete_transient($key);
        $messages = [
            'processed' => __('The first search crawl batch finished. Any remaining work and future crawls rely on WordPress cron.', 'dashi'),
            'busy' => __('A search crawl is already running. Wait and check the last crawl start time.', 'dashi'),
            'failed' => __('The search crawl could not complete this batch. Check the server error log and WordPress cron.', 'dashi'),
        ];
        if (isset($messages[$result])) {
            echo '<p role="status">' . esc_html($messages[$result]) . '</p>';
        }
    }

    private static function nextCrawlTime()
    {
        $state = self::readCrawlState();
        $pending = !empty($state['jobs']) || !empty($state['withdrawals']) || !empty($state['reindex_pending'])
            || (bool) get_option(self::CRAWL_WITHDRAWALS, []);
        return wp_next_scheduled($pending ? self::CRAWL_CONTINUATION : 'dashi_cron_hook');
    }

    private static function crawlIsOverdue($expected, $lastRun): bool
    {
        $cutoff = time() - self::CRAWL_NOTICE_GRACE;
        return $expected && $expected <= $cutoff && $lastRun <= $cutoff;
    }

    public static function crawlOverdueNotice()
    {
        if (!current_user_can('manage_options')) return;
        $expected = self::nextCrawlTime();
        if (!self::crawlIsOverdue($expected, (int) get_option(self::CRAWL_LAST_RUN))) return;

        if (self::canSuggestAlternateCron()) {
            $target = Option::ALTERNATE_CRON;
            $message = __('The site search index update is delayed. WP-Cron may not be working properly.', 'dashi');
            $link = __('Check the “Use WordPress alternate cron spawning” setting', 'dashi');
        } else {
            $target = 'dashi-manual-crawl';
            $message = __('The site search index update is delayed. You can start the search crawl manually.', 'dashi');
            $link = __('Open search crawl settings', 'dashi');
        }

        $url = admin_url('options-general.php?page=dashi_options#' . $target);
        echo '<div class="notice notice-warning"><p>' . esc_html($message)
            . ' <a href="' . esc_url($url) . '">' . esc_html($link) . '</a></p></div>';
    }

    private static function canSuggestAlternateCron()
    {
        // 定数がある場合、出汁の設定から代替起動へ切り替える必要がないか、切り替えられない。
        return !defined('ALTERNATE_WP_CRON') && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)
            && !get_option(Option::ALTERNATE_CRON);
    }

    public static function crawlNotice()
    {
        if (!current_user_can('manage_options')) return;
        $expected = self::nextCrawlTime();
        if ($expected) {
            printf(
                '<p>%s</p>',
                esc_html(sprintf(
                    /* translators: %s: Scheduled time in the site's time zone. */
                    __('Next search crawl scheduled for: %s', 'dashi'),
                    get_date_from_gmt(gmdate('Y-m-d H:i:s', $expected))
                ))
            );
        }
        $lastRun = (int) get_option(self::CRAWL_LAST_RUN);
        if (!get_option(self::CRAWL_ERROR) && !get_option(self::SAVED_INDEX_EPOCH_BLOCK)
            && !get_option(self::CRAWL_HOME_BLOCK)
            && $expected && !self::crawlIsOverdue($expected, $lastRun)) return;
        echo '<div class="notice notice-warning inline"><p>' . esc_html__(
            'Dashi search crawling is overdue or could not save or schedule its continuation. Check WordPress cron and the server error log.',
            'dashi'
        ) . '</p><p>' . esc_html($lastRun ? sprintf(
            /* translators: %s: Last crawl start time in the site's time zone. */
            __('Last crawl started: %s', 'dashi'),
            get_date_from_gmt(gmdate('Y-m-d H:i:s', $lastRun))
        ) : __('Dashi has no record of a crawl starting.', 'dashi')) . '</p>';
        echo '<p>' . esc_html__(
            'If delays recur after a manual start, ask your site administrator to check that WP-Cron runs regularly.',
            'dashi'
        ) . '</p>';
        echo '</div>';
    }

    private static function crawlDiscoveryRevision(?array $links)
    {
        return $links === null ? null : hash('sha256', serialize($links));
    }

    private static function crawlUrls($home, $scope, $html, $configured, ?array &$discovered)
    {
        $blacklist = self::getBlackList();
        if ($blacklist === null) return null;
        $specified = self::eliminateAssets(explode("\n", $configured), $blacklist);
        global $wpdb;
        // 対象外を判定する前に、空の索引とDBの読み取り失敗を区別する。
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $sources = $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_status, m.meta_value AS source_url
             FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
             AND m.meta_key = %s
             ORDER BY p.post_date DESC, p.ID DESC, m.meta_id ASC",
            'crawlsearch',
            'dashi_redirect_to'
        ));
        if ($wpdb->last_error !== '' || !is_array($sources)) return null;
        $existing = [];
        foreach ($sources as $source) {
            $url = $source->source_url;
            if (is_string($url) && $url !== '') {
                $existing[$url] = !empty($existing[$url]) || $source->post_status === 'publish';
            }
        }

        $links = $html !== null ? self::discoverPageLinks($home, $html) : null;
        if ($links !== null) $discovered = self::eliminateAssets($links, $blacklist);

        $eligible = array_fill_keys(array_merge([$home], $specified, $discovered ?? []), true);
        // リンク未確認の初回障害時は、既存索引の閲覧拒否だけを確認する。
        $other = array_fill_keys(
            array_keys(array_diff_key(array_filter($existing), $eligible)),
            $discovered === null ? null : false
        );
        if ($links !== null) {
            // リンク一覧だけが先に保存されても、対象外の索引はすぐに公開検索から外れる。
            $previous = get_option(self::CRAWL_DISCOVERY, []);
            $pending = ($previous['scope'] ?? null) === $scope ? (array) ($previous['withdrawals'] ?? []) : [];
            $snapshot = ['scope' => $scope, 'scoped_links' => $discovered, 'withdrawals' => $other + $pending];
            self::writeCrawlOption(self::CRAWL_DISCOVERY, $snapshot);
            if (get_option(self::CRAWL_DISCOVERY) !== $snapshot) return null;
        }
        return [$home => true] + $other
            + array_intersect_key($eligible, $existing)
            + $eligible;
    }

    private static function discoverPageLinks($home, $html)
    {
        if (!class_exists('\DOMDocument') || !preg_match('/<body\b/i', $html)) return null;
        $document = new \DOMDocument('1.0', 'UTF-8');
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $links = $xpath->query(
            '//body//a[@href and not(ancestor-or-self::*[@hidden or self::template or self::noscript])]'
        );
        if ($links === false) return null;

        $base = $home;
        $baseElement = $xpath->query('(//head/base[@href])[1]');
        if ($baseElement && $baseElement->length) {
            $href = trim($baseElement->item(0)->getAttribute('href'));
            $candidate = \WP_Http::make_absolute_url($href, $home);
            $parts = wp_parse_url($candidate);
            if (!empty($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['data', 'javascript'], true)) {
                $base = $candidate;
            }
        }
        $urls = [];
        foreach ($links as $link) {
            $href = explode('#', trim($link->getAttribute('href')), 2)[0];
            if ($href !== '') $urls[] = \WP_Http::make_absolute_url($href, $base);
        }
        return $urls;
    }

    private static function refreshIndex($url, $html, $accessDenied, $scope)
    {
        if (!self::ownsCrawlLock()) return null;
        if ($html === null && !$accessDenied) return true;
        $indexable = !$accessDenied && $html !== null && preg_match('/<body ([^>]+?)>/', $html, $body)
            && strpos($body[1], 'single') === false;
        if (!$indexable) {
            // Cron が停止しても、取り下げ対象を先に公開検索から外す。
            if (!self::updateCrawlWithdrawal($url, true)) self::persistCrawlWithdrawal($url);
            if (!wp_next_scheduled(self::CRAWL_CONTINUATION)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::CRAWL_CONTINUATION);
            }
        }
        global $wpdb;
        // 空の結果と読み取り失敗を区別し、URLは大文字・小文字まで一致させる。
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DBエラーを確認するため直接照合する。
        $matching = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_status FROM {$wpdb->posts} AS p
             INNER JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID
             WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
             AND m.meta_key = %s AND BINARY m.meta_value = %s
             ORDER BY p.ID DESC",
            'crawlsearch',
            'dashi_redirect_to',
            $url
        ));
        if ($wpdb->last_error !== '' || !is_array($matching)) return $indexable ? null : false;
        if ($indexable) {
            $id = self::updateDatabase($html, $matching[0]->ID ?? 0, $url, $scope);
            if (!$id) return null;
        } else {
            $id = 0;
        }
        $complete = true;
        foreach ($matching as $post) {
            if (!self::ownsCrawlLock()) return null;
            if ((int) $post->ID !== (int) $id) {
                // 状態の保存に失敗しても、公開クエリからは先に除外する。
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta}
                     WHERE post_id = %d AND meta_key = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                    $post->ID, self::CRAWL_VERIFIED_META, self::$crawlLock
                ));
                if ($deleted) wp_cache_delete((int) $post->ID, 'post_meta');
                if ($post->post_status === 'publish') {
                    self::writeCrawlPost((int) $post->ID, 'draft');
                }
                if (get_post_status($post->ID) === 'publish'
                    || metadata_exists('post', $post->ID, self::CRAWL_VERIFIED_META)) {
                    $complete = false;
                }
            }
        }
        if (!$complete) return false;
        if (!self::clearDiscoveryWithdrawal($url, $scope)) return $indexable ? null : false;
        return $indexable || self::updateCrawlWithdrawal($url, false);
    }

    private static function clearDiscoveryWithdrawal($url, $scope)
    {
        $snapshot = get_option(self::CRAWL_DISCOVERY, []);
        if (($snapshot['scope'] ?? null) !== $scope || !isset($snapshot['withdrawals'][$url])) return true;
        unset($snapshot['withdrawals'][$url]);
        self::writeCrawlOption(self::CRAWL_DISCOVERY, $snapshot);
        return get_option(self::CRAWL_DISCOVERY) === $snapshot;
    }

    private static function updateDatabase($html, $existingId, $url, $scope)
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

        $verifiedAt = (string) time();
        $fingerprint = hash('sha256', hash('sha256', $url)
            . hash('sha256', $item['post_title']) . hash('sha256', $item['post_content'])
            . hash('sha256', $verifiedAt));
        $format = self::CRAWL_FORMAT . ':' . $scope . ':' . $fingerprint;
        if (!self::ownsCrawlLock()) return 0;
        if ($existingId) {
            $id = (int) $existingId;
            if (!self::writeCrawlPost($id, 'publish', $item)) return 0;
        } else {
            // 取得元 URL の保存を確認してから投稿を公開する。検索への公開は署名の保存で確定する。
            $item['post_status'] = 'draft';
            $id = wp_insert_post(wp_slash($item), true);
            if (is_wp_error($id) || !$id || !self::ownsCrawlLock()) return 0;
            update_post_meta($id, 'dashi_redirect_to', wp_slash($url));
            if (get_post_meta($id, 'dashi_redirect_to', true) !== $url || !self::ownsCrawlLock()) return 0;
            if (!self::writeCrawlPost($id, 'publish')) return 0;
        }
        if (!self::ownsCrawlLock()) return 0;
        if (!self::writeCrawlVerificationMeta($id, self::CRAWL_VERIFIED_META, $verifiedAt)) return 0;
        return self::writeCrawlVerificationMeta($id, self::CRAWL_FORMAT_META, $format) ? $id : 0;
    }

    /** 内部索引の本文と状態を、ロックを保持する接続だけで更新する。 */
    private static function writeCrawlPost($id, $status, array $item = [])
    {
        global $wpdb;
        $modified = current_time('mysql');
        $modifiedGmt = current_time('mysql', true);
        if ($item) {
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_title = %s, post_content = %s, post_status = %s,
                 post_modified = %s, post_modified_gmt = %s
                 WHERE ID = %d AND post_type = 'crawlsearch' AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $item['post_title'], $item['post_content'], $status, $modified, $modifiedGmt, $id, self::$crawlLock
            ));
        } else {
            $written = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_status = %s, post_modified = %s, post_modified_gmt = %s
                 WHERE ID = %d AND post_type = 'crawlsearch' AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $status, $modified, $modifiedGmt, $id, self::$crawlLock
            ));
        }
        if ($written === false) return false;
        clean_post_cache($id);
        $post = get_post($id);
        return self::ownsCrawlLock() && $post && $post->post_type === 'crawlsearch' && $post->post_status === $status
            && (!$item || ($post->post_title === $item['post_title'] && $post->post_content === $item['post_content']));
    }

    /** この署名が一致する索引だけが検索に出る。ロック確認を署名のSQLと一体にする。 */
    private static function writeCrawlVerificationMeta($id, $key, $value)
    {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s
             AND IS_USED_LOCK(%s) = CONNECTION_ID()",
            $value, $id, $key, self::$crawlLock
        ));
        if ($updated === false) return false;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT %d, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s)",
            $id, $key, $value, self::$crawlLock, $id, $key
        ));
        wp_cache_delete($id, 'post_meta');
        return $inserted !== false && self::ownsCrawlLock()
            && get_post_meta($id, $key, true) === $value;
    }

    /**
     * getBlackList
     *
     * @return array|null
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
        if ($wpdb->last_error !== '' || !is_array($slugs_posttypes)) return null;
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
        $urls = [self::crawlHome()];
        foreach ($target_urls as $v)
        {
            $v = explode('#', trim($v), 2)[0];
            if (!self::isInCrawlScope($v)) continue;
            if (strpos($v, 'wp-content') !== false) continue;
            if (strpos($v, wp_login_url()) !== false) continue;
            if (strpos($v, '/wp-json') !== false) continue;
            if (strpos($v, '/feed') !== false) continue;
            if (strpos($v, '?p=') !== false) continue; // indexable page

            // indexable page
            foreach ($black_list as $black)
            {
                if (strpos($v, $black) !== false) continue 2;
            }

            $urls[] = $v;
        }

        $urls = array_unique($urls);
        return $urls;
    }
}
