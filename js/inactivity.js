/* global otpaInactivity */
(function () {
	'use strict';
	var c = otpaInactivity, deadline = Date.now() + c.remaining * 1000;
	var channel, dialog, counter, button, previousFocus, pending = false, busy = false;
	var lastEvent = 0, lastReport = 0, retryAt = 0, ended = false;
	var serverDeadline = deadline;
	try { channel = new BroadcastChannel(c.key); } catch (e) { /* Storage remains available. */ }
	function read() {
		try { return JSON.parse(localStorage.getItem(c.key)); } catch (e) { return null; }
	}
	function receive(state) {
		if (!state || typeof state.deadline !== 'number') { return; }
		if (state.logout) { ended = true; location.assign(c.redirect); return; }
		deadline = state.deadline;
		if (deadline - Date.now() > c.warning * 1000) { close(); }
	}
	function publish(logout) {
		var state = { deadline: deadline, activityAt: lastEvent, logout: !!logout };
		try { localStorage.setItem(c.key, JSON.stringify(state)); } catch (e) { /* BroadcastChannel fallback. */ }
		if (channel) { channel.postMessage(state); }
	}
	// Do not renew on page load. Reconcile with the server, which is authoritative.
	var shared = read();
	if (shared && shared.logout) { location.assign(c.redirect); return; }
	if (shared && shared.deadline > deadline) { deadline = shared.deadline; }
	if (channel) { channel.onmessage = function (event) { receive(event.data); }; }
	window.addEventListener('storage', function (event) {
		if (event.key === c.key && event.newValue) {
			try { receive(JSON.parse(event.newValue)); } catch (e) { /* Invalid state ignored. */ }
		}
	});
	function close() {
		if (!dialog || dialog.hidden) { return; }
		dialog.hidden = true;
		if (previousFocus && previousFocus.isConnected) { previousFocus.focus(); }
	}
	function show() {
		if (!dialog) {
			dialog = document.createElement('div');
			dialog.className = 'otpa-idle-overlay';
			dialog.hidden = true;
			var panel = document.createElement('section');
			panel.className = 'otpa-idle-modal';
			panel.setAttribute('role', 'alertdialog');
			panel.setAttribute('aria-modal', 'true');
			panel.setAttribute('aria-labelledby', 'otpa-idle-title');
			panel.setAttribute('aria-describedby', 'otpa-idle-message');
			var title = document.createElement('h2'); title.id = 'otpa-idle-title'; title.textContent = c.title;
			var message = document.createElement('p'); message.id = 'otpa-idle-message'; message.textContent = c.message;
			counter = document.createElement('p'); counter.setAttribute('role', 'timer');
			button = document.createElement('button'); button.type = 'button'; button.textContent = c.stay;
			button.addEventListener('click', function () { pending = false; request('stay'); });
			var fallback = document.createElement('a'); fallback.href = c.fallback; fallback.textContent = c.logout;
			panel.append(title, message, counter, button, fallback); dialog.append(panel); document.body.append(dialog);
			document.addEventListener('focusin', function (event) {
				if (!dialog.hidden && !dialog.contains(event.target)) { button.focus(); }
			});
			dialog.addEventListener('keydown', function (event) {
				if (event.key === 'Tab') {
					if (event.shiftKey && document.activeElement === button) { event.preventDefault(); fallback.focus(); }
					else if (!event.shiftKey && document.activeElement === fallback) { event.preventDefault(); button.focus(); }
				}
			});
		}
		if (dialog.hidden) { previousFocus = document.activeElement; dialog.hidden = false; button.focus(); }
	}
	function failed() {
		retryAt = Date.now() + 5000;
		show(); counter.textContent = c.failure; button.disabled = false;
	}
	function request(mode) {
		if (busy || ended) { return; }
		busy = true;
		var started = Date.now();
		var controller = new AbortController(), abort = setTimeout(function () { controller.abort(); }, 10000);
		fetch(c.ajax, { method: 'POST', credentials: 'same-origin', signal: controller.signal,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({ action: 'otpa_inactivity', nonce: c.nonce, mode: mode, idle_ms: mode === 'activity' ? Math.max(0, Date.now() - lastEvent) : 0 }).toString()
		}).then(function (response) {
			return response.text().then(function (body) {
				var data;
				try { data = JSON.parse(body); } catch (e) { data = null; }
				if (data && data.data && data.data.expired) {
					ended = true; publish(true); location.assign(c.redirect); return;
				}
				// WordPress returns 0 when the authenticated AJAX action no longer exists for this visitor.
				if (body.trim() === '0') { ended = true; publish(true); location.assign(c.redirect); return; }
				if (!response.ok || !data || !data.success || typeof data.data.remaining !== 'number') { throw new Error('verification'); }
				c.nonce = data.data.nonce;
				serverDeadline = Date.now() + data.data.remaining * 1000;
				var latest = read();
				deadline = serverDeadline;
				if (lastEvent > started) { deadline = lastEvent + c.timeout * 1000; pending = true; }
				if (latest && latest.activityAt > started) { deadline = Math.max(deadline, latest.deadline); }
				lastReport = Date.now(); retryAt = 0;
				publish(false);
				if (mode === 'stay' || deadline - Date.now() > c.warning * 1000) { close(); }
			});
		}).catch(failed).finally(function () { clearTimeout(abort); busy = false; });
	}
	function activity(event) {
		if (!event.isTrusted || ended || (dialog && !dialog.hidden) || Date.now() >= deadline) { return; }
		var now = Date.now();
		if (now - lastEvent < 500) { return; }
		lastEvent = now; deadline = now + c.timeout * 1000; pending = true; publish(false);
		if (now - lastReport >= 10000 || serverDeadline - now <= 15000) { pending = false; request('activity'); }
	}
	['click', 'keydown', 'touchstart', 'scroll', 'pointerdown'].forEach(function (name) {
		document.addEventListener(name, activity, { passive: true, capture: true });
	});
	function tick() {
		if (ended || busy) { return; }
		var now = Date.now();
		if (retryAt) { if (now >= retryAt) { request('status'); } return; }
		if (pending && now - lastReport >= 10000) { pending = false; request('activity'); return; }
		var remaining = Math.max(0, Math.ceil((deadline - now) / 1000));
		if (!remaining) { request('status'); return; }
		if (c.warning && remaining <= c.warning) {
			show(); counter.textContent = c.countdown.replace('%s', Math.floor(remaining / 60) + ':' + String(remaining % 60).padStart(2, '0'));
		}
	}
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) { receive(read()); request('status'); }
	});
	window.addEventListener('pageshow', function (event) { if (event.persisted) { request('status'); } });
	setInterval(tick, 1000);
	request('status');
}());
