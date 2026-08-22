#!/usr/bin/env node
"use strict";

/**
 * 預購搜尋 K393 會閃跳：條碼被寫成商品代號，只命中白紅 L；
 * 集運載入完又用未分組清單整份重畫。改走分組搜尋、展開同商品所有尺寸。
 * 不 stamp HTML。
 */

const fs = require("fs");
const path = require("path");
const {
  preorderSuggestShouldUseGrouped,
  preorderSearchExpandsProductCode,
  preorderEnterDoesNotAutopickFirst,
} = require("./lz-preorder-k393-search");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log("already:", label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, "\r\n");
    to = newStr.replace(/\n/g, "\r\n");
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

const SEARCH_OLD = `  function manualPreorderSearchRows(query) {
    var q = String(query || '').trim().toLowerCase();
    var rows = manualPreorderSkuRows();
    var exactRows = manualPreorderExactRows(query, rows);
    if (exactRows.length) return exactRows.slice(0, 12);`;

const SEARCH_NEW = `  function manualPreorderSearchRows(query) {
    var q = String(query || '').trim().toLowerCase();
    var rows = manualPreorderSkuRows();
    var productCodeRows = (rows || []).filter(function (row) {
      var code = String(row && row.product && (row.product.code || row.product.productLine) || '').trim().toUpperCase();
      return code && code === String(query || '').trim().toUpperCase();
    });
    if (productCodeRows.length > 1) return manualPreorderStableSortRows(productCodeRows).slice(0, 40);
    var exactRows = manualPreorderExactRows(query, rows);
    if (exactRows.length) return exactRows.slice(0, 12);`;

const SUGGEST_OLD = `  function renderManualPreorderSuggest() {
    var input = document.querySelector('[data-manual-preorder-search]');
    var box = document.querySelector('[data-manual-preorder-suggest]');
    if (!input || !box) return;
    var rows = manualPreorderSearchRows(input.value);`;

const SUGGEST_NEW = `  function renderManualPreorderSuggest() {
    renderManualPreorderSuggestGrouped();
    return;
    var input = document.querySelector('[data-manual-preorder-search]');
    var box = document.querySelector('[data-manual-preorder-suggest]');
    if (!input || !box) return;
    var rows = manualPreorderSearchRows(input.value);`;

const ENTER_OLD = `        if (directRows.length === 1) {
          selectManualPreorderSku(manualPreorderSkuKey(directRows[0]));
        } else {
          renderManualPreorderSuggest();
          var firstPick = document.querySelector('[data-manual-preorder-pick]');
          if (firstPick) selectManualPreorderSku(firstPick.getAttribute('data-manual-preorder-pick'));
        }`;

const ENTER_NEW = `        if (directRows.length === 1) {
          selectManualPreorderSku(manualPreorderSkuKey(directRows[0]));
        } else {
          renderManualPreorderSuggest();
        }`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "preorder-k393-search"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (preorderSuggestShouldUseGrouped(js) && preorderSearchExpandsProductCode(js) && preorderEnterDoesNotAutopickFirst(js)) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, SEARCH_OLD, SEARCH_NEW, "expand product-code variants");
  js = replaceOnce(js, SUGGEST_OLD, SUGGEST_NEW, "suggest uses grouped renderer");
  js = replaceOnce(js, ENTER_OLD, ENTER_NEW, "enter does not auto-add first size");
}
if (!preorderSuggestShouldUseGrouped(js)) throw new Error("grouped suggest missing");
if (!preorderSearchExpandsProductCode(js)) throw new Error("product-code expand missing");
if (!preorderEnterDoesNotAutopickFirst(js)) throw new Error("enter autopick still present");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
console.log("LINGZANZAN preorder K393 search ok");
