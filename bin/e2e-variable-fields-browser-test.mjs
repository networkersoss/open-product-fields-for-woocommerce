import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('console', (message) => { if (message.type() === 'error') errors.push(message.text()); });
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.goto(`${base}/product/opf-e2e-variable-fields-product/`, { waitUntil: 'domcontentloaded' });
const form = page.locator('form.variations_form');
const color = form.locator('select[name="attribute_color"]');
await color.waitFor({ state: 'visible' });
check('variable page renders parent group before a variation is chosen', await page.locator('[data-opf-field="parent_note"]').count() === 1);
check('variation-only fields are initially absent', await page.locator('[data-opf-field="red_note"], [data-opf-field="blue_note"]').count() === 0);

await color.selectOption({ label: 'Red' });
const red = page.locator('[data-opf-field="red_note"]');
await red.waitFor({ state: 'visible', timeout: 10000 });
check('selecting Red loads only its variation field group', await red.count() === 1 && await page.locator('[data-opf-field="blue_note"]').count() === 0);
check('Red variation updates totals base to its selected price', Number(await page.locator('.opf-product-totals').getAttribute('data-product-price')) === 12);

await color.selectOption({ label: 'Blue' });
const blue = page.locator('[data-opf-field="blue_note"]');
await blue.waitFor({ state: 'visible', timeout: 10000 });
await page.waitForFunction(() => document.querySelector('form.variations_form .single_add_to_cart_button')?.disabled === true);
check('changing to Blue removes Red fields and loads Blue fields', await blue.count() === 1 && await page.locator('[data-opf-field="red_note"]').count() === 0);
check('Blue variation updates totals base to its selected price', Number(await page.locator('.opf-product-totals').getAttribute('data-product-price')) === 20);
check('out-of-stock Blue remains unavailable after fields load', await form.locator('.single_add_to_cart_button').isDisabled());

await color.selectOption('');
await page.waitForFunction(() => document.querySelector('form.variations_form .single_add_to_cart_button')?.disabled === true);
check('clearing variation restores parent-only fields and variable base price', await page.locator('[data-opf-field="parent_note"]').count() === 1 && await page.locator('[data-opf-field="blue_note"], [data-opf-field="red_note"]').count() === 0 && Number(await page.locator('.opf-product-totals').getAttribute('data-product-price')) === 12);
check('clearing variation preserves WooCommerce selection-needed state', await form.locator('.single_add_to_cart_button').isDisabled());

let releaseResponse;
const variationRoute = '**/wp-json/opf/v1/variation-fields**';
const delayedRoute = async (route) => {
	await new Promise((resolve) => { releaseResponse = resolve; });
	await route.continue();
};
await page.route(variationRoute, delayedRoute);
const pendingFieldsRequest = page.waitForRequest((request) => request.url().includes('/wp-json/opf/v1/variation-fields'));
await color.selectOption({ label: 'Red' });
await pendingFieldsRequest;
await page.waitForTimeout(100);
check('add-to-cart stays disabled while variation fields are loading', await form.locator('.single_add_to_cart_button').isDisabled());
const submitPrevented = await form.evaluate((node) => {
	const event = new Event('submit', { bubbles: true, cancelable: true });
	node.dispatchEvent(event);
	return event.defaultPrevented;
});
check('pending variation fields block form submission', submitPrevented);
releaseResponse?.();
await page.locator('[data-opf-field="red_note"]').waitFor({ state: 'visible', timeout: 10000 });
await page.waitForFunction(() => document.querySelector('form.variations_form .single_add_to_cart_button')?.disabled === false);
check('add-to-cart re-enables after current variation fields load', !(await form.locator('.single_add_to_cart_button').isDisabled()));
await page.unroute(variationRoute, delayedRoute);

const deferredResponses = [];
const rapidRoute = async (route) => {
	const index = deferredResponses.length;
	await new Promise((resolve) => { deferredResponses[index] = resolve; });
	await route.continue();
};
await page.route(variationRoute, rapidRoute);
const redRequestPromise = page.waitForRequest((request) => request.url().includes('/wp-json/opf/v1/variation-fields'));
await color.selectOption({ label: 'Red' });
const redRequest = await redRequestPromise;
const blueRequestPromise = page.waitForRequest((request) => request.url().includes('/wp-json/opf/v1/variation-fields') && request !== redRequest);
await color.selectOption({ label: 'Blue' });
await blueRequestPromise;
deferredResponses[1]?.();
await page.locator('[data-opf-field="blue_note"]').waitFor({ state: 'visible', timeout: 10000 });
deferredResponses[0]?.();
await page.waitForTimeout(150);
check('late Red response cannot overwrite newer Blue selection', await page.locator('[data-opf-field="blue_note"]').count() === 1 && await page.locator('[data-opf-field="red_note"]').count() === 0 && Number(await page.locator('.opf-product-totals').getAttribute('data-product-price')) === 20);
await page.unroute(variationRoute, rapidRoute);
check('variation switching produces no browser errors', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));
await browser.close();
if (failures) process.exit(1);
