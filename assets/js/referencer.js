console.log('referencer');
jQuery(function($)
{
	if ($('.dashi_referencer'))
	{
		$('body').append('<div class="dashi_referencer_table" style="'
			+'position:fixed; max-height:50%; min-height: 50%; max-width:50%; background-color:#fff; border:1px solid #000; top:20%; left:20%;padding: 1em;'
			+'">'
			+ '<input type="text" class="search">'
		//	+ '<input type="hidden" class="page">'
			+ '<input type="hidden" class="dashi_referencer_post_type">'
			+ '<input class="dashi_referencer_search" type="button" value="検索">'
			+ '<input class="dashi_referencer_close" type="button" value="閉じる">'
			+ '<ul class="result"></div>'
			+ ''
			+ '</ul>');

		$('.dashi_referencer_table').hide();
		$('.dashi_referencer_table .dashi_referencer_close').on('click', function()
		{
			$('.dashi_referencer_focus').removeClass('dashi_referencer_focus')
			$('.dashi_referencer_table').hide();
		});

		$('.dashi_referencer_table .dashi_referencer_search').on('click', referencer_search);
	}

	$('.dashi_referencer').each(function()
	{

		/*
		var refer_button = $('<input style="width:25%;float:right;" class="dashi_referencer_button" type="button" value="参照">');
		refer_button.data('dashi_referencer', $(this).data('dashi_referencer')); //set post_type
		$(this).after(refer_button);
		*/

		$(this).on('click', function()
		{
			$('.dashi_referencer_focus').removeClass('dashi_referencer_focus')
			$(this).addClass('dashi_referencer_focus');
			$('.dashi_referencer_table').show();
			$('.dashi_referencer_table .dashi_referencer_post_type').val($(this).data('dashi_referencer'));

			referencer_search();
		});

		/*
		refer_button.on('click', function(e)
		{
			$(e.target).prev('.referencer').addClass('referencer_focus');

			$('.referencer_table').show();
			$('.referencer_table .referencer_post_type').val($(e.target).data('referencer'));
		});
		*/
	});

	function referencer_search()
	{
		var post_type    = $('.dashi_referencer_table .dashi_referencer_post_type').val();
		var search    = $('.dashi_referencer_table input.search').val();
		// var page      = $('.dashi_referencer_table').find('.page').val();

		var fd = new FormData();
		fd.append('action', Params.action);
		fd.append('post_type', post_type);
		fd.append('search', search);

		$.ajax({
			url: Params.ajax_url,
			type : "POST",
			dataType : "json",
			data : fd,
			processData : false,
			contentType : false
		}
		).done(function( result ){

			$('.dashi_referencer_table .result').empty();

			if  (result.data && result.data.length)
			{
				for (var key in result.data)
				{
					var row_data = result.data[key];
					var row_elm = $('<li>' + row_data.post_title + '</li>')
					var row_button = $('<input style="width:25%;float:right;" type="button" value="' + row_data.ID + '">');
					row_button.on('click', function()
					{
						$('.dashi_referencer_focus').val($(this).val());
						$('.dashi_referencer_table').hide();
					});
					row_elm.append(row_button);
					$('.dashi_referencer_table .result').append(row_elm);
				}
			}

			console.log(result);
		}).fail(function( result ){
			alert ('失敗');
		}).always(function( result ) {
		});








	}
});
