import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const groupId = process.env.OPF_SHORTCODE_GROUP_ID;
const productSlug = process.env.OPF_SHORTCODE_PRODUCT_SLUG || 'opf-e2e-shortcode-product';
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!groupId || !passwordFile) throw new Error('OPF_SHORTCODE_GROUP_ID and OPF_E2E_PASSWORD_FILE are required');

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
let failures = 0;
const check = (name, ok) => {
	console.log(`${ok ? 'ok' : 'FAIL'} ${name}`);
	if (!ok) failures++;
};

await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
await page.locator('#user_login').fill('admin');
await page.locator('#user_pass').fill(readFileSync(passwordFile, 'utf8').trim());
await page.locator('#wp-submit').click();
await page.waitForURL('**/wp-admin/**');
await page.goto(`${base}/wp-admin/post.php?post=${encodeURIComponent(groupId)}&action=edit`, { waitUntil: 'domcontentloaded' });
const builder = page.locator('#opf-builder-app');
await builder.waitFor({ state: 'visible' });
const shortcodeCard = builder.locator('.opf-b-field').nth(1);
const type = shortcodeCard.locator('select').first();
const content = shortcodeCard.locator('textarea').first();
const saveButton = builder.getByRole('button', { name: 'Save', exact: true });
check('saved shortcode group loads the shortcode field type and content in the builder', await type.inputValue() === 'shortcode' && (await content.inputValue()).includes('[opf_runtime_probe'));
check('builder Save control is a non-submitting button inside the WordPress post form', await saveButton.evaluate((button) => button.type === 'button'));

await content.fill('[opf_runtime_probe text="Builder and storefront verified"]');
const saveResponsePromise = page.waitForResponse((response) => response.url().includes('/opf/v1/groups') && response.request().method() === 'POST');
await saveButton.click();
const saveResponse = await saveResponsePromise;
await page.waitForFunction(() => {
	const text = document.getElementById('opf-b-status')?.textContent || '';
	return text === 'Saved.' || text.startsWith('Save failed:');
}, null, { timeout: 10000 });
const saveStatus = (await page.locator('#opf-b-status').textContent())?.trim() || '';
check('builder save endpoint returns success', saveResponse.status() === 200);
check('builder confirms the group saved', saveStatus === 'Saved.');
if (saveStatus !== 'Saved.') console.log(`builder save status: ${saveStatus}`);
await page.reload({ waitUntil: 'domcontentloaded' });
await builder.waitFor({ state: 'visible' });
check('builder save survives reload without converting or dropping shortcode markup', await type.inputValue() === 'shortcode' && (await content.inputValue()) === '[opf_runtime_probe text="Builder and storefront verified"]');
await page.screenshot({ path: '/tmp/opf-shortcode-builder.png', fullPage: true });

await page.goto(`${base}/product/${encodeURIComponent(productSlug)}/`, { waitUntil: 'domcontentloaded' });
const rendered = page.locator('.opf-e2e-shortcode');
await rendered.waitFor({ state: 'visible' });
check('product page executes the registered shortcode from the saved OPF group', (await rendered.innerText()) === 'Builder and storefront verified');
const bookingField = page.locator('[data-opf-field="booking"]');
const onlineMode = page.locator('[data-opf-field="calendar-mode"] input[value="online"]');
const offlineMode = page.locator('[data-opf-field="calendar-mode"] input[value="offline"]');
check('shortcode field starts visible for its matching conditional selection', await bookingField.isVisible());
await offlineMode.check();
await page.waitForFunction(() => document.querySelector('[data-opf-field="booking"]')?.classList.contains('opf-hide'));
check('shortcode field hides when its conditional selection no longer matches', !(await bookingField.isVisible()));
await onlineMode.check();
await bookingField.waitFor({ state: 'visible' });
check('shortcode field returns when the conditional selection matches again', await bookingField.isVisible());
check('builder and product pages have no uncaught JavaScript errors', errors.length === 0);
await page.screenshot({ path: '/tmp/opf-shortcode-product.png', fullPage: true });

await browser.close();
if (failures) process.exit(1);
