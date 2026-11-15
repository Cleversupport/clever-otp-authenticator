/* global OTPA, window */
jQuery(document).ready(function($) {
	var handle = $('#otpa_send_code'),
		email = $('#otpa_id_widget'),
		originalContent = handle.html(),
		countdownEnd = 0,
		countdownTimer = null;

	function validEmail() {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.val());
	}

	function updateButtonState() {
		handle.prop('disabled', countdownEnd > Date.now() || !validEmail());
	}

	function updateCountdown() {
		var remaining = Math.max(0, Math.ceil((countdownEnd - Date.now()) / 1000)),
			minutes = Math.floor(remaining / 60),
			seconds = remaining % 60;

		if (remaining > 0) {
			handle.html((minutes < 10 ? '0' : '') + minutes + ':' + (seconds < 10 ? '0' : '') + seconds);
			handle.prop('disabled', true);
		} else {
			window.clearInterval(countdownTimer);
			countdownTimer = null;
			countdownEnd = 0;
			handle.html(originalContent);
			updateButtonState();
		}
	}

	function startCountdown(duration) {
		window.clearInterval(countdownTimer);
		duration = Number(duration);
		countdownEnd = Date.now() + (isFinite(duration) && duration > 0 ? duration * 1000 : 0);
		updateCountdown();

		if (countdownEnd > 0) {
			countdownTimer = window.setInterval(updateCountdown, 250);
		}
	}

	email.on('input change keyup', updateButtonState);

	handle.on('click', function(e) {
		e.preventDefault();

		if (countdownEnd > Date.now() || !validEmail()) {
			updateButtonState();
			return;
		}

		startCountdown(OTPA.otp_code_request_throttle);
		window.requestOTPCode();
	});

	// Recalculate from the deadline after a background tab or device resumes.
	$(document).on('visibilitychange', function() {
		if (countdownEnd > 0) {
			updateCountdown();
		}
	});
	$(window).on('focus pageshow', function() {
		if (countdownEnd > 0) {
			updateCountdown();
		}
	});

	startCountdown(OTPA.otp_code_request_wait);
});
