<?php
namespace Dashi\Core\Posttype;

class Another
{
	static $anothers = array();

	/**
	 * forge
	 *
	 * @return  void
	 */
	public static function forge ()
	{
		// 差し替えのフォールバック
		add_action(
			'wp_loaded',
			array('\\Dashi\\Core\\Posttype\\Another', 'forcePublish')
		);

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

		// 差し替えるためのフック
		foreach ($posttypes as $posttype)
		{
			add_filter(
				'publish_'.$posttype,
				array('\\Dashi\\Core\\Posttype\\Another', 'futureToPublish'),
				10,
				2
			);
		}

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
		if ($another)
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
				$str = $post->post_status == 'pending' ?
							 __('Another version is pending (date: %s)', 'dashi') :
							 __('Another version is exists (date: %s)', 'dashi');
				$str = '<strong style="display:block;">'.sprintf($str, date($date_format, $another_utime)).'</strong>';
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
				 'Edit another version' :
				 'Add another version';

		$script = '';
		$script.= '<script type="text/javascript">';
		$script.= 'jQuery (function($){';

		// 差し替えの編集画面の場合
		if (isset($post->dashi_original_id))
		{
			$original_posttype = get_post_type_object($post->post_type);
			$str = static::getAnother($post->dashi_original_id) ?
				 sprintf(__('Edit another version of %s', 'dashi'), $original_posttype->label) :
				 sprintf(__('Add another version of %s', 'dashi'), $original_posttype->label);
			$script.= '$("title").text("'.$str.'");';
			$script.= '$("h1.wp-heading-inline").text("'.__($str, 'dashi').'");';
			$script.= '$("a.page-title-action").hide();';
		}
		// 通常の編集画面
		else
		{
			// 差し替えのリンク
			$script.= '$(".wp-heading-inline").after("<a href=\"'.static::getAnotherLink($post->ID).'\" class=\"page-title-action\">'.__($str, 'dashi').'</a>");';

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
				'meta_key' => 'dashi_original_id',
				'meta_value' => $original_id,
			));

		static::$anothers[$original_id] = isset($another->posts[0]) ?
																		$another->posts[0] :
																		false;

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
					 'Edit another version' :
					 'Add another version';

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

			$tmps['dashi_edit_another'] = '<a href="post-new.php?post_type='.$post->post_type.'&amp;dashi_original_id='.$post->ID.'" title="'.__('Keep this post until another post will be activated', 'dashi').'">'.__($str, 'dashi').'</a>';

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
		if ( ! isset($_GET['dashi_original_id'])) return;
		$original_id = $_GET['dashi_original_id'];

		// 差し替えの存在確認
		$another = static::getAnother($original_id);

