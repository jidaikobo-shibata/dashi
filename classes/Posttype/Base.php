<?php
namespace Dashi\Core\Posttype;

abstract class Base
{
	// basic values
	protected $post_type;
	protected $is_dashi = true;

	// for human
	protected $name = '';
	protected $menu_name = '';
	protected $singular_name = '';
	protected $plural = '';

	// instruction which shown at administration page
	protected $description = '';
	protected $title;

	// order in admin menu (alias: menu_position)
	protected $order = 10;

	// taxonomy
	protected $taxonomies = array();
	protected $custom_fields_taxonomies = array();

	// post type setting
	protected $public = true;
	protected $publicly_queryable = true;
	protected $show_ui = true;
	protected $query_var = true;
	protected $rewrite = true;
	protected $capability_type = 'post';
	protected $capabilities = array();
	protected $map_meta_cap = false;
	protected $hierarchical = false;
	protected $menu_position = 10;
	protected $has_archive = true;
	protected $show_in_nav_menus = true;
	protected $exclude_from_search = false;
	protected $supports = array();
	protected $enter_title_here = null;

	// custom_fields
	protected $custom_fields = array();
	protected $allow_move_meta_boxes = false;

	// visibility and controlability (alias: show_ui)
	protected $is_visible;

	// visibility on admin menu
	protected $is_hidden = false;

	// search
	protected $is_searchable = true;

	// redirection
	protected $is_redirect = false;
	protected $redirect_to = '';

	// use force ascii slug
	protected $is_use_force_ascii_slug = false;

	// sticky
	protected $is_use_sticky = true;
	protected $is_sticky_admin_only = false;

	// sitemap
	protected $sitemap_depth = 2;

	// mail
	protected $sendto;
	protected $replyto;
	protected $from_name;
	protected $subject;
	protected $re_subject;
	protected $is_auto_reply;
//	protected $is_store_contact = false;
	protected $is_send_exif = true;
	protected $auto_reply_field = 'email';

	// public form
	protected $allow_post_by_public_form = true;
	protected $public_form_post_title_field = '';
	protected $public_form_final_message = '';
	protected $public_form_post_content_field = '';
	protected $public_form_remove_exif;
	protected $public_form_allowed_mimes = array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'gif' => 'image/gif',
				'png' => 'image/png',
				'mp4|m4v' => 'video/mp4',
				'txt|asc|c|cc|h|srt' => 'text/plain',
				'csv' => 'text/csv',
				'tsv' => 'text/tab-separated-values',
				'wav' => 'audio/wav',
				'pdf' => 'application/pdf',
				'zip' => 'application/zip',
				'doc' => 'application/msword',
				'pot|pps|ppt' => 'application/vnd.ms-powerpoint',
				'xla|xls|xlt|xlw' => 'application/vnd.ms-excel',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			);

	// workflow
