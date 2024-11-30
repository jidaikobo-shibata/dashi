<?php
namespace Dashi\Core\Posttype;

class Copy
{
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

		$script = '';
		$script.= '<script type="text/javascript">';
		$script.= 'jQuery (function($){';

		// コピーのリンク
			$script.= '$(".wp-heading-inline").after("<a href=\"post-new.php?post_type='.$post->post_type.'&amp;dashi_copy_original_id='.$post->ID.'\" class=\"page-title-action\">'.__('Copy', 'dashi').'</a>");';

		$script.= '});';
		$script.= '</script>';
		echo $script;
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

			$tmps['dashi_edit_Copy'] = '<a href="post-new.php?post_type='.$post->post_type.'&amp;dashi_copy_original_id='.$post->ID.'" title="'.__('Create Copy', 'dashi').'">'.__('Copy', 'dashi').'</a>';

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
		$sql = 'SELECT count(ID) as num FROM '.$wpdb->posts.' WHERE `post_title` LIKE %s AND `post_status` = "publish";';
		$sql = $wpdb->prepare($sql, '%'.sprintf(__('%s\'s Copy%s', 'dashi'), $post_title, '').'%');
		$num = $wpdb->get_var($sql);
		$num = intval($num);
		$num++;
		return sprintf(__('%s\'s Copy%s', 'dashi'), $post_title, $num);
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
		$original_id = $_GET['dashi_copy_original_id'];
		$original = get_post($original_id);

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
		$str = sprintf(__('Create Copy of %s', 'dashi'), get_post_type_object($post->post_type)->label);

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
	 * @return void
	 */
	static public function editFormAfterTitle()
	{
		global $post;
		$original_id = isset($_GET['dashi_copy_original_id']) ?
								 $_GET['dashi_copy_original_id'] :
								 0;
		if ( ! $original_id) return;
		echo '<input type="hidden" name="dashi_copy_original_id" value="'.intval($original_id).'" />';

		$original = get_post($original_id);

		// taxonomy
		// 作成時のみ
		$class = P::post2class($original);
		if ($class && isset($_GET['dashi_copy_original_id']))
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
		$dashi_original_id = Input::post('dashi_copy_original_id');

		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return $post_id;
		if ( ! $dashi_original_id || $pagenow != 'post.php') return $post_id;

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
