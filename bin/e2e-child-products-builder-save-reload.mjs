// Real admin builder roundtrip for linked child-products configuration.
// This file refuses to run except against the marked disposable clone.
import fs from 'node:fs';
import crypto from 'node:crypto';
import { createRequire } from 'node:module';
import { isDeepStrictEqual } from 'node:util';

const wpPath = '/tmp/opf-child-builder-save-reload-wp';
const base = 'http://127.0.0.1:8465';
const evidence = '/tmp/opf-child-builder-save-reload-evidence';
if (!fs.existsSync(`${wpPath}/.opf-disposable-e2e`)) throw new Error('Disposable clone marker missing');
const clonePlugin = `${wpPath}/wp-content/plugins/open-product-fields-for-woocommerce`;
for (const relative of ['open-product-fields-for-woocommerce.php', 'assets/js/opf-builder.js', 'includes/Engine/FieldGroup.php']) {
	const source = fs.readFileSync(`/tmp/opf-child-builder-save-reload/${relative}`);
	const installed = fs.readFileSync(`${clonePlugin}/${relative}`);
	if (!crypto.timingSafeEqual(crypto.createHash('sha256').update(source).digest(), crypto.createHash('sha256').update(installed).digest())) throw new Error(`Disposable clone source mismatch: ${relative}`);
}
const state = JSON.parse(fs.readFileSync(`${evidence}/state.json`, 'utf8'));
const password = fs.readFileSync('/tmp/opf-child-builder-save-reload-admin-password', 'utf8');
const playwrightRoot = process.env.OPF_PLAYWRIGHT_ROOT || '/home/followersya-5hqi7/followersya.com';
const { chromium } = createRequire(`${playwrightRoot}/index.js`)('playwright');
const checks = [];
const errors = [];
const productSearchResponses = [];
const check = (label, pass) => {
	checks.push({ label, pass: !!pass });
	if (!pass) throw new Error(label);
};
const browser = await chromium.launch({ headless: true });
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	page.setDefaultTimeout(7000);
	page.on('pageerror', e => errors.push(e.message));
	page.on('response', response => {
		const request = response.request();
		if (response.url().includes('/wp-admin/admin-ajax.php') && `${response.url()} ${request.postData() || ''}`.includes('woocommerce_json_search_products')) {
			productSearchResponses.push({ status: response.status(), action: 'woocommerce_json_search_products' });
		}
	});
	let saveResponse = null;
	page.on('response', async response => {
		if (response.url().includes('/wp-json/opf/v1/groups') && response.request().method() === 'POST') {
			try { saveResponse = { status: response.status(), body: await response.json() }; } catch {}
		}
	});
	await page.goto(`${base}/wp-login.php`);
	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/wp-admin/);
	await page.goto(`${base}/wp-admin/post-new.php?post_type=opf_field_group`);
	await page.locator('#title').fill('OPF child-products builder save reload proof');

	const addField = async (label, subtype, selection = 'manual') => {
		await page.locator('#opf-builder-app').getByRole('button', { name: '+ Add field', exact: true }).click();
		const index = await page.locator('#opf-builder-fields > .opf-b-field').count() - 1;
		const card = page.locator('#opf-builder-fields > .opf-b-field').nth(index);
		await card.locator('.opf-b-field-head select').selectOption('products');
		await card.locator('.opf-b-label').fill(label);
		await card.locator('select[aria-label="Display"]').selectOption(subtype);
		await card.locator('select[aria-label="Product selection"]').selectOption(selection);
		return card;
	};
	const addManualProduct = async (fieldIndex, productId, productName) => {
		const card = page.locator('#opf-builder-fields > .opf-b-field').nth(fieldIndex);
		const picker = card.locator('select.opf-b-product-search');
		await picker.locator('xpath=following-sibling::span').click();
		const search = page.locator('.select2-container--open .select2-search__field');
		await search.fill(productName);
		const result = page.locator('.select2-container--open .select2-results__option').filter({ hasText: `#${productId}` });
		await result.waitFor();
		check(`WooCommerce product search returns ${productName} (#${productId})`, (await result.innerText()).includes(productName));
		await result.click();
		await card.locator('.opf-b-product-choice').last().waitFor();
	};

	let card = await addField('Manual card children', 'card');
	await addManualProduct(0, state.products.alpha, 'Builder child alpha');
	card = page.locator('#opf-builder-fields > .opf-b-field').nth(0);
	await addManualProduct(0, state.products.beta, 'Builder child beta');
	card = page.locator('#opf-builder-fields > .opf-b-field').nth(0);
	await card.locator('.opf-b-product-choice').nth(0).locator('select[aria-label="Price"]').selectOption('fixed');
	await card.locator('.opf-b-product-choice').nth(1).locator('select[aria-label="Price"]').selectOption('none');
	await card.locator('select[aria-label="Child quantity"]').selectOption('parent');
	check('manual card controls expose two real selected product rows', await card.locator('.opf-b-product-choice').count() === 2);

	card = await addField('Manual quantity children', 'card-qty');
	await addManualProduct(1, state.products.alpha, 'Builder child alpha');
	card = page.locator('#opf-builder-fields > .opf-b-field').nth(1);
	await addManualProduct(1, state.products.gamma, 'Builder child gamma');
	card = page.locator('#opf-builder-fields > .opf-b-field').nth(1);
	await card.locator('select[aria-label="Quantity controls"]').selectOption('plus_min');
	await card.locator('input[aria-label="Minimum total quantity"]').fill('2');
	await card.locator('input[aria-label="Maximum total quantity"]').fill('8');
	for (const [i, values] of [[1, 0, 4], [2, 1, 6]].entries()) {
		const inputs = card.locator('.opf-b-product-choice').nth(i).locator('.opf-b-product-qty');
		for (const [j, value] of values.entries()) await inputs.nth(j).fill(String(value));
	}
	await card.locator('.opf-b-product-choice').nth(0).locator('select[aria-label="Price"]').selectOption('fixed');
	await card.locator('.opf-b-product-choice').nth(1).locator('select[aria-label="Price"]').selectOption('none');
	check('manual card-qty controls expose per-choice and aggregate bounds', await card.locator('.opf-b-product-qty').count() === 6);

	card = await addField('Category linked cards', 'vcard', 'category');
	await card.locator('select[aria-label="Product category"]').selectOption(String(state.category));
	await card.locator('input[aria-label="Maximum products"]').fill('2');
	await card.locator('select[aria-label="Sorting"]').selectOption('name_asc');
	await card.locator('select[aria-label="Price"]').selectOption('none');
	await card.locator('select[aria-label="Child quantity"]').selectOption('parent');
	check('category vcard editor exposes persisted query, price and child-quantity settings', await card.locator('select[aria-label="Product category"]').inputValue() === String(state.category));

	await page.locator('#opf-builder-app').getByRole('button', { name: 'Save', exact: true }).click();
	await page.getByText('Saved.', { exact: true }).waitFor({ timeout: 10000 });
	check('actual builder REST save created a field group', !!saveResponse?.body?.id && saveResponse.status === 200);
	const createdGroup = saveResponse.body;
	check('creation response contains all three products fields', createdGroup.data.fields.length === 3 && createdGroup.data.fields.every(field => field.type === 'products'));
	fs.mkdirSync(evidence, { recursive: true });
	fs.writeFileSync(`${evidence}/created-group.json`, JSON.stringify(createdGroup, null, 2));
	// A newly-created builder save keeps the browser at post-new.php. Open the
	// persisted group's real edit URL before testing the edit/reload roundtrip.
	await page.goto(`${base}/wp-admin/post.php?post=${createdGroup.id}&action=edit`);
	const openedModel = await page.locator('#opf-builder-app').evaluate(el => JSON.parse(el.dataset.model));
	check('created group opens with all three saved product fields', openedModel.fields.length === 3 && isDeepStrictEqual(openedModel, createdGroup.data));
	const editManual = page.locator('#opf-builder-fields > .opf-b-field').nth(0);
	check('edit route shows the saved manual linked-products settings',
		await editManual.locator('select[aria-label="Display"]').inputValue() === 'card' &&
		await editManual.locator('select[aria-label="Product selection"]').inputValue() === 'manual' &&
		await editManual.locator('select[aria-label="Child quantity"]').inputValue() === 'parent');
	const updateResponsePromise = page.waitForResponse(response => response.url().includes('/wp-json/opf/v1/groups') && response.request().method() === 'POST');
	await editManual.locator('select[aria-label="Child quantity"]').selectOption('one');
	await page.locator('#opf-builder-app').getByRole('button', { name: 'Save', exact: true }).click();
	const updateResponse = await updateResponsePromise;
	const updateBody = await updateResponse.json();
	await page.getByText('Saved.', { exact: true }).waitFor({ timeout: 10000 });
	check('actual builder edit updated the existing group', updateResponse.status() === 200 && updateBody.id === createdGroup.id && updateBody.data.fields[0].qty_method === 'one');
	const savedModel = updateBody.data;
	fs.writeFileSync(`${evidence}/saved-group.json`, JSON.stringify({ id: updateBody.id, title: updateBody.title, data: savedModel }, null, 2));
	await page.reload();
	const reloadedModel = await page.locator('#opf-builder-app').evaluate(el => JSON.parse(el.dataset.model));
	fs.writeFileSync(`${evidence}/reloaded-model.json`, JSON.stringify(reloadedModel, null, 2));
	check('fresh admin reload hydrates exact persisted builder model', isDeepStrictEqual(reloadedModel, savedModel));
	const reloadedManual = page.locator('#opf-builder-fields > .opf-b-field').nth(0);
	check('reloaded manual subtype, selection, free child and edited child qty are visible',
		await reloadedManual.locator('select[aria-label="Display"]').inputValue() === 'card' &&
		await reloadedManual.locator('select[aria-label="Product selection"]').inputValue() === 'manual' &&
		await reloadedManual.locator('select[aria-label="Child quantity"]').inputValue() === 'one' &&
		await reloadedManual.locator('.opf-b-product-choice').nth(1).locator('select[aria-label="Price"]').inputValue() === 'none');
	const reloadedQty = page.locator('#opf-builder-fields > .opf-b-field').nth(1);
	check('reloaded quantity subtype, mode and aggregate bounds are visible',
		await reloadedQty.locator('select[aria-label="Display"]').inputValue() === 'card-qty' &&
		await reloadedQty.locator('select[aria-label="Quantity controls"]').inputValue() === 'plus_min' &&
		await reloadedQty.locator('input[aria-label="Minimum total quantity"]').inputValue() === '2' &&
		await reloadedQty.locator('input[aria-label="Maximum total quantity"]').inputValue() === '8');
	const reloadedCategory = page.locator('#opf-builder-fields > .opf-b-field').nth(2);
	check('reloaded category selection, vcard subtype, query cap/sort and free price are visible',
		await reloadedCategory.locator('select[aria-label="Display"]').inputValue() === 'vcard' &&
		await reloadedCategory.locator('select[aria-label="Product selection"]').inputValue() === 'category' &&
		await reloadedCategory.locator('input[aria-label="Maximum products"]').inputValue() === '2' &&
		await reloadedCategory.locator('select[aria-label="Sorting"]').inputValue() === 'name_asc' &&
		await reloadedCategory.locator('select[aria-label="Price"]').inputValue() === 'none');
	check('all real WooCommerce product searches returned HTTP 200', productSearchResponses.length === 4 && productSearchResponses.every(response => response.status === 200));
	check('no uncaught admin browser errors', errors.length === 0);
	await page.screenshot({ path: `${evidence}/builder-saved-reloaded.png`, fullPage: true });
	fs.writeFileSync(`${evidence}/browser-results.json`, JSON.stringify({ browser: browser.version(), checks, errors, saveStatus: saveResponse?.status, groupId: saveResponse?.body?.id }, null, 2));
	await context.close();
} finally {
	await browser.close();
}
console.log(`${checks.length} admin builder checks passed.`);
