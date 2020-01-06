<?php
namespace Dashi\Core\Posttype;

class Posttype
{
	protected static $_instances = array();
	protected static $_taxonomies = array();

	public static $banned = array(
		'attachment', 'attachment_id', 'author', 'author_name', 'calendar',
		'cat','category', 'category__and', 'category__in', 'category__not_in',
		'category_name','comments_per_page', 'comments_popup', 'customize_messenger_channel',
		'customized', 'cpage', 'day', 'debug', 'error', 'exact', 'feed', 'fields', 'hour',
		'link_category', 'm', 'minute', 'monthnum', 'more', 'name', 'nav_menu', 'nonce',
		'nopaging', 'offset', 'order', 'orderby', 'p', 'page', 'page_id', 'paged',
		'pagename', 'pb', 'perm', 'post', 'post__in', 'post__not_in', 'post_format',
		'post_mime_type', 'post_status', 'post_tag', 'post_type', 'posts',
		'posts_per_archive_page', 'posts_per_page', 'preview', 'robots', 's', 'search',
		'second', 'sentence', 'showposts', 'static', 'subpost', 'subpost_id', 'tag',
		'tag__and', 'tag__in', 'tag__not_in', 'tag_id', 'tag_slug__and', 'tag_slug__in',
		'taxonomy', 'tb', 'term', 'theme', 'type', 'w', 'withcomments', 'withoutcomments',
		'year',
		'date', 'dashi',
	);

