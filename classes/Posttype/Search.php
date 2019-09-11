<?php
namespace Dashi\Core\Posttype;

class Search
{
	private static $null_byte_deleted_s = '';

	/**
	 * delete null byte form string
	 *
	 * @return string
	 */
	private static function nullBytelessS()
	{
		if (static::$null_byte_deleted_s) return static::$null_byte_deleted_s;

		global $wp_query;
		if (isset($wp_query->query['s']))
		{
			static::$null_byte_deleted_s = str_replace('\0', '', $wp_query->query['s']);
		}
		return static::$null_byte_deleted_s;
	}

	/**
	 * distinct
	 *
	 * @return string
	 */
	public static function postsRequest($sql, $wp_query)
	{
		global $wp_query;

		// delete null-byte string from preset input field text
		if (isset($wp_query->query_vars['s']))
		{
			$wp_query->query_vars['s'] = preg_replace('/\0/', '', $wp_query->query_vars['s']);
		}

		$nullBytelessS = static::nullBytelessS();
		if ($nullBytelessS)
		{
			return str_replace('\0', '', $sql);
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
		global $wpdb;

		// query_string exists
		if (isset($wp_query->query['s'])) $wp_query->is_search = true;
		if ( ! static::nullBytelessS()) return;
		if ( ! $wp_query->is_search) return;

		// modify sql
		return $sql.'DISTINCT';
	}

	/**
	 * join
	 *
	 * @return string
	 */
	public static function searchJoin($join)
	{
		global $wp_query, $wpdb;

		if (is_admin()) return $join;

		// query_string exists
		if (isset($wp_query->query['s'])) $wp_query->is_search = true;
		if ( ! static::nullBytelessS()) return $join;
		if ( ! isset($wp_query->is_search) || ! $wp_query->is_search) return $join;

		$join .= " LEFT OUTER JOIN {$wpdb->postmeta} ON ({$wpdb->posts}.ID = {$wpdb->postmeta}.post_id)";
		return $join;
	}

	/**
	 * add fields
	 *
	 * @return string
	 */
	public static function searchFields($search, $wp_query)
	{
		global $wpdb;

		// query_string exists
		if (isset($wp_query->query['s'])) $wp_query->is_search = true;
		if ( ! static::nullBytelessS()) return $search;
		if ( ! $wp_query->is_search) return $search;

		// modify sql
		$sql = $wpdb->prepare(
			") OR ({$wpdb->postmeta}.meta_key = 'dashi_search' AND {$wpdb->postmeta}.meta_value LIKE %s",
			'%'.static::nullBytelessS().'%');
		$search = str_replace(')))', $sql.')))', $search);

		return $search;
	}

	/**
	 * exclude
	 *
	 * @return string
	 */
	public static function searchExclude($search, $wp_query)
	{
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
	}

	/**
	 * orderby
	 *
	 * @return string
	 */
	public static function searchOrderby($search, $wp_query)
	{
		// query_string exists
		if (isset($wp_query->query['s'])) $wp_query->is_search = true;
		if ( ! static::nullBytelessS()) return $search;
		if ( ! $wp_query->is_search) return $search;
		// do nothing yet

		return $search;
	}

	/*
	 * generate search str
	 * include alt text
	 * @param strings $str
	 * @return strings
	 */
	public static function generateSearchStr($str)
	{
		$str = preg_replace('/\<img [^\>]*?alt[^\>]*?=[^\>]*?"([^\>]*?)".*?\>/si', '$1', $str);
		$str = preg_replace('/\<input [^\>]*?value[^\>]*?=[^\>]*?"([^\>]*?)".*?\>/si', '$1', $str);
		$str = preg_replace('/\<script.+?\/script\>/si', '', $str);
		$str = preg_replace('/\<style.+?\/style\>/si', '', $str);
		$str = strip_tags($str);
		$str = str_replace(array("\n", "\r", "　"), ' ', $str);
		$str = preg_replace('/ +/is', ' ', $str);
		$str = esc_html($str);
		$str = trim($str);
		return $str;
	}

	/*
	 * store unsearchable pages
	 */
	public static function addPages()
	{
		 if ( ! is_admin()) return;

		// fetch html
		$res = wp_remote_get(home_url(), array('timeout' => 10, 'sslverify' => false,));

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
		}

		$target_urls = array_merge($ms[1], $specified_urls);

		// for eliminate normal page
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

		// eliminate assets
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
			// fetch each html
			$res = wp_remote_get($url, array('timeout' => 10, 'sslverify' => false,));
			if (isset($res->errors) && $res->errors) continue;
			$html = $res['body'];

			// non target pages
			preg_match('/\<body ([^\>]+?)\>/', $html, $ms);
			if ( ! $ms || preg_match('/single/', $ms[1])) continue;

			// get page title
			preg_match('/\<title[^\>]*?\>([^\<]+?)\</', $html, $ts);

			// words
			$html = static::generateSearchStr($html);

			// obj
			$obj = (object) array();
			$title = isset($ts[1]) ? $ts[1] : $url;
			$obj->post_title   = $title;
			$obj->post_content = $html;
			$obj->post_type    = 'crawlsearch';
			$obj->post_status  = 'publish';

			// update
			if (array_key_exists($title, $titles))
			{
				$id = $titles[$title];
				$obj->ID = $id;
				wp_update_post($obj);
				$ids[] = $id;
			}
			// add
			else
			{
				$id = wp_insert_post($obj);
				$ids[] = $id;
			}

			// add postmeta
			update_post_meta($id, 'dashi_redirect_to', $url);
		}

		// garbage collector
		foreach ($posts as $post)
		{
			if (in_array($post->ID, $ids)) continue;
			wp_delete_post($post->ID, $force_delete = 1);
		}
	}
}
