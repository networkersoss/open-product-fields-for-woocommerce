import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const slug = process.env.OPF_CHILD_PRODUCT_SLUG || 'opf-e2e-child-products-parent';
const parentId = Number(process.env.OPF_CHILD_PRODUCTS_PARENT || 15091);
const [childA, childB] = (process.env.OPF_CHILD_PRODUCT_IDS || '15088,15089').split(',').map(Number);
const api = `${base}/wp-json/wc/store/v1`;
const browser = await chromium.launch();
const context = await browser.newContext();
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => { console.log(`${ok ? 'ok' : 'FAIL'} ${name}`); if (!ok) failures++; };
const request = context.request;

await page.goto(`${base}/product/${encodeURIComponent(slug)}/`, { waitUntil: 'domcontentloaded' });
const fieldName = await page.locator('.opf-child-products--images input').first().getAttribute('name');
const match = fieldName && fieldName.match(/^opf\[(\d+)\]\[bundle_items\]/);
if (!match) throw new Error('Could not determine the rendered OPF field-group key.');
const groupId = match[1];
const getCart = async () => request.get(`${api}/cart`);
const initialCartResponse = await getCart();
const nonce = initialCartResponse.headers()['nonce'];
const headers = nonce ? { Nonce: nonce } : {};
const addResponse = await request.post(`${api}/cart/add-item`, {
	headers,
	data: { id: parentId, quantity: 1, opf_fields: { [groupId]: { bundle_items: { [childA]: '1', [childB]: '1', '15090': '0' } } } },
});
const addData = await addResponse.json();
if (!addResponse.ok()) console.log('Store API add error:', JSON.stringify(addData));
check('Store API accepts OPF child-product selection payload', addResponse.ok());
const cartResponse = await getCart();
const cart = await cartResponse.json();
const items = Array.isArray(cart.items) ? cart.items : [];
const parentItem = items.find((item) => Number(item.id) === parentId);
const childAItem = items.find((item) => Number(item.id) === childA);
const childBItem = items.find((item) => Number(item.id) === childB);
check('Store API cart returns native parent and linked child lines', !!parentItem && !!childAItem && !!childBItem && items.length === 3);
check('Store API cart calculates tax on native child product lines', Number(cart.totals?.total_tax || 0) > 0);

if (parentItem) {
	const cartNonce = cartResponse.headers()['nonce'] || nonce;
	const update = await request.post(`${api}/cart/update-item`, { headers: cartNonce ? { Nonce: cartNonce } : {}, data: { key: parentItem.key, quantity: 2 } });
	check('Store API accepts parent quantity update', update.ok());
	const updatedCart = await (await getCart()).json();
	const updatedChild = (updatedCart.items || []).find((item) => Number(item.id) === childA);
	check('Store API parent quantity update synchronizes child quantity', Number(updatedChild?.quantity) === 2);
	const updatedParent = (updatedCart.items || []).find((item) => Number(item.id) === parentId);
	const removeNonce = (await getCart()).headers()['nonce'] || cartNonce;
	const remove = await request.post(`${api}/cart/remove-item`, { headers: removeNonce ? { Nonce: removeNonce } : {}, data: { key: updatedParent?.key || parentItem.key } });
	check('Store API removes selected parent line', remove.ok());
	const emptyCart = await (await getCart()).json();
	check('Store API parent removal also removes all associated child lines', !Array.isArray(emptyCart.items) || emptyCart.items.length === 0);
}
check('Store API browser session has no uncaught JavaScript errors', errors.length === 0);
await context.close();
await browser.close();
if (failures) process.exit(1);
