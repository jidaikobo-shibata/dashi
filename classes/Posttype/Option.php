<?php
namespace Dashi\Core\Posttype;

class Option
{
	/*
	 * help
	 */
	public static function help()
	{
?>
&lt;?php
namespace Dashi\Posttype;

class Sample extends \Dashi\Core\Posttype\Base
{
  public static function __init ()
  {
    // ============ <?php echo __('Required items', 'dashi') ?> ============

    // <?php echo __('display name of post type. You may use multibyte character', 'dashi')."\n" ?>
    static::set('name', 'Sample');

    // supports
    static::set('supports', array(
        'title',
        'editor',
        'author',
        'thumbnail',
        'excerpt',
        'revisions',
        'page-attributes',
        'post-formats',
        // 'trackbacks',
        // 'custom-fields',
        // 'comments',
      ));

    // ============ <?php echo __('Taxonomies', 'dashi') ?> ============

    // taxonomies
    static::set('taxonomies', array (
        'my_tags' => array(
          'label'        => 'TAGS',
          'public'       => true,
          'show_ui'      => true,
          'hierarchical' => true,
        ),
      ));

    // <?php echo __('add Custom Fields to taxonomy', 'dashi')."\n" ?>
    static::set('custom_fields_taxonomies', array(
        'my_tags_category' => array(
          'mainimage' => array(
            'type' => 'file',
            'label' => 'main image',
            'attrs' => array()
          ),
        ),
      ));

    // ============ <?php echo __('Custom Fields', 'dashi') ?> ============

    static::set('custom_fields', array(

        'sample_field_1' => array(
          // <?php echo __('You may use, text, textarea, radio, checkbox, select and google_map', 'dashi')."\n" ?>
          'type'        => 'text',
          'label'       => 'sample field 1',
          'description' => '',

          // <?php echo __('You may create duplicatable meta_box.', 'dashi')."\n" ?>
          'duplicate' => true,

          // zip code
          // <?php echo __('set same "zip_group" name', 'dashi')."\n" ?>
          'zip_group'   => 'zip_group_1',
          'zip_to'       => 'address',

          // <?php echo __('about "zip_from_type" see https://github.com/ninton/jquery.jpostal.js', 'dashi')."\n" ?>
          'zip_group'   => 'zip_group_1',
          'zip_from'       => 'zip',
          'zip_from_type' => '%3 %4 %5 %6',

          // <?php echo __('Add column to admin index. set order', 'dashi')."\n" ?>
          'add_column' => 1,
          // <?php echo __('Add restriction to admin index', 'dashi')."\n" ?>
          'add_restriction' => true,

          'attrs' => array(
            'required' => 1,
            'style'    => 'width:100%;',

            // date picker
            // <?php echo __('"dashi_datepicker" class uses date picker', 'dashi')."\n" ?>
            'class'    => 'dashi_datepicker',
            // <?php echo __('"dashi_datepicker" accept date format', 'dashi')."\n" ?>
            'data-dashi_datepicker_format' => 'yy-mm-dd',

            // characters count
            // <?php echo __('"dashi_chrcount" class add character counter', 'dashi')."\n" ?>
            'class'    => 'dashi_chrcount',
            // <?php echo __('"dashi_chrcount" accept character number', 'dashi')."\n" ?>
            'data-dashi_chrcount_num' => 200,
          ),
        ),

        'event_start' => array(
          'type' => 'text',
          'label' => 'Start datetime',
          'description' => '',
          'filters' => array(
          ),
          'validations' => array(
          ),
          'attrs' => array(
            'class' => 'datetime dashi_datetimepicker',
            'data-dashi_timeformat' => 'HH:mm',
            'data-dashi_stepminute' => '15',
          )
        ),

        'email' => array(
          'label' => 'メールアドレス',
          'type' => 'text',
          'store_data' => false,
          'public_form_only' => true,
          'public_form_allow_send_by_mail' => false,
          'attrs' => array(
            'required' => true,
          ),
          'filters' => array(
            'alnum',
          ),
          'validations' => array(
            'Mailaddress',
          ),
        ),

         // <?php echo __('taxonomy settings', 'dashi')."\n" ?>
        'information_category' => array( // <?php echo __('if using taxonomy this field need to be used same name', 'dashi')."\n" ?>
          'type' => 'taxonomy', // <?php echo __('here must be "taxonomy"', 'dashi')."\n" ?>
          'label' => 'ジャンル', // <?php echo __('You may use label for public form', 'dashi')."\n" ?>
          'add_column' => 1, // <?php echo __('for index', 'dashi')."\n" ?>
          'add_restriction' => true, // <?php echo __('for index', 'dashi')."\n" ?>
          'value' => '',
//          'radio' => true, // <?php echo __('You may change checkbox to radio', 'dashi')."\n" ?>
          'attrs' => array(
          ),
          'fieldset_template' => '<dt>{label}</dt><dd>{field}</dd>',
        ),

        'sample_text_1' => array(
          'type'        => 'textarea',
          'label'       => 'sample text 1',
          // <?php echo __('true or array(). except for textarea_name. see: https://codex.wordpress.org/Function_Reference/wp_editor', 'dashi')."\n" ?>
          'wysiwyg' => true,
        ),

        'sample_field_2' => array(
          'label'       => 'sample field 1',
          // <?php echo __('you may invoke callback method', 'dashi')."\n" ?>
          'callback'    => 'method',
          // <?php echo __('"referencer" shows data table when input', 'dashi')."\n" ?>
          'referencer' => 'person',
        ),

        'hidden_field' => array(
          // <?php echo __('make cannot be searched', 'dashi')."\n" ?>
          'is_public_searchable' => false,
          'type'        => 'hidden',
          'value'       => 'value',
        ),

        'url' => array(
          'type' => 'text',
          'label' => 'Link',
          'description' => '',
          'filters' => array(
            'alnum',
//            'lower',
//            'upper',
//            'trim',
//            'int',
//            'date',
//            'datetime',
          ),
          'validations' => array(
//            'NotEmpty',
//            'Mailaddress',
//            'Sb',
//            'Alnum',
//            'Alnumplus',
//            'Alnumfilename',
//            'Image',
//            'Katakana',
//            'Hiragana',
//            'Uploadable',
          ),
          'attrs' => array(
            'required' => 1
          )
        ),

        'fields_sample' => array(
          'label' => 'foo',
          'fields' => array(
            'sample_field_2' => array(
              'type'        => 'radio',
              'label'       => 'sample field 2',
              'description' => '',
              // <?php echo __('default value. at checkbox, use array.', 'dashi')."\n" ?>
              'value'       => 1,
              'options' => array(
                1 => 'foo',
                2 => 'bar',
                3 => 'baz',
              ),
            ),
            'sample_field_2' => array(
              'type'        => 'radio',
              'label'       => 'sample field 2',
            ),
          ),

        ));

      // ============ <?php echo __('Optional items', 'dashi') ?> ============

      // <?php echo __('You may set, public, publicly_queryable, show_ui, query_var, rewrite, capability_type, capabilities, map_meta_cap, hierarchical, menu_position, has_archive, show_in_nav_menus, exclude_from_search', 'dashi')."\n" ?>

    // <?php echo __('need when use custom capability', 'dashi')."\n" ?>
    static::set('plural', 'Samples');

    // <?php echo __('always shown message at administration', 'dashi')."\n" ?>
    static::set('description', '');

    // <?php echo __('order of administration menu: default 10', 'dashi')."\n" ?>
    static::set('order', 2);

    // <?php echo __('use ascii slug (not multibyte character): default false', 'dashi')."\n" ?>
    static::set('is_use_force_ascii_slug', true);

    // <?php echo __('use sticky: default true', 'dashi')."\n" ?>
    static::set('is_use_sticky', true);

    // <?php echo __('search deeply (ex. custom_fields): default true', 'dashi')."\n" ?>
    static::set('is_searchable', true);

    // <?php echo __('invisible each page: default false', 'dashi')."\n" ?>
    static::set('is_redirect', false);

    // <?php echo __('set redirect url for each single page: default empty', 'dashi')."\n" ?>
    static::set('redirect_to', '');

    // <?php echo __('invisible post_type, cannot edit: default true', 'dashi')."\n" ?>
    static::set('is_visible', true);

    // <?php echo __('invisible post_type at administration menu: default false', 'dashi')."\n" ?>
    static::set('is_hidden', false);

    // <?php echo __('administrator only can set sticky: default false', 'dashi')."\n" ?>
    static::set('is_sticky_admin_only', true);

    // <?php echo __('place holder for first post', 'dashi')."\n" ?>
    static::set('enter_title_here', 'SOME WORDS');

    // <?php echo __('allow move meta_box at edit: default false', 'dashi')."\n" ?>
    static::set('allow_move_meta_boxes', true);

    // ============ <?php echo __('Dashi Public Form', 'dashi') ?> ============

    // <?php echo __('Mail settings', 'dashi')."\n" ?>
    static::set('sendto', get_option('admin_email'));
    static::set('replyto', get_option('admin_email'));
    static::set('from_name', get_option('blogname'));

    static::set('subject', __('Contact: ', 'dashi').get_option('blogname'));
    static::set('re_subject', __('Auto Reply: ', 'dashi').get_option('blogname'));

    static::set('is_auto_reply', true);
    static::set('auto_reply_field', 'email');

    static::set('is_send_exif', false);

    // <?php echo __('Public form settings', 'dashi')."\n" ?>
    static::set('allow_post_by_public_form', true);
    static::set('public_form_final_message', '<p>Thank you. Accept your post.</p>');
    static::set('public_form_post_title_field', 'title');
    static::set('public_form_allowed_mimes', array(
        'jpg|jpeg|jpe' => 'image/jpeg',
      ));
    static::set('sendto', 'example@example.com');
    static::set('subject', 'mail subject');
    static::set('re_subject', 'auto reply mail subject');
    static::set('is_auto_reply', true);
    static::set('auto_reply_field', 'email');

  }
}
<?php
	}

	/*
	 * helpHooks
	 */
	public static function helpHooks()
	{
?>

// <?php echo __('\'dashi_mod_custom_fields\' Hook can modify dashi default custom fields. ex: Pagepart', 'dashi')."\n" ?>
(array) dashi_mod_custom_fields ($post_type, $custom_fields)
add_filter('dashi_mod_custom_fields', function($arr, $post_type, $custom_fields)
{
  if ($post_type == 'pagepart')
  {
    $custom_fields['foo'] = array(
      'type' => 'text',
      'label' => 'FOO',
    );
    $arr = $custom_fields;
  }
  return $arr;
},
  10,
  3);

// <?php echo __('\'dashi_save_post_value\' Hook can modify save value.', 'dashi')."\n" ?>
(string) dashi_save_post_value ($content, $post_type, $key)
add_filter('dashi_save_post_value', function($val, $post_type, $field)
{
  if ($post_type == 'lecture')
  {
    return str_replace('--', '++', $val);
  }
  else
  {
    return $val;
  }
},
  10,
  3);

// <?php echo __('Hook of \Dashi\Core\Mail::send(). return true, \Dashi\Core\Mail::send() will not send a mail. so you should do something at your code.', 'dashi')."\n" ?>
(bool) dashi_mail ($to, $subject, $message, $additional_headers, $additional_parameters)

// <?php echo __('modify send button of public form', 'dashi')."\n" ?>
(string) dashi_public_form_submit_button ($end, $post_type)

// <?php echo __('modify send button of public form of confirm page', 'dashi')."\n" ?>
(string) dashi_public_form_confirm_button ($end, $post_type)

// <?php echo __('modify final message of public form', 'dashi')."\n" ?>
(string) dashi_public_form_final_message ($message, $post_type)

// <?php echo __('modify mail text of public form', 'dashi')."\n" ?>
(string) dashi_public_form_admin_mail_body (admin_mail, $post_type)

// <?php echo __('modify auto reply text of public form', 'dashi')."\n" ?>
(string) dashi_public_form_reply_mail_body ($reply_mail, $post_type)

// <?php echo __('Hook when something sent of public form', 'dashi')."\n" ?>
(void) dashi_public_form_mail_hook ($vals, $post_type)

add_filter('dashi_public_form_mail_hook',
  function ($vals, $post_type)
  {
    $tmp = clone $vals;
    $class = \Dashi\Core\Posttype\Posttype::posttype2class($post_type);
    $body = \Dashi\Core\Posttype\PublicForm::body($class, $tmp);
    $success = \Dashi\Core\Mail::send(
      $class::get('sendto'),
      $class::get('subject'),
      $body,
      'From: '.$from
    );
  },
  10,
  2
);
<?php
	}
}
