(function () {
	'use strict';
	var guide = document.querySelector('.otpa-usage-guide');
	if (!guide) { return; }
	var status = guide.querySelector('[role="status"]');
	guide.querySelectorAll('.otpa-guide-copy').forEach(function (button) {
		button.hidden = false;
		button.addEventListener('click', function () {
			var field = document.getElementById(button.dataset.copyTarget);
			if (!field) { return; }
			status.textContent = '';
			function manualCopy() {
				field.focus();
				field.select();
				status.textContent = status.dataset.manual;
			}
			if (!navigator.clipboard || !navigator.clipboard.writeText) {
				manualCopy();
				return;
			}
			navigator.clipboard.writeText(field.value).then(function () {
				status.textContent = status.dataset.copied;
			}, manualCopy);
		});
	});
}());
