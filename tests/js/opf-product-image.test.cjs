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
// `imageUrlMatches()` normalises URLs with `new URL()`, which a fresh vm
// context does not provide.
sandbox.URL = URL;
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

// A gallery whose slider is Flickity (Flatsome) or Swiper (Woodmart): the
// slides are still `.woocommerce-product-gallery__image`, but there is no
// `jQuery(...).data('flexslider')` and no WooCommerce `.flex-control-nav`
// thumbnail, so `navigate()` cannot move it. Astra Pro's thumbnail strip also
// carries `.flex-active-slide` inside the same gallery, which the wrapper-scoped
// lookup must not mistake for the main viewport slide.
const buildGallery = (urls) => {
	const writes = [];
	const navigations = [];
	const slides = urls.map((url) => {
		const attrs = new Map([['src', url], ['data-large_image', url], ['alt', url]]);
		const image = {
			attrs,
			getAttribute: (name) => (attrs.has(name) ? attrs.get(name) : null),
			setAttribute: (name, value) => { writes.push(`${name}=${value}`); attrs.set(name, String(value)); },
			removeAttribute: (name) => { writes.push(`${name}=null`); attrs.delete(name); },
			closest: () => null,
		};
		return { image, querySelector: (selector) => (selector === 'img' ? image : null) };
	});
	const thumb = { className: 'ast-woocommerce-product-gallery__image flex-active-slide' };
	let active = slides[0];
	const gallery = {
		querySelectorAll: (selector) => (selector === '.woocommerce-product-gallery__image' ? slides : []),
		querySelector: (selector) => {
			if (selector === '.woocommerce-product-gallery__wrapper .flex-active-slide') return active;
			if (selector === '.flex-active-slide') return thumb;
			return null;
		},
	};
	const doc = {
		querySelector: (selector) => {
			if (selector === '.woocommerce-product-gallery') return gallery;
			if (selector === '.woocommerce-product-gallery img.wp-post-image' || selector === '.woocommerce-product-gallery img') return slides[0].image;
			return null;
		},
	};
	return {
		doc,
		slides,
		writes,
		navigations,
		withFlexslider: () => {
			doc.defaultView = { jQuery: () => ({ data: () => ({}), flexslider: (index) => { navigations.push(index); active = slides[index]; } }) };
		},
	};
};

const ruleEvaluation = (targetUrl, field, value) => ({
	definitions: {},
	values: { [field]: value },
	rules: [{ target_url: targetUrl, conditions: [{ field, value }] }],
});

const variationForm = (url) => ({
	querySelector: (selector) => (selector === 'input[name="variation_id"], input.variation_id' ? { value: '55' } : null),
	getAttribute: (name) => (name === 'data-product_variations'
		? JSON.stringify([{ variation_id: 55, image: { full_src: url, src: url, alt: '', title: '' } }])
		: null),
});

test('a rule targeting a gallery slide paints it when the gallery has no FlexSlider navigation', () => {
	const fixture = buildGallery(['https://shop.test/main.png', 'https://shop.test/v-small.png', 'https://shop.test/rule-a.png']);
	sandbox.updateProductImage(fixture.doc, [ruleEvaluation('https://shop.test/rule-a.png', 'finish', 'gold')]);

	assert.equal(fixture.slides[0].image.attrs.get('src'), 'https://shop.test/rule-a.png');
	assert.equal(fixture.slides[0].image.attrs.get('data-large_image'), 'https://shop.test/rule-a.png');
	assert.deepEqual(fixture.navigations, [], 'no FlexSlider instance is involved');
});

test('a selected variation image that is a gallery slide is painted when navigation fails', () => {
	const fixture = buildGallery(['https://shop.test/main.png', 'https://shop.test/v-small.png', 'https://shop.test/v-large.png']);
	const baseQuery = fixture.doc.querySelector;
	fixture.doc.querySelector = (selector) => (selector === 'form.variations_form' ? variationForm('https://shop.test/v-large.png') : baseQuery(selector));
	sandbox.updateProductImage(fixture.doc, [{ definitions: {}, values: {}, rules: [] }]);

	assert.equal(fixture.slides[0].image.attrs.get('src'), 'https://shop.test/v-large.png');
	assert.equal(fixture.slides[0].image.attrs.get('alt'), '', 'variation alt wins over the slide alt');
});

test('a FlexSlider gallery still navigates to the matching slide instead of painting it', () => {
	const fixture = buildGallery(['https://shop.test/main.png', 'https://shop.test/v-small.png', 'https://shop.test/rule-a.png']);
	fixture.withFlexslider();
	sandbox.updateProductImage(fixture.doc, [ruleEvaluation('https://shop.test/rule-a.png', 'finish', 'gold')]);

	assert.deepEqual(fixture.navigations, [2], 'the slider owns the swap when it can move');
	assert.equal(fixture.slides[0].image.attrs.get('src'), 'https://shop.test/main.png');
});

test('clearing a rule restores the original slide when the theme thumbnail also carries flex-active-slide', () => {
	const fixture = buildGallery(['https://shop.test/main.png', 'https://shop.test/v-small.png', 'https://shop.test/v-large.png', 'https://shop.test/rule-a.png', 'https://shop.test/rule-b.png']);
	fixture.withFlexslider();
	const evaluation = ruleEvaluation('https://shop.test/rule-b.png', 'edge', 'xl');

	sandbox.updateProductImage(fixture.doc, [evaluation]);
	assert.deepEqual(fixture.navigations, [4]);

	evaluation.values = {};
	sandbox.updateProductImage(fixture.doc, [evaluation]);

	assert.deepEqual(fixture.navigations, [4, 0], 'the captured original slide index is navigated back to');
	assert.equal(fixture.slides[0].image.attrs.get('src'), 'https://shop.test/main.png');
});

test('a pass that changes nothing leaves the gallery image attributes untouched', () => {
	const fixture = buildGallery(['https://shop.test/main.png', 'https://shop.test/v-small.png', 'https://shop.test/rule-a.png']);

	sandbox.updateProductImage(fixture.doc, [{ definitions: {}, values: {}, rules: [] }]);
	assert.deepEqual(fixture.writes, [], 'Astra Pro observes src mutations and clicks its matching thumbnail');

	const evaluation = ruleEvaluation('https://shop.test/rule-a.png', 'finish', 'gold');
	sandbox.updateProductImage(fixture.doc, [evaluation]);
	assert.equal(fixture.slides[0].image.attrs.get('src'), 'https://shop.test/rule-a.png');

	evaluation.values = {};
	sandbox.updateProductImage(fixture.doc, [evaluation]);
	assert.equal(fixture.slides[0].image.attrs.get('src'), 'https://shop.test/main.png');
	assert.ok(fixture.writes.includes('src=https://shop.test/main.png'), 'a real change is still restored');
});
