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
	if (typeof $.fn.datetimepicker !== "function") return;
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
