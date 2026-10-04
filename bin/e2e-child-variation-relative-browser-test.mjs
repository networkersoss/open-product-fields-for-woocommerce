// Real Woo/Chromium proof for a linked variable child and relative child qty.
import fs from 'node:fs';
import { createRequire } from 'node:module';

const playwrightRoot = process.env.OPF_PLAYWRIGHT_ROOT || '/home/followersya-5hqi7/followersya.com/node_modules';
const require = createRequire( `${ playwrightRoot }/index.js` );
const { chromium } = require( 'playwright' );
const base = process.env.OPF_CHILD_VARIATION_BASE_URL || 'http://127.0.0.1:8463';
const dir = process.env.OPF_CHILD_VARIATION_ARTIFACT_DIR || '/tmp/opf-child-variation-relative-evidence';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback disposable clone required.' );
const state = JSON.parse( fs.readFileSync( dir + '/state.json' ) );
const checks = [];
const errors = [];
const check = ( label, pass ) => {
	checks.push( { label, pass: !! pass } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ label }` );
	if ( ! pass ) throw new Error( label );
};

const browser = await chromium.launch();
try {
	const context = await browser.newContext();
	const page = await context.newPage();
	page.on( 'pageerror', ( error ) => errors.push( error.message ) );
	await page.goto( `${ base }/product/opf-variation-relative-parent/`, { waitUntil: 'domcontentloaded' } );
	const variation = page.locator( '.opf-products--card input.opf-product-input[value="variable-red"]' );
	check( 'linked field renders the selected variable child choice', await variation.count() === 1 );
	check( 'choice references the exact Woo variation ID', Number( await variation.getAttribute( 'data-opf-product-id' ) ) === state.variation );
	await page.locator( 'form.cart input.qty' ).fill( '2' );
	await variation.check();
	await page.locator( '.opf-products--card-qty input.opf-qty[data-choice-slug="relative-child"]' ).fill( '2' );
	await page.locator( 'form.cart button.single_add_to_cart_button' ).click();
	await page.locator( '.wc-block-components-notice-banner.is-success, .woocommerce-message' ).first().waitFor( { timeout: 15000 } );

	let response = await context.request.get( `${ base }/wp-json/wc/store/v1/cart` );
	let cart = await response.json();
	let nonce = response.headers().nonce;
	check( 'add-to-cart creates parent plus both linked child lines', cart.items.length === 3 );
	const parent = cart.items.find( ( item ) => ! item.extensions?.opf?.childItem );
	const variationLine = cart.items.find( ( item ) => item.id === state.variation );
	let relativeLine = cart.items.find( ( item ) => item.id === state.relative );
	check( 'variable child cart line keeps its variation attribute and parent-scaled quantity',
		!! variationLine && variationLine.quantity === 2 && variationLine.variation?.some( ( attr ) => attr.raw_attribute === 'attribute_color' && attr.value === 'red' ) );
	check( 'relative child begins at selected quantity 2', relativeLine?.quantity === 2 );

	const updateParent = async ( quantity ) => {
		const updated = await context.request.post( `${ base }/wp-json/wc/store/v1/cart/update-item`, {
			headers: { Nonce: nonce }, data: { key: parent.key, quantity },
		} );
		const result = await updated.json();
		if ( ! updated.ok() ) throw new Error( `Parent quantity update failed: ${ JSON.stringify( result ) }` );
		nonce = updated.headers().nonce || nonce;
		return result;
	};

	cart = await updateParent( 3 );
	relativeLine = cart.items.find( ( item ) => item.id === state.relative );
	check( 'relative increase 2→3 follows WAPF 3.1.5 delta rule: child 2→4', relativeLine?.quantity === 4 );
	cart = await updateParent( 4 );
	relativeLine = cart.items.find( ( item ) => item.id === state.relative );
	check( 'relative increase 3→4 follows WAPF 3.1.5 delta rule: child 4→8', relativeLine?.quantity === 8 );
	cart = await updateParent( 2 );
	relativeLine = cart.items.find( ( item ) => item.id === state.relative );
	check( 'relative decrease 4→2 follows WAPF 3.1.5 ratio rule: child 8→4', relativeLine?.quantity === 4 );
	check( 'no uncaught browser errors', errors.length === 0 );
	fs.writeFileSync( dir + '/browser-results.json', JSON.stringify( { base, checks, errors }, null, 2 ) );
	console.log( 'SUCCESS child variation and relative quantity browser proof' );
} finally {
	if ( checks.some( ( item ) => ! item.pass ) ) fs.writeFileSync( dir + '/browser-results.json', JSON.stringify( { base, checks, errors }, null, 2 ) );
	await browser.close();
}
