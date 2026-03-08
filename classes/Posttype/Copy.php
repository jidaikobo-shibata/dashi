<?php
namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Copy
{
	private const COPY_NONCE_ACTION = 'dashi_copy_post';
	private const COPY_NONCE_NAME = '_dashi_copy_nonce';

	/**
	 * forge
	 *
	 * @return  void
	 */
	public static function forge ()
	{
		if ( ! is_admin()) return;

		$posttypes = array('post', 'page');
		foreach (P::instances() as $class)
		{
			$posttypes[] = P::class2posttype($class);
		}

		// すべてのポストタイプ一覧の各行に「コピー」を追加する
		foreach ($posttypes as $posttype)
		{
			add_filter(
				$posttype.'_row_actions',
				array('\\Dashi\\Core\\Posttype\\Copy', 'addEditCopy'),
				10,
				2
			);
		}

		// 複製編集画面を作る
		add_filter(
			'admin_head-post-new.php', // hook_suffix
			array('\\Dashi\\Core\\Posttype\\Copy', 'editCopyVersion')
		);
		add_action(
			'edit_form_after_title',
			array('\\Dashi\\Core\\Posttype\\Copy', 'editFormAfterTitle')
		);

		// 編集画面をいろいろするJavaScript
		add_action(
			'admin_head-post.php',
			array('\\Dashi\\Core\\Posttype\\Copy', 'adminHeadPostPhp')
		);

		add_action(
			'save_post',
			array('\\Dashi\\Core\\Posttype\\Copy', 'savePost')
		);
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

		// コピーのリンク
		$copy_url = wp_nonce_url(
			add_query_arg(
				array(
					'post_type' => (string) $post->post_type,
					'dashi_copy_original_id' => (int) $post->ID,
				),
				admin_url('post-new.php')
			),
			self::COPY_NONCE_ACTION,
			self::COPY_NONCE_NAME
		);
		$link_html = sprintf(
			'<a href="%s" class="page-title-action">%s</a>',
			esc_url($copy_url),
			esc_html__('Copy', 'dashi')
		);
		?>
<script type="text/javascript">
jQuery(function($){
	$(".wp-heading-inline").after(<?php echo wp_json_encode($link_html); ?>);
});
</script>
		<?php
	}

	/**
	 * addEditCopy
	 *
	 * @return  void
	 */
	public static function addEditCopy ($actions, $post)
	{
		if (current_user_can('edit_published_posts'))
		{
			$tmps = array();

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

			$copy_url = wp_nonce_url(
				add_query_arg(
					array(
						'post_type' => (string) $post->post_type,
						'dashi_copy_original_id' => (int) $post->ID,
					),
					admin_url('post-new.php')
				),
				self::COPY_NONCE_ACTION,
				self::COPY_NONCE_NAME
			);
			$tmps['dashi_edit_Copy'] = sprintf(
				'<a href="%1$s" title="%2$s">%3$s</a>',
				esc_url($copy_url),
				esc_attr__('Create Copy', 'dashi'),
				esc_html__('Copy', 'dashi')
			);

			$actions = $tmps + $actions;
		}

		return $actions;
	}

	/**
	 * modCopyTitle
	 *
	 * @param  string $post_title
	 * @return void
	 */
	public static function modCopyTitle ($post_title)
	{
		global $wpdb;
		$copy_title_base = sprintf(
			/* translators: 1: original post title, 2: copy number */
			__('%1$s\'s Copy%2$s', 'dashi'),
			$post_title,
			''
		);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- 既存タイトル重複数の算出に使用。
			$num = (int) $wpdb->get_var(
				$wpdb->prepare(
				'SELECT COUNT(ID) FROM '.$wpdb->posts.' WHERE post_title LIKE %s AND post_status = %s',
				'%' . $wpdb->esc_like($copy_title_base) . '%',
				'publish'
			)
		);

		return sprintf(
			/* translators: 1: original post title, 2: copy number */
			__('%1$s\'s Copy%2$s', 'dashi'),
			$post_title,
			$num + 1
		);
	}

	/**
	 * editCopyVersion
	 *
	 * @return  void
	 */
	public static function editCopyVersion ()
	{
		// タクソノミーはjavascriptなので、editFormAfterTitleで

		// オリジナルを取得
		if ( ! isset($_GET['dashi_copy_original_id'])) return;
		if ( ! isset($_GET[self::COPY_NONCE_NAME])) return;
		$nonce = sanitize_text_field(wp_unslash($_GET[self::COPY_NONCE_NAME]));
		if (!wp_verify_nonce($nonce, self::COPY_NONCE_ACTION)) return;

		$original_id = absint(wp_unslash($_GET['dashi_copy_original_id']));
		if (!$original_id) return;
		$original = get_post($original_id);
		if (!$original) return;

		global $post;
		$post->post_title = self::modCopyTitle($original->post_title);
		$post->post_content = $original->post_content;
		$post->post_excerpt = $original->post_excerpt;
		// $post->post_date = $original->post_date;

		// カスタムフィールド
		$class = P::posttype2class($post->post_type);
		foreach ($class::getCustomFieldsKeys() as $custom_field)
		{
			$post->$custom_field = $original->$custom_field;
		}

		// 表題をわかりやすく
		$str = sprintf(
			/* translators: %s: post type label */
			__('Create Copy of %s', 'dashi'),
			get_post_type_object($post->post_type)->label
		);
		$page_title = $str . ' ‹ ' . get_bloginfo('site-name') . ' — WordPress';
		?>
<script type="text/javascript">
jQuery(function($){
	$("title").text(<?php echo wp_json_encode($page_title); ?>);
	$("h1.wp-heading-inline").text(<?php echo wp_json_encode($str); ?>);
});
</script>
		<?php
	}

	/**
	 * editFormAfterTitle
	 * @return void
	 */
	static public function editFormAfterTitle()
	{
		global $post;
		$original_id = isset($_GET['dashi_copy_original_id']) ?
								 absint(wp_unslash($_GET['dashi_copy_original_id'])) :
								 0;
		if ( ! $original_id) return;
		if ( ! isset($_GET[self::COPY_NONCE_NAME])) return;
		$nonce = sanitize_text_field(wp_unslash($_GET[self::COPY_NONCE_NAME]));
		if (!wp_verify_nonce($nonce, self::COPY_NONCE_ACTION)) return;
		echo '<input type="hidden" name="dashi_copy_original_id" value="'.intval($original_id).'" />';
		wp_nonce_field(self::COPY_NONCE_ACTION, self::COPY_NONCE_NAME);

		$original = get_post($original_id);
		if (!$original) return;

		// taxonomy
		// 作成時のみ
		$class = P::post2class($original);
		if ($class && isset($_GET['dashi_copy_original_id']))
		{
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
				?>
<script type="text/javascript">
jQuery(function($){
	var checkstr = <?php echo wp_json_encode($checks); ?>;
	$("#<?php echo esc_js($ul_id); ?>").find(":input").each(function(){
		if (checkstr.indexOf(parseInt($(this).val(), 10)) === -1) {
			$(this).prop("checked", false);
		} else {
			$(this).prop("checked", true);
		}
	});
});
</script>
				<?php
			}
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
		$dashi_original_id = Input::post('dashi_copy_original_id');
		$nonce = Input::post(self::COPY_NONCE_NAME);

        if (is_null($dashi_original_id)) return $post_id;
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return $post_id;
		if ( ! $dashi_original_id || $pagenow != 'post.php') return $post_id;
		if (is_null($nonce) || !wp_verify_nonce((string) $nonce, self::COPY_NONCE_ACTION)) return $post_id;

		if (!current_user_can('edit_post', $post_id)) {
			return $post_id;
		}

		$copy = get_post($post_id);
		$original = get_post($dashi_original_id);

		// revisionの更新などが走るので、処理分け
		if ($original->post_type != $copy->post_type) return $post_id;

		// 公開日を差し替える
		// $copy->post_date = $original->post_date;
		// remove_action('save_post', array('\\Dashi\\Core\\Posttype\\Copy', 'savePost'));
		// wp_update_post($copy);
		// add_action('save_post', array('\\Dashi\\Core\\Posttype\\Copy', 'savePost'));

		return $post_id;
	}
}
