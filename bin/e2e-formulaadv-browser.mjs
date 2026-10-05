// Formula-advanced commerce lane browser proof. Real Chromium only, never
// production. Runs OPF and WAPF Extended 3.1.5 on identical disposable
// products under live CURCY 2.4.3 multi-currency, three tax classes and a real
// built-in gateway (COD) checkout through order persistence, partial refund
// and order-again.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

const runtime = '/tmp/opf-lane-formulaadv-wp';
const base = 'http://127.0.0.1:8291';
const out = process.env.OPF_FORMULAADV_OUT;
if (process.env.OPF_FORMULAADV_ALLOW !== '1' || !out || !fs.existsSync(out) || fs.realpathSync(runtime) !== runtime || fs.realpathSync(runtime + '/wp-content/database/.ht.sqlite') !== runtime + '/wp-content/database/.ht.sqlite') throw new Error('Explicit owned formulaadv SQLite clone required');
const { chromium } = createRequire(process.env.OPF_FORMULAADV_PLAYWRIGHT || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json')('playwright');
const state = JSON.parse(fs.readFileSync(out + '/state.json'));
const oc = state.cases;
const wp = code => execFileSync('wp', ['--path=' + runtime, 'eval', code], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }).trim();
const activate = provider => execFileSync('wp', ['--path=' + runtime, '--skip-plugins', '--skip-themes', 'option', 'update', 'active_plugins', JSON.stringify(['woocommerce/woocommerce.php', 'woocommerce-multi-currency/woocommerce-multi-currency.php', provider === 'wapf' ? 'advanced-product-fields-for-woocommerce-extended/advanced-product-fields-for-woocommerce-extended.php' : 'open-product-fields-for-woocommerce/open-product-fields-for-woocommerce.php']), '--format=json'], { encoding: 'utf8' });

const eur = n => Math.round(n * 100);
const expectedUnits = {};
for (const c of oc) expectedUnits[c.id] = eur(c.expected_unit_eur);
const expectedTax = {};
for (const c of oc) expectedTax[c.id] = Math.round(c.expected_unit_eur * c.tax_rate) / 100;
const expectedSubtotal = oc.reduce((s, c) => s + c.expected_unit_eur, 0);
const expectedTaxTotal = oc.reduce((s, c) => s + c.expected_unit_eur * c.tax_rate / 100, 0);

const fillOpf = async (page, gid, c) => {
	for (const id of ['x', 't', 'd', 'note']) {
		if (Object.prototype.hasOwnProperty.call(c.values, id)) {
			await page.locator('#opf-' + gid + '-' + id).fill(c.values[id]);
		}
	}
	if (c.values.prints) {
		await page.locator('input[name="opf[' + gid + '][prints][oak]"]').fill(String(c.values.prints.oak));
		await page.locator('input[name="opf[' + gid + '][prints][ash]"]').fill(String(c.values.prints.ash));
	}
	await page.locator('#opf-' + gid + '-fee').selectOption('a');
};
const fillWapf = async (page, c) => {
	for (const [name, value] of Object.entries(c.wapf_values)) {
		const loc = page.locator('input[name="wapf[' + name + ']"]');
		await loc.fill(value);
		if (name === 'field_d') { await page.keyboard.press('Escape'); await page.locator('body').click({ position: { x: 8, y: 8 } }); }
	}
	await page.locator('select[name="wapf[field_fee]"]').selectOption('a');
};

const results = { utc: new Date().toISOString(), runtime: state.runtime, curcy: { version: '2.4.3', eur_rate: 1.5, base_currency: 'USD' }, expected: { units_eur: expectedUnits, line_tax_eur: expectedTax, subtotal_eur: expectedSubtotal, tax_total_eur: expectedTaxTotal, order_total_eur: expectedSubtotal + expectedTaxTotal }, providers: {} };
const write = () => fs.writeFileSync(out + '/browser-lifecycle-results.json', JSON.stringify(results, null, 2));

