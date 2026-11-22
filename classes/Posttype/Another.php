<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Another
{
	private static function originalNonceAction($original_id)
	{
		return 'dashi_original_post_' . (int) $original_id;
	}

	private static function getRequestedOriginalId()
	{
		$original_id = filter_input(INPUT_GET, 'dashi_original_id', FILTER_VALIDATE_INT);
		if (!$original_id) return 0;

		$nonce = filter_input(INPUT_GET, '_dashi_original_nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		if (!is_string($nonce) || $nonce === '') return 0;
		if (!wp_verify_nonce($nonce, static::originalNonceAction($original_id))) return 0;

		global $post;
		$target_type = $post instanceof \WP_Post ? $post->post_type : Input::get('post_type', 'post');
		$original = get_post($original_id);
		if (!self::canEditReplacementSource($original, $target_type)) return 0;
		$type = get_post_type_object($target_type);
		return $type && current_user_can($type->cap->create_posts) ? (int) $original_id : 0;
	}

	private static function canEditReplacementSource($original, $target_type)
	{
		return $original instanceof \WP_Post && $original->post_type === $target_type
			&& current_user_can('edit_post', $original->ID);
	}

    static $anothers = array();
    private static $publishRequests = [];
    private static $deliveringNotifications = [];
    private const APPROVAL_VERSION = 1;
    private const NOTIFICATION_PREFIX = '_dashi_replacement_due_';

    /**
     * forge
     *
     * @return  void
     */
    public static function forge ()
    {
        add_action('dashi_replacement_maintenance', [self::class, 'forcePublish']);
        if (!wp_next_scheduled('dashi_replacement_maintenance')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'dashi_replacement_maintenance');
        }

        remove_action('publish_future_post', 'check_and_publish_future_post');
        add_action('publish_future_post', [self::class, 'holdUnapprovedReplacement']);
        add_filter('is_protected_meta', [self::class, 'protectReplacementTarget'], 10, 3);
        add_filter('map_meta_cap', [self::class, 'preserveAppliedDraftCapabilities'], 10, 4);
        add_filter('wp_insert_post_data', [self::class, 'holdReplacementBeforeSave'], PHP_INT_MAX, 2);
        add_filter('query', [self::class, 'guardDirectPublish']);
        add_action('add_term_relationship', [self::class, 'guardDirectPublishTerms'], PHP_INT_MIN);

        if ( ! is_admin()) return;

        $posttypes = array('post', 'page');
        foreach (P::instances() as $class)
        {
            $posttypes[] = P::class2posttype($class);
        }

        // すべてのポストタイプ一覧の各行に「差し替え」を追加する
        foreach ($posttypes as $posttype)
        {
            add_filter(
                $posttype.'_row_actions',
                array('\\Dashi\\Core\\Posttype\\Another', 'addEditAnotherVersion'),
                10,
                2
            );
        }

        // 一つの記事に対して複数の差し替えを持たないようにリダイレクトする
        add_filter(
            'init',
            array('\\Dashi\\Core\\Posttype\\Another', 'inhibitPluralAnotherVersion')
        );

        // 差し替え編集画面を作る
        add_filter(
            'admin_head-post-new.php', // hook_suffix
            array('\\Dashi\\Core\\Posttype\\Another', 'editAnotherVersion')
        );

        // オリジナルidを保存するためにhiddenを出力する
        add_action(
            'edit_form_after_title',
            array('\\Dashi\\Core\\Posttype\\Another', 'editFormAfterTitle')
        );

        // 保存時にいろいろする
        add_action(
            'save_post',
            array('\\Dashi\\Core\\Posttype\\Another', 'savePost')
        );

        // 差し替えがらみのpre_get_posts
        add_action(
            'pre_get_posts',
            array('\\Dashi\\Core\\Posttype\\Another', 'preGetPosts')
        );

        // 編集画面をいろいろするJavaScript
        add_action(
            'admin_head-post.php',
            array('\\Dashi\\Core\\Posttype\\Another', 'adminHeadPostPhp')
        );

        // 一覧画面の各行が差し替えを持っているかどうか確認する
        add_filter(
            'post_date_column_time',
            array('\\Dashi\\Core\\Posttype\\Another', 'postDateColumnTime'),
            10,
            4
        );

    }

    /**
     * postDateColumnTime
     *
     * @return  string
     */
    public static function postDateColumnTime ($t_time, $post)
    {
        $str = '';
        $another = static::getAnother($post->ID);
        if ($another && current_user_can('edit_post', $another->ID))
        {
            $another_utime = strtotime($another->post_date);
            $date_format = get_option('date_format').' H:i';

            // 失敗？
            if ((int) date_i18n('U') > $another_utime)
            {
                $str = __('Replace Another version was failed', 'dashi');
                $str = '<strong style="color: #f00;display:block;">'.$str.'</strong>';
            }
	            else
	            {
                    if ($post->post_status == 'pending') {
                        /* translators: %s: date and time */
                        $str = __('Another version is pending (date: %s)', 'dashi');
                    } else {
                        /* translators: %s: date and time */
                        $str = __('Another version is exists (date: %s)', 'dashi');
                    }
                $str = '<strong style="display:block;">'.sprintf(
                    /* translators: %s: date and time */
                    $str,
                    wp_date($date_format, $another_utime)
                ).'</strong>';
            }
        }
        return $t_time.$str;
    }

    /**
     * preGetPosts
     *
     * @return  void
     */
    public static function preGetPosts ()
    {
        // いつでも有効にするので管理画面だけとしない
        global $wp_query;

        if (is_null($wp_query)) return;

        // 予約投稿などではフィルタを通さない
        if (
            isset($wp_query->query['post_status']) &&
            in_array($wp_query->query['post_status'], array('future', 'draft'))
        ) return;

        $meta_query = $wp_query->get('meta_query');
        if ( ! is_array($meta_query)) $meta_query = array();
        $meta_query[] = array(
            'key'     => 'dashi_original_id',
            'compare' => 'NOT EXISTS',
        );

        // 管理画面で一覧を取得するときのみ
        $wp_query->set('meta_query', $meta_query);
    }

    /**
     * adminHeadPostPhp
     *
     * @return  void
     */
	    public static function adminHeadPostPhp ()
	    {
	        global $post;
	        if ( ! isset($post)) return;

        // 通常の編集画面でのリンク文字列用
        $str = static::getAnother($post->ID) ?
                 esc_html__('Edit another version', 'dashi') :
                 esc_html__('Add another version', 'dashi');

	        $script = '';
	        $script.= '<script type="text/javascript">';
	        $script.= 'jQuery (function($){';

	        // 差し替えの編集画面の場合
	        if (isset($post->dashi_original_id))
	        {
            $original_posttype = get_post_type_object($post->post_type);
            $str = static::getAnother($post->dashi_original_id) ?
                 sprintf(
                     /* translators: %s: post type label */
                     __('Edit another version of %s', 'dashi'),
                     $original_posttype->label
                 ) :
	                 sprintf(
	                     /* translators: %s: post type label */
	                     __('Add another version of %s', 'dashi'),
	                     $original_posttype->label
	                 );
	            $script.= '$("title").text('.wp_json_encode($str).');';
	            $script.= '$("h1.wp-heading-inline").text('.wp_json_encode($str).');';
	            $script.= '$("a.page-title-action").hide();';
	        }
	        // 通常の編集画面
	        else
	        {
	            // 差し替えのリンク
	            $link_html = sprintf(
	                '<a href="%s" class="page-title-action">%s</a>',
	                esc_url(static::getAnotherLink($post->ID)),
	                esc_html($str)
	            );
	            $script.= '$(".wp-heading-inline").after('.wp_json_encode($link_html).');';

            // 差し替えが存在する場合はステータスを表示する
            if (static::getAnother($post->ID))
            {
                $str = static::postDateColumnTime('', $post, '', '');
                $class = strpos($str, 'f00') !== false ? 'error dashi_error' : 'updated';
                $str = '<div class="message '.$class.'"><p>'.$str.'</p></div>';
                $str = addslashes($str);
                $script.= '$("#post-body").prepend("'.$str.'");';
            }
        }

        $script.= '});';
        $script.= '</script>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 管理画面用の既存 inline script 出力
        echo $script;
    }

    /**
     * getAnother
     *
     * @return  mixed
     */
    public static function getAnother ($original_id)
    {
        if (isset(static::$anothers[$original_id])) return static::$anothers[$original_id];

        $original = get_post($original_id);
        if ( ! $original) return false;

	        $another = new \WP_Query(array(
	                'post_type' => $original->post_type,
	                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	                'meta_key' => 'dashi_original_id',
	                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	                'meta_value' => $original_id,
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 適用済みの保存原稿を次の予約候補から除く。
                    'meta_query' => [['key' => '_dashi_replacement_applied', 'compare' => 'NOT EXISTS']],
	            ));

        static::$anothers[$original_id] = isset($another->posts[0])
            ? $another->posts[0]
            : false;

        return static::$anothers[$original_id];
    }

    /**
     * isAnother
     *
     * @return  mixed
     */
    public static function isAnother ($post_id)
    {
        $original = get_post_meta($post_id, 'dashi_original_id', true);
        return $original ? true : false;
    }

    /**
     * addEditAnotherVersion
     *
     * @return  void
     */
    public static function addEditAnotherVersion ($actions, $post)
    {
        if ($post->post_status == 'publish' && current_user_can('edit_published_posts'))
        {
            $tmps = array();
	            $str = static::getAnother($post->ID) ?
	                     esc_html__('Edit another version', 'dashi') :
	                     esc_html__('Add another version', 'dashi');

            // order
            if (isset($actions['edit']))
            {
                $tmps['edit'] = $actions['edit'];
                unset($actions['edit']);
            }
            if (isset($actions['inline hide-if-no-js']))
            {
                $tmps['inline hide-if-no-js'] = $actions['inline hide-if-no-js'];
                unset($actions['inline hide-if-no-js']);
            }

	            $tmps['dashi_edit_another'] = '<a href="' . esc_url(static::getAnotherLink($post->ID)) . '" title="'.esc_attr__('Keep this post until another post will be activated', 'dashi').'">'.$str.'</a>';

            $actions = $tmps + $actions;
        }

        return $actions;
    }

    /**
     * inhibitPluralAnotherVersion
     *
     * @return  void
     */
    public static function inhibitPluralAnotherVersion ()
    {
        // オリジナルを取得
	        $original_id = static::getRequestedOriginalId();
	        if (!$original_id) return;

        // 差し替えの存在確認
        $another = static::getAnother($original_id);

        // 差し替えが存在する場合はリダイレクト
        if ($another)
        {
	            wp_safe_redirect(admin_url('post.php?post='.$another->ID.'&action=edit'));
	            exit;
	        }
    }

    /**
     * editAnotherVersion
     *
     * @return  void
     */
    public static function editAnotherVersion ()
    {
        // タクソノミーはjavascriptなので、editFormAfterTitleで

        // オリジナルを取得
	        $original_id = static::getRequestedOriginalId();
	        if (!$original_id) return;

        $original = get_post($original_id);

        global $post;
        $post->post_title = $original->post_title;
        $post->post_content = $original->post_content;
        $post->post_excerpt = $original->post_excerpt;

        // カスタムフィールド
        $class = P::posttype2class($post->post_type);

        $checkscript = 'jQuery(function($) {';
        foreach ($class::getFlatCustomFields() as $key => $field) {
            $type = $field['type'] ?? null;
            if (!$type || ($field['store_data'] ?? true) == false) continue;

            // 名前と値は文字列リテラルとして渡し、CSSセレクターに連結しない。
            if (in_array($type, ['checkbox', 'radio', 'select'], true)) {
                $values = array_values(array_map('strval', array_filter(
                    get_post_meta($original->ID, $key), 'is_scalar'
                )));
                if ($values) {
                    $multiple = $type === 'select' && isset($field['attrs']['multiple']);
                    $name = wp_json_encode($key . ($type === 'checkbox' || $multiple ? '[]' : ''), JSON_HEX_TAG);
                    $checkscript .= 'var fields = $(document.getElementsByName(' . $name . '));';
                    if ($type === 'select') {
                        $selection = $multiple ? $values : $values[0];
                        $checkscript .= 'fields.val(' . wp_json_encode($selection, JSON_HEX_TAG) . ');';
                    } else {
                        $choices = wp_json_encode($type === 'radio' ? [$values[0]] : $values, JSON_HEX_TAG);
                        $checkscript .= 'fields.val(' . $choices . ');';
                    }
                }
            }
            $post->$key = $original->$key;
        }
        $checkscript .= '});';
        wp_add_inline_script('jquery-core', $checkscript);

        // h1をわかりやすく
        $str = sprintf(
            /* translators: %s: post type label */
            __('Add another version of %s', 'dashi'),
            get_post_type_object($post->post_type)->label
        );

	        $script = '';
	        $script.= '<script type="text/javascript">';
	        $script.= 'jQuery (function($){
$("title").text('.wp_json_encode($str.' ‹ '.get_bloginfo('site-name').' — WordPress').');
$("h1.wp-heading-inline").text('.wp_json_encode($str).');
});';
	        $script.= '</script>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 管理画面用の既存 inline script 出力
        echo $script;
    }

	/**
	 * editFormAfterTitle
	 * 判定に使うためにdashi_original_idを持ち回すのと、パーマリンクを消して一覧（か表）に戻る
	 * @return void
	 */
	static public function editFormAfterTitle()
	{
		global $post;
			$requested_original_id = static::getRequestedOriginalId();
			$original_id = $requested_original_id ?: (isset($post->dashi_original_id) ? (int) $post->dashi_original_id : 0);
			if (!$original_id || !isset($post->post_type)) return;
		$original = get_post($original_id);
		if (!self::canEditReplacementSource($original, $post->post_type)) return;

		echo '<input type="hidden" name="dashi_original_id" value="'.intval($original_id).'" />';
		wp_nonce_field(static::originalNonceAction($original_id), '_dashi_original_nonce');
		echo '<style type="text/css" scoped="scoped">#edit-slug-box {display: none}</style>';

		// back link
		self::echoBackLink($original);

		// taxonomy
		// 作成時のみ
		self::echoTaxonomyScript($original);
	}

	/**
	 * echoBackLink
	 *
	 * @param  object $original
	 * @return  void
	 */
	private static function echoBackLink ($original)
	{
		$cu = wp_get_current_user();
		$link = 'edit.php?post_type='.$original->post_type;

		if (
			(isset($cu->caps['administrator']) && $cu->caps['administrator'] === true) ||
			(isset($cu->caps['editor']) && $cu->caps['editor'] === true)
		)
		{
			$link = 'post.php?post='.$original->ID.'&action=edit';
		}
		elseif ($original->post_status == 'publish')
		{
			$link = get_permalink($original->ID);
		}
		elseif (in_array($original->post_status, array('pending', 'draft')))
		{
			$link = get_preview_post_link($original->ID);
		}

		if (is_string($link))
		{
				echo wp_kses_post(
                    sprintf(
                        /* translators: 1: original post URL, 2: original post title */
                        __('This post is another version of <a href="%1$s">%2$s</a>. If you publish, replace post immediately.', 'dashi'),
                        esc_url($link),
                        esc_html($original->post_title)
                    )
                );
			}
		}

	/**
	 * echoTaxonomyScript
	 *
	 * @param  object $original
	 * @return  void
	 */
		private static function echoTaxonomyScript ($original)
		{
			$class = P::post2class($original);
				if ($class && static::getRequestedOriginalId())
				{
				$script = '';
				foreach ($class::get('taxonomies') as $taxonomy_name => $taxonomy)
				{
				// update taxonomy
				$taxes = wp_get_object_terms($original->ID, $taxonomy_name);
				$ul_id = $taxonomy_name.'checklist';

				$checks = array();
				if ( ! $taxes) continue;
				foreach ($taxes as $tax)
				{
					$checks[] = $tax->term_id;
				}
				$checkstr = '['.join(',',$checks).']';

				$script.= 'jQuery (function($){
	var checkstr = '.$checkstr.';

$("#'.$ul_id.'").find(":input").each(function(){
	if (checkstr.indexOf(parseInt($(this).val())) == -1)
	{
		$(this).prop("checked", false);
	}
	else
	{
		$(this).prop("checked", true);
	}
});

	});';
				}
				$script = '<script type="text/javascript">'.$script.'</script>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 管理画面用の既存 inline script 出力
				echo $script;
			}
		}

	/**
	 * savePost
	 *
	 * @return  void
	 */
    public static function guardDirectPublish($query)
    {
        global $wpdb;
        $table = preg_quote($wpdb->posts, '~');
        // wp_publish_post() writes only the status and does not use wp_insert_post_data.
        if (!is_string($query) || !preg_match("~^UPDATE `{$table}` SET `post_status` = 'publish' WHERE `ID` = ([0-9]+)$~iD", $query, $match)) {
            return $query;
        }
        if (self::canPublishDirectly((int) $match[1]) === true) return $query;
        self::rejectDirectPublish();
        return 'SELECT 0 WHERE 1=0';
    }

    /**
     * Direct publication callers can check this before invoking wp_publish_post().
     * null means the database could not confirm whether the post is linked.
     */
    public static function canPublishDirectly($id)
    {
        $id = (int) $id;
        if ($id <= 0) return false;
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 保護された関連付けをキャッシュを介さず確認する。
        $linked = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d
             AND meta_key IN ('dashi_original_id', '_dashi_replacement_approval', '_dashi_replacement_applied')
             AND meta_value NOT IN ('', '0') LIMIT 1", $id
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->last_error === '' ? !$linked : null;
    }

    public static function guardDirectPublishTerms($postId)
    {
        // WordPress may assign default terms before its direct status write.
        // Ordinary taxonomy edits must still work for a replacement draft.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- コアの既定分類追加だけを識別し、引数や呼び出し履歴を記録しない。
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14) as $frame) {
            if (($frame['function'] ?? '') !== 'wp_publish_post' || isset($frame['class'])) continue;
            if (self::canPublishDirectly($postId) !== true) self::rejectDirectPublish();
            return;
        }
    }

    private static function rejectDirectPublish()
    {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            wp_die(esc_html__('The publication could not be verified. Please try again.', 'dashi'), '', ['response' => 503]);
        } else {
            wp_die(esc_html__('Replacement drafts cannot be published directly.', 'dashi'), '', ['response' => 409]);
        }
    }

    public static function holdReplacementBeforeSave($data, $postarr)
    {
        $status = get_post_status_object($data['post_status']);
        if (!$status || !$status->public || $data['post_type'] === 'revision') return $data;
        $id = (int) ($postarr['ID'] ?? 0);
        $linked = !empty($postarr['meta_input']['dashi_original_id'])
            || ($id && (get_post_meta($id, 'dashi_original_id', true)
                || get_post_meta($id, '_dashi_replacement_approval', true)
                || get_post_meta($id, '_dashi_replacement_applied', true)));
        $target = self::requestedReplacementTarget($id, $data['post_type']);
        if (!$linked && !$target) return $data;

        if ($target && self::canReplace(get_current_user_id(), $target, $id)) {
            self::$publishRequests[$id] = $target;
        }
        $data['post_status'] = 'pending';
        return $data;
    }

    private static function requestedReplacementTarget($id, $postType)
    {
        global $pagenow;
        if ($pagenow !== 'post.php' || !$id || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return 0;
        $request = Input::post();
        $source = filter_var($request['post_ID'] ?? '', FILTER_VALIDATE_INT);
        $saveNonce = $request['_wpnonce'] ?? '';
        if ($source !== $id || !is_string($saveNonce) || !wp_verify_nonce($saveNonce, 'update-post_' . $id)
            || !current_user_can('edit_post', $id)) return 0;

        $target = filter_var($request['dashi_original_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nonce = $request['_dashi_original_nonce'] ?? '';
        if (!$target || $target === $id || !is_string($nonce) || !wp_verify_nonce($nonce, self::originalNonceAction($target))) return 0;
        return self::canEditReplacementSource(get_post($target), $postType) ? $target : 0;
    }

	public static function savePost ($post_id)
	{
        $another = get_post($post_id);
        if (!$another || $another->post_type === 'revision') return $post_id;
        $dashi_original_id = self::requestedReplacementTarget((int) $post_id, $another->post_type);
        if (!$dashi_original_id) return $post_id;
        $original = get_post($dashi_original_id);
        if (!$original) return $post_id;

        if ((self::$publishRequests[$post_id] ?? 0) === $dashi_original_id) {
            unset(self::$publishRequests[$post_id]);
            Save::cudPostmeta($post_id, 'dashi_original_id', $dashi_original_id);
            if (!static::replacePosts($dashi_original_id, $another->ID)) {
                Save::cudPostmeta($post_id, 'dashi_original_id', $dashi_original_id);
                return $post_id;
            }
            wp_safe_redirect(admin_url('post.php?post=' . $original->ID . '&action=edit'));
            exit;
        }
		// dashi_original_idを加える
        if (!current_user_can('delete_post', $post_id)) return $post_id;
        $snapshot = self::replacementSnapshot($original, $another);
        if (!self::canReplace(get_current_user_id(), $dashi_original_id, $post_id)
            || $snapshot === null || !self::canTransferVisibility($original, $another)) {
            delete_post_meta($post_id, '_dashi_replacement_approval');
            Save::cudPostmeta($post_id, 'dashi_original_id', $dashi_original_id);
            return $post_id;
        }
        Save::cudPostmeta($post_id, 'dashi_original_id', $dashi_original_id);
        if ((int) get_post_meta($post_id, 'dashi_original_id', true) !== $dashi_original_id) return $post_id;
        $approval = [
            'version' => self::APPROVAL_VERSION,
            'original' => $dashi_original_id,
            'replacement' => (int) $post_id,
            'actor' => get_current_user_id(),
            'snapshot' => self::snapshotHash($snapshot),
        ];
        update_post_meta($post_id, '_dashi_replacement_approval', $approval);

	}

	/**
	 * forcePublish
	 *
	 * @return  void
	 */
	public static function forcePublish ()
	{
        foreach (self::dueNotifications(8) as $event) self::deliverNotification($event);
        self::migrateLegacyNotifications();
        // 管理画面で扱う投稿タイプは、公開検索の対象外でも予約の回収対象に含める。
        $postTypes = array_values(array_unique(array_merge(
            get_post_types(['exclude_from_search' => false]), get_post_types(['show_ui' => true])
        )));
        $committed = get_posts([
            'post_type' => $postTypes,
            'post_status' => ['publish', 'future', 'pending', 'draft', 'private'],
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 確定済みの後処理を回収する。
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_dashi_replacement_applied', 'compare' => 'EXISTS'],
                ['key' => '_dashi_replacement_notice_queued', 'compare' => 'NOT EXISTS'],
            ],
            'posts_per_page' => 5,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($committed as $id) self::finishCommittedReplacement($id);

        $posts = get_posts([
            'post_type' => $postTypes,
            'post_status' => ['publish', 'future'],
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 過去の予約の残りを少数ずつ回収する。
            'meta_query' => [
                'relation' => 'AND',
                ['key' => 'dashi_original_id', 'compare' => 'EXISTS'],
                ['key' => '_dashi_replacement_applied', 'compare' => 'NOT EXISTS'],
            ],
            'date_query' => [['column' => 'post_date_gmt', 'before' => gmdate('Y-m-d H:i:s'), 'inclusive' => true]],
            'posts_per_page' => 5,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        foreach ($posts as $post) self::processScheduledReplacement($post);
	}

	/**
	 * futureToPublish
	 *
	 * @param int $post_id
	 * @param object $post
	 * @return  void
	 */
	public static function futureToPublish ($post_id, $post)
	{
        self::processScheduledReplacement($post);
	}

	/**
	 * getAnotherLink
	 *
	 * @return  string
	 */
	public static function getAnotherLink ($post_id)
	{
		$posttype = P::class2posttype(P::postid2class($post_id));
		$url = add_query_arg(
			array(
				'post_type' => $posttype,
				'dashi_original_id' => (int) $post_id,
			),
			'post-new.php'
		);
		return wp_nonce_url($url, static::originalNonceAction($post_id), '_dashi_original_nonce');
	}

	/**
	 * replacePosts
	 *
	 * @return  void
	 */
	public static function replacePosts ($original_id, $another_id, $scheduled = false)
	{
        $lock = PublicFormState::lock('replacement_' . (int) $original_id, 0, false);
        if (!$lock) return false;
        try {
            $original = get_post($original_id);
            $another = get_post($another_id);
            if (!$original || !$another || $original->ID === $another->ID || $original->post_status === 'trash'
                || $original->post_type !== $another->post_type || !self::canTransferVisibility($original, $another)
                || get_post_meta($another_id, '_dashi_replacement_applied', true)) return false;
            if ($scheduled && !self::isDueReplacement($another)) return false;

            $snapshot = self::replacementSnapshot($original, $another);
            if ($snapshot === null) return false;
            $actor = $scheduled || !get_current_user_id()
                ? self::scheduledActor($original_id, $another_id, self::snapshotHash($snapshot)) : get_current_user_id();
            if (!self::canReplace($actor, $original_id, $another_id)) return false;
            foreach ([$original_id, $another_id] as $postId) {
                if (PublicUploadBlob::hasForPost($postId) || self::hasPrivateUploadManifest($postId)) return false;
            }
            $prepared = [];
            foreach ($snapshot['meta'] as $field => $values) {
                foreach ($values as $value) {
                    $value = sanitize_meta($field, $value, 'post', $original->post_type);
                    if (is_wp_error($value)) return false;
                    $prepared[$field][] = $value;
                }
            }
            $result = self::applyReplacement($original, $another, $snapshot, $prepared, $scheduled, $lock);
            if ($result === false) return false;
        } finally {
            PublicFormState::unlock($lock);
        }

        // 外側のトランザクションがある場合、確定後の後処理は定期処理に任せる。
        if ($result === 'committed') self::deliverReplacementNotification($original_id, $another_id);
        return true;
    }

    private static function canTransferVisibility($original, $another)
    {
        if (self::isRestrictedSource($another)) return false;
        $allowed = apply_filters('dashi_can_replace_post_visibility', null, $original, $another);
        if ($allowed !== null) return $allowed === true;
        // Dashiが把握できる追加の閲覧制限には、サイトから明示した判定を要求する。
        return !has_filter('post_password_required') && !has_filter('dashi_search_can_show_result');
    }

    private static function isRestrictedSource($post)
    {
        return $post->post_password !== '' || in_array($post->post_status, ['private', 'trash'], true);
    }

    private static function snapshotHash(array $snapshot)
    {
        return hash('sha256', serialize($snapshot));
    }

    private static function replacementSnapshot($original, $another)
    {
        $snapshot = [
            'post' => [
                'post_title' => $another->post_title, 'post_content' => $another->post_content,
                'post_excerpt' => $another->post_excerpt, 'post_date' => $another->post_date,
                'post_date_gmt' => $another->post_date_gmt,
            ],
            'meta' => [], 'terms' => [],
        ];
        $class = P::post2class($original);
        $taxonomies = get_object_taxonomies($original->post_type);
        if ($class) {
            $class::rebuildCustomFields($class, $original->post_name, $original->post_type);
            $fields = array_diff($class::getCustomFieldsKeys(), CustomFields::excludes());
            foreach (Revisions::storedFieldKeys($class, $fields) as $field) {
                $snapshot['meta'][$field] = get_post_meta($another->ID, $field);
            }
        }
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($another->ID, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($terms)) return null;
            sort($terms, SORT_NUMERIC);
            $snapshot['terms'][$taxonomy] = array_map('intval', $terms);
        }
        ksort($snapshot['meta']);
        ksort($snapshot['terms']);
        return $snapshot;
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WordPressの固定テーブルを使い、確定前には保存フックを発火させない。
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 対象の投稿IDを限定したトランザクション内の書き込み。
    private static function inTransaction()
    {
        global $wpdb;
        $previous = $wpdb->suppress_errors(true);
        try {
            $status = $wpdb->get_var('SELECT @@in_transaction');
            if ($status !== null) return (int) $status === 1;
            return false !== $wpdb->query('SAVEPOINT dashi_replacement_probe')
                && false !== $wpdb->query('RELEASE SAVEPOINT dashi_replacement_probe');
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

    private static function verifyLockedReplacement($original, $another, array $snapshot, $scheduled)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->posts} WHERE ID IN (%d,%d) ORDER BY ID FOR UPDATE", $original->ID, $another->ID
        ), OBJECT_K);
        if (count($rows) !== 2) return false;
        $source = new \WP_Post($rows[$another->ID]);
        $target = new \WP_Post($rows[$original->ID]);
        foreach (['post_status', 'post_password', 'post_author', 'post_type', 'post_parent', 'post_name'] as $field) {
            if ((string) $source->$field !== (string) $another->$field
                || (string) $target->$field !== (string) $original->$field) return false;
        }
        foreach ($snapshot['post'] as $field => $expected) {
            if ($source->$field !== $expected) return false;
        }
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id IN (%d,%d) ORDER BY meta_id FOR UPDATE", $target->ID, $source->ID
        ));
        if ($wpdb->last_error !== '') return false;
        $sourceMeta = [];
        foreach ($meta as $row) {
            if ((int) $row->post_id === (int) $source->ID) $sourceMeta[$row->meta_key][] = maybe_unserialize($row->meta_value);
        }
        if (!empty($sourceMeta['_dashi_replacement_applied'])) return false;
        foreach ($snapshot['meta'] as $field => $expected) {
            if (($sourceMeta[$field] ?? []) !== $expected) return false;
        }
        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT r.object_id,t.taxonomy,t.term_id FROM {$wpdb->term_relationships} r
             JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
             WHERE r.object_id IN (%d,%d) ORDER BY r.object_id,t.term_id FOR UPDATE", $source->ID, $target->ID
        ));
        if ($wpdb->last_error !== '') return false;
        $actual = [];
        foreach ($terms as $term) {
            if ((int) $term->object_id === (int) $source->ID) $actual[$term->taxonomy][] = (int) $term->term_id;
        }
        foreach ($snapshot['terms'] as $taxonomy => $expected) {
            if (($actual[$taxonomy] ?? []) !== $expected) return false;
        }

        foreach ([$source->ID, $target->ID] as $id) {
            wp_cache_delete($id, 'posts');
            wp_cache_delete($id, 'post_meta');
            foreach (array_keys($snapshot['terms']) as $taxonomy) wp_cache_delete($id, $taxonomy . '_relationships');
        }
        wp_cache_set('last_changed', microtime(), 'terms');
        if (!self::canTransferVisibility($target, $source)) return false;
        if (($scheduled || !get_current_user_id())
            && (int) ($sourceMeta['dashi_original_id'][0] ?? 0) !== (int) $target->ID) return false;
        $actor = $scheduled || !get_current_user_id()
            ? self::approvalActor($sourceMeta['_dashi_replacement_approval'][0] ?? null, $target->ID, $source->ID, self::snapshotHash($snapshot))
            : get_current_user_id();
        return self::canReplace($actor, $target->ID, $source->ID);
    }

    private static function applyReplacement($original, $another, array $snapshot, array $prepared, $scheduled, $lock)
    {
        global $wpdb;
        $engines = $wpdb->get_col($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s)',
            $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships, $wpdb->term_taxonomy
        ));
        if (count($engines) !== 4 || array_diff($engines, ['InnoDB'])) return false;

        $nested = self::inTransaction();
        $began = $nested ? $wpdb->query('SAVEPOINT dashi_replacement') : $wpdb->query('START TRANSACTION');
        if ($began === false) return false;
        $saved = false;
        $finalizing = false;
        try {
            if (!self::verifyLockedReplacement($original, $another, $snapshot, $scheduled)) return false;
            foreach ([$original->ID, $another->ID] as $postId) {
                if (PublicUploadBlob::hasForPost($postId) || self::hasPrivateUploadManifest($postId)) return false;
            }
            if (!self::inTransaction()) return false;
            $changes = $snapshot['post'] + [
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            ];
            $where = ['ID' => $original->ID, 'post_status' => $original->post_status, 'post_password' => $original->post_password];
            // 再接続先でSQLが再試行されても、元の接続のロックがなければ書き込まない。
            if ($wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts}
                 SET post_title = %s, post_content = %s, post_excerpt = %s,
                     post_date = %s, post_date_gmt = %s, post_modified = %s, post_modified_gmt = %s
                 WHERE ID = %d AND post_status = %s AND post_password = %s
                   AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $changes['post_title'], $changes['post_content'], $changes['post_excerpt'],
                $changes['post_date'], $changes['post_date_gmt'], $changes['post_modified'],
                $changes['post_modified_gmt'], $original->ID, $original->post_status,
                $original->post_password, $lock
            )) === false) return false;
            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $original->ID), ARRAY_A);
            if (!$current || array_diff_assoc($changes + $where, $current)) return false;

            foreach (array_keys($snapshot['meta']) as $field) {
                if ($wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->postmeta}
                     WHERE post_id = %d AND meta_key = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                    $original->ID, $field, $lock
                )) === false) return false;
                foreach (($prepared[$field] ?? []) as $value) {
                    if (!self::insertLockedReplacementMeta($original->ID, $field, $value, $lock)) return false;
                }
            }

            $affected = [];
            foreach ($snapshot['terms'] as $taxonomy => $terms) {
                $old = $wpdb->get_col($wpdb->prepare(
                    "SELECT r.term_taxonomy_id FROM {$wpdb->term_relationships} r
                     JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id
                     WHERE r.object_id = %d AND t.taxonomy = %s",
                    $original->ID, $taxonomy
                ));
                if ($wpdb->last_error !== '') return false;
                foreach ($old as $termTaxonomyId) {
                    if ($wpdb->query($wpdb->prepare(
                        "DELETE FROM {$wpdb->term_relationships}
                         WHERE object_id = %d AND term_taxonomy_id = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                        $original->ID, $termTaxonomyId, $lock
                    )) !== 1) return false;
                }

                $affected[$taxonomy] = array_map('intval', $old);
                foreach ($terms as $term) {
                    $termTaxonomyId = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s",
                        $term, $taxonomy
                    ));
                    if (!$termTaxonomyId || $wpdb->query($wpdb->prepare(
                        "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order)
                         SELECT %d, %d, 0 FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()",
                        $original->ID, $termTaxonomyId, $lock
                    )) !== 1) return false;
                    $affected[$taxonomy][] = $termTaxonomyId;
                }
            }

            $applied = ['original' => $original->ID, 'queued' => true, 'previous_status' => $another->post_status];
            if (!self::insertLockedReplacementMeta($another->ID, '_dashi_replacement_applied', $applied, $lock)
                || !self::insertLockedReplacementMeta($another->ID, '_dashi_replacement_notice_queued', '1', $lock)) {
                return false;
            }
            // 適用済み原稿は非公開の下書きとして保存する。通知側で原稿を削除しない。
            if ($wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_status = 'draft'
                 WHERE ID = %d AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $another->ID, $lock
            )) === false) return false;
            $version = self::notificationVersion($original->ID);
            if ($version === null || !self::insertLockedReplacementMeta(
                $original->ID,
                self::notificationKey(time()),
                ['source' => $another->ID, 'terms' => $affected, 'version' => $version],
                $lock
            )) return false;
            if (!self::ownsReplacementLock($lock)) return false;
            $finalizing = true;
            $result = $nested ? $wpdb->query('RELEASE SAVEPOINT dashi_replacement') : $wpdb->query('COMMIT');
            $saved = $result !== false && self::ownsReplacementLock($lock);
            return !$saved ? false : ($nested ? 'deferred' : 'committed');
        } finally {
            if (!$saved && $nested) {
                $wpdb->query('ROLLBACK TO SAVEPOINT dashi_replacement');
                $wpdb->query('RELEASE SAVEPOINT dashi_replacement');
            } elseif (!$saved) {
                $wpdb->query('ROLLBACK');
            }
            if ($finalizing) {
                // 確定の応答を失ったときも、古い投稿や分類をキャッシュに残さない。
                wp_cache_delete($original->ID, 'posts');
                wp_cache_delete($original->ID, 'post_meta');
                wp_cache_delete($another->ID, 'posts');
                wp_cache_delete($another->ID, 'post_meta');
                foreach (array_keys($snapshot['terms']) as $taxonomy) {
                    wp_cache_delete($original->ID, $taxonomy . '_relationships');
                }
                wp_cache_set('last_changed', microtime(), 'terms');
                wp_cache_set('last_changed', microtime(), 'posts');
            }
        }
    }
    private static function ownsReplacementLock($lock)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock)) === 1;
    }

    private static function insertLockedReplacementMeta($postId, $key, $value, $lock)
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
             SELECT %d, %s, %s FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()",
            $postId, $key, maybe_serialize($value), $lock
        )) === 1;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

    private static function notificationKey($time)
    {
        return self::NOTIFICATION_PREFIX . sprintf('%012d', $time);
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 期限つき通知はメタキー索引を使い、現行版はキャッシュを介さず照合する。
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 期限の索引またはメタIDで対象を絞る。
    private static function notificationVersion($id, &$post = null)
    {
        global $wpdb;
        $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
        if (!$post) return null;
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d
             AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s
             AND meta_key NOT IN (%s,%s,%s,%s) ORDER BY meta_id",
            $id, $wpdb->esc_like(self::NOTIFICATION_PREFIX) . '%', $wpdb->esc_like('_dashi_search_') . '%',
            '_dashi_replacement_notification', '_edit_lock', '_edit_last', 'dashi_search'
        ), ARRAY_A);
        if ($wpdb->last_error !== '') return null;
        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT term_taxonomy_id, term_order FROM {$wpdb->term_relationships}
             WHERE object_id = %d ORDER BY term_taxonomy_id", $id
        ), ARRAY_A);
        if ($wpdb->last_error !== '') return null;
        return self::snapshotHash([$post, $meta, $terms]);
    }

    private static function dueNotifications($limit, $originalId = 0)
    {
        global $wpdb;
        $prefix = $wpdb->esc_like(self::NOTIFICATION_PREFIX) . '%';
        $due = self::notificationKey(time());
        if ($originalId) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id = %d AND meta_key LIKE %s AND meta_key <= %s ORDER BY meta_key, meta_id LIMIT %d",
                $originalId, $prefix, $due, $limit
            ));
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key LIKE %s AND meta_key <= %s ORDER BY meta_key, meta_id LIMIT %d", $prefix, $due, $limit
        ));
    }

    private static function migrateLegacyNotifications()
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id LIMIT 2",
            '_dashi_replacement_notification'
        ));
        foreach ($rows as $row) {
            $record = maybe_unserialize($row->meta_value);
            if (!is_array($record) || empty($record['source'])) {
                $wpdb->delete($wpdb->postmeta, ['meta_id' => $row->meta_id, 'meta_key' => '_dashi_replacement_notification']);
                continue;
            }
            // 旧形式の通知は原稿の後処理だけを引き継ぐ。
            unset($record['version']);
            $wpdb->update($wpdb->postmeta, [
                'meta_key' => self::notificationKey(time()), 'meta_value' => maybe_serialize($record),
            ], ['meta_id' => $row->meta_id, 'meta_key' => '_dashi_replacement_notification']);
        }
    }

    private static function finishCommittedReplacement($replacementId)
    {
        if (self::inTransaction()) return;
        $record = get_post_meta($replacementId, '_dashi_replacement_applied', true);
        $id = is_array($record) ? (int) ($record['original'] ?? 0) : (int) $record;
        if (!$id || !get_post($id)) return;
        // 旧版の原稿側だけの記録を回収する。現行版には同じ原稿の通知がすでにある。
        if (is_array($record) && !empty($record['queued'])) {
            update_post_meta($replacementId, '_dashi_replacement_notice_queued', 1);
            return;
        }
        $lock = PublicFormState::lock('replacement_notification_' . $id, 0);
        if (!$lock) return;
        try {
            wp_cache_delete($replacementId, 'post_meta');
            if (get_post_meta($replacementId, '_dashi_replacement_notice_queued', true)) return;
            $queued = get_post_meta($replacementId, '_dashi_replacement_applied', true);
            $target = is_array($queued) ? (int) ($queued['original'] ?? 0) : (int) $queued;
            if ($target !== $id || (is_array($queued) && !empty($queued['queued']))) return;
            $notice = [
                'source' => (int) $replacementId, 'terms' => is_array($queued) ? ($queued['terms'] ?? []) : [],
            ];
            if (!self::insertLockedReplacementMeta($id, self::notificationKey(time()), $notice, $lock)) return;
            update_post_meta($replacementId, '_dashi_replacement_notice_queued', 1);
        } finally {
            PublicFormState::unlock($lock);
        }
    }

    private static function deliverReplacementNotification($originalId, $replacementId = 0)
    {
        foreach (self::dueNotifications(20, $originalId) as $event) {
            $record = maybe_unserialize($event->meta_value);
            if (!$replacementId || (int) ($record['source'] ?? 0) === (int) $replacementId) {
                self::deliverNotification($event);
                return;
            }
        }
    }

    private static function deliverNotification($event)
    {
        if (self::inTransaction()) return;
        global $wpdb;
        $eventId = (int) $event->meta_id;
        $lock = 'dashi_notice_' . md5(DB_NAME . ':' . $wpdb->prefix . ':' . $eventId);
        if (isset(self::$deliveringNotifications[$lock])) return;
        // 旧MySQLでは連携処理が別のロックを取っても解除されないよう、通知専用の接続を使う。
        $lockDb = PublicFormState::supportsMultipleLocks()
            ? $wpdb : self::connectNotificationDatabase(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        if (!$lockDb) return;
        try {
            if ((int) $lockDb->get_var($lockDb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) return;
            self::$deliveringNotifications[$lock] = true;
            try {
                self::deliverLockedNotification($event, $eventId, $lockDb, $lock);
            } finally {
                unset(self::$deliveringNotifications[$lock]);
                $lockDb->get_var($lockDb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        } finally {
            if ($lockDb !== $wpdb) $lockDb->close();
        }
    }

    private static function connectNotificationDatabase($user, $password, $name, $host)
    {
        try {
            $connection = new class($user, $password, $name, $host) extends \wpdb {
                public function db_connect($allow_bail = true)
                {
                    return @parent::db_connect(false);
                }

                public function check_connection($allow_bail = true)
                {
                    return parent::check_connection(false);
                }

                public function bail($message, $error_code = '500')
                {
                    return false;
                }
            };
        } catch (\Throwable $error) {
            // この接続は通知専用。既に確定した差し替えには影響させない。
            return null;
        }
        if ($connection->ready) return $connection;
        $connection->close();
        return null;
    }

    private static function deliverLockedNotification($event, $eventId, $lockDb, $lock)
    {
        global $wpdb;
        $leased = self::notificationKey(time() + 5 * MINUTE_IN_SECONDS);
        $payload = $event->meta_value;
        $originalId = (int) $event->post_id;
        $table = $wpdb->postmeta;
        if ($lockDb->query($lockDb->prepare(
            "UPDATE {$table} SET meta_key = %s WHERE meta_id = %d AND post_id = %d AND meta_key = %s
             AND BINARY meta_value = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
            $leased, $eventId, $originalId, $event->meta_key, $payload, $lock
        )) !== 1) return;

        $ownsDelivery = static function () use ($lockDb, $table, $eventId, $originalId, $leased, &$payload, $lock) {
            return (int) $lockDb->get_var($lockDb->prepare(
                "SELECT 1 FROM {$table} WHERE meta_id = %d AND post_id = %d AND meta_key = %s
                 AND BINARY meta_value = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $eventId, $originalId, $leased, $payload, $lock
            )) === 1;
        };

        $saveProgress = static function (array $record) use ($lockDb, $table, $eventId, $originalId, $leased, &$payload, $lock) {
            $next = maybe_serialize($record);
            if ($lockDb->query($lockDb->prepare(
                "UPDATE {$table} SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND meta_key = %s
                 AND BINARY meta_value = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $next, $eventId, $originalId, $leased, $payload, $lock
            )) !== 1) return false;
            $payload = $next;
            return true;
        };

        $record = (array) maybe_unserialize($payload);
        $source = (int) ($record['source'] ?? 0);
        try {
            if (!$ownsDelivery() || ($source && !self::notifyCommittedReplacement(
                $originalId, $source, $record, $ownsDelivery, $saveProgress
            ))) return;
            $lockDb->query($lockDb->prepare(
                "DELETE FROM {$table} WHERE meta_id = %d AND post_id = %d AND meta_key = %s
                 AND BINARY meta_value = %s AND IS_USED_LOCK(%s) = CONNECTION_ID()",
                $eventId, $originalId, $leased, $payload, $lock
            ));
        } catch (\Throwable $error) {
            // 記録は期限後に同じイベントIDで再試行する。
        }
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

    private static function notifyCommittedReplacement($originalId, $replacementId, array $record, callable $ownsDelivery, callable $saveProgress)
    {
        global $wpdb;
        // 送信を開始したまま中断した宛先は、旧版や非公開になった記事でも記録する。
        $started = (array) ($record['mail_started'] ?? []);
        if (in_array('sending', $started, true)) {
            foreach ($started as $id => $status) {
                if ($status === 'sending') $started[$id] = 'uncertain';
            }
            $record['mail_started'] = $started;
            if (!$saveProgress($record)) return false;
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 配送の成否を確認できない通知を運用者に知らせる。
            error_log('Dashi: replacement notification mail delivery is uncertain for post ' . (int) $originalId . '.');
        }

        clean_post_cache($originalId);
        $original = get_post($originalId);
        if (!$original) return $wpdb->last_error === '';
        foreach (($record['terms'] ?? []) as $taxonomy => $ids) {
            if ($ids) wp_update_term_count(array_unique($ids), $taxonomy);
        }
        Save::refreshSearchIndex($original);
        Search::updatePagePartIndex($original->ID, $original);
        $version = $record['version'] ?? '';
        if (!is_string($version) || !preg_match('/\A[a-f0-9]{64}\z/', $version)) return true;

        // 連携先には最新の公開状態・版・通知の所有権を確認する関数を渡す。
        $verify = static function () use ($originalId, $replacementId, $version, $ownsDelivery) {
            if (!$ownsDelivery()) return null;
            $post = self::currentNotificationPost($originalId, $replacementId, $version);
            return $ownsDelivery() ? $post : null;
        };
        $original = $verify();
        if (!$original) return $original === false;
        $getPublicPost = static function () use ($verify) {
            $post = $verify();
            return $post instanceof \WP_Post ? $post : null;
        };
        if (empty($record['integration_done'])) {
            $dispatched = self::dispatchReplacementIntegrations(
                [$originalId, $replacementId, $getPublicPost], $verify, $record, $saveProgress
            );
            if ($dispatched !== true) return $dispatched === false;
            $original = $verify();
            if (!$original) return $original === false;
            $record['integration_done'] = true;
            if (!$saveProgress($record)) return false;
        }

        $original = $verify();
        if (!$original) return $original === false;
        return self::sendAReplaceMail($original, $getPublicPost, $record, $saveProgress);
    }

    private static function dispatchReplacementIntegrations(array $args, callable $verify, array &$record, callable $saveProgress)
    {
        static $dispatching = false;
        if ($dispatching) return null;

        global $wp_filter;
        $name = 'dashi_replacement_committed';
        $wrappers = [];
        $slots = [];
        $ordinals = [];
        $completed = (array) ($record['integration_callbacks'] ?? []);
        $result = true;
        $executing = false;

        $wrapCallbacks = static function () use (
            &$wp_filter, $name, $verify, $saveProgress,
            &$record, &$completed, &$result, &$executing, &$wrapCallbacks, &$wrappers, &$slots, &$ordinals
        ) {
            $hook = $wp_filter[$name] ?? null;
            if (!$hook instanceof \WP_Hook) return;
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $key => $entry) {
                    $callback = $entry['function'];
                    $slot = $slots[$priority][$key] ?? null;
                    if ($slot && $callback === $slot['wrapper']) continue;
                    if ($slot && $callback === $slot['callback']) {
                        $id = $slot['id'];
                    } else {
                        $base = self::replacementIntegrationIdentity($callback, $priority);
                        $ordinal = $ordinals[$base] ?? 0;
                        $ordinals[$base] = $ordinal + 1;
                        $id = hash('sha256', $base . ':' . $ordinal);
                    }
                    $wrapper = static function (...$callbackArgs) use (
                        $callback, $id, $verify, $saveProgress, &$record, &$completed, &$result, &$executing, &$wrapCallbacks
                    ) {
                        if ($executing || $result !== true || isset($completed[$id])) return;
                        $executing = true;
                        try {
                            $post = $verify();
                            if (!$post) {
                                $result = $post === false ? false : null;
                                return;
                            }
                            call_user_func_array($callback, $callbackArgs);
                            if (!PublicFormState::canFinalizeSubmission()) {
                                $result = null;
                                return;
                            }
                            $completed[$id] = true;
                            $record['integration_callbacks'] = $completed;
                            $result = $saveProgress($record) ? true : null;
                        } catch (\Throwable $error) {
                            $result = null;
                        } finally {
                            $executing = false;
                            // 次の優先度に移る前に、この処理が追加したコールバックを包む。
                            $wrapCallbacks();
                        }
                    };
                    $slots[$priority][$key] = ['id' => $id, 'callback' => $callback, 'wrapper' => $wrapper];
                    $wrappers[] = [$hook, $priority, $key, $callback, $wrapper];
                    $hook->callbacks[$priority][$key]['function'] = $wrapper;
                }
            }
        };
        // 「all」で実行前に追加されたコールバックにも同じ確認を適用する。
        $wrapAfterAll = static function ($action) use ($name, $wrapCallbacks) {
            if ($action === $name) $wrapCallbacks();
        };
        $dispatching = true;
        try {
            $wrapCallbacks();
            add_action('all', $wrapAfterAll, PHP_INT_MAX);
            do_action('dashi_replacement_committed', ...$args);
        } finally {
            remove_action('all', $wrapAfterAll, PHP_INT_MAX);
            foreach ($wrappers as [$hook, $priority, $key, $callback, $wrapper]) {
                // コールバック自身が登録を削除・置換した場合は、その変更を尊重する。
                if (($hook->callbacks[$priority][$key]['function'] ?? null) === $wrapper) {
                    $hook->callbacks[$priority][$key]['function'] = $callback;
                }
            }
            $wrapCallbacks = null;
            $dispatching = false;
        }
        return $result;
    }

    private static function replacementIntegrationIdentity($callback, $priority)
    {
        if ($callback instanceof \Closure) {
            $source = new \ReflectionFunction($callback);
            $file = wp_normalize_path((string) $source->getFileName());
            $root = wp_normalize_path(ABSPATH);
            if (strpos($file, $root) === 0) $file = substr($file, strlen($root));
            $signature = ['closure', $file, $source->getStartLine(), $source->getEndLine()];
        } elseif (is_array($callback)) {
            $signature = [is_object($callback[0]) ? get_class($callback[0]) : $callback[0], $callback[1]];
        } elseif (is_object($callback)) {
            $signature = [get_class($callback), '__invoke'];
        } else {
            $signature = [$callback];
        }
        return serialize([$priority, $signature]);
    }

    private static function currentNotificationPost($originalId, $replacementId, $expected)
    {
        global $wpdb;
        $version = self::notificationVersion($originalId, $verified);
        if ($version === null) return !$verified && $wpdb->last_error === '' ? false : null;
        if (!hash_equals($expected, $version)) return false;
        $verified['ID'] = (int) $verified['ID'];
        $post = new \WP_Post((object) $verified);
        return self::canDispatchReplacementNotification($post, $replacementId) ? $post : false;
    }

    private static function canDispatchReplacementNotification($post, $replacementId)
    {
        if ($post->post_status !== 'publish' || $post->post_password !== ''
            || !is_post_type_viewable($post->post_type)) return false;

        $decision = apply_filters('dashi_can_dispatch_replacement_notification', null, $post, $replacementId);
        if ($decision !== null) return $decision === true;
        return !has_filter('dashi_can_replace_post_visibility')
            && !has_filter('dashi_search_can_show_result') && !has_filter('post_password_required');
    }

    public static function preserveAppliedDraftCapabilities($caps, $cap, $userId, $args)
    {
        if (!in_array($cap, ['edit_post', 'edit_page', 'delete_post', 'delete_page'], true) || empty($args[0])) return $caps;
        $post = get_post($args[0]);
        if (!$post) return $caps;
        if ($post->post_type === 'revision') $post = get_post($post->post_parent);
        if (!$post) return $caps;
        $record = get_post_meta($post->ID, '_dashi_replacement_applied', true);
        if (!$record) return $caps;

        // 旧版では元の状態を記録していないため、予約済み原稿と同じ権限を要求する。
        $prior = is_array($record) ? ($record['previous_status'] ?? 'future') : 'future';
        if (!in_array($prior, ['publish', 'future'], true)) return $caps;
        $type = get_post_type_object($post->post_type);
        if (!$type) return $caps;
        $required = strpos($cap, 'delete_') === 0 ? 'delete_published_posts' : 'edit_published_posts';
        if (isset($type->cap->$required)) $caps[] = $type->cap->$required;
        return array_unique($caps);
    }

    public static function protectReplacementTarget($protected, $key, $type)
    {
        return $protected || ($type === 'post' && $key === 'dashi_original_id');
    }

    private static function scheduledActor($originalId, $replacementId, $snapshotHash)
    {
        $approval = get_post_meta($replacementId, '_dashi_replacement_approval', true);
        return self::approvalActor($approval, $originalId, $replacementId, $snapshotHash);
    }

    private static function approvalActor($approval, $originalId, $replacementId, $snapshotHash)
    {
        if (!is_array($approval) || ($approval['version'] ?? null) !== self::APPROVAL_VERSION
            || ($approval['original'] ?? 0) !== (int) $originalId
            || ($approval['replacement'] ?? 0) !== (int) $replacementId
            || !is_string($approval['snapshot'] ?? null) || !hash_equals($approval['snapshot'], $snapshotHash)) return 0;
        return (int) ($approval['actor'] ?? 0);
    }

    private static function canReplace($actor, $originalId, $replacementId)
    {
        $type = get_post_type_object(get_post_type($originalId) ?: '');
        return $actor && $type && (int) $originalId !== (int) $replacementId
            && user_can($actor, $type->cap->publish_posts)
            && user_can($actor, 'edit_post', $originalId)
            && user_can($actor, 'edit_post', $replacementId)
            && user_can($actor, 'delete_post', $replacementId);
    }

    public static function holdUnapprovedReplacement($postId)
    {
        $replacement = get_post($postId);
        if (!$replacement || $replacement->post_status !== 'future') return;
        if (strtotime($replacement->post_date_gmt . ' GMT') > time()) {
            check_and_publish_future_post($postId);
            return;
        }
        $canPublish = self::canPublishDirectly($postId);
        if ($canPublish === null) {
            self::retryFutureReplacement($postId);
        } elseif ($canPublish) {
            check_and_publish_future_post($postId);
        } elseif (get_post_meta($postId, 'dashi_original_id', true)) {
            self::processScheduledReplacement($replacement);
        } else {
            // 承認などの痕跡が残る原稿は、元投稿との関連を確認できるまで保留する。
            self::holdReplacement($replacement, 0);
        }
    }

    private static function processScheduledReplacement($replacement)
    {
        $target = (int) get_post_meta($replacement->ID, 'dashi_original_id', true);
        if (!$target) return;
        if (!self::replacePosts($target, $replacement->ID, true)) self::holdReplacement($replacement, $target);
    }

    private static function retryFutureReplacement($postId)
    {
        $args = [(int) $postId];
        $next = wp_next_scheduled('publish_future_post', $args);
        if (!$next || $next <= time()) wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'publish_future_post', $args);
    }

    private static function holdReplacement($replacement, $target)
    {
        $postId = (int) $replacement->ID;
        if (!self::isDueReplacement($replacement)) return;
        $lock = PublicFormState::lock('replacement_' . ($target ?: $postId), 0, false);
        if (!$lock) {
            if ($replacement->post_status === 'future') self::retryFutureReplacement($postId);
            return;
        }

        global $wpdb;
        try {
            // 元投稿の変更・確定と競合しても、更新時点の状態で保留の可否を判断する。
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 投稿状態の変更条件を単一のSQLで照合する。
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_status = 'pending', post_modified = %s, post_modified_gmt = %s
                 WHERE ID = %d AND post_status = %s AND post_date_gmt = %s
                   AND IS_USED_LOCK(%s) = CONNECTION_ID()
                   AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta}
                       WHERE post_id = %d AND meta_key = '_dashi_replacement_applied')
                   AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta}
                       WHERE post_id = %d AND meta_key = 'dashi_original_id'
                         AND meta_value NOT IN ('', '0', %s))
                   AND EXISTS (SELECT 1 FROM {$wpdb->postmeta}
                       WHERE post_id = %d AND ((%d > 0 AND meta_key = 'dashi_original_id' AND meta_value = %s)
                          OR (%d = 0 AND meta_key = '_dashi_replacement_approval')))",
                current_time('mysql'), current_time('mysql', true), $postId, $replacement->post_status,
                $replacement->post_date_gmt, $lock, $postId, $postId, (string) $target,
                $postId, $target, (string) $target, $target
            ));
            // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $retry = $updated === false || ($updated === 0 && !self::ownsReplacementLock($lock));
        } finally {
            PublicFormState::unlock($lock);
        }

        if ($updated !== 1) {
            clean_post_cache($postId);
            if ($retry && get_post_status($postId) === 'future') self::retryFutureReplacement($postId);
            return;
        }
        clean_post_cache($postId);
        $held = get_post($postId);
        if ($held && $held->post_status === 'pending') {
            wp_transition_post_status('pending', $replacement->post_status, $held);
            Search::updatePagePartIndex($postId, $held);
        }
    }

    private static function isDueReplacement($post)
    {
        return in_array($post->post_status, ['future', 'publish'], true)
            && $post->post_date_gmt <= gmdate('Y-m-d H:i:s');
    }

	private static function hasPrivateUploadManifest($post_id)
	{
		foreach ((array) get_post_meta($post_id, '_dashi_public_uploads', true) as $upload) {
			if (is_array($upload) && empty($upload['final_url'])) return true;
		}
		return false;
	}

	/**
	 * sendAFailMail
	 *
	 * @param  integer $original_id
	 * @return  void
	 */
	private static function sendAFailMail ($original_id)
	{
		if (get_option('dashi_another_done_sendmail'))
		{
			$to = get_option('admin_email');
				$subject = sprintf(
                    /* translators: %s: site URL */
                    __('WordPress: Failed to Publish Another @ %s', 'dashi'),
                    home_url()
                );
				$message = sprintf(
                    /* translators: 1: post permalink, 2: message separator */
                    __("Failed to update content.\n\n%1\$s\n\n%2\$s\nDashi Plugin", 'dashi'),
                    get_permalink($original_id),
                    "-- \n"
                );
			\Dashi\Core\Mail::send($to, $subject, $message);

			// 管理者と別のユーザが記事を作っていたらそちらにも送信する
			$posted = get_post($original_id);
			$userdata = get_userdata($posted->post_author);
			if ($userdata->data->user_email != $to)
			{
				\Dashi\Core\Mail::send($userdata->data->user_email, $subject, $message);
			}
		}
	}

	/**
	 * sendAReplaceMail
	 *
	 * @param  integer $posted_id
	 * @return  void
	 */
    private static function sendAReplaceMail($post, callable $getPublicPost, array $record, callable $saveProgress)
    {
        if (!get_option('dashi_another_done_sendmail')) return true;

        $getRecipients = static function ($post) {
            $author = get_userdata($post->post_author);
            return [get_option('admin_email'), $author ? $author->user_email : ''];
        };
        $recipients = $getRecipients($post);
        $subject = sprintf(
            /* translators: %s: site URL */
            __('WordPress: Publish Another @ %s', 'dashi'),
            home_url()
        );
        $message = sprintf(
            /* translators: 1: post permalink, 2: message separator */
            __("Update content has done by reserved content.\n\n%1\$s\n\n%2\$s\nDashi Plugin", 'dashi'),
            get_permalink($post->ID),
            "-- \n"
        );

        $complete = true;
        $sent = (array) ($record['mail_sent'] ?? []);
        $started = (array) ($record['mail_started'] ?? []);
        $attempted = [];
        foreach ($recipients as $address) {
            if (!is_string($address) || !is_email($address)) continue;
            // キューに宛先の平文を残さず、管理者と投稿者が同一宛先なら1回にまとめる。
            $at = strrpos($address, '@');
            $mailbox = substr($address, 0, $at) . strtolower(substr($address, $at));
            $id = hash_hmac('sha256', $mailbox, wp_salt('auth'));
            if (isset($sent[$id]) || isset($attempted[$id])) continue;
            $attempted[$id] = true;
            // 受付済みか判定できない宛先は、再試行中も送信しない。
            if (isset($started[$id])) continue;

            $canSend = static function () use ($getPublicPost, $getRecipients, $address) {
                $verified = $getPublicPost();
                return $verified && in_array($address, $getRecipients($verified), true)
                    && PublicFormState::canFinalizeSubmission() && $getPublicPost();
            };
            if (!$canSend()) {
                $complete = false;
                continue;
            }
            $started[$id] = 'sending';
            $record['mail_started'] = $started;
            if (!$saveProgress($record)) return false;

            $accepted = \Dashi\Core\Mail::send($address, $subject, $message, '', '', $canSend);
            if (!PublicFormState::canFinalizeSubmission()) return false;
            unset($started[$id]);
            $record['mail_started'] = $started;
            if ($accepted) {
                $sent[$id] = true;
                $record['mail_sent'] = $sent;
            } else {
                $complete = false;
            }
            if (!$saveProgress($record)) return false;
        }
        return $complete;
    }
}
