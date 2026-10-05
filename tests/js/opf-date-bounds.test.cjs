const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js');
const context = {
	window: { OPF_I18N: {} },
	document: { readyState: 'loading', addEventListener() {}, querySelector() { return null; } },
	console,
	Date,
	Math,
	Intl,
	JSON,
	Number,
	String,
	Array,
	Object,
};
vm.createContext(context);
vm.runInContext(
	`${fs.readFileSync(sourcePath, 'utf8')}
globalThis.__resolveRelativePeriodDate = resolveRelativePeriodDate;
globalThis.__resolveDateBoundExpression = resolveDateBoundExpression;
globalThis.__syncDateBounds = syncDateBounds;
globalThis.__dateSelectionAllowed = dateSelectionAllowed;`,
	context
);

test('relative date periods apply days then months then years (WAPF order)', () => {
	// Month-end makes the order observable: 1d -> Feb 1, then 1m -> Mar 1.
	assert.equal(context.__resolveRelativePeriodDate('2026-01-31', '1m 1d'), '2026-03-01');
	assert.equal(context.__resolveRelativePeriodDate('2026-06-15', '7d'), '2026-06-22');
	assert.equal(context.__resolveRelativePeriodDate('2026-06-15', '-1m -7d'), '2026-05-08');
});

test('field-relative bounds resolve against the referenced sibling date input', () => {
	const withSibling = (value) => ({ closest: () => ({ querySelector: () => ({ value }) }) });
	assert.equal(context.__resolveDateBoundExpression(withSibling('2026-06-15'), '[field.start]+1d', undefined), '2026-06-16');
	assert.equal(context.__resolveDateBoundExpression(withSibling('2026-06-15'), '[field.start]3m', undefined), '2026-09-15');
	// WAPF drops the bound when the referenced field is empty.
	assert.equal(context.__resolveDateBoundExpression(withSibling(''), '[field.start]+1d', undefined), '');
});

test('syncDateBounds sets and clears the native min attribute from the expression', () => {
	const host = { querySelector: () => ({ value: '2026-06-15' }) };
	const attrs = {};
	const input = {
		type: 'date',
		dataset: { opfDateMinExpression: '[field.start]+1d' },
		getAttribute: (key) => (key in attrs ? attrs[key] : null),
		setAttribute: (key, value) => { attrs[key] = value; },
		hasAttribute: (key) => key in attrs,
		removeAttribute: (key) => { delete attrs[key]; },
		closest: () => host,
	};
	assert.equal(context.__syncDateBounds(input), true);
	assert.equal(attrs.min, '2026-06-16');

	host.querySelector = () => ({ value: '' });
	assert.equal(context.__syncDateBounds(input), true);
	assert.equal('min' in attrs, false);
});

test('disable_today blocks today while the site clock is available', () => {
	const now = new Date();
	const today = now.toISOString().slice(0, 10);
	const tomorrow = new Date(now.getTime() + 86400000).toISOString().slice(0, 10);
	const base = {
		min: '',
		max: '',
		dataset: {
			opfDisableToday: '1',
			opfDateSiteEpoch: String(Math.floor(now.getTime() / 1000)),
			opfDateTimezone: 'UTC',
		},
	};
	assert.equal(context.__dateSelectionAllowed(base, today), false);
	assert.equal(context.__dateSelectionAllowed(base, tomorrow), true);

	const allowed = { min: '', max: '', dataset: {} };
	assert.equal(context.__dateSelectionAllowed(allowed, today), true);
});
