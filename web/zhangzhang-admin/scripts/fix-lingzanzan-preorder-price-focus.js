#!/usr/bin/env node
"use strict";

/**
 * 預購打單「客戶單價」每打一個字就整列重畫，輸入框被清掉所以要連點才能輸入。
 * 改成只改小計與總額，不重畫整列。
 *
 * Live: assets/admin.js, assets/admin.css
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260822-price-focus-1";
const JS_MARKER = "syncManualPreorderTotals";
const CSS_MARKER = "/* 20260822 preorder price focus: keep 客戶單價 editable */";

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

function stampHtml(dir) {
  const names = [
    "admin-preorders.html",
    "admin-orders.html",
    "admin-live.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const RENDER_OLD = `  function renderManualPreorderLines() {
    var box = document.querySelector('[data-manual-preorder-lines]');
    var total = document.querySelector('[data-manual-preorder-total]');
    var productSubtotal = manualPreorderItems.reduce(function (sum, item) {
      return sum + Number(item.price || 0) * Number(item.qty || 0);
    }, 0);
    var form = document.querySelector('[data-manual-preorder-form]');
    applyManualPreorderShippingFee(form, false, true, true);
    var shippingTotal = Math.max(0, Number((form && form.querySelector('[name="shippingFee"]') || {}).value || 0));
    var grandTotal = productSubtotal + shippingTotal;
    if (total) total.textContent = orderMoney(grandTotal);
    var productSubtotalEl = document.querySelector('[data-manual-preorder-products-subtotal]');
    var shippingTotalEl = document.querySelector('[data-manual-preorder-shipping-total]');
    var grandTotalEl = document.querySelector('[data-manual-preorder-grand-total]');
    if (productSubtotalEl) productSubtotalEl.textContent = orderMoney(productSubtotal);
    if (shippingTotalEl) shippingTotalEl.textContent = orderMoney(shippingTotal);
    if (grandTotalEl) grandTotalEl.textContent = orderMoney(grandTotal);
    renderManualPreorderPayment();
    if (!box) return;
    box.innerHTML = manualPreorderItems.map(function (item, index) {`;

const RENDER_NEW = `  function syncManualPreorderTotals() {
    var total = document.querySelector('[data-manual-preorder-total]');
    var productSubtotal = manualPreorderItems.reduce(function (sum, item) {
      return sum + Number(item.price || 0) * Number(item.qty || 0);
    }, 0);
    var form = document.querySelector('[data-manual-preorder-form]');
    applyManualPreorderShippingFee(form, false, true, true);
    var shippingTotal = Math.max(0, Number((form && form.querySelector('[name="shippingFee"]') || {}).value || 0));
    var grandTotal = productSubtotal + shippingTotal;
    if (total) total.textContent = orderMoney(grandTotal);
    var productSubtotalEl = document.querySelector('[data-manual-preorder-products-subtotal]');
    var shippingTotalEl = document.querySelector('[data-manual-preorder-shipping-total]');
    var grandTotalEl = document.querySelector('[data-manual-preorder-grand-total]');
    if (productSubtotalEl) productSubtotalEl.textContent = orderMoney(productSubtotal);
    if (shippingTotalEl) shippingTotalEl.textContent = orderMoney(shippingTotal);
    if (grandTotalEl) grandTotalEl.textContent = orderMoney(grandTotal);
    renderManualPreorderPayment();
  }

  function renderManualPreorderLines() {
    var box = document.querySelector('[data-manual-preorder-lines]');
    syncManualPreorderTotals();
    if (!box) return;
    box.innerHTML = manualPreorderItems.map(function (item, index) {`;

const HANDLER_OLD = `      if (event.target && event.target.matches && event.target.matches('[data-manual-preorder-line-price]')) {
        var preorderPriceIndex = Number(event.target.getAttribute('data-manual-preorder-line-price'));
        if (manualPreorderItems[preorderPriceIndex]) {
          manualPreorderItems[preorderPriceIndex].price = Math.max(0, Number(event.target.value === '' ? 0 : event.target.value));
          renderManualPreorderLines();
        }
      }`;

const HANDLER_NEW = `      if (event.target && event.target.matches && event.target.matches('[data-manual-preorder-line-price]')) {
        var preorderPriceIndex = Number(event.target.getAttribute('data-manual-preorder-line-price'));
        if (manualPreorderItems[preorderPriceIndex]) {
          manualPreorderItems[preorderPriceIndex].price = Math.max(0, Number(event.target.value === '' ? 0 : event.target.value));
          var preorderPriceHost = event.target.closest('.manual-preorder-line-info');
          var preorderPriceSub = preorderPriceHost && preorderPriceHost.querySelector('em');
          if (preorderPriceSub) {
            preorderPriceSub.textContent = '小計 ' + orderMoney(Number(manualPreorderItems[preorderPriceIndex].qty || 0) * Number(manualPreorderItems[preorderPriceIndex].price || 0));
          }
          syncManualPreorderTotals();
        }
      }`;

const CSS_APPEND = `

${CSS_MARKER}
.manual-preorder-line-info [data-manual-preorder-line-price] {
  position: relative;
  z-index: 3;
  width: 120px;
  min-width: 120px;
  min-height: 44px !important;
  pointer-events: auto;
}
`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup admin js", backup(ADMIN_JS, "price-focus"));
let admin = fs.readFileSync(ADMIN_JS, "utf8");
if (admin.indexOf(JS_MARKER) !== -1 && admin.indexOf("preorderPriceHost") !== -1) {
  console.log("admin js already patched");
} else {
  admin = replaceOnce(admin, RENDER_OLD, RENDER_NEW, "split preorder totals from line redraw");
  admin = replaceOnce(admin, HANDLER_OLD, HANDLER_NEW, "price input keeps the field");
  fs.writeFileSync(ADMIN_JS, admin, "utf8");
}
if (admin.indexOf(JS_MARKER) === -1) throw new Error("syncManualPreorderTotals missing");
if (admin.indexOf("preorderPriceHost") === -1) throw new Error("in-place price paint missing");
if (/data-manual-preorder-line-price[\s\S]{0,280}renderManualPreorderLines\(\)/.test(admin)) {
  throw new Error("price input still rebuilds the line list");
}

console.log("backup admin css", backup(ADMIN_CSS, "price-focus"));
let css = fs.readFileSync(ADMIN_CSS, "utf8");
if (css.indexOf(CSS_MARKER) !== -1) {
  console.log("admin css already patched");
} else {
  fs.writeFileSync(ADMIN_CSS, css.replace(/\s*$/, "\n") + CSS_APPEND, "utf8");
  console.log("patched: price input hit area");
}

stampHtml(ROOT);
console.log("LINGZANZAN preorder price focus ok", STAMP);
