// Text-swatch corner radius storefront proof on the disposable WordPress clone.
//
// WAPF Extended 3.1.5 applies the design `apf-ts-radius` value as
// `border-radius: var(--apf-ts-radius, 4px)` on `.wapf-swatch--text`
// (assets/css/frontend-themed.min.css). This harness reads the computed
// border-radius of the served text-swatch chip, the OPF setting override, the
// imported WAPF variable fallback, and the checkbox regression control.
import { createRequire } from 'node:module';
import fs from 'node:fs';

const requireFromPlugin = createRequire(
	process.env.OPF_PLAYWRIGHT_PACKAGE || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json'
);
const { chromium } = requireFromPlugin( 'playwright' );

const base = process.env.OPF_TEXT_SWATCH_BASE_URL || 'http://127.0.0.1:8090';
const expected = process.env.OPF_TEXT_SWATCH_EXPECT || '';
const label = process.env.OPF_TEXT_SWATCH_LABEL || 'state';
const artifactDir = process.env.OPF_TEXT_SWATCH_ARTIFACTS || '/tmp/opf-text-swatch-radius-evidence';
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
if ( ! /^\d+$/.test( expected ) ) throw new Error( 'Set OPF_TEXT_SWATCH_EXPECT to the expected pixel radius.' );

fs.mkdirSync( artifactDir, { recursive: true } );

const checks = [];
const check = ( name, pass, detail = '' ) => {
	checks.push( { name, pass: !! pass, detail } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ name }${ detail ? ' — ' + detail : '' }` );
	if ( ! pass ) throw new Error( name );
};

const browser = await chromium.launch( { headless: true } );
const pageErrors = [];
try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	const response = await page.goto( base + '/product/opf-text-swatch-radius/', { waitUntil: 'domcontentloaded' } );
	check( 'storefront product page returns HTTP 200', response && 200 === response.status(), String( response && response.status() ) );

	const wrapper = page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper' );
	check( 'text swatch field renders the text-swatch wrapper', 1 === await wrapper.count() );

	const chips = page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper .opf-swatch--text' );
	check( 'every choice is a text swatch chip', 2 === await chips.count(), String( await chips.count() ) );

	const first = chips.first();
	const computed = await first.evaluate( ( el ) => getComputedStyle( el ).borderRadius );
	check( `computed border-radius is ${ expected }px`, `${ expected }px` === computed, computed );

	const inlineVar = await wrapper.getAttribute( 'style' );
	check( 'wrapper publishes the OPF radius variable only when configured', null === inlineVar || inlineVar.includes( '--opf-text-swatch-radius' ), String( inlineVar ) );

	// Regression control: WAPF renders plain checkbox choices as
	// `.wapf-checkbox`, so the text-swatch radius must not reach them.
	const checkbox = page.locator( '[data-opf-field="extras"] .opf-swatch--text' ).first();
	const checkboxRadius = await checkbox.evaluate( ( el ) => getComputedStyle( el ).borderRadius );
	check( 'plain checkbox choice keeps a square 0px radius', '0px' === checkboxRadius, checkboxRadius );

	// The chip is a real choice control: keyboard selection still works.
	await first.locator( 'input' ).check();
	check( 'text swatch choice is still selectable', await first.locator( 'input' ).isChecked() );
	const selectedRadius = await first.evaluate( ( el ) => getComputedStyle( el ).borderRadius );
	check( 'radius survives the selected state', `${ expected }px` === selectedRadius, selectedRadius );

	check( 'no page errors', 0 === pageErrors.length, pageErrors.join( ' | ' ) );

	await page.locator( '[data-opf-field="finish"]' ).screenshot( { path: `${ artifactDir }/text-swatch-${ label }.png` } );
	console.log( JSON.stringify( { state: label, expected: `${ expected }px`, computed, checkboxRadius, pageErrors, checks: checks.length }, null, 2 ) );
} finally {
	await browser.close();
}
