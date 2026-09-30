import { createRequire } from 'node:module';
const requireFromPlugin = createRequire(process.cwd() + '/index.js');
const { chromium } = requireFromPlugin('playwright');

const base = process.env.OPF_BASE_URL || 'http://127.0.0.1:8091';
const browser = await chromium.launch();
const page = await browser.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
await page.goto(`${base}/product/opf-e2e-number-mode-product/`, { waitUntil: 'domcontentloaded' });

const integer = page.locator('[data-opf-field="count"] input[type="number"]');
const decimal = page.locator('[data-opf-field="weight"] input[type="number"]');
await integer.waitFor({ state: 'visible' });
await decimal.waitFor({ state: 'visible' });
const integerHints = await integer.evaluate((input) => ({ step: input.step, fractionMismatch: (() => { input.value = '2.5'; return input.validity.stepMismatch; })() }));
const decimalHints = await decimal.evaluate((input) => ({ step: input.step, fractionAllowed: (() => { input.value = '1.75'; return input.checkValidity(); })(), misalignedRejected: (() => { input.value = '1.8'; return input.validity.stepMismatch; })() }));
if (integerHints.step !== '1' || !integerHints.fractionMismatch) throw new Error(`Integer browser constraints mismatch: ${JSON.stringify(integerHints)}`);
if (decimalHints.step !== '0.25' || !decimalHints.fractionAllowed || !decimalHints.misalignedRejected) throw new Error(`Decimal browser constraints mismatch: ${JSON.stringify(decimalHints)}`);
if (errors.length) throw new Error(`Browser errors: ${errors.join('; ')}`);
console.log(`Integer input: step=${integerHints.step}, fractional value rejected by browser`);
console.log(`Decimal input: step=${decimalHints.step}, aligned decimal accepted, misaligned value rejected`);
console.log('No uncaught JavaScript errors.');
await browser.close();
