#!/usr/bin/env node
"use strict";

/**
 * 驗收「全部可賣／全部公司自用／同一規格拆開」看起來像壞掉的黑圓點按鈕。
 * 原因：native radio 被 receiving-black-text 刷成全黑；已選 :after 被 latin1 寫成亂碼；
 * 台灣倉仍顯示中國倉用途 label，把分流三格擠歪。
 *
 * Cache-bust: admin.js / admin.css ?v=20260921-receiving-purpose-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260921-receiving-purpose-1";
const JS_MARKER = "freight-receiving-stock-purpose";
const CSS_MARKER = "/* 20260921 receiving purpose choice cards */";

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
    "admin-freight.html",
    "admin-reserved-shipping.html",
    "admin-orders.html",
    "admin-preorders.html",
    "admin-order-tracking.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1 && html.indexOf("admin.css") === -1) return;
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

const PANEL_OLD = `    var purposePanel = document.createElement('label');
    purposePanel.className = 'freight-receiving-stock-purpose';`;

const PANEL_NEW = `    var purposePanel = document.createElement('div');
    purposePanel.className = 'freight-receiving-stock-purpose';`;

const CLICK_OLD = `    Array.from(modal.querySelectorAll('.freight-receiving-goods-purpose label')).forEach(function (label) {
      var radio = label.querySelector('input[name="freight-receiving-goods-purpose"]');
      if (!radio) return;
      label.addEventListener('click', function () {
        radio.checked = true;
        updateRoutingSummary();
      });
    });`;

const CLICK_NEW = `    Array.from(modal.querySelectorAll('.freight-receiving-goods-purpose label')).forEach(function (label) {
      var radio = label.querySelector('input[name="freight-receiving-goods-purpose"]');
      if (!radio) return;
      label.addEventListener('click', function (event) {
        event.preventDefault();
        radio.checked = true;
        updateRoutingSummary();
      });
    });`;

const ROUTING_CLICK_OLD = `    Array.from(modal.querySelectorAll('.freight-receiving-routing label')).forEach(function (label) {
      var radio = label.querySelector('input[name="freight-receiving-routing"]');
      if (!radio) return;
      label.addEventListener('click', function () {
        if (radio.disabled) return;
        radio.checked = true;
        updateRoutingSummary();
      });
    });`;

const ROUTING_CLICK_NEW = `    Array.from(modal.querySelectorAll('.freight-receiving-routing label')).forEach(function (label) {
      var radio = label.querySelector('input[name="freight-receiving-routing"]');
      if (!radio) return;
      label.addEventListener('click', function (event) {
        if (radio.disabled) return;
        event.preventDefault();
        radio.checked = true;
        updateRoutingSummary();
      });
    });`;

const GOODS_RADIO_OLD = `.freight-receiving-workbench .freight-receiving-goods-purpose input[type="radio"]{appearance:auto!important;-webkit-appearance:radio!important;width:20px!important;height:20px!important;min-width:20px!important;opacity:1!important;visibility:visible!important;pointer-events:auto!important;accent-color:#b07a16!important}`;

const GOODS_RADIO_NEW = `.freight-receiving-workbench .freight-receiving-goods-purpose input[type="radio"]{appearance:none!important;-webkit-appearance:none!important;position:absolute!important;width:1px!important;height:1px!important;min-width:0!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important}`;

const ROUTING_RADIO_OLD = `.freight-receiving-workbench .freight-receiving-routing input[type="radio"]{appearance:auto!important;-webkit-appearance:radio!important;width:20px!important;height:20px!important;min-width:20px!important;opacity:1!important;visibility:visible!important;pointer-events:auto!important;accent-color:#70427a!important}`;

const ROUTING_RADIO_NEW = `.freight-receiving-workbench .freight-receiving-routing input[type="radio"]{appearance:none!important;-webkit-appearance:none!important;position:absolute!important;width:1px!important;height:1px!important;min-width:0!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important}`;

