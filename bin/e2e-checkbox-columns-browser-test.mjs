import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const groupId = process.env.OPF_CHECKBOX_COLUMNS_GROUP_ID;
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!groupId || !passwordFile) throw new Error('OPF_CHECKBOX_COLUMNS_GROUP_ID and OPF_E2E_PASSWORD_FILE are required');

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
	await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill(process.env.OPF_E2E_USER || 'admin');
	await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
	await page.locator('#wp-submit').click();
	await page.waitForURL('**/wp-admin/**');
	await page.goto(`${base}/wp-admin/post.php?post=${encodeURIComponent(groupId)}&action=edit`, { waitUntil: 'domcontentloaded' });
	const builder = page.locator('#opf-builder-app');
	await builder.waitFor({ state: 'visible' });
	const columns = builder.locator('input[title="Checkbox columns"]');
	check('builder loads the saved two-column value', await columns.count() === 1 && await columns.inputValue() === '2');
	await columns.fill('3');
	const saveResponsePromise = page.waitForResponse((response) => response.url().includes('/opf/v1/groups') && response.request().method() === 'POST');
	await builder.getByRole('button', { name: 'Save', exact: true }).click();
	const saveResponse = await saveResponsePromise;
	await page.waitForFunction(() => document.getElementById('opf-b-status')?.textContent === 'Saved.');
	check('builder saves the three-column value through REST', saveResponse.status() === 200);
	await page.reload({ waitUntil: 'domcontentloaded' });
	await builder.waitFor({ state: 'visible' });
	check('builder reload retains the saved three-column value', await columns.inputValue() === '3');

	await page.setViewportSize({ width: 1280, height: 900 });
	await page.goto(`${base}/product/opf-e2e-checkbox-columns-product/`, { waitUntil: 'domcontentloaded' });
	const choices = page.locator('[data-opf-field="extras"] .opf-checkboxes--columns');
	await choices.waitFor({ state: 'visible' });
	const choiceInputs = choices.locator('input[type="checkbox"]');
	check('storefront keeps all labeled checkbox choices', await choiceInputs.count() === 3 && await choices.getByText('Gift wrap').count() === 1 && await choices.getByText('Gift note').count() === 1 && await choices.getByText('Rush packing').count() === 1);
	check('desktop uses configured three-column grid', (await choices.evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length)) === 3);
	await page.setViewportSize({ width: 600, height: 900 });
	check('tablet-width layout wraps to two columns', (await choices.evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length)) === 2);
	await page.setViewportSize({ width: 400, height: 900 });
	check('mobile layout uses one column', (await choices.evaluate((node) => getComputedStyle(node).gridTemplateColumns.split(' ').length)) === 1);
	await page.setViewportSize({ width: 1280, height: 900 });
	await choiceInputs.nth(0).check();
	await choiceInputs.nth(2).check();
	const formValues = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()).filter(([key]) => key.endsWith('[extras][]')));
	check('selected checkbox values and label mapping remain unchanged', JSON.stringify(formValues.map(([, value]) => value)) === JSON.stringify(['gift', 'rush']));
	check('builder and storefront have no uncaught JavaScript errors', errors.length === 0);
	console.log(JSON.stringify({ groupId, pageErrors: errors }));
} finally {
	await browser.close();
}
if (failures) process.exit(1);
