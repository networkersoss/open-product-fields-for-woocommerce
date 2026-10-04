// Real storefront add-to-cart regression proof for the disposable clone.
import { createRequire } from 'node:module';
const require = createRequire(process.cwd() + '/index.js');
const { chromium } = require('playwright');

const base = process.env.OPF_WAPF_COEX_BASE_URL || 'http://127.0.0.1:8249';
const mode = process.env.OPF_WAPF_COEX_MODE;
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw new Error('Loopback clone required.');
if (!['active', 'opf-only'].includes(mode)) throw new Error('Set OPF_WAPF_COEX_MODE to active or opf-only.');

const browser = await chromium.launch();
const context = await browser.newContext();
const page = await context.newPage();
const failedRequests = [];
page.on('requestfailed', request => failedRequests.push(request.method() + ' ' + request.url() + ': ' + request.failure()?.errorText));

try {
  const response = await page.goto(base + '/product/opf-wapf-validation-coexistence/', { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error('Product page returned ' + (response?.status() ?? 'no response') + '.');
  const note = page.locator('[data-opf-field="required-note"] input, [data-opf-field="required-note"] textarea').first();
  const quantity = page.locator('[data-opf-field="prints"] input[type="number"]').first();
  await note.waitFor({ state: 'visible' });
  await quantity.waitFor({ state: 'visible' });
  await quantity.fill('2');

  if ('active' === mode) {
    await note.fill('Checked sample');
    const submitResponse = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/product/opf-wapf-validation-coexistence/'));
    await page.locator('form.cart button.single_add_to_cart_button').click();
    const response = await submitResponse;
    if (response.status() !== 200) throw new Error('Add-to-cart form POST returned HTTP ' + response.status() + '.');
    await page.locator('.woocommerce-message, .wc-block-components-notice-banner.is-success').first().waitFor({ timeout: 15000 });
    const cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
    if (!cartResponse.ok()) throw new Error('WooCommerce cart endpoint returned ' + cartResponse.status() + '.');
    const cart = await cartResponse.json();
    const product = cart.items?.find(item => item.slug === 'opf-wapf-validation-coexistence' || item.name === 'OPF WAPF Validation Coexistence');
    if (!product || product.quantity !== 1) throw new Error('Successful add-to-cart did not persist one fixture product.');
    console.log('ok WAPF-active real storefront add-to-cart succeeds with OPF required text and image quantity fields');
    console.log('ok classic WooCommerce form POST returned HTTP ' + response.status());
    console.log('ok WooCommerce Store API cart contains fixture product');
    const clearResponse = await context.request.delete(base + '/wp-json/wc/store/v1/cart/items', {
      headers: cartResponse.headers()['nonce'] ? { Nonce: cartResponse.headers()['nonce'] } : {},
    });
    if (!clearResponse.ok()) throw new Error('Could not empty the isolated browser cart; Store API returned ' + clearResponse.status() + '.');
    const emptyCartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
    const emptyCart = await emptyCartResponse.json();
    if (emptyCart.items?.length) throw new Error('Isolated browser cart cleanup left items behind.');
    console.log('ok isolated browser cart emptied; ' + failedRequests.length + ' failed browser requests');
  } else {
    await page.locator('form.cart').evaluate(form => {
      const submit = document.createElement('input');
      submit.type = 'hidden';
      submit.name = 'add-to-cart';
      submit.value = form.querySelector('button[name="add-to-cart"]').value;
      form.appendChild(submit);
      HTMLFormElement.prototype.submit.call(form);
    });
    await page.locator('.woocommerce-error, .wc-block-components-notice-banner.is-error').first().waitFor({ timeout: 15000 });
    const cartResponse = await context.request.get(base + '/wp-json/wc/store/v1/cart');
    if (!cartResponse.ok()) throw new Error('WooCommerce cart endpoint returned ' + cartResponse.status() + '.');
    const cart = await cartResponse.json();
    if (cart.items?.some(item => item.slug === 'opf-wapf-validation-coexistence' || item.name === 'OPF WAPF Validation Coexistence')) {
      throw new Error('OPF accepted the add-to-cart request with its required field missing.');
    }
    console.log('ok OPF-only real storefront validation rejects a missing required field');
    console.log('ok WooCommerce Store API cart has no fixture product; ' + failedRequests.length + ' failed browser requests');
  }
} finally {
  await context.close();
  await browser.close();
}
