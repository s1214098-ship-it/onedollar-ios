#!/usr/bin/env node
"use strict";

const {
  preorderNormalizeCode,
  preorderRowProductCode,
  preorderRowsForProductCode,
  preorderBarcodeEqualsProductCode,
  preorderSearchExpandProductCode,
  preorderSearchRowsPreferProductCode,
  preorderSuggestShouldUseGrouped,
  preorderSearchExpandsProductCode,
  preorderEnterDoesNotAutopickFirst,
} = require("./lz-preorder-k393-search");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

const k393Rows = [
  { product: { code: "K393", title: "2026世界杯西班牙" }, sku: { id: "K393-TW", barcode: "K393", color: "白紅", size: "L" } },
  { product: { code: "K393", title: "2026世界杯西班牙" }, sku: { id: "SKU-K393-fa9361", barcode: "K393P190923", color: "白色(PUTI)", size: "M" } },
  { product: { code: "K393", title: "2026世界杯西班牙" }, sku: { id: "PREORDER-WAIT:K393:白色(PUTI):L", color: "白色(PUTI)", size: "L" } },
  { product: { code: "K361" }, sku: { id: "K361-01-L", barcode: "K361", color: "黑色(HITEM)", size: "L" } },
];

assert(preorderNormalizeCode(" k393 ") === "K393", "normalizes product code");
assert(preorderRowProductCode(k393Rows[0]) === "K393", "reads product code");
assert(preorderRowsForProductCode(k393Rows, "K393").length === 3, "K393 returns all three variants");
assert(preorderBarcodeEqualsProductCode(k393Rows[0], "K393") === true, "TW sku barcode K393 is just the product code");
assert(preorderBarcodeEqualsProductCode(k393Rows[1], "K393") === false, "white M barcode is not the product code");

const exactOnlyL = [k393Rows[0]];
const expanded = preorderSearchExpandProductCode("K393", k393Rows, exactOnlyL);
assert(expanded.length === 3, "expands generic K393 barcode to M and L");
assert(expanded.some(function (row) { return row.sku.size === "M"; }), "includes white M");
assert(expanded.some(function (row) { return row.sku.size === "L" && String(row.sku.color).indexOf("白") !== -1; }), "includes white L");

const preferred = preorderSearchRowsPreferProductCode("K393", k393Rows, exactOnlyL);
assert(preferred.length === 3, "search prefers product variants over barcode-only L");
assert(preorderSearchRowsPreferProductCode("K393P190923", k393Rows, [k393Rows[1]]).length === 1, "real sku barcode still exact-matches one row");

const patched = [
  "function renderManualPreorderSuggest() {",
  "    renderManualPreorderSuggestGrouped();",
  "var productCodeRows = (rows || []).filter(function (row) {",
  "if (productCodeRows.length > 1) return manualPreorderStableSortRows(productCodeRows).slice(0, 40);",
  "if (directRows.length === 1) {",
  "          selectManualPreorderSku(manualPreorderSkuKey(directRows[0]));",
  "        } else {",
  "          renderManualPreorderSuggest();",
  "        }",
].join("\n");
assert(preorderSuggestShouldUseGrouped(patched) === true, "detects grouped suggest delegate");
assert(preorderSearchExpandsProductCode(patched) === true, "detects product-code expand");
assert(preorderEnterDoesNotAutopickFirst(patched) === true, "detects enter no longer auto-picks first of many");
assert(preorderSuggestShouldUseGrouped("function renderManualPreorderSuggest() {\n    var input") === false, "old ungrouped renderer is not the patch");

console.log("LINGZANZAN preorder K393 search tests ok");