	/**
	 * forge
	 *
	 * @return  void
	 */
	public static function forge()
	{
		// preload
		static::preload();

		// load posttypes
		add_action('init', array('\\Dashi\\Core\\Posttype\\Posttype', 'load'));

		// add_meta_box is must be invoke by admin_menu
		add_action(
			'admin_menu',
			array('\\Dashi\\Core\\Posttype\\CustomFields', 'addCustomFields')
		);

		// add column to admin index
		add_action(
			'manage_posts_columns',
			array('\\Dashi\\Core\\Posttype\\Index', 'addColumn')
		);
		add_action(
			'manage_posts_custom_column',
			array('\\Dashi\\Core\\Posttype\\Index', 'addCustomColumn'),
			10,
			2
		);

		// 絞り込みを追加
		add_action(
			'restrict_manage_posts',
			array('\\Dashi\\Core\\Posttype\\Index', 'restrictManagePosts')
		);
		add_action(
			'pre_get_posts',
			array('\\Dashi\\Core\\Posttype\\Index', 'preGetPosts')
		);

		// redirection - search result and direct access
		add_action('template_redirect', array('\\Dashi\\Core\\Posttype\\Redirect', 'redirect'));

		// force temaplate change
		if (get_option('dashi_enrich_search_result_page') && Input::get('s', false) !== false)
		{
			add_action('template_include', function ()
			{
				$template = DASHI_DIR.'/templates/search.php';
				return $template;
			});
		}

		// save hook
		add_action('save_post', array('\\Dashi\\Core\\Posttype\\Save', 'updateCustomFields'));
		add_action('edited_term', array('\\Dashi\\Core\\Posttype\\SaveCategories', 'save'));

		// non multi-byte slug
		add_filter('wp_unique_post_slug', array('\\Dashi\\Core\\Posttype\\Save', 'autoPostSlug'), 10, 4);

		// add post_type description
		add_action('admin_notices', array('\\Dashi\\Core\\Posttype\\Hook', 'showDescription'));

		// show errors
		if (strpos(Input::server('SCRIPT_NAME'), 'post.php') !== false)
		{
			add_action('admin_notices', array('\\Dashi\\Core\\Posttype\\Save', 'showMessages'));
		}

		// help
		add_action('current_screen', array('\\Dashi\\Core\\Posttype\\Help', 'hook'));

		// search hook
		if ( ! is_admin())
		{
			add_action('posts_request', array('\\Dashi\\Core\\Posttype\\Search', 'postsRequest'), 1, 2);
			add_action('posts_join', array('\\Dashi\\Core\\Posttype\\Search', 'searchJoin'));
			add_filter('posts_search', array('\\Dashi\\Core\\Posttype\\Search', 'searchFields'), 1, 2);
			add_filter('posts_distinct', array('\\Dashi\\Core\\Posttype\\Search', 'searchDistinct'), 1, 2);
			add_action('posts_where', array('\\Dashi\\Core\\Posttype\\Search', 'searchExclude'), 1, 2);
			add_action('posts_orderby', array('\\Dashi\\Core\\Posttype\\Search', 'searchOrderby'), 1, 2);
		}

		// check
		// \Dashi\Core\Posttype\Search::addPages();

		// activate hook
		register_activation_hook(
			DASHI_FILE,
			function ()
			{
				wp_schedule_event(time(), "daily", "dashi_cron_hook");
			}
		);
		add_action('dashi_cron_hook', array('\\Dashi\\Core\\Posttype\\Search', 'addPages'));

		register_deactivation_hook(
			DASHI_FILE,
			function ()
			{
				wp_clear_scheduled_hook('dashi_cron_hook');
			});

		// admin assets
		add_action(
			'wp_enqueue_scripts',
			function ()
			{
				wp_enqueue_style(
					'dashi_datetimepicker_css',
					plugins_url('assets/css/jquery-ui-timepicker-addon.css', DASHI_FILE)
				);

				wp_enqueue_style(
					'dashi_css',
					plugins_url('assets/css/css.css', DASHI_FILE)
				);

				if (get_option('dashi_google_map_api_key'))
				{
					wp_enqueue_script(
						'dashi_google_map_api_key_js',
						'https://maps.google.com/maps/api/js?key='.
							esc_html(get_option('dashi_google_map_api_key')),
						array(),
						null // API key のために ver を含めない
					);
				}

				wp_enqueue_script(
					'dashi_js_timepicker',
					plugins_url('assets/js/jquery-ui-timepicker-addon.js', DASHI_FILE),
					array('jquery-ui-datepicker'),
					'1.1',
					true
				);

				wp_enqueue_script(
					'dashi_js',
					plugins_url('assets/js/js.js', DASHI_FILE),
					array('jquery-ui-datepicker'),
					'1.1',
					true
				);
			}
		);

		// admin assets
		add_action(
			'admin_enqueue_scripts',
			function ()
			{
				wp_enqueue_script(
					'dashi_js_uploader',
					plugins_url('assets/js/uploader.js', DASHI_FILE)
				);

				wp_enqueue_script(
					'dashi_js_timepicker',
					plugins_url('assets/js/jquery-ui-timepicker-addon.js', DASHI_FILE),
					array('jquery-ui-datepicker'),
					'1.1',
					true
				);

				wp_enqueue_script(
					'dashi_js',
					plugins_url('assets/js/js.js', DASHI_FILE),
					array('jquery-ui-datepicker'),
					'1.1',
					true
				);

				// redundancy...
				wp_enqueue_style(
					'dashi_datetimepicker_css',
					plugins_url('assets/css/jquery-ui-timepicker-addon.css', DASHI_FILE)
				);

				wp_enqueue_style(
					'dashi_css',
					plugins_url('assets/css/css.css', DASHI_FILE)
				);

				if (get_option('dashi_google_map_api_key'))
				{
					wp_enqueue_script(
						'dashi_google_map_api_key_js',
						'https://maps.google.com/maps/api/js?key='.
						esc_html(get_option('dashi_google_map_api_key'))
					);
				}

				wp_enqueue_style('thickbox');
				wp_enqueue_script('media-upload');
				wp_enqueue_script('thickbox');
			}
		);

		// remove_bar_menus()
		add_action(
			'admin_bar_menu',
			function ($wp_admin_bar)
			{
				$wp_admin_bar->remove_menu('new-pagepart');
				$wp_admin_bar->remove_menu('new-editablehelp');
			},
			201);

		// dashi_ignore_checked_ontop()
		// Fix order of category check box
		if (get_option('dashi_ignore_checked_ontop'))
		{
			add_filter(
				'wp_terms_checklist_args',
				function ($args)
				{
					$args['checked_ontop'] = false;
					return $args;
				}
			);
		}

		// place holder of title
		// thx http://www.warna.info/archives/2929/
		add_filter(
			'enter_title_here',
			function ($enter_title_here, $post)
			{
				$post_type = get_post_type_object($post->post_type);
				if (
					isset($post_type->labels->enter_title_here) &&
					$post_type->labels->enter_title_here &&
					is_string($post_type->labels->enter_title_here))
				{
					$enter_title_here = esc_html($post_type->labels->enter_title_here);
				}
				return $enter_title_here;
			},
			10,
			2
		);

		// add Shortcode - is_user_logged_in area
		add_shortcode(
			'dashi_sitemap',
			array('\\Dashi\\Core\\Posttype\\Sitemap', 'shortcode')
		);

		// add Shortcode - public form
		add_shortcode(
			'dashi_public_form',
			array('\\Dashi\\Core\\Posttype\\PublicForm', 'shortcode')
		);

		add_action(
			'wp_ajax_custom_referencer',
			array('\\Dashi\\Core\\Posttype\\CustomFields', 'referencer_ajax_handler')
		);
	}

