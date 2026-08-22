#!/usr/bin/env node
"use strict";

/**
 * 業務出貨決定卡（複製到 LINE 的 canvas PNG）商品單價只有 11px，
 * 底下「單價 NT$790・1件・NT$790」看不清。加大畫布價錢、HTML 預覽、FIFO 列表單價。
 *
 * Cache-bust: admin.css / admin.js ?v=20260819-card-price-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260819-card-price-1";
const JS_MARKER = "context.font = '900 18px sans-serif';\n          context.fillText(qty + '件・' + freightFifoMoney(unitPrice * qty), imageX + 95, imageY + 258);";
const CSS_MARKER = "/* 20260819 card price: enlarge sales decision card amounts */";

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

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/admin\.css|admin\.js/.test(html)) continue;
    const next = html
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP)
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const PRODUCTS_HEIGHT_OLD = "    var productsHeight = 70 + productRows * 230;";
const PRODUCTS_HEIGHT_NEW = "    var productsHeight = 70 + productRows * 272;";

const CANVAS_HEIGHT_OLD = "    canvas.height = 690 + productsHeight + 140;";
const CANVAS_HEIGHT_NEW = "    canvas.height = 690 + productsHeight + 168;";

const ROW_STEP_OLD = "        var imageY = y + 74 + rowIndex * 230;";
const ROW_STEP_NEW = "        var imageY = y + 74 + rowIndex * 272;";

const PRICE_OLD = `        context.fillStyle = unitPrice > 0 ? '#fff3cf' : '#ff8f9d';
        context.font = '850 11px sans-serif';
        if (unitPrice > 0) {
          context.fillText('單價 ' + freightFifoMoney(unitPrice) + '・' + qty + '件・' + freightFifoMoney(unitPrice * qty), imageX + 95, imageY + 218);
        } else {
          context.fillText('金額待補／Harga belum diisi', imageX + 95, imageY + 218);
        }`;

const PRICE_NEW = `        context.fillStyle = unitPrice > 0 ? '#fff3cf' : '#ff8f9d';
        if (unitPrice > 0) {
          context.font = '850 16px sans-serif';
          context.fillText('單價 ' + freightFifoMoney(unitPrice), imageX + 95, imageY + 236);
          context.font = '900 18px sans-serif';
          context.fillText(qty + '件・' + freightFifoMoney(unitPrice * qty), imageX + 95, imageY + 258);
        } else {
          context.font = '850 16px sans-serif';
          context.fillText('金額待補／Harga belum diisi', imageX + 95, imageY + 248);
        }`;

const FOOTER_OLD = `    context.roundRect(48, y, 904, 96, 18);
    context.fill();
    context.fillStyle = '#f4bd4d';
    context.font = '850 17px sans-serif';
    context.fillText(isCombinedCard ? '合併單應付總額／Total gabungan' : '客戶應付總金額／Total pembayaran', 72, y + 35);
    context.fillStyle = '#fff3cf';
    context.font = '950 38px sans-serif';
    context.textAlign = 'right';
    context.fillText(freightFifoMoney(isCombinedCard ? orderCustomerBilling(row).total : model.payment.total), 928, y + 61);`;

const FOOTER_NEW = `    context.roundRect(48, y, 904, 112, 18);
    context.fill();
    context.fillStyle = '#f4bd4d';
    context.font = '850 18px sans-serif';
    context.fillText(isCombinedCard ? '合併單應付總額／Total gabungan' : '客戶應付總金額／Total pembayaran', 72, y + 38);
    context.fillStyle = '#fff3cf';
    context.font = '950 48px sans-serif';
    context.textAlign = 'right';
    context.fillText(freightFifoMoney(isCombinedCard ? orderCustomerBilling(row).total : model.payment.total), 928, y + 74);`;

const AMOUNT_OLD = `      var amountHtml = unitPrice > 0
        ? originHtml + '<span>單價／Harga ' + freightFifoMoney(unitPrice) + '</span><span>數量／Jumlah ' + qty + '・小計 ' + freightFifoMoney(unitPrice * qty) + '</span>'
        : originHtml + '<span class="is-missing">金額待補／Harga belum diisi</span>';`;

const AMOUNT_NEW = `      var amountHtml = unitPrice > 0
        ? originHtml + '<span class="freight-fifo-card-price">單價／Harga ' + freightFifoMoney(unitPrice) + '</span><span class="freight-fifo-card-price">數量／Jumlah ' + qty + '・小計 ' + freightFifoMoney(unitPrice * qty) + '</span>'
        : originHtml + '<span class="is-missing">金額待補／Harga belum diisi</span>';`;

const FIGURE_HEIGHT_OLD = `  grid-template-rows: minmax(0,1fr) auto;
  height: 230px;`;
