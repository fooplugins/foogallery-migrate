'use strict';
// Execute the shared production controller with small React/DOM/HTTP doubles.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('includes/ui/migration-progress.js', 'utf8');
let tree, stopCalls = 0, focusCalls = 0;
const button = { focus() { focusCalls++; } };
const requests = [], handlers = {};
const form = {
    percent: '0', html() { return this; }, serialize() { return 'nonce=valid&gallery=7'; },
    on(event, selector, fn) { handlers[selector] = fn; },
    find(selector) {
        return { length: selector.includes(':checked') ? 1 : 0, prop() { return this; },
            val: () => form.percent, attr: name => name === 'data-queued' ? '1' : '0',
            first() { return this; }, closest() { return this; }, find() { return this; }, text: () => '' };
    }
};
const context = {
    window: { setTimeout: fn => fn() }, document: { activeElement: button, contains: () => true, createElement: () => ({}), body: { appendChild() {} } },
    wp: { element: { createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
        createRoot: () => ({ render(value) { tree = value; } }) },
        components: { Modal: 'Modal', Spinner: 'Spinner', Button: 'Button' },
        i18n: { __: s => s, sprintf: (s, a, b) => s.replace('%1$s', a).replace('%2$s', b) } },
    jQuery: { ajax: options => requests.push(options) }, ajaxurl: '/wp-admin/admin-ajax.php'
};
context.window.foogalleryMigrateHandleAjaxResponse = () => false;
context.window.foogalleryMigrateHandleAjaxError = () => {};
vm.runInNewContext(source, context);
const progress = context.window.foogalleryMigrateProgress;
function nodes(node, type) {
    if (!node || typeof node !== 'object') return [];
    return (node.type === type ? [node] : []).concat(...(node.children || []).map(child => nodes(child, type)));
}
progress.begin('Gallery migration', () => stopCalls++);
assert.equal(tree.type, 'Modal');
assert.equal(tree.props.shouldCloseOnEsc, false);
assert.equal(tree.props.shouldCloseOnClickOutside, false);
assert.equal(nodes(tree, 'div').find(n => n.props.role === 'progressbar').props['aria-valuenow'], undefined);
progress.update(25, 'Gallery A — 25 of 100 images', '0 of 1 finished');
assert.equal(nodes(tree, 'div').find(n => n.props.role === 'progressbar').props['aria-valuenow'], 25);
const stop = nodes(tree, 'Button')[0]; stop.props.onClick(); stop.props.onClick();
assert.equal(stopCalls, 1);
assert.equal(progress.stopRequested(), true);
assert.equal(nodes(tree, 'Button')[0].props.disabled, true);
progress.update(50, 'Next batch');
assert.ok(JSON.stringify(tree).includes('Stopping after the current batch'));
progress.finish('Stopped', true);
assert.equal(tree.props.shouldCloseOnEsc, true);
assert.equal(nodes(tree, 'div').find(n => n.props.role === 'progressbar').props['aria-valuenow'], 50);
nodes(tree, 'Button')[0].props.onClick(); assert.equal(tree, null); assert.equal(focusCalls, 1);
for (const title of ['Gallery migration', 'Album migration', 'Blocks / Shortcodes migration', 'Image Tags migration']) {
    progress.begin(title, () => {}); assert.equal(tree.props.title, title); assert.equal(progress.stopRequested(), false);
    progress.finish(); assert.equal(nodes(tree, 'div').find(n => n.props.role === 'progressbar').props['aria-valuenow'], 100);
    tree.props.onRequestClose();
}
progress.begin('Single request', null); assert.equal(nodes(tree, 'Button').length, 0);
progress.fail('Request failed'); assert.equal(tree.props.shouldCloseOnEsc, true);
assert.ok(JSON.stringify(tree).includes('Request failed'));
const bind = context.window.foogalleryMigrateBindCollection;
bind(form, { title: 'Gallery migration', startButton: 'start', continueButton: 'resume', cancelButton: 'cancel', refreshButton: 'refresh',
    selection: 'selected', progress: 'progress', start: 'start-action', continue: 'continue-action', cancel: 'cancel-action', preflight() {} });
