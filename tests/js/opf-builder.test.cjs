const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../assets/js/opf-builder.js'),
	'utf8'
);

function element(tagName, nodesById) {
	const listeners = {};
	return {
		tagName,
		attributes: {},
		children: [],
		classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
		addEventListener(name, callback) { listeners[name] = callback; },
		appendChild(child) { this.children.push(child); return child; },
		setAttribute(name, value) {
			this.attributes[name] = String(value);
			if (name === 'id') nodesById[value] = this;
		},
		listeners,
	};
}

function findElement(root, predicate) {
	if (predicate(root)) return root;
	for (const child of root.children || []) {
		const match = findElement(child, predicate);
		if (match) return match;
	}
	return null;
}

function findElements(root, predicate, matches = []) {
	if (predicate(root)) matches.push(root);
	for (const child of root.children || []) findElements(child, predicate, matches);
	return matches;
}

test('builder saves selected product-attribute terms through the real save handler', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({
		fields: [
			{ id: 'finish_note', label: 'Finish note', description: 'Use up to 12 characters.', type: 'text', weight_formula: '[field.finish_note] * 1', choices: [], pricing: { type: 'none', amount: 0 } },
			{ id: 'prints', label: 'Prints', type: 'swatch', image_quantities: true, min: 1, max: 5, step: 1, min_selections: 1, max_selections: 2, min_total_quantity: 2, max_total_quantity: 6, choices: [{ slug: 'small', label: 'Small', image: '/small.jpg', pricing: { type: 'fixed', amount: 2 } }] },
			{ id: 'count', label: 'Count', type: 'number', number_mode: 'integer', min: 1, step: 1, choices: [], pricing: { type: 'none', amount: 0 } },
			{ id: 'legacy_count', label: 'Legacy count', type: 'number', choices: [], pricing: { type: 'none', amount: 0 } },
			{ id: 'new_count', label: 'New count', type: 'text', choices: [], pricing: { type: 'none', amount: 0 } },
			{ id: 'artwork', label: 'Artwork', type: 'upload', min_files: 2, max_files: -1, min_size_mb: 0.25, min_width: 640, min_height: 480, auto_resize: true, max_width: 1600, max_height: 1200, image_editor_mode: 'forced', image_editor_crop: true, image_editor_resize: false, image_editor_rotate: true, image_editor_flip: false, image_editor_aspect_ratio: '4:3', choices: [], pricing: { type: 'none', amount: 0 } },
		],
			rule_groups: [{ rules: [{ subject: 'product_attribute', operator: 'in', terms: ['pa_finish:7'] }] }],
			lookup_tables: { blinds: [['200', '100', 70], ['220', '160', 78]] },
			formula_variables: { wrap_cost: { default: 1, changes: [{ value: 1.5, logic: 'all', rules: [{ field: 'size', operator: 'is', value: 'large' }] }] } },
		}),
		nonce: 'fixture-nonce',
		rest: '/wp-json/opf/v1/groups',
		previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Attribute group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = {
		options: [
			{ value: 'pa_finish:7', checked: false },
			{ value: 'pa_finish:8', checked: true },
		],
	};

	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll(selector) {
			const key = {
				'#opf-placement-cats option:checked': 'opf-placement-cats',
				'#opf-placement-tags option:checked': 'opf-placement-tags',
				'#opf-placement-attributes option:checked': 'opf-placement-attributes',
			}[selector];
			return key ? nodesById[key].options.filter((option) => option.checked) : [];
		},
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = {
		fetch(url, options) {
			request = { url, options };
			return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) });
		},
	};
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });

	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	assert.ok(saveButton, 'toolbar should render a Save button');
	const instructionPresentation = findElement(mount, (node) => node.attributes && node.attributes.title === 'Instruction presentation');
	assert.ok(instructionPresentation, 'builder should expose instruction presentation');
	instructionPresentation.value = 'tooltip';
	instructionPresentation.listeners.change();
	const weightFormula = findElement(mount, (node) => node.attributes && node.attributes.title === 'Extra product weight formula');
	assert.ok(weightFormula, 'builder should expose an extra product weight formula');
	weightFormula.value = '[field.finish_note] * 1.5';
	weightFormula.listeners.input({ target: weightFormula });
	const numberMode = findElement(mount, (node) => node.attributes && node.attributes['data-opf-number-mode'] === '1');
	assert.ok(numberMode, 'builder should expose whole-number and decimal modes');
	numberMode.value = 'decimal';
	numberMode.listeners.change();
	const numberDisplay = findElement(mount, (node) => node.attributes && node.attributes['data-opf-number-display'] === '1');
	assert.ok(numberDisplay, 'builder should expose the per-field number stepper choice');
	numberDisplay.value = 'plus_min';
	numberDisplay.listeners.change();
	const typeSelectors = findElements(mount, (node) => node.tagName === 'select' && node.attributes && node.attributes.title === 'Field type');
	typeSelectors[4].value = 'number';
	typeSelectors[4].listeners.change();
	const maxFilesInput = findElement(mount, (node) => node.attributes && node.attributes.placeholder === 'Maximum files (-1 for unlimited)');
	assert.ok(maxFilesInput, 'builder should explain unlimited file count sentinel');
	maxFilesInput.value = '-1';
	maxFilesInput.listeners.input({ target: maxFilesInput });
	const variableEditor = nodesById['opf-b-formula-variables'];
	assert.ok(variableEditor, 'builder should render the formula variable editor');
	variableEditor.value = JSON.stringify({ wrap_cost: { default: 1, changes: [{ value: 1.75, logic: 'all', rules: [{ field: 'size', operator: 'is', value: 'large' }] }] } });
	variableEditor.listeners.input({ target: variableEditor });
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(request.url, '/wp-json/opf/v1/groups');
	const body = JSON.parse(request.options.body);
	assert.deepEqual(body.data.rule_groups, [
		{ rules: [{ subject: 'product_attribute', operator: 'in', terms: ['pa_finish:8'] }] },
	]);
	assert.deepEqual(body.data.lookup_tables, { blinds: [['200', '100', 70], ['220', '160', 78]] });
	assert.deepEqual(body.data.formula_variables, { wrap_cost: { default: 1, changes: [{ value: 1.75, logic: 'all', rules: [{ field: 'size', operator: 'is', value: 'large' }] }] } });
	assert.deepEqual(body.data.fields[1], {
		id: 'prints', label: 'Prints', type: 'swatch', image_quantities: true,
		min: 1, max: 5, step: 1, min_selections: 1, max_selections: 2,
		min_total_quantity: 2, max_total_quantity: 6,
		choices: [{ slug: 'small', label: 'Small', image: '/small.jpg', pricing: { type: 'fixed', amount: 2 } }],
	});
	assert.equal(body.data.fields[2].number_mode, 'decimal');
	assert.equal(body.data.fields[2].display, 'plus_min', 'per-field stepper choice round-trips through save');
	assert.equal(body.data.fields[3].number_mode, undefined, 'legacy number mode remains unset unless changed');
	assert.equal(body.data.fields[4].number_mode, 'integer', 'new number fields default to whole-number behavior');
	assert.equal(body.data.fields[5].min_files, 2);
	assert.equal(body.data.fields[5].max_files, '-1');
	assert.equal(body.data.fields[5].min_size_mb, 0.25);
	assert.equal(body.data.fields[5].min_width, 640);
	assert.equal(body.data.fields[5].min_height, 480);
	assert.equal(body.data.fields[5].auto_resize, true);
	assert.equal(body.data.fields[5].max_width, 1600);
	assert.equal(body.data.fields[5].max_height, 1200);
	assert.equal(body.data.fields[5].image_editor_mode, 'forced');
	assert.equal(body.data.fields[5].image_editor_crop, true);
	assert.equal(body.data.fields[5].image_editor_resize, false);
	assert.equal(body.data.fields[5].image_editor_rotate, true);
	assert.equal(body.data.fields[5].image_editor_flip, false);
	assert.equal(body.data.fields[5].image_editor_aspect_ratio, '4:3');
	assert.equal(body.data.fields[0].description_presentation, 'tooltip');
	assert.equal(body.data.fields[0].weight_formula, '[field.finish_note] * 1.5');
});