	/**
	 * preload
	 *
	 * @return  void
	 */
	public static function preload()
	{
		// load posttypes
		$posttypes_files = self::loadPostTypeFiles();
		$posttypes = self::definePostTypes($posttypes_files);

		// スラッグでカスタムフィールドを上書きするために取得
		$post_id = filter_input(INPUT_GET, 'post');
		$post_id = filter_input(INPUT_POST, 'post_ID') ?: $post_id;
		$post_type = filter_input(INPUT_GET, 'post_type');
		$post = ( ! $post_type && $post_id) ? get_post($post_id) : null ;
		$post_name = is_null($post) ? null : $post->post_name;

		// another
		$dashi_original_id = filter_input(INPUT_GET, 'dashi_original_id') ?: $post_id;
		$dashi_original_id = filter_input(INPUT_POST, 'dashi_original_id') ?: $dashi_original_id;
		$dashi_original_id = get_post_meta($post_id, 'dashi_original_id', TRUE) ?: $dashi_original_id;
		if ($dashi_original_id)
		{
			$original_post = get_post($dashi_original_id);
			$post_name = $original_post->post_name;
			$post_type = $original_post->post_type;
		}

		// forge
		foreach ($posttypes as $posttype)
		{
			$posttype::forge($post_name, $post_type);

			// 複製可能なカスタムフィールド（meta_box）を追加
			self::duplicateMetaBox($posttype);
		}

		// すべてのcustom_fieldsを用意
		CustomFields::setExpectedKeys();

		// custom_fields_flatを用意
		CustomFields::setFlattenedCustomFields();

		// 郵便番号確認
		foreach ($posttypes as $posttype)
		{
			\Dashi\Core\Zip::addHeader($posttype::getFlatCustomFields());
		}
	}

	/**
	 * loadPostTypeFiles
	 *
	 * @return  array
	 */
	private static function loadPostTypeFiles()
	{
		$posttypes_files = array();
		foreach (glob(get_stylesheet_directory()."/posttype/*.php") as $filename)
		{
			include($filename);
			$posttypes_files[] = $filename;
		}

		// 子テーマを使っているなら、親テーマを読む
		$pt_check = array_map('basename', $posttypes_files);
		if (get_stylesheet_directory() != get_template_directory())
		{
			foreach (glob(get_template_directory()."/posttype/*.php") as $filename)
			{
				if (in_array(basename($filename), $pt_check)) continue;
				include($filename);
				$posttypes_files[] = $filename;
			}
		}
		return $posttypes_files;
	}

	/**
	 * definePostTypes
	 *
	 * @return  array
	 */
	private static function definePostTypes($posttypes_files)
	{
		$posttypes = array();

		foreach ($posttypes_files as $filename)
		{
			$class = '\\Dashi\\Posttype\\'.ucfirst(substr(basename($filename), 0, -4));
			if (is_callable($class, '__init'))
			{
				$posttypes[] = $class;
			}
		}

		// default post type - allow override
		foreach (glob(DASHI_DIR."/posttype/*.php") as $filename)
		{
			$post_type = substr(basename($filename), 0, -4);
			$class = '\\Dashi\\Posttype\\'.ucfirst($post_type);
			if (in_array($class, $posttypes)) continue;
			if ($post_type == 'pagepart' && ! get_option('dashi_activate_pagepart')) continue;
			include($filename);
			$posttypes[] = $class;
		}

		// 出汁由来でないポストタイプの取得
		global $wpdb;
		$sql = 'SELECT `post_type` FROM '.$wpdb->posts.' GROUP BY `post_type`;';
		foreach ($wpdb->get_results($sql) as $v)
		{
			if (in_array($v->post_type, array('revision', 'attachment'))) continue;
			if (strpos($v->post_type, '-') !== false) continue;
			$class = '\\Dashi\\Posttype\\'.ucfirst($v->post_type);
			if (in_array($class, $posttypes)) continue;
			$posttypes[] = $class;
			static::virtual($v->post_type);
		}

		return $posttypes;
	}

