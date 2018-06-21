<?php
namespace Dashi\Core;

class Option
{
	private static $opts = array(
		'dashi_activate_pagepart' => 'Activate page part',
		'dashi_enrich_search_result_page' => 'Enrich search result page',
		'dashi_ignore_checked_ontop' => 'Keep taxonomies\' order at edit page',
		'dashi_remove_host_at_upload_file' => 'Attempt to use root relative path when upload files',
		'dashi_disactivate_author_page' => 'Disactivate author page to guest users.',
		'dashi_google_map_api_key' => 'Get API-key from <a href="https://console.developers.google.com/apis/library">Google API library</a>.<br />1. Choose Project<br />2. Click +<br />3. Input name to create Project<br />4. Click Auth in sidebar and click create<br />5. Input name<br />6. create auth information and choose API-key<br />7. Go Library and choose "Google Maps JavaScript API", "Google Maps Embed API" and "Google Maps Geocoding API" and activate it.',
		'dashi_development_mode' => 'Show error messages for developers',
		'dashi_head_html_is_ok' => 'After check html, check this.',
		'dashi_utility_pages_are_ok' => 'After check utility pages, check this.',
		'dashi_backup_is_ok' => 'When backup is available, check this.',
		'dashi_server_accesslog_is_ok' => 'When Server Access Log is available, check this.',
		'dashi_do_environmental_check' => 'Use dashboard environmental check.',
		'dashi_do_not_heavy_dashboard_check' => 'avoid heavy dashboard check (turn on at production).',
		'dashi_show_csv_export_dashboard' => 'show csv export at dashboard.',
		'dashi_show_wp_version' => 'Show always WordPress version at admin-bar.',
		'dashi_do_eliminate_control_codes' => 'eliminate control codes when saving post.',
		'dashi_avoid_wp_redirect_admin_locations' => 'avoid wp redirect admin location.',
		'dashi_sitemap_depth_of_page' => 'set depth of page od [dashi_sitemap] (0 to ignore page)',
		'dashi_public_form_done_sendmail' => 'Send a mail when public form used.',
		'dashi_another_done_sendmail' => 'Send a mail when Another content updated.',
		'dashi_development_diable_field_cache' => 'Avoid to use field cache (slow query but for development)',
		'dashi_keep_ssl_connection' => 'Keep SSL connection except for GuzzleHttp access.',
		'dashi_do_eliminate_utf_separation' => 'eliminate utf separation when saving post.',

		'dashi_no_need_analytics' => 'No need to check Google Analytics (error suppress)',
		'dashi_no_need_security_plugin' => 'No need to check Security Plugin (error suppress)',
		'dashi_no_need_sitemap_plugin' => 'No need to check sitemap.xml Plugin (error suppress)',
		'dashi_no_need_dev_plugin' => 'No need to check development Plugin (error suppress)',
		'dashi_no_need_acc_plugin' => 'No need to check Accessibility Plugin (error suppress)',

		'dashi_allow_comments' => 'Comments allowed site.',
		'dashi_allow_xmlrpc' => 'Use xmlrpc.php',
		'dashi_sitemap_page_upsidedown' => 'At Dashi Sitemap, turn page appears.',
		'dashi_sitemap_home_string' => 'At Dashi Sitemap, use this strings as a label.',

		'dashi_auto_update_core' => 'Auto Update (Core Major Version)',
		'dashi_auto_update_theme' => 'Auto Update (Theme)',
		'dashi_auto_update_plugin' => 'Auto Update (Plugin)',
		'dashi_auto_update_language' => 'Auto Update (Language)',

		'dashi_specify_search_index' => 'Specify search index page, when url is not come up to search result. Input URL for each line.',
	);

	/*
	 * get options
	 * @return array
	 */
	public static function getOptions()
	{
		return static::$opts;
	}

	/*
	 * dbio
	 */
	private static function dbio()
	{
		if (Input::post())
		{
			$posts = Input::post();

			// checkboxes
			foreach (array_keys(static::$opts) as $v)
			{
				delete_option($v);
				if (isset($posts[$v]))
				{
					if (in_array($v, array(
								'dashi_sitemap_depth_of_page',
								'dashi_google_map_api_key',
								'dashi_sitemap_home_string',
								'dashi_specify_search_index'
							)
						)
					)
					{
						update_option($v, esc_html($posts[$v]));
					}
					else
					{
						update_option($v, 1);
					}
				}
			}
		}
	}

