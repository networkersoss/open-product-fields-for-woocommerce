import { createRequire } from 'node:module';
const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productUrl = process.env.OPF_IMAGE_CHANGE_URL;
const lastProductUrl = process.env.OPF_IMAGE_CHANGE_LAST_URL;
if (!productUrl) throw new Error('OPF_IMAGE_CHANGE_URL is required');
if (!lastProductUrl) throw new Error('OPF_IMAGE_CHANGE_LAST_URL is required');

const browser = await chromium.launch();
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};
const openPage = async () => {
	const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
	const errors = [];
	page.on('pageerror', (error) => errors.push(error.message));
	return { page, errors };
};
const selectedGalleryIndex = (page) => page.locator('.woocommerce-product-gallery__image').evaluateAll((slides) => slides.findIndex((slide) => slide.classList.contains('flex-active-slide')));
const select = async (page, field, value) => {
	await page.locator(`[data-opf-field="${field}"] select`).selectOption(value);
	await page.waitForTimeout(150);
};

// --- rules mode: the last matching rule in the authored list wins
const { page, errors } = await openPage();
await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
const gallery = page.locator('.woocommerce-product-gallery');
await gallery.waitFor({ state: 'visible' });
await page.waitForFunction(() => window.OPF_IMAGE_RULES && Object.values(window.OPF_IMAGE_RULES).some((rules) => rules.length === 3));
const slideCount = await page.locator('.woocommerce-product-gallery__image').count();
const initialIndex = await selectedGalleryIndex(page);
check('product renders a three-image WooCommerce gallery with a known initial slide', slideCount === 3 && initialIndex === 0);

await select(page, 'color', 'red');
await select(page, 'size', 'large');
check('AND-combination rule navigates to matching existing gallery image', await selectedGalleryIndex(page) === 1);

await select(page, 'size', 'small');
const external = process.env.OPF_IMAGE_CHANGE_EXTERNAL;
check('wildcard rule shows its external target when size condition stops matching', !!external && await page.locator('.woocommerce-product-gallery__image.flex-active-slide img').getAttribute('src') === external);

await select(page, 'color', 'blue');
check('a later rule navigates to another existing gallery slide', await selectedGalleryIndex(page) === 2);

await select(page, 'color', 'green');
check('mismatch restores the original main gallery slide', await selectedGalleryIndex(page) === initialIndex);

check('no uncaught browser errors during gallery transitions', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));

// --- last mode: only the field changed last can select a rule, so the
// same values pick the rule of the changed field instead of the later rule.
const { page: lastPage, errors: lastErrors } = await openPage();
await lastPage.goto(lastProductUrl, { waitUntil: 'domcontentloaded' });
await lastPage.locator('.woocommerce-product-gallery').waitFor({ state: 'visible' });
await lastPage.waitForFunction(() => {
	const rules = window.OPF_IMAGE_RULES || {};
	const modes = window.OPF_IMAGE_RULE_MODES || {};
	const ids = Object.keys(rules);
	return ids.length === 1 && rules[ids[0]].length === 2 && modes[ids[0]] === 'last';
});
const lastModePayload = await lastPage.evaluate(() => {
	const gid = Object.keys(window.OPF_IMAGE_RULES)[0];
	return {
		mode: window.OPF_IMAGE_RULE_MODES[gid],
		rules: window.OPF_IMAGE_RULES[gid].map((rule) => rule.conditions.map((condition) => `${condition.field}=${condition.value}`).join('+')),
	};
});
check('last-mode page publishes one group in last mode with two single-field rules', lastModePayload.mode === 'last' && lastModePayload.rules.length === 2 && lastModePayload.rules.every((rule) => !rule.includes('+')));

const lastSlideCount = await lastPage.locator('.woocommerce-product-gallery__image').count();
check('last mode renders the base gallery slide before any field change', lastSlideCount === 3 && await selectedGalleryIndex(lastPage) === 0);

await select(lastPage, 'size', 'small');
check('last mode follows the size rule once size is the changed field', await selectedGalleryIndex(lastPage) === 2);

await select(lastPage, 'color', 'red');
check('last mode keeps the colour rule although the later size rule also matches', await selectedGalleryIndex(lastPage) === 1);

await select(lastPage, 'size', 'large');
check('last mode restores the base slide when the changed field matches no rule', await selectedGalleryIndex(lastPage) === 0);

check('no uncaught browser errors during the last-mode transitions', lastErrors.length === 0);
if (lastErrors.length) console.log(lastErrors.join('\n'));

await browser.close();
process.exit(failures ? 1 : 0);
