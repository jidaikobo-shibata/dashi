// label_fb
jQuery(function($){
	$('.label_fb').find('input[type="checkbox"], input[type="radio"]').each(function(e){
		check_fb($(this));
	});

	$('.label_fb').find('input[type="checkbox"], input[type="radio"]').on('change',function(e){
		check_fb($(this),e);
	});

	function check_fb(obj, e){
		if(!obj) return;
		if( e && obj.is("input[type='radio']")){
			obj.closest('form').find("[name="+obj.attr('name')+"]").parent().removeClass('on');
		}
		if(obj.prop('checked')){
			obj.parent().addClass('on');
		} else {
			obj.parent().removeClass('on');
		};

		setTimeout(function(){
		},100);
	}
});

// date picker
jQuery(function($){
	$(".dashi_datepicker").datepicker({
			beforeShow: function(input, inst) {
			// add dashi class
			$(inst.dpDiv).addClass('dashi_datepicker');
			// set date format
			var dateFormat = $(this).data('dashi_datepicker_format');
			var currentval = $(this).val();
			if(dateFormat)
			{
				$(this).datepicker('option', 'dateFormat', dateFormat);
				$(this).datepicker('setDate', currentval);
			}
		},
		onClose: function(input, inst){
			// remove dashi class
			$(inst.dpDiv).removeClass('dashi_datepicker');
		}
	});
});

// datetime picker
jQuery(function($){
	$(".dashi_datetimepicker").each(function(){
		var obj = {
			beforeShow: function(input, inst)
			{
				// add dashi class
				$(inst.dpDiv).addClass('dashi_datepicker');
			},
			addSliderAccess: false,
			sliderAccessArgs: { touchonly: false },
			changeMonth: true,
			changeYear: true
		} ;
		if($(this).data('dashi_stepminute'))
		{
			Object.assign( obj, { 'stepMinute' : $(this).data('dashi_stepminute')});
		}
		if($(this).data('dashi_timeformat'))
		{
			Object.assign( obj, { 'timeFormat' : $(this).data('dashi_timeformat')});
		}
		Object.assign( obj, { 'dateFormat' : 'yy-m-d'});
		$(this).datetimepicker(obj);
	});
});

// character count
jQuery(function($){
	$('.dashi_chrcount').each(function(){
		var id = $(this).attr("id");
		var num = $(this).attr("data-dashi_chrcount_num");
		if ( ! num)
		{
			num = 200;
		}
		var partnerCountArea = $('#dashi_chrcount_'+id).attr('id');
		var thisChrLength = $(this).val().length;
		$('#'+partnerCountArea).text((num)-(thisChrLength)+' / '+num);

		$(this).on('keydown keyup keypress change', function(){
			thisChrLength = $(this).val().length;
			var countDown = (num)-(thisChrLength);
			$('#'+partnerCountArea).text(countDown+' / '+num);

			if(countDown < 0){
				$('#'+partnerCountArea).css({color:'#ff0000',fontWeight:'bold'});
				$(this).css({background:'#ffcccc'});
			} else {
				$('#'+partnerCountArea).css({color:'#000000',fontWeight:'normal'});
				$(this).css({background:'#ffffff'});
			}
		});
	});
});

// keep sticky
jQuery(function($) {
	if (typeof(inlineEditPost) != "undefined")
	{
		var $wp_inline_edit = inlineEditPost.edit;

		inlineEditPost.edit = function(id)
		{
			$wp_inline_edit.apply(this, arguments);

			var $post_id = 0;
			if (typeof(id) == 'object')
			{
				$post_id = parseInt(this.getId(id));
			}

			if ($post_id > 0)
			{
				var $sticky_status = $('#dashi_sticky-' + $post_id).html();
				var $edit_row = $('#edit-' + $post_id);
				$('p.inline-edit-save', $edit_row).prepend('<input type="hidden" name="sticky" value="'+$sticky_status+'">');
			}
		};
	}
});

// env check ajax - prepare
// jQuery(function($){
// 	var url = $('#dashi_env_chk').data('dashiAjaxUrl');
// 	$.ajax({
// 		type: 'POST',
// 		url: $('#dashi_env_chk').data('dashiAjaxUrl'),
// 		dataType: 'json',
// 		data: {
// 			'action' : 'dashi_ajax_env_check',
// 		},
// 		beforeSend: function() {
// 		},
// 		success: function(data) {
// 			if ( ! data.data || data.data == 0)
// 			{
// 				$('#dashi_env_chk').remove();
// 			}
// 			else
// 			{
// 				$('#dashi_env_chk').addClass('update-plugins count-1').find('span').text(data.data);
// 			}
// 		},
// 		error:function() {
// 		}
// 	});
// });
