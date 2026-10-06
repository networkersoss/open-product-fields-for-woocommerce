const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../assets/js/opf-frontend.js'),
	'utf8'
);
const start = source.indexOf('const lookupTableValue =');
const end = source.indexOf('\n\nconst writeTotals =', start);
const visibilityStart = source.indexOf('const isVisible =');
const visibilityEnd = source.indexOf('\n\nconst init =', visibilityStart);
assert.notEqual(start, -1, 'pricing helper must remain in the frontend asset');
assert.notEqual(end, -1, 'pricing helper must remain before total rendering');
assert.notEqual(visibilityEnd, -1, 'conditional visibility helper must remain in the frontend asset');
const sandbox = { window: {} };
const moneyStart = source.indexOf('const fmtMoney =');
const moneyEnd = source.indexOf('\n\nconst lookupTableValue =', moneyStart);
assert.notEqual(moneyStart, -1, 'money formatter must remain in the frontend asset');
assert.notEqual(moneyEnd, -1, 'money formatter must remain before pricing helpers');
vm.runInNewContext(`${source.slice(moneyStart, moneyEnd)}\nglobalThis.formatMoney = fmtMoney;`, sandbox);
vm.runInNewContext(
	`${source.slice(visibilityStart, visibilityEnd)}\n${source.slice(start, end)}\nglobalThis.calculateAddon = choiceOrFieldAddon; globalThis.evaluateFormula = evalFormula; globalThis.resolveFormulaVariables = resolveFormulaVariables; globalThis.resolveGroupFormulaVariables = resolveGroupFormulaVariables; globalThis.resolveFieldPrices = resolveFieldPrices; globalThis.resolveCalculatedValues = resolveCalculatedValues; globalThis.updateChoicePriceHints = updateChoicePriceHints; globalThis.updateProductImage = updateProductImage; globalThis.resolveProductImageRule = resolveProductImageRule; globalThis.formatPriceHint = formatPriceHint;`,
	sandbox
);

const lineTotal = (field, value, base, quantity) =>
	sandbox.calculateAddon(field, value, base, quantity, 0, String(value)) * quantity;

test('negative and positive totals follow WooCommerce currency format', () => {
	sandbox.window.opf_config = { display_options: { symbol: '$', thousand: ',', decimal: '.', decimals: 2, price_format: 'symbolprice' } };
	assert.equal(sandbox.formatMoney(-1234.5), '-$1,234.50', 'negative sign precedes the whole configured price');
	assert.equal(sandbox.formatMoney(1234.5), '$1,234.50');
	sandbox.window.opf_config.display_options = { symbol: '€', thousand: '.', decimal: ',', decimals: 2, price_format: 'price symbol' };
	assert.equal(sandbox.formatMoney(-1234.5), '-1.234,50 €', 'suffix-currency spacing follows WooCommerce format');
	assert.equal(sandbox.formatMoney(1234.5), '1.234,50 €');
	sandbox.window.opf_config.display_options.price_format = 'price&nbsp;symbol';
	assert.equal(sandbox.formatMoney(-1234.5), '-1.234,50&nbsp;€', 'WooCommerce HTML entity format is preserved for totals innerHTML');
	sandbox.window.opf_config.display_options = { symbol: '¥', thousand: ',', decimal: '.', decimals: 0, price_format: 'symbolprice' };
	assert.equal(sandbox.formatMoney(-1234), '-¥1,234', 'zero-decimal currencies retain the leading minus');
	sandbox.window.opf_config = undefined;
});

test('opt-in image swatches replace the main product image and restore it when cleared', () => {
	const attributes = new Map([['src', 'https://shop.test/original.jpg'], ['srcset', 'original-2x.jpg 2x'], ['alt', 'Original']]);
	const image = {
		getAttribute: (key) => attributes.has(key) ? attributes.get(key) : null,
		setAttribute: (key, value) => attributes.set(key, String(value)),
		removeAttribute: (key) => attributes.delete(key),
	};
	const linkAttributes = new Map([['href', 'https://shop.test/original-large.jpg']]);
	const anchor = {
		getAttribute: (key) => linkAttributes.has(key) ? linkAttributes.get(key) : null,
		setAttribute: (key, value) => linkAttributes.set(key, String(value)),
		removeAttribute: (key) => linkAttributes.delete(key),
	};
	image.closest = () => anchor;
	const doc = { querySelector: () => image };
	const fields = { color: { type: 'swatch', change_product_image: true, choices: [{ slug: 'blue', image: 'https://shop.test/blue.jpg' }] } };

	sandbox.updateProductImage(doc, fields, { color: 'blue' });
	assert.equal(attributes.get('src'), 'https://shop.test/blue.jpg');
	assert.equal(attributes.has('srcset'), false, 'stale responsive source must not override selected image');
	assert.equal(linkAttributes.get('href'), 'https://shop.test/blue.jpg');
	sandbox.updateProductImage(doc, fields, { color: '' });
	assert.equal(attributes.get('src'), 'https://shop.test/original.jpg');
	assert.equal(attributes.get('srcset'), 'original-2x.jpg 2x');
	assert.equal(linkAttributes.get('href'), 'https://shop.test/original-large.jpg');
});

test('product image rules require all conditions, honor Any, and preserve last-match priority', () => {
	const rules = [
		{ target_url: '/red-large.jpg', conditions: [{ field: 'color', value: 'red' }, { field: 'size', value: 'large' }] },
		{ target_url: '/red-any.jpg', conditions: [{ field: 'color', value: 'red' }, { field: 'size', value: '*' }] },
	];
	assert.equal(sandbox.resolveProductImageRule(rules, { color: 'red', size: 'large' }), rules[1]);
	assert.equal(sandbox.resolveProductImageRule(rules, { color: 'red', size: 'small' }), rules[1]);
	assert.equal(sandbox.resolveProductImageRule(rules, { color: 'blue', size: 'large' }), null);
	assert.equal(sandbox.resolveProductImageRule(rules, { color: ['red', 'blue'], size: 'small' }), rules[1], 'multi-select conditions match selected values');
	assert.equal(sandbox.resolveProductImageRule([{ target_url: '/gift.jpg', conditions: [{ field: 'gift', value: '1' }] }], { gift: '1' }).target_url, '/gift.jpg', 'WAPF true-false values match OPF toggles');
});

test('last-changed image mode matches only the most recently changed field', () => {
	const rules = [
		{ target_url: '/red.jpg', conditions: [{ field: 'finish', value: 'red' }] },
		{ target_url: '/blue.jpg', conditions: [{ field: 'finish', value: 'blue' }] },
	];
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'red' }, 'last', 'finish'), rules[0]);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'blue' }, 'last', 'finish'), rules[1]);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'red' }, 'last', 'size'), null, 'another selected field cannot keep the finish image active');
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'green' }, 'last', 'finish'), null, 'a last-changed value without a mapped image falls back');
});

test('gallery image rules navigate to matching slides and restore the original slide on mismatch', () => {
	const makeImage = (src) => {
		const attrs = new Map([['src', src], ['data-large_image', src], ['alt', 'Product image']]);
		const linkAttrs = new Map([['href', src]]);
		const link = { getAttribute: (name) => linkAttrs.get(name) ?? null, setAttribute: (name, value) => linkAttrs.set(name, String(value)), removeAttribute: (name) => linkAttrs.delete(name) };
		const image = { currentSrc: src, getAttribute: (name) => attrs.get(name) ?? null, setAttribute: (name, value) => attrs.set(name, String(value)), removeAttribute: (name) => attrs.delete(name), closest: () => link };
		return { image, attrs, linkAttrs };
	};
	const first = makeImage('https://shop.test/front.jpg');
	const second = makeImage('https://shop.test/back.jpg');
	let active = 0;
	const slides = [first, second].map((item, index) => ({
		querySelector: (selector) => selector === 'img' ? item.image : null,
		classList: { contains: (name) => name === 'flex-active-slide' && active === index },
	}));
	const gallery = {
		querySelectorAll: (selector) => selector === '.woocommerce-product-gallery__image' ? slides : [],
		querySelector: (selector) => selector === '.flex-active-slide' ? slides[active] : null,
	};
	const flexslider = {};
	const control = { data: (key) => key === 'flexslider' ? flexslider : null, flexslider: (index) => { active = index; } };
	const doc = { location: { href: 'https://shop.test/product/' }, defaultView: { jQuery: () => control }, querySelector: (selector) => selector === '.woocommerce-product-gallery' ? gallery : null };
	const definitions = { color: { type: 'select', choices: [] } };
	const matching = { color: 'blue' };
	sandbox.updateProductImage(doc, [{ definitions, values: matching, rules: [{ target_url: 'https://shop.test/back.jpg', conditions: [{ field: 'color', value: 'blue' }] }] }]);
	assert.equal(active, 1, 'existing gallery target advances WooCommerce FlexSlider');
	sandbox.updateProductImage(doc, [{ definitions, values: { color: 'red' }, rules: [{ target_url: 'https://cdn.test/custom.jpg', conditions: [{ field: 'color', value: 'red' }] }] }]);
	assert.equal(second.attrs.get('src'), 'https://cdn.test/custom.jpg', 'external image replaces the currently active gallery slide');
	sandbox.updateProductImage(doc, [{ definitions, values: { color: 'red' }, rules: [{ target_url: 'https://shop.test/back.jpg', conditions: [{ field: 'color', value: 'blue' }] }] }]);
	assert.equal(active, 0, 'rule mismatch restores the initial gallery slide');
	assert.equal(second.attrs.get('src'), 'https://shop.test/back.jpg', 'mismatch restores every gallery slide image');
});

