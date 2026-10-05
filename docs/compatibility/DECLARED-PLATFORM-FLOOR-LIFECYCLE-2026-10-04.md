# Declared platform floor lifecycle — 2026-10-04

At OPF `54d5a46cd5ad0624fe236f3b8abeb983fe4baa8c`, a disposable install ran
on PHP 7.4.33, WordPress 6.5, and WooCommerce 9.0.0. The packages match the
SHA-256 values already recorded in
[`WAPF-MINIMUM-PLATFORM-EVIDENCE.md`](WAPF-MINIMUM-PLATFORM-EVIDENCE.md).
The PHP image was the official `wordpress:php7.4-apache` image pinned by digest;
WordPress core was replaced with the exact downloaded 6.5 archive, and the
database ran in a separately named MariaDB 10.11 container.

## Result

OPF activated alongside WooCommerce with PHP 7.4.33. A real Chromium session
loaded the field-group builder, saved a group through the authenticated
`opf/v1/groups` REST route, and previewed it through `opf/v1/preview`. Both
routes returned 200 with rendered data. Error contracts also held: an
unsupported schema returned 400 and previewing a nonexistent product returned
404.

The same published group rendered on a product page. Classic form submission
and WooCommerce Store API submission each carried an OPF value through cart,
checkout, and persisted order item metadata. Each order reloaded from the
WooCommerce order data store. After setting the test orders to `completed`, the
real account page exposed WooCommerce's Order Again action; both transports
restored the OPF selection to the cart. Chromium reported no uncaught page
errors. The full assertion list and package/image digests are in
[`DECLARED-PLATFORM-FLOOR-LIFECYCLE-2026-10-04.json`](evidence/DECLARED-PLATFORM-FLOOR-LIFECYCLE-2026-10-04.json).

This closes the unproved lifecycle portion of OPF's declared platform floor.
WAPF Pro/Extended's PHP 7.1 floor, WooCommerce 7.0 integration, and Free's
older published claims stay version-bounded evidence rather than queued work:
owner decision D2 (2026-10-05) accepted OPF's higher floor, so
`WAPF-COMPAT-MINIMUM-PLATFORM` is a documented difference and no platform
declaration changed. The stack and
browser harness were disposable `/tmp` artifacts rather than a checked-in
provisioning script.

## Cleanup

The isolated WordPress database contained only this fixture. Its product,
field group, checkout/account/cart pages, and test orders were deleted, then the
two test containers and private Docker network were removed. The source
archives, isolated WordPress files, browser output, and fixture scripts were
also deleted. The run used only `127.0.0.1:8620`; no production site or server
database was connected.
