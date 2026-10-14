// Browser contract tests using isolated tabs, fake time and mocked WordPress responses.
const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../js/inactivity.js', 'utf8');
let now = 100000, serverLast = now, ended = false, networkFails = false;
const channels = [];
const tabs = [], storage = new Map(), requests = [];
const flush = () => new Promise(resolve => setImmediate(resolve));
function tab(options = {}) {
	const listeners = {}, windowListeners = {}, elements = {}, timers = [];
	const document = { hidden: false, activeElement: null, addEventListener: (key, fn) => { listeners[key] = fn; } };
	function element(tag) {
		const e = { tag, hidden: false, isConnected: true, children: [], handlers: {}, textContent: '',
			append(...items) { this.children.push(...items); }, contains(target) { return target === this || this.children.some(child => child.contains(target)); }, setAttribute() {},
			addEventListener(key, fn) { this.handlers[key] = fn; }, focus() { document.activeElement = this; } };
		Object.defineProperty(e, 'id', { set(id) { elements[id] = e; } });
		return e;
	}
	document.createElement = element; document.body = element('body');
	const t = { hold: false, waiting: [], raw: null, listeners, windowListeners, document, timers, redirects: [], elements };
	tabs.push(t);
	const context = { document, window: { addEventListener: (key, fn) => { windowListeners[key] = fn; } },
		Date: { now: () => now }, String, JSON, Math, URLSearchParams, AbortController,
		BroadcastChannel: class {
			constructor(name) { this.name = name; channels.push(this); }
			postMessage(data) { channels.filter(channel => channel !== this && channel.name === this.name).forEach(channel => { if (channel.onmessage) { channel.onmessage({ data }); } }); }
		},
		setTimeout: () => 1, clearTimeout() {}, setInterval: fn => timers.push(fn),
		location: { assign: url => t.redirects.push(url) },
		localStorage: { getItem: key => { if (options.noStorage) { throw new Error('storage blocked'); } return storage.get(key) || null; }, setItem(key, value) {
			if (options.noStorage) { throw new Error('storage blocked'); }
			storage.set(key, value); tabs.filter(other => other !== t).forEach(other => {
				if (other.windowListeners.storage) { other.windowListeners.storage({ key, newValue: value }); }
			});
		} },
		otpaInactivity: { key: 'session', timeout: 60, remaining: Math.max(0, 60 - (now - serverLast) / 1000), warning: 30,
			ajax: '/ajax', nonce: 'nonce', redirect: '/otpa/passwordless-login/', fallback: '/secure-logout',
			title: 'Session Expiring Soon', message: 'Warning', countdown: 'Logout in %s', stay: 'Stay Logged In', logout: 'Log Out Securely', failure: 'Retrying' },
		fetch: async (url, options) => {
			const input = new URLSearchParams(options.body);
			const mode = input.get('mode'); requests.push(mode);
			if (networkFails) { throw new Error('offline'); }
			let data;
			if (ended || now - serverLast >= 60000) { ended = true; data = { success: false, data: { expired: true } }; }
			else {
				if (mode === 'activity' || mode === 'stay') { serverLast = Math.max(serverLast, mode === 'stay' ? now : now - Number(input.get('idle_ms'))); }
				data = { success: true, data: { remaining: (serverLast + 60000 - now) / 1000, nonce: 'renewed' } };
			}
			const response = { ok: !ended, text: async () => t.raw === null ? JSON.stringify(data) : t.raw };
			if (t.hold) { return new Promise(resolve => t.waiting.push(() => resolve(response))); }
			return response;
		} };
	vm.runInNewContext(source, context);
	t.tick = async () => { timers[0](); await flush(); };
	t.interact = async () => { listeners.click({ isTrusted: true }); await flush(); };
	t.modal = () => document.body.children[0];
	t.release = async () => { t.hold = false; t.waiting.splice(0).forEach(release => release()); await flush(); };
	return t;
}
(async () => {
	const a = tab(), b = tab(); await flush();
	assert.deepEqual(requests, ['status', 'status']); assert.equal(serverLast, 100000);
	console.log('PASS: loading/polling does not renew session');
	now += 31000; await a.tick();
	assert.equal(a.modal().hidden, false); assert.match(a.modal().children[0].children[2].textContent, /0:29/);
	now += 1000; await a.tick(); assert.match(a.modal().children[0].children[2].textContent, /0:28/);
	console.log('PASS: warning threshold and one-second countdown');
	await b.interact(); assert.equal(a.modal().hidden, true); assert.equal(serverLast, now);
	console.log('PASS: activity in another tab renews server and closes warning');
	now += 31000; await a.tick();
	a.modal().children[0].children[3].handlers.click(); await flush();
	assert.equal(a.modal().hidden, true); assert.equal(requests.at(-1), 'stay');
	console.log('PASS: Stay Logged In verifies and renews session');
	now += 20000; await b.interact();
	const activeAt = serverLast;
	now += 5000; await a.tick(); assert.equal(ended, false); assert.equal(serverLast, activeAt);
	console.log('PASS: hidden/idle tab cannot expire active tab; ticks do not count as activity');
	// A request in flight must not discard an event occurring at the same clock timestamp.
	now += 11000; b.hold = true;
	b.listeners.visibilitychange(); await flush();
	await b.interact();
	await b.release();
	now += 10001; await b.tick();
	assert.equal(requests.at(-1), 'activity');
	assert.equal(serverLast, now - 10001);
	console.log('PASS: busy status preserves same-timestamp interaction and its age');
	// A stale response cannot overwrite a newer BroadcastChannel activity when storage is blocked.
	const c = tab({ noStorage: true }); await flush();
	now += 11000; c.hold = true; c.listeners.visibilitychange(); await flush();
	now += 2000; await b.interact(); await c.release();
	assert.equal(c.redirects.length, 0);
	now += 31000; await c.tick();
	assert.match(c.modal().children[0].children[2].textContent, /0:29/);
	console.log('PASS: delayed status preserves newer BroadcastChannel-only interaction');
	// Stay clicked during a status request must be queued, and countdown must keep updating.
	c.hold = true; c.listeners.visibilitychange(); await flush();
	c.modal().children[0].children[3].handlers.click();
	now += 1000; await c.tick();
	assert.match(c.modal().children[0].children[2].textContent, /0:28/);
	await c.release();
	assert.equal(requests.at(-1), 'stay'); assert.equal(c.modal().hidden, true);
	console.log('PASS: busy renewal is queued and countdown updates while requests are pending');
	// Bare WordPress 0 is not proof that authentication was invalidated.
	c.raw = '0'; c.listeners.visibilitychange(); await flush();
	assert.equal(c.redirects.length, 0); assert.match(c.modal().children[0].children[2].textContent, /Retrying/);
	c.raw = null;
	console.log('PASS: missing AJAX action is handled as failure, never confirmed logout');

	networkFails = true; now += 61000; await a.tick();
	assert.equal(a.redirects.length, 0); assert.match(a.modal().children[0].children[2].textContent, /Retrying/);
	assert.equal(a.modal().children[0].children[4].href, '/secure-logout');
	console.log('PASS: network failure retains warning and secure fallback without assuming logout');
	networkFails = false; now += 5000; await a.tick();
	assert.equal(ended, true); assert.equal(a.redirects.at(-1), '/otpa/passwordless-login/');
	assert.equal(b.redirects.at(-1), '/otpa/passwordless-login/');
	console.log('PASS: sleep/wake expiration and confirmed logout synchronize tabs');
	// Confirmation of logout is terminal, even if an older success arrives afterward.
	tabs.length = 0; channels.length = 0; storage.clear(); ended = false; networkFails = false;
	now = 1000000; serverLast = now;
	const d = tab(), e = tab(); await flush();
	d.hold = true; d.listeners.visibilitychange(); await flush();
	now += 61000; await e.tick(); await d.release();
	assert.equal(JSON.parse(storage.get('session')).logout, true);
	assert.equal(d.redirects.at(-1), '/otpa/passwordless-login/');
	console.log('PASS: late successful response cannot undo a confirmed cross-tab logout');
})().catch(error => { console.error(error); process.exitCode = 1; });