test('external image rules replace the active image and restore original image attributes when cleared', () => {
	const attrs = new Map([['src', 'https://shop.test/original.jpg'], ['srcset', 'original-2x.jpg 2x'], ['alt', 'Original']]);
	const linkAttrs = new Map([['href', 'https://shop.test/original-large.jpg']]);
	const link = { getAttribute: (name) => linkAttrs.get(name) ?? null, setAttribute: (name, value) => linkAttrs.set(name, String(value)), removeAttribute: (name) => linkAttrs.delete(name) };
	const image = { getAttribute: (name) => attrs.get(name) ?? null, setAttribute: (name, value) => attrs.set(name, String(value)), removeAttribute: (name) => attrs.delete(name), closest: () => link };
	const doc = { querySelector: (selector) => selector === '.woocommerce-product-gallery' ? null : image };
	const definitions = {};
	const target = 'https://cdn.test/custom.jpg';
	sandbox.updateProductImage(doc, [{ definitions, values: { color: 'green' }, rules: [{ target_url: target, conditions: [{ field: 'color', value: 'green' }] }] }]);
	assert.equal(attrs.get('src'), target);
	assert.equal(attrs.has('srcset'), false);
	assert.equal(linkAttrs.get('href'), target);
	sandbox.updateProductImage(doc, [{ definitions, values: { color: 'blue' }, rules: [{ target_url: target, conditions: [{ field: 'color', value: 'green' }] }] }]);
	assert.equal(attrs.get('src'), 'https://shop.test/original.jpg');
	assert.equal(attrs.get('srcset'), 'original-2x.jpg 2x');
	assert.equal(linkAttrs.get('href'), 'https://shop.test/original-large.jpg');
});

test('last-changed gallery mode updates and restores the image from interaction history', () => {
	const attrs = new Map([['src', 'https://shop.test/original.jpg'], ['alt', 'Original']]);
	const linkAttrs = new Map([['href', 'https://shop.test/original-large.jpg']]);
	const link = { getAttribute: (name) => linkAttrs.get(name) ?? null, setAttribute: (name, value) => linkAttrs.set(name, String(value)), removeAttribute: (name) => linkAttrs.delete(name) };
	const image = { getAttribute: (name) => attrs.get(name) ?? null, setAttribute: (name, value) => attrs.set(name, String(value)), removeAttribute: (name) => attrs.delete(name), closest: () => link };
	const doc = { querySelector: () => image };
	const evaluation = {
		definitions: {},
		values: { finish: 'red', size: 'large' },
		imageRuleMode: 'last',
		rules: [{ target_url: 'https://cdn.test/red-finish.jpg', conditions: [{ field: 'finish', value: 'red' }] }],
		lastChangedField: 'finish',
	};
	sandbox.updateProductImage(doc, [evaluation]);
	assert.equal(attrs.get('src'), 'https://cdn.test/red-finish.jpg');
	evaluation.lastChangedField = 'size';
	sandbox.updateProductImage(doc, [evaluation]);
	assert.equal(attrs.get('src'), 'https://shop.test/original.jpg', 'a later unrelated choice restores the base image');
	assert.equal(linkAttrs.get('href'), 'https://shop.test/original-large.jpg');
});

test('browser pricing preserves flat and quantity-based choice totals', () => {
	const fixed = (per_unit) => ({
		type: 'select',
		choices: [{ slug: 'gift', disabled: false, pricing: { type: 'fixed', amount: 5, per_unit } }],
	});
	const percent = (per_unit) => ({
		type: 'select',
		choices: [{ slug: 'gift', disabled: false, pricing: { type: 'percent', amount: 10, per_unit } }],
	});

	for (const quantity of [1, 4]) {
		assert.equal(lineTotal(fixed(false), 'gift', 100, quantity), 5);
		assert.equal(lineTotal(fixed(true), 'gift', 100, quantity), 5 * quantity);
		assert.equal(lineTotal(percent(false), 'gift', 100, quantity), 10);
		assert.equal(lineTotal(percent(true), 'gift', 100, quantity), 10 * quantity);
	}
});

test('formula pricing converts line results to unit addons without q-squared totals', () => {
	const formulaChoice = (formula) => ({
		type: 'select',
		choices: [{ slug: 'choice', disabled: false, pricing: { type: 'formula', formula } }],
	});
	const formulaField = (formula) => ({ type: 'text', pricing: { type: 'formula', formula } });
	for (const quantity of [1, 4]) {
		assert.equal(lineTotal(formulaChoice('5 * [qty]'), 'choice', 100, quantity), 5 * quantity);
		assert.equal(lineTotal(formulaChoice('5'), 'choice', 100, quantity), 5);
		assert.equal(lineTotal(formulaField('5 * [qty]'), 'engraved', 100, quantity), 5 * quantity);
		assert.equal(lineTotal(formulaField('5'), 'engraved', 100, quantity), 5);
	}
	assert.equal(lineTotal(formulaChoice('-5 * [qty]'), 'choice', 100, 4), -20);
});

test('price hints use live per-choice formula pricing and configured presentation', () => {
	const fieldDefinitions = {
		finish: {
			type: 'select', conditionals: [],
			choices: [
				{ slug: 'engraved', disabled: false, pricing: { type: 'formula', formula: 'if([field.size] = large; 7.5; 3.5)' } },
				{ slug: 'gift-box', disabled: false, pricing: { type: 'fixed', amount: 2.5, per_unit: true } },
			],
		},
		size: { type: 'select', conditionals: [], choices: [] },
	};
	const hints = [
		{ dataset: { opfChoiceHint: 'engraved' }, textContent: '' },
		{ dataset: { opfChoiceHint: 'gift-box' }, textContent: '' },
	];
	const fieldEl = { querySelectorAll: (selector) => selector === '[data-opf-choice-hint]' ? hints : [], querySelector: () => null };
	const values = { finish: 'engraved', size: 'large' };
	const fieldPrices = sandbox.resolveFieldPrices(fieldDefinitions, values, 100, 2, 0, {}, {});
	sandbox.updateChoicePriceHints(fieldEl, fieldDefinitions, 'finish', fieldDefinitions.finish, values, 100, 2, 0, {}, {}, fieldPrices);
	assert.equal(hints[0].textContent, '(+ $7.5)', 'formula hint uses its own quantity expression once');
	assert.equal(hints[1].textContent, '(+ $5)', 'fixed hint uses the browser pricing calculation');
	const smallValues = { finish: 'engraved', size: 'small' };
	const smallPrices = sandbox.resolveFieldPrices(fieldDefinitions, smallValues, 100, 2, 0, {}, {});
	sandbox.updateChoicePriceHints(fieldEl, fieldDefinitions, 'finish', fieldDefinitions.finish, smallValues, 100, 2, 0, {}, {}, smallPrices);
	assert.equal(hints[0].textContent, '(+ $3.5)', 'formula hint refreshes after a sibling value changes');
	sandbox.window.OPF_PRICE_HINTS = { show: true, brackets: false, plus: false };
	sandbox.updateChoicePriceHints(fieldEl, fieldDefinitions, 'finish', fieldDefinitions.finish, smallValues, 100, 2, 0, {}, {}, smallPrices);
	assert.equal(hints[0].textContent, '+ $3.5', 'plus suppression follows WAPF formula-pricing limitation');
	assert.equal(hints[1].textContent, '$5', 'global hint controls remove brackets and plus for flat pricing');
	sandbox.window.OPF_PRICE_HINTS = { show: false };
	sandbox.updateChoicePriceHints(fieldEl, fieldDefinitions, 'finish', fieldDefinitions.finish, smallValues, 100, 2, 0, {}, {}, smallPrices);
	assert.equal(hints[0].textContent, '', 'global setting hides hints');
	sandbox.window.OPF_PRICE_HINTS = undefined;
});

test('price hints follow flat, quantity and formula line math with Woo price format order', () => {
	const choices = [
		{ slug: 'flat', disabled: false, pricing: { type: 'fixed', amount: 5, per_unit: false } },
		{ slug: 'qty-fixed', disabled: false, pricing: { type: 'fixed', amount: 5, per_unit: true } },
		{ slug: 'flat-percent', disabled: false, pricing: { type: 'percent', amount: 10, per_unit: false } },
		{ slug: 'qty-percent', disabled: false, pricing: { type: 'percent', amount: 10, per_unit: true } },
		{ slug: 'qty-formula', disabled: false, pricing: { type: 'formula', formula: '5 * [qty]' } },
		{ slug: 'flat-formula', disabled: false, pricing: { type: 'formula', formula: '5' } },
		{ slug: 'credit-formula', disabled: false, pricing: { type: 'formula', formula: '-10 * [qty]' } },
	];
	const field = { type: 'select', conditionals: [], choices };
	const definitions = { pricing: field };
	const hints = choices.map((choice) => ({ dataset: { opfChoiceHint: choice.slug }, textContent: '' }));
	const fieldEl = { querySelectorAll: (selector) => selector === '[data-opf-choice-hint]' ? hints : [], querySelector: () => null };
	sandbox.window.OPF_PRICE_HINTS = { show: true, brackets: true, plus: true };
	sandbox.window.OPF_PRICE_DISPLAY = { symbol: '€', thousand: '.', decimal: ',', decimals: 2, price_format: 'price symbol' };
	for (const quantity of [1, 4]) {
		const prices = sandbox.resolveFieldPrices(definitions, {}, 100, quantity, 0, {}, {});
		sandbox.updateChoicePriceHints(fieldEl, definitions, 'pricing', field, {}, 100, quantity, 0, {}, {}, prices);
		const expected = quantity === 1
			? ['(+ 5 €)', '(+ 5 €)', '(+ 10 €)', '(+ 10 €)', '(+ 5 €)', '(+ 5 €)', '(- 10 €)']
			: ['(+ 5 €)', '(+ 20 €)', '(+ 10 €)', '(+ 40 €)', '(+ 20 €)', '(+ 5 €)', '(- 40 €)'];
		assert.deepEqual(hints.map((node) => node.textContent), expected);
	}
	sandbox.window.OPF_PRICE_HINTS = undefined;
	sandbox.window.OPF_PRICE_DISPLAY = undefined;
});

