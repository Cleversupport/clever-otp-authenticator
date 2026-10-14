// Browser contract tests using isolated tabs, fake time and mocked WordPress responses.
const vm = require('node:vm');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const source = fs.readFileSync(__dirname + '/../js/inactivity.js', 'utf8');
let now = 100000, serverLast = now, ended = false, networkFails = false;
const tabs = [], storage = new Map(), requests = [];
const flush = () => new Promise(resolve => setImmediate(resolve));
function tab() {
	const listeners = {}, windowListeners = {}, elements = {}, timers = [];
	const document = { hidden: false, activeElement: null, addEventListener: (key, fn) => { listeners[key] = fn; } };
	function element(tag) {
		const e = { tag, hidden: false, isConnected: true, children: [], handlers: {}, textContent: '',
			append(...items) { this.children.push(...items); }, setAttribute() {},
			addEventListener(key, fn) { this.handlers[key] = fn; }, focus() { document.activeElement = this; } };
		Object.defineProperty(e, 'id', { set(id) { elements[id] = e; } });
		return e;
	}
	document.createElement = element; document.body = element('body');
	const t = { listeners, windowListeners, document, timers, redirects: [], elements };
	tabs.push(t);
	const context = { document, window: { addEventListener: (key, fn) => { windowListeners[key] = fn; } },
		Date: { now: () => now }, String, JSON, Math, URLSearchParams, AbortController,
		setTimeout: () => 1, clearTimeout() {}, setInterval: fn => timers.push(fn),
		location: { assign: url => t.redirects.push(url) },
		localStorage: { getItem: key => storage.get(key) || null, setItem(key, value) {
			storage.set(key, value); tabs.filter(other => other !== t).forEach(other => {
				if (other.windowListeners.storage) { other.windowListeners.storage({ key, newValue: value }); }
			});
		} },
		otpaInactivity: { key: 'session', timeout: 60, remaining: Math.max(0, 60 - (now - serverLast) / 1000), warning: 30,
			ajax: '/ajax', nonce: 'nonce', redirect: '/otpa/passwordless-login/', fallback: '/secure-logout',
			title: 'Session Expiring Soon', message: 'Warning', countdown: 'Logout in %s', stay: 'Stay Logged In', logout: 'Log Out Securely', failure: 'Retrying' },
		fetch: async (url, options) => {
			const mode = new URLSearchParams(options.body).get('mode'); requests.push(mode);
			if (networkFails) { throw new Error('offline'); }
			let data;
			if (ended || now - serverLast >= 60000) { ended = true; data = { success: false, data: { expired: true } }; }
			else {
				if (mode === 'activity' || mode === 'stay') { serverLast = now; }
				data = { success: true, data: { remaining: (serverLast + 60000 - now) / 1000, nonce: 'renewed' } };
			}
			return { ok: !ended, text: async () => JSON.stringify(data) };
		} };
	vm.runInNewContext(source, context);
	t.tick = async () => { timers[0](); await flush(); };
	t.interact = async () => { listeners.click({ isTrusted: true }); await flush(); };
	t.modal = () => document.body.children[0];
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
	networkFails = true; now += 61000; await a.tick();
	assert.equal(a.redirects.length, 0); assert.match(a.modal().children[0].children[2].textContent, /Retrying/);
	assert.equal(a.modal().children[0].children[4].href, '/secure-logout');
	console.log('PASS: network failure retains warning and secure fallback without assuming logout');
	networkFails = false; now += 5000; await a.tick();
	assert.equal(ended, true); assert.equal(a.redirects.at(-1), '/otpa/passwordless-login/');
	assert.equal(b.redirects.at(-1), '/otpa/passwordless-login/');
	console.log('PASS: sleep/wake expiration and confirmed logout synchronize tabs');
})().catch(error => { console.error(error); process.exitCode = 1; });
