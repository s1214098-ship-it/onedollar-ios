#!/usr/bin/env node
"use strict";

/**
 * 集運三種工作列被改成左側直欄，主畫面空了、沒辦法新增。
 * 搬回原本位置（到貨核對下面），卡片維持橫向整列。
 * 到貨補單縮圖改吃型號主圖，照片預覽改橫向完整顯示。
 *
 * Cache-bust: admin.css / admin.js / admin-freight.html ?v=20260821-workline-restore-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const HTML = path.join(ROOT, "admin-freight.html");
const CSS = path.join(ROOT, "assets", "admin.css");
const JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260821-workline-restore-1";
const RAIL_MARKER = "/* 20260821 freight workline left rail */";
const CHIPS_WIDE = `/* 20260821 workline metric chips */`;

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
  if (src.indexOf(newStr) !== -1) {
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

function cutSection(html, startKey) {
  const start = html.indexOf(startKey);
  if (start < 0) throw new Error("missing " + startKey);
  const endTag = "</section>";
  const end = html.indexOf(endTag, start);
  if (end < 0) throw new Error("missing close for " + startKey);
  let after = end + endTag.length;
  if (html[after] === "\r") after += 1;
  if (html[after] === "\n") after += 1;
  return { start, after, block: html.slice(start, after) };
}

function restoreWorklinePosition(html) {
  const workKey = '<section class="freight-workline-board"';
  const recvKey = '<section class="freight-receiving-lookup"';
  const workAt = html.indexOf(workKey);
  const recvAt = html.indexOf(recvKey);
  if (workAt < 0 || recvAt < 0) throw new Error("missing workline or receiving");
  if (workAt > recvAt) {
    console.log("already: workline after receiving lookup");
    return html;
  }
  const work = cutSection(html, workKey);
  html = html.slice(0, work.start) + html.slice(work.after);
  const recv = cutSection(html, recvKey);
  const insertAt = recv.after;
  console.log("patched: move workline back after receiving lookup");
  return html.slice(0, insertAt) + work.block + html.slice(insertAt);
}

function stripLeftRail(css) {
  const start = css.indexOf(RAIL_MARKER);
  if (start < 0) {
    console.log("already: left rail css gone");
    return css;
  }
  const next = css.indexOf("\n/* 20", start + RAIL_MARKER.length);
  const end = next >= 0 ? next : css.length;
  console.log("patched: remove left rail css", end - start, "bytes");
  return css.slice(0, start) + css.slice(end);
}

const CHIPS_MEDIA_OLD = `@media (min-width: 1280px) {
  #freight .freight-workline-metrics {
    grid-column: 2;
    justify-self: stretch;
  }
}
@media (max-width: 760px) {
  .freight-workline-metrics {
    grid-column: 1 / -1;
  }
}`;

const CHIPS_MEDIA_NEW = `@media (max-width: 760px) {
  .freight-workline-metrics {
    grid-column: 1 / -1;
  }
}`;

const IMAGE_OLD = `          image: String((sku && (sku.image || sku.colorImage)) || product.mainImage || (Array.isArray(product.images) && product.images[0]) || ''),`;

const IMAGE_NEW = `          image: String(product.mainImage || product.image || (sku && (sku.colorImage || sku.image)) || (Array.isArray(product.images) && product.images[0]) || ''),`;

const PREVIEW_OLD = `.freight-quantity-extra-preview img{max-width:120px;max-height:120px;object-fit:cover;border-radius:10px;background:#fff}`;

const PREVIEW_NEW = `.freight-quantity-extra-preview img{display:block;max-width:280px;max-height:160px;width:auto;height:auto;object-fit:contain;border-radius:10px;background:#fff}`;

const RESULT_IMG_OLD = `.freight-quantity-extra-results button img,.freight-quantity-extra-results button>span:first-child{grid-row:1/span 3;width:48px;height:48px;object-fit:cover;border-radius:8px;background:#dceee9}`;

const RESULT_IMG_NEW = `.freight-quantity-extra-results button img,.freight-quantity-extra-results button>span:first-child{grid-row:1/span 3;width:72px;height:48px;object-fit:contain;border-radius:8px;background:#dceee9}`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
    const page = fs.readFileSync(file, "latin1");
    if (page.indexOf("admin.js") === -1 && page.indexOf("admin.css") === -1) return;
    const next = page
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
    if (next === page) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(HTML)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup html", backup(HTML, "workline-restore"));
let html = fs.readFileSync(HTML, "latin1");
html = restoreWorklinePosition(html);
fs.writeFileSync(HTML, Buffer.from(html, "latin1"));

console.log("backup css", backup(CSS, "workline-restore"));
let css = fs.readFileSync(CSS, "utf8");
css = stripLeftRail(css);
css = replaceOnce(css, CHIPS_MEDIA_OLD, CHIPS_MEDIA_NEW, "metrics stay in original column");
if (css.indexOf(PREVIEW_OLD) !== -1) css = replaceOnce(css, PREVIEW_OLD, PREVIEW_NEW, "extra photo landscape");
if (css.indexOf(RESULT_IMG_OLD) !== -1) css = replaceOnce(css, RESULT_IMG_OLD, RESULT_IMG_NEW, "extra result thumb landscape");
if (css.indexOf(RAIL_MARKER) !== -1) throw new Error("left rail css still present");
fs.writeFileSync(CSS, css);

if (fs.existsSync(JS)) {
  console.log("backup js", backup(JS, "workline-restore"));
  let js = fs.readFileSync(JS, "utf8");
  if (js.indexOf(IMAGE_OLD) !== -1) {
    js = replaceOnce(js, IMAGE_OLD, IMAGE_NEW, "extra product uses model main image");
  } else if (js.indexOf("product.mainImage || product.image || (sku && (sku.colorImage") !== -1) {
    console.log("already: extra product uses model main image");
  } else {
    console.log("warn: extra product image line not found");
  }
  fs.writeFileSync(JS, js);
}

stampHtml(ROOT);

const check = fs.readFileSync(HTML, "latin1");
const worklineAt = check.indexOf('<section class="freight-workline-board"');
const recvAt = check.indexOf('<section class="freight-receiving-lookup"');
console.log("order", { worklineAt, recvAt, ok: worklineAt > recvAt });
if (!(worklineAt > recvAt)) throw new Error("workline not restored after receiving");
console.log("done", STAMP);
