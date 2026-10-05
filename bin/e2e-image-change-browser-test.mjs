import { createRequire } from 'node:module';
const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productUrl = process.env.OPF_IMAGE_CHANGE_URL;
if (!productUrl) throw new Error('OPF_IMAGE_CHANGE_URL is required');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};
const selectedGalleryIndex = () => page.locator('.woocommerce-product-gallery__image').evaluateAll((slides) => slides.findIndex((slide) => slide.classList.contains('flex-active-slide')));
const select = async (field, value) => {
	await page.locator(`[data-opf-field="${field}"] select`).selectOption(value);
	await page.waitForTimeout(150);
};

await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
const gallery = page.locator('.woocommerce-product-gallery');
await gallery.waitFor({ state: 'visible' });
await page.waitForFunction(() => window.OPF_IMAGE_RULES && Object.values(window.OPF_IMAGE_RULES).some((rules) => rules.length === 3));
const slideCount = await page.locator('.woocommerce-product-gallery__image').count();
const initialIndex = await selectedGalleryIndex();
check('product renders a three-image WooCommerce gallery with a known initial slide', slideCount === 3 && initialIndex === 0);

await select('color', 'red');
await select('size', 'large');
check('AND-combination rule navigates to matching existing gallery image', await selectedGalleryIndex() === 1);

await select('size', 'small');
const external = process.env.OPF_IMAGE_CHANGE_EXTERNAL;
check('wildcard rule shows its external target when size condition stops matching', !!external && await page.locator('.woocommerce-product-gallery__image.flex-active-slide img').getAttribute('src') === external);

await select('color', 'blue');
check('a later rule navigates to another existing gallery slide', await selectedGalleryIndex() === 2);

await select('color', 'green');
check('mismatch restores the original main gallery slide', await selectedGalleryIndex() === initialIndex);

check('no uncaught browser errors during gallery transitions', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));

await browser.close();
process.exit(failures ? 1 : 0);
