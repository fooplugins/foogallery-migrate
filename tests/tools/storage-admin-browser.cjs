'use strict';
// Invocation-owned synthetic browser fixture; never bundled or loaded by WordPress.
const fs = require('node:fs');
const cp = require('node:child_process');
const config = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const { chromium } = require(config.playwright);
const checks = [];
function check(ok, label) { if (!ok) throw new Error(label); checks.push(label); }
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    await context.addCookies(config.cookies);
    const page = await context.newPage();
    await page.goto(config.url, { waitUntil: 'networkidle' });
    check((await page.locator('h1').allTextContents()).includes('FooGallery Migration Storage'), 'real activated admin menu route renders operator page');
    check(await page.getByText('Not approved for this runtime.', { exact: false }).count(), 'default UI reports safe unapproved profile');
    const observedAction = await page.locator('form.foogm-storage-form').first().evaluate(f => ({ kind: typeof f.action, text: String(f.action), attribute: f.getAttribute('action') }));
    const submit = async (confirmation) => {
      const form = page.locator('form.foogm-storage-form').filter({ has: page.locator('input[value="' + confirmation + '"]') });
      await form.locator('input[type=checkbox]').check();
      const waiting = page.waitForResponse(r => r.url().includes('/admin-post.php') && r.request().method() === 'POST');
      await form.locator('button').click();
      const response = await waiting; const body = await response.json();
      if (body.success) { await page.waitForLoadState('networkidle'); await page.waitForTimeout(150); }
      return { status: response.status(), body };
    };
    const denied = await submit('switch-to-table');
    check(denied.status === 409 && !denied.body.success && denied.body.data.code === 'foogallery_migrate_storage_uncalibrated', 'actual no-profile HTTP POST safely refuses');
    await page.screenshot({ path: config.screenshots + '-unapproved.png', fullPage: true });
    // Explicit test-only server fixture, not a shipped default or production approval.
    fs.writeFileSync(config.profile_path, config.profile_code, { flag: 'wx', mode: 0o600 });
    await page.reload({ waitUntil: 'networkidle' });
    check(await page.getByText('Runtime capacity profile available.', { exact: false }).count(), 'UI observes same trusted test filter as receiver');
    const invalid = await page.evaluate(async () => {
      const form = document.querySelector('.foogm-storage-form'); const data = new FormData(form); data.set('nonce', 'not-valid');
      const r = await fetch(form.getAttribute('action'), { method: 'POST', body: data }); return { status: r.status, body: await r.json() };
    });
    check(invalid.status === 403 && !invalid.body.success, 'actual HTTP forged nonce rejected');
    const foreign = await page.evaluate(async () => {
      const form = document.querySelector('.foogm-storage-form'); const data = new FormData(form); data.set('blog_id', String(Number(data.get('blog_id')) + 1));
      const r = await fetch(form.getAttribute('action'), { method: 'POST', body: data }); return { status: r.status, body: await r.json() };
    });
    check(!foreign.body.success, 'actual HTTP cross-site request rejected');
    const switched = await submit('switch-to-table');
    check(switched.body.success && switched.body.data.backend === 'table', 'actual operator HTTP provisions schema and selects tables');
    await page.waitForSelector('input[value="switch-to-option"]');
    await page.screenshot({ path: config.screenshots + '-table.png', fullPage: true });
    const reversed = await submit('switch-to-option');
    check(reversed.body.success && reversed.body.data.backend === 'option', 'actual operator HTTP reverses to options');
    await page.waitForSelector('input[value="replace-retained-with-table"]');
    check(await page.getByText('A previous table copy is retained', { exact: false }).count(), 'retained replacement warning visible');
    cp.execFileSync(config.cove, ['wp', config.site, '--url=' + config.baseurl, '--skip-plugins', '--skip-themes', 'option', 'update', 'foogallery-migrate-data', '{"native_browser":"changed-after-reverse"}', '--format=json'], { stdio: 'pipe', timeout: 30000 });
    const replaced = await submit('replace-retained-with-table');
    check(replaced.body.success && replaced.body.data.backend === 'table', 'actual explicit operator retained replacement');
    await page.waitForSelector('input[value="switch-to-option"]');
    const last = await submit('switch-to-option');
    check(last.body.success, 'actual second reverse verifies replaced copy remains exportable');
    await page.waitForSelector('input[value="replace-retained-with-table"]');
    await page.screenshot({ path: config.screenshots + '-retained.png', fullPage: true });
    const state = JSON.parse(cp.execFileSync(config.cove, ['wp', config.site, '--url=' + config.baseurl, '--skip-plugins', '--skip-themes', 'option', 'get', 'foogallery-migrate-data', '--format=json'], { encoding: 'utf8', timeout: 30000 }));
    check(state.native_browser === 'changed-after-reverse', 'divergent authoritative option content survives replacement and export');
    console.log(JSON.stringify({ status: 'pass', checks, check_count: checks.length, responses: { denied, invalid, foreign, switched, reversed, replaced, last }, dom_named_action: observedAction, calibration: 'synthetic-owned-routing-filter-only-not-production-approval' }));
  } finally { await browser.close(); }
})().catch(e => { console.error(e.stack); process.exitCode = 1; });
