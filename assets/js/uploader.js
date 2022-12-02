jQuery(function($)
{
	// Suppress error ( valiate invisible field )
	$('.media-upload-form').find('submit').attr('formnovalidate', false);

	//file uploads by custom field
	var formfield;
	$('.upload_image_button').click(function() {
		$('html').addClass('Image');
		formfield = $(this).prev().attr('id');
		tb_show('', 'media-upload.php?type=image&amp;TB_iframe=true');
		return false;
	});
	$('.upload_file_button').click(function() {
		$('html').addClass('Image');
		formfield = $(this).prev().attr('id');
		tb_show('', 'media-upload.php?type=image&amp;TB_iframe=true');
		return false;
	});
	$('#insert-media-button').click(function()
	{
		formfield = false;
	});

	window.original_send_to_editor_dashi = window.send_to_editor;
	window.send_to_editor = function(html){
		if (formfield) {
			fileurl = $('img',html).attr('src');
			if(! fileurl){
				fileurl = $(html).attr('src');
				if (! fileurl)
				{
					fileurl = $(html).attr('href');
				}
			}
			$('#'+formfield).val(fileurl);
			tb_remove();
			$('html').removeClass('Image');
		} else {
			window.original_send_to_editor_dashi(html);
		}
	};

});
