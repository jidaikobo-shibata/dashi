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
				$ret .= field_hidden($name.'[]', $val);
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
		foreach ($options as $key => $text)
		{
			$name = esc_html($name);
			$key = esc_html($key);
			$checked = $key == $value ? ' checked="checked" ' : '';
			$options_html .= '<label for="'.$name.'_'.$key.'" class="label_fb">';
			$options_html .= '<input type="radio" name="'.$name.'" value="'.$key.'" id="'.$name.'_'.$key.'" '.$checked.' {attr}>';
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
		foreach ($options as $key => $text)
		{
			$name = esc_html($name);
			$key = esc_html($key);
			$checked = is_array($value) && in_array($key, $value) ? ' checked="checked" ' : '';
			$options_html .= '<label for="'.$name.'_'.$key.'" class="label_fb">';
			$options_html .= '<input type="checkbox" name="'.$name.'[]" value="'.$key.'" id="'.$name.'_'.$key.'" '.$checked.' {attr}>';
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

	public static function field_file(
		$name,
		$value,
		$description,
		$attrs,
		$template,
		$is_use_wp_uploader
	)
	{
		$err_class = '';
		$err_text = '';

		$html = '';
		if ($is_use_wp_uploader)
		{
			$html.= $description ? '<span class="dashi_description">'.$description.'</span>' : '';
			$html.= '<input style="width:72%;" type="text" name="'.esc_html($name).'" id="upload_field_'.esc_html($attrs['id']).'" value="'.esc_html($value).'" />';
			$html.= '<input style="width:25%;float:right;" class="button upload_file_button" type="button" value="'.__('upload', 'dashi').'" />';
			if ($value && preg_match('/\.(jpg|jpeg|png|gif)/is', $value))
			{
				$html.= '<div class="dashi_uploaded_thumbnail"><a href="'.esc_html($value).'" target="_blank"><img src="'.esc_html($value).'" alt="image" width="80" /></a></div>';
			}
		}
		else
		{
//			$html.= '<input type="hidden" name="MAX_FILE_SIZE" value="'.ini_get('post_max_size').'" />';
			$html.= static::input_base(
				'file',
				$name,
				'', // value
				$description,
				$attrs,
				$template
			);
		}

		return $html;
	}

	/*
	 * array を html の attribute に
	 */
	public static function array_to_attr($attrs)
	{
		$attr_strs = '';

		foreach ((array) $attrs as $property => $value)
		{
			// Ignore null/false
			if ($value === null or $value === false)
			{
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
