#!/usr/bin/env node
"use strict";

/**
 * 收件地址 超商／住家兩欄標題溢到格子外。
 * Global `label input { width:100%; padding:11px 12px }` inflates the radios,
 * so "超商出貨 NT$70" / "住家出貨 NT$100" sit in the gutter and hang off the right.
 *
 * Cache-bust: admin.css / address-helper.css ?v=20260819-ship-choice-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const HELPER_CSS = path.join(ROOT, "assets", "address-helper.css");
const STAMP = "20260819-ship-choice-1";
const MARKER = "/* 20260819 ship choice layout: keep 超商/住家 titles inside the cards */";

const PATCH = `
${MARKER}
.customer-shipping-addresses,
.customer-shipping-choice,
.customer-shipping-option {
  min-width: 0 !important;
  max-width: 100%;
  box-sizing: border-box;
}
.customer-shipping-choice {
  display: grid !important;
  grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
  gap: 10px !important;
  align-items: stretch !important;
}
.customer-shipping-option {
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) !important;
  grid-template-rows: auto auto minmax(0, 1fr) auto !important;
  overflow: hidden;
}
.customer-shipping-option-pick {
  display: grid !important;
  grid-template-columns: 22px minmax(0, 1fr) !important;
  align-items: start !important;
  gap: 8px !important;
  width: 100% !important;
  max-width: 100% !important;
  min-width: 0 !important;
  margin: 0 !important;
  padding: 0 !important;
  border: 0 !important;
  background: transparent !important;
  color: #fff8ed !important;
  -webkit-text-fill-color: #fff8ed !important;
  white-space: normal !important;
}
.customer-shipping-option-pick input,
.customer-shipping-option-pick input[type="radio"],
.customer-shipping-option-pick input[type="radio"]:focus,
.customer-shipping-option-pick input[type="radio"]:checked {
  appearance: auto !important;
  -webkit-appearance: radio !important;
  grid-column: 1 !important;
  width: 18px !important;
  height: 18px !important;
  min-width: 18px !important;
  max-width: 18px !important;
  min-height: 18px !important;
  max-height: 18px !important;
  margin: 3px 0 0 !important;
  padding: 0 !important;
  border: 0 !important;
  border-radius: 0 !important;
  background: transparent !important;
  background-color: transparent !important;
  box-shadow: none !important;
  flex: 0 0 18px !important;
  accent-color: #50e5d0;
}
.customer-shipping-option.is-home .customer-shipping-option-pick input[type="radio"] {
  accent-color: #f4bd4d;
}
.customer-shipping-option-pick span,
.customer-shipping-option-pick b,
.customer-shipping-option-pick small {
  display: block !important;
  min-width: 0 !important;
  max-width: 100% !important;
  white-space: normal !important;
  overflow-wrap: anywhere;
  word-break: break-word;
  line-height: 1.3;
}
.customer-shipping-option-pick span {
  grid-column: 2 !important;
  display: grid !important;
  gap: 2px !important;
  color: #fff8ed !important;
  -webkit-text-fill-color: #fff8ed !important;
}
.customer-shipping-option-pick b {
  color: #fff8ed !important;
  -webkit-text-fill-color: #fff8ed !important;
  font-size: 15px !important;
}
.customer-shipping-option-pick small,
.customer-shipping-option > small {
  color: #c8becb !important;
  -webkit-text-fill-color: #c8becb !important;
  font-weight: 700;
}
.customer-store-address-row,
.customer-home-address-row {
  min-width: 0 !important;
  max-width: 100%;
}
.customer-shipping-option .address-helper {
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
}
.customer-shipping-option .address-helper > div {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.customer-shipping-option .address-helper span,
.customer-shipping-option .address-helper button,
.customer-shipping-option .address-helper strong {
  max-width: 100%;
  white-space: normal !important;
  overflow-wrap: anywhere;
}
@media (max-width: 720px) {
  .customer-shipping-choice {
    grid-template-columns: minmax(0, 1fr) !important;
  }
}
`;

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

function appendPatch(file) {
  let css = fs.readFileSync(file, "utf8");
  if (css.includes(MARKER)) {
    css = css.replace(/\n\/\* 20260819 ship choice layout[\s\S]*$/m, "");
  }
  fs.writeFileSync(file, css.replace(/\s*$/, "") + "\n" + PATCH);
  console.log("patched", file, fs.statSync(file).size);
}

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/admin\.css|address-helper\.css/.test(html)) continue;
    const next = html
      .replace(/admin\.css(?:\?v=[^"']+)?/g, `admin.css?v=${STAMP}`)
      .replace(/address-helper\.css(?:\?v=[^"']+)?/g, `address-helper.css?v=${STAMP}`);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n++;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

function main() {
  console.log("backup admin.css", backup(ADMIN_CSS, "ship-choice"));
  console.log("backup helper.css", backup(HELPER_CSS, "ship-choice"));
  appendPatch(ADMIN_CSS);
  appendPatch(HELPER_CSS);
  stampHtml(ROOT);
}

main();
