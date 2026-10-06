/**
 * Quick-view adapters (assets/js/opf-quick-view.js).
 *
 * One listener per supported surface, each resolving the modal root that the
 * integration actually creates, plus the queue that holds a root until the
 * frontend module (a deferred script module) publishes `window.OPF_FRONTEND`.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'opf-quick-view.js' ), 'utf8');

/**
 * Adapter instance with a fake document, a fake jQuery that records its
 * delegated bindings, and the roots the selectors should resolve to.
 */
const load = ( { nodes = {}, api = undefined, jquery = true } = {} ) => {
	const bindings = {};
	const native = {};
	const calls = [];

	const document = {
		querySelector: ( selector ) => nodes[ selector ] || null,
		addEventListener: ( type, handler ) => { native[ type ] = handler; },
	};

	const $ = ( target ) => {
		const chain = {
			on: ( type, handler ) => {
				bindings[ type ] = { handler, target };
				return chain;
			},
		};
		return chain;
	};

	const window = { OPF_FRONTEND: api };
	if ( jquery ) window.jQuery = $;

	const context = {
		window,
		document,
		console,
	};
	vm.createContext( context );
	vm.runInContext( source, context );

	return { window, bindings, native, calls, document };
};

const recordingApi = ( calls ) => ( { reinit: ( root ) => calls.push( root ) } );

test('every supported quick view is wired to its own event', () => {
	const { bindings, native, document } = load();

	assert.ok( bindings[ 'quick_view_pro:load' ], 'Barn2 Quick View Pro triggers quick_view_pro:load' );
	assert.ok( bindings.mfpOpen, 'Flatsome/Magnific Popup triggers mfpOpen' );
	assert.ok( bindings[ 'woodmart-quick-view-displayed' ], 'Woodmart triggers woodmart-quick-view-displayed' );
	assert.ok( native.ast_quick_view_loader_stop, 'Astra Pro dispatches a native document event' );
	assert.ok( native[ 'opf:frontend-ready' ], 'the module signals when its API exists' );
	assert.equal( bindings[ 'quick_view_pro:load' ].target, document, 'all jQuery handlers are delegated from document' );
});

test('a quick view event re-initialises the root the integration creates', () => {
	const barn2Modal = { id: 'quick-view-15' };
	const astraProduct = { id: 'ast-quick-view-product' };
	const flatsomeLightbox = { id: 'product-lightbox' };
	const woodmartPopup = { id: 'product-quick-view' };
	const calls = [];
	const { bindings, native } = load( {
		api: recordingApi( calls ),
		nodes: {
			'#ast-quick-view-modal .product': astraProduct,
			'.product-lightbox': flatsomeLightbox,
			'.product-quick-view': woodmartPopup,
		},
	} );

	// Barn2 hands the modal element over as the second handler argument.
	bindings[ 'quick_view_pro:load' ].handler( {}, [ barn2Modal ] );
	assert.equal( calls.pop(), barn2Modal );

	// Barn2's own wrapper is the fallback when no element is passed.
	const barn2Wrapper = { id: 'wc-quick-view-product' };
	const withWrapper = load( {
		api: recordingApi( calls ),
		nodes: { '.jquery-modal #quick-view': barn2Wrapper },
	} );
	withWrapper.bindings[ 'quick_view_pro:load' ].handler( {} );
	assert.equal( calls.pop(), barn2Wrapper );

	native.ast_quick_view_loader_stop();
	assert.equal( calls.pop(), astraProduct, 'Astra Pro injects into #ast-quick-view-modal' );

	bindings.mfpOpen.handler();
	assert.equal( calls.pop(), flatsomeLightbox );

	bindings[ 'woodmart-quick-view-displayed' ].handler();
	assert.equal( calls.pop(), woodmartPopup );
});

test('a modal root is queued until the frontend module publishes its API', () => {
	const modal = { id: 'quick-view-15' };
	const product = { id: 'ast-quick-view-product' };
	const calls = [];
	const { window, bindings, native, document } = load( {
		nodes: { '#ast-quick-view-modal .product': product, '.product-quick-view': modal },
	} );

	native.ast_quick_view_loader_stop();
	bindings[ 'woodmart-quick-view-displayed' ].handler();
	assert.equal( calls.length, 0, 'nothing to call while the module is still loading' );

	window.OPF_FRONTEND = recordingApi( calls );
	native[ 'opf:frontend-ready' ]();

	assert.deepEqual( calls, [ product, modal ], 'each queued root is flushed once, in order' );

	bindings.mfpOpen.handler();
	assert.equal( calls.length, 3, 'later events go straight to the API' );

	// A theme that renamed its modal wrapper still gets the document as the
	// scan root, which is what makes a re-init safe rather than a no-op.
	bindings.mfpOpen.handler();
	assert.equal( calls[ calls.length - 1 ], document );
});

test('the adapter loads without jQuery and still wires the native Astra event', () => {
	const product = { id: 'ast-quick-view-product' };
	const calls = [];
	const { window, native } = load( {
		jquery: false,
		api: recordingApi( calls ),
		nodes: { '#ast-quick-view-modal .product': product },
	} );

	assert.equal( window.jQuery, undefined );
	assert.ok( native.ast_quick_view_loader_stop );
	native.ast_quick_view_loader_stop();
	assert.deepEqual( calls, [ product ] );
});