test('builder save keeps hundreds of fields and choices without truncation', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	const fields = Array.from({ length: 512 }, (_, index) => ({
		id: `field-${index}`, label: `Field ${index}`, type: 'text', description: '', choices: [],
		pricing: { type: 'none', amount: 0 },
	}));
	fields[0].type = 'select';
	fields[0].choices = Array.from({ length: 512 }, (_, index) => ({ slug: `choice-${index}`, label: `Choice ${index}`, pricing: { type: 'none', amount: 0 } }));
	mount.dataset = { postId: '12', model: JSON.stringify({ fields, rule_groups: [] }), nonce: 'fixture', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview' };
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Large option group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	let request;
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields.length, 512);
	assert.equal(body.data.fields[511].id, 'field-511');
	assert.equal(body.data.fields[0].choices.length, 512);
	assert.equal(body.data.fields[0].choices[511].slug, 'choice-511');
});

test('builder bulk-imports choice labels, values, and optional fixed prices', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12', model: JSON.stringify({ fields: [ {
			id: 'size', label: 'Size', type: 'select', choices: [ { slug: 'existing', label: 'Existing', pricing: { type: 'none', amount: 0 } } ],
	} ], rule_groups: [] }), nonce: 'fixture', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Imported options' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	let request;
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const bulkInput = findElement(mount, (node) => node.attributes && node.attributes.title === 'Bulk choice options');
	const importButton = findElement(mount, (node) => node.textContent === 'Import choices');
	assert.ok(bulkInput, 'builder should provide a bulk option input');
	assert.ok(importButton, 'builder should provide an import action');
	bulkInput.value = 'Small\nLarge\tL\t2.5\n\n';
	importButton.listeners.click();
	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.deepEqual(body.data.fields[0].choices, [
		{ slug: 'existing', label: 'Existing', pricing: { type: 'none', amount: 0 } },
		{ slug: 'small', label: 'Small', selected: false, disabled: false, pricing: { type: 'none', amount: 0, formula: '' } },
		{ slug: 'L', label: 'Large', selected: false, disabled: false, pricing: { type: 'fixed', amount: 2.5, formula: '' } },
	]);
});

