'use strict';
// Execute the actual rendered script. These DOM/HTTP doubles are not native SQL proof.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
let checks = 0;
function fixture(overrides = {}) {
  const status = { backend: 'option', phase: 'stable', target: 'option', retained: false, option_bytes: '0', records: null, schema: 'not ready', problem: '', calibrated: true, ...overrides };
  const php = 'require "tests/bootstrap.php"; $GLOBALS["foogallery_migrate_test_capabilities"]=array("manage_options"=>true); $GLOBALS["foogallery_migrate_test_blog_id"]=7; FooPlugins\\FooGalleryMigrate\\StorageAdmin::render(json_decode($argv[1],true));';
  const rendered = spawnSync('php', ['-r', php, JSON.stringify(status)], { encoding: 'utf8' });
  assert.equal(rendered.status, 0, rendered.stderr);
  const html = rendered.stdout;
  const forms = [...html.matchAll(/<form class="foogm-storage-form"([^>]*)>([\s\S]*?)<\/form>/g)].map(match => {
    const attrs = Object.fromEntries([...match[1].matchAll(/([\w-]+)="([^"]*)"/g)].map(m => [m[1], m[2]]));
    const fields = Object.fromEntries([...match[2].matchAll(/<input type="hidden" name="([^"]+)" value="([^"]*)">/g)].map(m => [m[1], m[2]]));
    return { attrs, fields, button: { disabled: false }, getAttribute(name) { return this.attrs[name] || null; }, querySelector(name) { assert.equal(name, 'button'); return this.button; }, addEventListener(name, listener) { assert.equal(name, 'submit'); this.submit = () => listener.call(this, { preventDefault() {} }); } };
  });
  const requests = [], result = { textContent: '' };
  let reloads = 0;
  class FormData {
    constructor(form) { this.fields = { ...form.fields }; }
    set(key, value) { this.fields[key] = value; }
    get(key) { return this.fields[key]; }
  }
  class XMLHttpRequest {
    open(method, url) { assert.equal(method, 'POST'); assert.match(url, /admin-post.php$/); }
    send(data) { this.fields = data.fields; requests.push(this); }
    respond(data, status = 200) { this.status = status; this.responseText = typeof data === 'string' ? data : JSON.stringify(data); this.onload(); }
  }
  const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
  vm.runInNewContext(script, { document: { querySelectorAll() { return forms; }, getElementById() { return result; } }, window: { location: { reload() { reloads++; } } }, FormData, XMLHttpRequest });
  return { forms, requests, result, get reloads() { return reloads; }, start(operation = 'switch') { const form = forms.find(f => f.fields.operation === operation); assert.ok(form); form.submit(); return form; } };
}
const okay = data => ({ success: true, data });
const receipt = (phase, backend = 'option') => okay({ blog_id: 7, backend, epoch: 1, phase });
function checkRequest(request, operation, purpose, scope = 'site') {
  assert.equal(request.fields.operation, operation);
  assert.equal(request.fields.scope, scope);
  assert.equal(Object.keys(request.fields).length, 7);
  assert.deepEqual(Object.keys(request.fields).sort(), ['action', 'blog_id', 'confirmation', 'nonce', 'operation', 'scope', 'target']);
  if (purpose) assert.equal(request.fields.nonce, 'nonce-foogallery_migrate_storage_' + purpose + '_7');
  checks++;
}
{
  const f = fixture(); assert.equal(f.requests.length, 0, 'Rendering never starts work');
  f.start(); checkRequest(f.requests[0], 'preflight', 'option_import');
  assert.ok(f.forms.every(form => form.button.disabled));
  f.forms[0].submit(); assert.equal(f.requests.length, 1, 'Concurrent cleanup is blocked');
  f.requests[0].respond(okay({ decode_admitted: true })); checkRequest(f.requests[1], 'provision', 'provision_schema', 'installation');
  f.requests[1].respond(okay({ schema: 'verified', backend: 'option' })); checkRequest(f.requests[2], 'switch');
  for (const [index, phase, backend] of [[2, 'copying', 'option'], [3, 'verified', 'option'], [4, 'flipped', 'table'], [5, 'stable', 'table']]) {
    f.requests[index].respond(receipt(phase, backend));
    if (phase !== 'stable') checkRequest(f.requests[index + 1], 'recover', 'recover');
  }
  assert.equal(f.reloads, 1); assert.equal(f.requests.length, 6); checks++;
}
for (const [overrides, operation, target] of [[{ schema: 'verified' }, 'switch', 'table'], [{ schema: 'verified', retained: true }, 'replace', 'table'], [{ schema: 'verified', backend: 'table', target: 'table' }, 'switch', 'option'], [{ schema: 'verified', phase: 'copying', target: 'table', discardable: true }, 'recover', 'table']]) {
  const f = fixture(overrides); f.start(operation);
  let i = 0;
  if (target === 'table' && operation !== 'recover') {
    checkRequest(f.requests[i], 'preflight', 'option_import'); f.requests[i++].respond(okay({ decode_admitted: true }));
  }
  checkRequest(f.requests[i], operation);
  assert.equal(f.requests[i].fields.target, target);
  assert.ok(!f.requests.some(r => r.fields.operation === 'provision'));
  f.requests[i].respond(receipt('stable', target)); assert.equal(f.reloads, 1); checks++;
}
for (const failAt of [0, 1, 2, 3]) {
  for (const failure of ['denial', 'timeout', 'network', 'html', 'malformed', 'http-error', 'unexpected-phase', 'wrong-site', 'wrong-backend']) {
    const f = fixture(); f.start();
    for (let i = 0; i < failAt; i++) f.requests[i].respond(i === 0 ? okay({ decode_admitted: true }) : i === 1 ? okay({ schema: 'verified', backend: 'option' }) : receipt('copying'));
    const r = f.requests[failAt];
    if (failure === 'denial') r.respond({ success: false, data: { message: 'Refused.' } }, 409);
    else if (failure === 'timeout') r.ontimeout();
    else if (failure === 'network') r.onerror();
    else if (failure === 'html') r.respond('<html>Proxy failure</html>');
    else if (failure === 'malformed') r.respond({ success: true });
    else if (failure === 'http-error') r.respond(okay({}), 500);
    else if (failAt < 2) { r.respond({ success: false, data: { message: 'Refused.' } }, 409); }
    else if (failure === 'unexpected-phase') r.respond(receipt('unknown'));
    else if (failure === 'wrong-site') r.respond(okay({ blog_id: 8, phase: 'copying', backend: 'option' }));
    else r.respond(receipt('stable', 'option'));
    assert.equal(f.requests.length, failAt + 1, failure + ' must stop the sequence');
    assert.equal(f.reloads, 0); assert.ok(f.result.textContent.length); checks++;
  }
}
{
  const f = fixture(); f.start(); f.requests[0].respond(okay({})); f.requests[1].respond(okay({}));
  for (let i = 2; i < 258; i++) f.requests[i].respond(receipt('copying'));
  assert.equal(f.requests.length, 258); assert.equal(f.reloads, 0); assert.match(f.result.textContent, /saved its progress/); checks++;
}
console.log('PASS: ' + checks + ' rendered storage workflow checks (DOM/HTTP doubles).');
