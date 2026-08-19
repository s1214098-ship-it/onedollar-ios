#!/usr/bin/env node
"use strict";

/**
 * 預購打單搜尋只載 products/skus，沒載物流預報。
 * SE181 這種還沒建成正式產品的集運款（豪鴻已收到）會打不到。
 * 有搜尋字時才載 freight tracking，開頁仍不先抓 7MB。
 *
 * Cache-bust: admin.js ?v=20260819-preorder-freight-search-2
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260819-preorder-freight-search-2";
const MARKER = "state.freightTracking.__lingzanzanFreightNormalized === true";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
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

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    const html = fs.readFileSync(file, "utf8");
    if (!/admin\.js/.test(html)) continue;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, next);
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const OLD = `    var searchText = String(input.value || '').trim();
    if (!catalogLoaded) {
      if (!searchText) {
        box.hidden = true;
        box.innerHTML = '';
        return;
      }
      box.hidden = false;
      box.innerHTML = '<p>商品資料載入中，完成後會自動顯示搜尋結果。</p>';
      ensureAdminPreorderCatalogData(false).then(function () {
        renderManualPreorderSuggestGrouped();
      }).catch(function () {
        box.innerHTML = '<p>商品資料載入失敗，請重新整理後再試。</p>';
      });
      return;
    }`;

const READY_OLD = `    var freightReady = !!(state.freightTracking && Array.isArray(state.freightTracking.items));`;
const READY_NEW = `    var freightReady = !!(state.freightTracking && state.freightTracking.__lingzanzanFreightNormalized === true);`;

const NEW = `    var searchText = String(input.value || '').trim();
    var freightReady = !!(state.freightTracking && state.freightTracking.__lingzanzanFreightNormalized === true);
    if (!catalogLoaded || (searchText && !freightReady)) {
      if (!searchText) {
        box.hidden = true;
        box.innerHTML = '';
        return;
      }
      box.hidden = false;
      box.innerHTML = !catalogLoaded
        ? '<p>商品資料載入中，完成後會自動顯示搜尋結果。</p>'
        : '<p>正在載入集運預報商品，完成後會顯示搜尋結果。</p>';
      ensureAdminPreorderCatalogData(true).then(function () {
        renderManualPreorderSuggestGrouped();
      }).catch(function () {
        box.innerHTML = '<p>商品資料載入失敗，請重新整理後再試。</p>';
      });
      return;
    }`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "preorder-freight-search"));
let src = fs.readFileSync(ADMIN_JS, "utf8");
if (src.indexOf("正在載入集運預報商品，完成後會顯示搜尋結果。") === -1) {
  src = replaceOnce(src, OLD, NEW, "preorder search loads freight forecast");
} else if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(src, READY_OLD, READY_NEW, "freight ready uses loaded flag not empty array");
} else {
  console.log("js already patched");
}
fs.writeFileSync(ADMIN_JS, src);
console.log("js written", src.length);
stampHtml(ROOT);
console.log("LINGZANZAN preorder freight search:", STAMP);
