#!/usr/bin/env node
"use strict";

/**
 * When 小姐／管理者 ships (加入本次配送 / 開啟出貨單), pop similar
 * same-customer + same-product shipment records from the last 31 days
 * so they can compare whether this item already went out.
 *
 * Cache-bust: admin.js ?v=20260820-similar-ship-1
 * HTML stamp is latin1 (byte-preserving).
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260820-similar-ship-1";
const MARKER = "function freightFifoSimilarShipRecords(";

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

const HELPERS = `
  function adminTaipeiTodayKey() {
    try { return new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Taipei' }); }
    catch (error) { return new Date().toISOString().slice(0, 10); }
  }
  function adminDateKeyAddDays(key, days) {
    var parts = String(key || '').slice(0, 10).split('-');
    if (parts.length < 3) return '';
    var dt = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]) + Number(days || 0)));
    return dt.toISOString().slice(0, 10);
  }
  function adminRecordDateKey(order) {
    return taipeiCalendarKey(order && (order.shippedAt || order.deliveredAt || order.pickedUpAt || order.shippingDate || order.orderDate || order.createdAt || order.updatedAt || '') || '');
  }
  function adminWithinLastMonth(key) {
    var today = adminTaipeiTodayKey();
    var cutoff = adminDateKeyAddDays(today, -31);
    key = String(key || '').slice(0, 10);
    return !!key && !!cutoff && key >= cutoff && key <= today;
  }
  function freightFifoSimilarSourceRow(id) {
    id = String(id || '');
    if (!id) return null;
    return (state.orders || []).find(function (row) { return String(row && row.id || '') === id; })
      || (state.inquiries || []).find(function (row) { return String(row && row.id || '') === id; })
      || null;
  }
  function freightFifoLinkedIdMap(row) {
    var ids = {};
    if (!row) return ids;
    [row.id, row.orderId, row.convertedToOrderId, row.convertedOrderId, row.sourceInquiryId, row.inquiryId, row.preorderId, row.formalOrderId].forEach(function (value) {
      value = String(value || '').trim();
      if (value) ids[value] = true;
    });
    return ids;
  }
  function freightFifoProductKeySet(row) {
    var keys = {};
    (Array.isArray(row && row.items) ? row.items : []).forEach(function (item) {
      var key = adminDuplicateProductKey(item);
      if (key) keys[key] = item;
    });
    return keys;
  }
  function freightFifoSimilarShipKind(order) {
    var status = String(order && (order.status || order.stage) || '').toLowerCase();
    if (['cancelled', 'canceled', 'deleted', 'void'].indexOf(status) !== -1) return '';
    var delivery = String(order && order.deliveryState || '').toLowerCase();
    if (delivery === 'returned' || status === 'returned') return 'returned';
    return adminDuplicateShipKind(order);
  }
  function freightFifoSimilarShipKindLabel(kind) {
    return {
      shipped: '已出過／配送中或已取件',
      returned: '出過但已退回',
      open: '還沒出，同一商品還有一張',
      closed: '已結束'
    }[kind] || '請比對';
  }
  function freightFifoSimilarItemLine(item) {
    item = item || {};
    return ['款 ' + (item.code || item.productCode || '-'), '色 ' + (item.color || item.colorName || '未填'), '尺碼 ' + (item.size || item.sizeName || '均碼'), '×' + Math.max(0, Number(item.qty || item.quantity || 0))].join('／');
  }
  function freightFifoSimilarShipRecords(id) {
    var source = freightFifoSimilarSourceRow(id);
    if (!source) return [];
    var customerKey = adminDuplicateCustomerKey(source);
    var productKeys = freightFifoProductKeySet(source);
    if (!customerKey || !Object.keys(productKeys).length) return [];
    var skip = freightFifoLinkedIdMap(source);
    var rows = [];
    function consider(order, sourceType) {
      if (!order || skip[String(order.id || '')]) return;
      if (adminDuplicateCustomerKey(order) !== customerKey) return;
      var kind = freightFifoSimilarShipKind(order);
      if (!kind || kind === 'closed') return;
      var dateKey = adminRecordDateKey(order);
      if (!adminWithinLastMonth(dateKey)) return;
      var overlap = [];
      (Array.isArray(order.items) ? order.items : []).forEach(function (item) {
        var key = adminDuplicateProductKey(item);
        if (key && productKeys[key]) overlap.push(item);
      });
      if (!overlap.length) return;
      skip[String(order.id || '')] = true;
      rows.push({
        id: String(order.id || ''),
        sourceType: sourceType,
        kind: kind,
        dateKey: dateKey,
        name: (order.customer && order.customer.name) || order.customerName || order.name || '-',
        phone: (order.customer && order.customer.phone) || order.customerPhone || order.phone || '-',
        tracking: order.trackingNo || order.shippingTrackingNo || '',
        carrier: order.shippingCarrier || order.carrier || '',
        delivery: order.deliveryState || order.status || kind,
        overlap: overlap
      });
    }
    (state.orders || []).forEach(function (order) { consider(order, 'order'); });
    (state.inquiries || []).forEach(function (row) { consider(row, 'inquiry'); });
    rows.sort(function (a, b) { return String(b.dateKey).localeCompare(String(a.dateKey)); });
    return rows.slice(0, 10);
  }
  function freightFifoEnsureSimilarShipStyle() {
    if (document.getElementById('freight-similar-ship-style')) return;
    var style = document.createElement('style');
    style.id = 'freight-similar-ship-style';
    style.textContent = '.freight-similar-ship-dialog{position:fixed;inset:0;z-index:12050;display:grid;place-items:center;padding:16px;background:rgba(18,10,16,.72)}'
      + '.freight-similar-ship-card{width:min(640px,100%);max-height:min(86vh,900px);overflow:auto;display:grid;gap:12px;padding:18px;border:2px solid #f0bd54;border-radius:18px;background:#2a1d27;color:#fff8ed}'
      + '.freight-similar-ship-card h3{margin:0;color:#f0bd54;font-size:22px}'
      + '.freight-similar-ship-card p{margin:0;color:#eadde8;line-height:1.5;font-weight:700}'
      + '.freight-similar-ship-item{padding:12px;border:1px solid #8c728d;border-radius:14px;background:#3a2e3d}'
      + '.freight-similar-ship-item b{display:block;margin-bottom:4px}'
      + '.freight-similar-ship-item.is-shipped,.freight-similar-ship-item.is-returned{border-color:#ffadb0}'
      + '.freight-similar-ship-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px}'
      + '.freight-similar-ship-actions button{min-height:48px;border-radius:12px;font-weight:900;cursor:pointer}'
      + '.freight-similar-ship-actions .is-go{border:0;background:#f0bd54;color:#1a1214}'
      + '.freight-similar-ship-actions .is-stop{border:1px solid #ffadb0;background:#6b3840;color:#fff}';
    document.head.appendChild(style);
  }
  function freightFifoShowSimilarShipDialog(id, matches, onContinue) {
    freightFifoEnsureSimilarShipStyle();
    var existing = document.querySelector('[data-freight-similar-ship-dialog]');
    if (existing) existing.remove();
    var source = freightFifoSimilarSourceRow(id) || {};
    var customer = source.customer || {};
    var dialog = document.createElement('div');
    dialog.className = 'freight-similar-ship-dialog';
    dialog.setAttribute('data-freight-similar-ship-dialog', '');
    dialog.innerHTML = '<section class="freight-similar-ship-card">'
      + '<h3>一個月內有同樣出貨紀錄，請先比對</h3>'
      + '<p>客人 ' + escapeHtml(customer.name || source.customerName || source.name || '-') + '／' + escapeHtml(customer.phone || source.customerPhone || source.phone || '-') + '。下面是 31 天內同一商品的單，先看有沒有出過再決定要不要出。</p>'
      + matches.map(function (row) {
        return '<article class="freight-similar-ship-item is-' + escapeHtml(row.kind) + '"><b>' + escapeHtml(freightFifoSimilarShipKindLabel(row.kind)) + '｜' + escapeHtml(row.dateKey || '-') + '</b>'
          + '<span>' + escapeHtml(row.id) + (row.tracking ? '／物流 ' + row.tracking : '／還沒有物流單號') + (row.carrier ? '／' + row.carrier : '') + '</span>'
          + '<small>' + escapeHtml(row.overlap.map(freightFifoSimilarItemLine).join('；')) + '</small></article>';
      }).join('')
      + '<div class="freight-similar-ship-actions"><button type="button" class="is-stop" data-similar-ship-stop>先不要出，我去核對</button><button type="button" class="is-go" data-similar-ship-go>確認沒重複，繼續出貨</button></div>'
      + '</section>';
    function close() { if (dialog.parentNode) dialog.parentNode.removeChild(dialog); }
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || event.target.closest('[data-similar-ship-stop]')) {
        close();
        return;
      }
      if (event.target.closest('[data-similar-ship-go]')) {
        close();
        if (typeof onContinue === 'function') onContinue();
      }
    });
    document.body.appendChild(dialog);
  }
  function freightFifoGuardSimilarShip(id, trigger, onContinue) {
    if (trigger && trigger.getAttribute && trigger.getAttribute('data-similar-ship-ok') === '1') return false;
    var matches = freightFifoSimilarShipRecords(id);
    if (!matches.length) return false;
    freightFifoShowSimilarShipDialog(id, matches, function () {
      if (trigger && trigger.setAttribute) trigger.setAttribute('data-similar-ship-ok', '1');
      if (typeof onContinue === 'function') onContinue();
    });
    return true;
  }
`;

const KIND_OLD = `  function adminDuplicateKindLabel(kind) {
    if (kind === 'mixed') return '出過一張，網站／未出還有一張';
    if (kind === 'open') return '兩張都還沒出貨';
    return '已出過的重複客人';
  }
  function adminDuplicateProductGroups() {`;

const KIND_NEW = `  function adminDuplicateKindLabel(kind) {
    if (kind === 'mixed') return '出過一張，網站／未出還有一張';
    if (kind === 'open') return '兩張都還沒出貨';
    return '已出過的重複客人';
  }
${HELPERS}  function adminDuplicateProductGroups() {`;

const SELECT_OLD = `  function setFreightFifoCustomerSelection(button, selected) {
    var inquiryId = String(button && (button.getAttribute(selected ? 'data-freight-fifo-select' : 'data-freight-fifo-unselect')) || '');
    if (!inquiryId) return;`;

const SELECT_NEW = `  function setFreightFifoCustomerSelection(button, selected) {
    var inquiryId = String(button && (button.getAttribute(selected ? 'data-freight-fifo-select' : 'data-freight-fifo-unselect')) || '');
    if (!inquiryId) return;
    if (selected && freightFifoGuardSimilarShip(inquiryId, button, function () {
      setFreightFifoCustomerSelection(button, true);
    })) return;`;

const OPEN_OLD = `  function openFreightFifoShipmentSafe(preorderId, formalOrderId, sourceButton) {
    var existing = document.querySelector('[data-freight-fifo-modal]');
    if (existing) existing.remove();`;

const OPEN_NEW = `  function openFreightFifoShipmentSafe(preorderId, formalOrderId, sourceButton) {
    if (freightFifoGuardSimilarShip(String(preorderId || formalOrderId || ''), sourceButton, function () {
      openFreightFifoShipmentSafe(preorderId, formalOrderId, sourceButton);
    })) return;
    var existing = document.querySelector('[data-freight-fifo-modal]');
    if (existing) existing.remove();`;

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

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "similar-ship"));
let src = fs.readFileSync(ADMIN_JS, "utf8");
if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(src, KIND_OLD, KIND_NEW, "similar-ship helpers");
  src = replaceOnce(src, SELECT_OLD, SELECT_NEW, "guard 加入本次配送");
  src = replaceOnce(src, OPEN_OLD, OPEN_NEW, "guard 開啟出貨單");
} else {
  console.log("js similar-ship already present");
}
if (src.indexOf(MARKER) === -1) throw new Error("marker missing after patch");
if (src.indexOf("一個月內有同樣出貨紀錄") === -1) throw new Error("dialog copy missing");
fs.writeFileSync(ADMIN_JS, src, "utf8");
console.log("js written", ADMIN_JS, "len", src.length);
stampHtml(ROOT);
console.log("LINGZANZAN similar-ship popup ok", STAMP);
