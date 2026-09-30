import { createRequire } from 'node:module';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productId = Number(process.env.OPF_HIDE_VALUES_PRODUCT_ID || 0);
const productUrl = process.env.OPF_HIDE_VALUES_PRODUCT_URL;
const groupId = String(process.env.OPF_HIDE_VALUES_GROUP_ID || '');
if (!productId || !productUrl || !groupId) throw new Error('OPF_HIDE_VALUES_PRODUCT_ID, OPF_HIDE_VALUES_PRODUCT_URL, and OPF_HIDE_VALUES_GROUP_ID are required');

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const api = `${base}/wp-json/wc/store/v1`;
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
let cartKey = '';
const check = (name, ok, details = '') => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${details ? `: ${details}` : ''}`);
	if (!ok) failures++;
};

try {
	await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
	const form = page.locator('form.cart');
	check('product renders the configured OPF fields', await form.locator(`[name="opf[${groupId}][finish]"]`).count() === 1);

	const initialCart = await context.request.get(`${api}/cart`);
	const nonce = initialCart.headers().nonce;
	const add = await context.request.post(`${api}/cart/add-item`, {
		headers: nonce ? { Nonce: nonce } : {},
		data: { id: productId, quantity: 1, opf_fields: { [groupId]: {
			finish: 'premium', cart_note: 'cart value', checkout_note: 'checkout value', order_note: 'order value', public_note: 'public value',
		} } },
	});
	const addBody = await add.text();
	check('Store API accepts all fixture values', add.ok(), `${add.status()} ${addBody.slice(0, 800)}`);
	if (!add.ok()) throw new Error(`Store API add-item failed: ${add.status()} ${addBody.slice(0, 1600)}`);
	const cart = await (await context.request.get(`${api}/cart`, { headers: { Referer: `${base}/cart/` } })).json();
	const item = (cart.items || []).find((entry) => Number(entry.id) === productId);
	cartKey = item?.key || '';
	const cartRows = item?.item_data || [];
	const cartLabels = cartRows.map((row) => row.name);
	check('Cart Block response suppresses only hide_cart fields', !!item && !cartLabels.includes('Finish') && !cartLabels.includes('Cart secret') && cartLabels.includes('Checkout secret') && cartLabels.includes('Order secret') && cartLabels.includes('Public note'), JSON.stringify(cartRows));
	check('hidden cart field pricing still contributes to cart totals', Number(item?.totals?.line_total || 0) > 1000, JSON.stringify({ prices: item?.prices, totals: item?.totals }));

	const cartPage = await page.goto(`${base}/cart/`, { waitUntil: 'domcontentloaded' });
	const cartText = await page.locator('.cart_item').first().innerText();
	check('classic Cart page hides only the cart-configured values', !!cartPage && cartPage.ok() && !cartText.includes('Finish') && !cartText.includes('Cart secret') && cartText.includes('Checkout secret') && cartText.includes('Order secret') && cartText.includes('Public note'));
	const checkoutPage = await page.goto(`${base}/checkout/`, { waitUntil: 'domcontentloaded' });
	const checkoutText = await page.locator('.cart_item').first().innerText();
	check('classic Checkout page hides only checkout-configured values', !!checkoutPage && checkoutPage.ok() && checkoutText.includes('Finish') && checkoutText.includes('Cart secret') && !checkoutText.includes('Checkout secret') && checkoutText.includes('Order secret') && checkoutText.includes('Public note'));
	const checkoutCart = await page.evaluate(async (url) => {
		const response = await fetch(url, { credentials: 'same-origin' });
		return { status: response.status, data: await response.json() };
	}, `${api}/cart`);
	const checkoutItem = (checkoutCart.data.items || []).find((entry) => Number(entry.id) === productId);
	const checkoutRows = checkoutItem?.item_data || [];
	const checkoutLabels = checkoutRows.map((row) => row.name);
	check('Checkout-origin Store API response suppresses only hide_checkout fields', checkoutCart.status === 200 && !!checkoutItem && checkoutLabels.includes('Finish') && checkoutLabels.includes('Cart secret') && !checkoutLabels.includes('Checkout secret') && checkoutLabels.includes('Order secret') && checkoutLabels.includes('Public note'), JSON.stringify(checkoutRows));
	check('no uncaught browser errors', errors.length === 0, JSON.stringify(errors));
} finally {
	if (cartKey) {
		const cart = await context.request.get(`${api}/cart`).catch(() => null);
		const nonce = cart?.headers().nonce;
		await context.request.post(`${api}/cart/remove-item`, { headers: nonce ? { Nonce: nonce } : {}, data: { key: cartKey } }).catch(() => null);
	}
	await context.close();
	await browser.close();
}

if (failures) process.exit(1);
