<?php
namespace Dashi\Core;

class Field
{
    /*
     * input_base
     */
    public static function input_base(
        $type,
        $name,
        $value = '',
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        if ( ! isset($attrs['style']) && ! isset($attrs['size'])) $attrs['style'] = 'width:100%;';
        $template = $template ?: '<span class="dashi_description">{description}</span>
<input type="{type}" name="{name}" value="{value}" {attr}>';

        return str_replace(
            array(
                '{type}',
                '{name}',
                '{value}',
                '{description}',
                '{attr}',
            ),
            array(
                esc_html($type),
                esc_html($name),
                esc_html($value),
                $description,
                static::array_to_attr($attrs),
            ),
            $template);
    }

    /*
     * input text
     */
    public static function field_text(
        $name,
        $value = '',
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        $template = $template ?: '<span class="dashi_description">{description}</span>
<input type="text" name="{name}" value="{value}" {attr}>';
        return static::input_base(
            'text',
            $name,
            $value,
            $description,
            $attrs,
            $template
        );
    }

    /*
     * input password
     */
    public static function field_password(
        $name,
        $value = '',
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        $template = $template ?: '<span class="dashi_description">{description}</span>
<input type="password" name="{name}" value="{value}" {attr}>';

        return static::input_base(
            'password',
            $name,
            '', // value
            $description,
            $attrs,
            $template
        );
    }

    /*
     * hidden
     */
    public static function field_hidden(
        $name,
        $value = '',
        $template = '<input type="hidden" name="{name}" value="{value}">'
    )
    {
        if (is_array($value))
        {
            $ret = '';
            foreach ($value as $val)
            {
                $ret .= static::field_hidden($name.'[]', $val);
            }
            return $ret;
        }
        else
        {
            return str_replace(
                array(
                    '{name}',
                    '{value}',
                ),
                array(
                    esc_html($name),
                    esc_html($value),
                ),
                $template);
        }
    }

    /*
     * textarea
     */
    public static function field_textarea(
        $name,
        $value = '',
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        if ( ! isset($attrs['style']) && ! isset($attrs['cols'])) $attrs['style'] = 'width:100%;';
        if ( ! isset($attrs['rows'])) $attrs['rows'] = '6';

        $template = $template ?: '<span class="dashi_description">{description}</span>
<textarea name="{name}" {attr}>{value}</textarea>';

        return str_replace(
            array(
                '{name}',
                '{value}',
                '{description}',
                '{attr}',
            ),
            array(
                esc_html($name),
                esc_html($value),
                $description,
                static::array_to_attr($attrs),
            ),
            $template);
    }

    /*
     * select
     */
    public static function field_select(
        $name,
        $value = '',
        $options = array(),
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        if ( ! $options)
        {
            return '<strong>$options of '.esc_html($name).' is missing.</strong>';
        }

        $template = $template ?: '<span class="dashi_description">{description}</span>
<select name="{name}" {attr}>
{options}
</select>';

        $is_multilpe = isset($attrs['multiple']);
        if ($is_multilpe)
        {
            $template = str_replace('{name}', '{name}[]', $template);
        }

        $options_html = '';
        foreach ($options as $key => $text)
        {
            if ($is_multilpe)
            {
                $selected = in_array($key, $value) ? ' selected="selected" ' : '';
            }
            else
            {
                $selected = $key == $value ? ' selected="selected" ' : '';
            }
            $options_html .= '<option value="'.esc_html($key).'" '.$selected.'>'.esc_html($text).'</option>';
        }

        return str_replace(
            array(
                '{name}',
                '{options}',
                '{description}',
                '{attr}',
            ),
            array(
                esc_html($name),
                $options_html,
                $description,
                static::array_to_attr($attrs),
            ),
            $template);
    }

    /*
     * radio
     */
    public static function field_radio(
        $name,
        $value = '',
        $options = array(),
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        $options_html = '';
        $name_attr = esc_attr($name);
        foreach ($options as $key => $text) {
            $key_attr = esc_attr($key);
            $checked = $key == $value ? ' checked="checked"' : '';
            $options_html .= '<label for="'.$name_attr.'_'.$key_attr.'" class="label_fb">';
            $options_html .= '<input type="radio" name="'.$name_attr.'" value="'.$key_attr.'" id="'.$name_attr.'_'.$key_attr.'"'.$checked.' '.static::array_to_attr($attrs).' />';
            $options_html .= esc_html($text);
            $options_html .= '</label>';
        }

        $template = $template ?: '<span class="dashi_description">{description}</span>
{options}';

        return str_replace(
            array(
                '{name}',
                '{options}',
                '{description}',
                '{attr}',
            ),
            array(
                $name,
                $options_html,
                $description,
                static::array_to_attr($attrs),
            ),
            $template);
    }

