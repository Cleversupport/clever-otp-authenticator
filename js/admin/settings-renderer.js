jQuery(document).ready(function($) {
	
	if ( $('.otpa-color-picker').length ) {
		$('.otpa-color-picker').wpColorPicker({
			change: function(event, ui) {
				$(event.target).val(ui.color.toString()).trigger('change');
			},
			clear: function(event) {
				$(event.target).val('').trigger('change');
			}
		});
	}

	$('#otpa_send_code_button_background_color, #otpa_send_code_button_background_hover_color, #otpa_send_code_button_text_color, #otpa_send_code_button_text_hover_color').on('change input', updateSendCodeButtonPreview);
	$('.otpa-form-settings-preview iframe').on('load', updateSendCodeButtonPreview);

	function updateSendCodeButtonPreview() {
		var iframe = $('.otpa-form-settings-preview iframe');

		if (! iframe.length || ! iframe[0].contentDocument) {
			return;
		}

		var backgroundColor      = $('#otpa_send_code_button_background_color').val(),
			backgroundHoverColor = $('#otpa_send_code_button_background_hover_color').val(),
			textColor            = $('#otpa_send_code_button_text_color').val(),
			textHoverColor       = $('#otpa_send_code_button_text_hover_color').val(),
			css                  = '#otpa_send_code{border-radius:10px;}';

		if (backgroundColor) {
			css += '#otpa_send_code{background-color:' + backgroundColor + ';}';
		}

		if (backgroundHoverColor) {
			css += '#otpa_send_code:hover{background-color:' + backgroundHoverColor + ';}';
		}

		if (textColor) {
			css += '#otpa_send_code,#otpa_send_code *{color:' + textColor + ';}';
		}

		if (textHoverColor) {
			css += '#otpa_send_code:hover,#otpa_send_code:hover *{color:' + textHoverColor + ';}';
		}

		$(iframe[0].contentDocument).find('#otpa-send-code-button-preview-style').remove();
		$(iframe[0].contentDocument).find('head').append('<style id="otpa-send-code-button-preview-style">' + css + '</style>');
	}

	$('.otpa-media-select').click(function(e) {
		e.preventDefault();

		var imageFrame,
			field            = $(this).parent(),
			valueHolder      = field.find('input[type="hidden"]'),
			removeButton     = field.find('.otpa-media-reset'),
			previewHolder    = field.find('.otpa-style-preview-image'),
			previewContainer = field.find('.otpa-style-preview-image-container');

		if (imageFrame) {
			imageFrame.open();
		}

		imageFrame = wp.media({
			multiple : false,
			library  : {
				type : 'image',
			}
		});

		imageFrame.on('close',function() {
			var selection  = imageFrame.state().get('selection'),
				galleryIDs = [],
				index      = 0;

			selection.each(function(attachment) {
				 galleryIDs[index] = attachment.id;
				 index++;
			});

			valueHolder.val(selection.models[0].attributes.url);
			previewHolder.attr('src', selection.models[0].attributes.url);
			removeButton.removeClass('hidden');
			previewContainer.removeClass('empty');
		});

		imageFrame.on('open',function() {
			var selection = imageFrame.state().get('selection'),
				ids       = valueHolder.val().split(',');

			ids.forEach(function(id) {
				var attachment = wp.media.attachment(id);

				attachment.fetch();
				selection.add( attachment ? [ attachment ] : [] );
			});

		});

		imageFrame.open();
	});

	$('.otpa-media-reset').on('click', function(e) {
		e.preventDefault();

		var field            = $(this).parent(),
			valueHolder      = field.find('input[type="hidden"]'),
			removeButton     = field.find('.otpa-media-reset'),
			previewHolder    = field.find('.otpa-style-preview-image'),
			previewContainer = field.find('.otpa-style-preview-image-container');

		previewContainer.addClass('empty');
		valueHolder.val('');
		previewHolder.attr('src', '');
		removeButton.addClass('hidden');
	});

});
