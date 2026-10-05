import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productSlug = process.env.OPF_PREFILL_PRODUCT_SLUG || 'opf-e2e-shortcode-product';
const groupId = process.env.OPF_PREFILL_GROUP_ID;
if (!groupId) throw new Error('OPF_PREFILL_GROUP_ID is required');

const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

const productUrl = `${base}/product/${encodeURIComponent(productSlug)}/`;
const engraving = page.locator(`[data-opf-group="${groupId}"] [data-opf-field="engraving"] input`);
const choice = (slug) => page.locator(`[data-opf-group="${groupId}"] [data-opf-field="colors"] input[value="${slug}"]`);

await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
await engraving.waitFor({ state: 'visible' });
check('unparameterized fields retain their configured default', await engraving.inputValue() === '' && await choice('green').isChecked());

await page.goto(`${productUrl}?engraving=Ada%20%2B%20Grace&colors=red%2Cblue`, { waitUntil: 'domcontentloaded' });
check('percent-decoded value preselects configured text field', await engraving.inputValue() === 'Ada + Grace');
check('comma-separated query value selects all matching checkbox choices', await choice('red').isChecked() && await choice('blue').isChecked());
check('explicit query selection overrides field default', !(await choice('green').isChecked()));
check('URL prefill product page has no uncaught JavaScript errors', errors.length === 0);

await browser.close();
if (failures) process.exit(1);
