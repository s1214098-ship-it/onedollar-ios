#!/usr/bin/env node
"use strict";

/**
 * 寄庫單可刪除並把商品撥回原本倉庫；寄庫名單可列印客戶明細給業務。
 * 「取消寄庫」仍只移出名單、不刪單。不改 .is-active 金鈕。
 *
 * Cache-bust: admin.js ?v=20260822-hold-delete-1
 * admin-reserved-shipping.html is UTF-8.
 */

const fs = require("fs");
const path = require("path");
const {
  reservedHoldDeleteUsesDeleteAction,
  reservedHoldPrintButtonInHtml,
  reservedHoldDoesNotRestyleActive,
} = require("./lz-reserved-hold-delete-print");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const HTML = path.join(ROOT, "admin-reserved-shipping.html");
const STAMP = "20260822-hold-delete-1";

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

const CARD_OLD = `'<button type="button" class="ghost-button" data-reserved-cancel="' + escapeHtml(order.id || '') + '">' + (isHold ? '取消寄庫' : '取消預約') + '</button></div></footer></article>';`;

const CARD_NEW = `'<button type="button" class="ghost-button" data-reserved-cancel="' + escapeHtml(order.id || '') + '">' + (isHold ? '取消寄庫' : '取消預約') + '</button>' +
      (isHold ? '<button type="button" class="ghost-button" data-reserved-hold-delete="' + escapeHtml(order.id || '') + '">刪除寄庫並撥回倉庫</button>' : '') +
      '</div></footer></article>';`;

const HELPER_OLD = `  function reservedShippingCandidateHtml(order, today) {`;

const HELPER_NEW = `  function reservedHoldWarehouseSummary(order) {
    order = order || {};
    var seen = {};
    (Array.isArray(order.items) ? order.items : []).forEach(function (item) {
      if (!item) return;
      var label = String(item.sourceWarehouse || item.warehouseName || item.warehouse || item.sourceWarehouseCode || item.warehouseCode || '').trim();
      if (label) seen[label] = true;
    });
    var labels = Object.keys(seen);
    if (labels.length) return labels.join('、');
    return String(order.warehouse || order.warehouseCode || '原倉').trim() || '原倉';
  }

  function reservedHoldItemSummary(order) {
    return ((order && order.items) || []).map(function (item) {
      return String((item && (item.code || item.title || item.name)) || '商品') + ' ×' + Math.max(0, Number(item && (item.qty || item.quantity) || 0));
    }).filter(Boolean).join('、') || '尚無商品明細';
  }

  function deleteReservedHoldAndRestock(button) {
    var card = button && button.closest && button.closest('[data-reserved-order]');
    var orderId = String((button && button.getAttribute('data-reserved-hold-delete')) || (card && card.getAttribute('data-reserved-order')) || '').trim();
    var source = String(card && card.getAttribute('data-reserved-source') || 'order');
    if (!orderId) { toast('找不到這張寄庫單'); return; }
    var order = ((state.orders || []).concat(state.inquiries || [])).find(function (row) { return String(row && row.id || '') === orderId; });
    if (!order) { toast('找不到訂單，請重新整理'); return; }
    var status = String(order.status || '').toLowerCase();
    var delivery = String(order.deliveryState || '').toLowerCase();
    if (status === 'shipped' || status === 'in_transit' || delivery === 'in_transit' || delivery === 'arrived_store' || delivery === 'delivered') {
      toast('這張已交寄或已到店，不能直接刪除。請先作廢物流，或改用退貨還原庫存。');
      return;
    }
    if (String(order.shipmentMergeGroupId || '').trim()) {
      toast('這張屬於合併出貨單，請先解除合併再刪除，避免只退其中一張。');
      return;
    }
    var customer = order.customer || {};
    var name = customer.name || order.customerName || order.name || '未填姓名';
    var phone = customer.phone || order.customerPhone || order.phone || '未填電話';
    var warehouse = reservedHoldWarehouseSummary(order);
    var items = reservedHoldItemSummary(order);
    if (!window.confirm('確定刪除這張寄庫單，並把商品撥回原本倉庫？\\n' + name + '／' + phone + '\\n' + items + '\\n庫存回到：' + warehouse + '\\n訂單會刪除。若只要移出名單、訂單留著，請按「取消寄庫」。')) return;
    button.disabled = true;
    var login = currentLogin();
    var request = source === 'inquiry'
      ? callStockInquiryApi({ action: 'delete', inquiryId: orderId, reason: '刪除寄庫並撥回倉庫', deletedBy: login.name || login.account || '行政人員' })
      : callOrderAdmin({ action: 'delete', orderId: orderId, reason: '刪除寄庫並撥回倉庫', deletedBy: login.name || login.account || '行政人員' });
    request.then(function (data) {
      if (source === 'inquiry') {
        state.inquiries = (state.inquiries || []).filter(function (row) { return String(row && row.id || '') !== orderId; });
      } else if (Array.isArray(data && data.orders)) {
        state.orders = data.orders;
      } else {
        state.orders = (state.orders || []).filter(function (row) { return String(row && row.id || '') !== orderId; });
      }
      renderReservedShippingWorkbench();
      toast('已刪除寄庫單，商品已撥回' + warehouse);
      return refreshOrdersAndInquiries();
    }).then(function () {
      renderReservedShippingWorkbench();
    }).catch(function (error) {
      toast(error.message || '刪除寄庫失敗');
    }).finally(function () {
      button.disabled = false;
    });
  }

  function reservedShippingCandidateHtml(order, today) {`;

