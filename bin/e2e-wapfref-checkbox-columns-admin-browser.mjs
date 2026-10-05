// Real WordPress-admin REST save/reload proof for WAPF-FIELD-CHECKBOX-COLUMNS.
// Logs in as a temporary administrator, opens the real OPF field-group builder
// post screen, reads the builder's own REST nonce/URL, saves a `columns: 4`
// change to `/wp-json/opf/v1/groups`, then proves the value survives a fresh
// builder load and that the storefront renders a 4-column grid.
//
//   OPF_WAPFREF_BASE_URL=http://127.0.0.1:8251 OPF_WAPFREF_ADMIN_PASSWORD=... \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//   node bin/e2e-wapfref-checkbox-columns-admin-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');

const base = process.env.OPF_WAPFREF_BASE_URL || 'http://127.0.0.1:8251';
const out = process.env.OPF_WAPFREF_OUT || 'docs/compatibility/wapf-reference-proof-20261005';
const dir = path.join(out, 'row3-checkbox-columns');
const password = process.env.OPF_WAPFREF_ADMIN_PASSWORD;
if (!password) throw new Error('OPF_WAPFREF_ADMIN_PASSWORD is required.');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone required.');
const state = JSON.parse(fs.readFileSync(path.join(dir, 'browser-state.json'), 'utf8'));

const checks = [];
const check = (label, pass, detail = '') => {
  checks.push({ label, pass: !!pass, detail });
  console.log(`${pass ? 'ok' : 'FAIL'} ${label}${detail ? ' (' + detail + ')' : ''}`);
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
page.setDefaultNavigationTimeout(120000);
page.setDefaultTimeout(120000);
try {
  await page.goto(base + '/wp-login.php');
  await page.locator('#user_login').fill(state.admin_user);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
  check('temporary administrator login succeeds', true);

  const builder = `${base}/wp-admin/post.php?post=${state.group}&action=edit`;
  await page.goto(builder, { waitUntil: 'domcontentloaded' });
  const mount = page.locator('#opf-builder-app');
  await mount.waitFor();
  const nonce = await mount.getAttribute('data-nonce');
  const rest = await mount.getAttribute('data-rest');
  const postId = await mount.getAttribute('data-post-id');
  const model = JSON.parse(await mount.getAttribute('data-model'));
  check('builder exposes a live REST nonce + endpoint', !!nonce && /opf\/v1\/groups$/.test(rest || ''), rest || 'none');
  check('fixture group starts at columns:2', 2 === Number(model.fields[0].columns), String(model.fields[0].columns));

  model.fields[0].columns = 4;
  const saveResponse = await context.request.post(rest, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
    data: { id: Number(postId), title: 'WAPFREF checkbox columns', data: model },
  });
  const saveBody = await saveResponse.json();
  check('admin REST POST /opf/v1/groups accepts the save', saveResponse.status() === 200 && Number(saveBody.id) === Number(postId), `HTTP ${saveResponse.status()} id=${saveBody.id}`);

  const listResponse = await context.request.get(base + '/wp-json/opf/v1/groups', { headers: { 'X-WP-Nonce': nonce } });
  const groups = await listResponse.json();
  const saved = groups.find(g => Number(g.id) === Number(postId));
  check('REST reload returns columns:4', saved && 4 === Number(saved.data.fields[0].columns), saved ? String(saved.data.fields[0].columns) : 'group missing');

  await page.reload({ waitUntil: 'domcontentloaded' });
  const reloaded = JSON.parse(await page.locator('#opf-builder-app').getAttribute('data-model'));
  check('fresh builder load shows columns:4', 4 === Number(reloaded.fields[0].columns), String(reloaded.fields[0].columns));

  await page.goto(`${base}/product/${state.slug}/`, { waitUntil: 'domcontentloaded' });
  const grid = page.locator('[data-opf-field="extras"] .opf-checkboxes--columns').first();
  await grid.waitFor();
  await page.waitForFunction(() => {
    const g = document.querySelector('[data-opf-field="extras"] .opf-checkboxes--columns');
    return g && getComputedStyle(g).display === 'grid';
  }, null, { timeout: 30000 });
  const columns = await grid.evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').filter(Boolean).length);
  check('storefront renders the saved 4-column checkbox grid', 4 === columns, `columns=${columns}`);
  await page.screenshot({ path: path.join(dir, 'storefront-4-columns.png'), fullPage: true });

  const failed = checks.filter(c => !c.pass);
  fs.writeFileSync(path.join(dir, 'browser-admin-rest.json'), JSON.stringify({ utc: new Date().toISOString(), checks }, null, 2));
  if (failed.length) throw new Error(`${failed.length} check(s) failed: ${failed.map(c => c.label).join('; ')}`);
  console.log('SUCCESS row3 admin REST save/reload');
} finally {
  await context.close();
  await browser.close();
}
