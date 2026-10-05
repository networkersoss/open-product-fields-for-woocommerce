import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productSlug = process.env.OPF_QTY_PRODUCT_SLUG || 'opf-e2e-shortcode-product';
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

const quantity = page.locator('form.cart input[name="quantity"]').first();
await page.goto(`${base}/product/${encodeURIComponent(productSlug)}/?qty=invalid`, { waitUntil: 'domcontentloaded' });
await quantity.waitFor({ state: 'visible' });
const defaultQuantity = await quantity.inputValue();
await page.goto(`${base}/product/${encodeURIComponent(productSlug)}/?qty=4`, { waitUntil: 'domcontentloaded' });
check('valid qty URL value preselects the current product quantity', await quantity.inputValue() === '4');
await page.goto(`${base}/product/${encodeURIComponent(productSlug)}/?qty=2.5`, { waitUntil: 'domcontentloaded' });
check('quantity that violates WooCommerce step keeps the product default', await quantity.inputValue() === defaultQuantity);
check('quantity prefill product pages have no uncaught JavaScript errors', errors.length === 0);

await browser.close();
if (failures) process.exit(1);
