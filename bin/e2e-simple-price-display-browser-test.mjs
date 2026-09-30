import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!passwordFile) throw new Error('OPF_E2E_PASSWORD_FILE is required');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};
const productId = Number(process.env.OPF_E2E_SIMPLE_PRODUCT_ID);
const secondId = Number(process.env.OPF_E2E_HIDDEN_PRODUCT_ID);
const variableUrl = `${base}/product/opf-e2e-variable-price-display-product/`;
if (!productId || !secondId) throw new Error('Fixture product IDs are required');
const editUrl = (id) => `${base}/wp-admin/post.php?post=${id}&action=edit`;
const detailUrl = `${base}/product/opf-e2e-simple-price-display-product/`;
const hiddenUrl = `${base}/product/opf-e2e-hidden-price-product/`;

async function priceOnProduct(url) {
	await page.goto(url, { waitUntil: 'domcontentloaded' });
	const price = page.locator('.wp-block-group.woocommerce.product .wp-block-woocommerce-product-price').first();
	return { count: await price.count(), text: ((await price.textContent()) || '').trim(), html: await price.innerHTML() };
}

async function saveProductDisplay(id, mode, label) {
	await page.goto(editUrl(id), { waitUntil: 'domcontentloaded' });
	await page.locator('select[name="_opf_price_display"]').selectOption(mode);
	await page.locator('input[name="_opf_price_label"]').fill(label);
	await page.locator('#publish').click();
	await page.waitForLoadState('domcontentloaded');
}

try {
	let price = await priceOnProduct(detailUrl);
	check('simple product keeps WooCommerce price by default', price.count === 1 && /\$10(?:\.00)?/.test(price.text) && !price.html.includes('opf-product-price-label'));
	price = await priceOnProduct(hiddenUrl);
	check('another simple product can independently hide its price', price.count === 1 && !price.text);

	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	await page.goto(editUrl(productId), { waitUntil: 'domcontentloaded' });
	check('price display controls are in this product’s General pricing panel', await page.locator('select[name="_opf_price_display"]').count() === 1 && await page.locator('input[name="_opf_price_label"]').count() === 1);

	await saveProductDisplay(productId, 'hide', 'Starting at');
	price = await priceOnProduct(detailUrl);
	check('per-product hide mode removes the simple detail price', price.count === 1 && !price.text);
	price = await priceOnProduct(hiddenUrl);
	check('saving one product does not change another product setting', price.count === 1 && !price.text);

	await page.goto(`${base}/shop/`, { waitUntil: 'domcontentloaded' });
	const productCard = page.locator('.wc-block-product-template li, li.product').filter({ has: page.locator('a[href*="opf-e2e-simple-price-display-product"]') }).first();
	check('per-product hide does not change shop archive price', await productCard.locator('.wc-block-components-product-price, .price').count() === 1 && /\$10(?:\.00)?/.test((await productCard.textContent()) || ''));
	await page.goto(detailUrl, { waitUntil: 'domcontentloaded' });
	const relatedCard = page.locator('.wc-block-product-template li, li.product').filter({ has: page.locator('a[href*="opf-e2e-hidden-price-product"]') }).first();
	check('current product setting does not hide a related simple product price', await relatedCard.count() === 1 && /\$20(?:\.00)?/.test((await relatedCard.textContent()) || ''));
	price = await priceOnProduct(variableUrl);
	check('simple-product setting does not change variable-product pricing', price.count === 1 && /\$12(?:\.00)?/.test(price.text));

	await page.goto(detailUrl, { waitUntil: 'domcontentloaded' });
	await page.goto(detailUrl, { waitUntil: 'domcontentloaded' });
	const cartResult = await page.evaluate(async (id) => {
		const cartResponse = await fetch('/wp-json/wc/store/v1/cart', { credentials: 'same-origin' });
		const nonce = cartResponse.headers.get('Nonce');
		const addResponse = await fetch('/wp-json/wc/store/v1/cart/add-item', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', ...(nonce ? { Nonce: nonce } : {}) },
			body: JSON.stringify({ id, quantity: 1 }),
		});
		const added = await addResponse.json();
		const item = added.items?.find((candidate) => candidate.id === id);
		let removed = false;
		if (item?.key) {
			const removeResponse = await fetch('/wp-json/wc/store/v1/cart/remove-item', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', ...(nonce ? { Nonce: nonce } : {}) },
				body: JSON.stringify({ key: item.key }),
			});
			removed = removeResponse.ok;
		}
		return { status: addResponse.status, name: item?.name, prices: item?.prices, removed };
	}, productId);
	check('Store API cart price stays unchanged while product-page price is hidden', cartResult.status === 201 && cartResult.name?.includes('Simple Price Display Product') && Number(cartResult.prices?.price) === 1000 && cartResult.prices?.currency_code === 'USD');
	check('Store API cart fixture line is removed after verification', cartResult.removed);

	await saveProductDisplay(productId, 'before', 'Starting at');
	price = await priceOnProduct(detailUrl);
	check('label-before mode preserves price and places a plain text label first', price.text.includes('Starting at') && price.text.indexOf('Starting at') < price.text.indexOf('$10'));
	await saveProductDisplay(productId, 'after', 'each');
	price = await priceOnProduct(detailUrl);
	check('label-after mode places its label after the WooCommerce price', price.text.indexOf('$10') >= 0 && price.text.indexOf('$10') < price.text.indexOf('each'));
	await saveProductDisplay(productId, 'replace', 'Request a quote');
	price = await priceOnProduct(detailUrl);
	check('replace mode shows only the configured text on this product', price.text === 'Request a quote' && !price.text.includes('$10'));
	await saveProductDisplay(productId, 'default', '');
	price = await priceOnProduct(detailUrl);
	check('default mode restores original WooCommerce price markup', /\$10(?:\.00)?/.test(price.text) && !price.html.includes('opf-product-price-label'));
	check('simple-price browser flow has no uncaught JavaScript errors', errors.length === 0);
	console.log(JSON.stringify({ pageErrors: errors, price }));
} finally {
	await browser.close();
}
if (failures) process.exit(1);
