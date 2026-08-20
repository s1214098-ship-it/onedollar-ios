#!/usr/bin/env node
"use strict";

/**
 * 等待通知／先別出貨：若正式出貨單已建立（尤其物流單已成立、尚未至門市寄件），
 * 卡片上直接給「開啟出貨單（舊證）」，不必再到準備出貨名單翻。
 *
 * Cache-bust: admin.js ?v=20260820-wait-notify-old-1
 * HTML stamp is latin1 (byte-preserving), freight pages only.
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260820-wait-notify-old-1";
const MARKER = "function freightFifoWaitNotifyOpenShipmentHtml(";

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
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) {
      console.log("skip missing", name);
      return;
    }
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

const HELPERS = `
  function freightFifoWaitNotifyExistingOrder(row) {
    var ids = freightFifoWaitNotifyTargetIds(row);
    var idx = freightFifoIndexes();
    var inquiry = (ids.inquiryId && idx.inquiryById.get(ids.inquiryId)) || freightFifoSourceInquiry(row) || null;
    var order = (ids.orderId && idx.orderById.get(ids.orderId)) || null;
    if (!order && row && row.id) order = idx.orderById.get(String(row.id)) || null;
    if (!order) order = resolveFreightFifoFormalOrder(inquiry || row, order);
    if (!order && inquiry) order = freightFifoFormalOrderForInquiry(inquiry.id) || null;
    if (!order || !order.id || freightFifoLooksLikeInquiry(order)) return null;
    var status = String(order.status || '').toLowerCase();
    var delivery = String(order.deliveryState || '').toLowerCase();
    if (['cancelled', 'canceled', 'returned', 'delivered', 'completed', 'draft'].indexOf(status) !== -1) return null;
    if (delivery === 'delivered' || delivery === 'returned') return null;
    return order;
  }

  function freightFifoTrackingNotYetDroppedAtStore(order) {
    if (!order) return false;
    var parcels = freightFifoOutboundParcelsFromRow(order);
    var tracking = freightFifoNormTrackingNo((parcels[0] && parcels[0].trackingNo) || order.trackingNo || order.shippingTrackingNo);
    if (!tracking) return false;
    var delivery = String(order.deliveryState || '').toLowerCase();
    var status = String(order.status || '').toLowerCase();
    if (delivery === 'in_transit' || delivery === 'delivered' || delivery === 'returned') return false;
    if (status === 'in_transit' || status === 'shipped' || status === 'delivered' || status === 'returned') return false;
    var official = String((parcels[0] && (parcels[0].officialStatus || parcels[0].officialStatusText)) || order.externalStatus || '');
    if (/已取件|配達完成|配送完成|已送達|到達門市|抵達門市|已到店|離開寄件門市|門市已收|已交寄|運輸中|配送中|轉運中/.test(official)) return false;
    return true;
  }

  function freightFifoEnsureWaitNotifyOldShipStyle() {
    if (typeof document === 'undefined' || !document.getElementById) return;
    if (document.getElementById('freight-fifo-wait-notify-old-ship-style')) return;
    var style = document.createElement('style');
    style.id = 'freight-fifo-wait-notify-old-ship-style';
    style.textContent = '.freight-fifo-wait-notify-old-ship{display:flex;flex-wrap:wrap;align-items:center;gap:8px;width:100%;margin:0 0 8px}'
      + '.freight-fifo-wait-notify-old-ship .ghost-button{border-color:#f0bd54;color:#f0bd54}'
      + '.freight-fifo-wait-notify-old-ship small{color:#f0bd54}';
    if (document.head) document.head.appendChild(style);
  }

  function freightFifoWaitNotifyOpenShipmentHtml(row) {
    freightFifoEnsureWaitNotifyOldShipStyle();
    var order = freightFifoWaitNotifyExistingOrder(row);
    if (!order || !order.id) return '';
    var parcels = freightFifoOutboundParcelsFromRow(order);
    var tracking = freightFifoNormTrackingNo((parcels[0] && parcels[0].trackingNo) || order.trackingNo || order.shippingTrackingNo);
    var official = String((parcels[0] && (parcels[0].officialStatus || parcels[0].officialStatusText)) || order.externalStatus || '').trim();
    var notAtStore = freightFifoTrackingNotYetDroppedAtStore(order);
    var hint = '';
    if (tracking) {
      hint = '<small>' + escapeHtml(tracking)
        + (official ? '／' + escapeHtml(official) : (notAtStore ? '／物流單已建立，尚未至門市寄件' : ''))
        + '</small>';
    } else {
      hint = '<small>正式出貨單已建立</small>';
    }
    return '<div class="freight-fifo-wait-notify-old-ship">'
      + '<button type="button" class="ghost-button" data-similar-ship-ok="1" data-freight-fifo-wait-notify-open="' + escapeHtml(order.id) + '" data-freight-fifo-pending-open="' + escapeHtml(order.id) + '" onclick="return window.__openFreightFifoPendingFromButton&&window.__openFreightFifoPendingFromButton(this,event)">開啟出貨單（舊證）</button>'
      + hint
      + '</div>';
  }

`;

const CARD_FN = `  function freightFifoWaitNotifyCardHtml(row, index) {`;

const ACTIONS_OLD = `freightFifoWaitNotifyNoteHtml(row) + freightFifoWaitNotifyButtonHtml(row, true)`;

const ACTIONS_NEW = `freightFifoWaitNotifyNoteHtml(row) + freightFifoWaitNotifyOpenShipmentHtml(row) + freightFifoWaitNotifyButtonHtml(row, true)`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "wait-notify-old"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(MARKER) !== -1 && js.indexOf("data-freight-fifo-wait-notify-open") !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, CARD_FN, HELPERS + CARD_FN, "wait-notify open-old helpers");
  console.log("actions needle idx", js.indexOf(ACTIONS_OLD), "count-hint", js.split(ACTIONS_OLD).length - 1);
  js = replaceOnce(js, ACTIONS_OLD, ACTIONS_NEW, "wait-notify card open-old button");
}
if (js.indexOf(MARKER) === -1) throw new Error("open-old helper missing after patch");
if (js.indexOf("data-freight-fifo-wait-notify-open") === -1) throw new Error("open-old button missing after patch");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
stampHtml(ROOT);
console.log("LINGZANZAN wait-notify open old shipment ok", STAMP);
