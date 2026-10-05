const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/opf-frontend.js'), 'utf8');
const start = source.indexOf('const productImageAttributeNames =');
const end = source.indexOf('\n\nlet pricePreviewRequestId =', start);
assert.notEqual(start, -1, 'product image behavior must remain in the frontend asset');
assert.notEqual(end, -1, 'product image behavior must end before total rendering');
const sandbox = {};
vm.runInNewContext(
	`${source.slice(start, end)}\nglobalThis.resolveProductImageRule = resolveProductImageRule; globalThis.updateProductImage = updateProductImage;`,
	sandbox
);

test('last-changed mode requires the current field and value to match', () => {
	const rules = [
		{ target_url: '/red.jpg', conditions: [{ field: 'finish', value: 'red' }] },
		{ target_url: '/blue.jpg', conditions: [{ field: 'finish', value: 'blue' }] },
	];
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'red' }, 'last', 'finish'), rules[0]);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'blue' }, 'last', 'finish'), rules[1]);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'red' }, 'last', 'size'), null);
	assert.equal(sandbox.resolveProductImageRule(rules, { finish: 'green' }, 'last', 'finish'), null);
});

test('last-changed mode restores the original product image when the new field has no image rule', () => {
	const attrs = new Map([['src', 'https://shop.test/original.jpg'], ['srcset', 'original-2x.jpg 2x'], ['alt', 'Original']]);
	const linkAttrs = new Map([['href', 'https://shop.test/original-large.jpg']]);
	const link = { getAttribute: (name) => linkAttrs.get(name) ?? null, setAttribute: (name, value) => linkAttrs.set(name, String(value)), removeAttribute: (name) => linkAttrs.delete(name) };
	const image = { getAttribute: (name) => attrs.get(name) ?? null, setAttribute: (name, value) => attrs.set(name, String(value)), removeAttribute: (name) => attrs.delete(name), closest: () => link };
	const doc = { querySelector: () => image };
	const evaluation = {
		definitions: {},
		values: { finish: 'red', size: 'large' },
		imageRuleMode: 'last',
		rules: [{ target_url: 'https://cdn.test/red.jpg', conditions: [{ field: 'finish', value: 'red' }] }],
		lastChangedField: 'finish',
	};
	sandbox.updateProductImage(doc, [evaluation]);
	assert.equal(attrs.get('src'), 'https://cdn.test/red.jpg');
	evaluation.lastChangedField = 'size';
	sandbox.updateProductImage(doc, [evaluation]);
	assert.equal(attrs.get('src'), 'https://shop.test/original.jpg');
	assert.equal(attrs.get('srcset'), 'original-2x.jpg 2x');
	assert.equal(linkAttrs.get('href'), 'https://shop.test/original-large.jpg');
});