const click = name => handlers[name]({ preventDefault() {} });
click('start'); click('start'); assert.equal(requests.length, 1, 'double click cannot start two writers');
nodes(tree, 'Button')[0].props.onClick();
assert.equal(requests.length, 1, 'stop must wait for in-flight response');
requests[0].success('<form>'); assert.ok(requests[1].data.endsWith('action=cancel-action'));
requests[1].success('<form>'); assert.equal(requests.length, 2, 'stopping never queues a continuation');
assert.ok(JSON.stringify(tree).includes('Completed work is kept'));
click('resume'); form.percent = '100'; requests[2].success('<form>');
assert.equal(requests.length, 3, 'completion does not send an extra continuation');
assert.equal(tree.props.shouldCloseOnEsc, true);
click('resume'); requests[3].error({}, 'failed', 'network');
assert.equal(tree.props.shouldCloseOnEsc, true);
click('resume'); assert.equal(requests.length, 5, 'transport failure releases the request lock');
form.percent = undefined; requests[4].success('<form>');
assert.equal(requests.length, 5, 'missing progress stops instead of polling forever');
for (const tab of ['galleries', 'albums', 'content', 'image-tags']) {
    const php = fs.readFileSync('includes/views/view-migrate-tab-' + tab + '.php', 'utf8');
    const script = php.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, '"test message"');
    new vm.Script(script, { filename: tab + '.js' });
    assert.match(php, /foogalleryMigrate(BindCollection|Progress)/, tab + ' uses the same dialog');
}
// Run the actual content/tag tab scripts, not copies of their polling logic.
function tabFixture(tab) {
    const events = {}, calls = [], timers = [], messages = [];
    let stopped = false;
    const chain = { length: 1, serialize: () => 'nonce=valid',
        find() { return this; }, prop() { return this; }, attr(name, value) { return arguments.length > 1 ? this : '1'; }, text() { return this; },
        hide() { return this; }, show() { return this; }, css() { return this; },
        addClass() { return this; }, removeClass() { return this; }, html() { return this; }, val: () => 'nonce',
        on(event, selector, fn) { events[selector] = fn; } };
    const $ = arg => { if (typeof arg === 'function') arg($); return chain; };
    $.ajax = request => calls.push(request);
    const dialog = { begin(title, stop) { stopped = false; messages.push(['begin', title, stop]); },
        update(...args) { messages.push(['update', ...args]); }, finish(...args) { messages.push(['finish', ...args]); },
        fail(...args) { messages.push(['fail', ...args]); }, stopRequested: () => stopped };
    const php = fs.readFileSync('includes/views/view-migrate-tab-' + tab + '.php', 'utf8');
    const code = php.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, '"test message"');
    vm.runInNewContext(code, { jQuery: $, document: {}, ajaxurl: '/ajax',
        window: { foogalleryMigrateProgress: dialog, foogalleryMigrateHandleAjaxResponse: () => false,
            foogalleryMigrateHandleAjaxError() {}, alert() {}, confirm: () => true, setTimeout: fn => timers.push(fn) } });
    return { events, calls, timers, messages, stop() { stopped = true; }, click(selector) { events[selector].call({}, { preventDefault() {} }); } };
}
for (const selector of ['.refresh_content', '.refresh_content_status']) {
    const tab = tabFixture('content'); tab.click(selector); tab.click(selector);
    assert.equal(tab.calls.length, 1, 'content double click starts one batch');
    tab.stop(); tab.calls[0].success({ success: true, data: { progress: { complete: false, scanned: 100, cursor: 100, total: 200 } } });
    tab.timers.shift()();
    assert.equal(tab.calls.length, 1, 'content stop cannot start a second batch');
    assert.equal(tab.messages.at(-1)[0], 'finish'); assert.equal(tab.messages.at(-1)[2], true);
    tab.click(selector); assert.equal(tab.calls.length, 2, 'content can resume');
    tab.calls[1].success({ success: true, data: { progress: { complete: true, scanned: 200, cursor: 200, total: 200 }, html: '<table></table>' } });
    assert.equal(tab.messages.at(-1)[0], 'finish'); assert.equal(tab.timers.length, 0);
}
const replace = tabFixture('content'); replace.click('.replace_content');
assert.equal(replace.messages[0][2], null, 'single replacement request cannot pretend to stop mid-write');
replace.calls[0].success('<table></table>'); assert.equal(replace.messages.at(-1)[0], 'finish');
const tags = tabFixture('image-tags'); tags.click('.foogallery-migrate-image-tags-start'); tags.click('.foogallery-migrate-image-tags-start');
assert.equal(tags.calls.length, 1); assert.equal(tags.calls[0].data.reset, 1);
tags.stop(); tags.calls[0].success({ success: true, data: { complete: false, total: 100, processed: 50, progress: 50 } });
assert.equal(tags.calls.length, 1, 'tag stop waits for the batch and ends polling');
assert.equal(tags.messages.at(-1)[2], true);
tags.click('.foogallery-migrate-image-tags-start'); assert.equal(tags.calls[1].data.reset, 0, 'stopped tag sync resumes its existing queue');
tags.calls[1].success({ success: true, data: { complete: true, total: 100, processed: 100, progress: 100 } });
assert.equal(tags.messages.at(-1)[0], 'finish');
console.log('PASS: shared dialog lifecycle, all four tabs, serial stop, completion, resume and failure regression checks');