test('storefront hint conversion scales live choices by the server-published factor', () => {
	const choices = [
		{ slug: 'flat', disabled: false, pricing: { type: 'fixed', amount: 2, per_unit: true } },
		{ slug: 'pct', disabled: false, pricing: { type: 'percent', amount: 10, per_unit: true } },
		{ slug: 'half-price', disabled: false, pricing: { type: 'formula', formula: '[price] / 2' } },
		{ slug: 'qty-formula', disabled: false, pricing: { type: 'formula', formula: '5 * [qty]' } },
	];
	const field = { type: 'select', conditionals: [], choices };
	const definitions = { pricing: field };
	const hints = choices.map((choice) => ({ dataset: { opfChoiceHint: choice.slug }, textContent: '' }));
	const fieldEl = { querySelectorAll: (selector) => selector === '[data-opf-choice-hint]' ? hints : [], querySelector: () => null };
	sandbox.window.OPF_PRICE_HINTS = { show: true, brackets: true, plus: true };
	sandbox.window.OPF_PRICE_DISPLAY = { symbol: '$', thousand: ',', decimal: '.', decimals: 2, price_format: 'symbolprice' };

	// Display base 15 equals shop base 10 × factor 1.5 — the live CURCY case.
	const prices = sandbox.resolveFieldPrices(definitions, {}, 10, 1, 0, {}, {});
	sandbox.updateChoicePriceHints(fieldEl, definitions, 'pricing', field, {}, 15, 1, 0, {}, {}, prices, true, 1.5);
	assert.deepEqual(
		hints.map((node) => node.textContent),
		['(+ $3)', '(+ $1)', '(+ $7.5)', '(+ $7.5)'],
		'fixed and formula convert once; percent-derived hints never convert; [price] formulas use the shop base'
	);

	// No published factor (and the default argument) keep the pre-fix output.
	sandbox.updateChoicePriceHints(fieldEl, definitions, 'pricing', field, {}, 15, 1, 0, {}, {}, prices, true, 1);
	const withFactorOne = hints.map((node) => node.textContent);
	sandbox.updateChoicePriceHints(fieldEl, definitions, 'pricing', field, {}, 15, 1, 0, {}, {}, prices);
	assert.deepEqual(hints.map((node) => node.textContent), withFactorOne, 'factor 1 and the default argument are identical');
	assert.equal(withFactorOne[0], '(+ $2)', 'no conversion leaves the base-currency fixed hint');
	assert.equal(withFactorOne[1], '(+ $1.5)', 'no conversion leaves the base-currency percent hint');
	sandbox.window.OPF_PRICE_HINTS = undefined;
	sandbox.window.OPF_PRICE_DISPLAY = undefined;
});

test('scalar and price-calculation hints use the same live line math', () => {
	sandbox.window.OPF_PRICE_HINTS = { show: true, brackets: true, plus: true };
	sandbox.window.OPF_PRICE_DISPLAY = { symbol: '$', thousand: ',', decimal: '.', decimals: 2, price_format: 'symbolprice' };
	const formulaField = { type: 'text', default: '', pricing: { type: 'formula', formula: '5 * [qty]' } };
	const fixedField = { type: 'number', default: '2', pricing: { type: 'fixed', amount: 8, per_unit: false } };
	const calculationField = { type: 'calculation', calculation_type: 'price', formula: '3 * [qty]' };
	const definitions = { formula: formulaField, fixed: fixedField, charge: calculationField };
	const makeElement = (hint) => ({ querySelectorAll: () => [], querySelector: () => hint });
	const formulaHint = { textContent: '' };
	const fixedHint = { textContent: '' };
	const calculationHint = { textContent: '' };
	for (const quantity of [1, 4]) {
		const prices = sandbox.resolveFieldPrices(definitions, {}, 100, quantity, 0, {}, {});
		sandbox.updateChoicePriceHints(makeElement(formulaHint), definitions, 'formula', formulaField, {}, 100, quantity, 0, {}, {}, prices);
		sandbox.updateChoicePriceHints(makeElement(fixedHint), definitions, 'fixed', fixedField, {}, 100, quantity, 0, {}, {}, prices);
		sandbox.updateChoicePriceHints(makeElement(calculationHint), definitions, 'charge', calculationField, {}, 100, quantity, 0, {}, {}, prices);
		assert.equal(formulaHint.textContent, quantity === 1 ? '(+ $5)' : '(+ $20)');
		assert.equal(fixedHint.textContent, '(+ $8)');
		assert.equal(calculationHint.textContent, '(+ $' + 3 * quantity + ')');
	}
	sandbox.window.OPF_PRICE_HINTS = undefined;
	sandbox.window.OPF_PRICE_DISPLAY = undefined;
});

test('choice hints decode the WooCommerce currency symbol entity published by the server', () => {
	sandbox.window.OPF_PRICE_HINTS = { show: true, brackets: true, plus: true };
	const format = ( symbol ) => {
		sandbox.window.OPF_PRICE_DISPLAY = { symbol, thousand: ',', decimal: '.', decimals: 2, price_format: 'symbolprice' };
		return sandbox.formatPriceHint( 10, 'fixed' );
	};

	// `get_woocommerce_currency_symbol()` output: the hint is written through
	// `textContent`, which does not decode entities.
	assert.equal(format('&#36;'), '(+ $10)');
	assert.equal(format('&euro;'), '(+ €10)');
	assert.equal(format('&pound;'), '(+ £10)');
	assert.equal(format('&#8377;'), '(+ ₹10)');
	assert.equal(format('$'), '(+ $10)', 'an already decoded symbol is untouched');
	assert.equal(format('&notanentity;'), '(+ &notanentity;10)', 'unknown entities are left as published');

	sandbox.window.OPF_PRICE_DISPLAY = { symbol: '&#36;', thousand: ',', decimal: '.', decimals: 2, price_format: 'price symbol' };
	assert.equal(sandbox.formatPriceHint( 10, 'fixed' ), '(+ 10 $)', 'the symbol decodes in suffix formats too');

	sandbox.window.OPF_PRICE_HINTS = undefined;
	sandbox.window.OPF_PRICE_DISPLAY = undefined;
});

test('browser pricing preserves flat and quantity-based text and number totals', () => {
	const modes = [
		{ type: 'characters', value: 'éx!', amount: 2, expected: 6 },
		{ type: 'value', value: '3', amount: 2, expected: 6 },
	];
	for (const mode of modes) {
		const flat = { type: 'text', pricing: { type: mode.type, amount: mode.amount, per_unit: false } };
		const quantityBased = { type: 'text', pricing: { type: mode.type, amount: mode.amount, per_unit: true } };
		for (const quantity of [1, 4]) {
			assert.equal(lineTotal(flat, mode.value, 100, quantity), mode.expected);
			assert.equal(lineTotal(quantityBased, mode.value, 100, quantity), mode.expected * quantity);
		}
	}

	const quantityFee = { type: 'text', pricing: { type: 'quantity', amount: 3 } };
	assert.equal(lineTotal(quantityFee, 'yes', 100, 1), 3);
	assert.equal(lineTotal(quantityFee, 'yes', 100, 4), 12);
});

