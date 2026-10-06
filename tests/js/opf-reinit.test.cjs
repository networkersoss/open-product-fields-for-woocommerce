/**
 * Public re-initialisation entry (`window.OPF_FRONTEND`) for markup injected
 * after page load — the quick-view modal surface.
 *
 * What this pins:
 *  - `init( root )` scans the given subtree only, never the document;
 *  - a group injected without the page-level `window.OPF_FIELDS` entry reads
 *    its own `data-opf-registry` payload (the AJAX fragment's inline script is
 *    destroyed by themes that sanitize it) and drives conditionals + pricing
 *    from it;
 *  - `initTotals( root )` / `writeTotals( root )` write into that subtree's
 *    totals node, not the first one in the document;
 *  - re-runs stay idempotent and `opf:reinit` reaches the same entry point.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'opf-frontend.js'), 'utf8');

// The registry the server prints inside a group element. The page-level
// `window.OPF_FIELDS` global is deliberately absent from the context below.
const INLINE_REGISTRY = JSON.stringify({
	fields: {
		finish: {
			type: 'select',
			subtype: null,
			multiple: false,
			conditionals: [],
			choices: [
				{ slug: 'none', label: 'None', disabled: false, quantity: null, pricing: { type: 'none', amount: 0, formula: '', formula_raw: '', per_unit: false } },
				{ slug: 'gold', label: 'Gold', disabled: false, quantity: null, pricing: { type: 'fixed', amount: 15, formula: '', formula_raw: '', per_unit: false } },
			],
			pricing: { type: 'none', amount: 0, formula: '', formula_raw: '', per_unit: false },
		},
		note: {
			type: 'text',
			conditionals: [ { action: 'hide', logic: 'all', rules: [ { field: 'finish', operator: 'is', value: 'gold' } ] } ],
			choices: [],
		},
	},
	image_rules: [ { target_url: 'https://shop.test/rule-a.png', conditions: [ { field: 'finish', value: 'gold' } ] } ],
	image_rule_mode: 'rules',
});

const makeNode = ( { tag = 'div', attrs = {}, queries = {}, all = {} } = {} ) => {
	const attributes = { ...attrs };
	const classes = new Set();
	const node = {
		tagName: tag.toUpperCase(),
		dataset: {},
		hidden: false,
		disabled: false,
		checked: false,
		value: '',
		type: '',
		style: {},
		parentElement: null,
		listeners: {},
		getAttribute: ( name ) => ( name in attributes ? attributes[ name ] : null ),
		setAttribute: ( name, value ) => { attributes[ name ] = String( value ); if ( 'hidden' === name ) node.hidden = true; },
		hasAttribute: ( name ) => name in attributes,
		removeAttribute: ( name ) => { delete attributes[ name ]; if ( 'hidden' === name ) node.hidden = false; },
		toggleAttribute: ( name, enabled ) => { if ( enabled ) node.setAttribute( name, '' ); else node.removeAttribute( name ); },
		getAttributeNames: () => Object.keys( attributes ),
		classList: {
			toggle: ( name, enabled ) => {
				const on = undefined === enabled ? ! classes.has( name ) : !! enabled;
				if ( on ) classes.add( name ); else classes.delete( name );
				if ( 'opf-hide' === name || 'opf-field--hidden' === name ) node.hidden = on;
			},
			contains: ( name ) => classes.has( name ),
			add: ( name ) => classes.add( name ),
			remove: ( name ) => classes.delete( name ),
		},
		querySelector: ( selector ) => ( selector in queries ? queries[ selector ] : null ),
		querySelectorAll: ( selector ) => ( selector in all ? all[ selector ] : [] ),
		matches: () => false,
		closest: () => null,
		contains: () => true,
		addEventListener: ( type, handler ) => { node.listeners[ type ] = handler; },
		dispatchEvent: () => true,
		remove: () => {},
		focus: () => {},
	};
	return node;
};

// One injected group: `finish` is preselected to the priced option, `note`
// hides only when the inline definition of `finish` is actually read.
const makeGroup = () => {
	const select = makeNode( { tag: 'select', attrs: { name: 'opf[18][finish]' } } );
	select.value = 'gold';
	const noteInput = makeNode( { tag: 'input', attrs: { type: 'text', name: 'opf[18][note]' } } );
	const finishField = makeNode( {
		attrs: { 'data-opf-field': 'finish' },
		queries: {
			'input:checked': null,
			'input:not([type="hidden"]), textarea, select': select,
			'input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), textarea, select': select,
			'input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea, select': select,
		},
		all: { 'input, select, textarea': [ select ] },
	} );
	const noteField = makeNode( {
		attrs: { 'data-opf-field': 'note' },
		queries: {
			'input:checked': null,
			'input:not([type="hidden"]), textarea, select': noteInput,
			'input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), textarea, select': noteInput,
			'input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea, select': noteInput,
		},
		all: { 'input, select, textarea': [ noteInput ] },
	} );
	const fields = [ finishField, noteField ];
	const group = makeNode( {
		attrs: { 'data-opf-group': '18', 'data-opf-product-price': '100', 'data-opf-registry': INLINE_REGISTRY },
		queries: {
			'[data-opf-field="finish"]': finishField,
			'[data-opf-field="note"]': noteField,
			'[data-opf-field="finish"] input:checked': null,
			'[data-opf-field="note"] input:checked': null,
			'.opf-input:not([type="hidden"])': null,
		},
		all: {
			'[data-opf-field]': fields,
			'[data-opf-swap-image]': [],
			'():not()': [],
		},
	} );
	group.matches = ( selector ) => '[data-opf-group]' === selector;
	return group;
};

const makeTotals = () => {
	const nodes = {
		product: { innerHTML: '', textContent: '' },
		options: { innerHTML: '', textContent: '' },
		grand: { innerHTML: '', textContent: '' },
	};
	const totals = makeNode( {
		attrs: { 'data-product-price': '100' },
		queries: {
			'.opf-product-total, .wapf-product-total': nodes.product,
			'.opf-options-total, .wapf-options-total': nodes.options,
			'.opf-grand-total, .wapf-grand-total': nodes.grand,
		},
	} );
	return { node: totals, nodes };
};

/**
 * Module instance with a fake DOM. `root` holds the injected group and its
 * totals block; the document holds the decoys that must stay untouched.
 */
