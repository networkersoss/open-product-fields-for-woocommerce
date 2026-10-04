// Authenticated WP admin save/reload + storefront/cart/checkout lifecycle on a disposable clone.
import fs from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');
const base = process.env.OPF_IQZ_BASE_URL || 'http://127.0.0.1:8246';
const dir = '/tmp/opf-image-quantity-zoom-evidence';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone required.');
const state = JSON.parse(fs.readFileSync(dir + '/runtime-state.json'));
const password = process.env.OPF_IQZ_ADMIN_PASSWORD;
if (!password) throw new Error('OPF_IQZ_ADMIN_PASSWORD is required.');
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1365, height: 900 } });
const page = await context.newPage();
const checks = [];
const errors = [];
const check = (label, pass) => { checks.push({ label, pass: !!pass }); console.log(`${pass ? 'ok' : 'FAIL'} ${label}`); if (!pass) throw new Error(label); };
page.on('pageerror', e => errors.push(e.message));
page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
try {
  if (process.env.OPF_IQZ_SKIP_ADMIN !== '1') {
    await page.goto(base + '/wp-login.php');
    await page.locator('#user_login').fill('opf_iqz_admin');
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await page.waitForURL(/wp-admin/);
    check('isolated WordPress admin login succeeds', true);

    const editor = `${base}/wp-admin/post.php?post=${state.groups[0]}&action=edit`;
    await page.goto(editor, { waitUntil: 'domcontentloaded' });
    const zoom = page.locator('[data-opf-image-setting="image_zoom"]');
    await zoom.waitFor({ state: 'visible' });
    check('real admin builder loads disabled image quantity zoom control', !(await zoom.isChecked()));
    await zoom.check();
    await page.getByRole('button', { name: 'Save' }).click();
    await page.waitForFunction(() => document.querySelector('#opf-b-status')?.textContent.trim() === 'Saved.');
    check('admin builder saves zoom setting through WordPress REST', true);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.locator('[data-opf-image-setting="image_zoom"]').waitFor({ state: 'visible' });
    check('admin reload restores enabled image quantity zoom control', await page.locator('[data-opf-image-setting="image_zoom"]').isChecked());
    await page.screenshot({ path: dir + '/admin-image-quantity-zoom.png', fullPage: true });
  } else {
    check('previous real admin save/reload proof enabled zoom setting', true);
  }

  for (const [slug, zoomOn] of [['opf-iqz-on', true], ['opf-iqz-off', false]]) {
    await page.goto(`${base}/product/${slug}/`, { waitUntil: 'domcontentloaded' });
    const field = page.locator('[data-opf-field="prints"]');
    await field.waitFor();
    const wrap = field.locator('.opf-image-quantity__img');
    check(`${slug}: one image quantity option rendered`, await wrap.count() === 1);
    if (zoomOn) {
      check('zoom page has full-size zoom URL', (await wrap.getAttribute('data-zoom-url'))?.includes('/wp-content/uploads/'));
      check('zoom preview starts hidden', !(await wrap.locator('.opf-swatch-zoom-preview').isVisible()));
      await wrap.hover();
      check('zoom preview enlarges on hover', await wrap.locator('.opf-swatch-zoom-preview').isVisible());
      await page.keyboard.press('Tab');
      await field.locator('input[type=number]').focus();
      check('zoom preview enlarges on keyboard focus', await wrap.locator('.opf-swatch-zoom-preview').isVisible());
      await page.screenshot({ path: dir + '/storefront-image-quantity-zoom.png', fullPage: true });
      await page.setViewportSize({ width: 390, height: 844 });
      await page.reload({ waitUntil: 'domcontentloaded' });
      const mobileField = page.locator('[data-opf-field="prints"]');
      const mobileInput = mobileField.locator('input[type=number]');
      await mobileInput.waitFor({ state: 'visible' });
      const mobileLayout = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, field: document.querySelector('[data-opf-field="prints"]')?.getBoundingClientRect().right }));
      check('390px viewport keeps the image quantity control visible without horizontal page overflow', mobileLayout.document <= mobileLayout.viewport && mobileLayout.field <= mobileLayout.viewport);
      await page.setViewportSize({ width: 1365, height: 900 });
      await page.reload({ waitUntil: 'domcontentloaded' });
    } else {
      check('control page emits no zoom URL or preview', !(await wrap.getAttribute('data-zoom-url')) && await wrap.locator('.opf-swatch-zoom-preview').count() === 0);
    }
    const qty = field.locator('input[type=number]');
    await qty.fill('2');
    await page.locator('form.cart button.single_add_to_cart_button').click();
    await page.locator('.woocommerce-message, .wc-block-components-notice-banner.is-success').first().waitFor({ timeout: 15000 });
    check(`${slug}: product with quantity two added to cart`, true);
  }
  const cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
  const cart = await cartResponse.json();
  const unit = cart.totals.currency_minor_unit;
  const money = value => Number(value) / (10 ** unit);
  check('cart contains both comparison products', cart.items.length === 2);
  const lineTotals = cart.items.map(i => money(i.totals.line_total));
  check('zoom on/off cart line prices match', lineTotals[0] === lineTotals[1]);
  check('both configured carts total $22.50 per product ($45 combined)', lineTotals.every(total => total === 22.5) && money(cart.totals.total_price) === 45);
  fs.writeFileSync(dir + '/cart.json', JSON.stringify({ total: money(cart.totals.total_price), items: cart.items.map(i => ({ name: i.name, quantity: i.quantity, line_total: money(i.totals.line_total) })) }, null, 2));

  await page.goto(base + '/iqz-checkout/', { waitUntil: 'domcontentloaded' });
  const nonce = await page.locator('[name="woocommerce-process-checkout-nonce"]').inputValue();
  const checkout = await context.request.post(base + '/?wc-ajax=checkout', { form: {
    'woocommerce-process-checkout-nonce': nonce, billing_first_name: 'Test', billing_last_name: 'Buyer', billing_country: 'US',
    billing_address_1: '1 Test Street', billing_city: 'Testville', billing_state: 'CA', billing_postcode: '90210',
    billing_phone: '5551234567', billing_email: 'iqz@example.invalid', payment_method: 'bacs', terms: 'on'
  }});
  const body = await checkout.json();
  check('classic checkout succeeds', body.result === 'success');
  const orderId = Number(body.redirect.match(/order-received\/(\d+)/)?.[1]);
  check('checkout returns a persisted order id', orderId > 0);
  fs.writeFileSync(dir + '/order-id.txt', String(orderId));
  check('browser had no uncaught JavaScript or console errors', errors.length === 0);
  fs.writeFileSync(dir + '/browser-results.json', JSON.stringify({ checks, errors }, null, 2));
  console.log('SUCCESS image quantity zoom runtime lifecycle');
} finally {
  if (checks.some(c => !c.pass)) fs.writeFileSync(dir + '/browser-results.json', JSON.stringify({ checks, errors }, null, 2));
  await browser.close();
}
