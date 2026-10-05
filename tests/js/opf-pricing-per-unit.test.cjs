const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const context = {
	window: { opf_config: {} },
	opf_config: {},
	document: { readyState: 'loading', addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; } },
	console,
};
vm.createContext(context);
vm.runInContext(`${fs.readFileSync(sourcePath, 'utf8')}\nglobalThis.__choiceOrFieldAddon = choiceOrFieldAddon; globalThis.__writeTotals = writeTotals;`, context);

// choiceOrFieldAddon returns the PER-UNIT addon — the same space as
// Calculator::choice_addon (WAPF do_pricing parity).
//   normal field    : per_unit ? result : result/qty
//   qty_based field : per_unit ? result*qty : result

test('formula choice pricing is flat per line by default (WAPF fx parity)', () => {
	const def = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.2' } }] };
	assert.equal(context.__choiceOrFieldAddon(def, 'a', 100, 1, 0, ''), 20);
	assert.equal(context.__choiceOrFieldAddon(def, 'a', 100, 4, 0, ''), 5); // result/qty → line adds 20
});

test('explicit per_unit formula scales with quantity', () => {
	const def = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.2', per_unit: true } }] };
	assert.equal(context.__choiceOrFieldAddon(def, 'a', 100, 4, 0, ''), 20); // line adds 80
});

test('flat formulas keep live [qty] tokens', () => {
	const def = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '2 * [qty] + 5' } }] };
	assert.equal(context.__choiceOrFieldAddon(def, 'a', 100, 4, 0, ''), 13 / 4);
});

test('fixed stays flat per line and percent scales by default', () => {
	const flat = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'fixed', amount: 5 } }] };
	assert.equal(context.__choiceOrFieldAddon(flat, 'a', 100, 4, 0, ''), 1.25);
	const qt = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'fixed', amount: 5, per_unit: true } }] };
	assert.equal(context.__choiceOrFieldAddon(qt, 'a', 100, 4, 0, ''), 5);
	const pct = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'percent', amount: 10 } }] };
	assert.equal(context.__choiceOrFieldAddon(pct, 'a', 100, 4, 0, ''), 10);
	const p = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'percent', amount: 10, per_unit: false } }] };
	assert.equal(context.__choiceOrFieldAddon(p, 'a', 100, 5, 0, ''), 2);
});

test('verbatim [qty] formulas price as WAPF fx regardless of per_unit', () => {
	// Regression: per_unit must not re-scale a formula that consumed [qty] —
	// the line adds eval(formula) once (WAPF fixed per-unit calc_price).
	const verbatim = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.1 * [qty]', per_unit: true } }] };
	assert.equal(context.__choiceOrFieldAddon(verbatim, 'a', 100, 3, 0, ''), 10);
	assert.equal(context.__choiceOrFieldAddon(verbatim, 'a', 100, 1, 0, ''), 10);
	// qty_based row: eval verbatim per unit.
	assert.equal(context.__choiceOrFieldAddon(verbatim, 'a', 100, 3, 0, '', {}, {}, 100, true), 30);
	// A mapper-normalized formula (formula_raw ≠ formula) keeps per-unit
	// semantics even when an interior [qty] survives the strip.
	const normalized = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '([price] + [addons]) * [qty] + 1', formula_raw: '(([price] + [options_total]) * [qty] + 1) * [qty]', per_unit: true } }] };
	assert.equal(context.__choiceOrFieldAddon(normalized, 'a', 10, 3, 0, ''), 31);
});

test('scalar field normalized formula tracks server per-unit semantics', () => {
	// Regression for the documented preview mismatch: an imported WAPF scalar
	// formula whose legacy migration injected per_unit=true must charge the
	// evaluated result per unit, matching Calculator::field_pricing_addon —
	// not a flat line adjustment.
	const def = { type: 'number', pricing: { type: 'formula', formula: 'round(3)', formula_raw: 'round(3)', per_unit: true } };
	assert.equal(context.__choiceOrFieldAddon(def, '5', 100, 1, 0, '5'), 3);
	assert.equal(context.__choiceOrFieldAddon(def, '5', 100, 3, 0, '5'), 3); // line adds 9
	// A native flat scalar formula (explicit per_unit=false, as normalize_pricing
	// always emits the flag) divides by line quantity: line adds 3 once.
	const flat = { type: 'number', pricing: { type: 'formula', formula: 'round(3)', formula_raw: 'round(3)', per_unit: false } };
	assert.equal(context.__choiceOrFieldAddon(flat, '5', 100, 3, 0, '5'), 1);
	// Empty scalar input contributes nothing on either side.
	assert.equal(context.__choiceOrFieldAddon(def, '', 100, 3, 0, ''), 0);
});

