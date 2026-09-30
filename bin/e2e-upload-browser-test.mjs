import { createRequire } from 'node:module';
const require2 = createRequire(process.cwd() + '/index.js');
const { chromium } = require2('playwright');
const BASE = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
let pass = 0;
let fail = 0;
const check = (label, condition) => {
	if (condition) { pass++; console.log('  ok   ', label); }
	else { fail++; console.log('  FAIL ', label); }
};

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
await page.goto(`${ BASE }/product/e2e-upload-product/`, { waitUntil: 'domcontentloaded', timeout: 20000 });
const uploader = page.locator('[data-opf-upload]').first();
check('upload UI: modern uploader + file input rendered', await uploader.count() === 1 && await uploader.locator('input[type="file"]').count() === 1);
check('upload UI: configured product/group/field context emitted', !! await uploader.getAttribute('data-opf-upload-product') && !! await uploader.getAttribute('data-opf-upload-group') && await uploader.getAttribute('data-opf-upload-field') === 'artwork');
check('upload UI: frontend module served externally', await page.locator('script[type="module"][src*="opf-frontend.js"]').count() === 1 && await page.locator('script[type="module"]').evaluateAll((scripts) => scripts.every((script) => !script.textContent.includes('OPF frontend'))));

const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGBgAAAABQABh6FO1AAAAABJRU5ErkJggg==';
await page.route('**/wp-json/opf/v1/uploads**', async (route) => {
	await new Promise((resolve) => setTimeout(resolve, 600));
	await route.continue();
});
await page.evaluate(({ encoded }) => {
	const bytes = Uint8Array.from(atob(encoded), (char) => char.charCodeAt(0));
	const file = new File([bytes], 'dropped-art.png', { type: 'image/png' });
	const data = new DataTransfer();
	data.items.add(file);
	const drop = document.querySelector('[data-opf-upload-drop]');
	drop.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: data }));
}, { encoded: png });
await page.waitForTimeout(80);
const submitWhilePending = await page.locator('form.cart').evaluate((form) => {
	const event = new Event('submit', { bubbles: true, cancelable: true });
	const allowed = form.dispatchEvent(event);
	return { allowed, pending: form.dataset.opfUploadsPending || '0' };
});
check('upload UI: progress is shown and add-to-cart is blocked while transfer is pending', !submitWhilePending.allowed && Number(submitWhilePending.pending) > 0 && (await uploader.locator('[data-opf-upload-list] progress').count()) === 1 && (await uploader.locator('[data-opf-upload-status]').textContent()).includes('wait for uploads'));
await uploader.locator('[data-opf-upload-token]').waitFor({ state: 'attached', timeout: 15000 });
check('upload UI: drag/drop creates session ticket and image thumbnail', /^[a-f0-9]{48}$/.test(await uploader.locator('[data-opf-upload-token]').inputValue()) && await uploader.locator('.opf-upload__preview').count() === 1 && await uploader.locator('[data-opf-upload-list] progress').count() === 0);
const stagedToken = await uploader.locator('[data-opf-upload-token]').inputValue();
const legacyPublicResponse = await page.request.get(`${ BASE }/wp-content/uploads/.opf-private/${ stagedToken }.png`);
const legacyResponseBytes = Buffer.from(await legacyPublicResponse.body());
const legacyResponseType = legacyPublicResponse.headers()['content-type'] || '';
const uploadBytesExposed = legacyResponseBytes.equals(Buffer.from(png, 'base64')) || legacyResponseType.startsWith('image/png');
check(`upload security: old public token URL does not serve upload bytes (HTTP ${ legacyPublicResponse.status() }, ${ legacyResponseType || 'no content-type' })`, !uploadBytesExposed);

await uploader.getByRole('button', { name: 'Remove dropped-art.png' }).click();
await uploader.locator('[data-opf-upload-token]').waitFor({ state: 'detached', timeout: 10000 });
check('upload UI: remove clears ticket and preview row', await uploader.locator('[data-opf-upload-list] li').count() === 0);

await page.unrouteAll();
await uploader.locator('input[type="file"]').setInputFiles([
	{ name: 'browser-art.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') },
	{ name: 'browser-proof.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') },
]);
await page.waitForFunction(() => document.querySelectorAll('[data-opf-upload-token]').length === 2, null, { timeout: 15000 });
check('upload UI: multiple selected files are staged sequentially within configured limit', await uploader.locator('[data-opf-upload-list] li').count() === 2 && await uploader.locator('[data-opf-upload-token]').count() === 2);
await Promise.all([
	page.waitForLoadState('domcontentloaded'),
	page.locator('form.cart button[name="add-to-cart"], button.single_add_to_cart_button').first().click(),
]);
const pageText = await page.locator('body').innerText();
check('upload UI: completed Ajax upload submits through classic Woo cart form', pageText.includes('Artwork') || pageText.includes('added to your cart'));
check('upload UI: no browser runtime errors', errors.length === 0);
await page.screenshot({ path: '/tmp/opf-upload-ui.png', fullPage: true });
await browser.close();
console.log(fail === 0 ? `\nSUCCESS: all ${ pass } upload browser checks passed.` : `\n${ fail } upload browser check(s) failed.`);
process.exit(fail === 0 ? 0 : 1);
