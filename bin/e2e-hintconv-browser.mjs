// Pricing-hint conversion lane browser proof. Real Chromium only, never
// production. Runs OPF and WAPF Extended 3.1.5 on identical disposable
// products under live CURCY 2.4.3 multi-currency (base USD, EUR at rate 1.5),
// records the storefront / cart / checkout hint strings and the persisted
// order hint, and repeats the fixture with CURCY inactive as the
// no-currency-plugin regression leg.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const runtime = '/tmp/opf-lane-hintconv-wp';
const base = 'http://127.0.0.1:8292';
const out = process.env.OPF_HINTCONV_OUT;
if (process.env.OPF_HINTCONV_ALLOW !== '1' || !out || !fs.existsSync(out) || fs.realpathSync(runtime) !== runtime || fs.realpathSync(runtime + '/wp-content/database/.ht.sqlite') !== runtime + '/wp-content/database/.ht.sqlite') throw new Error('Explicit owned hintconv SQLite clone required');
const { chromium } = createRequire(process.env.OPF_HINTCONV_PLAYWRIGHT || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json')('playwright');
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
const minor = n => Math.round(n * 100);

// Reads the provider's own hint markup from a served page region: the static
// <span class="…-pricing-hint"> pills, the rendered option labels and the
// item-data rows the customer sees. `scope` keeps the theme mini-cart out of
// the storefront reading.
const grabHints = (page, provider, scope) => page.evaluate(({ provider, scope }) => {
	const cls = provider === 'wapf' ? 'wapf-pricing-hint' : 'opf-pricing-hint';
	const norm = s => (s || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
	const root = document.querySelector(scope) || document.body;
	const spans = Array.from(root.querySelectorAll('.' + cls)).map(e => norm(e.textContent));
	const options = Array.from(root.querySelectorAll('option')).map(o => norm(o.textContent)).filter(t => /\(.*\)/.test(t));
	const rows = Array.from(root.querySelectorAll('dl.variation, .wc-item-meta li')).map(e => norm(e.textContent));
	const items = Array.from(root.querySelectorAll('.cart_item, tr')).map(e => norm(e.textContent)).filter(t => t.length > 10);
	return { scope, spans, options, rows, items };
}, { provider, scope });

const results = { utc: new Date().toISOString(), clone: state.runtime, legs: {} };
const write = () => fs.writeFileSync(out + '/browser-results.json', JSON.stringify(results, null, 2));

const fillOpf = async (page, gid, c) => {
	for (const field of c.fields) {
		if ('text' === field.type && c.values[field.id] !== undefined && 'x' !== field.id) await page.locator('#opf-' + gid + '-' + field.id).fill(c.values[field.id]);
		if ('text' === field.type && 'x' === field.id) await page.locator('#opf-' + gid + '-x').fill(c.values.x);
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

const readOrder = orderId => JSON.parse(wp('$o=wc_get_order(' + orderId + ');$L=[];foreach($o->get_items() as $i){$meta=[];foreach($i->get_meta_data() as $d){$dd=$d->get_data();$meta[$dd["key"]]=$dd["value"];}$L[]=["product_id"=>$i->get_product_id(),"total"=>$i->get_total(),"total_tax"=>$i->get_total_tax(),"meta"=>$meta];}echo wp_json_encode(["id"=>$o->get_id(),"currency"=>$o->get_currency(),"subtotal"=>$o->get_subtotal(),"total"=>$o->get_total(),"payment_method"=>$o->get_payment_method(),"lines"=>$L]);'));

const browser = await chromium.launch({ executablePath: process.env.OPF_HINTCONV_CHROMIUM || '/home/followersya-5hqi7/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell' });
const legs = (process.env.OPF_HINTCONV_LEGS || 'opf,wapf,opf_nocurcy,wapf_nocurcy').split(',').filter(Boolean);
try {
	for (const leg of legs) {
		const provider = leg.startsWith('wapf') ? 'wapf' : 'opf';
		const curcy = !leg.endsWith('nocurcy');
		activate(provider, curcy);
		wp('global $wpdb; $wpdb->delete($wpdb->prefix . \'woocommerce_sessions\', [ \'session_key\' => (string) ' + state.user + ' ]); delete_user_meta(' + state.user + ', \'_woocommerce_persistent_cart_1\'); echo \'cleared\';');
		const row = { leg, provider, curcy, currency: curcy ? 'EUR' : 'USD', cases: {}, pageErrors: [], consoleErrors: [] };
		results.legs[leg] = row;
		write();
		const context = await browser.newContext();
		const page = await context.newPage();
		page.on('pageerror', e => row.pageErrors.push(e.message));
		page.on('console', m => { if (m.type() === 'error') row.consoleErrors.push(m.text()); });
		try {
			if (curcy) await page.goto(base + '/?wmc-currency=EUR', { waitUntil: 'domcontentloaded' });
			else await page.goto(base + '/', { waitUntil: 'domcontentloaded' });
			row.cookie = (await context.cookies()).filter(c => c.name.startsWith('wmc')).map(c => ({ name: c.name, value: c.value }));
			await page.goto(base + '/wp-login.php');
			await page.locator('#user_login').fill('hintconv-proof');
			await page.locator('#user_pass').fill('hintconv-proof-pass-123');
			await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('#wp-submit').click()]);
			row.logged_in = await page.locator('#wp-admin-bar-logout, .woocommerce-MyAccount-navigation-link--orders').count() > 0;
			wp('delete_user_meta(' + state.user + ', \'_woocommerce_persistent_cart_1\'); echo \'ok\';');
			await context.request.delete(base + '/?rest_route=/wc/store/v1/cart/items');

			for (const c of cases) {
				const pid = state.products[c.id], gid = state.groups[c.id];
				const resp = await page.goto(base + '/?p=' + pid, { waitUntil: 'networkidle' });
				const caseRow = { product: pid, group: gid, http_status: resp.status(), kind: c.kind, addon_base: c.addon, unit_eur: c.unit_eur };
				row.cases[c.id] = caseRow;
				// Static per-option/field hint pills rendered into the form.
				caseRow.storefront_hints = await grabHints(page, provider, 'form.cart');
				if (provider === 'opf') await fillOpf(page, gid, c); else await fillWapf(page, c);
				await page.locator('input.qty').fill('1');
				await page.locator('input.qty').dispatchEvent('change');
				await page.waitForTimeout(900);
				caseRow.preview_totals = await page.locator(provider === 'wapf' ? '.wapf-product-totals' : '.opf-product-totals').innerText().catch(() => '');
				caseRow.preview_totals = norm(await page.locator(provider === 'wapf' ? '.wapf-product-totals' : '.opf-product-totals').innerText().catch(() => ''));
				await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('button[name="add-to-cart"],button.single_add_to_cart_button').click()]);
				const cart = await (await context.request.get(base + '/?rest_route=/wc/store/v1/cart')).json();
				const line = (cart.items || []).find(i => i.id === pid);
				caseRow.cart_line = line ? { quantity: line.quantity, unit_price: line.prices.price, unit_currency: line.prices.currency_code, line_total: line.totals.line_total, item_data: (line.item_data || []).map(d => ({ key: d.key, display: norm(d.display) })) } : null;
				caseRow.cart_unit_ok = !!line && Math.abs(Number(line.prices.price) - minor(curcy ? c.unit_eur : 10 + c.addon)) <= 1;
				console.log('CART', leg, c.id, 'unit=', caseRow.cart_line && caseRow.cart_line.unit_price, caseRow.cart_line && caseRow.cart_line.unit_currency, 'expected=', curcy ? minor(c.unit_eur) : minor(10 + c.addon), 'ok=', caseRow.cart_unit_ok);
			}

			// Real classic cart page: the served field display the customer sees.
			const cartFull = await (await context.request.get(base + '/?rest_route=/wc/store/v1/cart')).json();
			row.cart_totals = { currency: cartFull.totals.currency_code, total: cartFull.totals.total_price, tax: cartFull.totals.total_tax };
			await page.goto(base + '/cart/', { waitUntil: 'networkidle' });
			row.cart_page = await grabHints(page, provider, '.woocommerce-cart-form');
			row.cart_page_url = page.url();

			// Real classic checkout: order-review field display, then order.
			await page.goto(base + '/checkout/', { waitUntil: 'networkidle' });
			row.checkout_page = await grabHints(page, provider, '#order_review');
			row.checkout_rendered = (await page.locator('#order_review').innerText()).slice(0, 600);
			if (!(await page.locator('#billing_country').inputValue())) await page.locator('#billing_country').selectOption('US');
			await page.locator('#billing_first_name').fill('Hint');
			await page.locator('#billing_last_name').fill('ConvProof');
			await page.locator('#billing_address_1').fill('123 Proof Street');
			await page.locator('#billing_city').fill('San Francisco');
			await page.locator('#billing_postcode').fill('94103');
			await page.locator('#billing_phone').fill('5551234567');
			if (!(await page.locator('#billing_email').inputValue())) await page.locator('#billing_email').fill('hintconv-proof@example.test');
			await page.locator('#billing_state').selectOption('CA');
			const gateway = provider === 'opf' ? 'cod' : 'cheque';
			if (await page.locator('#payment_method_' + gateway).count()) await page.locator('#payment_method_' + gateway).check();
			row.payment_method = gateway;
			await page.waitForTimeout(300);
			let resolveCheckout;
			const checkoutResponse = new Promise(resolve => { resolveCheckout = resolve; });
			let rawBody = '';
			await page.route('**/*wc-ajax=checkout*', async route => {
				const resp = await route.fetch(); rawBody = await resp.text();
				let data; try { data = JSON.parse(rawBody); } catch (e) { data = { parse_error: String(e), raw: rawBody.slice(0, 1000) }; }
				await route.fulfill({ response: resp, body: rawBody }); resolveCheckout(data);
			});
			await page.locator('#place_order').click();
			const checkout = await Promise.race([checkoutResponse, new Promise((_, reject) => setTimeout(() => reject(new Error('Checkout response timeout')), 30000))]);
			row.checkout_result = checkout.result;
			if (checkout.result !== 'success') { row.checkout_errors = checkout; throw new Error('Actual gateway checkout failed: ' + JSON.stringify(checkout).slice(0, 800)); }
			const orderId = Number(new URL(checkout.redirect, base).searchParams.get('order-received') || String(checkout.redirect).match(/order-received\/(\d+)/)?.[1]);
			row.order_id = orderId;
			row.order = readOrder(orderId);
			row.order_hints = {};
			for (const line of row.order.lines) {
				const id = cases.find(c => state.products[c.id] === line.product_id)?.id || String(line.product_id);
				const visible = Object.entries(line.meta || {}).filter(([key]) => !key.startsWith('_')).map(entry => norm(String(entry[1])));
				const structured = line.meta?.['_opf_fields_snapshot'] || line.meta?.['_wapf_meta'] || '';
				row.order_hints[id] = { visible_meta: visible, structured: typeof structured === 'string' ? structured : JSON.stringify(structured) };
			}
			row.order_totals = { currency: row.order.currency, subtotal: row.order.subtotal, total: row.order.total, payment_method: row.order.payment_method };
			row.units_ok = row.order.lines.length === cases.length && cases.every(c => {
				const l = row.order.lines.find(x => x.product_id === state.products[c.id]);
				return l && Math.abs(Number(l.total) - (curcy ? c.unit_eur : 10 + c.addon)) < 0.005;
			});
			console.log('ORDER', leg, orderId, row.order.currency, 'subtotal=', row.order.subtotal, 'total=', row.order.total, 'units_ok=', row.units_ok);
			write();
		} catch (e) {
			row.failure = e.stack; write(); throw e;
		} finally { await context.close(); write(); }
	}
} finally { await browser.close(); write(); }
console.log('Recorded hint-conversion lifecycle for legs: ' + legs.join(', '));
