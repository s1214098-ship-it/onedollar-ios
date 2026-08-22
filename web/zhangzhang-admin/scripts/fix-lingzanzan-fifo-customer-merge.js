#!/usr/bin/env node
"use strict";

/**
 * FIFO「此客戶已打單／出貨單內容」可合併未出貨單。
 * 已完成配送、已到店、寄庫單不併入。每列「開啟出貨單」留著，
 * 有些客人會先出、有些才寄庫。
 *
 * Live: assets/admin.js
 * 不 stamp 全站 HTML，避免頁面閃重整。
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260822-customer-merge-1";
const JS_MARKER = "function freightFifoCustomerOrderCanMerge(";

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

const HELPERS = `
  function freightFifoCustomerOrderIsHold(row) {
    row = row || {};
    var reason = String(row.reservedShippingHoldReason || '').trim();
    if (reason && reason !== 'scheduled_ship') return true;
    var note = [row.shippingNote, row.reservedShippingNote, row.reservedShippingHoldReasonText].join(' ');
    return /寄庫|等待直播|等直播|先不出貨/.test(note);
  }

  function freightFifoCustomerOrderCanMerge(row) {
    if (!row || row.cancelled === true) return false;
    var id = String(row.id || '').trim();
    if (!id) return false;
    var delivery = String(row.deliveryState || '').toLowerCase();
    if (['arrived_store', 'in_transit', 'delivered', 'returned', 'shipped'].indexOf(delivery) !== -1) return false;
    var key = freightFifoCustomerOrderStatusKey(row);
    if (['cancelled', 'returned', 'delivered', 'transit', 'arrived'].indexOf(key) !== -1) return false;
    var status = String(row.status || '').toLowerCase();
    if (['shipped', 'in_transit', 'delivered', 'completed', 'returned', 'cancelled', 'canceled', 'refunded', 'closed'].indexOf(status) !== -1) return false;
    if (freightFifoCustomerOrderIsHold(row)) return false;
    return true;
  }

  function freightFifoCustomerOrderMergeIds(rows) {
    var ids = [];
    var seen = {};
    (rows || []).forEach(function (row) {
      if (!freightFifoCustomerOrderCanMerge(row)) return;
      var id = String(row.id || '').trim();
      if (!id || seen[id]) return;
      seen[id] = true;
      ids.push(id);
    });
    return ids;
  }

  function freightFifoCustomerOrderMergeButtonLabel(count) {
    var n = Math.max(0, Number(count || 0));
    return n >= 2 ? ('合併未出貨 ' + n + ' 張一起出') : '';
  }

  function freightFifoCustomerOrderMergeButtonHtml(rows, forDialog) {
    var ids = freightFifoCustomerOrderMergeIds(rows);
    var label = freightFifoCustomerOrderMergeButtonLabel(ids.length);
    if (!label) return '';
    var cls = forDialog ? 'is-go' : 'primary-button';
    return '<button type="button" class="' + cls + '" data-freight-fifo-customer-orders-merge="' + escapeHtml(ids.join(',')) + '">' + escapeHtml(label) + '</button>';
  }

  function freightFifoResolveCustomerOrderMergeIds(ids) {
    ids = (ids || []).map(function (id) { return String(id || '').trim(); }).filter(Boolean);
    var orderIds = {};
    (state.orders || []).forEach(function (order) {
      var id = String(order && order.id || '').trim();
      if (id) orderIds[id] = true;
    });
    var resolved = [];
    var skipped = [];
    var seen = {};
    ids.forEach(function (id) {
      var row = ((state.orders || []).concat(state.inquiries || [])).find(function (item) {
        return item && String(item.id || '') === id;
      }) || {};
      var converted = String(row.convertedOrderId || row.convertedToOrderId || row.orderId || '').trim();
      var formal = typeof freightFifoFormalOrderForInquiry === 'function' ? freightFifoFormalOrderForInquiry(id) : null;
      var formalId = String((formal && formal.id) || converted || '');
      if (orderIds[id]) {
        if (!seen[id]) { seen[id] = true; resolved.push(id); }
        return;
      }
      if (formalId && orderIds[formalId]) {
        if (!seen[formalId]) { seen[formalId] = true; resolved.push(formalId); }
        return;
      }
      skipped.push(id);
    });
    return { resolved: resolved, skipped: skipped };
  }

  function freightFifoMergeCustomerOrders(ids, button) {
    ids = String(ids || '').split(',').map(function (id) { return String(id || '').trim(); }).filter(Boolean);
    if (ids.indexOf('BYORDER-20260720-078596') !== -1) {
      toast('這組訂單不能合併');
      return;
    }
    var blockedPhone = false;
    var phones = {};
    ids.forEach(function (id) {
      var row = ((state.orders || []).concat(state.inquiries || [])).find(function (item) {
        return item && String(item.id || '') === id;
      });
      var phone = String(((row && row.customer) || {}).phone || (row && (row.customerPhone || row.phone)) || '').replace(/\\D/g, '');
      if (phone === '16346546308') blockedPhone = true;
      if (phone.length >= 6) phones[phone] = true;
    });
    if (blockedPhone) {
      toast('這組訂單不能合併');
      return;
    }
    if (Object.keys(phones).length > 1) {
      toast('所選訂單電話不同，不能合併');
      return;
    }
    var found = freightFifoResolveCustomerOrderMergeIds(ids);
    if (found.resolved.length < 2) {
      toast(found.skipped.length ? '未出貨單還沒轉成正式出貨單，請先開出貨單再合併' : '未出貨單不足 2 張，不能合併');
      return;
    }
    var copy = '確定把這位客戶未出貨的 ' + found.resolved.length + ' 張訂單合併一起出嗎？運費只保留一次。已完成配送與寄庫單不會併入；每張出貨單仍可單獨開啟。';
    if (found.skipped.length) copy += '\\n\\n這幾張還不是正式出貨單，先跳過：' + found.skipped.join('、');
    if (!window.confirm(copy)) return;
    if (button) button.disabled = true;
    callOrderAdmin({ action: 'merge-shipment', orderIds: found.resolved }).then(function (data) {
      if (Array.isArray(data.orders)) state.orders = data.orders;
      var dialog = document.querySelector('[data-freight-customer-orders-dialog]');
      if (dialog && dialog.parentNode) dialog.parentNode.removeChild(dialog);
      if (typeof renderFreightCustomerShipmentQueue === 'function') renderFreightCustomerShipmentQueue();
      else if (typeof renderAll === 'function') renderAll();
      toast('已合併未出貨 ' + found.resolved.length + ' 張，運費只計一次。出貨單仍可單獨開啟。');
      var master = String(data.masterOrderId || found.resolved[0] || '');
      if (master && typeof openFreightFifoShipmentSafe === 'function') {
        window.setTimeout(function () { openFreightFifoShipmentSafe('', master); }, 120);
      }
    }).catch(function (error) {
      if (button) button.disabled = false;
      toast(error.message || '合單失敗');
    });
  }

  function freightFifoOpenCustomerHoldList(id) {
    id = String(id || '').trim();
    window.location.href = './admin-reserved-shipping.html' + (id ? ('?order=' + encodeURIComponent(id)) : '');
  }

`;

const HELPERS_OLD = `  function freightFifoCollectSearchCustomerOrders() {`;
const HELPERS_NEW = HELPERS + `  function freightFifoCollectSearchCustomerOrders() {`;

const SECTION_OLD = `    return '<section class="freight-fifo-search-pending is-customer-orders"><header><div><b>此客戶已打單／出貨單內容</b><small>待配貨四格只列出可配貨的單。直播打單還在「接單確認中」、或已取消的詢問單，會出現在這裡。點彈窗可看出貨內容，再開出貨單加商品。</small></div><strong>' + rows.length + ' 張</strong><button type="button" class="primary-button" data-freight-fifo-customer-orders-open>彈出訂單內容</button></header>'`;

const SECTION_NEW = `    var mergeBtn = freightFifoCustomerOrderMergeButtonHtml(rows, false);
    return '<section class="freight-fifo-search-pending is-customer-orders"><header><div><b>此客戶已打單／出貨單內容</b><small>待配貨四格只列出可配貨的單。直播打單還在「接單確認中」、或已取消的詢問單，會出現在這裡。未出貨單可合併一起出（已完成配送與寄庫不併入）。每張仍可單獨開出貨單。</small></div><strong>' + rows.length + ' 張</strong><button type="button" class="primary-button" data-freight-fifo-customer-orders-open>彈出訂單內容</button>' + mergeBtn + '</header>'`;

const DIALOG_P_OLD = `      + '<p>' + escapeHtml(customer.name || first.customerName || '未填客戶') + '／' + escapeHtml(customer.phone || first.customerPhone || freightFifoQueueSearch || '-') + '。下面是搜尋到的訂單內容，可直接開啟出貨單修改或加商品。</p>'
      + rows.map(function (row) { return freightFifoCustomerOrderArticleHtml(row, true); }).join('')
      + '<div class="freight-customer-orders-actions"><button type="button" class="is-stop" data-freight-customer-orders-close>關閉</button></div>'`;

const DIALOG_P_NEW = `      + '<p>' + escapeHtml(customer.name || first.customerName || '未填客戶') + '／' + escapeHtml(customer.phone || first.customerPhone || freightFifoQueueSearch || '-') + '。下面是搜尋到的訂單內容。未出貨單可合併一起出；已完成配送與寄庫單不併入。每張出貨單仍可單獨開啟。</p>'
      + rows.map(function (row) { return freightFifoCustomerOrderArticleHtml(row, true); }).join('')
      + '<div class="freight-customer-orders-actions">' + freightFifoCustomerOrderMergeButtonHtml(rows, true) + '<button type="button" class="is-stop" data-freight-customer-orders-close>關閉</button></div>'`;

const DIALOG_CLICK_OLD = `      var openBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-order-open]');
      if (!openBtn) return;`;

const DIALOG_CLICK_NEW = `      var mergeBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-orders-merge]');
      if (mergeBtn) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoMergeCustomerOrders(mergeBtn.getAttribute('data-freight-fifo-customer-orders-merge') || '', mergeBtn);
        return;
      }
      var holdOpenBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-hold-open]');
      if (holdOpenBtn) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoOpenCustomerHoldList(holdOpenBtn.getAttribute('data-freight-fifo-customer-hold-open') || '');
        return;
      }
      var openBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-order-open]');
      if (!openBtn) return;`;

const CLICK_VAR_OLD = `      var customerOrdersOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-open]') : null;
      var customerOrderOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-order-open]') : null;`;

const CLICK_VAR_NEW = `      var customerOrdersOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-open]') : null;
      var customerOrdersMerge = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-merge]') : null;
      var customerHoldOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-hold-open]') : null;
      var customerOrderOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-order-open]') : null;`;

const CLICK_OLD = `      if (customerOrdersOpen) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoShowCustomerOrdersDialog(freightFifoCollectSearchCustomerOrders());
        return;
      }`;

const CLICK_NEW = `      if (customerOrdersOpen) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoShowCustomerOrdersDialog(freightFifoCollectSearchCustomerOrders());
        return;
      }
      if (customerOrdersMerge) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoMergeCustomerOrders(customerOrdersMerge.getAttribute('data-freight-fifo-customer-orders-merge') || '', customerOrdersMerge);
        return;
      }
      if (customerHoldOpen) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoOpenCustomerHoldList(customerHoldOpen.getAttribute('data-freight-fifo-customer-hold-open') || '');
        return;
      }`;

const STATUS_KEY_OLD = `  function freightFifoCustomerOrderStatusKey(row) {
    row = row || {};
    var status = String(row.status || '').toLowerCase();
    var delivery = String(row.deliveryState || '').toLowerCase();
    if (status === 'cancelled' || status === 'canceled') return 'cancelled';
    if (status === 'returned' || delivery === 'returned') return 'returned';
    if (status === 'delivered' || status === 'completed' || delivery === 'delivered') return 'delivered';
    if (status === 'confirming' || status === 'draft' || status === 'pending') return 'draft';
    if (delivery === 'in_transit' || status === 'shipped' || status === 'in_transit') return 'transit';
    if (delivery === 'arrived_store') return 'arrived';
    return 'open';
  }`;

const STATUS_KEY_NEW = `  function freightFifoCustomerOrderStatusKey(row) {
    row = row || {};
    var status = String(row.status || '').toLowerCase();
    var delivery = String(row.deliveryState || '').toLowerCase();
    if (status === 'cancelled' || status === 'canceled') return 'cancelled';
    if (status === 'returned' || delivery === 'returned') return 'returned';
    if (status === 'delivered' || status === 'completed' || delivery === 'delivered') return 'delivered';
    if (delivery === 'in_transit' || status === 'shipped' || status === 'in_transit') return 'transit';
    if (delivery === 'arrived_store') return 'arrived';
    if (typeof freightFifoCustomerOrderIsHold === 'function' && freightFifoCustomerOrderIsHold(row)) return 'hold';
    if (status === 'confirming' || status === 'draft' || status === 'pending') return 'draft';
    return 'open';
  }`;

const STATUS_LABEL_OLD = `      delivered: '已完成配送',
      draft: '已打單／接單確認中',
      transit: '配送中',
      arrived: '已到店'`;

const STATUS_LABEL_NEW = `      delivered: '已完成配送',
      hold: '已寄庫',
      draft: '已打單／接單確認中',
      transit: '配送中',
      arrived: '已到店'`;

const STYLE_OLD = `      + '.freight-customer-orders-item.is-draft{border-color:#f0bd54}'
      + '.freight-customer-orders-item.is-cancelled,.freight-customer-orders-item.is-returned{border-color:#ffadb0}'`;

const STYLE_NEW = `      + '.freight-customer-orders-item.is-draft{border-color:#f0bd54}'
      + '.freight-customer-orders-item.is-hold,.freight-fifo-search-pending.is-customer-orders article.is-hold{border-color:#7ec8ff}'
      + '.freight-customer-orders-item.is-hold b,.freight-fifo-search-pending.is-customer-orders article.is-hold .freight-fifo-search-pending-status{color:#7ec8ff}'
      + '.freight-customer-orders-item.is-cancelled,.freight-customer-orders-item.is-returned{border-color:#ffadb0}'`;

const ARTICLE_OLD = `    var openBtn = '<button type="button" class="' + (forDialog ? 'is-go' : 'primary-button') + '" data-similar-ship-ok="1" data-freight-fifo-customer-order-open="' + escapeHtml(row.id || '') + '" data-freight-fifo-customer-order-kind="' + escapeHtml(kind) + '">開啟出貨單（可加商品）</button>';
    if (forDialog) {
      return '<article class="freight-customer-orders-item is-' + escapeHtml(statusKey) + '"><b>' + escapeHtml(statusLabel) + '｜' + escapeHtml(String(row.createdAt || row.orderDate || '').slice(0, 16).replace('T', ' ') || '-') + '</b>'
        + '<span>' + escapeHtml(row.id || '-') + (row.trackingNo || row.shippingTrackingNo ? '／物流 ' + (row.trackingNo || row.shippingTrackingNo) : '／還沒有物流單號') + '</span>'
        + itemHtml
        + '<div class="freight-customer-orders-actions">' + openBtn + '</div></article>';
    }
    return '<article><div><b>' + escapeHtml(customer.name || row.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || row.customerPhone || '未填電話') + '</span><small>' + escapeHtml((row.id || '-') + '／' + statusLabel) + '</small></div>'
      + itemHtml
      + '<div class="freight-fifo-search-pending-actions"><strong class="freight-fifo-search-pending-status">' + escapeHtml(statusLabel) + '</strong>' + openBtn + '</div></article>';`;

const ARTICLE_NEW = `    var openBtn = '<button type="button" class="' + (forDialog ? 'is-go' : 'primary-button') + '" data-similar-ship-ok="1" data-freight-fifo-customer-order-open="' + escapeHtml(row.id || '') + '" data-freight-fifo-customer-order-kind="' + escapeHtml(kind) + '">開啟出貨單（可加商品）</button>';
    var holdBtn = statusKey === 'hold'
      ? '<button type="button" class="' + (forDialog ? 'is-stop' : 'ghost-button') + '" data-freight-fifo-customer-hold-open="' + escapeHtml(row.id || '') + '">看寄庫名單</button>'
      : '';
    if (forDialog) {
      return '<article class="freight-customer-orders-item is-' + escapeHtml(statusKey) + '"><b>' + escapeHtml(statusLabel) + '｜' + escapeHtml(String(row.createdAt || row.orderDate || '').slice(0, 16).replace('T', ' ') || '-') + '</b>'
        + '<span>' + escapeHtml(row.id || '-') + (row.trackingNo || row.shippingTrackingNo ? '／物流 ' + (row.trackingNo || row.shippingTrackingNo) : '／還沒有物流單號') + '</span>'
        + itemHtml
        + '<div class="freight-customer-orders-actions">' + openBtn + holdBtn + '</div></article>';
    }
    return '<article class="is-' + escapeHtml(statusKey) + '"><div><b>' + escapeHtml(customer.name || row.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || row.customerPhone || '未填電話') + '</span><small>' + escapeHtml((row.id || '-') + '／' + statusLabel) + '</small></div>'
      + itemHtml
      + '<div class="freight-fifo-search-pending-actions"><strong class="freight-fifo-search-pending-status">' + escapeHtml(statusLabel) + '</strong>' + openBtn + holdBtn + '</div></article>';`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "customer-merge"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, HELPERS_OLD, HELPERS_NEW, "customer merge helpers");
  js = replaceOnce(js, STATUS_KEY_OLD, STATUS_KEY_NEW, "hold status key 已寄庫");
  js = replaceOnce(js, STATUS_LABEL_OLD, STATUS_LABEL_NEW, "hold status label");
  js = replaceOnce(js, STYLE_OLD, STYLE_NEW, "hold row style");
  js = replaceOnce(js, ARTICLE_OLD, ARTICLE_NEW, "hold 看寄庫名單 + keep 出貨");
  js = replaceOnce(js, SECTION_OLD, SECTION_NEW, "section merge button");
  js = replaceOnce(js, DIALOG_P_OLD, DIALOG_P_NEW, "dialog merge button");
  js = replaceOnce(js, DIALOG_CLICK_OLD, DIALOG_CLICK_NEW, "dialog merge click");
  js = replaceOnce(js, CLICK_VAR_OLD, CLICK_VAR_NEW, "section merge click var");
  js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "section merge click");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("merge helper missing after patch");
if (js.indexOf("data-freight-fifo-customer-orders-merge") === -1) throw new Error("merge button missing after patch");
if (js.indexOf("開啟出貨單（可加商品）") === -1) throw new Error("open shipment button removed");
if (js.indexOf("已寄庫") === -1) throw new Error("已寄庫 label missing");
if (js.indexOf("data-freight-fifo-customer-hold-open") === -1) throw new Error("看寄庫名單 missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
console.log("LINGZANZAN fifo customer order merge ok", STAMP);
console.log("no html stamp");
