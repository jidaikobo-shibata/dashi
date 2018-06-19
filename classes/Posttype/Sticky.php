<?php
namespace Dashi\Core\Posttype;

class Sticky
{
	/**
	 * metaBox
	 *
	 * @return  void
	 */
	public static function metaBox()
	{
		global $post;
		if ( ! is_object($post) || ! isset($post->post_type)) return;
		$class = P::posttype2class($post->post_type);

		$editable = false;
		if (
			$class::get('is_sticky_admin_only') === false ||
			($class::get('is_sticky_admin_only') && current_user_can('administrator'))
		)
		{
			$editable = true;
		}
		$disable = $editable ? '' : ' disabled="disabled"';

		?>
			<input id="dashi_sticky_chk"<?php echo $disable ?> name="sticky" type="checkbox" value="sticky" <?php checked(is_sticky()); ?> /> <label for="dashi_sticky_chk" class="selectit"><?php _e('Stick this to the front page', 'dashi') ?></label><?php
	}

	/**
	 * column
	 * js.jsでhiddenを追加
	 * @return  void
	 */
	public static function column($status, $post, $date, $mode)
	{
		if ($post->post_type == 'post') return;

		$val = intval(is_sticky($post->ID));
		echo '<span id="dashi_sticky-'.$post->ID.'" style="display:none;">'.$val.'</span>';
	}
}
