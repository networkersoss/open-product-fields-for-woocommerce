import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const url = process.argv[2];
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
const mode = process.env.OPF_STEPPER_MODE || 'on';
if (!url || !passwordFile || !['on', 'off'].includes(mode)) throw new Error('Usage: OPF_E2E_PASSWORD_FILE=... OPF_STEPPER_MODE=on|off node bin/e2e-number-stepper-browser-test.mjs <fixture-product-url>');

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

try {
	const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
	check('number-stepper fixture responds successfully', response?.status() === 200);
	const field = page.locator('[data-opf-field="quantity"]');
	const input = field.locator('input[type="number"]');
	if ( 'off' === mode ) {
		check('disabled global setting retains the native number input without step buttons', await input.count() === 1 && await field.locator('[data-opf-number-stepper]').count() === 0);
		const formValues = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()).filter(([key]) => key.endsWith('[quantity]')));
		check('disabled setting preserves the native input value and single form payload', formValues.length === 1 && formValues[0][1] === '0.5');
	} else {
	const down = field.getByRole('button', { name: 'Decrease Quantity' });
	const up = field.getByRole('button', { name: 'Increase Quantity' });
	check('native number constraints and default value are preserved', await input.inputValue() === '0.5' && await input.getAttribute('placeholder') === 'Enter amount' && await input.getAttribute('min') === '0' && await input.getAttribute('max') === '1' && await input.getAttribute('step') === '0.25');
	check('step buttons have accessible names and native button semantics', await down.getAttribute('type') === 'button' && await up.getAttribute('type') === 'button' && await down.count() === 1 && await up.count() === 1);

	await up.focus();
	await page.keyboard.press('Enter');
	check('keyboard activation increments by the configured decimal step', await input.inputValue() === '0.75');
	await up.click();
	check('increment stops at max and disables the upper button', await input.inputValue() === '1' && await up.isDisabled());
	await down.click();
	check('decrement works from the max boundary', await input.inputValue() === '0.75' && !(await up.isDisabled()));
	await down.click();
	await down.click();
	await down.click();
	check('decimal arithmetic is exact and decrement stops at min', await input.inputValue() === '0' && await down.isDisabled());

	await input.fill('0.5');
	await input.evaluate((node) => { node.readOnly = true; node.dispatchEvent(new Event('input', { bubbles: true })); });
	check('read-only number fields disable both controls', await down.isDisabled() && await up.isDisabled());
	await input.evaluate((node) => { node.readOnly = false; node.disabled = true; node.dispatchEvent(new Event('input', { bubbles: true })); });
	check('disabled number fields disable both controls', await down.isDisabled() && await up.isDisabled());
	await input.evaluate((node) => { node.disabled = false; node.dispatchEvent(new Event('input', { bubbles: true })); });
	const serialized = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()).filter(([key]) => key.endsWith('[quantity]')));
	check('stepper preserves the single native form input/value', serialized.length === 1 && serialized[0][1] === '0.5');
	}

	await page.goto(`${new URL(url).origin}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	await page.goto(`${new URL(url).origin}/wp-admin/admin.php?page=wc-settings&tab=products&section=opf_product_fields`, { waitUntil: 'domcontentloaded' });
	const setting = page.locator('input[name="opf_number_buttons"]');
	check('WooCommerce Product Fields settings display the correct global toggle state', await setting.count() === 1 && await setting.isChecked() === ('on' === mode));
	check('number-stepper browser flow has no uncaught JavaScript errors', errors.length === 0);
	console.log(JSON.stringify({ url, pageErrors: errors }));
} finally {
	await browser.close();
}
if (failures) process.exit(1);
