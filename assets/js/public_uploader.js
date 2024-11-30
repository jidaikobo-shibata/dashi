/**
 * 配列 (filename[1]) のようなものは見ない
 * multiple も見ない
 * image しか処理しない
 */
jQuery(function($)
{
	var uplader_id_cnt = 0;

	$('input[type="file"]').each(function ()
	{
		var name = $(this).attr('name');
		var multiple = $(this).attr('multiple');

		// var id = name.replace('[', '_').replace(']', '_');
		var id = name;
		var session = DashiUpload.session;

		var uploader_element = $('<div class="__uploader" data-name="' + name + '"></div>');
		var upload_button_element = $('<a class="__uploader_button button" href="">ファイルをアップロード</a>');
		var clear_button_element = $('<a class="__clear_button button" href="">クリア</a>');
		uploader_element.append(upload_button_element);
		uploader_element.append(clear_button_element);


		var image_element  = $('<div class="__uploader_uploaded"></div>');

		if (session[DashiUpload.form] && session[DashiUpload.form][name] && session[DashiUpload.form][name].name)
		{
			var filename = session[DashiUpload.form][name].name;

			image_element.append('<img style="max-width:200px; display:block;" src="' + DashiUpload.upload_url + filename + '">');
			image_element.append('<input type="hidden" class="filename" name="'  + name + '[name]" value="' + filename + '">'); // path を保存
			image_element.append('<input type="hidden" class="filevalue" name="' + name + '[dashi_uploaded_file]" value="1">'); // path を保存
			if (session[DashiUpload.form][name].exif)
			{
				image_element.append('<input type="hidden" class="filevalue" name="' + name + '[exif]" value="' + session[DashiUpload.form][name].exif.replace(/\"/g, "&quot;") + '">');
				
			}
		}

		uploader_element.append(image_element);

		$(this).after(uploader_element);

		$(this).remove();

		clear_button_element.on('click', function(e)
		{
			e.preventDefault();
			$(e.target).closest('.__uploader').find('.__uploader_uploaded img').remove();
			$(e.target).closest('.__uploader').find('.filename').remove();
			$(e.target).closest('.__uploader').find('.filevalue').remove();
		});

		upload_button_element.on('click', function(e)
		{
			e.preventDefault();
			upload_target_area = $(e.target).closest('.__uploader').find('.__uploader_uploaded');

			var uploader_id = 'uploader_hidden_file_' + uplader_id_cnt;

			// file を格納する element
			var file_input_element;
			file_input_element = $('<input type="file" id="' + uploader_id + '">');
			file_input_element.css({width: 0, height: 0, display: 'none'});
			$('body').append(file_input_element);

			file_input_element.on('change', function()
			{
				var files = document.getElementById(uploader_id).files;
				for (var index in files)
				{
					var file = files[index];
					if (file instanceof File)
					{
						var fd = new FormData();
						fd.append('action', DashiUpload.action);
						fd.append('file_data', file);
						fd.append('form', DashiUpload.form);
						/*
						 * handle が難しい(over size など)ので
						 * 個別に ajax を飛ばす
						 */

						var postData = {
							url: DashiUpload.ajax_url,
							type : "POST",
							dataType : "json",
							data : fd,
							processData : false,
							contentType : false
						};

						upload_target_area.append('<div class="ajax-uploading"><span>アップロード中...</span></div>');



						$.ajax(postData
						).done(function( result ){
							if (!result)
							{
								alert ('アップロードに失敗');
							}
							else
							{
								if (!result['success'])
								{
									if (result.data.message)
									{
										alert(result.data.message.toString());
									}
									return;
								}

								if (result.data.errors.length)
								{
									alert(result.data.errors);
									return;
								}

								if (! upload_target_area) return;


								var field_name = upload_target_area.closest('.__uploader').data('name');

								upload_target_area.empty();

								if ( result.data['type'].match(/^image*/) && result.data['path'])
								{
									upload_target_area.append('<img style="max-width:200px; display:block;" src="' + result.data['path'] + '">');
								}
								else // image 以外
								{
									upload_target_area.append('<img style="max-width:200px; display:block;" src="' + DashiUpload.home_url + '/wp-includes/images/media/default.png">');
								}

								upload_target_area.append('<input type="hidden" class="filename" name="'  + field_name + '[name]" value="' + result.data['name'] + '">'); // path を保存
								upload_target_area.append('<input type="hidden" class="filevalue" name="' + field_name + '[dashi_uploaded_file]" value="1">'); // path を保存
								if (result.data['exif']) upload_target_area.append('<input type="hidden" class="filevalue" name="' + field_name + '[exif]" value="' + result.data['exif'].replace(/\"/g, "&quot;") + '">'); // path を保存
								
							}
						}).fail(function( result ){
							console.log('fail');
								console.log(result);
							alert ('アップロードに失敗');
						}).always(function( result ) {
							console.log('always');
							console.log(result);
							$('.ajax-uploading').remove()
						});

					}
				}
			});

			file_input_element.click();

			uplader_id_cnt++;

		});










	});


});