const FIGURE_HEIGHT_NEW = `  grid-template-rows: minmax(0,1fr) auto;
  height: 272px;`;

const FIGCAPTION_OLD = `.freight-fifo-share-card-items.is-photo-only figure figcaption {
  display: grid;
  gap: 3px;
  min-height: 52px;
  padding: 8px 6px;
  background: #211a25;
  color: #fff3cf;
  font-size: 11px;
  font-weight: 900;
  line-height: 1.3;
  text-align: center;
  white-space: normal;
}`;

const FIGCAPTION_NEW = `.freight-fifo-share-card-items.is-photo-only figure figcaption {
  display: grid;
  gap: 4px;
  min-height: 78px;
  padding: 8px 6px;
  background: #211a25;
  color: #fff3cf;
  font-size: 15px;
  font-weight: 900;
  line-height: 1.3;
  text-align: center;
  white-space: normal;
}`;

const CARD_PRICE_OLD = `.freight-fifo-share-card-items .freight-fifo-card-price { color: #fff; font-weight: 800; }`;
const CARD_PRICE_NEW = `.freight-fifo-share-card-items .freight-fifo-card-price { display: block; margin-top: 2px; color: #fff; font-size: 17px; font-weight: 850; letter-spacing: 0.02em; }`;

const LIST_PRICE_OLD = `.freight-fifo-product-price{display:block;margin:4px 0 0;color:#ffe7a8;font-weight:900}`;
const LIST_PRICE_NEW = `.freight-fifo-product-price{display:block;margin:4px 0 0;color:#ffe7a8;font-size:16px;font-weight:900}`;

const MEDIA_210_OLD = `.freight-fifo-share-card-items.is-photo-only figure { height: 210px; }`;
const MEDIA_210_NEW = `.freight-fifo-share-card-items.is-photo-only figure { height: 252px; }`;
const MEDIA_280_OLD = `.freight-fifo-share-card-items.is-photo-only figure { height: 280px; }`;
const MEDIA_280_NEW = `.freight-fifo-share-card-items.is-photo-only figure { height: 304px; }`;

const FOOTER_B_OLD = `.freight-fifo-share-card footer b { overflow-wrap: anywhere; font-size: 13px; }`;
const FOOTER_B_NEW = `.freight-fifo-share-card footer b { overflow-wrap: anywhere; font-size: 22px; font-weight: 950; }`;

function patchJs() {
  console.log("backup js", backup(ADMIN_JS, "card-price"));
  let src = fs.readFileSync(ADMIN_JS, "utf8");
  if (src.includes(JS_MARKER)) {
    console.log("js already patched");
    return;
  }
  src = replaceOnce(src, PRODUCTS_HEIGHT_OLD, PRODUCTS_HEIGHT_NEW, "canvas row height");
  src = replaceOnce(src, CANVAS_HEIGHT_OLD, CANVAS_HEIGHT_NEW, "canvas total height");
  src = replaceOnce(src, ROW_STEP_OLD, ROW_STEP_NEW, "canvas row step");
  src = replaceOnce(src, PRICE_OLD, PRICE_NEW, "canvas item price");
  src = replaceOnce(src, FOOTER_OLD, FOOTER_NEW, "canvas footer total");
  src = replaceOnce(src, AMOUNT_OLD, AMOUNT_NEW, "html card price class");
  fs.writeFileSync(ADMIN_JS, src);
  console.log("js written", src.length);
}

function patchCss() {
  console.log("backup css", backup(ADMIN_CSS, "card-price"));
  let src = fs.readFileSync(ADMIN_CSS, "utf8");
  if (src.includes(CSS_MARKER)) {
    console.log("css already patched");
    return;
  }
  src = replaceOnce(src, FIGURE_HEIGHT_OLD, FIGURE_HEIGHT_NEW, "css figure height");
  src = replaceOnce(src, FIGCAPTION_OLD, FIGCAPTION_NEW, "css figcaption");
  src = replaceOnce(src, CARD_PRICE_OLD, CARD_PRICE_NEW, "css card price");
  src = replaceOnce(src, LIST_PRICE_OLD, LIST_PRICE_NEW, "css fifo list price");
  src = replaceOnce(src, MEDIA_210_OLD, MEDIA_210_NEW, "css mobile figure");
  src = replaceOnce(src, MEDIA_280_OLD, MEDIA_280_NEW, "css wide figure");
  src = replaceOnce(src, FOOTER_B_OLD, FOOTER_B_NEW, "css share footer total");
  src = src + "\n" + CSS_MARKER + "\n";
  fs.writeFileSync(ADMIN_CSS, src);
  console.log("css written", src.length);
}

patchJs();
patchCss();
stampHtml(ROOT);
console.log("LINGZANZAN sales card prices enlarged:", STAMP);