test('builder rejects malformed bulk prices without partially changing choices', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12', model: JSON.stringify({ fields: [ {
			id: 'size', label: 'Size', type: 'checkbox', choices: [ { slug: 'existing', label: 'Existing', pricing: { type: 'none', amount: 0 } } ],
	} ], rule_groups: [] }), nonce: 'fixture', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Imported options' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	let request;
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const bulkInput = findElement(mount, (node) => node.attributes && node.attributes.title === 'Bulk choice options');
	const importButton = findElement(mount, (node) => node.textContent === 'Import choices');
	bulkInput.value = 'New option\tnew\nBad price\tbad\tn/a';
	importButton.listeners.click();
	const bulkStatus = findElement(mount, (node) => node.attributes && node.attributes['data-opf-bulk-choice-status'] === '1');
	assert.match(bulkStatus.textContent, /line 2/i);
	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	assert.deepEqual(JSON.parse(request.options.body).data.fields[0].choices, [
		{ slug: 'existing', label: 'Existing', pricing: { type: 'none', amount: 0 } },
	]);
});

test('builder saves disabled choices through the real save handler', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({
			fields: [
				{
					id: 'delivery', label: 'Delivery', type: 'select',
					choices: [
						{ slug: 'standard', label: 'Standard', selected: false, disabled: false, pricing: { type: 'none', amount: 0 } },
						{ slug: 'unavailable', label: 'Unavailable', selected: false, disabled: false, pricing: { type: 'none', amount: 0 } },
					],
				},
			],
		}),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Choice group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };

	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = {
		fetch(url, options) {
			request = { url, options };
			return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) });
		},
	};
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });

	const findByTitle = (node, title) => {
		if (node?.attributes?.title === title) return node;
		for (const child of node?.children || []) {
			const match = findByTitle(child, title);
			if (match) return match;
		}
		return null;
	};
	const disabledToggle = findByTitle(mount, 'Unavailable option: Unavailable');
	assert.ok(disabledToggle, 'choice editor should expose an unavailable toggle');
	disabledToggle.checked = true;
	disabledToggle.listeners.change({ target: disabledToggle });
	const repeatToggle = findByTitle(mount, 'Allow repeated rows');
	assert.ok(repeatToggle, 'builder should expose button-repeat control for supported field types');
	repeatToggle.checked = true;
	repeatToggle.listeners.change({ target: repeatToggle });
	const findRepeatMode = (node) => {
		if (node?.attributes?.['data-opf-repeat-mode'] === '1') return node;
		for (const child of node?.children || []) {
			const match = findRepeatMode(child);
			if (match) return match;
		}
		return null;
	};
	const repeatMode = findRepeatMode(mount);
	assert.ok(repeatMode, 'builder should expose the repeater mode selector');
	repeatMode.value = 'quantity';
	repeatMode.listeners.change({ target: repeatMode });

	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	assert.ok(saveButton, 'toolbar should render a Save button');
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));

	assert.equal(request.url, '/wp-json/opf/v1/groups');
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].choices[0].disabled, false);
	assert.equal(body.data.fields[0].choices[1].disabled, true);
	assert.deepEqual(body.data.fields[0].repeat, { enabled: true, mode: 'quantity' });
});

