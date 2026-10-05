import { createRequire } from 'node:module';
const require2 = createRequire(process.cwd() + '/index.js');
const { chromium } = require2('playwright');
const BASE = process.env.OPF_BASE_URL || 'http://127.0.0.1:8090';
let pass = 0, fail = 0;
const check = (l, c) => { if (c) { pass++; console.log('  ok   ', l); } else { fail++; console.log('  FAIL ', l); } };

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', e => errors.push('pageerror: ' + e.message));

// 1. Product page
await page.goto(BASE + '/product/e2e-matched-product/', { waitUntil: 'domcontentloaded' });
await page.locator('[data-opf-field="delivery"] input[value="boost"]').check({ force: true });
await page.locator('[data-opf-field="boost_note"] textarea').waitFor({ state: 'visible' });
await page.locator('[data-opf-field="boost_note"] textarea').fill('rush order please');
await page.screenshot({ path: '/tmp/ui-1-product.png' });
check('product: boost selected + note filled + screenshot', true);

// 2. Add to cart (classic form POST)
await Promise.all([
	page.waitForLoadState('domcontentloaded'),
	page.locator('form.cart button[name="add-to-cart"], button.single_add_to_cart_button').first().click(),
]);

// 3. Cart page — block cart is client-rendered; wait for the data, not a timer
await page.goto(BASE + '/cart/', { waitUntil: 'domcontentloaded' });
let appeared = true;
try { await page.waitForSelector('text=Delivery speed', { timeout: 15000 }); } catch { appeared = false; }
check('cart: selection visible in block cart', appeared);
if (appeared) {
	const t = await page.textContent('body');
	check('cart: Boost choice shown', t.includes('Boost'));
	check('cart: boost note shown', t.includes('rush order please'));
	check('cart: priced $117.00 (100 + 5 boost + 2 note + 10 pricing mode)', t.includes('117.00'));
}
await page.screenshot({ path: '/tmp/ui-2-cart.png', fullPage: true });

// 4. Checkout — fill by visible labels
await page.goto(BASE + '/checkout/', { waitUntil: 'domcontentloaded' });
let coShown = true;
try { await page.waitForSelector('text=Delivery speed', { timeout: 15000 }); } catch { coShown = false; }
check('checkout: selection visible', coShown);

const byLabel = async (labelRe, value) => {
	const loc = page.getByLabel(labelRe).first();
	try { await loc.waitFor({ state: 'visible', timeout: 8000 }); await loc.fill(value); return true; }
	catch { return false; }
};
check('checkout: email filled', await byLabel(/^email address$/i, 'buyer@example.com'));
check('checkout: first name filled', await byLabel(/^first name$/i, 'Test'));
check('checkout: last name filled', await byLabel(/^last name$/i, 'Buyer'));
check('checkout: address filled', await byLabel(/^address$/i, '1 Test St'));
check('checkout: city filled', await byLabel(/^city$/i, 'Testville'));
check('checkout: postcode filled', await byLabel(/^zip code$/i, '12345'));
await page.waitForTimeout(600);
await page.screenshot({ path: '/tmp/ui-3-checkout.png', fullPage: true });

const place = page.getByRole('button', { name: /place order/i }).first();
check('checkout: place order present', await place.count() > 0);
await place.click();
await page.waitForLoadState('domcontentloaded');
let confirmed = true;
try { await page.waitForSelector('text=Delivery speed', { timeout: 20000 }); } catch { confirmed = false; }
const doneText = await page.textContent('body');
const placed = confirmed || /order-received/.test(page.url()) || doneText.includes('Thank you');
check('order confirmation reached', placed);
check('confirmation shows Delivery speed / Boost', doneText.includes('Delivery speed') && doneText.includes('Boost'));
await page.screenshot({ path: '/tmp/ui-4-order-received.png', fullPage: true });

check('no JS page errors during entire flow', errors.length === 0);
if (errors.length) console.log(errors.slice(0, 5).join('\n'));