	/**
	 * duplicateMetaBox
	 *
	 * @return  void
	 */
	private static function duplicateMetaBox($posttype)
	{
		// duplicate（入力欄複製）属性のあるカスタムフィールドがある場合、meta_boxごと増やす
		$custom_fields = $posttype::get('custom_fields');
		if ( ! $custom_fields) return;

		// 編集画面以外ではmetaboxは無視していい
		global $pagenow;
		if ($pagenow != 'post.php') return;

		// 表示順番を維持するためにカウント
		$mods = array();
		foreach ($custom_fields as $key => $val)
		{
			$labels = array();

			// duplicate属性がある？
			$adds = array();
			if (isset($val['duplicate']) && $val['duplicate'] === true)
			{
				// duplicateのあるfieldを改造するため、破棄
				unset($val['duplicate']);
				$val['duplicated'] = TRUE;
//				unset($custom_fields[$key]);
				$arr_name = isset($val['label']) ? $val['label'] : $key;

				// 必要数を確保するために、現在DBに保管されている数を数える
				$allnum = self::countMetaboxAmount($key, $val);

				// labelを保存しておく
				if (isset($val['fields']) && is_array($val['fields']))
				{
					foreach ($val['fields'] as $kk => $fs)
					{
						$labels[$kk] = isset($fs['label']) ? $fs['label'] : $kk;
					}
				}

				// 必要数ぶん増やす
				$adds = self::addBoxes($key, $val, $allnum, $labels, $arr_name, $adds);

				// meta_boxを増やすため、$keyを記憶
				$mods[$key] = $adds;
			}
		}

		// array_splice()だとkeyが振り直されてしまうので、泥臭い挿入
		if ($mods)
		{
			foreach ($mods as $k => $v)
			{
				$n = 0;
				foreach ($custom_fields as $kk => $vv)
				{
					if ($k == $kk)
					{
						unset($custom_fields[$kk]);
						// meta_boxを増やすため、いったんここまでを保存
						$prevs = array_slice($custom_fields, 0, $n);
						$lasts = array_slice($custom_fields, $n, count($custom_fields));

						// はさむ
						$custom_fields = $prevs + $v + $lasts;
					}
					$n++;
				}
			}

			$posttype::set('custom_fields', $custom_fields);
		}
	}

	/**
	 * countMetaboxAmount
	 *
	 * @param  string $key
	 * @param  array $val
	 * @return  integer
	 */
	private static function countMetaboxAmount($key, $val)
	{
		$allnum = 0;
		if (
			isset($_GET['dashi_copy_original_id']) &&
			is_numeric($_GET['dashi_copy_original_id'])
		)
		{
			$post_id = filter_input(INPUT_GET, 'dashi_copy_original_id', FILTER_SANITIZE_NUMBER_INT);
		}
		else
		{
			$post_id = filter_input(INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT);
		}

		// fieldsの場合、入力されている最大値を用いる。
		if ($post_id)
		{
			$items = array();
			if (isset($val['fields']) && is_array($val['fields']))
			{
				foreach ($val['fields'] as $kk => $fs)
				{
					$items[] = count(get_post_meta($post_id, $kk));
				}
				$allnum = max($items);
			}
			else
			{
				// fieldsでない場合は普通にカウント
				$allnum = count(get_post_meta($post_id, $key));
			}
		}
		return $allnum;
	}

