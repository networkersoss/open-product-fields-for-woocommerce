// Real-Chromium proof for the text-swatch chip decoration (base, hover, selected).
//
// WAPF Extended 3.1.5 applies four declarations to `.wapf-swatch--text`
// (`assets/css/frontend-themed.min.css`):
//
//   border: var(--apf-ts-border, none);
//   color: var(--apf-ts-color, inherit);
//   background: var(--apf-ts-bg, transparent);
//   border-radius: var(--apf-ts-radius, 4px);
//
// and two state rules to the same selector:
//
//   .wapf-swatch--text:hover        color: var(--apf-ts-color-hov, inherit);
//                                   border-color: var(--apf-ts-border-color-hov, transparent);
//                                   background: var(--apf-ts-bg-hov, transparent);
//   .wapf-swatch--text.wapf-checked border-color: var(--apf-ts-border-color-sel, transparent);
//                                   background: var(--apf-ts-bg-sel, transparent);
//                                   color: var(--apf-ts-color-sel, inherit);
//
// OPF re-emits the `--apf-ts-*` values from the migrated `wapf_design_settings`
// option and must apply them on `.opf-text-swatch-wrapper .opf-swatch--text`
// only: plain checkbox/radio choices also carry `.opf-swatch--text` in OPF.
//
// `OPF_TSCHIP_EXPECT.hover` / `.selected` (both optional, same shape as the base
// values) assert the configured state values; when a state is omitted the
// harness asserts the state is *not visible* — the unconfigured fallbacks
// (`transparent` / `inherit`) must leave the chip's rendering untouched. Every
// state is also checked against the plain checkbox and radio choices.
//
// Usage:
//   OPF_TSCHIP_BASE_URL=http://127.0.0.1:8090 \
//   OPF_TSCHIP_LABEL=migrated \
//   OPF_TSCHIP_EXPECT='{"border":"2px solid rgb(204, 204, 204)","background":"rgb(18, 18, 18)","color":"rgb(255, 255, 255)","radius":"4px","hover":{"borderColor":"rgb(164, 164, 164)","background":"rgb(0, 255, 0)","color":"rgb(0, 0, 255)"},"selected":{"borderColor":"rgb(53, 60, 78)","background":"rgb(18, 18, 18)","color":"rgb(255, 255, 255)"}}' \
//   OPF_TSCHIP_OUT=<repo>/docs/compatibility/textswatchchip-proof-20261005/migrated-hover-selected.json \
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

// `hover` / `selected` are optional: an omitted state must not be visible.
const stateExpectation = ( name ) => {
	const value = expected[name];
	if ( undefined === value ) return null;
	for ( const key of [ 'borderColor', 'background', 'color' ] ) {
		if ( ! value[key] ) throw new Error( `OPF_TSCHIP_EXPECT.${ name } needs borderColor, background and color.` );
	}
	return value;
};
const hoverExpect = stateExpectation( 'hover' );
const selectedExpect = stateExpectation( 'selected' );

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

// Assert the configured state values, or — when the state is unconfigured — that
// nothing visible changed (an unconfigured chip keeps `transparent`/`inherit`
// fallbacks, and its border-color resolves to transparent while it has no border
// to paint).
const checkVisibleState = ( name, style, expectation ) => {
	if ( expectation ) {
		check( `${ name }: border-color is "${ expectation.borderColor }"`, expectation.borderColor === style.borderTopColor, style.borderTopColor );
		check( `${ name }: background is "${ expectation.background }"`, expectation.background === style.background, style.background );
		check( `${ name }: colour is "${ expectation.color }"`, expectation.color === style.color, style.color );
		check( `${ name }: keeps the configured radius`, style.radius === expected.radius, style.radius );
		return;
	}
	check( `${ name }: no border appears`, '0px' === style.borderTopWidth && 'none' === style.borderTopStyle, `${ style.borderTopWidth } ${ style.borderTopStyle }` );
	check( `${ name }: background stays transparent`, 'rgba(0, 0, 0, 0)' === style.background, style.background );
	check( `${ name }: colour stays inherited`, style.color === style.inheritedColor, `${ style.color } vs wrapper ${ style.inheritedColor }` );
};

// Plain checkbox/radio choices share `.opf-swatch--text`; no state may decorate
// them (WAPF keeps them on `.wapf-checkbox`/`.wapf-radio`).
const checkPlainState = ( name, style, baseStyle ) => {
	check( `${ name }: stays borderless`, '0px' === style.borderTopWidth && 'none' === style.borderTopStyle, `${ style.borderTopWidth } ${ style.borderTopStyle } ${ style.borderTopColor }` );
	check( `${ name }: stays transparent`, 'rgba(0, 0, 0, 0)' === style.background, style.background );
	check( `${ name }: keeps its 0px radius`, '0px' === style.radius, style.radius );
	check( `${ name }: keeps the unscoped wrapper`, ! /opf-text-swatch-wrapper/.test( style.wrapperClasses ), style.wrapperClasses );
	check( `${ name }: rendering is unchanged`, baseStyle.background === style.background && baseStyle.border === style.border, `${ style.background } / ${ style.border }` );
};