// Modern upload field: real private REST staging, progress-state submit guard,
// local image preview, server deletion, then the ordinary classic cart form.
const uploadPage = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const uploadErrors = [];
const uploadResponses = [];
uploadPage.on('pageerror', e => uploadErrors.push(e.message));
uploadPage.on('response', async response => {
	if (response.url().includes('/wp-json/opf/v1/uploads')) {
		let body = '';
		try { body = await response.text(); } catch {}
		uploadResponses.push({ method: response.request().method(), status: response.status(), body });
	}
});
await uploadPage.goto(BASE + '/product/e2e-upload-product/', { waitUntil: 'domcontentloaded' });
const uploader = uploadPage.locator('[data-opf-upload]').first();
check('upload UI: modern uploader and drop target rendered', await uploader.count() === 1 && await uploader.locator('[data-opf-upload-drop]').count() === 1);
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==', 'base64');
await uploadPage.route('**/wp-json/opf/v1/uploads/**', async route => {
	await new Promise(resolve => setTimeout(resolve, 500));
	await route.continue();
});
await uploader.locator('input[type="file"]').setInputFiles({ name: 'browser-art.png', mimeType: 'image/png', buffer: png });
await uploadPage.waitForTimeout(75);
await uploadPage.locator('form.cart button[name="add-to-cart"], button.single_add_to_cart_button').first().click();
const pendingStatus = await uploader.locator('[data-opf-upload-status]').textContent();
const pendingBlocked = pendingStatus.includes('wait for uploads') && uploadPage.url().includes('/product/e2e-upload-product/');
if (!pendingBlocked) console.log('  detail upload pending:', JSON.stringify({ status: pendingStatus, url: uploadPage.url(), notices: await uploadPage.locator('.woocommerce-error, .woocommerce-message').allTextContents(), responses: uploadResponses }));
check('upload UI: add-to-cart is blocked while Ajax upload is pending', pendingBlocked);
await uploader.locator('[data-opf-upload-token]').waitFor({ state: 'attached', timeout: 15000 });
check('upload UI: progress completes with a session ticket and image preview', await uploader.locator('[data-opf-upload-list] progress').count() === 0 && await uploader.locator('.opf-upload__preview').count() === 1 && /^[a-f0-9]{48}$/.test(await uploader.locator('[data-opf-upload-token]').inputValue()));
await uploader.getByRole('button', { name: 'Remove browser-art.png' }).click();
await uploader.locator('[data-opf-upload-token]').waitFor({ state: 'detached', timeout: 10000 });
check('upload UI: remove deletes the session ticket and preview row', await uploader.locator('[data-opf-upload-list] li').count() === 0);
await uploadPage.unrouteAll();
await uploader.locator('input[type="file"]').setInputFiles({ name: 'browser-art.png', mimeType: 'image/png', buffer: png });
await uploader.locator('[data-opf-upload-token]').waitFor({ state: 'attached', timeout: 15000 });
await Promise.all([
	uploadPage.waitForLoadState('domcontentloaded'),
	uploadPage.locator('form.cart button[name="add-to-cart"], button.single_add_to_cart_button').first().click(),
]);
const uploadBody = await uploadPage.locator('body').innerText();
const submittedUpload = !uploadPage.url().includes('/product/e2e-upload-product/') || await uploadPage.locator('.woocommerce-message').count() > 0 || uploadBody.includes('has been added to your cart');
if (!submittedUpload) console.log('  detail upload submit:', JSON.stringify({ status: await uploader.locator('[data-opf-upload-status]').textContent(), url: uploadPage.url(), notices: await uploadPage.locator('.woocommerce-error, .woocommerce-message').allTextContents(), responses: uploadResponses, body: (await uploadPage.locator('body').innerText()).slice(0, 1200) }));
check('upload UI: staged file submits through classic add-to-cart', submittedUpload);
check('upload UI: no runtime errors during upload flow', uploadErrors.length === 0);
await uploadPage.close();

console.log(fail === 0 ? `\nSUCCESS: all ${pass} real-browser UI checks passed.` : `\n${fail} FAILURES of ${pass + fail}`);
await browser.close();
process.exit(fail === 0 ? 0 : 1);