test('browser calculation formulas read numeric values from sibling fields', () => {
	assert.equal(
		sandbox.evaluateFormula('[field.width] * [field.height]', 50, 2, 0, '', { width: '4', height: '3' }),
		12
	);
	assert.equal(
		sandbox.evaluateFormula('[field.width] + [price] + [qty]', 10, 2, 0, '', { width: '5' }),
		17
	);
	assert.equal(
		sandbox.evaluateFormula('[field.width] * 2', 10, 2, 0, '', { width: '<script>' }),
		0
	);
	assert.equal(sandbox.evaluateFormula('[price] + [options_total]', 100, 1, 25, '', {}), 125);
	assert.equal(sandbox.evaluateFormula('if([field.width] > 5; 10; 20)', 100, 1, 0, '', { width: '8' }), 10);
	assert.equal(sandbox.evaluateFormula('if(and([field.width] >= 5; [field.width] < 10); 10; 20)', 100, 1, 0, '', { width: '4' }), 20);
	assert.equal(sandbox.evaluateFormula('if(or([field.width] = 8; [field.width] != 4); 10; 20)', 100, 1, 0, '', { width: '8' }), 10);
	assert.equal(sandbox.evaluateFormula('max(min([price]; 4); abs(-2))', 10, 1, 0, '', {}), 4);
	assert.ok(Math.abs(sandbox.evaluateFormula('round(0.33337; 4)', 10, 1, 0, '', {}) - 0.3334) < 0.000001);
	assert.equal(sandbox.evaluateFormula('round(4.8)', 10, 1, 0, '', {}), 5);
	assert.equal(sandbox.evaluateFormula('ceil(4.1)', 10, 1, 0, '', {}), 5);
	assert.equal(sandbox.evaluateFormula('floor(4.8)', 10, 1, 0, '', {}), 4);
	assert.equal(sandbox.evaluateFormula('pow(4; 2)', 10, 1, 0, '', {}), 16);
	assert.equal(sandbox.evaluateFormula('sqrt(144)', 10, 1, 0, '', {}), 12);
	assert.equal(sandbox.evaluateFormula('sqrt(-1)', 10, 1, 0, '', {}), 0);
	assert.equal(sandbox.evaluateFormula('round(2.5)', 10, 1, 0, '', {}), 3);
	assert.equal(sandbox.evaluateFormula('round(-2.5)', 10, 1, 0, '', {}), -3);
	assert.equal(sandbox.evaluateFormula('min(1)', 10, 1, 0, '', {}), 1);
	assert.equal(sandbox.evaluateFormula('min(4; 2; 3)', 10, 1, 0, '', {}), 2);
	assert.equal(sandbox.evaluateFormula('max(4; 9; 3)', 10, 1, 0, '', {}), 9);
	assert.ok(Math.abs(sandbox.evaluateFormula('sin(0.5)', 10, 1, 0, '', {}) - Math.sin(0.5)) < 0.000001);
	assert.ok(Math.abs(sandbox.evaluateFormula('cos(0.5)', 10, 1, 0, '', {}) - Math.cos(0.5)) < 0.000001);
	assert.ok(Math.abs(sandbox.evaluateFormula('tan(0.5)', 10, 1, 0, '', {}) - Math.tan(0.5)) < 0.000001);
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; [field.end])', 10, 1, 0, '', { start: '2026-06-01', end: '2026-06-10' }, '2026-06-15'), 9);
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; [field.end]) * 40', 10, 1, 0, '', { start: '2026-06-01', end: '2026-06-10' }, '2026-06-15'), 360);
	// PHP parity: Calculator uses DateTime::diff()->days — always absolute.
	assert.equal(sandbox.evaluateFormula('datediff([field.end]; [field.start])', 10, 1, 0, '', { start: '2026-06-01', end: '2026-06-10' }, '2026-06-15'), 9);
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; today())', 10, 1, 0, '', { start: '2026-06-01' }, '2026-06-15'), 14);
	assert.equal(sandbox.evaluateFormula('datediff(today(); [field.end])', 10, 1, 0, '', { end: '2026-06-29' }, '2026-06-15'), 14);
	sandbox.opf_config = { date_format: 'mm-dd-yyyy' };
	assert.equal(sandbox.evaluateFormula("dow('01-10-2023')", 10, 1, 0, '', {}), 2, 'WAPF weekday numbering starts with Sunday at zero');
	assert.equal(sandbox.evaluateFormula('dow([field.start])', 10, 1, 0, '', { start: '2024-01-01' }), 1);
	assert.equal(sandbox.evaluateFormula('dow(today())', 10, 1, 0, '', {}, '2026-06-15'), 1);
	assert.equal(sandbox.evaluateFormula("month('01-03-2023')", 10, 1, 0, '', {}), 1, 'default WAPF month-day-year format parses January 3');
	assert.equal(sandbox.evaluateFormula("month('03-01-2023')", 10, 1, 0, '', {}), 3);
	assert.equal(sandbox.evaluateFormula("dow('02-30-2023')", 10, 1, 0, '', {}), 0, 'invalid calendar dates fail closed');
	sandbox.opf_config.date_format = 'dd/mm/yyyy';
	assert.equal(sandbox.evaluateFormula("dow('31/12/2023')", 10, 1, 0, '', {}), 0);
	assert.equal(sandbox.evaluateFormula("month('31/12/2023')", 10, 1, 0, '', {}), 12);
	sandbox.opf_config = undefined;
	sandbox.OPF_TODAY = '2026-06-15';
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; today())', 10, 1, 0, '', { start: '2026-06-01' }), 14);
	delete sandbox.OPF_TODAY;
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; [field.end])', 10, 1, 0, '', { start: '', end: '2026-06-10' }, '2026-06-15'), 0);
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; [field.end])', 10, 1, 0, '', { start: '2026-02-30', end: '2026-06-10' }, '2026-06-15'), 0);
	assert.equal(sandbox.evaluateFormula('datediff([field.start]; [field.end])', 10, 1, 0, '', { start: '2024-02-28', end: '2024-03-01' }, '2024-03-01'), 2);
	assert.equal(sandbox.evaluateFormula('checked(extras)', 10, 1, 0, '', { extras: ['gift', 'priority'] }), 2);
	assert.equal(sandbox.evaluateFormula('checked(extras)', 10, 1, 0, '', { extras: [] }), 0);
	assert.equal(sandbox.evaluateFormula('checked(extras)', 10, 1, 0, '', { extras: 'gift' }), 0);
	assert.equal(sandbox.evaluateFormula('checked(extras) * 5 * [qty]', 10, 3, 0, '', { extras: ['gift', 'priority'] }), 30);
	assert.equal(sandbox.evaluateFormula('files(artwork) * 5 * [qty]', 10, 3, 0, '', {}, '', { artwork: 2 }), 30);
	assert.equal(sandbox.evaluateFormula('files(artwork)', 10, 1, 0, '', {}, '', {}), 0);
	assert.equal(sandbox.evaluateFormula('sumQty(images)', 10, 1, 0, '', { images: { a: '2', b: '3' } }), 5);
	assert.equal(sandbox.evaluateFormula('sumQty(images)', 10, 1, 0, '', { images: { a: 'bad' } }), 0);
	assert.equal(sandbox.evaluateFormula('len(a quick brown fox; true)', 10, 1, 0, '', {}), 14);
	assert.equal(sandbox.evaluateFormula('len([field.title]; false)', 10, 1, 0, '', { title: 'Déjà vu' }), 7);
	assert.equal(sandbox.evaluateFormula('len([field.title]; true)', 10, 1, 0, '', { title: 'Déjà vu' }), 6);
	assert.equal(sandbox.evaluateFormula('if([field.color] = Red; 10; 20)', 10, 1, 0, '', { color: 'Red' }), 10);
	assert.equal(sandbox.evaluateFormula('if([field.color] = Red; 10; 20)', 10, 1, 0, '', { color: 'Blue' }), 20);
});

test('browser calculation outputs resolve forward dependencies and fail closed when hidden or cyclic', () => {
	const definitions = {
		area: { type: 'calculation', formula: '[field.width] * 2', conditionals: [] },
		width: { type: 'number', conditionals: [] },
		large_only: {
			type: 'checkbox', conditionals: [{ action: 'show', logic: 'all', rules: [{ field: 'area', operator: 'greater', value: '10' }] }],
		},
	};
	assert.deepEqual(
		{ ...sandbox.resolveCalculatedValues(definitions, { width: '6', large_only: ['yes'] }) },
		{ width: '6', area: 12, large_only: ['yes'] }
	);
	assert.deepEqual(
		{ ...sandbox.resolveCalculatedValues(definitions, { width: '4', large_only: ['yes'] }) },
		{ width: '4', area: 8 }
	);

	const hiddenDefinitions = {
		mode: { type: 'select', conditionals: [] },
		area: { type: 'calculation', formula: '20', conditionals: [{ action: 'hide', logic: 'all', rules: [{ field: 'mode', operator: 'is', value: 'hide' }] }] },
		large_only: { type: 'checkbox', conditionals: [{ action: 'show', logic: 'all', rules: [{ field: 'area', operator: 'greater', value: '10' }] }] },
	};
	assert.deepEqual(
		{ ...sandbox.resolveCalculatedValues(hiddenDefinitions, { mode: 'hide', large_only: ['forged'] }) },
		{ mode: 'hide' }
	);

	const cyclic = {
		first: { type: 'calculation', formula: '[field.second] + 1', conditionals: [] },
		second: { type: 'calculation', formula: '[field.first] + 1', conditionals: [] },
		dependent: { type: 'text', conditionals: [{ action: 'show', logic: 'all', rules: [{ field: 'first', operator: 'not_empty', value: '' }] }] },
	};
	assert.deepEqual({ ...sandbox.resolveCalculatedValues(cyclic, { dependent: 'forged' }) }, {});
});