    public static function field_checkbox(
        $name,
        $value = array(),
        $options = array(),
        $description = '',
        $attrs = array(),
        $template = ''
    )
    {
        $options_html = '';
        $name_attr = esc_attr($name);

        foreach ($options as $key => $text)
        {
            $key_attr = esc_attr($key);
            $input_id = $name_attr . '_' . $key_attr;
            $checked = is_array($value) && in_array($key, $value) ? ' checked="checked"' : '';

            $options_html .= '<label for="' . $input_id . '" class="label_fb">';
            $options_html .= '<input type="checkbox" name="' . $name_attr . '[]" value="' . $key_attr . '" id="' . $input_id . '"' . $checked . ' ' . static::array_to_attr($attrs) . ' />';
            $options_html .= esc_html($text);
            $options_html .= '</label>';
        }

        $template = $template ?: '<span class="dashi_description">{description}</span>
    {options}';

        return str_replace(
            array(
                '{name}',
                '{options}',
                '{description}',
                '{attr}',
            ),
            array(
                $name_attr,
                $options_html,
                $description,
                static::array_to_attr($attrs),
            ),
            $template
        );
    }

    public static function field_file(
        $name,
        $value,
        $description,
        $attrs,
        $template,
        $is_use_wp_uploader
    )
    {
        $html = '';
        $name_attr = esc_attr($name);
        $value_attr = esc_attr($value);
        $id_attr = isset($attrs['id']) ? esc_attr($attrs['id']) : '';

        if ($is_use_wp_uploader)
        {
            $html .= $description ? '<span class="dashi_description">' . esc_html($description) . '</span>' : '';

            $html .= '<input style="width:72%;" type="text" name="' . $name_attr . '" id="upload_field_' . $id_attr . '" value="' . $value_attr . '" />';
            $html .= '<input style="width:25%;float:right;" class="button upload_file_button" type="button" value="' . esc_attr(__('upload', 'dashi')) . '" />';

            if ($value && preg_match('/\.(jpg|jpeg|png|gif)$/i', $value))
            {
                $html .= '<div class="dashi_uploaded_thumbnail">';
                $html .= '<a href="' . esc_url($value) . '" target="_blank">';
                $html .= '<img src="' . esc_url($value) . '" alt="image" width="80" />';
                $html .= '</a></div>';
            }
        }
        else
        {
            // 通常の file input（value は空）
            $html .= static::input_base(
                'file',
                $name,
                '', // value は空でよい
                esc_html($description),
                $attrs,
                $template
            );
        }

        return $html;
    }


