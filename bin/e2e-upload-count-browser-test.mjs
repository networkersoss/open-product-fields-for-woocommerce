import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
await page.goto(`${base}/product/opf-e2e-upload-count-product/`, { waitUntil: 'domcontentloaded' });
const uploader = page.locator('[data-opf-upload]');
const input = uploader.locator('input[type="file"]');
if (!(await input.getAttribute('multiple') !== null)) throw new Error('Unlimited upload field did not render a multiple file input.');
if (await uploader.getAttribute('data-opf-upload-max') !== '-1') throw new Error('Unlimited sentinel was not rendered.');
if (await uploader.getAttribute('data-opf-upload-min') !== '2') throw new Error('Minimum count was not rendered.');
const result = await page.locator('form.cart').evaluate((form) => {
	const event = new Event('submit', { bubbles: true, cancelable: true });
	const allowed = form.dispatchEvent(event);
	return { allowed, status: form.querySelector('[data-opf-upload-status]')?.textContent || '' };
});
if (result.allowed || !result.status.includes('Upload at least 2 file(s).')) throw new Error(`Minimum upload browser guard failed: ${JSON.stringify(result)}`);
if (errors.length) throw new Error(`Browser errors: ${errors.join('; ')}`);
console.log('Unlimited upload field renders a multiple input with max=-1.');
console.log('Minimum count renders and blocks add-to-cart until two files are present.');
console.log('No uncaught JavaScript errors.');
await browser.close();
