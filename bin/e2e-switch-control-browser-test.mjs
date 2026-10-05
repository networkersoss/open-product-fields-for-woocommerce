import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const url = process.argv[2];
if (!url) throw new Error('Usage: node bin/e2e-switch-control-browser-test.mjs <fixture-product-url>');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const pageErrors = [];
page.on('pageerror', (error) => pageErrors.push(error.message));

try {
	const response = await page.goto(url, { waitUntil: 'networkidle' });
	assert.equal(response?.status(), 200, 'fixture product page loads');

	const enabled = page.getByRole('switch', { name: 'Enabled' });
	const gift = page.getByRole('switch', { name: 'Gift wrap' });
	const note = page.getByRole('switch', { name: 'Gift note' });
	assert.equal(await enabled.count(), 1, 'true/false field is exposed as one named switch');
	assert.equal(await gift.count(), 1, 'each checkbox choice is exposed as a named switch');
	assert.equal(await note.count(), 1, 'second checkbox choice remains independent');
	assert.equal(await enabled.getAttribute('type'), 'checkbox', 'switch keeps native checkbox form semantics');
	assert.equal(await gift.getAttribute('type'), 'checkbox');
	assert.equal(await enabled.isChecked(), false);
	assert.equal(await page.locator('[data-opf-field="conditional_note"]').isVisible(), false, 'conditional control is hidden while its switch is off');

	const initial = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()));
	assert.ok(!initial.some(([key, value]) => key.includes('conditional_note') && value !== ''), 'hidden conditional control contributes no value');

	await enabled.focus();
	await page.keyboard.press('Space');
	assert.equal(await enabled.isChecked(), true, 'Space toggles the true/false switch');
	assert.equal(await page.locator('[data-opf-field="conditional_note"]').isVisible(), true, 'switch change activates the conditional field');

	await gift.focus();
	await page.keyboard.press('Space');
	assert.equal(await gift.isChecked(), true, 'Space toggles a checkbox choice switch');
	assert.equal(await note.isChecked(), false, 'checkbox switches are independent');

	const serialized = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()));
	assert.ok(serialized.some(([key, value]) => key.endsWith('[enabled]') && value === '1'), 'checked true/false value is submitted as 1');
	assert.deepEqual(serialized.filter(([key]) => key.endsWith('[extras][]')).map(([, value]) => value), ['gift'], 'selected checkbox retains its choice slug and unselected switch is omitted');

	const switchStyle = await enabled.evaluate((input) => ({
		appearance: getComputedStyle(input).appearance,
		background: getComputedStyle(input).backgroundColor,
		focusOutline: (() => { input.focus(); return getComputedStyle(input).outlineStyle; })(),
	}));
	assert.equal(switchStyle.appearance, 'none', 'custom switch control is visibly styled');
	assert.notEqual(switchStyle.background, 'rgba(0, 0, 0, 0)', 'switch track has a visible state color');
	assert.ok(pageErrors.length === 0, `no browser errors: ${pageErrors.join('; ')}`);
	console.log(JSON.stringify({ passed: 16, url, switchStyle, pageErrors }));
} finally {
	await browser.close();
}
