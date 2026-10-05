// Real storefront add-to-cart proof with OPF and WAPF Extended 3.1.5 both
// active on the disposable clone.
//
// The fixture (`bin/e2e-wapf-coexistence-test.php`) creates a product with an
// OPF required text field, an image-quantity field and a date field. WAPF
// registers its own validators on `wapf/validate`
// (class-linked-products-controller.php:37, extend/date.php:152) and
// `maybe_add_pricing_class` on `wapf/html/field_container_classes`
// (class-linked-products-controller.php:35). Pre-fix, OPF's array field raised
// `TypeError: … validate_cart(): Argument #3 ($field) must be of type
// SW_WAPF_PRO\Includes\Models\Field, array given` and a per-field
// `Attempt to read property "type" on array` warning.
import { createRequire } from 'node:module';

const requireFromPlugin = createRequire(
	process.env.OPF_PLAYWRIGHT_PACKAGE || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json'
);
const { chromium } = requireFromPlugin( 'playwright' );

const base = process.env.OPF_WAPF_COEX_BASE_URL || 'http://127.0.0.1:8090';
const slug = process.env.OPF_WAPF_COEX_SLUG || 'opf-wapf-coexistence';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );

const checks = [];
const check = ( name, pass, detail = '' ) => {
	checks.push( { name, pass: !! pass } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ name }${ detail ? ' — ' + detail : '' }` );
	if ( ! pass ) throw new Error( name );
};

const browser = await chromium.launch( { headless: true } );
const context = await browser.newContext();
const page = await context.newPage();
const pageErrors = [];
const failedRequests = [];
page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'requestfailed', ( request ) => failedRequests.push( request.method() + ' ' + request.url() ) );

try {
	const url = base + '/product/' + slug + '/';
	const response = await page.goto( url, { waitUntil: 'domcontentloaded' } );
	check( 'product page returns HTTP 200', response && 200 === response.status(), String( response && response.status() ) );

	const note = page.locator( '[data-opf-field="note"] input' ).first();
	const quantity = page.locator( '[data-opf-field="prints"] input[type="number"]' ).first();
	const date = page.locator( '[data-opf-field="delivery"] input' ).first();
	await note.waitFor( { state: 'visible' } );
	await quantity.waitFor( { state: 'visible' } );
	await date.waitFor( { state: 'visible' } );
	check(
		'the fixture OPF fields render with WAPF active',
		1 === await page.locator( '[data-opf-field="note"]' ).count()
			&& 1 === await page.locator( '[data-opf-field="prints"]' ).count()
			&& 1 === await page.locator( '[data-opf-field="delivery"]' ).count()
	);

	await note.fill( 'Checked sample' );
	await quantity.fill( '2' );
	await date.fill( '2027-06-15' );

	const submit = page.waitForResponse( ( r ) => 'POST' === r.request().method() && r.url().includes( '/product/' + slug + '/' ) );
	await page.locator( 'form.cart button.single_add_to_cart_button' ).click();
	const posted = await submit;
	check( 'add-to-cart form POST returns HTTP 200', 200 === posted.status(), String( posted.status() ) );

	const body = await posted.text();
	check( 'the response contains no PHP fatal', ! /Fatal error|TypeError|Uncaught/.test( body ) );
	check( 'the response contains no PHP warning', ! /Warning:/.test( body ) );
	await page.locator( '.woocommerce-message, .wc-block-components-notice-banner.is-success' ).first().waitFor( { timeout: 15000 } );
	check( 'the storefront confirms the add-to-cart', true );

	const cartResponse = await context.request.get( base + '/wp-json/wc/store/v1/cart' );
	check( 'Store API cart is readable', cartResponse.ok(), String( cartResponse.status() ) );
	const cart = await cartResponse.json();
	const line = ( cart.items || [] ).find( ( item ) => item.name === 'OPF WAPF Coexistence' );
	check( 'the cart holds the fixture product', !! line );
	check( 'the cart quantity is 1', line && 1 === line.quantity, line ? String( line.quantity ) : 'no line' );
	check( 'no page errors', 0 === pageErrors.length, pageErrors.join( ' | ' ) );
	check( 'no failed requests', 0 === failedRequests.length, failedRequests.join( ' | ' ) );

	const cleared = await context.request.delete( base + '/wp-json/wc/store/v1/cart/items', {
		headers: cartResponse.headers()['nonce'] ? { Nonce: cartResponse.headers()['nonce'] } : {},
	} );
	check( 'isolated browser cart emptied', cleared.ok(), String( cleared.status() ) );

	console.log( JSON.stringify( { url, checks: checks.length, pageErrors, failedRequests }, null, 2 ) );
} finally {
	await context.close();
	await browser.close();
}