const CLICK_OLD = `      var button = event.target.closest('[data-reserved-save], [data-reserved-cancel], [data-reserved-merge]');
      if (!button) return;`;

const CLICK_NEW = `      var holdDeleteBtn = event.target.closest('[data-reserved-hold-delete]');
      if (holdDeleteBtn) {
        event.preventDefault();
        deleteReservedHoldAndRestock(holdDeleteBtn);
        return;
      }
      var button = event.target.closest('[data-reserved-save], [data-reserved-cancel], [data-reserved-merge]');
      if (!button) return;`;

const HTML_OLD = `        <section class="reserved-shipping-hold">
          <header><div><p>HOLD LIST</p><h2>寄庫名單</h2><span>先不出貨的單在這裡。搜電話、姓名，或直接打「寄庫」。今天要出／逾期只篩上面預約排單，不會把寄庫藏起來。</span></div></header>
          <div class="reserved-shipping-list" data-reserved-hold-list><p>正在讀取…</p></div>`;

const HTML_NEW = `        <section class="reserved-shipping-hold">
          <header><div><p>HOLD LIST</p><h2>寄庫名單</h2><span>先不出貨的單在這裡。搜電話、姓名，或直接打「寄庫」。今天要出／逾期只篩上面預約排單，不會把寄庫藏起來。</span></div><div class="reserved-shipping-title-actions"><button type="button" class="ghost-button" data-reserved-print="hold">列印寄庫清單給業務</button></div></header>
          <div class="reserved-shipping-list" data-reserved-hold-list><p>正在讀取…</p></div>`;

if (!reservedHoldDoesNotRestyleActive(CARD_NEW + HELPER_NEW + HTML_NEW)) {
  throw new Error("refusing to restyle .is-active");
}

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(HTML)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "hold-delete");
backup(HTML, "hold-delete");

let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, CARD_OLD, CARD_NEW, "hold card delete+restock button");
js = replaceOnce(js, HELPER_OLD, HELPER_NEW, "deleteReservedHoldAndRestock helper");
js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "hold delete click");
fs.writeFileSync(ADMIN_JS, js);

let html = fs.readFileSync(HTML, "utf8");
html = replaceOnce(html, HTML_OLD, HTML_NEW, "print hold list button");
html = html
  .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
  .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
fs.writeFileSync(HTML, html);

if (!reservedHoldDeleteUsesDeleteAction(js)) throw new Error("hold delete missing after js patch");
if (!reservedHoldPrintButtonInHtml(html)) throw new Error("hold print button missing after html patch");
console.log("LINGZANZAN reserved hold delete+print ok", STAMP);
