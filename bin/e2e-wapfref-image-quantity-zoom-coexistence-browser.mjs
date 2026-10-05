// Real storefront add-to-cart check with WAPF Extended 3.1.5 and OPF both
// active, for the WAPF-FIELD-IMAGE-QUANTITY-ZOOM coexistence residual.
//
//   OPF_WAPFREF_BASE_URL=http://127.0.0.1:8251 \
//   NODE_PATH=/home/followersya-5hqi7/followersya.com/node_modules \
//   node bin/e2e-wapfref-image-quantity-zoom-coexistence-browser.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');

const base = process.env.OPF_WAPFREF_BASE_URL || 'http://127.0.0.1:8251';
const out = process.env.OPF_WAPFREF_OUT || 'docs/compatibility/wapf-reference-proof-20261005';
const dir = path.join(out, 'row5-image-quantity-zoom');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone required.');
fs.mkdirSync(dir, { recursive: true });

const slug = 'wapfref-iqz-coexistence';
const checks = [];
const check = (label, pass, detail = '') => {
  checks.push({ label, pass: !!pass, detail });
  console.log(`${pass ? 'ok' : 'FAIL'} ${label}${detail ? ' (' + detail + ')' : ''}`);
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1100, height: 900 } });
const page = await context.newPage();
page.setDefaultNavigationTimeout(120000);
page.setDefaultTimeout(120000);
const failedRequests = [];
page.on('requestfailed', r => failedRequests.push(`${r.method()} ${r.url()}: ${r.failure()?.errorText}`));
const serverErrors = [];
page.on('response', r => { if (r.status() >= 500) serverErrors.push(`${r.status()} ${r.url()}`); });

try {
  await page.goto(`${base}/product/${slug}/`, { waitUntil: 'domcontentloaded' });
  check('WAPF 3.1.5 is enqueued on the product page', await page.locator('script[src*="advanced-product-fields-for-woocommerce-extended"]').count() > 0);
  const note = page.locator('[data-opf-field="note"] input, [data-opf-field="note"] textarea').first();
  const qty = page.locator('[data-opf-field="prints"] input[type="number"]').first();
  await note.waitFor({ state: 'visible' });
  await qty.waitFor({ state: 'visible' });
  await note.fill('Checked sample');
  await qty.fill('2');

  const submit = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes(`/product/${slug}/`));
  await page.locator('form.cart button.single_add_to_cart_button').click();
  const response = await submit;
  check('both-active classic add-to-cart POST is not a server error', response.status() < 500, `HTTP ${response.status()}`);
  await page.locator('.woocommerce-message, .wc-block-components-notice-banner.is-success').first().waitFor({ timeout: 30000 });

  const cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
  const cart = await cartResponse.json();
  const line = cart.items?.find(i => i.slug === slug || i.name === 'WAPFREF IQZ coexistence');
  check('Store API cart contains the fixture product', !!line && line.quantity === 1);
  check('no 5xx responses during the both-active request', 0 === serverErrors.length, serverErrors.join('; '));
  check('no failed browser requests', 0 === failedRequests.length, failedRequests.join('; '));

  if (cartResponse.headers()['nonce']) {
    await context.request.delete(base + '/wp-json/wc/store/v1/cart/items', { headers: { Nonce: cartResponse.headers()['nonce'] } });
  }
  const after = await (await context.request.get(base + '/wp-json/wc/store/v1/cart')).json();
  check('isolated browser cart emptied', (after.items?.length ?? 0) === 0);

  fs.writeFileSync(path.join(dir, 'browser-add-to-cart.json'), JSON.stringify({ utc: new Date().toISOString(), checks }, null, 2));
  console.log('SUCCESS row5 coexistence add-to-cart');
} finally {
  await context.close();
  await browser.close();
}
