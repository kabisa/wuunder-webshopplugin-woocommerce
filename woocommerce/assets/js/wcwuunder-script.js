jQuery(document).ready(function($) {
	var url
	
	$("#doaction, #doaction2").click(function (event) {
		var actionselected = $(this).attr("id").substr(2);
		if ( $('select[name="' + actionselected + '"]').val() == "wcflespakket") {
			event.preventDefault();
			var checked = [];
			$('tbody th.check-column input[type="checkbox"]:checked').each(
				function() {
					checked.push($(this).val());
				}
			);
			
			var order_ids=checked.join('x');
			
			var H = $(window).height()-120;

			url = 'edit.php?&action=wcflespakket&order_ids='+order_ids+'&TB_iframe=true&height='+H+'&width=720';

			// disable background scrolling
			$("body").css({ overflow: 'hidden' })
		
			tb_show('', url);
		}

		if ( $('select[name="' + actionselected + '"]').val() == "wcflespakket-label") {
			event.preventDefault();
			var checked = [];
			$('tbody th.check-column input[type="checkbox"]:checked').each(
				function() {
					checked.push($(this).val());
				}
			);
			
			var order_ids=checked.join('x');
			url = 'edit.php?&action=wcflespakket-label&order_ids='+order_ids;
			
			window.location.href = url;
		}
	});

	// click print button
	$('.one-flespakket').on('click', function(event) {
		event.preventDefault();
		var url = $(this).attr('href');

		// disable background scrolling
		$("body").css({ overflow: 'hidden' })

		var H = $(window).height()-120;
		tb_show('', url + '&TB_iframe=true&width=720&height='+H);
	});

	$(window).bind('tb_unload', function() {
		// re-enable scrolling after closing thickbox
		// (not really needed since page is reloaded in the next step, but applied anyway)
		$("body").css({ overflow: 'inherit' })

		// reload page
		window.location.reload()
	});
	
});