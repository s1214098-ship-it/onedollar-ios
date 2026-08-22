#!/usr/bin/env node
"use strict";

/**
 * 訂單追蹤卡「本單購買 N 件／M 款」先前只放第一款縮圖。
 * 改成每款一列：自己的照片 + 款／色／尺碼／件數。
 *
 * Cache-bust: admin.js / admin-order-tracking.css ?v=20260819-item-thumbs-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const CSS = path.join(ROOT, "assets", "admin-order-tracking.css");
const RISK_CSS = path.join(ROOT, "assets", "member-risk-v2.css");
const STAMP = "20260819-item-thumbs-1";
const JS_MARKER = "function orderTrackingPurchaseRowsHtml(";
const CSS_MARKER = "/* 20260819 each purchased style has its own thumb */";

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
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const HELPER = `  function orderTrackingPurchaseRowsHtml(items) {
    items = Array.isArray(items) ? items : [];
    var qty = items.reduce(function (sum, item) {
      return sum + Math.max(0, Number(item && (item.qty || item.quantity) || 0));
    }, 0);
    var rows = items.map(function (item) {
      item = item || {};
      var image = typeof orderTrackingItemImage === 'function' ? orderTrackingItemImage(item) : String(item.colorImage || item.image || item.photo || '');
      var line = orderTrackingPurchaseLineText(item);
      var label = item.code || item.title || item.name || '商品';
      var thumb = image
        ? '<button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(image) + '" aria-label="放大查看 ' + escapeHtml(label) + ' 商品圖片"><img src="' + escapeHtml(image) + '" alt="' + escapeHtml(label) + ' 商品縮圖" loading="lazy"></button>'
        : '<span class="order-tracking-product-thumb is-empty">無圖</span>';
      return '<article class="order-tracking-purchase-row">' + thumb + '<small class="order-tracking-purchase-lines">' + escapeHtml(line || '商品資料待補') + '</small></article>';
    }).join('');
    if (!rows) rows = '<small class="order-tracking-purchase-lines">本單商品資料待補</small>';
    return '<span class="order-tracking-product-copy"><b>本單購買 ' + qty + ' 件／' + items.length + ' 款</b>' + rows + '</span>';
  }

  function orderTrackingCompactCardHtml(entry, now) {`;

const COMPACT_OLD = `'<div class="order-tracking-products"><button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(image) + '" aria-label="放大查看商品圖片"><img src="' + escapeHtml(image) + '" alt="商品縮圖" loading="lazy"></button><span class="order-tracking-product-copy"><b>本單購買 ' + qty + ' 件／' + items.length + ' 款</b><small class="order-tracking-purchase-lines">' + escapeHtml(itemSummary || '本單商品資料待補') + '</small></span></div>',`;

const COMPACT_NEW = `'<div class="order-tracking-products">' + orderTrackingPurchaseRowsHtml(items) + '</div>',`;

const EXPANDED_OLD = `'<div class="order-tracking-products"><button type="button" class="order-tracking-product-thumb" data-order-photo="' + escapeHtml(firstItemImage) + '" aria-label="放大查看 ' + escapeHtml(firstItemLabel) + ' 商品圖片"><img src="' + escapeHtml(firstItemImage) + '" alt="' + escapeHtml(firstItemLabel) + ' 商品縮圖" loading="lazy"></button><span class="order-tracking-product-copy"><b>本單購買 ' + qty + ' 件／' + items.length + ' 款</b><small class="order-tracking-purchase-lines">' + escapeHtml(firstItems || '本單商品資料待補') + '</small></span></div>',`;

const EXPANDED_NEW = `'<div class="order-tracking-products">' + orderTrackingPurchaseRowsHtml(items) + '</div>',`;

const CSS_PATCH = `
${CSS_MARKER}
.order-tracking-products{grid-template-columns:minmax(0,1fr);align-items:start}
.order-tracking-product-copy{gap:8px}
.order-tracking-purchase-row{display:grid;grid-template-columns:58px minmax(0,1fr);gap:10px;align-items:center}
.order-tracking-purchase-row .order-tracking-purchase-lines{margin-top:0;color:#f3efe6;white-space:normal;line-height:1.4}
.order-tracking-product-thumb.is-empty{display:grid;place-items:center;color:#9aa7a3;font-size:11px;font-weight:800}
`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/admin\.js|admin-order-tracking\.css/.test(html)) continue;
    const next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, `admin.js?v=${STAMP}`)
      .replace(/admin-order-tracking\.css(?:\?v=[^"']+)?/g, `admin-order-tracking.css?v=${STAMP}`);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n++;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

function main() {
  console.log("backup js", backup(JS, "item-thumbs"));
  console.log("backup css", backup(CSS, "item-thumbs"));
  let js = fs.readFileSync(JS, "utf8");
  if (js.includes(JS_MARKER)) {
    console.log("js already patched");
  } else {
    js = replaceOnce(
      js,
      "  function orderTrackingCompactCardHtml(entry, now) {",
      HELPER,
      "purchase rows helper"
    );
    js = replaceOnce(js, COMPACT_OLD, COMPACT_NEW, "compact multi thumbs");
    js = replaceOnce(js, EXPANDED_OLD, EXPANDED_NEW, "expanded multi thumbs");
    fs.writeFileSync(JS, js);
    console.log("js written", js.length);
    try {
      new Function(js);
      console.log("js syntax ok");
    } catch (error) {
      throw new Error("admin.js syntax: " + error.message);
    }
  }

  let css = fs.readFileSync(CSS, "utf8");
  if (css.includes(CSS_MARKER)) {
    console.log("css already patched");
  } else {
    fs.writeFileSync(CSS, css.replace(/\s*$/, "") + "\n" + CSS_PATCH);
    console.log("css patched", fs.statSync(CSS).size);
  }
  if (fs.existsSync(RISK_CSS)) {
    let risk = fs.readFileSync(RISK_CSS, "utf8");
    if (!risk.includes(CSS_MARKER)) {
      fs.writeFileSync(RISK_CSS, risk.replace(/\s*$/, "") + "\n" + CSS_PATCH);
      console.log("risk css patched");
    }
  }
  stampHtml(ROOT);
}

main();