const load = () => {
	const group = makeGroup();
	const totals = makeTotals();
	const documentTotals = makeTotals();
	const documentListeners = {};
	const documentQueries = [];
	const rootQueries = [];
	const events = [];

	const root = makeNode( {
		all: { '[data-opf-group]': [ group ], 'form.cart input[name="quantity"], form.cart .qty': [] },
		queries: { '[data-opf-fields]': null },
	} );
	root.matches = ( selector ) => '[data-opf-fields]' === selector;
	root.querySelectorAll = ( selector ) => {
		rootQueries.push( selector );
		if ( '[data-opf-group]' === selector ) return [ group ];
		return [];
	};
	root.querySelector = ( selector ) => {
		if ( '[data-opf-fields]' === selector ) return root;
		if ( selector.includes( 'opf-product-totals' ) ) return totals.node;
		if ( selector.includes( 'form.cart' ) ) return null;
		return null;
	};

	const document = {
		readyState: 'loading',
		querySelector: ( selector ) => {
			documentQueries.push( selector );
			if ( selector.includes( 'opf-product-totals' ) ) return documentTotals.node;
			return null;
		},
		querySelectorAll: ( selector ) => {
			documentQueries.push( selector );
			return [];
		},
		addEventListener: ( type, handler ) => { ( documentListeners[ type ] = documentListeners[ type ] || [] ).push( handler ); },
		dispatchEvent: ( event ) => {
			events.push( event.type );
			( documentListeners[ event.type ] || [] ).forEach( ( handler ) => handler( event ) );
			return true;
		},
	};

	const context = {
		window: {
			OPF_FIELDS: undefined,
			OPF_IMAGE_RULES: undefined,
			opf_config: { product_base_price: 100 },
			setTimeout,
			clearTimeout,
		},
		document,
		console,
		setTimeout,
		clearTimeout,
		CustomEvent: class {
			constructor( type, init ) { this.type = type; this.detail = init && init.detail; }
		},
		fetch: undefined,
	};
	vm.createContext( context );
	vm.runInContext(
		`${source}\nglobalThis.__api = window.OPF_FRONTEND; globalThis.__init = init; globalThis.__initTotals = initTotals; globalThis.__writeTotals = writeTotals; globalThis.__groupImageRules = groupImageRules;`,
		context
	);

	return { context, group, root, totals, documentTotals, document, documentQueries, rootQueries, events };
};