		// 差し替えが存在する場合はリダイレクト
		if ($another)
		{
			wp_redirect(admin_url('post.php?post='.$another->ID.'&action=edit'));
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
		if ( ! isset($_GET['dashi_original_id'])) return;
		$original_id = $_GET['dashi_original_id'];
		$original = get_post($original_id);

		global $post;
		$post->post_title = $original->post_title;
		$post->post_content = $original->post_content;
		$post->post_excerpt = $original->post_excerpt;

		// カスタムフィールド
		$class = P::posttype2class($post->post_type);
		foreach ($class::getCustomFieldsKeys() as $custom_field)
		{
			$post->$custom_field = $original->$custom_field;
		}

		// 表題をわかりやすく
		$str = sprintf(__('Add another version of %s', 'dashi'), get_post_type_object($post->post_type)->label);

		$script = '';
		$script.= '<script type="text/javascript">';
		$script.= 'jQuery (function($){
$("title").text("'.$str.' ‹ '.get_bloginfo('site-name').' — WordPress");
$("h1.wp-heading-inline").text("'.$str.'");
});';
		$script.= '</script>';
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
		$original_id = isset($_GET['dashi_original_id']) ?
								 $_GET['dashi_original_id'] :
								 $post->dashi_original_id;
		if ( ! $original_id) return;
		echo '<input type="hidden" name="dashi_original_id" value="'.intval($original_id).'" />';
		echo '<style type="text/css" scoped="scoped">#edit-slug-box {display: none}</style>';

		$original = get_post($original_id);

		// back link
		$cu = wp_get_current_user();
		$link = 'edit.php?post_type='.$original->post_type;
		if ($cu->caps['administrator'] || $cu->caps['editor'] )
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
			echo sprintf(__('This post is another version of <a href="%s">%s</a>. If you publish, replace post immediately.', 'dashi'), $link, $original->post_title);
		}

		// taxonomy
		// 作成時のみ
		$class = P::post2class($original);
		if ($class && isset($_GET['dashi_original_id']))
		{
			$script = '';
			$script.= '<script type="text/javascript">';
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
			$script.= '</script>';
			echo $script;
		}
	}

	/**
	 * savePost
	 *
	 * @return  void
	 */
	public static function savePost ($post_id)
	{
		// 適用するページのみ
		global $pagenow;
		$dashi_original_id = Input::post('dashi_original_id');

		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return $post_id;
		if ( ! $dashi_original_id || $pagenow != 'post.php') return $post_id;

		$another = get_post($post_id);
		$original = get_post($dashi_original_id);

		// revisionの更新などが走るので、処理分け
		if ($original->post_type != $another->post_type) return $post_id;

		// 公開日が過去であれば、そのまま差し替えて終わる（通常の編集と同じ）
		if (
			strtotime($another->post_date) <= date_i18n('U') &&
			$another->post_status == 'publish'
		)
		{
			static::replacePosts ($dashi_original_id, $another->ID);
			wp_redirect(admin_url('post.php?post='.$original->ID.'&action=edit'));
			exit;
		}

		// dashi_original_idを加える
		Save::cudPostmeta($post_id, 'dashi_original_id', intval($dashi_original_id));
	}

	/**
	 * forcePublish
	 *
	 * @return  void
	 */
	public static function forcePublish ()
	{
		// あまり頻繁に走って欲しくないので、トップページを表示した時か
		// ダッシュボードを表示した時だけ走るようにする
		global $pagenow;

		$http = is_ssl() ? 'https://' : 'http://';
		$url = untrailingslashit($http . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);

		$is_dashboard = ($pagenow == 'index.php' && is_admin());
		$is_toppage = $url == home_url();

		if ( ! $is_dashboard && ! $is_toppage) return;

		// 取り残された差し替え記事を探す
		$args = array(
			'post_type' => 'any',
			'meta_query' =>
			array(
				'key'     => 'dashi_original_id',
				'compare' => 'EXISTS',
			)
		);
		$posts = get_posts($args);
		if (empty($posts)) return;

		// 差し替え記事のうち、期日を過ぎているものを探してアップデート
		foreach ($posts as $post)
		{
			if (
				date_i18n('U') > strtotime($post->post_date) &&
				(isset($post->dashi_original_id) && $post->dashi_original_id)
			)
			{
				static::replacePosts ($post->dashi_original_id, $post->ID);
			}
		}
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
		$original_id = $post->dashi_original_id;

		if ( ! $original_id) return;
		static::replacePosts ($original_id, $post_id);
	}

	/**
	 * getAnotherLink
	 *
	 * @return  string
	 */
	public static function getAnotherLink ($post_id)
	{
		$posttype = P::class2posttype(P::postid2class($post_id));
		return 'post-new.php?post_type='.$posttype.'&amp;dashi_original_id='.$post_id;
	}

	/**
	 * replacePosts
	 *
	 * @return  void
	 */
	public static function replacePosts ($original_id, $another_id)
	{
		$original = get_post($original_id);
		$another = get_post($another_id);

		// post
		$original->post_title   = $another->post_title;
		$original->post_content = $another->post_content;
		$original->post_excerpt = $another->post_excerpt;
		$original->post_date    = $another->post_date;

		// remove action to inhibit bad loop
		remove_action('save_post', array('\\Dashi\\Core\\Posttype\\Another', 'savePost'));
		$posted_id = wp_update_post($original);

		// failed
		if ( ! $posted_id)
		{
			// send a mail?
			if (get_option('dashi_another_done_sendmail'))
			{
				$to = get_option('admin_email');
				$subject = sprintf(__('WordPress: Failed to Publish Another @ %s', 'dashi'), home_url());
				$message = sprintf(__("Failed to update content.\n\n%s\n\n%s\nDashi Plugin", 'dashi'), get_permalink($posted_id), "-- \n");
				\Dashi\Core\Mail::send($to, $subject, $message);

				// 管理者と別のユーザが記事を作っていたらそちらにも送信する
				$posted = get_post($posted_id);
				$userdata = get_userdata($posted->post_author);
				if ($userdata->data->user_email != $to)
				{
					\Dashi\Core\Mail::send($userdata->data->user_email, $subject, $message);
				}
			}
			return;
		}

		// postmeta
		$class = P::post2class($original);
		$class::rebuildCustomFields($class, $original->post_name ,$original->post_type);
		foreach ($class::getCustomFieldsKeys() as $field)
		{
			if (in_array($field, CustomFields::excludes())) continue;

			// くどいようだけど、dashiはpost_metaを全消し全入れなので、2コ以上の配列の場合は、array()
			$meta = get_post_meta($another_id, $field);
			Save::cudPostmeta($original_id, $field, count($meta) > 1 ? $meta : $meta[0]);
		}

		// taxonomy
		$taxonomies = $class::get('taxonomies');

		// delete first
		wp_delete_object_term_relationships($original_id, array_keys($taxonomies));

		// update taxonomy
		foreach ($taxonomies as $taxonomy_name => $taxonomy)
		{
			$taxes = wp_get_object_terms($another_id, $taxonomy_name);

			if ( ! $taxes) continue;
			$updates = array();
			foreach ($taxes as $tax)
			{
				$updates[] = $tax->term_id;
			}
			wp_set_object_terms($original_id, $updates, $taxonomy_name);
		}

		// delete another
		wp_delete_post($another->ID, true);

		// send a mail?
		if (get_option('dashi_another_done_sendmail'))
		{
			$to = get_option('admin_email');
			$subject = sprintf(__('WordPress: Publish Another @ %s', 'dashi'), home_url());
			$message = sprintf(__("Update content has done by reserved content.\n\n%s\n\n%s\nDashi Plugin", 'dashi'), get_permalink($posted_id), "-- \n");
			\Dashi\Core\Mail::send($to, $subject, $message);

			// 管理者と別のユーザが記事を作っていたらそちらにも送信する
			$posted = get_post($posted_id);
			$userdata = get_userdata($posted->post_author);
			if ($userdata->data->user_email != $to)
			{
				\Dashi\Core\Mail::send($userdata->data->user_email, $subject, $message);
			}
		}

		// recover hook
		add_action('save_post', array('\\Dashi\\Core\\Posttype\\Another', 'savePost'));
	}
}
