import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const wpPath = process.env.OPF_CLONE_WP || '/tmp/opf-acf-e2e.GL3z2t/wordpress';
const fixturePath = `${wpPath}/wp-content/plugins/open-product-fields-for-woocommerce/bin/e2e-commerce-tax-test.php`;
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
const productUrl = `${base}/product/opf-e2e-commerce-tax-product/`;
const nonTaxableUrl = `${base}/product/opf-e2e-non-taxable-product/`;
const variableProductUrl = `${base}/product/opf-e2e-variable-tax-product/`;
if (!passwordFile) throw new Error('OPF_E2E_PASSWORD_FILE is required');

const wp = (...args) => execFileSync('wp', [
	`--path=${wpPath}`, '--skip-themes', '--user=admin', ...args,
], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }).trim();
const fixture = (...args) => wp('eval-file', fixturePath, ...args);
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const failures = [];
const pageErrors = [];
const previews = [];
const previewRequests = [];
let checks = 0;
page.on('pageerror', (error) => pageErrors.push(error.message));
page.on('request', (request) => {
	if (request.url().includes('/opf/v1/price-preview')) {
		try { previewRequests.push(request.postDataJSON()); } catch {}
	}
});
page.on('response', async (response) => {
	if (!response.url().includes('/opf/v1/price-preview')) return;
	try { previews.push({ status: response.status(), body: await response.json() }); } catch {}
});

