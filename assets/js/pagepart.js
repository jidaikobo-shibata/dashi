jQuery(function($)
{

	$('div.dashi_pagepart_wrapper a.edit_link').hover(
		function(){
			$(this).parent().addClass('outline_hover');
		},
		function(){
			$(this).parent().removeClass('outline_hover');
		}
	);

	$('div.dashi_pagepart_wrapper a.edit_link').focus(
		function(){
			$(this).parent().addClass('outline_hover');
		}
	).blur(
		function(){
			$(this).parent().removeClass('outline_hover');
		}
	);

});