test('browser runtime refresh reveals a calculation-dependent field and updates its price preview', () => {
	const output = { textContent: '' };
	let widthField;
	let widthInput;
	let premiumInput;
	let premiumHint;
	let premiumChoiceHint;
	let inputHandler;
	let pricing;
	const field = (id, input = null, calculation = null) => {
		const node = {
			id,
			hidden: false,
			dataset: {},
			classList: {
				toggle: (name, enabled) => { if (name === 'opf-hide') node.hidden = enabled; },
				contains: (name) => name === 'opf-hide' && node.hidden,
			},
			getAttribute: (name) => name === 'data-opf-field' ? id : null,
			hasAttribute: (name) => name === 'hidden' && node.hidden,
			toggleAttribute: (name, enabled) => { if (name === 'hidden') node.hidden = enabled; },
			querySelector: (selector) => {
				if (selector === '[data-opf-calculation]') return calculation;
				if (selector === '[data-opf-field-hint]') return id === 'premium' ? premiumHint : null;
				if (selector === '.acc-value') return null;
				if (selector === 'input:not([type="hidden"]), textarea, select') return input;
				if (selector.startsWith('input:not')) return input && input.type !== 'checkbox' && input.type !== 'radio' ? input : null;
				return null;
			},
			querySelectorAll: (selector) => selector === '[data-opf-choice-hint]' && id === 'premium' ? [premiumChoiceHint] : [],
		};
		return node;
	};
	widthInput = { type: 'number', value: '4', name: 'opf[g][width]', checked: false, dataset: {}, setCustomValidity: () => {}, closest: () => widthField };
	premiumInput = { type: 'checkbox', value: 'yes', name: 'opf[g][premium][]', checked: true, dataset: {}, setCustomValidity: () => {}, closest: () => null };
	premiumHint = { textContent: '' };
	premiumChoiceHint = { dataset: { opfChoiceHint: 'yes' }, textContent: '' };
	widthField = field('width', widthInput);
	const areaField = field('area', null, output);
	const premiumField = field('premium', premiumInput);
	const fields = [areaField, widthField, premiumField];
	const group = {
		getAttribute: (name) => ({ 'data-opf-group': 'g', 'data-opf-product-price': '100' })[name] || null,
		hasAttribute: (name) => name === 'data-opf-product-price',
		querySelectorAll: (selector) => {
			if (selector === '[data-opf-field]') return fields;
			if (selector === '.opf-swatch') return [];
			if (selector.includes('premium') && selector.includes('input:checked')) return premiumInput.checked ? [premiumInput] : [];
			return [];
		},
		querySelector: (selector) => selector.includes('premium') && selector.includes('input:checked') && premiumInput.checked ? premiumInput : null,
		addEventListener: (type, handler) => { if (type === 'input') inputHandler = handler; },
	};
	const definitions = {
		area: { type: 'calculation', formula: '[field.width] * 2', result_text: '{result}' },
		width: { type: 'number' },
		premium: { type: 'checkbox', conditionals: [{ action: 'show', logic: 'all', rules: [{ field: 'area', operator: 'greater', value: '10' }] }], choices: [{ slug: 'yes', label: 'Premium', disabled: false, pricing: { type: 'fixed', amount: 7, per_unit: true } }] },
	};
	const context = {
		window: { OPF_FIELDS: { g: definitions } },
		document: {
			readyState: 'complete',
			querySelector: (selector) => selector.includes('quantity') ? { value: '2' } : null,
			querySelectorAll: () => [group],
			dispatchEvent: (event) => { pricing = event.detail; },
		},
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
	};
	const visibilityStart = source.indexOf('const isVisible =');
	const initStart = source.indexOf('const init =');
	const initAllStart = source.indexOf('const initAll =');
	assert.notEqual(initAllStart, -1, 'runtime init must remain at the end of the frontend asset');
	vm.runInNewContext(
		`const REGISTRY = window.OPF_FIELDS || {};\n${source.slice(visibilityStart, initAllStart)}\nglobalThis.runInit = init; globalThis.runWriteTotals = writeTotals;`,
		context
	);
	context.runInit();
	assert.equal(areaField.hidden, false);
	assert.equal(premiumField.hidden, true, 'calc dependent field starts hidden below its threshold');
	assert.equal(premiumChoiceHint.textContent, '', 'hidden priced choices do not display active hints');
	assert.equal(output.textContent, '8');
	widthInput.value = '6';
	inputHandler({ target: widthInput });
	context.runWriteTotals();
	assert.equal(premiumField.hidden, false, 'calc dependent field becomes visible after input changes');
	assert.equal(premiumChoiceHint.textContent, '(+ $14)', 'visible choice hint refreshes from current quantity');
	assert.equal(output.textContent, '12');
	assert.equal(pricing.options, 14);
	assert.equal(pricing.final, 214);
});

test('browser date validation blocks today at the site-local cutoff and allows tomorrow', () => {
	let runtimeClock = Date.parse('2026-06-15T14:29:00Z');
	class RuntimeDate extends Date { static now() { return runtimeClock; } }
	const dateInput = {
		type: 'date', value: '2026-06-15', name: 'opf[g][delivery_date]', checked: false,
		dataset: { opfDateCutoff: '14:30', opfDateSiteEpoch: String(runtimeClock / 1000), opfDateTimezone: 'UTC', opfAllowPast: '0', opfAllowFuture: '1' },
		validityMessage: '', setCustomValidity(message) { this.validityMessage = message; },
		closest: () => dateField,
	};
	let inputHandler;
	const dateField = {
		hidden: false, dataset: {}, classList: { toggle() {} },
		getAttribute: (name) => name === 'data-opf-field' ? 'delivery_date' : null,
		toggleAttribute() {},
		querySelector: (selector) => selector.includes('input[type="date"]') || selector.includes('input:not') ? dateInput : null,
		querySelectorAll: () => [],
	};
	const group = {
		getAttribute: (name) => ({ 'data-opf-group': 'g', 'data-opf-product-price': '100' })[name] || null,
		querySelectorAll: (selector) => selector === '[data-opf-field]' ? [dateField] : [],
		addEventListener: (name, handler) => { if (name === 'input') inputHandler = handler; },
	};
	let dateTicker;
	const context = {
		window: { OPF_FIELDS: { g: { delivery_date: { type: 'date', conditionals: [] } } } },
		document: { querySelectorAll: () => [group], dispatchEvent() {} },
		Date: RuntimeDate,
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
	};
	context.window.setInterval = (callback) => { dateTicker = callback; return 1; };
	const visibilityStart = source.indexOf('const isVisible =');
	const initAllStart = source.indexOf('const initAll =');
	vm.runInNewContext(`const REGISTRY = window.OPF_FIELDS || {};\n${source.slice(visibilityStart, initAllStart)}\nglobalThis.runInit = init;`, context);
	context.runInit();
	assert.equal(dateInput.validityMessage, '', 'today is allowed before the cutoff');
	dateInput.value = '2026-06-14';
	inputHandler({ target: dateInput });
	assert.equal(dateInput.validityMessage, 'Past dates are unavailable.');
	dateInput.value = '2026-06-16';
	inputHandler({ target: dateInput });
	assert.equal(dateInput.validityMessage, '');
	dateInput.value = '2026-06-15';
	inputHandler({ target: dateInput });
	runtimeClock += 60000;
	dateTicker();
	assert.equal(dateInput.validityMessage, 'Today is no longer available.');
	dateInput.value = '2026-06-16';
	inputHandler({ target: dateInput });
	assert.equal(dateInput.validityMessage, '');
	dateInput.dataset.opfAllowFuture = '0';
	inputHandler({ target: dateInput });
	assert.equal(dateInput.validityMessage, 'Future dates are unavailable.');
});

test('date calendar follows all past/future toggle combinations in the site timezone', () => {
	const runtimeClock = Date.parse('2026-06-15T10:00:00Z');
	class RuntimeDate extends Date { static now() { return runtimeClock; } }
	const snippetStart = source.indexOf('const dateSiteClock =');
	const snippetEnd = source.indexOf('const init =', snippetStart);
	const context = { Intl, Date: RuntimeDate, Number, JSON };
	vm.runInNewContext(`${source.slice(snippetStart, snippetEnd)}\nglobalThis.isDateAllowed = dateSelectionAllowed;`, context);
	for (const [allowPast, allowFuture] of [[true, true], [true, false], [false, true], [false, false]]) {
		const input = { min: '', max: '', dataset: {
			opfAllowPast: allowPast ? '1' : '0', opfAllowFuture: allowFuture ? '1' : '0',
			opfDateSiteEpoch: String(runtimeClock / 1000), opfDateClientEpoch: String(runtimeClock), opfDateTimezone: 'UTC',
		} };
		assert.equal(context.isDateAllowed(input, '2026-06-14'), allowPast, `past date policy ${allowPast}/${allowFuture}`);
		assert.equal(context.isDateAllowed(input, '2026-06-15'), true, `today policy ${allowPast}/${allowFuture}`);
		assert.equal(context.isDateAllowed(input, '2026-06-16'), allowFuture, `future date policy ${allowPast}/${allowFuture}`);
	}
	const legacyInput = { min: '', max: '', dataset: {} };
	assert.equal(context.isDateAllowed(legacyInput, '2026-06-14'), true, 'unconfigured legacy fields retain the unrestricted behavior');
	assert.equal(context.isDateAllowed(legacyInput, '2026-06-16'), true, 'unconfigured legacy fields retain the unrestricted behavior');
});

test('date calendar disables all dates when the cutoff timezone is unavailable', () => {
	const runtimeClock = Date.parse('2026-06-15T14:29:00Z');
	class RuntimeDate extends Date { static now() { return runtimeClock; } }
	const input = {
		min: '', max: '', dataset: {
			opfDateCutoff: '14:30', opfDateSiteEpoch: String(runtimeClock / 1000),
			opfDateClientEpoch: String(runtimeClock), opfDateTimezone: 'Unavailable/Timezone',
		},
	};
	const snippetStart = source.indexOf('const dateSiteClock =');
	const snippetEnd = source.indexOf('const init =', snippetStart);
	const context = { Intl, Date: RuntimeDate, Number, JSON };
	vm.runInNewContext(`${source.slice(snippetStart, snippetEnd)}\nglobalThis.isDateAllowed = dateSelectionAllowed;`, context);
	assert.equal(context.isDateAllowed(input, '2026-06-15'), false, 'today is blocked when the configured timezone cannot be resolved');
	assert.equal(context.isDateAllowed(input, '2026-06-14'), false, 'past date is blocked when cutoff timezone is unavailable');
	assert.equal(context.isDateAllowed(input, '2026-06-16'), false, 'future date is blocked when cutoff timezone is unavailable');
	assert.equal(context.isDateAllowed(input, '2026-06-17'), false, 'all picker dates fail closed without the configured site clock');
});