//	protected $is_use_workflow = false;

	/**
	 * abstract method
	 *
	 * @return  void
	 */
	public static function __init(){}

	/**
	 * after
	 *
	 * @return  void
	 */
	public static function __after(){}

	/**
	 * forge
	 *
	 * @param  string|null $post_name
	 * @param  string|null $post_type
	 * @return  void
	 */
	public static function forge($post_name, $post_type)
	{
		// forge
		$class = get_called_class();
		if ( ! Posttype::instance($class))
		{
			// construct
			Posttype::setInstance($class, new static());
			$instance = Posttype::instance($class);
			$instance::__init();

			// load slug_custom_fields
			if ( ! is_null($post_name))
			{
				static::rebuildCustomFields($class, $post_name, $post_type);
			}

			$instance::set('post_type', Posttype::class2posttype(get_called_class()));
		}
	}

	/**
	 * rebuildCustomFields
	 *
	 * @param   string $class
	 * @param   string $post_name
	 * @param   string $post_type
	 * @return  void
	 */
	public static function rebuildCustomFields($class, $post_name, $post_type)
	{
		foreach (glob(get_stylesheet_directory()."/slug_custom_fields/*.php") as $filename)
		{
			$slug_class = str_replace('.php', '', basename($filename));
			if (
				P::class2posttype($class) == $post_type &&
				$slug_class == $post_name
			)
			{
				include_once($filename);
				$slug_class_name = '\\Dashi\\Slug\\'.ucfirst($slug_class);
				if ( ! class_exists($slug_class_name)) continue;
				$slug_custom_fields = $slug_class_name::__init();

				$custom_fields = $class::get('custom_fields');
				$class::set('custom_fields', array_merge($custom_fields, $slug_custom_fields));

				// overhead...
				C::setExpectedKeys();
			}
		}
	}

	/**
	 * getter
	 *
	 * @param   string    $name
	 * @return  mixed
	 */
	public static function get($name)
	{
		$instance = Posttype::instance(get_called_class());
		if ( ! $instance) return;

		if (property_exists($instance, $name))
		{
			return $instance->$name;
		}
	}

	/**
	 * setter
	 *
	 * @param   string    $name
	 * @param   mixed     $value
	 * @return  void
	 */
	public static function set($name, $value)
	{
		$instance = Posttype::instance(get_called_class());
		if (property_exists($instance, $name))
		{
			$instance->$name = $value;
		}
	}

	/**
	 * return keys
	 *
	 * @param  bool $remove_bracket
	 * @return  Array
	 */
	public static function getCustomFieldsKeys($remove_bracket = false)
	{
		$ret = C::getExpectedKeys(get_called_class());
		if ($remove_bracket)
		{
			$ret = array_map(function($v){
				return str_replace('[0]', '', $v);
			}, $ret);
		}
		return $ret;
	}

	/**
	 * return flattened custom_fields
	 *
	 * @return  Array
	 */
	public static function getFlatCustomFields()
	{
		return C::getFlattenedCustomFields(get_called_class());
	}

	/**
	 * return key_val
	 *
	 * @return  Array
	 */
	public static function getOpts()
	{
		$post_type = Posttype::class2posttype(get_called_class());
		$items = get_posts('post_type='.$post_type.'&orderby=menu_order&order=ASC&numberposts=-1');
		if ($items)
		{
			$to_opt = function ($arrs)
			{
				$retvals = array();
				foreach ($arrs as $v)
				{
					$retvals[$v->ID] = $v->post_title;
				}
				return $retvals;
			};
			return $to_opt($items);
		}

		return array();
	}

	/**
	 * dashiではpost_metaはcheckboxと配列対策で全消し全入れなので、ここで、文脈に応じた取得もできるようにしておく
	 *
	 * @param   int     $post_id
	 * @param   string  $key
	 * @return  mixed
	 */
	public static function getPostMeta($post_id, $key)
	{
		$custom_fields = static::get('custom_fields');

		$val = null;
		if (isset($custom_fields[$key]))
		{
			$val = static::getPostMetaValue($post_id, $key, $custom_fields);
		}

		// fieldが見つかってないので、fieldsを疑う
		if ( ! $val)
		{
			foreach ($custom_fields as $field => $v)
			{
				if ( ! isset($v['fields'][$key])) continue;
				$val = static::getPostMetaValue($post_id, $key, $custom_fields['fields'][$key]);
				break;
			}
		}

		return $val;
	}

	/**
	 * checkboxとselect multipleからは配列を取得する
	 * @param   int     $post_id
	 * @param   string  $key
	 * @param   array   $custom_fields // 文脈によって異なるので、明示的に必須
	 * @return  mixed
	 */
	public static function getPostMetaValue($post_id, $key, $custom_fields)
	{
		$type = isset($custom_fields[$key]['type']) ? $custom_fields[$key]['type'] : null;
		if (
			$type == 'checkbox' ||
			($type == 'select' && isset($custom_fields[$key]['attrs']['multiple']))
		)
		{
			return get_post_meta($post_id, $key); // array
		}
		else
		{
			return get_post_meta($post_id, $key, true);
		}
	}

	/**
	 * checkboxとselect multipleからは配列を取得する
	 * @param   string  $key
	 * @return  array
	 */
	public static function getPostMetaOptions($key)
	{
		$custom_fields = static::get('custom_fields');

		$opts = false;
		if (isset($custom_fields[$key]))
		{
			$opts = isset($custom_fields[$key]['options']) ? $custom_fields[$key]['options'] : false;
		}

		// optsが見つかってないので、fieldsを疑う
		if ( ! $opts)
		{
			foreach ($custom_fields as $field => $v)
			{
				if ( ! isset($v['fields'][$key])) continue;

				$opts = isset($custom_fields['fields'][$key]['options']) ?
					$custom_fields['fields'][$key]['options'] :
					false;
			}
		}

		// ここまで見つかっていないということは、妥当でない値を尋ねているか、設定値が妥当でないので、例外をスローする
		if ( ! $opts)
		{
			throw new \Exception (sprintf(__('%s is incorrect argument or setting of custom_fields of %s is wrong.', 'dashi'), $key, get_called_class()));
		}
	}
}
