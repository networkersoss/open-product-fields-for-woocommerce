// Real-Chromium proof for the text-swatch chip decoration.
//
// WAPF Extended 3.1.5 applies four declarations to `.wapf-swatch--text`
// (`assets/css/frontend-themed.min.css`):
//
//   border: var(--apf-ts-border, none);
//   color: var(--apf-ts-color, inherit);
//   background: var(--apf-ts-bg, transparent);
//   border-radius: var(--apf-ts-radius, 4px);
//
// OPF re-emits the `--apf-ts-*` values from the migrated `wapf_design_settings`
// option and must apply them on `.opf-text-swatch-wrapper .opf-swatch--text`
// only: plain checkbox/radio choices also carry `.opf-swatch--text` in OPF.
//
// Usage:
//   OPF_TSCHIP_BASE_URL=http://127.0.0.1:8090 \
//   OPF_TSCHIP_LABEL=migrated \
//   OPF_TSCHIP_EXPECT='{"border":"2px solid rgb(204, 204, 204)","background":"rgb(18, 18, 18)","color":"rgb(255, 255, 255)","radius":"4px"}' \
//   OPF_TSCHIP_OUT=<repo>/docs/compatibility/textswatchchip-proof-20261005/migrated.json \
//   node bin/e2e-text-swatch-chip-browser.mjs

import { createRequire } from 'node:module';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const requireFromPlugin = createRequire(
	process.env.OPF_PLAYWRIGHT_PACKAGE || '/home/followersya-5hqi7/followersya.com/node_modules/playwright/package.json'
);
const { chromium } = requireFromPlugin( 'playwright' );

const base = process.env.OPF_TSCHIP_BASE_URL || 'http://127.0.0.1:8090';
const label = process.env.OPF_TSCHIP_LABEL || 'state';
const out = process.env.OPF_TSCHIP_OUT || '';
const screenshotDir = process.env.OPF_TSCHIP_SCREENSHOTS || '';
const expected = JSON.parse( process.env.OPF_TSCHIP_EXPECT || '{}' );
if ( ! /^http:\/\/127\.0\.0\.1:\d+$/.test( base ) ) throw new Error( 'Loopback clone required.' );
if ( ! expected.border || ! expected.background || ! expected.color || ! expected.radius ) {
	throw new Error( 'OPF_TSCHIP_EXPECT needs border, background, color and radius.' );
}

const checks = [];
const check = ( name, pass, detail = '' ) => {
	checks.push( { name, pass: !! pass, detail } );
	console.log( `${ pass ? 'ok' : 'FAIL' } ${ name }${ detail ? ' — ' + detail : '' }` );
};
const report = () => {
	const failures = checks.filter( ( c ) => ! c.pass ).length;
	console.log( `${ checks.length } checks, ${ failures } failures (${ label })` );
	if ( failures ) process.exitCode = 1;
};

const chipStyle = ( locator ) => locator.evaluate( ( node ) => {
	const style = getComputedStyle( node );
	const wrapper = node.parentElement ? getComputedStyle( node.parentElement ) : null;
	return {
		border: `${ style.borderTopWidth } ${ style.borderTopStyle } ${ style.borderTopColor }`,
		borderTopWidth: style.borderTopWidth,
		borderTopStyle: style.borderTopStyle,
		borderTopColor: style.borderTopColor,
		background: style.backgroundColor,
		color: style.color,
		inheritedColor: wrapper ? wrapper.color : null,
		radius: style.borderRadius,
		classes: node.className,
		wrapperClasses: node.parentElement ? node.parentElement.className : '',
	};
} );