test('date calendar disables the cutoff day and lets customers select an allowed date', () => {
	class Node {
		constructor(tagName) { this.tagName = tagName; this.children = []; this.attributes = {}; this.dataset = {}; this.listeners = {}; this.hidden = false; this.disabled = false; this.tabIndex = 0; this._text = ''; }
		set textContent(value) { this._text = String(value); if (this.tagName === 'div') this.children = []; }
		get textContent() { return this._text; }
		appendChild(child) { this.children.push(child); return child; }
		setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'id') this.id = String(value); }
		addEventListener(name, callback) { this.listeners[name] = callback; }
		focus() { this.focused = true; }
		dispatchEvent(event) { this.lastEvent = event; if (this.listeners[event.type]) this.listeners[event.type](event); return true; }
		closest(selector) { return selector === 'button[data-opf-date]' && this.tagName === 'button' && this.dataset.opfDate ? this : null; }
		find(predicate) { if (predicate(this)) return this; for (const child of this.children) { const result = child.find ? child.find(predicate) : null; if (result) return result; } return null; }
		querySelectorAll(selector) { return selector === 'button[data-opf-date]' ? this.children.flatMap((child) => child.querySelectorAll ? child.querySelectorAll(selector) : []).concat(this.children.filter((child) => child.tagName === 'button' && child.dataset.opfDate)) : []; }
		querySelector(selector) {
			if (selector === '.opf-date-picker') return this.find((node) => node.className === 'opf-date-picker');
			if (selector === 'button[tabindex="0"]') return this.find((node) => node.tagName === 'button' && node.tabIndex === 0 && !node.disabled);
			if (selector === 'button[aria-pressed="true"]:not(:disabled)') return this.find((node) => node.tagName === 'button' && node.attributes['aria-pressed'] === 'true' && !node.disabled);
			const match = selector.match(/button\[data-opf-date="([^"]+)"\]/);
			if (match) return this.find((node) => node.tagName === 'button' && node.dataset.opfDate === match[1] && !node.disabled);
			return null;
		}
	}
	const document = { createElement: (tagName) => new Node(tagName) };
	let runtimeClock = Date.parse('2026-06-15T14:29:00Z');
	class RuntimeDate extends Date { static now() { return runtimeClock; } }
	const input = new Node('input');
	input.id = 'delivery-date';
	input.value = '2026-06-15';
	input.min = '';
	input.max = '';
	input.dataset = { opfDateCutoff: '14:30', opfDateSiteEpoch: String(runtimeClock / 1000), opfDateTimezone: 'UTC', opfDateFormat: 'd/m/yy', opfWeekStart: '1' };
	const field = new Node('div');
	field.querySelector = () => null;
	const snippetStart = source.indexOf('const dateSiteClock =');
	const snippetEnd = source.indexOf('const init =', snippetStart);
	const context = { document, window: { Event: function Event(type, options) { this.type = type; this.bubbles = options.bubbles; } }, Intl, Date: RuntimeDate, Number, String, JSON };
	vm.runInNewContext(`${source.slice(snippetStart, snippetEnd)}\nglobalThis.runDatePicker = initDatePicker;`, context);
	context.runDatePicker(field, input);
	const calendar = field.find((node) => node.className === 'opf-date-picker__grid');
	assert.deepEqual(calendar.children[0].children.map((node) => node.textContent), [ 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' ], 'weekday headings follow the configured Monday start');
	assert.equal(calendar.children[1].children[0].dataset.opfDate, '2026-06-01', 'the first calendar week aligns its first date under Monday');
	const today = calendar.find((node) => node.dataset.opfDate === '2026-06-15');
	const tomorrow = calendar.find((node) => node.dataset.opfDate === '2026-06-16');
	assert.equal(today.disabled, false, 'the current date is selectable before the cutoff');
	assert.equal(tomorrow.disabled, false, 'the next day remains selectable');
	const toggle = field.find((node) => node.className === 'opf-date-picker__toggle');
	toggle.listeners.click();
	const selectedToday = calendar.find((node) => node.dataset.opfDate === '2026-06-15');
	assert.equal(selectedToday.focused, true, 'opening the calendar focuses the selected date');
	calendar.listeners.keydown({ key: 'Home', target: selectedToday, preventDefault() {} });
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-15').tabIndex, 0, 'Home moves to Monday for a Monday-start calendar');
	calendar.listeners.keydown({ key: 'End', target: selectedToday, preventDefault() {} });
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-21').tabIndex, 0, 'End moves to Sunday for a Monday-start calendar');
	let prevented = false;
	calendar.listeners.keydown({ key: 'ArrowRight', target: selectedToday, preventDefault() { prevented = true; } });
	const rovingTomorrow = calendar.find((node) => node.dataset.opfDate === '2026-06-16');
	assert.equal(prevented, true);
	assert.equal(rovingTomorrow.tabIndex, 0, 'arrow navigation moves the single tab stop');
	assert.equal(rovingTomorrow.focused, true);
	calendar.listeners.keydown({ key: 'PageDown', target: rovingTomorrow, preventDefault() {} });
	const nextMonthDate = calendar.find((node) => node.dataset.opfDate === '2026-07-16');
	assert.equal(nextMonthDate.tabIndex, 0, 'PageDown moves the roving tab stop to the same day next month');
	assert.equal(nextMonthDate.focused, true, 'PageDown moves focus with the active date');
	calendar.listeners.keydown({ key: 'PageUp', target: nextMonthDate, preventDefault() {} });
	const restoredDate = calendar.find((node) => node.dataset.opfDate === '2026-06-16');
	assert.equal(restoredDate.tabIndex, 0, 'PageUp restores the active date and its tab stop');
	const panel = field.find((node) => node.className === 'opf-date-picker__panel');
	panel.listeners.keydown({ key: 'Escape', preventDefault() {} });
	assert.equal(panel.hidden, true, 'Escape closes the calendar');
	assert.equal(toggle.focused, true, 'Escape returns focus to the calendar toggle');
	toggle.listeners.click();
	runtimeClock += 60000;
	input.opfRenderDateCalendar();
	const cutoffDay = calendar.find((node) => node.dataset.opfDate === '2026-06-15');
	assert.equal(cutoffDay.disabled, true, 'the open calendar disables today once the site-local cutoff passes');
	calendar.find((node) => node.dataset.opfDate === '2026-06-16').listeners.click();
	assert.equal(input.value, '2026-06-16');
	assert.equal(input.lastEvent.type, 'change');
	assert.equal(toggle.textContent, '16/6/26', 'the selected-date control uses the configured format');
	assert.equal(toggle.attributes['aria-label'], 'Change date, 16/6/26');
	input.dataset.opfDisabledWeekdays = '[1]';
	input.dataset.opfDisabledDates = '["2026-06-17","06-19","2026-06-20 2026-06-22","12-24 01-03"]';
	input.opfRenderDateCalendar();
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-15').disabled, true, 'disabled weekdays are visibly unavailable');
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-16').disabled, false, 'an allowed weekday stays selectable');
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-17').disabled, true, 'an explicit date is visibly unavailable');
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-19').disabled, true, 'a recurring month/day date is visibly unavailable');
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-20').disabled, true, 'the start of an exact date range is unavailable');
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-21').disabled, true, 'dates inside an exact date range are unavailable');
	assert.equal(calendar.find((node) => node.dataset.opfDate === '2026-06-22').disabled, true, 'the end of an exact date range is unavailable');
	input.dataset.opfDisabledWeekdays = '[0,1,2,3,4,5,6]';
	input.opfRenderDateCalendar();
	assert.equal(field.find((node) => node.className === 'opf-date-picker__status').textContent, 'No selectable dates this month.');
});

test('browser calculation dependencies receive lookup and formula pricing context', () => {
	const definitions = {
		result: { type: 'calculation', formula: '[price.option] + [addons] + [options_total] + [price] / 100 + [qty] + files(art) + lookuptable(rates; size)', conditionals: [] },
		option: { type: 'select', conditionals: [] },
		size: { type: 'number', conditionals: [] },
	};
	const values = sandbox.resolveCalculatedValues(
		definitions,
		{ option: 'yes', size: '5' },
		100,
		1,
		3,
		{ rates: [['5', 10]] },
		{},
		{ art: 2 },
		{ option: 4 }
	);
	assert.equal(values.result, 24);
});

test('browser formula variables use defaults and first matching local condition', () => {
	const definitions = {
		wrap_cost: {
			default: 1,
			changes: [
				{ value: 1.5, logic: 'all', rules: [{ field: 'size', operator: 'is', value: 'large' }] },
				{ value: 2, logic: 'any', rules: [{ field: 'size', operator: 'is', value: 'large' }] },
			],
		},
	};
	assert.deepEqual({ ...sandbox.resolveFormulaVariables(definitions, { size: 'large' }) }, { wrap_cost: 1.5 });
	assert.deepEqual({ ...sandbox.resolveFormulaVariables(definitions, { size: 'small' }) }, { wrap_cost: 1 });
	assert.equal(sandbox.evaluateFormula('[var_wrap_cost] * 3', 100, 1, 0, '', {}, '', {}, {}, { wrap_cost: 1.5 }), 4.5);
	assert.equal(sandbox.evaluateFormula('[var_unknown] * 3', 100, 1, 0, '', {}), 0);
});

test('browser ACF formula calls resolve numeric product and options values', () => {
	const variables = { opf_acf_field_unit_cost: 12.5, opf_acf_option_gold_price: 3.25 };
	assert.equal(sandbox.evaluateFormula('acf(unit_cost) + acf_option(gold_price) * 2', 0, 1, 0, '', {}, '', {}, {}, variables), 19);
	assert.equal(sandbox.evaluateFormula('acf(missing) + acf_option(missing)', 0, 1, 0, '', {}, '', {}, {}, variables), 0);
	assert.equal(sandbox.evaluateFormula('acf_option(1 + injected)', 0, 1, 0, '', {}, '', {}, {}, variables), 0);
	const field = { type: 'select', choices: [{ slug: 'gold', disabled: false, pricing: { type: 'formula', formula: 'acf(unit_cost) * [qty]' } }] };
	assert.equal(sandbox.calculateAddon(field, 'gold', 0, 4, 0, '', {}, {}, variables), 12.5);
});

