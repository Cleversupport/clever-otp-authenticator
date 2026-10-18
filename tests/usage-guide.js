'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const script = fs.readFileSync('js/admin/usage-guide.js', 'utf8');
async function scenario(clipboard, outcome) {
    let listener, copied, selected = false, focused = false;
    const field = { value: '[otpa_passwordless_login show_logo="yes"]', focus() { focused = true; }, select() { selected = true; } };
    const button = { hidden: true, dataset: { copyTarget: 'example' }, addEventListener(event, callback) { assert.equal(event, 'click'); listener = callback; } };
    const status = { textContent: '', dataset: { copied: 'Copied', manual: 'Select and copy' } };
    const guide = { querySelector() { return status; }, querySelectorAll() { return [button]; } };
    const context = { document: { querySelector() { return guide; }, getElementById(id) { assert.equal(id, 'example'); return field; } }, navigator: {} };
    if (clipboard) { context.navigator.clipboard = { writeText(value) { copied = value; return clipboard(value); } }; }
    vm.runInNewContext(script, context);
    assert.equal(button.hidden, false);
    listener();
    await Promise.resolve();
    assert.equal(status.textContent, outcome === 'success' ? 'Copied' : 'Select and copy');
    if (outcome === 'success') { assert.equal(copied, field.value); assert.equal(focused, false); }
    else { assert.equal(selected, true); assert.equal(focused, true); }
}
(async function () {
    await scenario(() => Promise.resolve(), 'success');
    await scenario(() => Promise.reject(new Error('permission denied')), 'manual');
    await scenario(null, 'manual');
    vm.runInNewContext(script, { document: { querySelector() { return null; } } });
    console.log('PASS: exact clipboard text, success announcement, denied/unavailable fallback, focus/selection and absent guide');
}()).catch(error => { console.error(error); process.exitCode = 1; });
