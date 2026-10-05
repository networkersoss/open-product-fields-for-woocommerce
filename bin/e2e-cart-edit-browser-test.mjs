import { createRequire } from 'node:module';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const productId = Number(process.env.OPF_CART_EDIT_PRODUCT_ID || 0);
const productUrl = process.env.OPF_CART_EDIT_PRODUCT_URL;
if (!productId || !productUrl) throw new Error('OPF_CART_EDIT_PRODUCT_ID and OPF_CART_EDIT_PRODUCT_URL are required');

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const request = context.request;
const api = `${base}/wp-json/wc/store/v1`;
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
let cartKey = '';
const check = (name, ok, details = '') => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${details ? `: ${details}` : ''}`);
	if (!ok) failures++;
};
const cartRequest = async (path, data) => {
	const current = await request.get(`${api}/cart`);
	const nonce = current.headers().nonce;
	return request.post(`${api}/${path}`, { headers: nonce ? { Nonce: nonce } : {}, data });
};

try {
	await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
	const finishName = await page.locator('form.cart select[name^="opf["][name$="][finish]"]').getAttribute('name');
	const groupMatch = finishName?.match(/^opf\[(\d+)\]\[finish\]$/);
	check('product page renders the editable OPF field group', !!groupMatch);
	if (!groupMatch) throw new Error('Could not determine fixture field-group ID');
	const groupId = groupMatch[1];

	const initialCartResponse = await request.get(`${api}/cart`);
	const initialNonce = initialCartResponse.headers().nonce;
	const add = await request.post(`${api}/cart/add-item`, {
		headers: initialNonce ? { Nonce: initialNonce } : {},
		data: { id: productId, quantity: 1, opf_fields: { [groupId]: { finish: 'blue', message: 'Initial engraving' } } },
	});
	check('Store API adds the initial customized line', add.ok());
	const initialData = await (await request.get(`${api}/cart`)).json();
	const initialItem = (initialData.items || []).find((item) => Number(item.id) === productId);
	cartKey = initialItem?.key || '';
	check('initial cart line has OPF values and signed edit permalink', !!initialItem && !!cartKey && initialItem.permalink.includes('opf_edit_cart_item=') && initialItem.permalink.includes('_wpnonce='), JSON.stringify({ key: cartKey, permalink: initialItem?.permalink }));
	const initialTotal = Number(initialItem?.totals?.line_total || 0);

	await page.goto(`${base}/cart/`, { waitUntil: 'domcontentloaded' });
	const blockEditLink = page.locator('.wc-block-cart-items__row a[href*="opf_edit_cart_item"]').first();
	await blockEditLink.waitFor({ state: 'visible' });
	check('real Woo Cart Block exposes the signed edit link on its product permalink', (await blockEditLink.getAttribute('href'))?.includes(`opf_edit_cart_item=${cartKey}`));
	await blockEditLink.click();
	await page.waitForURL((url) => url.searchParams.get('opf_edit_cart_item') === cartKey);
	const form = page.locator('form.cart');
	const finish = form.locator(`select[name="opf[${groupId}][finish]"]`);
	const message = form.locator(`input[name="opf[${groupId}][message]"]`);
	check('edit link restores the prior selection and text', await finish.inputValue() === 'blue' && await message.inputValue() === 'Initial engraving');
	check('edit form carries the original cart key and valid nonce', await form.locator('input[name="opf_edit_cart_item"]').inputValue() === cartKey && (await form.locator('input[name="opf_edit_cart_nonce"]').inputValue()).length > 0);
	check('Woo add-to-cart button changes to Update options', (await form.locator('.single_add_to_cart_button').innerText()).includes('Update options'));
	await finish.selectOption('red');
	await message.fill('Updated engraving');
	await form.locator('input.qty').fill('2');
	const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
	await form.locator('.single_add_to_cart_button').click();
	await navigation;

	const updatedCartResponse = await request.get(`${api}/cart`);
	const updatedData = await updatedCartResponse.json();
	const updatedItems = (updatedData.items || []).filter((item) => Number(item.id) === productId);
	const updatedItem = updatedItems[0];
	const itemData = updatedItem?.item_data || [];
	const hasRed = itemData.some((value) => value.name === 'Finish' && value.value === 'Red');
	const hasMessage = itemData.some((value) => value.name === 'Personal message' && value.value === 'Updated engraving');
	const updatedTotal = Number(updatedItem?.totals?.line_total || 0);
	check('update replaces the original cart row without changing its key or duplicating the line', updatedItems.length === 1 && updatedItem?.key === cartKey, JSON.stringify({ count: updatedItems.length, key: updatedItem?.key }));
	check('Store API cart shows edited option and text values', hasRed && hasMessage, JSON.stringify(itemData));
	check('submitted quantity is retained and pricing recalculates from edited choice', Number(updatedItem?.quantity) === 2 && updatedTotal > initialTotal, JSON.stringify({ initialTotal, initialPrices: initialItem?.prices, initialTotals: initialItem?.totals, updatedTotal, updatedPrices: updatedItem?.prices, updatedTotals: updatedItem?.totals, cartTotals: updatedData.totals, quantity: updatedItem?.quantity }));
	check('the edited Cart Block permalink still targets this cart line', updatedItem?.permalink.includes(`opf_edit_cart_item=${cartKey}`));
	check('cart edit round-trip produced no uncaught browser errors', errors.length === 0, JSON.stringify(errors));
} finally {
	if (cartKey) {
		const cart = await request.get(`${api}/cart`).catch(() => null);
		const data = cart?.ok() ? await cart.json().catch(() => ({})) : {};
		const item = (data.items || []).find((entry) => entry.key === cartKey);
		if (item) await cartRequest('cart/remove-item', { key: cartKey }).catch(() => null);
	}
	await context.close();
	await browser.close();
}

if (failures) process.exit(1);