test('browser group formula variables merge product-specific ACF values', () => {
	sandbox.window.OPF_FORMULA_VARIABLES = { group: { wrap_cost: { default: 2, changes: [] } } };
	sandbox.window.OPF_ACF_VARIABLES = { group: { opf_acf_field_unit_cost: 12.5 } };
	assert.deepEqual(
		{ ...sandbox.resolveGroupFormulaVariables('group', {}) },
		{ wrap_cost: 2, opf_acf_field_unit_cost: 12.5 }
	);
});

test('browser field-price tokens resolve selected per-item prices and forward references', () => {
	const fields = {
		early: {
			type: 'select', conditionals: [], pricing: { type: 'none' },
			choices: [{ slug: 'yes', disabled: false, pricing: { type: 'formula', formula: '[price.later] + [price.size]' } }],
		},
		size: {
			type: 'select', conditionals: [], pricing: { type: 'none' },
			choices: [{ slug: 'large', disabled: false, pricing: { type: 'fixed', amount: 20, per_unit: true } }],
		},
		later: {
			type: 'select', conditionals: [], pricing: { type: 'none' },
			choices: [{ slug: 'gold', disabled: false, pricing: { type: 'formula', formula: '[price.size] * 0.25' } }],
		},
	};
	const values = { early: 'yes', size: 'large', later: 'gold' };
	const prices = sandbox.resolveFieldPrices(fields, values, 100, 1, 0, {}, {});

	assert.equal(prices.size, 20);
	assert.equal(prices.later, 5);
	assert.equal(prices.early, 25);
	assert.equal(sandbox.evaluateFormula('[price.EARLY]', 100, 1, 0, '', {}, '', {}, {}, {}, prices), 25);
	assert.equal(sandbox.evaluateFormula('[price.missing]', 100, 1, 0, '', {}, '', {}, {}, {}, prices), 0);
});

test('browser field-price reference cycles fail closed for every member', () => {
	const fields = {
		a: { type: 'select', conditionals: [], choices: [{ slug: 'yes', disabled: false, pricing: { type: 'formula', formula: '[price.b] + 1' } }] },
		b: { type: 'select', conditionals: [], choices: [{ slug: 'yes', disabled: false, pricing: { type: 'formula', formula: '[price.a] + 1' } }] },
	};
	const prices = sandbox.resolveFieldPrices(fields, { a: 'yes', b: 'yes' }, 100, 1, 0, {}, {});
	assert.equal(prices.a, 0);
	assert.equal(prices.b, 0);
});

test('browser lookup formulas resolve exact rows and two-dimensional numeric ceilings', () => {
	const tables = {
		quantity_price: [['100', 10], ['200', 20]],
		blinds: [
			['200', '100', 70], ['220', '100', 74],
			['200', '160', 74], ['220', '160', 78],
	],
		print: [['500', '4x6', 'Glossy', 30], ['1000', '4x6', 'Glossy', 56]],
	};
	assert.equal(sandbox.evaluateFormula('lookuptable(quantity_price; quantity)', 0, 1, 0, '', { quantity: '150' }, '', {}, tables), 20);
	assert.equal(sandbox.evaluateFormula('lookuptable(blinds; width; height)', 0, 1, 0, '', { width: '210', height: '150' }, '', {}, tables), 78);
	assert.equal(sandbox.evaluateFormula('lookuptable(blinds; width; height)', 0, 1, 0, '', { width: '200', height: '100' }, '', {}, tables), 70);
	assert.equal(sandbox.evaluateFormula('lookuptable(print; quantity; size; paper)', 0, 1, 0, '', { quantity: '1000', size: '4x6', paper: 'Glossy' }, '', {}, tables), 56);
	assert.equal(sandbox.evaluateFormula('lookuptable(print; quantity; size; paper)', 0, 1, 0, '', { quantity: '750', size: '4x6', paper: 'Glossy' }, '', {}, tables), 0);
	assert.equal(sandbox.evaluateFormula('lookuptable(blinds; width; height)', 0, 1, 0, '', { width: '230', height: '150' }, '', {}, tables), 0);
	const choice = { type: 'select', choices: [{ slug: 'custom', disabled: false, pricing: { type: 'formula', formula: 'lookuptable(blinds; width; height)' } }] };
	assert.equal(sandbox.calculateAddon(choice, 'custom', 0, 2, 0, '', { width: '210', height: '150' }, tables) * 2, 78);
});

test('image quantity choice pricing multiplies by selected image quantities', () => {
	const field = {
		type: 'swatch', image_quantities: true,
		choices: [
			{ slug: 'a', disabled: false, pricing: { type: 'fixed', amount: 2, per_unit: true } },
			{ slug: 'b', disabled: false, pricing: { type: 'fixed', amount: 3, per_unit: true } },
		],
	};
	assert.equal(lineTotal(field, { a: '1', b: '2' }, 50, 2), 16);
});

test('informational calculations update when no totals element is rendered', () => {
	const output = { textContent: '' };
	const inputs = {
		width: { value: '4' },
		area: null,
	};
	const fields = ['width', 'area'].map((id) => ({
		getAttribute: (name) => name === 'data-opf-field' ? id : null,
		hasAttribute: () => false,
		querySelector: (selector) => {
			if (selector === '[data-opf-calculation]') return id === 'area' ? output : null;
			return inputs[id];
		},
		querySelectorAll: () => [],
	}));
	const group = {
		getAttribute: (name) => ({ 'data-opf-group': 'g', 'data-opf-product-price': '10' })[name] || null,
		hasAttribute: (name) => name === 'data-opf-product-price',
		querySelectorAll: () => fields,
		querySelector: () => null,
	};
	const context = {
		window: { OPF_FIELDS: { g: {
			width: { type: 'number' },
			area: { type: 'calculation', formula: '[var_wrap_cost] * [qty]', result_text: '{result} sq ft' },
		} }, OPF_FORMULA_VARIABLES: { g: { wrap_cost: { default: 0.5, changes: [{ value: 1.5, logic: 'all', rules: [{ field: 'width', operator: 'is', value: '4' }] }] } } } },
		document: {
			querySelector: (selector) => selector.indexOf('quantity') !== -1 ? { value: '2' } : null,
			querySelectorAll: () => [group],
			dispatchEvent: () => {},
		},
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
	};
	const writeStart = source.indexOf('const evalFormula =');
	const writeEnd = source.indexOf('\n\nconst initTotals', writeStart);
	const visibilityStart = source.indexOf('const isVisible =');
	const visibilityEnd = source.indexOf('\n\nconst init =', visibilityStart);
	assert.notEqual(writeStart, -1, 'frontend pricing helpers must exist');
	assert.notEqual(writeEnd, -1, 'frontend initialization must follow the writer');
	vm.runInNewContext(`${source.slice(visibilityStart, visibilityEnd)}\n${source.slice(writeStart, writeEnd)}\nglobalThis.runWriteTotals = writeTotals;`, context);
	context.runWriteTotals();
	assert.equal(output.textContent, '3 sq ft', 'resolved variable sees populated field value before formula evaluation');
});

test('browser formula counts ignore selected checkbox fields hidden by conditionals', () => {
	const output = { textContent: '' };
	const selected = { type: 'checkbox', value: 'gift' };
	const fields = ['controller', 'extras', 'count'].map((id) => ({
		getAttribute: (name) => name === 'data-opf-field' ? id : null,
		hasAttribute: () => false,
		querySelector: (selector) => {
			if (selector === '[data-opf-calculation]') return id === 'count' ? output : null;
			if (id === 'controller' && selector.startsWith('input:not')) return { value: 'hide' };
			return null;
		},
		querySelectorAll: () => [],
	}));
	const group = {
		getAttribute: (name) => ({ 'data-opf-group': 'g', 'data-opf-product-price': '10' })[name] || null,
		hasAttribute: (name) => name === 'data-opf-product-price',
		querySelectorAll: (selector) => selector === '[data-opf-field]' ? fields : selector.includes('extras') ? [selected] : [],
		querySelector: (selector) => selector.includes('extras') ? selected : null,
	};
	const context = {
		window: { OPF_FIELDS: { g: {
			controller: { type: 'text' },
			extras: { type: 'checkbox', conditionals: [{ action: 'hide', logic: 'all', rules: [{ field: 'controller', operator: 'is', value: 'hide' }] }] },
			count: { type: 'calculation', formula: 'checked(extras) * 5', result_text: '{result}' },
		} } },
		document: {
			querySelector: () => null,
			querySelectorAll: () => [group],
			dispatchEvent: () => {},
		},
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
	};
	const visibilityStart = source.indexOf('const isVisible =');
	const visibilityEnd = source.indexOf('\n\nconst init =', visibilityStart);
	const writeStart = source.indexOf('const evalFormula =');
	const writeEnd = source.indexOf('\n\nconst initTotals', writeStart);
	vm.runInNewContext(`${source.slice(visibilityStart, visibilityEnd)}\n${source.slice(writeStart, writeEnd)}\nglobalThis.runWriteTotals = writeTotals;`, context);
	context.runWriteTotals();
	assert.equal(output.textContent, '0');
});

