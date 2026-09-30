import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const url = process.argv[2];
if (!url) throw new Error('Usage: node bin/e2e-defaults-browser-test.mjs <fixture-product-url>');

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
	check('defaults fixture product responds successfully', response?.status() === 200);
	const engraving = page.locator('[data-opf-field="engraving"] input');
	const count = page.locator('[data-opf-field="count"] input');
	const finish = page.locator('[data-opf-field="finish"] select');
	const gift = page.locator('[data-opf-field="extras"] input[value="gift"]');
	const emptyBag = page.locator('[data-opf-field="empty_extras"] input[value="bag"]');
	const enabled = page.locator('[data-opf-field="enabled"] input[type="checkbox"]');
	check('scalar placeholder and text default render together', await engraving.getAttribute('placeholder') === 'Type initials' && await engraving.inputValue() === 'Ada');
	check('numeric placeholder and zero default render together', await count.getAttribute('placeholder') === 'Number of items' && await count.inputValue() === '0');
	check('choice default is selected while other choice is not', await finish.inputValue() === 'blue' && await finish.locator('option[value="red"]').evaluate((node) => !node.selected));
	check('non-empty checkbox default is checked and empty default stays unchecked', await gift.isChecked() && !(await emptyBag.isChecked()));
	check('false toggle default hides its dependent default field', !(await enabled.isChecked()) && !(await page.locator('[data-opf-field="secret"]').isVisible()));
	const formValues = await page.locator('form.cart').evaluate((form) => Array.from(new FormData(form).entries()));
	check('rendered defaults serialize as browser controls', formValues.some(([key, value]) => key.endsWith('[engraving]') && value === 'Ada') && formValues.some(([key, value]) => key.endsWith('[count]') && value === '0') && formValues.some(([key, value]) => key.endsWith('[finish]') && value === 'blue') && formValues.some(([key, value]) => key.endsWith('[extras][]') && value === 'gift'));
	check('empty choice default contributes no submitted choice value', !formValues.some(([key]) => key.endsWith('[empty_extras][]')));
	check('defaults browser flow has no uncaught JavaScript errors', errors.length === 0);
	console.log(JSON.stringify({ url, pageErrors: errors }));
} finally {
	await browser.close();
}
if (failures) process.exit(1);
