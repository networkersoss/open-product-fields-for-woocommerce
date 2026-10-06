// Storefront choice-hint seam proof (CURCY multi-currency).
//
// Loads the disposable clone's product pages with real Chromium, reads the
// server-rendered static hint pills, the JS-populated `opf-choice__hint`
// element, the `option` labels and the preview totals for OPF and WAPF
// Extended 3.1.5, and repeats every leg with CURCY inactive.
//
// Legs: opf | wapf | opf_nocurcy (env OPF_SHINT_LEGS).
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const runtime = '/tmp/opf-lane-jshint-wp';
const base = 'http://127.0.0.1:8293';
const out = process.env.OPF_SHINT_OUT;
if (process.env.OPF_SHINT_ALLOW !== '1' || !out || !fs.existsSync(out) || fs.realpathSync(runtime) !== runtime) throw new Error('Explicit owned shint SQLite clone required');
const { chromium } = createRequire(process.env.OPF_SHINT_PLAYWRIGHT || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json')('playwright');
const state = JSON.parse(fs.readFileSync(out + '/state.json'));
const cases = state.cases;
const wp = code => execFileSync('wp', ['--path=' + runtime, 'eval', code], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }).trim();
const activate = (provider, curcy) => {
	const plugins = ['woocommerce/woocommerce.php'];
	if (curcy) plugins.push('woocommerce-multi-currency/woocommerce-multi-currency.php');
	plugins.push(provider === 'wapf' ? 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' : 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php');
	execFileSync('wp', ['--path=' + runtime, '--skip-plugins', '--skip-themes', 'option', 'update', 'active_plugins', JSON.stringify(plugins), '--format=json'], { encoding: 'utf8' });
};

const norm = s => (s || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();

const readStorefront = (page, provider) => page.evaluate(({ provider }) => {
	const norm = s => (s || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
	const root = document.querySelector('form.cart') || document.body;
	const cls = provider === 'wapf' ? 'wapf-pricing-hint' : 'opf-pricing-hint';
	const pills = Array.from(root.querySelectorAll('.' + cls)).map(e => norm(e.textContent));
	const choiceHints = Array.from(root.querySelectorAll('[data-opf-choice-hint]')).map(e => ({ slug: e.getAttribute('data-opf-choice-hint'), text: norm(e.textContent) }));
	const options = Array.from(root.querySelectorAll('option')).map(o => ({ value: o.value, text: norm(o.textContent), price: o.getAttribute('data-opf-price'), pricetype: o.getAttribute('data-opf-pricetype') }));
	const priceData = Array.from(root.querySelectorAll('[data-opf-price]')).map(e => ({ tag: e.tagName, id: e.id || null, price: e.getAttribute('data-opf-price'), pricetype: e.getAttribute('data-opf-pricetype') }));
	const totalsEl = document.querySelector(provider === 'wapf' ? '.wapf-product-totals' : '.opf-product-totals');
	const groupEl = root.querySelector('[data-opf-group]') || document.querySelector('[data-opf-group]');
	return {
		pills,
		choiceHints,
		options,
		priceData,
		totals: norm(totalsEl ? totalsEl.innerText : ''),
		totalsPriceAttr: totalsEl ? totalsEl.getAttribute('data-product-price') : null,
		hintConversionAttr: groupEl ? groupEl.getAttribute('data-opf-hint-conversion') : null,
		currency: window.wmc_current_currency || null,
		config: {
			product_base_price: (window.opf_config || {}).product_base_price ?? null,
			currency_rate: (window.opf_config || {}).currency_rate ?? null,
			hint_conversion: (window.opf_config || {}).hint_conversion ?? null,
			display_options: (window.opf_config || {}).display_options ?? null,
		},
	};
}, { provider });

const fillOpf = async (page, gid, c) => {
	for (const field of c.fields) {
		if ('text' === field.type) await page.locator('#opf-' + gid + '-' + field.id).fill(c.values[field.id]);
		if ('date' === field.type) await page.locator('#opf-' + gid + '-' + field.id).fill(c.values[field.id]);
		if ('select' === field.type) await page.locator('#opf-' + gid + '-' + field.id).selectOption('a');
		if ('radio' === field.type) await page.locator('#opf-' + gid + '-' + field.id + '-a').check();
	}
};
const fillWapf = async (page, c) => {
	for (const [id, value] of Object.entries(c.wapf_values)) {
		const loc = page.locator('input[name="wapf[' + id + ']"]');
		await loc.fill(value);
		if ('field_d' === id) { await page.keyboard.press('Escape'); await page.locator('body').click({ position: { x: 8, y: 8 } }); }
	}
	for (const [id] of Object.entries(c.wapf_radio)) {
		await page.locator('input[type="radio"][name="wapf[' + id + ']"]').first().check();
	}
	for (const field of c.fields) {
		if ('select' === field.type) await page.locator('select[name="wapf[field_' + field.id + ']"]').selectOption('a');
	}
};

const results = { utc: new Date().toISOString(), clone: state.runtime, legs: {} };
const write = () => fs.writeFileSync(out + '/storefront-results.json', JSON.stringify(results, null, 2));

const browser = await chromium.launch({ executablePath: process.env.OPF_SHINT_CHROMIUM || '/home/followersya-5hqi7/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell' });
const legs = (process.env.OPF_SHINT_LEGS || 'opf,wapf,opf_nocurcy').split(',').filter(Boolean);
try {
	for (const leg of legs) {
		const provider = leg.startsWith('wapf') ? 'wapf' : 'opf';
		const curcy = !leg.endsWith('nocurcy');
		activate(provider, curcy);
		const row = { leg, provider, curcy, currency: curcy ? 'EUR' : 'USD', cases: {}, pageErrors: [], consoleErrors: [] };
		results.legs[leg] = row;
		write();
		const context = await browser.newContext();
		const page = await context.newPage();
		page.on('pageerror', e => row.pageErrors.push(e.message));
		page.on('console', m => { if (m.type() === 'error') row.consoleErrors.push(m.text()); });
		try {
			await page.goto(curcy ? base + '/?wmc-currency=EUR' : base + '/', { waitUntil: 'domcontentloaded' });
			row.cookie = (await context.cookies()).filter(c => c.name.startsWith('wmc')).map(c => ({ name: c.name, value: c.value }));
			for (const c of cases) {
				const pid = state.products[c.id], gid = state.groups[c.id];
				const resp = await page.goto(base + '/?p=' + pid, { waitUntil: 'networkidle' });
				const caseRow = { product: pid, group: gid, http_status: resp.status(), kind: c.kind, addon_base: c.addon, unit_eur: c.unit_eur };
				row.cases[c.id] = caseRow;
				if (provider === 'opf') await fillOpf(page, gid, c); else await fillWapf(page, c);
				await page.locator('input.qty').fill('1');
				await page.locator('input.qty').dispatchEvent('change');
				await page.waitForTimeout(1000);
				Object.assign(caseRow, await readStorefront(page, provider));
				if (process.env.OPF_SHINT_SHOTS === '1') await page.screenshot({ path: out + '/storefront-' + leg + '-' + c.id + '.png' });
				console.log('CASE', leg, c.id, 'hints=', JSON.stringify(caseRow.choiceHints), 'pills=', JSON.stringify(caseRow.pills), 'totals=', caseRow.totals);
			}
			write();
		} catch (e) {
			row.failure = e.stack; write(); throw e;
		} finally { await context.close(); write(); }
	}
} finally { await browser.close(); write(); }
console.log('Recorded storefront hint rows for legs: ' + legs.join(', '));