const browser = await chromium.launch( { headless: true } );
try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	const response = await page.goto( base + '/product/opf-text-swatch-chip/', { waitUntil: 'load' } );
	check( 'fixture product page returns HTTP 200', response && 200 === response.status(), String( response && response.status() ) );

	const result = { label, url: page.url(), generatedAt: new Date().toISOString(), expected, stylesheets: await page.evaluate( () => Array.from( document.querySelectorAll( 'link[rel="stylesheet"]' ) ).map( ( link ) => link.href ).filter( ( href ) => /opf-frontend|advanced-product-fields/.test( href ) ) ) };

	const wrapper = page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper' );
	check( 'text swatch field renders the text-swatch wrapper', 1 === await wrapper.count() );
	const chips = page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper .opf-swatch--text' );
	check( 'every choice is a text swatch chip', 2 === await chips.count(), String( await chips.count() ) );

	const chip = await chipStyle( chips.first() );
	result.chip = chip;
	if ( 'none' === expected.border ) {
		// Unconfigured: WAPF's `border: var(--apf-ts-border, none)` fallback, i.e.
		// the initial border the element already had.
		check( 'chip border falls back to none (0px)', '0px' === chip.borderTopWidth && 'none' === chip.borderTopStyle, chip.border );
	} else {
		check( `chip border is "${ expected.border }"`, expected.border === chip.border, chip.border );
	}
	check( `chip background is "${ expected.background }"`, expected.background === chip.background, chip.background );
	if ( 'inherit' === expected.color ) {
		// The unconfigured fallback is WAPF's `color: var(--apf-ts-color, inherit)`:
		// the chip must resolve to the colour of its own container.
		check( 'chip color falls back to inherit', chip.color === chip.inheritedColor, `${ chip.color } vs wrapper ${ chip.inheritedColor }` );
	} else {
		check( `chip color is "${ expected.color }"`, expected.color === chip.color, chip.color );
	}
	check( `chip border-radius is "${ expected.radius }"`, expected.radius === chip.radius, chip.radius );
	check( 'chip wrapper is the scoped text-swatch wrapper', /opf-text-swatch-wrapper/.test( chip.wrapperClasses ), chip.wrapperClasses );

	// Regression control: the plain checkbox choice shares `.opf-swatch--text`
	// but lives in a wrapper without the scoping class, so the chip rule must
	// not reach it. WAPF renders that element borderless/transparent too.
	const checkbox = await chipStyle( page.locator( '[data-opf-field="extras"] .opf-swatch--text' ).first() );
	result.checkboxControl = checkbox;
	check( 'plain checkbox choice keeps no border', '0px' === checkbox.borderTopWidth && 'none' === checkbox.borderTopStyle, `${ checkbox.borderTopWidth } ${ checkbox.borderTopStyle } ${ checkbox.borderTopColor }` );
	check( 'plain checkbox choice keeps a transparent background', 'rgba(0, 0, 0, 0)' === checkbox.background, checkbox.background );
	check( 'plain checkbox choice keeps a 0px radius', '0px' === checkbox.radius, checkbox.radius );
	check( 'plain checkbox wrapper is not the text-swatch wrapper', ! /opf-text-swatch-wrapper/.test( checkbox.wrapperClasses ), checkbox.wrapperClasses );

	// The chip is still a real choice control.
	const firstInput = page.locator( '[data-opf-field="finish"] input[value="matte"]' );
	await firstInput.check();
	const checkedChip = await chipStyle( page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper .opf-swatch--text' ).first() );
	result.checkedChip = checkedChip;
	check( 'selected chip keeps its border', 'none' === expected.border ? ( '0px' === checkedChip.borderTopWidth && 'none' === checkedChip.borderTopStyle ) : expected.border === checkedChip.border, checkedChip.border );
	check( 'selected chip keeps its background', expected.background === checkedChip.background, checkedChip.background );

	if ( screenshotDir ) {
		mkdirSync( screenshotDir, { recursive: true } );
		await page.screenshot( { path: `${ screenshotDir }/${ label }.png`, fullPage: false } );
	}

	if ( out ) {
		mkdirSync( dirname( out ), { recursive: true } );
		writeFileSync( out, JSON.stringify( result, null, 2 ) + '\n' );
		console.log( `artifact ${ out }` );
	}
	console.log( `${ checks.length } checks, ${ checks.filter( ( c ) => ! c.pass ).length } failures (${ label })` );
	report();
} finally {
	await browser.close();
}
