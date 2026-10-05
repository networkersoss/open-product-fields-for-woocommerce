import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const api = `${base}/wp-json/wc/store/v1`;
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
const productId = Number(process.env.OPF_LAYERED_PRODUCT_ID);
const groupId = Number(process.env.OPF_LAYERED_GROUP_ID);
const productUrl = process.env.OPF_LAYERED_PRODUCT_URL;
if (!passwordFile || !productId || !groupId || !productUrl) throw new Error('Layered-image cart E2E environment is incomplete');

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
const page = await context.newPage();
const errors = [];
const cartResponses = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', (response) => {
	if (response.url().includes('/wp-json/wc/store/v1/cart')) cartResponses.push({ status: response.status(), url: response.url() });
});
let failures = 0;
let delayBeforeRun = true;
const check = (name, ok, detail = '') => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${detail ? `: ${detail}` : ''}`);
	if (!ok) failures++;
};

async function cart() {
	const response = await context.request.get(`${api}/cart`);
	if (!response.ok()) throw new Error(`Store API cart GET failed: ${response.status()}`);
	return { data: await response.json(), nonce: response.headers().nonce || '' };
}

async function removeFixtureLines() {
	for (let attempt = 0; attempt < 5; attempt++) {
		const current = await cart();
		const item = (current.data.items || []).find((entry) => Number(entry.id) === productId);
		if (!item) return;
		const response = await context.request.post(`${api}/cart/remove-item`, {
			headers: current.nonce ? { Nonce: current.nonce } : {},
			data: { key: item.key },
		});
		if (!response.ok()) throw new Error(`Could not remove layered fixture cart item: ${response.status()}`);
	}
	throw new Error('Layered fixture cart item remained after five cleanup attempts');
}

async function setDelay(enabled) {
	await page.goto(`${base}/wp-admin/post.php?post=${productId}&action=edit`, { waitUntil: 'domcontentloaded' });
	await page.locator('#woocommerce-product-data .product_data_tabs a[href="#opf_layered_images_product_data"]').click();
	const delay = page.locator('#_opf_layered_delay');
	if (enabled) await delay.check(); else await delay.uncheck();
	await page.locator('#publish').click();
	await page.waitForLoadState('domcontentloaded');
	delayBeforeRun = enabled;
}

try {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
	await page.waitForFunction(() => Array.isArray(window.OPF_LAYERED_IMAGES) && window.OPF_LAYERED_IMAGES.length === 1);
	const baseImageUrl = await page.evaluate(() => window.OPF_LAYERED_IMAGES[0].base_url);
	await removeFixtureLines();

	const add = async (fields) => {
		const current = await cart();
		const data = { id: productId, quantity: 1 };
		if (fields) data.opf_fields = { [groupId]: fields };
		const response = await context.request.post(`${api}/cart/add-item`, {
			headers: current.nonce ? { Nonce: current.nonce } : {},
			data,
		});
		const result = await response.json();
		if (!response.ok()) console.log('Store API add-item error:', JSON.stringify(result));
		return { ok: response.ok(), response: result };
	};

	const blue = await add({ color: ['blue'], trim: ['gold'] });
	check('Store API accepts a cart line with two independently mapped image choices', blue.ok);
	const red = await add({ color: ['red'] });
	check('Store API accepts a second cart line with a different image choice', red.ok);
	const delayedEmpty = await add(null);
	check('Store API accepts an unselected line while delay-until-selection is enabled', delayedEmpty.ok);

	let current = await cart();
	let fixtureItems = (current.data.items || []).filter((item) => Number(item.id) === productId);
	const generated = fixtureItems.map((item) => item.images?.[0]?.src || '');
	const generatedImages = generated.filter((src) => src.includes('/opf-layered-images/'));
	check('different cart selections produce distinct generated composites without collisions', fixtureItems.length === 3 && generatedImages.length === 2 && new Set(generatedImages).size === 2, JSON.stringify({ count: fixtureItems.length, images: generated }));
	const original = fixtureItems.find((item) => !(item.images?.[0]?.src || '').includes('/opf-layered-images/'));
	check('delay mode leaves the original Woo product image when no mapped choice is selected', !!original);
	const composite = fixtureItems.find((item) => (item.images?.[0]?.src || '').includes('/opf-layered-images/'))?.images?.[0]?.src;
	if (composite) {
		const pixelProof = await page.evaluate(async ({ baseUrl, compositeUrl }) => {
			const pixel = async (url) => {
				const image = new Image();
				image.src = url;
				await image.decode();
				const canvas = document.createElement('canvas');
				canvas.width = image.naturalWidth;
				canvas.height = image.naturalHeight;
				const context = canvas.getContext('2d');
				context.drawImage(image, 0, 0);
				return { width: canvas.width, height: canvas.height, center: Array.from(context.getImageData(2, 1, 1, 1).data) };
			};
			return { base: await pixel(baseUrl), composite: await pixel(compositeUrl) };
		}, { baseUrl: baseImageUrl, compositeUrl: composite });
		check('generated cart image is a readable 8×4 PNG with selected layer pixels composited over the base', pixelProof.base.width === 8 && pixelProof.base.height === 4 && pixelProof.composite.width === 8 && pixelProof.composite.height === 4 && pixelProof.base.center.join(',') !== pixelProof.composite.center.join(','), JSON.stringify(pixelProof));
	}

	await page.goto(`${base}/cart/`, { waitUntil: 'domcontentloaded' });
	await page.waitForFunction((title) => document.body.innerText.includes(title), 'OPF E2E Layered Image Product', { timeout: 10000 }).catch(() => {});
	const cartBlockEvidence = await page.evaluate(() => ({
		block: !!document.querySelector('.wp-block-woocommerce-cart, [data-block-name="woocommerce/cart"]'),
		images: Array.from(document.querySelectorAll('img[src*="/opf-layered-images/"]')).map((image) => image.getAttribute('src')),
		fixtureText: document.body.innerText.includes('OPF E2E Layered Image Product'),
	}));
	const browserCartState = await page.evaluate(async () => {
		const response = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
		const data = await response.json();
		return { status: response.status, items: (data.items || []).map((item) => ({ name: item.name, image: item.images?.[0]?.src })) };
	});
	check('actual Woo Cart Block renders the selected composite image for the fixture item', cartBlockEvidence.block && cartBlockEvidence.fixtureText && cartBlockEvidence.images.length >= 2, JSON.stringify({ ...cartBlockEvidence, browserCartState, cartResponses }));

	await page.goto(`${base}/checkout/`, { waitUntil: 'domcontentloaded' });
	await page.waitForFunction((title) => document.body.innerText.includes(title), 'OPF E2E Layered Image Product', { timeout: 10000 }).catch(() => {});
	const checkoutBlockEvidence = await page.evaluate(() => ({
		block: !!document.querySelector('.wp-block-woocommerce-checkout, [data-block-name="woocommerce/checkout"]'),
		images: Array.from(document.querySelectorAll('img[src*="/opf-layered-images/"]')).map((image) => image.getAttribute('src')),
		fixtureText: document.body.innerText.includes('OPF E2E Layered Image Product'),
	}));
	check('actual Woo Checkout Block renders the same selected composite image', checkoutBlockEvidence.block && checkoutBlockEvidence.fixtureText && checkoutBlockEvidence.images.length >= 2, JSON.stringify({ ...checkoutBlockEvidence, browserCartState, cartResponses }));

	await removeFixtureLines();
	await setDelay(false);
	const baseOnly = await add(null);
	check('Store API accepts a no-selection line when delay is disabled', baseOnly.ok);
	current = await cart();
	fixtureItems = (current.data.items || []).filter((item) => Number(item.id) === productId);
	const baseOnlySrc = fixtureItems[0]?.images?.[0]?.src || '';
	check('no-delay cart fallback generates the base-only image when no mapped choice is selected', fixtureItems.length === 1 && baseOnlySrc.includes('/opf-layered-images/') && baseOnlySrc !== composite, baseOnlySrc);
	await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
	const baseOnlyPixelProof = await page.evaluate(async ({ baseUrl, compositeUrl }) => {
		const pixel = async (url) => {
			const image = new Image();
			image.src = url;
			await image.decode();
			const canvas = document.createElement('canvas');
			canvas.width = image.naturalWidth;
			canvas.height = image.naturalHeight;
			const context = canvas.getContext('2d');
			context.drawImage(image, 0, 0);
			return { width: canvas.width, height: canvas.height, center: Array.from(context.getImageData(2, 1, 1, 1).data) };
		};
		return { base: await pixel(baseUrl), composite: await pixel(compositeUrl) };
	}, { baseUrl: baseImageUrl, compositeUrl: baseOnlySrc });
	check('no-delay fallback composite pixels equal the configured base image without selected layers', baseOnlyPixelProof.base.width === 8 && baseOnlyPixelProof.base.height === 4 && baseOnlyPixelProof.base.center.join(',') === baseOnlyPixelProof.composite.center.join(','), JSON.stringify(baseOnlyPixelProof));
	await removeFixtureLines();
	current = await cart();
	check('Store API browser cart contains no layered fixture lines after removal', !(current.data.items || []).some((item) => Number(item.id) === productId));
	await setDelay(true);
	check('block cart browser session has no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
} finally {
	try {
		await removeFixtureLines();
	} catch (error) {
		console.error(`Cart cleanup failed: ${error.message}`);
	}
	if (!delayBeforeRun) {
		try { await setDelay(true); } catch (error) { console.error(`Delay setting restore failed: ${error.message}`); }
	}
	await context.close();
	await browser.close();
}

if (failures) process.exit(1);