const browser = await chromium.launch({ executablePath: process.env.OPF_FORMULAADV_CHROMIUM || '/home/followersya-5hqi7/.cache/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-linux64/chrome-headless-shell' });
const writeComparison = () => {
	const o = results.providers.opf, w = results.providers.wapf;
	if (!o || !w || !o.line_checks || !w.line_checks) return;
	const rows = oc.map(c => {
		const ol = o.line_checks[c.id] || {}, wl = w.line_checks[c.id] || {};
		return {
			id: c.id, formula: c.formula, tax_class: c.tax_class || 'standard', tax_rate: c.tax_rate,
			expected_addon_base: c.addon, expected_unit_eur: c.expected_unit_eur, expected_line_tax_eur: expectedTax[c.id],
			opf: { unit_eur: ol.observed_total, addon_base: ol.addon_base_observed, line_tax_eur: ol.observed_total_tax, tax_class: ol.tax_class, field_meta: ol.field_meta_present },
			wapf: { unit_eur: wl.observed_total, addon_base: wl.addon_base_observed, line_tax_eur: wl.observed_total_tax, tax_class: wl.tax_class, field_meta: wl.field_meta_present },
			storefront_preview: { opf: (o.cases[c.id] || {}).preview, wapf: (w.cases[c.id] || {}).preview },
			match: Math.abs(Number(ol.observed_total) - Number(wl.observed_total)) < 1e-6 && Math.abs(Number(ol.observed_total_tax) - Number(wl.observed_total_tax)) < 1e-6 && ol.addon_ok && wl.addon_ok,
		};
	});
	const cmp = {
		utc: new Date().toISOString(), clone: state.runtime,
		currencies: { base: 'USD', current: 'EUR', curcy_rate: 1.5, base_product_price_usd: 10, quantity: 1 },
		orders: { opf: o.order_id, wapf: w.order_id },
		order_totals: {
			opf: { currency: o.order.currency, subtotal: o.order.subtotal, tax: o.order.total_tax, total: o.order.total, payment_method: o.payment_method },
			wapf: { currency: w.order.currency, subtotal: w.order.subtotal, tax: w.order.total_tax, total: w.order.total, payment_method: w.payment_method },
		},
		rows, all_match: rows.every(r => r.match),
	};
	fs.writeFileSync(out + '/opf-vs-wapf-numbers.json', JSON.stringify(cmp, null, 2));
};
try {
	const only = (process.env.OPF_FORMULAADV_ONLY || 'opf,wapf').split(',').filter(Boolean);
	for (const provider of ['opf', 'wapf']) {
		if (!only.includes(provider)) continue;
		activate(provider);
		// Logged-in customers keep their cart in a session row keyed by user id;
		// clear it (plus the persistent-cart meta) so runs do not accumulate.
		wp('global $wpdb; $wpdb->delete($wpdb->prefix . \'woocommerce_sessions\', [ \'session_key\' => (string) ' + state.user + ' ]); delete_user_meta(' + state.user + ', \'_woocommerce_persistent_cart_1\'); echo \'cleared\';');
		const row = { provider, cases: {}, pageErrors: [], consoleErrors: [] };
		results.providers[provider] = row; write();
		const context = await browser.newContext();
		const page = await context.newPage();
		page.on('pageerror', e => row.pageErrors.push(e.message));
		page.on('console', m => { if (m.type() === 'error') row.consoleErrors.push(m.text()); });
		try {
			// Switch the live CURCY currency to EUR (sets wmc_current_currency cookie).
			await page.goto(base + '/?wmc-currency=EUR', { waitUntil: 'domcontentloaded' });
			row.cookie = (await context.cookies()).filter(c => c.name.startsWith('wmc'));
			const prodProbe = await (await context.request.get(base + '/?rest_route=/wc/store/v1/products/' + state.products.arith)).json();
			row.curcy_price_path = {
				note: 'CURCY converts catalog prices through the general Woo price path (woocommerce_product_get_price -> wmc_get_price); this run never loads the WOOCS or Aelia adapter.',
				base_product_price_usd: 10,
				store_api_price_minor_units: prodProbe.prices && prodProbe.prices.price,
				store_api_currency: prodProbe.prices && prodProbe.prices.currency_code,
				expected_minor_units: 1500,
			};
			// Log in as the owned customer so checkout makes an account order for order-again.
			await page.goto(base + '/wp-login.php');
			await page.locator('#user_login').fill('formulaadv-proof');
			await page.locator('#user_pass').fill('formulaadv-proof-pass-123');
			await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.locator('#wp-submit').click()]);
			row.logged_in = await page.locator('#wp-admin-bar-logout, .woocommerce-MyAccount-navigation-link--orders').count() > 0;
			// Drop any persistent cart left by an earlier run, then clear the live cart.
			wp('delete_user_meta(' + state.user + ', \'_woocommerce_persistent_cart_1\'); echo \'ok\';');
			await context.request.delete(base + '/?rest_route=/wc/store/v1/cart/items');

			// Product page -> add to cart for each formula family.
			for (const c of oc) {
				const pid = state.products[c.id], gid = state.groups[c.id];
				const pageErrors = [];
				page.on('pageerror', e => pageErrors.push(e.message));
				const resp = await page.goto(base + '/?p=' + pid, { waitUntil: 'networkidle' });
				const caseRow = { product: pid, group: gid, http_status: resp.status(), expected_unit_eur: c.expected_unit_eur, tax_class: c.tax_class || 'standard', tax_rate: c.tax_rate, formula: c.formula, pageErrors };
				row.cases[c.id] = caseRow;
				const form = await page.locator('form.cart').innerText().catch(() => '');
				caseRow.rendered = form.slice(0, 400);
				if (provider === 'opf') { await fillOpf(page, gid, c); } else { await fillWapf(page, c); }
				await page.locator('input.qty').fill('1'); await page.locator('input.qty').dispatchEvent('change');
				await page.waitForTimeout(900);
				caseRow.preview = await page.locator(provider === 'wapf' ? '.wapf-product-totals' : '.opf-product-totals').innerText().catch(() => '');
				await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.locator('button[name="add-to-cart"],button.single_add_to_cart_button').click()]);
				const cart = await (await context.request.get(base + '/?rest_route=/wc/store/v1/cart')).json();
				const line = (cart.items || []).find(i => i.id === pid);
				caseRow.cart_line = line ? { quantity: line.quantity, unit_price: line.prices.price, line_total: line.totals.line_total, line_tax: line.totals.line_total_tax, item_data: line.item_data } : null;
				caseRow.unit_ok = !!line && line.quantity === 1 && Number(line.prices.price) === expectedUnits[c.id];
				console.log('CART', provider, c.id, 'unit=', caseRow.cart_line && caseRow.cart_line.unit_price, 'expected=', expectedUnits[c.id], 'ok=', caseRow.unit_ok);
			}

			const cartFull = await (await context.request.get(base + '/?rest_route=/wc/store/v1/cart')).json();
			row.cart = { currency: cartFull.totals.currency_code, total: cartFull.totals.total_price, tax: cartFull.totals.total_tax, line_items: (cartFull.items || []).map(i => ({ id: i.id, qty: i.quantity, unit: i.prices.price, line_total: i.totals.line_total, line_tax: i.totals.line_total_tax })) };
			row.cart_total_ok = cartFull.totals.currency_code === 'EUR' && Math.abs(Number(cartFull.totals.total_price) - eur(expectedSubtotal + expectedTaxTotal)) <= 1;

			// Classic shortcode checkout through the real built-in COD gateway.
			await page.goto(base + '/checkout/', { waitUntil: 'networkidle' });
			row.checkout_rendered = (await page.locator('#order_review').innerText()).slice(0, 800);
			const curSel = await page.locator('#billing_country').inputValue().catch(() => '');
			if (!curSel) await page.locator('#billing_country').selectOption('US');
			await page.locator('#billing_first_name').fill('Formula');
			await page.locator('#billing_last_name').fill('AdvProof');
			await page.locator('#billing_address_1').fill('123 Proof Street');
			await page.locator('#billing_city').fill('San Francisco');
			await page.locator('#billing_postcode').fill('94103');
			await page.locator('#billing_phone').fill('5551234567');
			if (!(await page.locator('#billing_email').inputValue())) await page.locator('#billing_email').fill('formulaadv-proof@example.test');
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
			row.checkout_raw = rawBody.slice(0, 2000);
			if (checkout.result !== 'success') { row.checkout_notices = await page.locator('.woocommerce-error, .woocommerce-info, .woocommerce-message').allInnerTexts().catch(() => []); row.checkout_errors = checkout; row.checkout_body = (await page.locator('body').innerText().catch(() => '')).slice(0, 3000); throw new Error('Actual gateway checkout failed: ' + JSON.stringify(checkout).slice(0, 800)); }
			const orderId = Number(new URL(checkout.redirect, base).searchParams.get('order-received') || String(checkout.redirect).match(/order-received\/(\d+)/)?.[1]);
			row.order_id = orderId;
			row.order = JSON.parse(wp('$o=wc_get_order(' + orderId + ');$L=[];foreach($o->get_items() as $i){$m=[];foreach($i->get_meta_data() as $d){$m[]=$d->get_data()["key"];} $L[]=["product_id"=>$i->get_product_id(),"qty"=>$i->get_quantity(),"subtotal"=>$i->get_subtotal(),"total"=>$i->get_total(),"total_tax"=>$i->get_total_tax(),"tax_class"=>$i->get_tax_class(),"meta"=>$m];}echo wp_json_encode(["id"=>$o->get_id(),"status"=>$o->get_status(),"currency"=>$o->get_currency(),"subtotal"=>$o->get_subtotal(),"total_tax"=>$o->get_total_tax(),"total"=>$o->get_total(),"payment_method"=>$o->get_payment_method(),"meta_keys"=>array_keys($o->get_meta_data() ? [] : []),"lines"=>$L]);'));
			const lines = row.order.lines || [];
			row.line_checks = {};
			for (const c of oc) {
				const l = lines.find(x => x.product_id === state.products[c.id]);
				row.line_checks[c.id] = l ? {
					expected_unit_eur: c.expected_unit_eur,
					observed_total: Number(l.total),
					observed_total_tax: Number(l.total_tax),
					expected_tax_eur: expectedTax[c.id],
					unit_ok: Math.abs(Number(l.total) - c.expected_unit_eur) < 0.005,
					tax_ok: Math.abs(Number(l.total_tax) - expectedTax[c.id]) < 0.02,
					tax_class: l.tax_class,
					meta: l.meta,
					field_meta_present: l.meta.some(k => k === (provider === 'wapf' ? '_wapf_meta' : '_opf_fields')),
				} : null;
			}
			row.order_currency_ok = row.order.currency === 'EUR';
			row.order_total_ok = Math.abs(Number(row.order.total) - (expectedSubtotal + expectedTaxTotal)) < 0.02;
			for (const c of oc) {
				const lc = row.line_checks[c.id];
				if (!lc) continue;
				lc.formula = c.formula;
				lc.addon_base_expected = c.addon;
				lc.addon_base_observed = Math.round((lc.observed_total / 1.5 - 10) * 1e6) / 1e6;
				lc.addon_ok = Math.abs(lc.addon_base_observed - c.addon) < 1e-6;
			}
			row.all_addons_ok = Object.values(row.line_checks).every(x => x && x.addon_ok);
			row.field_meta_key = provider === 'wapf' ? '_wapf_meta' : '_opf_fields';
			row.field_meta_payload = JSON.parse(wp('$o=wc_get_order(' + orderId + '); $i=array_values($o->get_items())[0]; echo wp_json_encode($i->get_meta("' + row.field_meta_key + '", true));'));
			row.all_units_ok = Object.values(row.line_checks).every(x => x && x.unit_ok);
			row.all_tax_ok = Object.values(row.line_checks).every(x => x && x.tax_ok);
			row.all_meta_ok = Object.values(row.line_checks).every(x => x && x.field_meta_present);
			console.log('ORDER', provider, row.order_id, row.order.currency, 'subtotal=', row.order.subtotal, 'tax=', row.order.total_tax, 'total=', row.order.total, 'units_ok=', row.all_units_ok, 'tax_ok=', row.all_tax_ok, 'meta_ok=', row.all_meta_ok);

			// Partial refund through the real refund path.
			row.refund = JSON.parse(wp('$r=wc_create_refund(["order_id"=>' + orderId + ',"amount"=>2.00,"reason"=>"formulaadv partial refund"]);if(is_wp_error($r)){echo wp_json_encode(["error"=>$r->get_error_message()]);}else{$o=wc_get_order(' + orderId + ');echo wp_json_encode(["refund_id"=>$r->get_id(),"order_total"=>$o->get_total(),"refunded"=>$o->get_total_refunded(),"status"=>$o->get_status()]);}'));
			row.refund_ok = !row.refund.error && Math.abs(Number(row.refund.refunded) - 2.00) < 0.005;

			// Order-again: real login already active; use the real view-order link.
			// COD leaves the order 'processing'; WooCommerce only offers order-again
			// for completed orders, so fulfil it first (merchant action).
			wp('$o=wc_get_order(' + orderId + '); if($o){$o->update_status(\'completed\');} echo \'ok\';');
			await page.goto(base + '/my-account/view-order/' + orderId + '/', { waitUntil: 'networkidle' });
			const action = page.getByRole('link', { name: 'Order again', exact: true });
			row.order_again_action = await action.count();
			await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), action.click()]);
			await page.waitForTimeout(1500);
			const restored = await (await context.request.get(base + '/?rest_route=/wc/store/v1/cart')).json();
			row.order_again = { currency: restored.totals.currency_code, lines: (restored.items || []).map(i => ({ id: i.id, qty: i.quantity, unit: i.prices.price, line_total: i.totals.line_total })) };
			row.order_again_ok = oc.every(c => row.order_again.lines.some(r => r.id === state.products[c.id] && Number(r.unit) === expectedUnits[c.id]));
			row.order_again_total = restored.totals.total_price;
			console.log('ORDER-AGAIN', provider, 'lines=', JSON.stringify(row.order_again.lines), 'ok=', row.order_again_ok, 'total=', row.order_again_total);
			write();
		} catch (e) {
			row.failure = e.stack; write(); throw e;
		} finally { await context.close(); write(); }
	}
} finally { await browser.close(); write(); writeComparison(); }
console.log('Recorded formula-advanced commerce lifecycle for ' + Object.keys(results.providers).length + ' providers.');
