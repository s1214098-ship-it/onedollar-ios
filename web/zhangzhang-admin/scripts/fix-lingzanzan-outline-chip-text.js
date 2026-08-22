#!/usr/bin/env node
"use strict";

/**
 * 產品大綱編號 chips: selected/focused text was #181118 (near-black)
 * because a later global rule
 *   button:not(.ghost):not(.danger-button):not(.modal-close) { color:#181118 }
 * beats
 *   .product-outline-chip > button[data-select-product-category] { color:#fff8ed }
 * (0,3,1 vs 0,2,1). Dark chip fill + black type is unreadable.
 * Keep cream titles / teal 下一號 on dark chips only. Do not restyle other .is-active gold buttons.
 *
 * Cache-bust: admin-products-horizontal-9.css / admin-product-forwarder-cost-8.js
 *             ?v=20260819-outline-chip-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const CSS = path.join(ROOT, "assets", "admin-products-horizontal-9.css");
const HTML = path.join(ROOT, "admin-products.html");
const COMPUTER_HTML = path.join(ROOT, "admin-products-computer.html");
const JS = path.join(ROOT, "assets", "admin-product-forwarder-cost-8.js");
const STAMP = "20260819-outline-chip-1";
const MARKER = "/* 20260819 outline chip text: keep cream/teal on selected dark chips */";

const PATCH = `
${MARKER}
.product-outline-chip,
.product-outline-chip.is-active {
  color: #fff8ed !important;
}
.product-outline-chip > button[data-select-product-category],
.product-outline-chip > button[data-select-product-category]:hover,
.product-outline-chip > button[data-select-product-category]:focus,
.product-outline-chip > button[data-select-product-category]:focus-visible,
.product-outline-chip > button[data-select-product-category]:active,
.product-outline-chip.is-active > button[data-select-product-category],
.product-outline-chip.is-active > button[data-select-product-category]:hover,
.product-outline-chip.is-active > button[data-select-product-category]:focus,
.product-outline-chip.is-active > button[data-select-product-category]:focus-visible,
.product-outline-chip.is-active > button[data-select-product-category]:active {
  color: #fff8ed !important;
  -webkit-text-fill-color: #fff8ed !important;
  background: transparent !important;
  background-color: transparent !important;
  appearance: none;
  -webkit-appearance: none;
}
.product-outline-chip > button[data-select-product-category] b,
.product-outline-chip.is-active > button[data-select-product-category] b {
  color: #fff8ed !important;
  -webkit-text-fill-color: #fff8ed !important;
}
.product-outline-chip > button[data-select-product-category] span,
.product-outline-chip > button[data-select-product-category] em,
.product-outline-chip.is-active > button[data-select-product-category] span,
.product-outline-chip.is-active > button[data-select-product-category] em {
  color: #8ee9dc !important;
  -webkit-text-fill-color: #8ee9dc !important;
}
.product-outline-chip > .chip-remove,
.product-outline-chip.is-active > .chip-remove {
  color: #cbb8c2 !important;
  -webkit-text-fill-color: #cbb8c2 !important;
  background: transparent !important;
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

function stamp(html) {
  return html
    .replace(/admin-products-horizontal-9\.css(?:\?v=[^"']+)?/g, `admin-products-horizontal-9.css?v=${STAMP}`)
    .replace(/admin-product-forwarder-cost-8\.js(?:\?v=[^"']+)?/g, `admin-product-forwarder-cost-8.js?v=${STAMP}`);
}

function main() {
  if (!fs.existsSync(CSS)) throw new Error("missing css " + CSS);
  console.log("backup css", backup(CSS, "outline-chip"));
  let css = fs.readFileSync(CSS, "utf8");
  if (css.includes(MARKER)) {
    css = css.replace(/\n\/\* 20260819 outline chip text[\s\S]*$/m, "");
  }
  fs.writeFileSync(CSS, css.replace(/\s*$/, "") + "\n" + PATCH);
  console.log("css patched", CSS, "len", fs.statSync(CSS).size);

  for (const file of [HTML, COMPUTER_HTML]) {
    if (!fs.existsSync(file)) {
      console.log("skip missing", file);
      continue;
    }
    console.log("backup html", backup(file, "outline-chip"));
    const next = stamp(fs.readFileSync(file, "utf8"));
    fs.writeFileSync(file, next);
    const ok = next.includes("admin-products-horizontal-9.css?v=" + STAMP);
    console.log("html stamped", file, ok ? "ok" : "MISSING STAMP");
  }
  if (fs.existsSync(JS)) {
    const js = fs.readFileSync(JS, "utf8");
    if (!js.includes("outline chip text keep cream")) {
      backup(JS, "outline-chip");
      fs.writeFileSync(JS, js.replace(/\s*$/, "") + `\n/* ${STAMP} outline chip text keep cream */\n`);
      console.log("js tagged", JS);
    } else {
      console.log("js already tagged");
    }
  }
}

main();