const CSS_APPEND = `
${CSS_MARKER}
.freight-receiving-goods-purpose,
.freight-receiving-routing {
  align-items: stretch;
}
.freight-receiving-goods-purpose label,
.freight-receiving-routing label:has(input[name="freight-receiving-routing"]) {
  position: relative;
  min-height: 92px;
  padding: 14px 16px 14px 16px !important;
  display: grid !important;
  gap: 6px !important;
  align-content: start;
  box-sizing: border-box;
}
.freight-receiving-workbench .freight-receiving-goods-purpose label.is-selected b:after,
.freight-receiving-workbench .freight-receiving-routing label.is-selected b:after {
  content: " \\5DF2\\9078" !important;
  color: #8a5a10 !important;
  -webkit-text-fill-color: #8a5a10 !important;
  font-size: 12px !important;
  font-weight: 900 !important;
  letter-spacing: .06em;
}
.freight-receiving-workbench .freight-receiving-routing label.is-selected b:after {
  color: #70427a !important;
  -webkit-text-fill-color: #70427a !important;
}
.freight-receiving-goods-purpose label:hover,
.freight-receiving-routing label:has(input[name="freight-receiving-routing"]):hover {
  border-color: #b07a16;
  box-shadow: 0 0 0 3px rgba(176,122,22,.14);
}
.freight-receiving-routing .freight-receiving-stock-purpose {
  grid-column: 1 / -1;
  display: grid;
  grid-template-columns: minmax(180px, 1fr) minmax(220px, 1fr);
  gap: 10px;
  align-items: center;
  padding: 12px;
  border: 1px solid #8ab0d4;
  border-radius: 12px;
  background: #f2f8ff;
}
.freight-receiving-routing .freight-receiving-stock-purpose[hidden],
.freight-receiving-routing [hidden] {
  display: none !important;
}
.freight-receiving-final-route.is-switched:before {
  content: "\\26A0\\FE0F  \\8DE8\\90E8\\9580\\5165\\5EAB\\3000" !important;
}
`;

if (!fs.existsSync(JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "receiving-purpose"));
let js = fs.readFileSync(JS, "utf8");
js = replaceOnce(js, PANEL_OLD, PANEL_NEW, "stock purpose is a panel not a radio label");
js = replaceOnce(js, ROUTING_CLICK_OLD, ROUTING_CLICK_NEW, "routing card click");
js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "purpose card click");
try {
  new Function(js);
  console.log("js syntax ok");
} catch (error) {
  throw new Error("admin.js syntax: " + error.message);
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("stock purpose marker missing");
if (js.indexOf("createElement('label');\n    purposePanel.className = 'freight-receiving-stock-purpose'") !== -1) {
  throw new Error("stock purpose still a label");
}
fs.writeFileSync(JS, js, "utf8");
console.log("admin.js written", JS, "len", js.length);

if (!fs.existsSync(CSS)) throw new Error("missing admin.css");
console.log("backup css", backup(CSS, "receiving-purpose"));
let css = fs.readFileSync(CSS, "utf8");
css = replaceOnce(css, GOODS_RADIO_OLD, GOODS_RADIO_NEW, "hide native goods radios");
css = replaceOnce(css, ROUTING_RADIO_OLD, ROUTING_RADIO_NEW, "hide native routing radios");
if (css.indexOf(CSS_MARKER) !== -1) {
  console.log("admin css cards already patched");
} else {
  css = css.replace(/\s*$/, "\n") + CSS_APPEND;
  console.log("patched: purpose choice card css");
}
fs.writeFileSync(CSS, css, "utf8");
if (css.indexOf(CSS_MARKER) === -1) throw new Error("purpose card css missing");
if (css.indexOf("\\5DF2\\9078") === -1) throw new Error("selected badge escape missing");

stampHtml(ROOT);
console.log("LINGZANZAN receiving purpose cards ok", STAMP);
