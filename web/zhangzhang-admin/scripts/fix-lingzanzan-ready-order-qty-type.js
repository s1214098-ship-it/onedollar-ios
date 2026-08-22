#!/usr/bin/env node
"use strict";

/**
 * 現貨打單尺寸卡：數量中間格子與單價要能直接打字，不要擠成直的「單價」鈕。
 * 不改 .is-active 金鈕。
 *
 * Cache-bust: admin.js/css ?v=20260822-ready-qty-1
 */

const fs = require("fs");
const path = require("path");
const {
  readyOrderQtyFieldsAreTypeable,
  readyOrderQtyHintSaysType,
} = require("./lz-ready-order-qty-type");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260822-ready-qty-1";
const CSS_MARKER = "/* 20260822 ready-order type: qty and price stay typeable */";

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
  const names = ["admin-orders.html"];
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

const CSS_PATCH = `
${CSS_MARKER}
.admin-shipment-sku-pick {
  grid-template-columns: minmax(0, 1.3fr) minmax(148px, 168px) minmax(112px, 140px) !important;
  align-items: stretch !important;
}
.admin-shipment-pick-stepper {
  grid-template-columns: 36px minmax(64px, 1fr) 36px !important;
  min-width: 148px !important;
}
.admin-shipment-pick-stepper input {
  width: 100% !important;
  min-width: 64px !important;
  min-height: 40px !important;
  font-size: 18px !important;
  font-weight: 900 !important;
  -moz-appearance: textfield;
}
.admin-shipment-pick-stepper input::-webkit-outer-spin-button,
.admin-shipment-pick-stepper input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.admin-shipment-pick-price {
  min-width: 112px !important;
  writing-mode: horizontal-tb !important;
}
.admin-shipment-pick-price input {
  min-height: 40px !important;
  min-width: 96px !important;
  width: 100% !important;
  font-size: 18px !important;
}
@media (max-width: 720px) {
  .admin-shipment-sku-pick {
    grid-template-columns: 1fr !important;
  }
  .admin-shipment-pick-stepper,
  .admin-shipment-pick-price {
    width: 100% !important;
  }
}
`;

const HINT_OLD = `? '先改各尺寸數量與單價，再一次帶入多件。庫存會顯示三倉對照，但帶入與儲存只扣上方已選出貨倉。'`;
const HINT_NEW = `? '數量點＋－中間那格直接打字，單價也是打字；改完按「一次帶入」。帶入與儲存只扣上方已選出貨倉。'`;

const TOAST_OLD = `toast('請先在各尺寸按 + 選數量，再一次帶入出貨單');`;
const TOAST_NEW = `toast('請先在各尺寸中間格子打數量（或按＋－），再按一次帶入');`;

const QTY_OLD = `<input type="number" min="0" max="' + stock + '" value="0" data-admin-shipment-pick-qty="' + escapeHtml(key) + '"' + (stock <= 0 ? ' disabled' : '') + '>`;
const QTY_NEW = `<input type="number" inputmode="numeric" min="0" max="' + stock + '" value="0" data-admin-shipment-pick-qty="' + escapeHtml(key) + '"' + (stock <= 0 ? ' disabled' : '') + '>`;

const PRICE_OLD = `<label class="admin-shipment-pick-price">單價<input type="number" min="0" step="1" value="' + escapeHtml(price) + '" data-admin-shipment-pick-price="' + escapeHtml(key) + '"></label>`;
const PRICE_NEW = `<label class="admin-shipment-pick-price">單價<input type="number" inputmode="numeric" min="0" step="1" value="' + escapeHtml(price) + '" data-admin-shipment-pick-price="' + escapeHtml(key) + '"></label>`;

if (CSS_PATCH.indexOf(".is-active") !== -1) throw new Error("refusing to restyle .is-active");

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(ADMIN_CSS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "ready-qty");
backup(ADMIN_CSS, "ready-qty");

let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, HINT_OLD, HINT_NEW, "hint says type qty");
js = replaceOnce(js, TOAST_OLD, TOAST_NEW, "toast says type qty");
js = replaceOnce(js, QTY_OLD, QTY_NEW, "qty inputmode numeric");
js = replaceOnce(js, PRICE_OLD, PRICE_NEW, "price inputmode numeric");
fs.writeFileSync(ADMIN_JS, js);

let css = fs.readFileSync(ADMIN_CSS, "utf8");
if (css.indexOf(CSS_MARKER) === -1) {
  css = css.replace(/\s*$/, "") + "\n" + CSS_PATCH;
  console.log("patched: qty/price typeable css");
} else {
  console.log("already: qty/price typeable css");
}
fs.writeFileSync(ADMIN_CSS, css);

if (!readyOrderQtyHintSaysType(js)) throw new Error("qty typing copy missing after js patch");
if (!readyOrderQtyFieldsAreTypeable(css)) throw new Error("qty typing css missing after css patch");

stampHtml(ROOT);
console.log("LINGZANZAN ready order qty type ok", STAMP);
