import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const slug = process.env.OPF_CHILD_PRODUCT_SLUG || 'opf-e2e-child-products-parent';
const ids = (process.env.OPF_CHILD_PRODUCT_IDS || '').split(',').map(Number);
if (ids.length !== 3 || ids.some((id) => !id)) throw new Error('Set OPF_CHILD_PRODUCT_IDS to the three fixture IDs.');
const browser = await chromium.launch();
let failures = 0;
const check = (name, ok) => { console.log(`${ok ? 'ok' : 'FAIL'} ${name}`); if (!ok) failures++; };
const makePage = async () => {
	const context = await browser.newContext();
	const page = await context.newPage();
	const errors = [];
	page.on('pageerror', (error) => errors.push(error.message));
	return { context, page, errors };
};

const { context, page, errors } = await makePage();
await page.goto(`${base}/product/${encodeURIComponent(slug)}/`, { waitUntil: 'domcontentloaded' });
const choices = page.locator('.opf-child-products--images .opf-child-product');
await choices.first().waitFor({ state: 'visible' });
check('specific linked products render as image cards', await choices.count() === 3);
const categorySelect = page.locator('select[name*="[category_items]"]');
check('category source exposes all three fixture products', await categorySelect.locator('option').count() === 3);
const zoom = page.locator('.opf-child-product-image-zoom').first();
await zoom.scrollIntoViewIfNeeded();
await page.screenshot({ path: '/tmp/opf-child-products-product.png', fullPage: false });
await zoom.hover();
const hoverTransform = await zoom.locator('img').evaluate((img) => getComputedStyle(img).transform);
check('image zoom enlarges on hover', hoverTransform !== 'none');
await zoom.focus();
check('image zoom target is keyboard focusable and labelled', await zoom.getAttribute('aria-label') !== null && await zoom.evaluate((node) => node.tabIndex === 0));

for (const id of ids.slice(0, 2)) {
	await page.locator(`.opf-child-products--images input[name$="[${id}]"]`).fill('1');
}
await page.locator('form.cart .single_add_to_cart_button').click();
await page.waitForTimeout(500);
await page.goto(`${base}/cart/`, { waitUntil: 'domcontentloaded' });
const cartRows = page.locator('tr.cart_item');
check('parent and selected child products become native cart lines', await cartRows.count() === 3 && await page.getByText('OPF E2E Child Products Parent').count() > 0 && await page.getByText('OPF E2E Child A').count() > 0 && await page.getByText('OPF E2E Child B').count() > 0);
const rowFor = (name) => page.locator('tr.cart_item').filter({ has: page.locator('.product-name a').getByText(name, { exact: true }) });
const parentRow = rowFor('OPF E2E Child Products Parent');
const parentQty = parentRow.locator('input.qty');
await parentQty.fill('2');
await page.locator('button[name="update_cart"]').click();
await page.waitForTimeout(500);
const childARow = rowFor('OPF E2E Child A');
check('changing parent quantity scales linked child quantities', await childARow.locator('input.qty').inputValue() === '2');
const totalText = await page.locator('.order-total .amount, .cart_totals .order-total').last().innerText().catch(() => '');
check('WooCommerce tax is applied to the native child product lines', /3[.,]20|10%/.test(totalText) || await page.locator('.tax-rate').count() > 0);
await parentRow.locator('a.remove').click();
await page.waitForTimeout(500);
check('removing the parent also removes all linked child lines', await page.locator('tr.cart_item').count() === 0);
check('product/cart pages have no uncaught JavaScript errors', errors.length === 0);
await context.close();

const lowStock = await makePage();
await lowStock.page.goto(`${base}/product/${encodeURIComponent(slug)}/`, { waitUntil: 'domcontentloaded' });
await lowStock.page.locator(`.opf-child-products--images input[name$="[${ids[2]}]"]`).fill('2');
await lowStock.page.locator('form.cart .single_add_to_cart_button').click();
await lowStock.page.waitForTimeout(400);
const stockRejected = await lowStock.page.getByText(/not have enough stock|no longer available/i).count() > 0;
await lowStock.page.goto(`${base}/cart/`, { waitUntil: 'domcontentloaded' });
check('insufficient child stock rejects the bundle atomically', await lowStock.page.locator('tr.cart_item').count() === 0 && stockRejected);
check('stock validation has no uncaught JavaScript errors', lowStock.errors.length === 0);
await lowStock.context.close();
await browser.close();
if (failures) process.exit(1);