test('browser file counts preview selected uploads and ignore conditionally hidden upload fields', () => {
	const output = { textContent: '' };
	const fields = ['artwork', 'hidden_artwork', 'controller', 'count'].map((id) => ({
		getAttribute: (name) => name === 'data-opf-field' ? id : null,
		hasAttribute: () => false,
		querySelector: (selector) => {
			if (selector === '[data-opf-calculation]') return id === 'count' ? output : null;
			if (selector === 'input[type="file"]' && id === 'artwork') return { files: [ {}, {} ], value: '' };
			if (selector === 'input[type="file"]' && id === 'hidden_artwork') return { files: [ {}, {}, {} ], value: '' };
			if (id === 'controller' && selector.startsWith('input:not')) return { value: 'hide' };
			return null;
		},
		querySelectorAll: () => [],
	}));
	const group = {
		getAttribute: (name) => ({ 'data-opf-group': 'g', 'data-opf-product-price': '10' })[name] || null,
		hasAttribute: (name) => name === 'data-opf-product-price',
		querySelectorAll: (selector) => selector === '[data-opf-field]' ? fields : [],
		querySelector: () => null,
	};
	const context = {
		window: { OPF_FIELDS: { g: {
			artwork: { type: 'upload' },
			hidden_artwork: { type: 'upload', conditionals: [{ action: 'hide', logic: 'all', rules: [{ field: 'controller', operator: 'is', value: 'hide' }] }] },
			controller: { type: 'text' },
			count: { type: 'calculation', formula: '(files(artwork) + files(hidden_artwork)) * 5', result_text: '{result}' },
		} } },
		document: { querySelector: () => null, querySelectorAll: () => [group], dispatchEvent: () => {} },
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
	};
	const visibilityStart = source.indexOf('const isVisible =');
	const visibilityEnd = source.indexOf('\n\nconst init =', visibilityStart);
	const writeStart = source.indexOf('const evalFormula =');
	const writeEnd = source.indexOf('\n\nconst initTotals', writeStart);
	vm.runInNewContext(`${source.slice(visibilityStart, visibilityEnd)}\n${source.slice(writeStart, writeEnd)}\nglobalThis.runWriteTotals = writeTotals;`, context);
	context.runWriteTotals();
	assert.equal(output.textContent, '10');
});

test('price calculations add or subtract line adjustments in the browser mirror', () => {
	const outputs = { upcharge: { textContent: '' }, credit: { textContent: '' } };
	const inputs = { width: { value: '4' } };
	const fields = ['width', 'upcharge', 'credit'].map((id) => ({
		getAttribute: (name) => name === 'data-opf-field' ? id : null,
		hasAttribute: () => false,
		querySelector: (selector) => selector === '[data-opf-calculation]' ? outputs[id] || null : inputs[id],
		querySelectorAll: () => [],
	}));
	const group = {
		getAttribute: (name) => ({ 'data-opf-group': 'g', 'data-opf-product-price': '10' })[name] || null,
		hasAttribute: (name) => name === 'data-opf-product-price',
		querySelectorAll: () => fields,
		querySelector: () => null,
	};
	let pricing;
	const context = {
		window: { OPF_FIELDS: { g: {
			width: { type: 'number' },
			upcharge: { type: 'calculation', calculation_type: 'price', formula: '[field.width] * 2', result_text: '+{result}' },
			credit: { type: 'calculation', calculation_type: 'price', formula: '0 - [field.width]', result_text: '{result}' },
		} } },
		document: {
			querySelector: (selector) => selector.indexOf('quantity') !== -1 ? { value: '3' } : null,
			querySelectorAll: () => [group],
			dispatchEvent: (event) => { pricing = event.detail; },
		},
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
	};
	const writeStart = source.indexOf('const evalFormula =');
	const writeEnd = source.indexOf('\n\nconst initTotals', writeStart);
	const visibilityStart = source.indexOf('const isVisible =');
	const visibilityEnd = source.indexOf('\n\nconst init =', visibilityStart);
	vm.runInNewContext(`${source.slice(visibilityStart, visibilityEnd)}\n${source.slice(writeStart, writeEnd)}\nglobalThis.runWriteTotals = writeTotals;`, context);
	context.runWriteTotals();
	assert.equal(outputs.upcharge.textContent, '+8');
	assert.equal(outputs.credit.textContent, '-4');
	assert.equal(pricing.base, 30);
	assert.ok(Math.abs(pricing.options - 4) < 0.000001);
	assert.ok(Math.abs(pricing.final - 34) < 0.000001);
	assert.equal(pricing.quantity, 3);
});

test('summary totals use WooCommerce shop-context totals after raw option math', async () => {
	const amountNode = () => ({
		innerHTML: '',
		set textContent(value) { this.innerHTML = String(value); },
		get textContent() { return this.innerHTML; },
	});
	const amounts = {
		product: amountNode(),
		options: amountNode(),
		grand: amountNode(),
	};
	let previewRequest = null;
	let failPreview = false;
	let racePreview = false;
	const pendingPreviews = [];
	const quantityInput = { value: '1' };
	const previewStatus = { hidden: true, textContent: '' };
	let pricingDetail = null;
	const totals = {
		getAttribute: (name) => ({ 'data-product-price': '10', 'data-product-id': '41', 'data-opf-tax-product-id': '41', 'data-opf-price-preview-url': '/wp-json/opf/v1/price-preview' })[name] ?? null,
		setAttribute: () => {},
		removeAttribute: () => {},
		querySelectorAll: () => [ amounts.product, amounts.options, amounts.grand ],
		querySelector: (selector) => selector.includes('preview-status') ? previewStatus : selector.includes('product-total') ? amounts.product : selector.includes('options-total') ? amounts.options : amounts.grand,
	};
	const valueInput = { value: 'premium' };
	const field = {
		getAttribute: (name) => name === 'data-opf-field' ? 'finish' : null,
		hasAttribute: () => false,
		querySelector: () => valueInput,
		querySelectorAll: () => [],
	};
	const group = {
		getAttribute: (name) => name === 'data-opf-group' ? 'g' : null,
		hasAttribute: (name) => name === 'data-opf-group',
		querySelectorAll: () => [ field ],
		querySelector: () => null,
	};
	const context = {
		window: {
			OPF_FIELDS: {
				g: {
					finish: {
						type: 'select',
						choices: [ { slug: 'premium', pricing: { type: 'fixed', amount: 5, per_unit: true } } ],
					},
				},
			},
		},
		document: {
		querySelector: (selector) => selector.includes('totals') ? totals : selector.includes('quantity') ? quantityInput : null,
			querySelectorAll: () => [ group ],
		dispatchEvent: (event) => { pricingDetail = event.detail; },
		},
		CustomEvent: function CustomEvent(name, options) { this.type = name; this.detail = options.detail; },
		fmtMoney: sandbox.formatMoney,
		fetch: async (url, options) => {
			previewRequest = { url, body: JSON.parse(options.body), credentials: options.credentials };
			if (racePreview) {
				return new Promise((resolve) => pendingPreviews.push({ body: previewRequest.body, resolve }));
			}
			return failPreview
				? { ok: false, json: async () => ({}) }
				: { ok: true, json: async () => ({ product_total: 12, options_total: 6, grand_total: 18 }) };
		},
	};
	sandbox.window.opf_config = { display_options: { symbol: '$', thousand: ',', decimal: '.', decimals: 2, price_format: 'symbolprice' } };
	const writeStart = source.indexOf('const evalFormula =');
	const writeEnd = source.indexOf('\n\nconst initTotals', writeStart);
	const visibilityStart = source.indexOf('const isVisible =');
	const visibilityEnd = source.indexOf('\n\nconst init =', visibilityStart);
	vm.runInNewContext(`${source.slice(visibilityStart, visibilityEnd)}\n${source.slice(writeStart, writeEnd)}\nglobalThis.runWriteTotals = writeTotals;`, context);
	await context.runWriteTotals();
	assert.deepEqual(previewRequest, {
		url: '/wp-json/opf/v1/price-preview',
		body: { product_id: 41, options: 5, quantity: 1 },
		credentials: 'same-origin',
	});
	assert.equal(previewStatus.hidden, true);
	assert.equal(amounts.product.innerHTML, '$12.00');
	assert.equal(amounts.options.innerHTML, '$6.00');
	assert.equal(amounts.grand.innerHTML, '$18.00');
	assert.equal(JSON.stringify(pricingDetail), JSON.stringify({
		base: 10,
		options: 5,
		final: 15,
		quantity: 1,
		displayed: { base: 12, options: 6, final: 18 },
	}));
	failPreview = true;
	await context.runWriteTotals();
	assert.equal(amounts.product.textContent, '');
	assert.equal(amounts.options.textContent, '');
	assert.equal(amounts.grand.textContent, '');
	assert.equal(previewStatus.hidden, false);
	assert.equal(previewStatus.textContent, 'The price preview could not be updated. The final price will be calculated by WooCommerce.');
	failPreview = false;
	racePreview = true;
	quantityInput.value = '2';
	const staleRequest = context.runWriteTotals();
	quantityInput.value = '3';
	const newestRequest = context.runWriteTotals();
	assert.deepEqual(pendingPreviews.map((entry) => entry.body.quantity), [ 2, 3 ]);
	pendingPreviews[1].resolve({ ok: true, json: async () => ({ product_total: 30, options_total: 15, grand_total: 45 }) });
	await newestRequest;
	pendingPreviews[0].resolve({ ok: true, json: async () => ({ product_total: 20, options_total: 10, grand_total: 30 }) });
	await staleRequest;
	assert.equal(amounts.product.innerHTML, '$30.00');
	assert.equal(amounts.options.innerHTML, '$15.00');
	assert.equal(amounts.grand.innerHTML, '$45.00');
});
