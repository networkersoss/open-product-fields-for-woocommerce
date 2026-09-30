import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
const uploads = [];
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', async (response) => {
	if (response.url().includes('/opf/v1/uploads') && response.request().method() === 'POST' && response.request().resourceType() === 'xhr' && response.status() >= 200 && response.status() < 300) {
		try { uploads.push(await response.json()); } catch {}
	}
});
await page.goto(`${base}/product/opf-e2e-upload-editor-product/`, { waitUntil: 'domcontentloaded' });
const fields = page.locator('[data-opf-upload]');
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQImWP8ICLCAANMDEgAABtqARyVdUwxAAAAAElFTkSuQmCC', 'base64');

const forced = fields.nth(0);
const directRaw = await page.evaluate(async ({ endpoint, nonce, product, group, field, bytes }) => {
	const form = new FormData();
	form.append('product_id', product);
	form.append('group_id', group);
	form.append('field_id', field);
	form.append('file', new File([Uint8Array.from(atob(bytes), (char) => char.charCodeAt(0))], 'raw.png', { type: 'image/png' }));
	const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce }, body: form });
	return { status: response.status, body: await response.json() };
}, {
	endpoint: await forced.getAttribute('data-opf-upload-url'),
	nonce: await forced.getAttribute('data-opf-upload-nonce'),
	product: await forced.getAttribute('data-opf-upload-product'),
	group: await forced.getAttribute('data-opf-upload-group'),
	field: await forced.getAttribute('data-opf-upload-field'),
	bytes: image.toString('base64'),
});
if (directRaw.status === 200 || !String(directRaw.body?.message || '').includes('required crop aspect ratio')) {
	throw new Error(`Direct raw 4×2 upload bypassed the forced 1:1 crop: ${JSON.stringify(directRaw)}`);
}
const rounded = fields.nth(2);
const roundedUpload = await page.evaluate(async ({ endpoint, nonce, product, group, field }) => {
	const canvas = document.createElement('canvas');
	canvas.width = 100;
	canvas.height = 56;
	const context = canvas.getContext('2d');
	context.fillStyle = '#246';
	context.fillRect(0, 0, canvas.width, canvas.height);
	const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
	const form = new FormData();
	form.append('product_id', product);
	form.append('group_id', group);
	form.append('field_id', field);
	form.append('file', new File([blob], 'rounded.png', { type: 'image/png' }));
	const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce }, body: form });
	return { status: response.status, body: await response.json() };
}, {
	endpoint: await rounded.getAttribute('data-opf-upload-url'),
	nonce: await rounded.getAttribute('data-opf-upload-nonce'),
	product: await rounded.getAttribute('data-opf-upload-product'),
	group: await rounded.getAttribute('data-opf-upload-group'),
	field: await rounded.getAttribute('data-opf-upload-field'),
});
if (roundedUpload.status !== 200 || !roundedUpload.body?.token || roundedUpload.body.size <= 0) {
	throw new Error(`Generated 100×56 16:9 canvas image failed the forced-ratio REST upload: ${JSON.stringify(roundedUpload)}`);
}
const roundedDelete = await page.evaluate(async ({ endpoint, nonce, token }) => {
	const response = await fetch(`${endpoint.replace(/\/?$/, '/')}${token}`, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
	return { status: response.status, body: await response.json() };
}, { endpoint: await rounded.getAttribute('data-opf-upload-url'), nonce: await rounded.getAttribute('data-opf-upload-nonce'), token: roundedUpload.body.token });
if (roundedDelete.status !== 200) throw new Error(`Rounded-ratio staged file cleanup failed: ${JSON.stringify(roundedDelete)}`);
await forced.locator('input[type="file"]').setInputFiles({ name: 'forced.png', mimeType: 'image/png', buffer: image });
const modal = page.locator('dialog[open]');
await modal.waitFor({ timeout: 5000 });
if (await modal.getByRole('button', { name: 'Upload original' }).count()) throw new Error('Forced editor offered an original-file bypass.');
if (await modal.locator('select[aria-label="Crop aspect ratio"]').inputValue() !== '1:1') throw new Error('Forced editor did not select the configured aspect preset.');
await modal.getByRole('button', { name: 'Rotate right' }).click();
await modal.getByRole('button', { name: 'Flip horizontally' }).click();
await modal.getByRole('button', { name: 'Use edited image' }).click();
await forced.locator('[data-opf-upload-status]').filter({ hasText: 'uploaded.' }).waitFor({ timeout: 10000 });
if (!uploads[0]?.token || !(uploads[0].size < image.length)) throw new Error(`Forced crop was not applied before upload: ${JSON.stringify(uploads[0])}`);
await forced.getByRole('button', { name: 'Remove forced.png' }).click();
await forced.locator('[data-opf-upload-token]').waitFor({ state: 'detached', timeout: 5000 });

const optional = fields.nth(1);
await optional.locator('input[type="file"]').setInputFiles({ name: 'optional.png', mimeType: 'image/png', buffer: image });
await optional.locator('[data-opf-upload-status]').filter({ hasText: 'uploaded.' }).waitFor({ timeout: 10000 });
if (!uploads[1]?.token || uploads[1].size !== image.length) throw new Error(`Optional editor did not preserve the original-file choice: ${JSON.stringify(uploads[1])}`);
if (await page.locator('dialog[open]').count()) throw new Error('Optional editor opened automatically before the user chose Edit.');
const optionalTokenInput = optional.locator('[data-opf-upload-token]');
const originalToken = await optionalTokenInput.inputValue();
await optional.getByRole('button', { name: 'Edit optional.png' }).click();
const optionalModal = page.locator('dialog[open]');
await optionalModal.waitFor({ timeout: 5000 });
if (await optionalModal.getByRole('button', { name: 'Upload original' }).count()) throw new Error('Manual Edit offered a redundant second original-file choice.');
await optionalModal.getByRole('button', { name: 'Cancel' }).click();
if (await optionalTokenInput.inputValue() !== originalToken) throw new Error('Canceling optional Edit replaced the original staged token.');
await optional.getByRole('button', { name: 'Edit optional.png' }).click();
const manualEdit = page.locator('dialog[open]');
await manualEdit.waitFor({ timeout: 5000 });
const replacementResponse = page.waitForResponse((response) => response.url().includes('/opf/v1/uploads') && response.request().method() === 'POST' && response.request().resourceType() === 'xhr' && response.status() === 200, { timeout: 10000 });
await manualEdit.getByRole('button', { name: 'Use edited image' }).click();
const replacementResult = await (await replacementResponse).json();
if (!replacementResult?.token || replacementResult.token === originalToken || !(replacementResult.size < image.length)) throw new Error(`Manual Edit did not safely replace the original staged image: ${JSON.stringify(replacementResult)}`);
if (await optional.locator('[data-opf-upload-token]').count() !== 1 || await optionalTokenInput.inputValue() !== replacementResult.token) throw new Error('Manual Edit left duplicate or stale upload tokens in the field.');
await optional.getByRole('button', { name: 'Remove optional.png' }).click();
await optional.locator('[data-opf-upload-token]').waitFor({ state: 'detached', timeout: 5000 });
await forced.locator('input[type="file"]').setInputFiles({ name: 'broken.png', mimeType: '', buffer: Buffer.from('not an image') });
await forced.locator('[data-opf-upload-status]').filter({ hasText: 'not a readable image' }).waitFor({ timeout: 5000 });
if (await forced.locator('[data-opf-upload-token]').count()) throw new Error('Forced editor accepted undecodable bytes with blank MIME.');
await optional.locator('input[type="file"]').setInputFiles({ name: 'original.txt', mimeType: '', buffer: Buffer.from('not an image') });
try {
	await optional.locator('[data-opf-upload-status]').filter({ hasText: 'file type that is not allowed' }).waitFor({ timeout: 10000 });
} catch (error) {
	throw new Error(`Optional blank-MIME decode fallback status was not the server allow-list response: ${await optional.locator('[data-opf-upload-status]').textContent()}; responses=${JSON.stringify(uploads)}`);
}
if (await optional.locator('[data-opf-upload-token]').count()) throw new Error('Optional editor did not submit original bytes after decode failure.');
if (errors.length) throw new Error(`Browser errors: ${errors.join('; ')}`);
console.log('Forced editor blocked original bypass and applied 1:1 crop before upload.');
console.log('Generated 100×56 16:9 PNG passed direct authoritative REST validation and storage.');
console.log('Optional editor uploaded original bytes immediately; manual Edit replaced its staged token, and Cancel preserved the original.');
console.log('Rotate and flip controls worked; forced decode failure rejected; optional decode failure passed original to server policy.');
console.log('Staged files were removed and no uncaught JS errors occurred.');
await browser.close();