test('builder saves radio card orientation and editable choice presentation', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [{ id: 'finish', label: 'Finish', type: 'radio', choices: [{ slug: 'linen', label: 'Linen', pricing: { type: 'none', amount: 0 } }] }] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Card group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });

	const find = (node, predicate) => {
		if (predicate(node)) return node;
		for (const child of node?.children || []) {
			const match = find(child, predicate);
			if (match) return match;
		}
		return null;
	};
	const layout = find(mount, (node) => node?.attributes?.['data-opf-card-layout'] === '1');
	assert.ok(layout, 'radio field should expose the card layout selector');
	layout.value = 'horizontal';
	layout.listeners.change({ target: layout });
	const image = find(mount, (node) => node?.attributes?.placeholder === 'Card image URL (optional)');
	const description = find(mount, (node) => node?.attributes?.placeholder === 'Card description (optional)');
	assert.ok(image && description, 'card choices should expose optional image and description editors');
	image.value = '/uploads/linen.jpg';
	image.listeners.input({ target: image });
	description.value = 'Soft woven finish';
	description.listeners.input({ target: description });
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].card_layout, 'horizontal');
	assert.equal(body.data.fields[0].choices[0].image, '/uploads/linen.jpg');
	assert.equal(body.data.fields[0].choices[0].description, 'Soft woven finish');
});

test('builder saves optional hover/focus zoom for regular image swatches', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [{ id: 'fabric', label: 'Fabric', type: 'swatch', choices: [{ slug: 'linen', label: 'Linen', image: '/linen.jpg', pricing: { type: 'none', amount: 0 } }] }] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Image swatch zoom' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const zoom = findElement(mount, (node) => node.attributes?.title === 'Enlarge image swatches on hover or keyboard focus');
	assert.ok(zoom, 'builder exposes zoom only for image swatches');
	zoom.checked = true;
	zoom.listeners.change();
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].image_zoom, true);
});

test('builder saves switch presentation for true-false and checkbox fields', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [
			{ id: 'enabled', label: 'Enabled', type: 'toggle', choices: [] },
			{ id: 'extras', label: 'Extras', type: 'checkbox', choices: [{ slug: 'gift', label: 'Gift wrap', pricing: { type: 'none', amount: 0 } }] },
		] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Switch options' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; }, querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); }, createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const controls = findElements(mount, (node) => node.attributes?.title === 'Display as switches');
	assert.equal(controls.length, 2, 'builder exposes this only for true-false and checkbox fields');
	controls.forEach((control) => { control.checked = true; control.listeners.change(); });
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].switch_control, true);
	assert.equal(body.data.fields[1].switch_control, true);
});

test('builder saves a bounded checkbox column count', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [{ id: 'extras', label: 'Extras', type: 'checkbox', choices: [{ slug: 'gift', label: 'Gift wrap', pricing: { type: 'none', amount: 0 } }] }] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Checkbox columns' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; }, querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); }, createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const columns = findElement(mount, (node) => node.attributes?.title === 'Checkbox columns');
	assert.ok(columns, 'builder exposes the column count for checkbox fields');
	assert.equal(columns.attributes.min, '1');
	assert.equal(columns.attributes.max, '12');
	columns.value = '3';
	columns.listeners.input({ target: columns });
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].columns, 3);
});

test('builder saves a separate hover/focus zoom setting for image+quantity choices', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [{ id: 'prints', label: 'Prints', type: 'swatch', image_quantities: true, choices: [{ slug: 'small', label: 'Small', image: '/small.jpg', pricing: { type: 'none', amount: 0 } }] }] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Image quantity zoom' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const zoom = findElement(mount, (node) => node.attributes?.title === 'Enlarge image+quantity choices on hover or keyboard focus');
	assert.ok(zoom, 'builder exposes the separate image+quantity zoom setting');
	zoom.checked = true;
	zoom.listeners.change();
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].image_quantity_zoom, true);
	assert.equal(body.data.fields[0].image_zoom, undefined);
});

