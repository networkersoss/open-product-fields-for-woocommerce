const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

// WAPF-ADMIN-DUPLICATE-FIELD: the builder's "Duplicate field" control has to
// insert a deep copy immediately after its source and hand it a collision-free
// id, so two clicks on the same source yield two distinct siblings.

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

function findElements(root, predicate, matches = []) {
	if (predicate(root)) matches.push(root);
	for (const child of root.children || []) findElements(child, predicate, matches);
	return matches;
}

function boot(model) {
	const nodesById = {};
	const mount = element('div', nodesById);
	mount.dataset = {
		postId: '12',
		model: JSON.stringify(model),
		nonce: 'fixture-nonce',
		rest: '/wp-json/opf/v1/groups',
		previewRest: '/wp-json/opf/v1/preview',
	};
	nodesById['opf-builder-app'] = mount;
	nodesById.title = { value: 'Duplicate group' };
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
	return { mount, nodesById, getRequest: () => request };
}

const duplicateButtons = (nodesById) =>
	findElements(nodesById['opf-builder-fields'], (node) => typeof node.className === 'string' && node.className.split(' ').includes('opf-b-duplicate-field'));

const labelInputs = (nodesById) =>
	findElements(nodesById['opf-builder-fields'], (node) => node.attributes && node.attributes.placeholder === 'Field label');

const save = async (mount, getRequest) => {
	const saveButton = mount.children[0].children.find((child) => child.textContent === 'Save');
	assert.ok(saveButton, 'builder should render a Save button');
	saveButton.listeners.click();
	await new Promise((resolve) => setImmediate(resolve));
	assert.equal(getRequest().url, '/wp-json/opf/v1/groups');
	return JSON.parse(getRequest().options.body).data.fields;
};

test('duplicate field inserts a deep copy after its source with a collision-free id', async () => {
	const { mount, nodesById, getRequest } = boot({
		fields: [
			{ id: 'note', label: 'Note', type: 'text', choices: [], pricing: { type: 'none', amount: 0 } },
			{
				id: 'prints', label: 'Prints', type: 'select',
				choices: [
					{ slug: 'small', label: 'Small', pricing: { type: 'fixed', amount: 2 } },
					{ slug: 'large', label: 'Large', pricing: { type: 'none', amount: 0 } },
				],
				pricing: { type: 'fixed', amount: 1.5 },
				conditionals: [{ field: 'note', operator: 'is', value: 'yes' }],
				min: 1,
				max: 4,
			},
			// A field already carrying the default copy id: the first duplicate
			// has to skip it instead of colliding.
			{ id: 'prints-copy', label: 'Earlier copy', type: 'text', choices: [], pricing: { type: 'none', amount: 0 } },
			{ id: 'tail', label: 'Tail', type: 'text', choices: [], pricing: { type: 'none', amount: 0 } },
		],
		rule_groups: [],
	});

	assert.equal(duplicateButtons(nodesById).length, 4, 'each field card renders one duplicate control');

	duplicateButtons(nodesById)[1].listeners.click();
	duplicateButtons(nodesById)[1].listeners.click();

	const fields = await save(mount, getRequest);
	assert.deepEqual(
		fields.map((field) => field.id),
		['note', 'prints', 'prints-copy-3', 'prints-copy-2', 'prints-copy', 'tail'],
		'both copies belong to the source and each takes an unused id'
	);

	const sourceField = fields[1];
	const firstCopy = fields[3];
	const secondCopy = fields[2];
	assert.equal(firstCopy.id, 'prints-copy-2', 'first copy skips the taken prints-copy id');
	assert.equal(secondCopy.id, 'prints-copy-3', 'the second copy does not reuse the first copy id');
	assert.deepEqual(firstCopy.choices, sourceField.choices, 'choices survive the duplication');
	assert.deepEqual(firstCopy.pricing, { type: 'fixed', amount: 1.5 }, 'pricing survives the duplication');
	assert.deepEqual(firstCopy.conditionals, [{ field: 'note', operator: 'is', value: 'yes' }], 'condition references survive the duplication');
	assert.equal(firstCopy.min, 1);
	assert.equal(firstCopy.max, 4);
});

test('duplicate field copies stay independent of their source', async () => {
	const { mount, nodesById, getRequest } = boot({
		fields: [
			{
				id: 'finish', label: 'Finish', type: 'select',
				choices: [{ slug: 'linen', label: 'Linen', pricing: { type: 'fixed', amount: 1 } }],
				pricing: { type: 'fixed', amount: 1 },
			},
		],
		rule_groups: [],
	});

	duplicateButtons(nodesById)[0].listeners.click();

	const labels = labelInputs(nodesById);
	assert.equal(labels.length, 2, 'source and copy each render a label editor');
	labels[0].value = 'Finish (edited)';
	labels[0].listeners.input({ target: labels[0] });

	const fields = await save(mount, getRequest);
	assert.deepEqual(fields.map((field) => field.id), ['finish', 'finish-copy']);
	assert.equal(fields[0].label, 'Finish (edited)');
	assert.equal(fields[1].label, 'Finish', 'editing the source leaves the copy untouched (deep copy, not a shared reference)');
	assert.deepEqual(fields[1].choices, [{ slug: 'linen', label: 'Linen', pricing: { type: 'fixed', amount: 1 } }]);
});