test('image_quantity choices price the entered count as the value', () => {
	// WAPF image-swatch-qty: entered count is $val (nr/nrq/[x] consume it);
	// fixed stays flat per selected choice, qt per product unit.
	const def = {
		type: 'image_quantity',
		choices: [
			{ slug: 'oak', label: 'Oak', pricing: { type: 'fixed', amount: 2, per_unit: false } },
			{ slug: 'ash', label: 'Ash', pricing: { type: 'formula', formula: '[x]*3', per_unit: true } },
		],
	};
	const value = { _opf_type: 'image_quantity', quantities: { oak: 2, ash: 3 } };
	// oak flat 2/3 per unit + ash nrq 3*3 per unit.
	assert.equal(context.__choiceOrFieldAddon(def, value, 10, 3, 0, ''), 2 / 3 + 9);
});

test('qty_based rows apply the WAPF clone_type=qty truth table', () => {
	const flatFormula = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.2' } }] };
	assert.equal(context.__choiceOrFieldAddon(flatFormula, 'a', 100, 4, 0, '', {}, {}, 100, true), 20);
	const qt = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'fixed', amount: 5, per_unit: true } }] };
	assert.equal(context.__choiceOrFieldAddon(qt, 'a', 100, 4, 0, '', {}, {}, 100, true), 20);
	const flatFixed = { type: 'select', choices: [{ slug: 'a', pricing: { type: 'fixed', amount: 5 } }] };
	assert.equal(context.__choiceOrFieldAddon(flatFixed, 'a', 100, 4, 0, '', {}, {}, 100, true), 5);
});

test('writeTotals computes line totals from per-unit addons at qty > 1', () => {
	const makeField = (id) => ({
		getAttribute(name) { return 'data-opf-field' === name ? id : null; },
		hasAttribute() { return false; },
		matches() { return false; },
		closest() { return null; },
		querySelector(selector) {
			return selector === 'input:checked' ? { value: 'a', checked: true } : null;
		},
		querySelectorAll() { return []; },
	});
	const group = {
		getAttribute() { return 'g1'; },
		querySelectorAll(selector) { return '[data-opf-field]' === selector ? [makeField('plan')] : []; },
		querySelector(selector) {
			return /data-opf-field="plan"\] input:checked/.test(selector) ? { value: 'a', checked: true } : null;
		},
	};
	const totals = Object.fromEntries(['product', 'options', 'grand'].map((name) => [name, { innerHTML: '' }]));
	const totalsEl = {
		getAttribute(name) { return 'data-product-price' === name ? '10' : null; },
		querySelector(selector) {
			if (selector.includes('opf-product-total')) return totals.product;
			if (selector.includes('opf-options-total')) return totals.options;
			if (selector.includes('opf-grand-total')) return totals.grand;
			return null;
		},
	};
	context.document.querySelector = (selector) => {
		if (selector.includes('product-totals')) return totalsEl;
		if (selector.includes('form.cart')) return { value: '4' };
		return null;
	};
	context.document.querySelectorAll = (selector) => ('[data-opf-group]' === selector ? [group] : []);

	context.window.OPF_FIELDS = { g1: { plan: { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.2' } }] } } };
	context.__writeTotals();
	// flat: line adds the formula result once → 40 + 2 = 42.
	assert.equal(totals.options.innerHTML, '$2.00');
	assert.equal(totals.grand.innerHTML, '$42.00');

	context.window.OPF_FIELDS = { g1: { plan: { type: 'select', choices: [{ slug: 'a', pricing: { type: 'formula', formula: '[price] * 0.2', per_unit: true } }] } } };
	context.__writeTotals();
	// per-unit: 20% of 10 per unit → 40 + 8 = 48.
	assert.equal(totals.options.innerHTML, '$8.00');
	assert.equal(totals.grand.innerHTML, '$48.00');

	context.window.OPF_FIELDS.g1.plan.choices[0].pricing = { type: 'formula', formula: 'min(-5;0)', per_unit: true };
	context.__writeTotals();
	assert.equal(totals.options.innerHTML, '-$20.00');
	assert.equal(totals.grand.innerHTML, '$20.00');

	context.window.OPF_FIELDS.g1.plan.choices[0].pricing = { type: 'formula', formula: 'min(-100;0)' };
	context.__writeTotals();
	assert.equal(totals.options.innerHTML, '-$100.00');
	assert.equal(totals.grand.innerHTML, '$0.00', 'Only the final product price is clamped.');
});