test('builder saves conditional product image targets and Any combination conditions', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({
			fields: [
				{ id: 'color', label: 'Color', type: 'select', choices: [{ slug: 'red', label: 'Red', pricing: { type: 'none', amount: 0 } }] },
				{ id: 'size', label: 'Size', type: 'radio', choices: [{ slug: 'large', label: 'Large', pricing: { type: 'none', amount: 0 } }] },
			],
			image_rules: [{ target_url: 'https://shop.test/red.jpg', conditions: [{ field: 'color', value: 'red' }, { field: 'size', value: '*' }] }],
		}),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Image rules group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const find = (node, predicate) => {
		if (predicate(node)) return node;
		for (const child of node?.children || []) {
			const match = find(child, predicate);
			if (match) return match;
		}
		return null;
	};
	const target = find(mount, (node) => node?.attributes?.placeholder === 'Gallery or external image URL');
	assert.ok(target, 'image target URL is editable independently from choice images');
	target.value = 'https://cdn.test/red-large.jpg';
	target.listeners.input({ target });
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.deepEqual(body.data.image_rules, [{ target_url: 'https://cdn.test/red-large.jpg', conditions: [{ field: 'color', value: 'red' }, { field: 'size', value: '*' }] }]);
});

test('builder saves past/future date policies and cutoff through the real save handler', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [{ id: 'delivery_date', type: 'date', label: 'Delivery date', cutoff_time: '', choices: [] }] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Date group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const findByType = (node, type) => {
		if (node?.tagName === 'input' && node.attributes?.type === type) return node;
		for (const child of node?.children || []) {
			const match = findByType(child, type);
			if (match) return match;
		}
		return null;
	};
	const cutoff = findByType(mount, 'time');
	assert.ok(cutoff, 'date settings should expose a time input for the same-day cutoff');
	cutoff.value = '14:30';
	cutoff.listeners.input({ target: cutoff });
	const findPolicy = (node, key) => {
		if (node?.attributes?.['data-opf-date-policy'] === key) return node;
		for (const child of node?.children || []) {
			const found = findPolicy(child, key);
			if (found) return found;
		}
		return null;
	};
	const allowPast = findPolicy(mount, 'allow_past');
	const allowFuture = findPolicy(mount, 'allow_future');
	assert.ok(allowPast && allowFuture, 'date settings should expose both date-selection policies');
	allowPast.checked = false;
	allowPast.listeners.change({ target: allowPast });
	allowFuture.checked = false;
	allowFuture.listeners.change({ target: allowFuture });
	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	assert.ok(saveButton, 'toolbar should render a Save button');
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].cutoff_time, '14:30');
	assert.equal(body.data.fields[0].allow_past, false);
	assert.equal(body.data.fields[0].allow_future, false);
});

test('builder saves per-surface value visibility independently', async () => {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify({ fields: [{ id: 'gift_note', type: 'text', label: 'Gift note', choices: [] }] }),
		nonce: 'fixture-nonce', rest: '/wp-json/opf/v1/groups', previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Visibility group' };
	nodesById['opf-placement-cats'] = { options: [] };
	nodesById['opf-placement-tags'] = { options: [] };
	nodesById['opf-placement-attributes'] = { options: [] };
	let request;
	const document = {
		getElementById(id) { return nodesById[id] || null; },
		querySelectorAll() { return []; },
		createElement(tagName) { return element(tagName, nodesById); },
		createTextNode(text) { return { textContent: text }; },
	};
	const window = { fetch(url, options) { request = { url, options }; return Promise.resolve({ json: () => Promise.resolve({ id: 12 }) }); } };
	vm.runInNewContext(source, { document, window, JSON, parseInt, String, Array, Object });
	const find = (node, key) => {
		if (node?.attributes?.['data-opf-visibility'] === key) return node;
		for (const child of node?.children || []) {
			const match = find(child, key);
			if (match) return match;
		}
		return null;
	};
	for (const key of ['hide_cart', 'hide_checkout', 'hide_order']) {
		const input = find(mount, key);
		assert.ok(input, `builder exposes ${key}`);
		input.checked = key !== 'hide_order';
		input.listeners.change();
	}
	const save = mount.children[0].children.find((child) => child.textContent === 'Save');
	save.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	const body = JSON.parse(request.options.body);
	assert.equal(body.data.fields[0].hide_cart, true);
	assert.equal(body.data.fields[0].hide_checkout, true);
	assert.equal(body.data.fields[0].hide_order, undefined);
});