	/**
	 * addBoxes
	 *
	 * @param  string $val
	 * @param  array $val
	 * @param  integer $allnum
	 * @param  array $labels
	 * @param  string $arr_name
	 * @param  array $adds
	 * @return  array
	 */
	private static function addBoxes($key, $val, $allnum, $labels, $arr_name, $adds)
	{
		for ($i = 0;$i <= $allnum;$i++)
		{
			$numstr = $i + 1;
			// fieldsの各要素を複製する
			if (isset($val['fields']) && is_array($val['fields']))
			{
				$fields = array();
				foreach ($val['fields'] as $kk => $fs)
				{
					// もとから配列の形をしているようなものは破棄
					$tmpkey = preg_replace("/\[\d*?\]/", '', $kk);
					$fs['label'] = $labels[$tmpkey].' ('.$numstr.')';
					$fs['label_origi'] = $labels[$tmpkey];

					// ここで、配列の形に変更
					$fields[$tmpkey.'['.$i.']'] = $fs;
				}
				$val['fields'] = $fields;
			}
			$val['label'] = $arr_name.' ('.$numstr.')';
			$val['label_origi'] = $arr_name;

			$val['original_key'] = $key;
			$adds[$key.'['.$i.']'] = $val; // keyはfieldsがない場合はそのままnameになる
		}
		return $adds;
	}

	/**
	 * virtual
	 *
	 * @return  void
	 */
	public static function virtual($posttype)
	{
		$posttype = ucfirst($posttype);
		eval("namespace Dashi\\Posttype;class {$posttype} extends \\Dashi\\Core\\Posttype\\Base {public static function __init (){ static::set('is_dashi', false); } }");
	}

	/**
	 * load
	 *
	 * @return  void
	 */
	public static function load()
	{
		// load posttypes first
		foreach (static::instances() as $posttype)
		{
			if (
				static::class2posttype($posttype) == 'pagepart' &&
				! get_option('dashi_activate_pagepart')
			) continue;

			// add post type
			static::addCustomPostType($posttype);

			// add hidden fields
			// hidden field must be add firster than admin_menu hook
			CustomFields::addHiddenFields($posttype);
		}
	}

	/**
	 * class2posttype
	 *
	 * @param   string $str
	 * @return  string
	 */
	public static function class2posttype($str)
	{
		return strtolower(substr($str, strrpos($str, '\\') + 1));
	}

	/**
	 * posttype2class
	 *
	 * @param   string $str
	 * @return  string
	 */
	public static function posttype2class($str)
	{
		$class = '\\Dashi\\Posttype\\'.ucfirst($str);
		return class_exists($class) ? $class : false;
	}

	/**
	 * post2class
	 *
	 * @param   obj $post
	 * @return  string
	 */
	public static function post2class($post)
	{
		$post_type = get_post_type($post);
		return static::posttype2class($post_type);
	}

	/**
	 * postid2class
	 *
	 * @param   int $post_id
	 * @return  string
	 */
	public static function postid2class($post_id)
	{
		return static::post2class(get_post($post_id));
	}

	/**
	 * set instance
	 *
	 * @param   string    $name
	 * @param   object    $obj
	 * @return  void
	 */
	public static function setInstance($posttype, $obj)
	{
		static::$_instances[$posttype] = $obj;

		// sort by order
		foreach (static::instances() as $posttype)
		{
			$sort[$posttype] = $posttype::get('order');
		}
		array_multisort($sort, SORT_ASC, static::$_instances);
	}

	/**
	 * get single instance by class name
	 *
	 * @param   string    $name
	 * @return  instance
	 */
	public static function instance ($name)
	{
		return array_key_exists($name, static::$_instances) ? static::$_instances[$name] : FALSE;
	}

	/**
	 * get all instances name
	 *
	 * @return  array
	 */
	public static function instances ()
	{
		return array_keys(static::$_instances);
	}

	/**
	 * get all taxonomies
	 *
	 * @return  array
	 */
	public static function taxonomies ()
	{
		return static::$_taxonomies;
	}

	/**
	 * get instance name by post_type
	 *
	 * @param   string    $name
	 * @return  string
	 */
	public static function getInstance ($name)
	{
		$str = 'Dashi\\Posttype\\'.ucfirst($name);
		return in_array($str, static::instances()) ? '\\'.$str : FALSE;
	}

