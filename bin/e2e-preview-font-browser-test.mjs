import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
const requirePlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requirePlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
const productId = Number(process.env.OPF_LIVE_PREVIEW_PRODUCT_ID);
const productUrl = process.env.OPF_LIVE_PREVIEW_PRODUCT_URL;
const fontFile = process.env.OPF_PREVIEW_FONT_FILE;
const fontName = 'OPF E2E Manrope';
const invalidFont = '/tmp/opf-preview-font-invalid.woff2';
if (!passwordFile || !productId || !productUrl || !fontFile) throw new Error('Preview-font E2E environment is incomplete');
writeFileSync(invalidFont, Buffer.from('not-a-font-file'));

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
const errors = [];
const fontResponses = [];
let failures = 0;
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', (response) => {
	if (response.url().includes('/opf-preview-fonts/')) fontResponses.push({ url: response.url(), status: response.status(), type: response.headers()['content-type'] || '' });
});
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

try {
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');

	const settingsUrl = `${base}/wp-admin/admin.php?page=wc-settings&tab=products&section=opf_live_preview`;
	await page.goto(settingsUrl, { waitUntil: 'domcontentloaded' });
	const settingsForm = page.locator('#mainform');
	check('font manager is in WooCommerce product settings and enables multipart form upload', await page.getByRole('heading', { name: 'Live Content Preview' }).count() > 0 && await settingsForm.evaluate((form) => form.enctype === 'multipart/form-data'));
	await page.locator('#opf-preview-font-name').fill(fontName);
	await page.locator('#opf-preview-font-file').setInputFiles(invalidFont);
	await settingsForm.locator('.woocommerce-save-button').click();
	await page.waitForLoadState('domcontentloaded');
	check('font upload rejects a WOFF2 extension with an invalid binary signature', await page.getByText('Upload a valid WOFF or WOFF2 file', { exact: false }).count() > 0);
	check('rejected font is not added to the registered list', await page.getByText('No custom fonts registered yet.').count() === 1);

	await page.locator('#opf-preview-font-name').fill(fontName);
	await page.locator('#opf-preview-font-file').setInputFiles(fontFile);
	await page.locator('#mainform .woocommerce-save-button').click();
	await page.waitForLoadState('domcontentloaded');
	check('valid WOFF2 font is registered from the settings section', await page.locator('#opf-preview-font-delete option').filter({ hasText: fontName }).count() === 1);

	await page.goto(`${base}/wp-admin/post.php?post=${productId}&action=edit`, { waitUntil: 'domcontentloaded' });
	await page.locator('#woocommerce-product-data .product_data_tabs a[href="#opf_live_preview_product_data"]').click();
	await page.locator('[data-opf-preview-group]').selectOption(String(Number(process.env.OPF_LIVE_PREVIEW_GROUP_ID)));
	await page.locator('[data-opf-preview-field]').selectOption('engraving');
	await page.locator('[data-opf-preview-image]').selectOption({ index: 1 });
	await page.locator('[data-opf-preview-font-picker]').selectOption(fontName);
	check('registered font appears in product editor picker and populates its family value', await page.locator('[data-opf-preview-font-family]').inputValue() === fontName);
	await page.locator('[data-opf-dynamic-property]').selectOption('color');
	await page.locator('[data-opf-dynamic-field]').selectOption('ink_multi');
	await page.locator('[data-opf-dynamic-default]').fill('#008000');
	await page.locator('[data-opf-dynamic-values] [data-opf-dynamic-choice="blue"]').fill('#0033cc');
	await page.locator('[data-opf-dynamic-property]').selectOption('font_family');
	await page.locator('[data-opf-dynamic-field]').selectOption('font_choice');
	await page.locator('[data-opf-dynamic-default]').fill('Arial, sans-serif');
	await page.locator('[data-opf-dynamic-values] [data-opf-dynamic-choice="serif"]').selectOption(fontName);
	await page.locator('#publish').click();
	await page.waitForLoadState('domcontentloaded');
	await page.locator('#woocommerce-product-data .product_data_tabs a[href="#opf_live_preview_product_data"]').click();
	check('registered font and dynamic font-choice mapping survive product save/reload', await page.locator('[data-opf-preview-font-picker]').inputValue() === fontName && JSON.parse(await page.locator('[data-opf-preview-json]').inputValue())[0]?.dynamic?.font_family?.values?.serif === fontName);

	fontResponses.length = 0;
	await page.goto(productUrl, { waitUntil: 'domcontentloaded' });
	await page.waitForFunction(() => Array.isArray(window.OPF_LIVE_PREVIEWS) && window.OPF_LIVE_PREVIEWS.length === 1);
	const overlay = page.locator('.woocommerce-product-gallery__image').nth(1).locator('[data-opf-live-preview="preview_1_engraving"]');
	await page.locator('[data-opf-field="engraving"] input').first().fill('Manrope works');
	const fontLoad = await page.evaluate(async (name) => {
		const loaded = await document.fonts.load(`24px "${name}"`, 'Manrope works');
		return {
			loaded: loaded.map((face) => ({ family: face.family.replace(/^['"]|['"]$/g, ''), status: face.status })),
			registered: Array.from(document.fonts).map((face) => ({ family: face.family.replace(/^['"]|['"]$/g, ''), status: face.status })),
			check: document.fonts.check(`24px "${name}"`, 'Manrope works'),
			fontRules: Array.from(document.querySelectorAll('style')).map((style) => style.textContent).filter((css) => css.includes('/opf-preview-fonts/')),
			stylesheetFontRules: Array.from(document.styleSheets).flatMap((sheet) => {
				try { return Array.from(sheet.cssRules).map((rule) => rule.cssText).filter((css) => css.includes('/opf-preview-fonts/')); }
				catch { return []; }
			}),
			fontResources: performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/opf-preview-fonts/')).map((entry) => ({ name: entry.name, initiatorType: entry.initiatorType })),
		};
	}, fontName);
	console.log('font load evidence ' + JSON.stringify({ fontResponses, fontLoad }));
	check('storefront emits @font-face and browser loads its uploaded font bytes', fontResponses.some((response) => response.status === 200 && response.type.includes('font/woff2') && response.url.includes('/opf-preview-fonts/')) && fontLoad.loaded.some((face) => face.family === fontName && face.status === 'loaded'));
	check('base and choice-mapped custom fonts render on the live overlay', await overlay.locator('.opf-live-preview-text').evaluate((node) => getComputedStyle(node).fontFamily.includes('OPF E2E Manrope')));
	await page.locator('[data-opf-field="font_choice"] select').selectOption('serif');
	check('local Select choice changes the overlay to the registered custom font', await overlay.locator('.opf-live-preview-text').evaluate((node) => getComputedStyle(node).fontFamily.includes('OPF E2E Manrope')));
	check('font upload and storefront flow has no uncaught browser errors', errors.length === 0);

	await page.goto(settingsUrl, { waitUntil: 'domcontentloaded' });
	const fontId = await page.locator('#opf-preview-font-delete option').filter({ hasText: fontName }).getAttribute('value');
	await page.locator('#opf-preview-font-delete').selectOption(fontId);
	await page.locator('#mainform .woocommerce-save-button').click();
	await page.waitForLoadState('domcontentloaded');
	check('registered test font can be safely removed from the WooCommerce settings section', await page.getByText('No custom fonts registered yet.').count() === 1);
} finally {
	await browser.close();
	try { unlinkSync(invalidFont); } catch {}
}
if (failures) process.exit(1);
