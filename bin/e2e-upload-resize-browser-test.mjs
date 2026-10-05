import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');
const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
let uploadResult = null;
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', async (response) => {
	if (response.url().includes('/opf/v1/uploads') && response.request().method() === 'POST') {
		try { uploadResult = await response.json(); } catch {}
	}
});
await page.goto(`${base}/product/opf-e2e-upload-resize-product/`, { waitUntil: 'domcontentloaded' });
const uploader = page.locator('[data-opf-upload]');
const input = uploader.locator('input[type="file"]');
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAACCAIAAADwyuo0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAEklEQVQImWP8ICLCAANMDEgAABtqARyVdUwxAAAAAElFTkSuQmCC', 'base64');
await input.setInputFiles({ name: 'four-by-two.png', mimeType: 'image/png', buffer: image });
await page.locator('[data-opf-upload-status]').filter({ hasText: 'uploaded.' }).waitFor({ timeout: 10000 });
if (!uploadResult?.token || !(uploadResult.size < image.length)) throw new Error(`Server did not store a smaller resized result: ${JSON.stringify(uploadResult)}`);
if (errors.length) throw new Error(`Browser errors: ${errors.join('; ')}`);
console.log(`Browser REST upload transformed 4×2 PNG (${image.length} bytes) to smaller server-side file (${uploadResult.size} bytes).`);
console.log('Server dimensions/aspect ratio verified by the matching WP image-editor store E2E.');
await browser.close();
