define(function(require) {
	var elgg = require("elgg");
	var $ = require("jquery");
	var lightbox = require('elgg/lightbox');

	function init() {
		//Seri 8/10/2020 - commented this code block bc elgg-lightbox-photo class
		//can do the work
		/*
		if ($(".tidypics-lightbox").length) {
			$(".tidypics-lightbox").colorbox({
				photo:true,
				maxWidth:'95%',
				maxHeight:'95%'
			});


			$("#cboxOverlay").css("z-index", "10100");
			$("#colorbox").css("z-index", "10101");
		}

		if ($(".tidypics-river-lightbox").length) {
			$(".tidypics-river-lightbox").colorbox({
				maxWidth:'95%',
				maxHeight:'95%',
				onComplete: function() {
					$(this).colorbox.resize();
				}
			});

			$("#cboxOverlay").css("z-index", "10100");
			$("#colorbox").css("z-index", "10101");
		}

		if ($(".tidypics-selectalbum-lightbox").length) {
			$(".tidypics-selectalbum-lightbox").colorbox();

			$("#cboxOverlay").css("z-index", "10100");
			$("#colorbox").css("z-index", "10101");
		}
		*/

		if ($(".tidypics-slideshow-lightbox").length) {
			$(".tidypics-slideshow-lightbox").colorbox({
				width: '95%',
				height: '95%',
				maxWidth: '95%',
				maxHeight: '95%',
				title: false,
				arrowKey: false
			});

			$("#cboxOverlay").css("z-index", "10100");
			$("#colorbox").css("z-index", "10101");
		}

		$("#tidypics-sort").sortable({
			opacity: 0.7,
			revert: true,
			scroll: true
		});

		$('.elgg-form-photos-album-sort').submit(function() {
			var tidypics_guids = [];
			$("#tidypics-sort li").each(function(index) {
				tidypics_guids.push($(this).attr('id'));
			});
			$('input[name="guids"]').val(tidypics_guids.toString());
		});
	}

	init();
});
