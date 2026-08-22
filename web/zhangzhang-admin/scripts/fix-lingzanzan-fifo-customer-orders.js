#!/usr/bin/env node
"use strict";

/**
 * FIFO 搜尋電話時，直播打單（confirming）與已取消詢問單不在待配貨四格，
 * 行政會以為沒打單。補上「此客戶已打單」區塊＋彈窗看出貨內容，
 * 並可直接開啟出貨單加商品。
 *
 * Live: assets/admin.js
 * Cache-bust: file mtime (asset-boot) + stamp fallback
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260822-customer-orders-1";
const JS_MARKER = "function freightFifoCollectSearchCustomerOrders(";

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
  const names = [
    "admin-freight.html",
    "admin-reserved-shipping.html",
    "admin-orders.html",
    "admin-order-tracking.html",
    "admin-preorders.html",
    "admin-live.html",
    "admin.html",
  ];
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

const VARS_OLD = `  var freightFifoQueueSearch = '';
  var freightFifoProductScope = null;`;

const VARS_NEW = `  var freightFifoQueueSearch = '';
  var freightFifoCustomerOrdersDialogPending = false;
  var freightFifoProductScope = null;`;

const HELPERS = `
  function freightFifoCustomerOrderIsHold(row) {
    row = row || {};
    var reason = String(row.reservedShippingHoldReason || '').trim();
    if (reason && reason !== 'scheduled_ship') return true;
    var note = [row.shippingNote, row.reservedShippingNote, row.reservedShippingHoldReasonText].join(' ');
    return /寄庫|等待直播|等直播|先不出貨/.test(note);
  }

  function freightFifoCustomerOrderStatusKey(row) {
    row = row || {};
    var status = String(row.status || '').toLowerCase();
    var delivery = String(row.deliveryState || '').toLowerCase();
    if (status === 'cancelled' || status === 'canceled') return 'cancelled';
    if (status === 'returned' || delivery === 'returned') return 'returned';
    if (status === 'delivered' || status === 'completed' || delivery === 'delivered') return 'delivered';
    if (delivery === 'in_transit' || status === 'shipped' || status === 'in_transit') return 'transit';
    if (delivery === 'arrived_store') return 'arrived';
    if (freightFifoCustomerOrderIsHold(row)) return 'hold';
    if (status === 'confirming' || status === 'draft' || status === 'pending') return 'draft';
    return 'open';
  }

  function freightFifoCustomerOrderStatusLabel(row) {
    var key = freightFifoCustomerOrderStatusKey(row);
    return {
      cancelled: '已取消',
      returned: '已退回',
      delivered: '已完成配送',
      hold: '已寄庫',
      draft: '已打單／接單確認中',
      transit: '配送中',
      arrived: '已到店'
    }[key] || String((row && (row.statusLabel || row.status)) || '出貨單');
  }

  function freightFifoCustomerOrderItemLine(item) {
    item = item || {};
    return [
      item.code || item.productCode || item.sku || '-',
      item.title || item.productName || item.name || '',
      '色 ' + (item.color || item.colorName || '未填色'),
      '尺碼 ' + (item.size || item.sizeName || '均碼'),
      '×' + Math.max(0, Number(item.qty || item.quantity || 0))
    ].filter(Boolean).join('／');
  }

  function freightFifoCustomerOrderOpenKind(row) {
    var id = String(row && row.id || '');
    if (!id) return 'inquiry';
    if ((state.orders || []).some(function (order) { return String(order && order.id || '') === id; })) return 'order';
    if (String(row && row.source || '') === 'live-order' || /^LIVE-/i.test(id)) return 'order';
    return 'inquiry';
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

  function freightFifoOpenCustomerHoldList(id) {
    id = String(id || '').trim();
    window.location.href = './admin-reserved-shipping.html' + (id ? ('?order=' + encodeURIComponent(id)) : '');
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

  function freightFifoCollectSearchCustomerOrders() {
    if (!String(freightFifoQueueSearch || '').trim()) return [];
    var seen = {};
    var rows = [];
    function add(row) {
      if (!row || !row.id) return;
      var id = String(row.id);
      if (seen[id]) return;
      if (!freightFifoRowMatchesSearch(row)) return;
      seen[id] = true;
      rows.push(row);
    }
    (state.orders || []).forEach(add);
    (state.inquiries || []).forEach(add);
    rows.sort(function (a, b) { return freightFifoCreatedTime(b) - freightFifoCreatedTime(a); });
    return rows.slice(0, 40);
  }

  function freightFifoEnsureCustomerOrdersStyle() {
    if (typeof document === 'undefined' || !document.getElementById) return;
    if (document.getElementById('freight-customer-orders-style')) return;
    var style = document.createElement('style');
    style.id = 'freight-customer-orders-style';
    style.textContent = '.freight-fifo-search-pending.is-customer-orders{border:2px solid #f0bd54;background:#2a1d27}'
      + '.freight-fifo-search-pending.is-customer-orders header b{color:#f0bd54}'
      + '.freight-fifo-search-pending.is-customer-orders .freight-fifo-search-pending-items{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}'
      + '.freight-fifo-search-pending.is-customer-orders .freight-fifo-search-pending-items span{display:flex;align-items:center;gap:6px;padding:6px 8px;border:1px solid #8c728d;border-radius:10px;background:#3a2e3d}'
      + '.freight-fifo-search-pending.is-customer-orders .freight-fifo-search-pending-items img{width:36px;height:36px;object-fit:cover;border-radius:8px}'
      + '.freight-customer-orders-dialog{position:fixed;inset:0;z-index:12060;display:grid;place-items:center;padding:16px;background:rgba(18,10,16,.72)}'
      + '.freight-customer-orders-card{width:min(720px,100%);max-height:min(88vh,960px);overflow:auto;display:grid;gap:12px;padding:18px;border:2px solid #f0bd54;border-radius:18px;background:#2a1d27;color:#fff8ed}'
      + '.freight-customer-orders-card h3{margin:0;color:#f0bd54;font-size:22px}'
      + '.freight-customer-orders-card p{margin:0;color:#eadde8;line-height:1.5;font-weight:700}'
      + '.freight-customer-orders-item{padding:12px;border:1px solid #8c728d;border-radius:14px;background:#3a2e3d;display:grid;gap:6px}'
      + '.freight-customer-orders-item.is-draft{border-color:#f0bd54}'
      + '.freight-customer-orders-item.is-hold,.freight-fifo-search-pending.is-customer-orders article.is-hold{border-color:#7ec8ff}'
      + '.freight-customer-orders-item.is-hold b,.freight-fifo-search-pending.is-customer-orders article.is-hold .freight-fifo-search-pending-status{color:#7ec8ff}'
      + '.freight-customer-orders-item.is-cancelled,.freight-customer-orders-item.is-returned{border-color:#ffadb0}'
      + '.freight-customer-orders-item b{display:block}'
      + '.freight-customer-orders-item small{color:#eadde8}'
      + '.freight-customer-orders-actions{display:flex;flex-wrap:wrap;gap:8px}'
      + '.freight-customer-orders-actions button{min-height:44px;border-radius:12px;font-weight:900;cursor:pointer}'
      + '.freight-customer-orders-actions .is-go{border:0;background:#f0bd54;color:#1a1214}'
      + '.freight-customer-orders-actions .is-stop{border:1px solid #cbb7c4;background:#3a2e3d;color:#fff8ed}';
    if (document.head) document.head.appendChild(style);
  }

  function freightFifoCustomerOrderArticleHtml(row, forDialog) {
    row = row || {};
    var customer = row.customer || {};
    var items = Array.isArray(row.items) ? row.items : [];
    var kind = freightFifoCustomerOrderOpenKind(row);
    var statusKey = freightFifoCustomerOrderStatusKey(row);
    var statusLabel = freightFifoCustomerOrderStatusLabel(row);
    var itemHtml = items.length ? '<div class="freight-fifo-search-pending-items">' + items.map(function (item) {
      item = item || {};
      var image = item.image || item.colorImage || item.productImage || './assets/brand-logo.jpg';
      return '<span><img src="' + escapeHtml(image) + '" alt="" loading="lazy"><b>' + escapeHtml(item.code || item.productCode || '-') + '</b><small>' + escapeHtml(freightFifoCustomerOrderItemLine(item)) + '</small></span>';
    }).join('') + '</div>' : '<small>這張單還沒有商品列</small>';
    var openBtn = '<button type="button" class="' + (forDialog ? 'is-go' : 'primary-button') + '" data-similar-ship-ok="1" data-freight-fifo-customer-order-open="' + escapeHtml(row.id || '') + '" data-freight-fifo-customer-order-kind="' + escapeHtml(kind) + '">開啟出貨單（可加商品）</button>';
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
      + '<div class="freight-fifo-search-pending-actions"><strong class="freight-fifo-search-pending-status">' + escapeHtml(statusLabel) + '</strong>' + openBtn + holdBtn + '</div></article>';
  }

  function freightFifoCustomerOrdersSectionHtml(rows) {
    freightFifoEnsureCustomerOrdersStyle();
    rows = Array.isArray(rows) ? rows : [];
    if (!rows.length) return '';
    var mergeBtn = freightFifoCustomerOrderMergeButtonHtml(rows, false);
    return '<section class="freight-fifo-search-pending is-customer-orders"><header><div><b>此客戶已打單／出貨單內容</b><small>待配貨四格只列出可配貨的單。直播打單還在「接單確認中」、或已取消的詢問單，會出現在這裡。未出貨單可合併一起出（已完成配送與寄庫不併入）。每張仍可單獨開出貨單。</small></div><strong>' + rows.length + ' 張</strong><button type="button" class="primary-button" data-freight-fifo-customer-orders-open>彈出訂單內容</button>' + mergeBtn + '</header>'
      + rows.slice(0, 20).map(function (row) { return freightFifoCustomerOrderArticleHtml(row, false); }).join('')
      + '</section>';
  }

  function freightFifoShowCustomerOrdersDialog(rows) {
    freightFifoEnsureCustomerOrdersStyle();
    rows = Array.isArray(rows) ? rows : freightFifoCollectSearchCustomerOrders();
    var existing = document.querySelector('[data-freight-customer-orders-dialog]');
    if (existing) existing.remove();
    if (!rows.length) {
      toast('沒有找到這位客戶的出貨單');
      return;
    }
    var first = rows[0] || {};
    var customer = first.customer || {};
    var dialog = document.createElement('div');
    dialog.className = 'freight-customer-orders-dialog';
    dialog.setAttribute('data-freight-customer-orders-dialog', '');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.innerHTML = '<section class="freight-customer-orders-card">'
      + '<h3>相同客戶的出貨單</h3>'
      + '<p>' + escapeHtml(customer.name || first.customerName || '未填客戶') + '／' + escapeHtml(customer.phone || first.customerPhone || freightFifoQueueSearch || '-') + '。下面是搜尋到的訂單內容。未出貨單可合併一起出；已完成配送與寄庫單不併入。每張出貨單仍可單獨開啟。</p>'
      + rows.map(function (row) { return freightFifoCustomerOrderArticleHtml(row, true); }).join('')
      + '<div class="freight-customer-orders-actions">' + freightFifoCustomerOrderMergeButtonHtml(rows, true) + '<button type="button" class="is-stop" data-freight-customer-orders-close>關閉</button></div>'
      + '</section>';
    function close() { if (dialog.parentNode) dialog.parentNode.removeChild(dialog); }
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || (event.target.closest && event.target.closest('[data-freight-customer-orders-close]'))) {
        close();
        return;
      }
      var holdOpenBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-hold-open]');
      if (holdOpenBtn) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoOpenCustomerHoldList(holdOpenBtn.getAttribute('data-freight-fifo-customer-hold-open') || '');
        return;
      }
      var mergeBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-orders-merge]');
      if (mergeBtn) {
        event.preventDefault();
        event.stopPropagation();
        freightFifoMergeCustomerOrders(mergeBtn.getAttribute('data-freight-fifo-customer-orders-merge') || '', mergeBtn);
        return;
      }
      var openBtn = event.target.closest && event.target.closest('[data-freight-fifo-customer-order-open]');
      if (!openBtn) return;
      event.preventDefault();
      event.stopPropagation();
      var id = openBtn.getAttribute('data-freight-fifo-customer-order-open') || '';
      var kind = openBtn.getAttribute('data-freight-fifo-customer-order-kind') || 'order';
      close();
      if (kind === 'inquiry') openFreightFifoShipmentSafe(id, '', openBtn);
      else openFreightFifoShipmentSafe('', id, openBtn);
    });
    document.body.appendChild(dialog);
  }

`;

const MERGE_OLD = `  function freightFifoMergeCustomerKey(row) {`;
const MERGE_NEW = HELPERS + `  function freightFifoMergeCustomerKey(row) {`;

const SEARCH_OLD = `      freightFifoQueueSearch = String((form.querySelector('[data-freight-fifo-search]') || {}).value || '').trim();
      resetFreightFifoSectionPages();`;

const SEARCH_NEW = `      freightFifoQueueSearch = String((form.querySelector('[data-freight-fifo-search]') || {}).value || '').trim();
      freightFifoCustomerOrdersDialogPending = !!freightFifoQueueSearch;
      resetFreightFifoSectionPages();`;

const COLLECT_OLD = `      }).slice(0, 20)
      : [];
    var searchedReadyRows = searching ? allReadyRows : allReadyRows.filter(freightFifoRowMatchesSearch);`;

const COLLECT_NEW = `      }).slice(0, 20)
      : [];
    var searchedCustomerOrders = searching ? freightFifoCollectSearchCustomerOrders() : [];
    var customerOrdersSection = freightFifoCustomerOrdersSectionHtml(searchedCustomerOrders);
    var searchedReadyRows = searching ? allReadyRows : allReadyRows.filter(freightFifoRowMatchesSearch);`;

const CONCAT_OLD = ` + mergeToolbarHtml + deliveredSection + returnedSection + notReadySection + waitingSection + selectedSection + waitNotifySection + idPendingSection + formalSection + transitSection;
    if (window.LingzanzanMemberRisk && typeof window.LingzanzanMemberRisk.refresh === 'function') {
      window.LingzanzanMemberRisk.refresh();
    }
    refreshIndonesiaPendingSection();`;

const CONCAT_NEW = ` + mergeToolbarHtml + customerOrdersSection + deliveredSection + returnedSection + notReadySection + waitingSection + selectedSection + waitNotifySection + idPendingSection + formalSection + transitSection;
    if (freightFifoCustomerOrdersDialogPending) {
      freightFifoCustomerOrdersDialogPending = false;
      if (searchedCustomerOrders.length) {
        window.setTimeout(function () { freightFifoShowCustomerOrdersDialog(searchedCustomerOrders); }, 30);
      }
    }
    if (window.LingzanzanMemberRisk && typeof window.LingzanzanMemberRisk.refresh === 'function') {
      window.LingzanzanMemberRisk.refresh();
    }
    refreshIndonesiaPendingSection();`;

const CLICK_VAR_OLD = `      var orderButton = event.target.closest ? event.target.closest('[data-freight-fifo-order]') : null;
      var cancelOrderButton = event.target.closest ? event.target.closest('[data-freight-fifo-cancel-order]') : null;`;

const CLICK_VAR_NEW = `      var orderButton = event.target.closest ? event.target.closest('[data-freight-fifo-order]') : null;
      var customerOrdersOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-open]') : null;
      var customerOrdersMerge = event.target.closest ? event.target.closest('[data-freight-fifo-customer-orders-merge]') : null;
      var customerHoldOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-hold-open]') : null;
      var customerOrderOpen = event.target.closest ? event.target.closest('[data-freight-fifo-customer-order-open]') : null;
      var cancelOrderButton = event.target.closest ? event.target.closest('[data-freight-fifo-cancel-order]') : null;`;

const CLICK_OLD = `      if (orderButton) {
        event.preventDefault();
        event.stopPropagation();
        openFreightFifoShipmentSafe('', orderButton.getAttribute('data-freight-fifo-order') || '', orderButton);
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
      }
      if (customerOrderOpen) {
        event.preventDefault();
        event.stopPropagation();
        var customerOrderId = customerOrderOpen.getAttribute('data-freight-fifo-customer-order-open') || '';
        var customerOrderKind = customerOrderOpen.getAttribute('data-freight-fifo-customer-order-kind') || 'order';
        var customerOrdersDialog = document.querySelector('[data-freight-customer-orders-dialog]');
        if (customerOrdersDialog && customerOrdersDialog.parentNode) customerOrdersDialog.parentNode.removeChild(customerOrdersDialog);
        if (customerOrderKind === 'inquiry') openFreightFifoShipmentSafe(customerOrderId, '', customerOrderOpen);
        else openFreightFifoShipmentSafe('', customerOrderId, customerOrderOpen);
        return;
      }
      if (orderButton) {
        event.preventDefault();
        event.stopPropagation();
        openFreightFifoShipmentSafe('', orderButton.getAttribute('data-freight-fifo-order') || '', orderButton);
      }`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "customer-orders"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, VARS_OLD, VARS_NEW, "pending dialog flag");
  js = replaceOnce(js, MERGE_OLD, MERGE_NEW, "customer order helpers");
  js = replaceOnce(js, SEARCH_OLD, SEARCH_NEW, "search submit opens popup");
  js = replaceOnce(js, COLLECT_OLD, COLLECT_NEW, "collect matching customer orders");
  js = replaceOnce(js, CONCAT_OLD, CONCAT_NEW, "render section and auto popup");
  js = replaceOnce(js, CLICK_VAR_OLD, CLICK_VAR_NEW, "click vars");
  js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "click open customer order");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("collect helper missing after patch");
if (js.indexOf("data-freight-fifo-customer-order-open") === -1) throw new Error("open button missing after patch");
if (js.indexOf("customerOrdersSection") === -1) throw new Error("section missing after patch");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
stampHtml(ROOT);
console.log("LINGZANZAN fifo customer orders popup ok", STAMP);
