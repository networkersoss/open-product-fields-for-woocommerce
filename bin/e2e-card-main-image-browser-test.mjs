import { createRequire } from 'node:module';
const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productUrl = process.env.OPF_CARD_IMAGE_URL;
const galleryUrl = process.env.OPF_CARD_IMAGE_GALLERY;
const externalUrl = process.env.OPF_CARD_IMAGE_EXTERNAL;
if (!productUrl || !galleryUrl || !externalUrl) throw new Error('OPF_CARD_IMAGE_URL, OPF_CARD_IMAGE_GALLERY, and OPF_CARD_IMAGE_EXTERNAL are required');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};
const activeIndex = async () => page.locator('.woocommerce-product-gallery__image').evaluateAll((slides) => slides.findIndex((slide) => slide.classList.contains('flex-active-slide')));
const choose = async (slug) => {
	await page.locator(`[data-opf-field="finish"] input[type="radio"][value="${slug}"]`).check({ force: true });
	await page.waitForTimeout(150);
};

await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
await page.locator('.woocommerce-product-gallery').waitFor({ state: 'visible' });
await page.waitForFunction(() => window.OPF_FIELDS && Object.values(window.OPF_FIELDS).some((fields) => fields.finish?.change_product_image));
const initial = await activeIndex();
check('card product starts on its original main image and renders image-bearing radio cards', initial === 0 && await page.locator('[data-opf-field="finish"] .opf-card__image').count() === 2);

await choose('gallery');
check('selecting a card targets its existing WooCommerce gallery image', await activeIndex() === 1);

await choose('external');
check('selecting a card with an external image swaps the active gallery image URL', await page.locator('.woocommerce-product-gallery__image.flex-active-slide img').getAttribute('src') === externalUrl);

await choose('unmatched');
check('selecting a card without an image target restores the original image and slide', await activeIndex() === initial && await page.locator('.woocommerce-product-gallery__image.flex-active-slide img').getAttribute('src') === await page.locator('.woocommerce-product-gallery__image').first().locator('img').getAttribute('src'));

await page.reload({ waitUntil: 'domcontentloaded' });
await page.locator('.woocommerce-product-gallery').waitFor({ state: 'visible' });
check('clearing the previous card selection by reloading restores the original slide', await activeIndex() === 0);
check('card image selection and restoration produce no uncaught browser errors', errors.length === 0);
if (errors.length) console.log(errors.join('\n'));

await browser.close();
process.exit(failures ? 1 : 0);
