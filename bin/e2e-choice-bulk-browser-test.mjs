import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const groupId = process.env.OPF_CHOICE_BULK_GROUP_ID;
const passwordFile = process.env.OPF_E2E_PASSWORD_FILE;
if (!groupId || !passwordFile) throw new Error('OPF_CHOICE_BULK_GROUP_ID and OPF_E2E_PASSWORD_FILE are required');

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
const field = builder.locator('.opf-b-field').first();
const bulkOptions = field.locator('textarea[title="Bulk choice options"]');
const saveButton = builder.getByRole('button', { name: 'Save', exact: true });
check('bulk import control is available for select choices', await bulkOptions.count() === 1);
check('existing option is retained when importing by default', await field.locator('.opf-b-choice').count() === 1);

await bulkOptions.fill('Small\nLarge\tL\t2.50\n');
await field.getByRole('button', { name: 'Import choices' }).click();
const choices = field.locator('.opf-b-choice');
check('paste adds both choices while retaining the existing choice', await choices.count() === 3);
check('plain label receives a slug and label', await choices.nth(1).locator('input.opf-b-slug').inputValue() === 'small' && await choices.nth(1).locator('input.opf-b-input:not(.opf-b-slug)').nth(0).inputValue() === 'Small');
check('explicit value and fixed price are represented in the builder', await choices.nth(2).locator('input.opf-b-slug').inputValue() === 'L' && await choices.nth(2).locator('input.opf-b-input:not(.opf-b-slug)').nth(0).inputValue() === 'Large' && await choices.nth(2).locator('select').inputValue() === 'fixed' && await choices.nth(2).locator('input[placeholder="amount"]').inputValue() === '2.5');

const responsePromise = page.waitForResponse((response) => response.url().includes('/opf/v1/groups') && response.request().method() === 'POST');
await saveButton.click();
const saveResponse = await responsePromise;
await page.waitForFunction(() => document.getElementById('opf-b-status')?.textContent === 'Saved.');
check('group save endpoint succeeds', saveResponse.status() === 200);
await page.reload({ waitUntil: 'domcontentloaded' });
await builder.waitFor({ state: 'visible' });
const reloadedChoices = builder.locator('.opf-b-field').first().locator('.opf-b-choice');
check('imported choices and pricing survive a builder reload', await reloadedChoices.count() === 3 && await reloadedChoices.nth(1).locator('input.opf-b-slug').inputValue() === 'small' && await reloadedChoices.nth(2).locator('input.opf-b-slug').inputValue() === 'L' && await reloadedChoices.nth(2).locator('select').inputValue() === 'fixed' && await reloadedChoices.nth(2).locator('input[placeholder="amount"]').inputValue() === '2.5');
check('builder flow has no uncaught JavaScript errors', errors.length === 0);

await browser.close();
if (failures) process.exit(1);
