import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire( process.env.OPF_PLAYWRIGHT_ROOT + '/index.js' );
const { chromium } = require( 'playwright' );
const base = 'http://127.0.0.1:8423';
const dir = '/tmp/opf-category-price-evidence';
const state = JSON.parse( fs.readFileSync( dir + '/state.json', 'utf8' ) );
const password = fs.readFileSync( '/tmp/opf-category-price-admin-password', 'utf8' );
const checks = [];
const errors = [];
const orders = {};
const ownedOrders = fs.existsSync( dir + '/owned-orders.json' ) ? JSON.parse( fs.readFileSync( dir + '/owned-orders.json', 'utf8' ) ) : ( fs.existsSync( dir + '/orders.json' ) ? Object.values( JSON.parse( fs.readFileSync( dir + '/orders.json', 'utf8' ) ) ) : [] );
const check = ( label, pass ) => {
	checks.push( { label, pass: !! pass } );
	if ( ! pass ) throw new Error( label );
};
const browser = await chromium.launch( { headless: true } );
try {
	const admin = await browser.newContext();
	const page = await admin.newPage();
	page.on( 'pageerror', e => errors.push( e.message ) );
	await page.goto( base + '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'parity-admin' );
	await page.locator( '#user_pass' ).fill( password );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
	const edit = base + '/wp-admin/post.php?post=' + state.group + '&action=edit';
	await page.goto( edit );
	const prices = page.locator( '#opf-builder-app select[aria-label="Price"]' );
	check( 'served builder exposes four category fixed/none controls', await prices.count() === 4 );
	for ( const [ index, original ] of [ 'fixed', 'none', 'fixed', 'none' ].entries() ) {
		check( 'builder initial category price ' + index, await prices.nth( index ).inputValue() === original );
		const changed = original === 'fixed' ? 'none' : 'fixed';
		await prices.nth( index ).selectOption( changed );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await page.getByText( 'Saved.', { exact: true } ).waitFor();
		await page.reload();
		check( 'builder saved/reloaded category price ' + index, await prices.nth( index ).inputValue() === changed );
		await prices.nth( index ).selectOption( original );
		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await page.getByText( 'Saved.', { exact: true } ).waitFor();
	}
	await page.screenshot( { path: dir + '/builder.png', fullPage: true } );
	await admin.close();

	for ( const transport of [ 'classic', 'store-api' ] ) {
		const context = await browser.newContext();
		const page = await context.newPage();
		page.on( 'pageerror', e => errors.push( transport + ': ' + e.message ) );
		await page.goto( base + '/?p=' + state.parent );
		await page.locator( 'form.cart input.input-fixed_card' ).first().waitFor();
		check( transport + ' paid card emits parent price type', await page.locator( 'input.input-fixed_card[value="' + state.alpha + '"]' ).getAttribute( 'data-opf-pricetype' ) === 'qt' );
		check( transport + ' free card omits paid pricing metadata', await page.locator( 'input.input-none_card[value="' + state.beta + '"]' ).getAttribute( 'data-opf-pricetype' ) === null );
		check( transport + ' both quantity category fields render catalog products', await page.locator( 'input.input-fixed_qty' ).count() === 2 && await page.locator( 'input.input-none_qty' ).count() === 2 );
		await page.screenshot( { path: dir + '/render-' + transport + '.png', fullPage: true } );
		const payload = { [ state.group ]: { fixed_card: [ String( state.alpha ) ], none_card: [ String( state.beta ) ], fixed_qty: { [ state.beta ]: 3 }, none_qty: { [ state.alpha ]: 4 } } };
			await page.locator( 'input.input-fixed_card[value="' + state.alpha + '"]' ).check();
			await page.locator( 'input.input-none_card[value="' + state.beta + '"]' ).check();
			await page.locator( 'input.input-fixed_qty[data-choice-slug="' + state.beta + '"]' ).fill( '3' );
			await page.locator( 'input.input-none_qty[data-choice-slug="' + state.alpha + '"]' ).fill( '4' );
			await page.locator( 'form.cart input[name="quantity"]' ).fill( '2' );
			await page.waitForTimeout( 150 );
			const preview = Number( ( await page.locator( '.opf-grand-total' ).innerText() ).replace( /[^0-9.]/g, '' ) );
			fs.writeFileSync( dir + '/selected-preview-' + transport + '.json', JSON.stringify( { expected: 92, actual: preview }, null, 2 ) );
			await page.screenshot( { path: dir + '/selected-preview-' + transport + '.png', fullPage: true } );
			check( transport + ' selected category preview equals checkout total 92', preview === 92 );
		if ( transport === 'classic' ) {
			await page.locator( 'button.single_add_to_cart_button' ).click();
			await page.locator( '.woocommerce-message, .wc-block-components-notice-banner.is-success' ).first().waitFor();
		} else {
			const initial = await context.request.get( base + '/?rest_route=/wc/store/v1/cart' );
			const response = await context.request.post( base + '/?rest_route=/wc/store/v1/cart/add-item', { headers: { Nonce: initial.headers().nonce }, data: { id: state.parent, quantity: 2, opf_fields: payload } } );
			check( transport + ' add-item succeeds', response.ok() );
		}
		const result = await context.request.get( base + '/?rest_route=/wc/store/v1/cart' );
		const cart = await result.json();
		check( transport + ' creates parent and four category child lines', cart.items.length === 5 );
		check( transport + ' total 40+16+0+36+0=92', Number( cart.totals.total_price ) / 10 ** cart.totals.currency_minor_unit === 92 );
		const children = cart.items.filter( item => item.extensions?.opf?.childItem );
		check( transport + ' child markers exposed by Store API', children.length === 4 );
		check( transport + ' two child lines free', children.filter( item => Number( item.totals.line_total ) === 0 ).length === 2 );
		let redirect;
		if ( transport === 'classic' ) {
			await page.goto( base + '/?page_id=' + state.checkout );
			const nonce = await page.locator( '[name="woocommerce-process-checkout-nonce"]' ).inputValue();
			const checkout = await context.request.post( base + '/?wc-ajax=checkout', { form: {
				'woocommerce-process-checkout-nonce': nonce,
				billing_first_name: 'Category', billing_last_name: 'Proof', billing_country: 'US',
				billing_address_1: '1 Test Street', billing_city: 'Testville', billing_state: 'CA', billing_postcode: '90210',
				billing_phone: '5551234567', billing_email: 'category-proof@example.invalid', payment_method: 'bacs', terms: 'on',
			} } );
			const response = await checkout.json();
			check( transport + ' real checkout succeeds', response.result === 'success' );
			redirect = response.redirect;
			orders[ transport ] = Number( redirect.match( /order-received\/(\d+)/ )?.[1] || new URL( redirect ).searchParams.get( 'order-received' ) );
		} else {
			const address = { first_name: 'Category', last_name: 'Proof', country: 'US', address_1: '1 Test Street', city: 'Testville', state: 'CA', postcode: '90210', phone: '5551234567', email: 'category-proof@example.invalid' };
			const checkout = await context.request.post( base + '/?rest_route=/wc/store/v1/checkout', { headers: { Nonce: result.headers().nonce }, data: { billing_address: address, shipping_address: address, payment_method: 'bacs', payment_data: [] } } );
			const response = await checkout.json();
			check( transport + ' real Store API checkout succeeds', checkout.ok() && response.payment_result?.payment_status === 'success' );
			redirect = response.payment_result.redirect_url;
			orders[ transport ] = Number( response.order_id );
		}
		check( transport + ' order id captured', orders[ transport ] > 0 );
		ownedOrders.push( orders[ transport ] );
		await page.goto( redirect );
		check( transport + ' order confirmation shows all selected products', ( await page.locator( 'body' ).innerText() ).includes( 'Category price alpha' ) && ( await page.locator( 'body' ).innerText() ).includes( 'Category price beta' ) );
		await page.screenshot( { path: dir + '/order-' + transport + '.png', fullPage: true } );
		await context.close();
	}
	check( 'no browser runtime errors', errors.length === 0 );
} finally {
	fs.writeFileSync( dir + '/browser-checks.json', JSON.stringify( { checks, errors }, null, 2 ) );
	fs.writeFileSync( dir + '/orders.json', JSON.stringify( orders, null, 2 ) );
	fs.writeFileSync( dir + '/owned-orders.json', JSON.stringify( [ ...new Set( ownedOrders ) ], null, 2 ) );
	await browser.close();
}
console.log( checks.length + ' browser checks passed.' );
