'use strict';
// Real owned-site UI/HTTP acceptance. No profile injection and no conversion.
const fs = require('node:fs');
const cp = require('node:child_process');
const config = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const { chromium } = require(config.playwright);
const checks = [], responses = [];
function check(ok, label) { if (!ok) throw new Error(label); checks.push(label); }
function snapshot() {
  return JSON.parse(cp.execFileSync(config.wpcli, ['--path=' + config.root, '--user=admin', '--skip-themes', 'eval-file', config.nativeTest, 'snapshot'], {
    encoding: 'utf8', timeout: 30000, env: { ...process.env, FOOGM_PROVISION_OWNED_ROOT: config.root, FOOGM_PROVISION_OWNED_DATABASE: config.database }
  }));
}
const equal = (a, b) => JSON.stringify(a) === JSON.stringify(b);
(async () => {
  const browser = await chromium.launch({ headless: true });
  let failure = null;
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    await context.addCookies(config.cookies);
    const page = await context.newPage();
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto(config.url, { waitUntil: 'networkidle' });
    const siteAdmin = await browser.newContext({ ignoreHTTPSErrors: true });
    await siteAdmin.addCookies(config.siteAdminCookies);
    const adminPage = await siteAdmin.newPage(); await adminPage.goto(config.url, { waitUntil: 'networkidle' });
    // Core's first per-blog admin bootstrap may schedule its own cron events.
    // Warm both real admin routes BEFORE freezing equality baselines.
    const otherPage = await context.newPage();
    await otherPage.goto(config.otherPost.replace('admin-post.php', 'options-general.php?page=foogallery-migrate-storage'), { waitUntil: 'networkidle' });
    await otherPage.close();
    check(await adminPage.locator('input[name=operation][value=provision]').count() === 0, 'site-only administrator has no shared provisioning control');
    const initial = snapshot();
    fs.writeFileSync(config.snapshotPrefix + '-before.json', JSON.stringify(initial));
    await page.reload({ waitUntil: 'networkidle' });
    await page.screenshot({ path: config.screenshots + '-loaded.png', fullPage: true });
    check(equal(initial, snapshot()), 'opening real Storage & Cleanup operator route leaves all database rows and DDL unchanged');
    check(await page.locator('h1').innerText() === 'FooGallery Migration Storage', 'actual registered admin route renders');
    const form = page.locator('form.foogm-storage-form').filter({ has: page.locator('input[name=operation][value=provision]') });
    check(await form.count() === 1, 'separate schema-only provisioning control is available without calibration');
    const payload = await form.evaluate(f => Object.fromEntries(new FormData(f)));
    const switchNonce = await page.locator('form.foogm-storage-form').filter({ has: page.locator('input[name=operation][value=switch]') }).locator('input[name=nonce]').inputValue();
    const action = await form.getAttribute('action');
    const denied = async (label, request, data, status, code, endpoint = action) => {
      const before = snapshot(); const r = await request.post(endpoint, { form: data, maxRedirects: 0 });
      const body = await r.json(); responses.push({ label, status: r.status(), body });
      check(r.status() === status && !body.success && body.data.code === code, label + ' returns actual receiver denial');
      check(equal(before, snapshot()), label + ' leaves whole database unchanged');
    };
    await denied('site administrator lacking network authority', siteAdmin.request, payload, 403, 'foogallery_migrate_storage_forbidden');
    for (const [label, data, status, code] of [
      ['forged nonce', { ...payload, nonce: 'invalid' }, 403, 'invalid_nonce'],
      ['wrong-purpose conversion nonce', { ...payload, nonce: switchNonce }, 403, 'invalid_nonce'],
      ['missing confirmation', { ...payload, confirmation: '' }, 400, 'invalid_request'],
      ['wrong scope', { ...payload, scope: 'site' }, 400, 'invalid_request'],
      ['cross-blog identity', { ...payload, blog_id: '2' }, 400, 'invalid_request'],
      ['extra field', { ...payload, policy: 'not-accepted' }, 400, 'invalid_request']
    ]) { await denied(label, context.request, data, status, 'foogallery_migrate_storage_' + code); }
    await denied('main-site nonce replay on second blog', context.request, { ...payload, blog_id: '2' }, 403, 'foogallery_migrate_storage_invalid_nonce', config.otherPost);
    const beforeGet = snapshot();
    const get = await context.request.get(action + '?' + new URLSearchParams(payload), { maxRedirects: 0 });
    check(get.status() === 400 && equal(beforeGet, snapshot()), 'GET cannot provision and leaves database unchanged');
    const anonymous = await browser.newContext({ ignoreHTTPSErrors: true });
    const beforeAnonymous = snapshot();
    const anon = await anonymous.request.post(action, { form: payload, maxRedirects: 0 });
    responses.push({ label: 'unauthenticated framework denial', status: anon.status() });
    check([302, 400, 403].includes(anon.status()) && equal(beforeAnonymous, snapshot()), 'unauthenticated HTTP request cannot reach provisioning or alter database');
    // Native required checkbox is actually operated, not bypassed via a fake endpoint.
    check(!(await form.locator('input[type=checkbox]').isChecked()), 'explicit UI confirmation begins unchecked');
    await form.locator('button').click();
    check(await form.locator('input[type=checkbox]').evaluate(e => !e.validity.valid) && equal(initial, snapshot()), 'unconfirmed browser submit is blocked without database writes');
    await page.screenshot({ path: config.screenshots + '-before.png', fullPage: true });
    let captured;
    await page.route('**/wp-admin/admin-post.php', async route => {
      const response = await route.fetch({ maxRedirects: 0, maxRetries: 0, timeout: 30000 });
      const body = await response.body(); captured = { status: response.status(), body: JSON.parse(body.toString()) };
      await route.fulfill({ response, body });
    });
    await form.locator('input[type=checkbox]').check();
    const response = page.waitForResponse(r => r.url().includes('admin-post.php') && r.request().method() === 'POST');
    await form.locator('button').click(); await response;
    await page.waitForFunction(() => !document.querySelector('input[name=operation][value=provision]'));
    await page.waitForLoadState('networkidle');
    check(captured.status === 200 && captured.body.success && captured.body.data.backend === 'option' && captured.body.data.schema === 'verified', 'actual checked UI POST provisions shared schema without switching authority');
    responses.push({ label: 'confirmed UI provisioning', ...captured });
    const status = await page.locator('dl').innerText();
    check(/Backend\s+option/.test(status) && /Switch phase\s+stable/.test(status) && /Shared schema\s+verified/.test(status), 'post-provision UI shows usable verified schema and stable option authority');
    check(await page.getByText('Not approved for this runtime.', { exact: false }).count() === 1, 'conversion remains uncalibrated; no profile was injected');
    await page.screenshot({ path: config.screenshots + '-after.png', fullPage: true });
    const after = snapshot(), newTables = Object.keys(after).filter(k => !initial[k]);
    fs.writeFileSync(config.snapshotPrefix + '-after.json', JSON.stringify(after));
    check(equal(newTables.sort(), ['wp_foogallery_migrate_legacy_records', 'wp_foogallery_migrate_legacy_state']), 'HTTP provisioning creates only the fixed owned shared schema');
    for (const table of Object.keys(initial)) {
      if (table === 'wp_options') continue;
      check(equal(initial[table], after[table]), 'HTTP provisioning preserves unrelated/cross-blog table: ' + table);
    }
    check(errors.length === 0, 'browser has no JavaScript errors');
    console.log(JSON.stringify({ status: 'pass', checks, check_count: checks.length, responses, database_equality: 'all rows and DDL; only allocator AUTO_INCREMENT excluded', conversion: 'NOT RUN', calibration: 'none' }));
  } catch (e) { failure = e; console.log(JSON.stringify({ status: 'fail', failure: e.stack, checks, responses })); }
  finally { await browser.close(); }
  if (failure) process.exitCode = 1;
})().catch(e => { console.error(e.stack); process.exitCode = 1; });