	/**
	 * add custom post type
	 *
	 * @return  void
	 */
	private static function addCustomPostType ($posttype)
	{
		if ( ! $posttype::get('is_dashi')) return;

		if (in_array($posttype, static::$banned))
		{
			throw new \Exception (sprintf(__('%s is cannot use as posttype name', 'dashi'), $name));
		}

		$labels = array(
			'name'              => $posttype::get('name'),
			'singular_name'     => $posttype::get('singular_name') ?: $posttype::get('name'),
			'menu_name'         => $posttype::get('menu_name') ?: $posttype::get('name'),
			'add_new'           => sprintf(__('add %s', 'dashi'), $posttype::get('name')),
			'add_new_item'      => sprintf(__('add %s', 'dashi'), $posttype::get('name')),
			'edit_item'         => sprintf(__('edit %s', 'dashi'), $posttype::get('name')),
			'new_item'          => sprintf(__('new %s', 'dashi'), $posttype::get('name')),
			'view_item'         => sprintf(__('view %s', 'dashi'), $posttype::get('name')),
			'parent_item_colon' => '',
		);

		// post_title の place holder
		if ($posttype::get('enter_title_here'))
		{
			$labels['enter_title_here'] = $posttype::get('enter_title_here');
		}

		// is visible
		$show_ui =  ! is_null($posttype::get('is_visible')) ?
						 $posttype::get('is_visible') :
						 $posttype::get('show_ui');

		// adhoc! cannot filter at admin index
		global $pagenow;
//		$publicly_queryable = $posttype::get('publicly_queryable');
		$publicly_queryable = $pagenow == 'edit.php' && is_admin() ? false : true;

		// add
		$args = array(
			'labels'              => $labels,
			'public'              => $posttype::get('public'),
			'publicly_queryable'  => $publicly_queryable,
			'show_ui'             => $show_ui,
			'show_in_nav_menus'   => $posttype::get('show_in_nav_menus'),
			'query_var'           => $posttype::get('query_var'),
			'rewrite'             => $posttype::get('rewrite'),
			'hierarchical'        => $posttype::get('hierarchical'),
			'menu_position'       => $posttype::get('order') ?: $posttype::get('menu_position'),
			'has_archive'         => $posttype::get('has_archive'),
			'supports'            => $posttype::get('supports'),
			'exclude_from_search' => $posttype::get('exclude_from_search'),
		);

		// capabilities
		$args = self::registerCapabilities($posttype, $args);

		register_post_type($posttype::get('post_type'), $args);

		// flush_rules
		global $wp_rewrite;
		$wp_rewrite->flush_rules();

		// taxonomies
		self::registerTaxonomy($posttype);

		// run __after
		$posttype::__after();
	}

	/**
	 * registerCapabilities
	 *
	 * @param  Object $posttype
	 * @param  Array $args
	 * @return  Array
	 */
	private static function registerCapabilities($posttype, $args)
	{
		if ($posttype::get('capability_type'))
		{
			$args['capability_type'] = $posttype::get('capability_type');
		}
		if ($posttype::get('capabilities'))
		{
			$args['capabilities'] = $posttype::get('capabilities');
		}
		if ($posttype::get('map_meta_cap'))
		{
			$args['map_meta_cap'] = $posttype::get('map_meta_cap');
		}
		return $args;
	}

	/**
	 * registerTaxonomy
	 *
	 * @param  Object $posttype
	 * @return  void
	 */
	private static function registerTaxonomy ($posttype)
	{
		$custom_fields_taxonomies = $posttype::get('custom_fields_taxonomies');
		foreach ($posttype::get('taxonomies') as $name => $val)
		{
			if (in_array($name, static::$banned))
			{
				throw new \Exception (sprintf(__('%s is cannot use as taxonomy name', 'dashi'), $name));
			}
			register_taxonomy($name, $posttype::get('post_type'), $val);

			if ( ! array_key_exists($name, $custom_fields_taxonomies)) continue;
			// add custom field to categories or taxonomies
			add_action(
				$name.'_edit_form_fields',
				array('\\Dashi\\Core\\Posttype\\CustomFieldsCategories', 'addCustomFields')
			);

			add_action(
				'edited_term',
				array('\\Dashi\\Core\\Posttype\\CustomFieldsCategories', 'saveHook')
			);

			static::$_taxonomies[$name] = static::class2posttype($posttype);
		}
	}
}
