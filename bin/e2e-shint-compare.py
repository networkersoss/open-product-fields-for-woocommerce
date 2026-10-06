#!/usr/bin/env python3
"""Storefront choice-hint seam comparison.

Reads the pre-fix baseline and the post-fix storefront capture and writes the
side-by-side OPF-vs-WAPF rows, the before/after OPF rows and the no-currency
regression flag. Numeric comparison strips the presentation (spaces, brackets,
entity encoding) so OPF's own money format is compared by value.
"""
import json
import re
import sys
from pathlib import Path

out = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent / "docs/compatibility/storefront-hint-20261005"
before = json.loads((out / "baseline/storefront-results.json").read_text())
after = json.loads((out / "storefront-results.json").read_text())


def case_rows(doc, leg):
    return doc["legs"][leg]["cases"]


def hints(case):
    return [h["text"] for h in case.get("choiceHints", [])]


def decode(s):
    return (s.replace("&euro;", "€").replace("&#36;", "$").replace("&nbsp;", " ")
            .replace("&amp;", "&"))


def money_numbers(values):
    nums = []
    for v in values:
        for m in re.findall(r"-?\d+(?:\.\d+)?", decode(v)):
            nums.append(float(m))
    return nums


def surface(case):
    return {
        "choice_hints": hints(case),
        "pills": case.get("pills", []),
        "options": case.get("options", []),
        "price_data": case.get("priceData", []),
        "preview_totals": case.get("totals", ""),
        "totals_price_attr": case.get("totalsPriceAttr"),
    }


cases = ["fixed", "percent", "minmax", "date"]

# --- before / after OPF under CURCY -----------------------------------------
before_after = {"currency": "EUR", "rate": 1.5, "cases": {}}
for c in cases:
    b = surface(case_rows(before, "opf")[c])
    a = surface(case_rows(after, "opf")[c])
    before_after["cases"][c] = {
        "before": b, "after": a,
        "choice_hint_before_value": money_numbers(b["choice_hints"]),
        "choice_hint_after_value": money_numbers(a["choice_hints"]),
        "preview_totals_unchanged": b["preview_totals"] == a["preview_totals"],
    }
(out / "before-after-opf.json").write_text(json.dumps(before_after, indent=2, ensure_ascii=False))

# --- OPF vs WAPF after under CURCY ------------------------------------------
side = {"currency": "EUR", "rate": 1.5, "note": "choice_hints only exist on OPF; WAPF has no JS-populated per-choice hint", "cases": {}}
for c in cases:
    o = surface(case_rows(after, "opf")[c])
    w = surface(case_rows(after, "wapf")[c])
    side["cases"][c] = {
        "opf": o, "wapf": w,
        "preview_totals_match": o["preview_totals"] == w["preview_totals"],
        "opf_static_pills_match_wapf_pills": o["pills"] == w["pills"],
    }
side["all_preview_totals_match"] = all(v["preview_totals_match"] for v in side["cases"].values())
(out / "opf-vs-wapf-hints.json").write_text(json.dumps(side, indent=2, ensure_ascii=False))

# --- no-currency regression: before vs after --------------------------------
nocur = {"currency": "USD", "identical": True, "cases": {}}
for c in cases:
    b = surface(case_rows(before, "opf_nocurcy")[c])
    a = surface(case_rows(after, "opf_nocurcy")[c])
    same = b == a
    nocur["identical"] = nocur["identical"] and same
    nocur["cases"][c] = {"before": b, "after": a, "identical": same}
(out / "no-currency-regression.json").write_text(json.dumps(nocur, indent=2, ensure_ascii=False))

print(json.dumps({
    "before_after": {c: {"hint_before": before_after["cases"][c]["choice_hint_before_value"],
                         "hint_after": before_after["cases"][c]["choice_hint_after_value"],
                         "totals_unchanged": before_after["cases"][c]["preview_totals_unchanged"]} for c in cases},
    "all_preview_totals_match": side["all_preview_totals_match"],
    "preview_totals": {c: side["cases"][c]["opf"]["preview_totals"] for c in cases},
    "no_currency_identical": nocur["identical"],
}, indent=2))
