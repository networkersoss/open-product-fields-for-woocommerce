import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!passwordFile) throw new Error('OPF_E2E_PASSWORD_FILE is required');

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
	const response = await page.goto(`${base}/product/opf-e2e-styled-choices-product/`, { waitUntil: 'domcontentloaded' });
	check('styled-choice fixture product responds successfully', response?.status() === 200);
	const group = page.locator('.opf-field-group--styled-choice-controls').filter({ has: page.locator('[data-opf-field="extras"]') });
	const gift = group.getByRole('checkbox', { name: 'Gift wrap', exact: true });
	const note = group.getByRole('checkbox', { name: 'Gift note', exact: true });
	const blue = group.getByRole('radio', { name: 'Blue', exact: true });
	const red = group.getByRole('radio', { name: 'Red', exact: true });
	check('checkbox and radio remain native named controls', await group.count() === 1 && await gift.count() === 1 && await note.count() === 1 && await blue.count() === 1 && await red.count() === 1);
	const colors = await gift.evaluate((input) => ({ accent: getComputedStyle(input).accentColor, border: getComputedStyle(input).borderColor }));
	check('configured accent and border colors reach native controls', colors.accent === 'rgb(20, 90, 158)' && colors.border === 'rgb(51, 51, 51)');
	await gift.focus();
	await page.keyboard.press('Space');
	check('Space toggles the native checkbox and preserves independent choices', await gift.isChecked() && !(await note.isChecked()));
	await blue.focus();
	await page.keyboard.press('ArrowRight');
	check('radio arrow navigation selects the adjacent native option', await red.isChecked() && !(await blue.isChecked()));
	const selected = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()));
	check('selected values keep their native form names and slugs', selected.some(([key, value]) => key.endsWith('[extras][]') && value === 'gift') && selected.some(([key, value]) => key.endsWith('[finish]') && value === 'red'));

	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	await page.goto(`${base}/wp-admin/admin.php?page=wc-settings&tab=products&section=opf_product_fields`, { waitUntil: 'domcontentloaded' });
	check('WooCommerce settings expose the styled-control toggle and colors', await page.locator('input[name="opf_styled_choice_controls"]').isChecked() && await page.locator('input[name="opf_choice_accent"]').inputValue() === '#145a9e' && await page.locator('input[name="opf_choice_border"]').inputValue() === '#333333');
	check('styled-choice browser flow has no uncaught JavaScript errors', errors.length === 0);
	console.log(JSON.stringify({ pageErrors: errors, colors }));
} finally {
	await browser.close();
}
if (failures) process.exit(1);
