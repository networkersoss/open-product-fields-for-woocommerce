import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
const productUrl = `${base}/product/opf-e2e-summary-modes-product/`;
if (!passwordFile) throw new Error('OPF_E2E_PASSWORD_FILE is required');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
let expectedGrandTotal = null;
const check = (name, ok, detail = '') => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}${detail ? ` ${JSON.stringify(detail)}` : ''}`);
	if (!ok) failures++;
};

const amount = (value) => Number(String(value || '').replace(/[^0-9,.-]/g, '').replace(/,(?=\d{3}(?:\D|$))/g, '').replace(',', '.'));

async function saveMode(mode) {
	await page.goto(`${base}/wp-admin/admin.php?page=wc-settings&tab=products&section=opf_product_fields`, { waitUntil: 'domcontentloaded' });
	const setting = page.locator('select[name="opf_price_summary_mode"]');
	if (await setting.count() !== 1) throw new Error('Price-summary setting is missing from WooCommerce Product Fields settings');
	await setting.selectOption(mode);
	await page.locator('button[name="save"]').click();
	await page.waitForLoadState('domcontentloaded');
	await page.goto(`${base}/wp-admin/admin.php?page=wc-settings&tab=products&section=opf_product_fields`, { waitUntil: 'domcontentloaded' });
	return await page.locator('select[name="opf_price_summary_mode"]').inputValue();
}

try {
	const login = await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	check('disposable WooCommerce clone responds', login?.status() === 200);
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');

	for (const [mode, expectedRows] of [['three_line', 3], ['grand_total', 3], ['hidden', 3]]) {
		const saved = await saveMode(mode);
		check(`${mode} setting persists after Woo settings reload`, saved === mode, saved);
		const response = await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
		check(`${mode} summary product responds`, response?.status() === 200);
		const summary = page.locator('.opf-product-totals');
		const before = await summary.evaluate((node) => ({
			grand: node.querySelector('.opf-grand-total')?.textContent?.trim(),
			options: node.querySelector('.opf-options-total')?.textContent?.trim(),
		}));
		await page.locator('[data-opf-field="finish"] select').selectOption('premium');
		await page.waitForFunction(() => /(?:15|16)(?:\.00)?/.test(document.querySelector('.opf-grand-total')?.textContent || ''), null, { timeout: 5000 });
		const state = await summary.evaluate((node) => ({
			mode: node.dataset.opfSummaryMode,
			display: getComputedStyle(node).display,
			rows: Array.from(node.querySelectorAll('.opf-summary-row')).map((row) => getComputedStyle(row).display),
			grand: node.querySelector('.opf-grand-total')?.textContent?.trim(),
			options: node.querySelector('.opf-options-total')?.textContent?.trim(),
		}));
		const expectedVisible = 'three_line' === mode ? [true, true, true] : [false, false, true];
		const rowLayout = 'hidden' === mode || (state.rows.length === expectedRows && state.rows.every((display, index) => (display !== 'none') === expectedVisible[index]));
		check(`${mode} summary renders the expected rows`, state.mode === mode && rowLayout && ('hidden' !== mode || state.display === 'none'), state);
		const delta = amount(state.grand) - amount(before.grand);
		const optionDelta = amount(state.options) - amount(before.options);
		check(`${mode} selected Premium adds exactly $5 to the existing matched-group total`, Math.abs(delta - 5) < 0.001 && Math.abs(optionDelta - 5) < 0.001, { before, after: state, delta, optionDelta });
		if (null === expectedGrandTotal) expectedGrandTotal = amount(state.grand);
		else check(`${mode} keeps the same grand total across summary presentation modes`, Math.abs(amount(state.grand) - expectedGrandTotal) < 0.001, state.grand);
	}
	check('summary-mode browser flow has no uncaught JavaScript errors', errors.length === 0, errors);
} finally {
	await browser.close();
}
if (failures) process.exit(1);