const check = (name, ok, detail = '') => {
	checks++;
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${detail ? ` ${JSON.stringify(detail)}` : ''}`);
	if (!ok) failures.push(name);
};
const amount = (value) => Number(String(value || '').replace(/[^0-9,.-]/g, '').replace(/,(?=\d{3}(?:\D|$))/g, '').replace(',', '.'));
const totals = async () => page.locator('.opf-product-totals').evaluate((node) => ({
	product: node.querySelector('.opf-product-total')?.textContent?.trim() || '',
	options: node.querySelector('.opf-options-total')?.textContent?.trim() || '',
	grand: node.querySelector('.opf-grand-total')?.textContent?.trim() || '',
	busy: node.getAttribute('aria-busy'),
	statusHidden: node.querySelector('.opf-price-preview-status')?.hidden,
	statusText: node.querySelector('.opf-price-preview-status')?.textContent?.trim(),
}));
const selection = async () => page.evaluate(() => {
	const groupValues = {};
	Array.from(document.querySelectorAll('[data-opf-group] input:checked, [data-opf-group] select')).forEach((input) => {
		const match = input.name.match(/^opf\[([^\]]+)\]\[([^\]]+)\]/);
		if (!match) return;
		const [, groupId, fieldId] = match;
		if (!groupValues[groupId]) groupValues[groupId] = {};
		if (input.name.endsWith('[]')) {
			if (!Array.isArray(groupValues[groupId][fieldId])) groupValues[groupId][fieldId] = [];
			groupValues[groupId][fieldId].push(input.value);
		} else {
			groupValues[groupId][fieldId] = input.value;
		}
	});
	return {
		groups: Array.from(document.querySelectorAll('[data-opf-group]')).map((group) => group.getAttribute('data-opf-group')),
		groupValues,
		quantity: document.querySelector('form.cart input[name="quantity"], form.cart .qty')?.value,
		values: Array.from(document.querySelectorAll('[data-opf-group] input:checked, [data-opf-group] select')).map((input) => ({ name: input.name, value: input.value })),
		pricing: window.__opfPricingEvents?.at(-1),
	};
});
const selectPremium = async (url) => {
	previewRequests.length = 0;
	const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
	check(`served product returns 200 (${url.endsWith('non-taxable-product/') ? 'non-taxable' : 'taxable'})`, response?.status() === 200, response?.status());
	await page.evaluate(() => {
		window.__opfPricingEvents = [];
		document.addEventListener('opf:pricing', (event) => window.__opfPricingEvents.push(event.detail));
	});
	await page.locator('[data-opf-field="finish"] select').selectOption('premium');
	await page.locator('[data-opf-field="setup"] input[value="rush"]').check();
	await page.locator('form.cart input[name="quantity"], form.cart .qty').first().fill('3');
	await page.waitForFunction(() => {
		const summary = document.querySelector('.opf-product-totals');
		return summary && !summary.getAttribute('aria-busy') && summary.querySelector('.opf-grand-total')?.textContent?.trim();
	}, null, { timeout: 10000 });
	return {
		...(await totals()),
		request: previewRequests.at(-1),
		selection: await selection(),
	};
};

try {
	const login = await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	check('disposable WooCommerce clone responds', login?.status() === 200);
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	check('admin login reached clone dashboard', page.url().includes('/wp-admin/'));

	for (const pricesIncludeTax of [ 'no', 'yes' ]) {
		for (const shopDisplay of [ 'excl', 'incl' ]) {
			fixture('mode', pricesIncludeTax, shopDisplay);
			previews.length = 0;
			const actual = await selectPremium(productUrl);
			const response = previews.at(-1);
			check(`${pricesIncludeTax} prices / ${shopDisplay} display invokes Woo tax preview`, response?.status === 200 && response.body.product_total !== undefined && Number.isFinite(Number(response.body.options_total)), response);
			check(`${pricesIncludeTax} prices / ${shopDisplay} summary equals Woo helper payload`, Math.abs(amount(actual.product) - Number(response?.body.product_total)) < 0.011 && Math.abs(amount(actual.options) - Number(response?.body.options_total)) < 0.011 && Math.abs(amount(actual.grand) - Number(response?.body.grand_total)) < 0.011, { actual, preview: response?.body });
			check(`${pricesIncludeTax} prices / ${shopDisplay} emits distinct raw and displayed pricing data`, await page.evaluate(() => {
				let detail;
				document.addEventListener('opf:pricing', (event) => { detail = event.detail; }, { once: true });
				document.querySelector('[data-opf-field="finish"] select')?.dispatchEvent(new Event('change', { bubbles: true }));
				return new Promise((resolve) => setTimeout(() => resolve(Boolean(detail?.displayed && Number.isFinite(detail.final) && Number.isFinite(detail.displayed.final))), 250));
			}));
			const php = fixture('verify', JSON.stringify({ groups: actual.selection.groupValues, options: actual.request.options, quantity: actual.request.quantity }));
			check(`${pricesIncludeTax} prices / ${shopDisplay} cart/order lifecycle ran`, php.includes('Shop preview, cart tax/line, and order persistence were measured.'), php.split('\n').slice(-3).join('\n'));
			console.log(php);
		}
	}

	fixture('mode', 'no', 'incl');
	previews.length = 0;
	const nonTaxable = await selectPremium(nonTaxableUrl);
	const nonTaxPreview = previews.at(-1);
	const nonTaxProductId = Number(await page.locator('.opf-product-totals').getAttribute('data-opf-tax-product-id'));
	const nonTaxStatus = wp('eval', `echo wc_get_product(${nonTaxProductId})->get_tax_status();`);
	check('non-taxable fixture is actually non-taxable', nonTaxStatus === 'none', { id: nonTaxProductId, status: nonTaxStatus });
	const nonTaxOptionsUntaxed = nonTaxPreview?.body && nonTaxable.request
		&& Math.abs(Number(nonTaxPreview.body.options_total) - Number(nonTaxable.request.options) * Number(nonTaxable.request.quantity)) < 0.011;
	check('non-taxable Woo preview keeps the submitted options delta untaxed', nonTaxPreview?.status === 200 && Number(nonTaxable.request?.product_id) === nonTaxProductId && nonTaxStatus === 'none' && Math.abs(Number(nonTaxPreview.body.product_total) - 30) < 0.011 && nonTaxOptionsUntaxed && Math.abs(amount(nonTaxable.product) - 30) < 0.011 && Math.abs(amount(nonTaxable.grand) - amount(nonTaxable.product) - amount(nonTaxable.options)) < 0.011, { productId: nonTaxProductId, request: nonTaxable.request, actual: nonTaxable, preview: nonTaxPreview });
	const nonTaxCartOrder = fixture('verify', JSON.stringify({ non_taxable: true, groups: nonTaxable.selection.groupValues, options: nonTaxable.request.options, quantity: nonTaxable.request.quantity }));
	check('non-taxable cart/order lifecycle records zero tax', nonTaxCartOrder.includes('Shop preview, cart tax/line, and order persistence were measured.') && nonTaxCartOrder.includes('"line_tax":"0"'), nonTaxCartOrder.split('\n').slice(-3).join('\n'));
	console.log(nonTaxCartOrder);

	fixture('mode', 'no', 'incl');
	const taxState = JSON.parse(wp('eval', 'echo wp_json_encode(get_option("opf_e2e_commerce_tax_state"));'));
	const variationTaxResults = {};
	for (const [label, taxClass] of [ [ 'Standard', '' ], [ 'Reduced', 'reduced-rate' ] ]) {
		previews.length = 0;
		previewRequests.length = 0;
		const response = await page.goto(variableProductUrl, { waitUntil: 'domcontentloaded' });
		check(`variable product page returns 200 (${label})`, response?.status() === 200, response?.status());
		await page.evaluate(() => {
			window.__opfPricingEvents = [];
			document.addEventListener('opf:pricing', (event) => window.__opfPricingEvents.push(event.detail));
		});
		await page.locator('form.variations_form select[name="attribute_color"]').selectOption({ label });
		const variationId = Number(taxState.variation_by_label?.[label]);
		await page.waitForFunction((id) => Number(document.querySelector('.opf-product-totals')?.getAttribute('data-opf-tax-product-id')) === id, variationId, { timeout: 10000 });
		await page.locator('[data-opf-field="finish"] select').selectOption('premium');
		await page.locator('[data-opf-field="setup"] input[value="rush"]').check();
		await page.locator('form.cart input[name="quantity"], form.cart .qty').first().fill('3');
		await page.waitForFunction(() => {
			const summary = document.querySelector('.opf-product-totals');
			return summary && !summary.getAttribute('aria-busy') && summary.querySelector('.opf-grand-total')?.textContent?.trim();
		}, null, { timeout: 10000 });
		const actual = await totals();
		const request = previewRequests.at(-1);
		const preview = previews.at(-1);
		const selected = await selection();
		check(`${label} variation tax preview uses selected variation ID`, Number(request?.product_id) === variationId && Number(await page.locator('.opf-product-totals').getAttribute('data-opf-tax-product-id')) === variationId, { variationId, request });
		check(`${label} variation summary equals Woo selected-tax-class preview`, preview?.status === 200 && Math.abs(amount(actual.product) - Number(preview.body.product_total)) < 0.011 && Math.abs(amount(actual.options) - Number(preview.body.options_total)) < 0.011 && Math.abs(amount(actual.grand) - Number(preview.body.grand_total)) < 0.011, { actual, preview });
		const php = fixture('verify', JSON.stringify({ variation_id: variationId, groups: selected.groupValues, options: request.options, quantity: request.quantity }));
		check(`${label} variation cart/order uses ${taxClass || 'standard'} tax class and persists the variation`, php.includes(`"variation_id":${variationId}`) && php.includes(`"tax_class":"${taxClass}"`) && php.includes('Shop preview, cart tax/line, and order persistence were measured.'), php.split('\n').slice(-4).join('\n'));
		const cartLine = JSON.parse(php.match(/CART=(\{[^\n]+\})/)?.[1] || '{}');
		variationTaxResults[label] = Number(cartLine.line_tax);
		console.log(php);
	}
	check('variation-specific rate changes Woo tax amount', variationTaxResults.Standard > variationTaxResults.Reduced && variationTaxResults.Reduced > 0, variationTaxResults);

	await page.route('**/wp-json/opf/v1/price-preview', async (route) => {
		const body = route.request().postDataJSON();
		if (Number(body?.quantity) === 2) await new Promise((resolve) => setTimeout(resolve, 400));
		else await new Promise((resolve) => setTimeout(resolve, 20));
		await route.continue();
	});
	const quantity = page.locator('form.cart input[name="quantity"], form.cart .qty').first();
	await quantity.fill('2');
	await page.waitForTimeout(90);
	await quantity.fill('3');
	await page.waitForFunction(() => {
		const summary = document.querySelector('.opf-product-totals');
		return summary && !summary.getAttribute('aria-busy') && summary.querySelector('.opf-grand-total')?.textContent?.trim();
	}, null, { timeout: 10000 });
	const newest = await totals();
	await page.waitForTimeout(500);
	const afterOlderResponse = await totals();
	check('late older tax response cannot overwrite newest quantity total', amount(newest.grand) > 0 && newest.grand === afterOlderResponse.grand, { newest, afterOlderResponse });
	await page.unroute('**/wp-json/opf/v1/price-preview');

	await page.route('**/wp-json/opf/v1/price-preview', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
	await quantity.fill('4');
	await page.waitForFunction(() => {
		const summary = document.querySelector('.opf-product-totals');
		return summary && !summary.querySelector('.opf-price-preview-status')?.hidden;
	}, null, { timeout: 5000 });
	const failed = await totals();
	check('failed tax preview clears stale displayed amounts and announces the error', !failed.product && !failed.options && !failed.grand && failed.statusHidden === false && failed.statusText.includes('could not be updated'), failed);
	await page.unroute('**/wp-json/opf/v1/price-preview');
	await quantity.fill('3');
	await page.waitForFunction(() => {
		const summary = document.querySelector('.opf-product-totals');
		return summary && summary.querySelector('.opf-price-preview-status')?.hidden && summary.querySelector('.opf-grand-total')?.textContent?.trim();
	}, null, { timeout: 10000 });
	check('preview recovers after the endpoint responds again', Number.isFinite(amount((await totals()).grand)));
	check('tax browser flow has no uncaught JavaScript errors', pageErrors.length === 0, pageErrors);
	check(`tax browser flow reached ${checks} assertions`, true);
} finally {
	await browser.close();
}
if (failures.length) process.exit(1);