	/*
	 * setting
	 */
	public static function setting()
	{
		static::dbio();

		// html
		$html = '';
		$html.= '<div class="wrap">';
		$html.= '<div id="icon-themes" class="icon32"><br /></div>';
		$html.= '<h1>'.__('Dashi Framework', 'dashi').' '.__("Settings").'</h1>';
		$html.= '<div class="postbox" style="margin-top: 15px;">';
		$html.= '<div class="inside">';
		$html.= '<h2>'.__("Settings").'</h2>';

		$html.= '<form action="" method="POST">';

		foreach (static::$opts as $k => $v)
		{
			if (in_array($k, array(
				'dashi_sitemap_home_string',
				'dashi_sitemap_depth_of_page',
				'dashi_google_map_api_key',
				'dashi_specify_search_index'
			))) continue;
			if (substr($k, 0, 17) == 'dashi_auto_update') continue;
			$opt = get_option($k);
			$checked = $opt ? ' checked="checked"' : '';
			$html.= '<p><label><input type="checkbox" name="'.$k.'" value="1"'.$checked.' />'.__($v, 'dashi').'</label></p>';
		}

		// Google Map
		$k = 'dashi_google_map_api_key';
		$html.= '<fieldset style="border: 1px #aaa solid;padding: 10px 15px 0;margin:0 0 10px;"><legend><label for="'.$k.'">Google Map API Key</label></legend>';
		$html.= '<input type="text" name="'.$k.'" id="'.$k.'" size="40" value="'.get_option($k).'" />';
		$html.= '<p>'.__(static::$opts[$k], 'dashi').'</p></fieldset>';

		// sitemap depth
		$k = 'dashi_sitemap_depth_of_page';
		$html.= '<fieldset style="border: 1px #aaa solid;padding: 10px 15px 10px;"><legend><label for="'.$k.'">'.__(static::$opts[$k], 'dashi').'</label></legend>';
		$html.= '<input type="text" name="'.$k.'" id="'.$k.'" size="5" value="'.get_option($k).'" />';
		$html.= '</fieldset>';

		// sitemap depth
		$k = 'dashi_sitemap_home_string';
		$html.= '<fieldset style="border: 1px #aaa solid;padding: 10px 15px 10px;"><legend><label for="'.$k.'">'.__(static::$opts[$k], 'dashi').'</label></legend>';
		$value = get_option($k) ?: __('Home');
		$html.= '<input type="text" name="'.$k.'" id="'.$k.'" size="20" value="'.$value.'" />';
		$html.= '</fieldset>';

		// updates
		$updates = array(
			'dashi_auto_update_core',
			'dashi_auto_update_theme',
			'dashi_auto_update_plugin',
			'dashi_auto_update_language'
		);
		$html.= '<fieldset style="border: 1px #aaa solid;padding: 10px 15px 10px;"><legend><label for="'.$k.'">'.__('Auto Update', 'dashi').'</label></legend>';
		// auto update
		foreach ($updates as $update)
		{
			$checked = get_option($update) ? ' checked="checked"' : '';
			$html.= '<p><label><input type="checkbox" name="'.$update.'" id="'.$update.'" size="20" value="1"'.$checked.' />'.__(static::$opts[$update], 'dashi').'</label></p>';
		}
		$html.= '</fieldset>';

		// specify search index url
		$k = 'dashi_specify_search_index';
		$html.= '<fieldset style="border: 1px #aaa solid;padding: 10px 15px 10px;"><legend><label for="'.$k.'">'.__(static::$opts[$k], 'dashi').'</label></legend>';
		$html.= '<textarea style="width: 100%;min-height:6em" name="'.$k.'" id="'.$k.'">'.get_option($k).'</textarea>';
		$html.= '</fieldset>';

		$html.= '<p><input type="submit" value="'.__('Submit').'" class="button button-primary button-large" /></p>';
		$html.= '</form>';
		$html.= '</div><!--/.inside-->';
		$html.= '</div><!--/.postbox-->';
		$html.= '</div><!--/.wrap-->';

		$html.= '<div class="wrap">';
		$html.= '<div id="icon-themes" class="icon32"><br /></div>';
		$html.= '<div class="postbox" style="margin-top: 15px;">';
		$html.= '<div class="inside" id="help_area">';
		$html.= '<h2>'.__("Help").'</h2>';
		$html.= '<div><a href="?page=dashi_options&amp;help=posttype#help_area">Post Type</a> | ';
		$html.= '<a href="?page=dashi_options&amp;help=shortcode#help_area">shortcode</a> | ';
		$html.= '<a href="?page=dashi_options&amp;help=seo#help_area">SEO</a> | ';
		$html.= '<a href="?page=dashi_options&amp;help=hooks#help_area">Hooks</a> | ';
		$html.= '</div>';

		$html.= '<div class="inside">';
		echo $html;
?>
<textarea style="width: 100%;height: 500px;border: 1px #aaa solid;padding: 10px;font-family: monospace;">
<?php
if (input::get('help') == 'form')
{
	Form\Option::help();
}
elseif (input::get('help') == 'form_re')
{
	Form\Option::help_re();
}
elseif (input::get('help') == 'form_to')
{
	Form\Option::help_to();
}
elseif (input::get('help') == 'shortcode')
{
	Shortcode::option();
}
elseif (input::get('help') == 'seo')
{
	echo '1. '.__('Prepare sitemap.xml.', 'dashi')."\n";
	echo '2. '.__('Prepare Gmail account.', 'dashi')."\n";
	echo '3. '.__('Create Google Analytics account by Gmail account.', 'dashi')."\n";
	echo '4. '.__('Set Google Analytics JavaScript in the head of html.', 'dashi')."\n";
	echo '5. '.__('Manage -> Properties -> setting -> Search Console', 'dashi')."\n";
	echo '6. '.__('Create account at Search Console and add site and confirm it.', 'dashi')."\n";
	echo '7. '.__('Create Microsoft account by Gmail account.', 'dashi')."\n";
}
elseif (input::get('help') == 'hooks')
{
	Posttype\Option::helpHooks();
}
else
{
	Posttype\Option::help();
}
?>
</textarea>

<?php
		$html = '</div><!--/.inside-->';
		$html.= '</div><!--/.postbox-->';
		$html.= '</div><!--/.wrap-->';

		echo $html;
	}
}
