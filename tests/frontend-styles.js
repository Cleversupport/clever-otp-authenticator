'use strict';

// Dependency-free DOM/jQuery boundary doubles. Run the complete production
// initialization and verify it leaves stylesheet nodes and their rules intact.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function checkStyles(source, standalone) {
    const styles = [
        { id: 'otpa-main-style-css', css: '.otpa-wrapper { display: none; }' },
        { id: 'dashicons-css', css: '.dashicons { font-family: dashicons; }' },
        { id: 'jet-popup-frontend-css', css: '.jet-popup--hide-state { visibility: hidden; }' },
        { id: 'to-top-css', css: '#to_top_scrollup { position: fixed; }' },
        { id: 'wp-block-library-inline-css', css: '.screen-reader-text { clip-path: inset(50%); }' },
        { id: 'wpml-legacy-horizontal-list-0-css', css: '.wpml-ls ul { display: flex; }' },
        { id: 'ea11y-widget-fonts-css', css: '.accessibility-widget { font-family: sans-serif; }' },
        { id: 'hello-elementor-css', css: 'body { margin: 0; }' },
        { id: 'wp-custom-css', css: '.custom-modal { display: none; }' },
        { css: '.anonymous-style { color: green; }' },
    ];
    const before = JSON.stringify(styles);
    const document = {};
    const handlers = {};
    const window = {
        location: { search: '' },
        addEventListener(name, callback) { handlers[name] = callback; },
        dispatchEvent(event) { if (handlers[event.type]) handlers[event.type](); },
    };
    let wrapperShown = false;
    function collection(selector) {
        return {
            length: 0,
            hasClass(name) { return standalone && name === 'otpa-page'; },
            each(callback) { styles.slice().forEach((style, i) => callback(i, style)); },
            attr(name) { return typeof selector === 'object' ? selector[name] : undefined; },
            remove() { styles.splice(styles.indexOf(selector), 1); },
            fadeIn() { wrapperShown = true; return this; },
            on() { return this; },
        };
    }
    function jQuery(selector) {
        const result = collection(selector);
        result.ready = callback => callback(jQuery);
        return result;
    }
    const context = {
        jQuery, document, window, OTPA: {},
        CustomEvent: function(type) { this.type = type; },
    };
    vm.runInNewContext(source, context);
    assert.strictEqual(JSON.stringify(styles), before, 'all stylesheet nodes and rules must survive OTP initialization');
    assert(wrapperShown, 'OTP form is still revealed');
    assert.strictEqual(typeof window.requestOTPCode, 'function', 'OTP request interface is initialized');
    assert.strictEqual(typeof handlers.requestOTPCode, 'function', 'OTP request listener is registered');
}

for (const file of ['main.js', 'main.min.js']) {
    const source = fs.readFileSync(path.join(__dirname, '../js', file), 'utf8');
    checkStyles(source, true);
    checkStyles(source, false);
    console.log(file + ': external styles preserved on standalone and embedded OTP pages');
}
