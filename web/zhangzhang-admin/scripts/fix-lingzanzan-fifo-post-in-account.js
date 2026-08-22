#!/usr/bin/env node
"use strict";

/**
 * 郵局「入帳成功」= 收寄局已收件，應進配送中，不要停在等我通知出貨。
 * 出貨物流公司下拉改帶 inquiry 既有 POST；handoff 也讀包裹 officialStatus。
 * 不 stamp 全部 admin HTML，不改 .is-active 金鈕。
 */

const fs = require("fs");
const path = require("path");
const {
  fifoPostInAccountJsHasHandoffText,
  fifoPostInAccountJsReadsParcelOfficial,
  fifoPostInAccountJsPrefillsCarrier,
  fifoPostInAccountJsAlreadyInTransitUsesInquiry,
} = require("./lz-fifo-post-in-account");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260822-post-in-account-1";

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
  const names = ["admin-freight.html", "admin-reserved-shipping.html"];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const HANDOFF_OLD = `    var externalText = [order.externalStatus, order.internalStatus, order.deliveryStatus, queueEntry && queueEntry.sourceStatusText, queueEntry && queueEntry.externalStatus].join(' ');
    if (order.carrierHandoffAt) return true;
    if (/已收件|已交寄|門市已收|運輸中|配送中|轉運中|離開寄件門市|郵件處理中|貨件已受理|到達門市|抵達門市|已到店|已送達|取件完成/i.test(externalText)) return true;`;

const HANDOFF_NEW = `    var parcelOfficial = ((order.outboundParcels || []).map(function (parcel) {
      return parcel && (parcel.officialStatus || parcel.officialStatusText);
    }).join(' '));
    var externalText = [order.externalStatus, order.internalStatus, order.deliveryStatus, queueEntry && queueEntry.sourceStatusText, queueEntry && queueEntry.externalStatus, parcelOfficial].join(' ');
    if (order.carrierHandoffAt) return true;
    if (/入帳成功|已收件|已交寄|門市已收|運輸中|配送中|轉運中|離開寄件門市|郵件處理中|貨件已受理|到達門市|抵達門市|已到店|已送達|取件完成|收寄/i.test(externalText)) return true;`;

const CARRIER_OLD = `shippingCarrierOptionsHtml(order && order.shippingCarrier || '', true)`;

const CARRIER_NEW = `shippingCarrierOptionsHtml((order && order.shippingCarrier) || (row && row.shippingCarrier) || (preorder && preorder.shippingCarrier) || '', true)`;

const ALREADY_OLD = `    var alreadyInTransit = !!orderId && orderTrackingHasCarrierHandoff(currentOrder);`;

const ALREADY_NEW = `    var sourceInquiry = (state.inquiries || []).find(function (inq) { return String(inq && inq.id || '') === String(preorderId); }) || {};
    var alreadyInTransit = orderTrackingHasCarrierHandoff(currentOrder) || orderTrackingHasCarrierHandoff(sourceInquiry);`;

const CHECK_OLD = `    if (carrierHandoffInput) carrierHandoffInput.checked = orderTrackingHasCarrierHandoff(order || {});`;

const CHECK_NEW = `    if (carrierHandoffInput) carrierHandoffInput.checked = orderTrackingHasCarrierHandoff(order || {}) || orderTrackingHasCarrierHandoff(row || preorder || {});`;

const WAIT_OLD = `    if (/已取件|配達完成|配送完成|已送達|到達門市|抵達門市|已到店|離開寄件門市|門市已收|已交寄|運輸中|配送中|轉運中/.test(official)) return false;`;

const WAIT_NEW = `    if (/入帳成功|已取件|配達完成|配送完成|已送達|到達門市|抵達門市|已到店|離開寄件門市|門市已收|已交寄|運輸中|配送中|轉運中|收寄/.test(official)) return false;`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "post-in-account");
let src = fs.readFileSync(ADMIN_JS, "utf8");
src = replaceOnce(src, HANDOFF_OLD, HANDOFF_NEW, "入帳成功 counts as carrier handoff");
src = replaceOnce(src, CARRIER_OLD, CARRIER_NEW, "prefill POST from inquiry");
src = replaceOnce(src, ALREADY_OLD, ALREADY_NEW, "alreadyInTransit reads inquiry 入帳成功");
src = replaceOnce(src, CHECK_OLD, CHECK_NEW, "handoff checkbox from inquiry parcels");
if (src.indexOf(WAIT_OLD) !== -1 || src.indexOf(WAIT_NEW) !== -1) {
  src = replaceOnce(src, WAIT_OLD, WAIT_NEW, "wait-notify 入帳成功 not 尚未寄件");
} else {
  console.log("skip missing:", "wait-notify 入帳成功 not 尚未寄件");
}
fs.writeFileSync(ADMIN_JS, src);

if (!fifoPostInAccountJsHasHandoffText(src)) throw new Error("handoff 入帳成功 missing after patch");
if (!fifoPostInAccountJsReadsParcelOfficial(src)) throw new Error("parcel official missing after patch");
if (!fifoPostInAccountJsPrefillsCarrier(src)) throw new Error("carrier prefill missing after patch");
if (!fifoPostInAccountJsAlreadyInTransitUsesInquiry(src)) throw new Error("alreadyInTransit inquiry missing after patch");

stampHtml(ROOT);
console.log("LINGZANZAN fifo post 入帳成功 ok", STAMP);
