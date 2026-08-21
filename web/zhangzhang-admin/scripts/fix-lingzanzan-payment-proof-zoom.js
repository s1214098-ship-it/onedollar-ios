#!/usr/bin/env node
"use strict";

/**
 * 出貨單印尼單據／收款證明縮圖沒接 data-order-photo，點了不會開燈箱。
 * 預覽與歷史證明改成點圖放大；燈箱 z-index 蓋過 FIFO 視窗。
 *
 * Cache-bust: admin.js / admin.css ?v=20260821-proof-zoom-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260821-proof-zoom-1";
const JS_MARKER = "freight-fifo-payment-proof-thumb";
const CSS_MARKER = "/* 20260821 payment proof click to zoom */";

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

const PREVIEW_OLD = `    if (preview) preview.innerHTML = box._paymentProofImage
      ? '<figure><img src="' + escapeHtml(box._paymentProofImage) + '" alt="訂金收款證明"></figure><span>已帶入證明，儲存訂單後才正式入帳</span>'
      : '<span>尚未上傳證明。可選擇檔案、貼上截圖，或把圖片拖進來。</span>';`;

const PREVIEW_NEW = `    if (preview) preview.innerHTML = box._paymentProofImage
      ? '<button type="button" class="freight-fifo-payment-proof-thumb" data-order-photo="' + escapeHtml(box._paymentProofImage) + '" aria-label="放大查看收款證明"><img src="' + escapeHtml(box._paymentProofImage) + '" alt="訂金收款證明"></button><span>已帶入證明，儲存訂單後才正式入帳・點圖放大</span>'
      : '<span>尚未上傳證明。可選擇檔案、貼上截圖，或把圖片拖進來。</span>';`;

const HISTORY_OLD = `      var image = entry.proofImage ? '<img src="' + escapeHtml(entry.proofImage) + '" alt="第 ' + (index + 1) + ' 次收款證明" style="max-width:64px;max-height:64px;object-fit:contain">' : '';`;

const HISTORY_NEW = `      var image = entry.proofImage ? '<button type="button" class="freight-fifo-payment-proof-thumb is-history" data-order-photo="' + escapeHtml(entry.proofImage) + '" aria-label="放大查看第 ' + (index + 1) + ' 次收款證明"><img src="' + escapeHtml(entry.proofImage) + '" alt="第 ' + (index + 1) + ' 次收款證明"></button>' : '';`;

const CLICK_OLD = `    section.addEventListener('click', function (event) {
      var removeButton = event.target.closest ? event.target.closest('[data-freight-fifo-payment-proof-remove]') : null;
      if (!removeButton || !section.contains(removeButton)) return;
      event.preventDefault();
      clearFreightFifoPaymentProofImage(box);
    });`;

const CLICK_NEW = `    section.addEventListener('click', function (event) {
      var photo = event.target.closest ? event.target.closest('[data-order-photo]') : null;
      if (photo && section.contains(photo)) {
        event.preventDefault();
        event.stopPropagation();
        openOrderPhoto(photo.getAttribute('data-order-photo') || '', photo);
        return;
      }
      var removeButton = event.target.closest ? event.target.closest('[data-freight-fifo-payment-proof-remove]') : null;
      if (!removeButton || !section.contains(removeButton)) return;
      event.preventDefault();
      clearFreightFifoPaymentProofImage(box);
    });`;

const Z_OLD = `.order-photo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 2147483000;
  display: none;`;

const Z_NEW = `.order-photo-lightbox {
  position: fixed;
  inset: 0;
  z-index: 2147483646;
  display: none;`;

const CSS_PATCH = `
${CSS_MARKER}
.freight-fifo-payment-proof-thumb {
  display: block;
  margin: 0;
  padding: 6px;
  border: 1px dashed #8d7443;
  border-radius: 12px;
  background: #120e14;
  cursor: zoom-in;
}
.freight-fifo-payment-proof-thumb img {
  display: block;
  max-width: 160px;
  max-height: 160px;
  object-fit: contain;
  pointer-events: none;
}
.freight-fifo-payment-proof-thumb.is-history {
  display: inline-block;
  margin-left: 8px;
  padding: 2px;
  vertical-align: middle;
}
.freight-fifo-payment-proof-thumb.is-history img {
  max-width: 64px;
  max-height: 64px;
}
`;

function stampHtml(dir) {
  const names = [
    "admin-freight.html",
    "admin-orders.html",
    "admin-preorders.html",
    "admin-live.html",
    "admin-reserved-shipping.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach((name) => {
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

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "proof-zoom"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, PREVIEW_OLD, PREVIEW_NEW, "proof preview click-to-zoom");
  js = replaceOnce(js, HISTORY_OLD, HISTORY_NEW, "proof history click-to-zoom");
  js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "proof click opens lightbox");
} else {
  console.log("js already patched");
}
fs.writeFileSync(ADMIN_JS, js);
console.log("admin.js written", js.length);

console.log("backup css", backup(ADMIN_CSS, "proof-zoom"));
let css = fs.readFileSync(ADMIN_CSS, "utf8");
css = replaceOnce(css, Z_OLD, Z_NEW, "lightbox above fifo");
if (css.indexOf(CSS_MARKER) === -1) {
  css = css.replace(/\s*$/, "") + "\n" + CSS_PATCH;
  console.log("css thumb styles");
} else {
  console.log("css thumb already");
}
fs.writeFileSync(ADMIN_CSS, css);

stampHtml(ROOT);
if (js.indexOf(JS_MARKER) === -1) throw new Error("proof thumb missing after patch");
console.log("done", STAMP);
