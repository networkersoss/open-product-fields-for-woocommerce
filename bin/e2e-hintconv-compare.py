#!/usr/bin/env python3
"""Hint-conversion lane comparison: builds the staged side-by-side artifacts
from the raw browser runs.

Inputs (all under docs/compatibility/hint-conversion-20261005/):
  browser-results.json          fixed OPF + WAPF, CURCY on (EUR) and off (USD)
  baseline/browser-results.json pristine HEAD OPF, CURCY on (EUR) and off (USD)

Outputs:
  opf-vs-wapf-hints.json        fixed OPF vs WAPF, cart/checkout/order hints
  before-after-opf.json         pristine vs fixed OPF (converted + base currency)
  no-currency-regression.json   pristine vs fixed OPF with CURCY inactive
"""
import json
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[1] / "docs/compatibility/hint-conversion-20261005"
fixed = json.loads((ROOT / "browser-results.json").read_text())
base = json.loads((ROOT / "baseline/browser-results.json").read_text())

CASES = ["fixed", "percent", "minmax", "date"]


def cart_display(row, cid):
    line = (row["cases"][cid].get("cart_line") or {})
    return [d.get("display", "") for d in line.get("item_data", [])]


def cart_rows(row):
    return row["cart_page"]["rows"]


def checkout_rows(row):
    return row["checkout_page"]["rows"]


def order_visible(row, cid):
    return (row.get("order_hints") or {}).get(cid, {}).get("visible_meta", [])


def storefront(row, cid):
    sf = row["cases"][cid]["storefront_hints"]
    return {"spans": sf["spans"], "options": sf["options"]}


# ---- 1. OPF vs WAPF (fixed, CURCY active, EUR) ------------------------------
o, w = fixed["legs"]["opf"], fixed["legs"]["wapf"]
rows = []
for cid in CASES:
    c = o["cases"][cid]
    rows.append({
        "case": cid,
        "kind": c["kind"],
        "addon_base_usd": c["addon_base"],
        "line_unit_eur": c["unit_eur"],
        "storefront": {"opf": storefront(o, cid), "wapf": storefront(w, cid)},
        "cart_field_display": {"opf": cart_rows(o)[CASES.index(cid)], "wapf": cart_rows(w)[CASES.index(cid)]},
        "checkout_field_display": {"opf": checkout_rows(o)[CASES.index(cid)], "wapf": checkout_rows(w)[CASES.index(cid)]},
        "cart_item_data": {"opf": cart_display(o, cid), "wapf": cart_display(w, cid)},
        "order_visible_meta": {"opf": order_visible(o, cid), "wapf": order_visible(w, cid)},
        "cart_line_eur": {
            "opf": (o["cases"][cid]["cart_line"] or {}).get("unit_price"),
            "wapf": (w["cases"][cid]["cart_line"] or {}).get("unit_price"),
        },
        "cart_display_match": cart_rows(o)[CASES.index(cid)] == cart_rows(w)[CASES.index(cid)],
        "checkout_display_match": checkout_rows(o)[CASES.index(cid)] == checkout_rows(w)[CASES.index(cid)],
        "order_meta_match": order_visible(o, cid) == order_visible(w, cid),
        "cart_line_match": (o["cases"][cid]["cart_line"] or {}).get("unit_price") == (w["cases"][cid]["cart_line"] or {}).get("unit_price"),
    })
cmp_out = {
    "currencies": {"base": "USD", "current": "EUR", "curcy_rate": 1.5, "base_product_price_usd": 10, "quantity": 1, "taxes": "off"},
    "orders": {"opf": o.get("order_id"), "wapf": w.get("order_id")},
    "order_totals": {"opf": o.get("order_totals"), "wapf": w.get("order_totals")},
    "cart_totals": {"opf": o.get("cart_totals"), "wapf": w.get("cart_totals")},
    "rows": rows,
    "all_cart_display_match": all(r["cart_display_match"] for r in rows),
    "all_checkout_display_match": all(r["checkout_display_match"] for r in rows),
    "all_order_meta_match": all(r["order_meta_match"] for r in rows),
    "all_cart_line_match": all(r["cart_line_match"] for r in rows),
}
(ROOT / "opf-vs-wapf-hints.json").write_text(json.dumps(cmp_out, indent=2) + "\n")

# ---- 2. before/after OPF (pristine HEAD vs fixed) --------------------------
bo, fo = base["legs"]["opf"], fixed["legs"]["opf"]
before_after = {
    "curcy": "active, EUR rate 1.5",
    "before": "pristine HEAD includes/Service/{PricingHints,Renderer}.php",
    "after": "this lane's fix",
    "rows": [],
}
for cid in CASES:
    before_after["rows"].append({
        "case": cid,
        "cart_field_display": {"before": cart_rows(bo)[CASES.index(cid)], "after": cart_rows(fo)[CASES.index(cid)]},
        "checkout_field_display": {"before": cart_rows(bo)[CASES.index(cid)], "after": checkout_rows(fo)[CASES.index(cid)]},
        "order_visible_meta": {"before": order_visible(bo, cid), "after": order_visible(fo, cid)},
        "storefront_spans": {"before": storefront(bo, cid)["spans"], "after": storefront(fo, cid)["spans"]},
    })
(ROOT / "before-after-opf.json").write_text(json.dumps(before_after, indent=2) + "\n")

# ---- 3. no-currency regression: pristine vs fixed, CURCY inactive ----------
bn, fn = base["legs"]["opf_nocurcy"], fixed["legs"]["opf_nocurcy"]
reg = {"curcy": "inactive", "currency": "USD", "rows": [], "identical": True}
for cid in CASES:
    same = (
        cart_rows(bn)[CASES.index(cid)] == cart_rows(fn)[CASES.index(cid)]
        and checkout_rows(bn)[CASES.index(cid)] == checkout_rows(fn)[CASES.index(cid)]
        and order_visible(bn, cid) == order_visible(fn, cid)
        and storefront(bn, cid)["spans"] == storefront(fn, cid)["spans"]
        and storefront(bn, cid)["options"] == storefront(fn, cid)["options"]
        and cart_display(bn, cid) == cart_display(fn, cid)
    )
    reg["rows"].append({
        "case": cid,
        "cart_field_display": {"before": cart_rows(bn)[CASES.index(cid)], "after": cart_rows(fn)[CASES.index(cid)]},
        "checkout_field_display": {"before": cart_rows(bn)[CASES.index(cid)], "after": checkout_rows(fn)[CASES.index(cid)]},
        "order_visible_meta": {"before": order_visible(bn, cid), "after": order_visible(fn, cid)},
        "storefront_spans": {"before": storefront(bn, cid)["spans"], "after": storefront(fn, cid)["spans"]},
        "cart_item_data": {"before": cart_display(bn, cid), "after": cart_display(fn, cid)},
        "byte_identical": same,
    })
    reg["identical"] = reg["identical"] and same
reg["order_totals"] = {"before": bn.get("order_totals"), "after": fn.get("order_totals")}
(ROOT / "no-currency-regression.json").write_text(json.dumps(reg, indent=2) + "\n")

print("all_cart_display_match   ", cmp_out["all_cart_display_match"])
print("all_checkout_display_match", cmp_out["all_checkout_display_match"])
print("all_order_meta_match     ", cmp_out["all_order_meta_match"])
print("all_cart_line_match      ", cmp_out["all_cart_line_match"])
print("no-currency byte-identical", reg["identical"])