test('the module publishes the documented public re-init entry point', () => {
	const { context, events } = load();

	assert.equal( typeof context.__api, 'object' );
	assert.equal( typeof context.__api.init, 'function' );
	assert.equal( typeof context.__api.initTotals, 'function' );
	assert.equal( typeof context.__api.reinit, 'function', 'reinit() is the quick-view entry point' );
	assert.ok( events.includes( 'opf:frontend-ready' ), 'the adapter queue is flushed once the API exists' );
});

test('init(root) initialises a detached subtree from its inline registry', () => {
	const { context, group, root, rootQueries } = load();
	const note = group.querySelector( '[data-opf-field="note"]' );
	assert.equal( note.hidden, false, 'the injected field starts visible' );

	context.__init( root );

	assert.equal( group.dataset.opfInitialized, '1', 'the injected group is initialised' );
	assert.equal(
		note.hidden,
		true,
		'the conditional from data-opf-registry hid the field — the page-level window.OPF_FIELDS is absent'
	);
	assert.ok(
		rootQueries.includes( '[data-opf-group]' ),
		'init( root ) scans the given root for field groups'
	);
});

test('a second init(root) leaves an already-initialised group alone', () => {
	const { context, group } = load();
	context.__init( group );
	const note = group.querySelector( '[data-opf-field="note"]' );
	note.hidden = false;

	context.__init( group );

	assert.equal( note.hidden, false, 'the data-opf-initialized guard keeps re-runs idempotent' );
});

test('writeTotals(root) prices the injected subtree from the inline registry', async () => {
	const { context, group, root, totals, documentTotals } = load();

	context.__init( group );
	context.__initTotals( root );
	await context.__writeTotals( root );

	assert.equal( totals.nodes.grand.innerHTML, '$115.00', 'base 100 + the inline-definition priced choice (+15)' );
	assert.equal( totals.nodes.options.innerHTML, '$15.00' );
	assert.equal( totals.nodes.product.innerHTML, '$100.00' );
	assert.equal( documentTotals.nodes.grand.innerHTML, '', 'a page-level totals node is not the injected subtree target' );
	assert.equal( root.dataset.opfTotalsInitialized, '1', 'the injected container is marked as bound' );
	assert.equal( typeof root.listeners.input, 'function', 're-init binds the injected container listeners' );
	assert.equal( typeof root.listeners.change, 'function' );
});

test('an opf:reinit event re-initialises the root it carries', () => {
	const { context, document } = load();
	const injected = makeGroup();
	injected.dataset = {};
	const note = injected.querySelector( '[data-opf-field="note"]' );

	document.dispatchEvent( { type: 'opf:reinit', detail: { root: injected } } );

	assert.equal( injected.dataset.opfInitialized, '1' );
	assert.equal( note.hidden, true, 'the event path reaches the same initialiser as the global' );
	assert.equal( context.__api, context.window.OPF_FRONTEND );
});

test('groupImageRules reads the inline payload when the page global is absent', () => {
	const { context, group } = load();

	const rules = context.__groupImageRules( group, '18' );

	assert.equal( rules.mode, 'rules' );
	// The payload crosses the vm realm boundary: compare the data, not the object identities.
	assert.deepEqual(
		JSON.parse( JSON.stringify( rules.rules ) ),
		[ { target_url: 'https://shop.test/rule-a.png', conditions: [ { field: 'finish', value: 'gold' } ] } ]
	);
});