    public static function field_file_media(
        $name,
        $value,
        $description,
        $attrs,
        $template,
        $is_image
    )
    {
        static $field_file_media_js_written = false;

        $html = '';
        $name_attr = esc_attr($name);
        $value_attr = esc_attr($value);
        $id_attr = isset($attrs['id']) ? esc_attr($attrs['id']) : '';

        // $id = $args['label_for'];
        // $value = get_option( $id, '' );
        $media_url = $value_attr ? wp_get_attachment_url( $value_attr ) : '';
        $media_url = esc_url( $media_url );

        // ファイルの場合の表題
        $mediaValues = $value_attr ? get_post( $value_attr ) : false;
        $mediaTitle = $mediaValues ? $mediaValues->post_title : '';

        // ファイルの種類の判定
        $ext = strtolower(pathinfo(parse_url($media_url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);

        // 画像の場合の代替テキスト
        $alt_text = get_post_meta($value_attr, '_wp_attachment_image_alt', true);
        $MediaDesc = $isImg ? $alt_text : $mediaTitle;

        // labelText
        $labelText = $is_image ? '代替テキスト' : 'リンクテキスト';

        // mediaType
        $mediaType = $is_image ? 'image' : 'file';

        // postId
        $postId = get_the_ID() ?: 0;

        // render
        $html.= '<div>';
        $html.= '<button type="button" class="button dashi-file-media-upload" data-target="' . $id_attr .'" data-media-type="'.$mediaType.'">メディアを追加</button> ';
        $html.= '<button type="button" class="button dashi-file-media-remove" data-target="' . $id_attr .'" data-media-type="'.$mediaType.'">メディアを削除</button>';
        $html.= '<input type="hidden" id="' . $id_attr .'" name="' . $name_attr .'" value="' . $value_attr .'">';
        $html.= '<div id="' . $id_attr .'-preview">';
        if ( $media_url  ) {
            if ( $isImg  ) {
                $html.= '<img src="' . $media_url . '" style="max-width: 150px; height: auto;" alt="画像">';
            } else {
                $html.= '<a href="' . $media_url . '">'. $media_url .'</a>';
            }
        }
        $html.= '</div>';

        $html.= '<label>';
        $html.= $labelText . '<input type="text" id="'.$id_attr.'-text" name=\'dashi_file_media_text[' . $value_attr . ']\' value="' . esc_html($MediaDesc) . '">';
        $html.= '<input type="hidden" id="'.$id_attr.'-meta" name=\'dashi_file_media_meta[' . $value_attr . ']\' value="' . basename($media_url) . '">';
        $html.= '</label>';

        $html.= '</div>';

        if ( !wp_script_is('media-views', 'enqueued') ) {
            wp_enqueue_media();
        }

        if ($field_file_media_js_written === false) {
            $field_file_media_js_written = true;

 			$postId = get_the_ID() ?: 0;
 $html .= <<<EOD
<script>
jQuery(document).ready(function($) {
	// アップローダー起動
	$('.dashi-file-media-upload').on('click', function(e) {
		e.preventDefault();

		const target = $(this).data('target');
		const image_id = $('#' + target).val();
		const mediaType = $(this).data('mediaType');
		const uploaderLabel = mediaType === 'image' ? '画像' : 'ファイル';

		// PHPで渡された投稿ID（未保存なら0）
		let post_id = {$postId};

		// JS側（auto-draft含む）の投稿IDを取得
		const js_post_id = (typeof wp !== 'undefined' &&
			wp.media && wp.media.view &&
			wp.media.view.settings && wp.media.view.settings.post &&
			wp.media.view.settings.post.id)
			? wp.media.view.settings.post.id : 0;

		post_id = post_id > 0 ? post_id : js_post_id;

		// メディアライブラリの設定
		const mediaArgs = {
			title: uploaderLabel + 'を選択',
			button: { text: uploaderLabel + 'を選択' },
			multiple: false,
			library: {
				type: mediaType === 'image' ? 'image' : 'application' // applicationだと画像以外のメディアも含まれなくなる
			},
//    frame: mediaType === 'image' ? 'select' : 'post'
		};

		if (post_id > 0) {
			mediaArgs.post = post_id;
		}

		const frame = wp.media(mediaArgs);
		let lastSelectedId = null;

		// すでに選択されているメディアをセット
		frame.on('open', function() {
			if (image_id) {
				const selection = frame.state().get('selection');
				const attachment = wp.media.attachment(image_id);
				attachment.fetch();
				selection.reset([attachment]);
			}
		});

		// 選択したときに情報を反映＆記録
 	let isSelected = false;
		frame.on('select', function() {
      isSelected = true;
			const attachment = frame.state().get('selection').first().toJSON();
			lastSelectedId = attachment.id;
			applyAttachmentData(attachment, target);
		});

		// 閉じたときにも、既にあるIDで再取得して最新情報を反映
		frame.on('close', function() {
      setTimeout(function() {
      if (isSelected) {
        isSelected = false;
        return;
      }
        const currentId = $('#' + target).val();
        if (currentId) {
          const attachment = wp.media.attachment(currentId);
          attachment.fetch().then(function() {
            applyAttachmentData(attachment.toJSON(), target);
          });
        }
      }, 0);
		});

		frame.open();

		// 添付ファイルの情報を入力欄に反映
		function applyAttachmentData(attachment, target) {
			let preview = '';
			let text = '';
			let meta = attachment.filename || '';

			if (mediaType === 'image') {
				preview = '<img src="' + attachment.url + '" style="max-width: 150px; height: auto;" alt="' + uploaderLabel + '">';
				text = attachment.alt || '';
			} else {
				preview = '<a href="' + attachment.url + '">' + attachment.url + '</a>';
				text = attachment.title || '';
			}

			$('#' + target).val(attachment.id);
			$('#' + target + '-preview').html(preview);
			$('#' + target + '-text')
				.val(text)
				.attr('name', 'dashi_file_media_text[' + attachment.id + ']');
			$('#' + target + '-meta')
				.val(meta)
				.attr('name', 'dashi_file_media_meta[' + attachment.id + ']');
		}
	});

	// 削除ボタン処理
	$('.dashi-file-media-remove').on('click', function(e) {
		e.preventDefault();

		const target = $(this).data('target');
		$('#' + target).val('');
		$('#' + target + '-preview').html('');
		$('#' + target + '-text')
			.val('')
			.attr('name', 'dashi_file_media_text[]');
		$('#' + target + '-meta')
			.val('')
			.attr('name', 'dashi_file_media_meta[]');
	});
});
</script>
EOD;
        }

        return $html;
    }

    /*
     * array を html の attribute に
     */
    public static function array_to_attr($attrs) {
        $attr_strs = '';

        foreach ((array) $attrs as $property => $value) {
            // Ignore null/false
            if ($value === null or $value === false) {
                continue;
            }

            // validation test
            if ($property == 'required') continue;

            // If the key is numeric then it must be something like selected="selected"
            if (is_numeric($property))
            {
                $property = $value;
            }

            $attr_strs .= esc_html($property).'="'.esc_html($value).'" ';
        }

        // strip off the last space for return
        return trim($attr_strs);
    }
}