const browser = await chromium.launch( { headless: true } );
try {
	const page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	const response = await page.goto( base + '/product/opf-text-swatch-chip/', { waitUntil: 'load' } );
	check( 'fixture product page returns HTTP 200', response && 200 === response.status(), String( response && response.status() ) );

	const result = {
		label,
		url: page.url(),
		generatedAt: new Date().toISOString(),
		expected,
		configured: { hover: !! hoverExpect, selected: !! selectedExpect },
		stylesheets: await page.evaluate( () => Array.from( document.querySelectorAll( 'link[rel="stylesheet"]' ) ).map( ( link ) => link.href ).filter( ( href ) => /opf-frontend|advanced-product-fields/.test( href ) ) ),
	};

	// Element screenshots are clipped with document coordinates from a full-page
	// capture, so they never scroll a fresh element under the parked pointer and
	// never disturb a hover state. The native radio/checkbox is hidden
	// symmetrically in every capture (`visibility` keeps its box): OPF does not
	// hide the unstyled native control, so it paints its own check mark when it is
	// checked — the comparison must isolate the chip's decoration, not the native
	// glyph.
	const parkMouse = () => page.mouse.move( 1279, 899 );
	const clipShot = async ( locator ) => {
		await locator.evaluate( ( node ) => node.querySelectorAll( 'input' ).forEach( ( input ) => { input.style.visibility = 'hidden'; } ) );
		const box = await locator.boundingBox();
		const offset = await page.evaluate( () => ( { x: window.scrollX, y: window.scrollY } ) );
		const shot = await page.screenshot( {
			fullPage: true,
			clip: { x: box.x + offset.x, y: box.y + offset.y, width: box.width, height: box.height },
		} );
		await locator.evaluate( ( node ) => node.querySelectorAll( 'input' ).forEach( ( input ) => { input.style.visibility = ''; } ) );
		return shot.toString( 'base64' );
	};
	const checkRendering = ( name, before, after, mustDiffer ) => check(
		`${ name }: rendered pixels ${ mustDiffer ? 'change' : 'are unchanged' }`,
		mustDiffer ? before !== after : before === after,
		before === after ? 'identical clip' : 'different clip'
	);

	const singleChips = page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper .opf-swatch--text' );
	const multiChips = page.locator( '[data-opf-field="finish_multi"] .opf-text-swatch-wrapper .opf-swatch--text' );
	check( 'single-select text swatch renders the text-swatch wrapper', 1 === await page.locator( '[data-opf-field="finish"] .opf-text-swatch-wrapper' ).count() );
	check( 'every single-select choice is a text swatch chip', 2 === await singleChips.count(), String( await singleChips.count() ) );
	check( 'multi-choice text swatch renders the text-swatch wrapper', 1 === await page.locator( '[data-opf-field="finish_multi"] .opf-text-swatch-wrapper' ).count() );
	check( 'every multi-choice choice is a text swatch chip', 2 === await multiChips.count(), String( await multiChips.count() ) );
	check( 'the multi-choice chip is not the single-select variant', ! /opf-single-select/.test( ( await chipStyle( multiChips.first() ) ).classes ), ( await chipStyle( multiChips.first() ) ).classes );

	await parkMouse();

	// --- base state -------------------------------------------------------
	const chip = await chipStyle( singleChips.first() );
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

	const multiBase = await chipStyle( multiChips.first() );
	result.multiBase = multiBase;
	check( 'multi-choice chip carries the same base decoration', multiBase.border === chip.border && multiBase.background === chip.background && multiBase.color === chip.color && multiBase.radius === chip.radius, `${ multiBase.border } / ${ multiBase.background } / ${ multiBase.color } / ${ multiBase.radius }` );

	const hoverTarget = singleChips.nth( 1 );
	const baseHoverTarget = await chipStyle( hoverTarget );
	const baseHoverTargetShot = await clipShot( hoverTarget );
	const baseMultiHoverTarget = await chipStyle( multiChips.nth( 1 ) );
	const baseMultiHoverTargetShot = await clipShot( multiChips.nth( 1 ) );
	const baseChipShot = await clipShot( singleChips.first() );
	const baseMultiChipShot = await clipShot( multiChips.first() );
	result.baseHoverTarget = baseHoverTarget;
	result.baseMultiHoverTarget = baseMultiHoverTarget;

	// Plain checkbox/radio choices: the regression risk — `.opf-swatch--text`
	// without the scoping wrapper.
	const plainBase = {};
	for ( const [ field, kind ] of [ [ 'extras', 'checkbox' ], [ 'size', 'radio' ] ] ) {
		const locator = page.locator( `[data-opf-field="${ field }"] .opf-swatch--text` ).first();
		const control = await chipStyle( locator );
		plainBase[kind] = { control, shot: await clipShot( locator ) };
		result[ `base${ kind[0].toUpperCase() + kind.slice( 1 ) }Control` ] = control;
		check( `plain ${ kind } choice keeps no border`, '0px' === control.borderTopWidth && 'none' === control.borderTopStyle, `${ control.borderTopWidth } ${ control.borderTopStyle } ${ control.borderTopColor }` );
		check( `plain ${ kind } choice keeps a transparent background`, 'rgba(0, 0, 0, 0)' === control.background, control.background );
		check( `plain ${ kind } choice keeps a 0px radius`, '0px' === control.radius, control.radius );
		check( `plain ${ kind } wrapper is not the text-swatch wrapper`, ! /opf-text-swatch-wrapper/.test( control.wrapperClasses ), control.wrapperClasses );
	}

	// --- hover state ------------------------------------------------------
	await hoverTarget.hover();
	const hovered = await chipStyle( hoverTarget );
	result.hovered = hovered;
	check( 'hovered chip keeps the text-swatch scope', /opf-text-swatch-wrapper/.test( hovered.wrapperClasses ), hovered.wrapperClasses );
	checkVisibleState( 'hovered chip', hovered, hoverExpect );
	if ( hoverExpect ) {
		check( 'hovered chip keeps the base border width', baseHoverTarget.borderTopWidth === hovered.borderTopWidth, hovered.borderTopWidth );
		check( 'hovered chip keeps the base radius', baseHoverTarget.radius === hovered.radius, hovered.radius );
	}
	checkRendering( 'hovered chip', baseHoverTargetShot, await clipShot( hoverTarget ), !! hoverExpect );

	await multiChips.nth( 1 ).hover();
	const hoveredMulti = await chipStyle( multiChips.nth( 1 ) );
	result.hoveredMulti = hoveredMulti;
	checkVisibleState( 'hovered multi-choice chip', hoveredMulti, hoverExpect );
	checkRendering( 'hovered multi-choice chip', baseMultiHoverTargetShot, await clipShot( multiChips.nth( 1 ) ), !! hoverExpect );

	for ( const [ field, kind ] of [ [ 'extras', 'checkbox' ], [ 'size', 'radio' ] ] ) {
		const locator = page.locator( `[data-opf-field="${ field }"] .opf-swatch--text` ).first();
		await locator.hover();
		const hoveredPlain = await chipStyle( locator );
		result[ `hovered${ kind[0].toUpperCase() + kind.slice( 1 ) }Control` ] = hoveredPlain;
		checkPlainState( `hovered plain ${ kind } choice`, hoveredPlain, plainBase[kind].control );
		checkRendering( `hovered plain ${ kind } choice`, plainBase[kind].shot, await clipShot( locator ), false );
	}

	await parkMouse();

	// --- selected state ---------------------------------------------------
	await page.locator( '[data-opf-field="finish"] input[value="matte"]' ).check();
	await parkMouse();
	const selected = await chipStyle( singleChips.first() );
	result.selectedChip = selected;
	check( 'selected chip carries `.opf-checked` (the class the state rule consumes)', /(^|\s)opf-checked(\s|$)/.test( selected.classes ), selected.classes );
	checkVisibleState( 'selected chip', selected, selectedExpect );
	checkRendering( 'selected chip', baseChipShot, await clipShot( singleChips.first() ), !! selectedExpect );

	await page.locator( '[data-opf-field="finish_multi"] input[value="matte"]' ).check();
	await parkMouse();
	const selectedMulti = await chipStyle( multiChips.first() );
	result.selectedMultiChip = selectedMulti;
	check( 'selected multi-choice chip carries `.opf-checked`', /(^|\s)opf-checked(\s|$)/.test( selectedMulti.classes ), selectedMulti.classes );
	checkVisibleState( 'selected multi-choice chip', selectedMulti, selectedExpect );
	checkRendering( 'selected multi-choice chip', baseMultiChipShot, await clipShot( multiChips.first() ), !! selectedExpect );

	// WAPF cascade: `.wapf-checked` is declared after `:hover`, so a selected
	// chip that is also hovered keeps its selected values.
	await singleChips.first().hover();
	const selectedHovered = await chipStyle( singleChips.first() );
	result.selectedHovered = selectedHovered;
	check( 'selected + hovered chip keeps the selected state', selectedHovered.background === selected.background && selectedHovered.color === selected.color && selectedHovered.borderTopColor === selected.borderTopColor, `${ selectedHovered.background } / ${ selectedHovered.color } / ${ selectedHovered.borderTopColor }` );

	// --- plain choices, checked ------------------------------------------
	for ( const [ field, kind ] of [ [ 'extras', 'checkbox' ], [ 'size', 'radio' ] ] ) {
		const locator = page.locator( `[data-opf-field="${ field }"] .opf-swatch--text` ).first();
		await locator.locator( 'input' ).check();
		await parkMouse();
		const checkedPlain = await chipStyle( locator );
		result[ `checked${ kind[0].toUpperCase() + kind.slice( 1 ) }Control` ] = checkedPlain;
		check( `checked plain ${ kind } choice is selected but not decorated`, /(^|\s)opf-checked(\s|$)/.test( checkedPlain.classes ), checkedPlain.classes );
		checkPlainState( `checked plain ${ kind } choice`, checkedPlain, plainBase[kind].control );
		checkRendering( `checked plain ${ kind } choice`, plainBase[kind].shot, await clipShot( locator ), false );
	}

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
