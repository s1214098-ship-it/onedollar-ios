#!/usr/bin/env node
"use strict";

/**
 * 寄庫單同客人合併：搜尋一張會彈出同客人其他寄庫商品，
 * 丟進寄庫時也提醒。可按「合併同客人寄庫單一起出」。
 *
 * Live: assets/admin.js, assets/admin.css
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260822-hold-merge-1";
const JS_MARKER = "function freightFifoCollectCustomerHoldSiblings(";
const CSS_MARKER = "/* 20260822 hold merge: same-customer 寄庫 */";

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

const HELPERS = `
  function freightFifoHoldCustomerKey(row) {
    row = row || {};
    var customer = row.customer || {};
    var phone = String(customer.phone || row.customerPhone || row.phone || '').replace(/\\D/g, '');
    if (phone.length >= 6) return 'p:' + phone;
    var name = String(customer.name || row.customerName || row.name || '').replace(/\\s+/g, '').toLowerCase();
    return name.length >= 2 ? 'n:' + name : '';
  }

  function freightFifoHoldRowIsClosed(row) {
    var status = String((row && row.status) || '').toLowerCase();
    if (['cancelled', 'canceled', 'shipped', 'in_transit', 'delivered', 'completed', 'returned', 'converted'].indexOf(status) !== -1) return true;
    if (String((row && row.convertedOrderId) || '').trim()) return true;
    if (String((row && row.convertedToOrderId) || '').trim()) return true;
    return false;
  }

  function freightFifoHoldRowIsActiveHold(row) {
    if (!row || freightFifoHoldRowIsClosed(row)) return false;
    var reason = String(row.reservedShippingHoldReason || '').trim();
    if (reason && reason !== 'scheduled_ship') return true;
    var note = [row.shippingNote, row.reservedShippingNote, row.reservedShippingHoldReasonText].join(' ');
    return /寄庫|等待直播|等直播|先不出貨/.test(note);
  }

  function freightFifoHoldItemSummary(row) {
    var items = Array.isArray(row && row.items) ? row.items : [];
    if (!items.length) return '尚未填商品';
    return items.map(function (item) {
      item = item || {};
      return [item.code || item.productCode || item.sku || item.title || '商品', item.color || item.colorName || '', item.size || item.sizeName || '', '×' + (Math.max(0, Number(item.qty || item.quantity || 0)) || 1)].filter(Boolean).join(' ');
    }).join('、');
  }

  function freightFifoCollectCustomerHoldSiblings(target) {
    var matchKey = freightFifoHoldCustomerKey(target);
    var phone = String((target && ((target.customer && target.customer.phone) || target.customerPhone || target.phone)) || '').replace(/\\D/g, '');
    if (!matchKey && phone.length >= 6) matchKey = 'p:' + phone;
    if (!matchKey) return [];
    var skip = String((target && target.id) || '');
    var seen = {};
    var rows = [];
    function add(row) {
      if (!row || !row.id) return;
      var id = String(row.id);
      if (seen[id] || (skip && id === skip)) return;
      if (!freightFifoHoldRowIsActiveHold(row)) return;
      if (freightFifoHoldCustomerKey(row) !== matchKey) return;
      seen[id] = true;
      rows.push(row);
    }
    (state.orders || []).forEach(add);
    (state.inquiries || []).forEach(add);
    return rows;
  }

  function freightFifoHoldReminderMessage(siblings) {
    siblings = Array.isArray(siblings) ? siblings : [];
    if (!siblings.length) return '';
    return '此客人已有 ' + siblings.length + ' 張寄庫單，打包時可合併一起出：' + siblings.map(function (row) {
      return String(row.id || '-') + '／' + freightFifoHoldItemSummary(row);
    }).join('；');
  }

  function freightFifoHoldMergeIds(row, siblings) {
    var ids = [];
    var seen = {};
    function add(id) {
      id = String(id || '');
      if (!id || seen[id]) return;
      seen[id] = true;
      ids.push(id);
    }
    add(row && row.id);
    (siblings || []).forEach(function (sib) { add(sib && sib.id); });
    return ids;
  }

  function freightFifoHoldMergeButtonLabel(siblingCount) {
    var n = Math.max(0, Number(siblingCount || 0));
    return n ? ('合併同客人 ' + (n + 1) + ' 張寄庫單一起出') : '';
  }

  function freightFifoShowCustomerHoldDialog(rows) {
    if (typeof freightFifoEnsureCustomerOrdersStyle === 'function') freightFifoEnsureCustomerOrdersStyle();
    rows = Array.isArray(rows) ? rows : [];
    var existing = document.querySelector('[data-freight-hold-merge-dialog]');
    if (existing) existing.remove();
    if (rows.length < 2) {
      if (rows.length === 1) toast('這張寄庫單目前沒有同客人其他寄庫商品');
      return;
    }
    var first = rows[0] || {};
    var customer = first.customer || {};
    var dialog = document.createElement('div');
    dialog.className = 'freight-customer-orders-dialog';
    dialog.setAttribute('data-freight-hold-merge-dialog', '');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    var mergeIds = rows.map(function (row) { return String(row.id || ''); }).filter(Boolean);
    dialog.innerHTML = '<section class="freight-customer-orders-card">'
      + '<h3>同客人寄庫單，可合併一起出</h3>'
      + '<p>' + escapeHtml(customer.name || first.customerName || '未填客戶') + '／' + escapeHtml(customer.phone || first.customerPhone || '-') + '。先打包裝的其他商品也在寄庫，請核對後合併或一起開啟出貨單。</p>'
      + rows.map(function (row) {
        return '<article class="freight-customer-orders-item is-draft"><b>寄庫中｜' + escapeHtml(String(row.reservedShippingHoldReasonText || row.reservedShippingHoldReason || '寄庫')) + '</b>'
          + '<span>' + escapeHtml(row.id || '-') + '／' + escapeHtml(freightFifoHoldItemSummary(row)) + '</span>'
          + '<div class="freight-customer-orders-actions"><button type="button" class="is-go" data-freight-hold-open="' + escapeHtml(row.id || '') + '">開啟這張寄庫單</button></div></article>';
      }).join('')
      + '<div class="freight-customer-orders-actions">'
      + (mergeIds.length > 1 ? '<button type="button" class="is-go" data-freight-hold-merge-all="' + escapeHtml(mergeIds.join(',')) + '">' + escapeHtml(freightFifoHoldMergeButtonLabel(mergeIds.length - 1)) + '</button>' : '')
      + '<button type="button" class="is-stop" data-freight-hold-merge-close>關閉</button></div></section>';
    function close() { if (dialog.parentNode) dialog.parentNode.removeChild(dialog); }
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog || (event.target.closest && event.target.closest('[data-freight-hold-merge-close]'))) {
        close();
        return;
      }
      var openBtn = event.target.closest && event.target.closest('[data-freight-hold-open]');
      if (openBtn) {
        event.preventDefault();
        var openId = openBtn.getAttribute('data-freight-hold-open') || '';
        var openRow = ((state.orders || []).concat(state.inquiries || [])).find(function (row) { return row && String(row.id || '') === openId; });
        close();
        if (openRow && /^BYORDER-/i.test(openId) && String(openRow.source || '') !== 'live-order') openFreightFifoShipmentSafe(openId, '', openBtn);
        else openFreightFifoShipmentSafe('', openId, openBtn);
        return;
      }
      var mergeBtn = event.target.closest && event.target.closest('[data-freight-hold-merge-all]');
      if (mergeBtn) {
        event.preventDefault();
        var ids = String(mergeBtn.getAttribute('data-freight-hold-merge-all') || '').split(',').filter(Boolean);
        if (ids.length < 2 || !window.confirm('確定把這位客戶的 ' + ids.length + ' 張寄庫單合併一起出貨嗎？運費只保留一次。')) return;
        mergeBtn.disabled = true;
        callOrderAdmin({ action: 'merge-shipment', orderIds: ids }).then(function (data) {
          if (Array.isArray(data.orders)) state.orders = data.orders;
          close();
          if (typeof renderReservedShippingWorkbench === 'function') renderReservedShippingWorkbench();
          toast('已合併同客人寄庫單，會一起出貨');
        }).catch(function (error) {
          mergeBtn.disabled = false;
          toast(error.message || '合單失敗');
        });
      }
    });
    document.body.appendChild(dialog);
  }

  function freightFifoRemindCustomerHoldSiblings(modal) {
    if (!modal) return [];
    var phone = String((modal.querySelector('[data-freight-fifo-phone]') || {}).value || '').replace(/\\D/g, '');
    var id = modal.getAttribute('data-order-id') || modal.getAttribute('data-inquiry-id') || '';
    var siblings = freightFifoCollectCustomerHoldSiblings({ id: id, customer: { phone: phone } });
    var box = modal.querySelector('[data-freight-fifo-hold-siblings]');
    var copy = freightFifoHoldReminderMessage(siblings);
    if (box) {
      box.hidden = !copy;
      box.textContent = copy;
      if (copy) box.classList.add('freight-fifo-hold-siblings');
    }
    if (copy) toast(copy);
    if (siblings.length) {
      var current = ((state.orders || []).concat(state.inquiries || [])).find(function (row) { return row && String(row.id || '') === String(id); });
      freightFifoShowCustomerHoldDialog((current ? [current] : []).concat(siblings));
    }
    return siblings;
  }

`;

const COLLECT_OLD = `  function reservedShippingCollectEntries() {`;
const COLLECT_NEW = HELPERS + `  function reservedShippingCollectEntries() {`;

const CACHE_OLD = `var reservedShippingPrintCache = { schedule: [], hold: [], visible: [], query: '', filter: '' };`;
const CACHE_NEW = `var reservedShippingPrintCache = { schedule: [], hold: [], visible: [], query: '', filter: '' };
  var reservedHoldMergeDialogQuery = '';`;

const RELATED_OLD = `    var related = source === 'order' ? reservedShippingRelatedNewOrders(order) : [];
    var itemChanged = reservedShippingHasItemChange(order);`;
const RELATED_NEW = `    var related = source === 'order' ? reservedShippingRelatedNewOrders(order) : [];
    var holdSiblings = freightFifoCollectCustomerHoldSiblings(order);
    var itemChanged = reservedShippingHasItemChange(order);`;

const MERGEIDS_OLD = `    var mergeIds = [String(order.id || '')].concat(related.map(function (row) { return String(row.id || ''); }));`;
const MERGEIDS_NEW = `    var mergeIds = freightFifoHoldMergeIds(order, holdSiblings.concat(related));`;

const ALERT_OLD = `(related.length ? '<span>另外新增 ' + related.length + ' 張未出貨訂單：' + escapeHtml(related.map(function (row) { return row.id; }).join('、')) + '</span>' : '') + '</div>' : '') +
      '<div class="reserved-shipping-edit">`;
const ALERT_NEW = `(related.length ? '<span>另外新增 ' + related.length + ' 張未出貨訂單：' + escapeHtml(related.map(function (row) { return row.id; }).join('、')) + '</span>' : '') + '</div>' : '') +
      (holdSiblings.length ? '<div class="reserved-shipping-change-alert is-hold-merge"><b>同客人還有 ' + holdSiblings.length + ' 張寄庫單</b><span>' + escapeHtml(holdSiblings.map(function (row) { return freightFifoHoldItemSummary(row); }).join('；')) + '</span><button type="button" class="primary-button" data-reserved-hold-siblings="' + escapeHtml(freightFifoHoldMergeIds(order, holdSiblings).join(',')) + '">看全部寄庫單</button></div>' : '') +
      '<div class="reserved-shipping-edit">`;

const BTN_OLD = `(related.length ? '<button type="button" class="primary-button" data-reserved-merge="' + escapeHtml(mergeIds.join(',')) + '">合併新增訂單一起出</button>' : '')`;
const BTN_NEW = `(mergeIds.length > 1 ? '<button type="button" class="primary-button" data-reserved-merge="' + escapeHtml(mergeIds.join(',')) + '">' + escapeHtml(holdSiblings.length ? freightFifoHoldMergeButtonLabel(holdSiblings.length) : '合併新增訂單一起出') + '</button>' : '')`;

const HOLDLIST_OLD = `if (holdList) holdList.innerHTML = held.length ? held.map(function (entry) { return reservedShippingCardHtml(entry.row, today, entry.lane, entry.source); }).join('') : '<div class="reserved-shipping-empty">目前沒有符合條件的寄庫名單。</div>';
    var candidateList = page.querySelector('[data-reserved-candidates]');`;
const HOLDLIST_NEW = `if (holdList) holdList.innerHTML = held.length ? held.map(function (entry) { return reservedShippingCardHtml(entry.row, today, entry.lane, entry.source); }).join('') : '<div class="reserved-shipping-empty">目前沒有符合條件的寄庫名單。</div>';
    var holdPopupRows = [];
    var holdPopupSeen = {};
    held.forEach(function (entry) {
      var row = entry && entry.row;
      if (!row || !row.id) return;
      var id = String(row.id);
      if (!holdPopupSeen[id]) { holdPopupSeen[id] = true; holdPopupRows.push(row); }
      freightFifoCollectCustomerHoldSiblings(row).forEach(function (sib) {
        var sibId = String(sib.id || '');
        if (!sibId || holdPopupSeen[sibId]) return;
        holdPopupSeen[sibId] = true;
        holdPopupRows.push(sib);
      });
    });
    if (query && holdPopupRows.length >= 2 && String(query) !== String(reservedHoldMergeDialogQuery || '')) {
      reservedHoldMergeDialogQuery = query;
      window.setTimeout(function () { freightFifoShowCustomerHoldDialog(holdPopupRows); }, 30);
    } else if (!query) {
      reservedHoldMergeDialogQuery = '';
    }
    var candidateList = page.querySelector('[data-reserved-candidates]');`;

const OPEN_OLD = `      var openShip = event.target.closest('[data-reserved-open-shipment]');
      if (openShip) {`;
const OPEN_NEW = `      var holdSiblingsBtn = event.target.closest('[data-reserved-hold-siblings]');
      if (holdSiblingsBtn) {
        event.preventDefault();
        var siblingIds = String(holdSiblingsBtn.getAttribute('data-reserved-hold-siblings') || '').split(',').filter(Boolean);
        var siblingRows = siblingIds.map(function (id) {
          return ((state.orders || []).concat(state.inquiries || [])).find(function (row) { return row && String(row.id || '') === id; });
        }).filter(Boolean);
        freightFifoShowCustomerHoldDialog(siblingRows);
        return;
      }
      var openShip = event.target.closest('[data-reserved-open-shipment]');
      if (openShip) {`;

const CONFIRM_OLD = `確定把這位客戶新增的 ' + ids.length + ' 張訂單合併一起出貨嗎？運費只保留一次。`;
const CONFIRM_NEW = `確定把這位客戶的 ' + ids.length + ' 張單合併一起出貨嗎？運費只保留一次。寄庫單也可一起出。`;

const MERGETOAST_OLD = `toast('已合併同一位客戶的新增訂單，會一起出貨');`;
const MERGETOAST_NEW = `toast('已合併同一位客戶的訂單，會一起出貨');`;

const SAVECOPY_OLD = `    var fifoHoldSaveCopy = fifoHoldActive ? '更新寄庫原因' : '加入寄庫名單';`;
const SAVECOPY_NEW = `    var fifoHoldSaveCopy = fifoHoldActive ? '更新寄庫原因' : '加入寄庫名單';
    var holdSiblingRows = freightFifoCollectCustomerHoldSiblings(row || order || preorder || {});
    var holdSiblingsCopy = holdSiblingRows.length ? freightFifoHoldReminderMessage(holdSiblingRows) : '';`;

const HOLDRESULT_OLD = `<p data-freight-fifo-hold-result></p><label>預約出貨日期`;
const HOLDRESULT_NEW = `<p data-freight-fifo-hold-result></p>' + (holdSiblingsCopy ? '<p class="freight-fifo-hold-siblings" data-freight-fifo-hold-siblings>' + escapeHtml(holdSiblingsCopy) + '</p>' : '<p data-freight-fifo-hold-siblings hidden></p>') + '<label>預約出貨日期`;

const TOAST_OLD = `toast(savedHold ? '已加入寄庫名單' : '已存檔，尚未轉正式出貨單');`;
const TOAST_NEW = `toast(savedHold ? '已加入寄庫名單' : '已存檔，尚未轉正式出貨單');
      if (savedHold) freightFifoRemindCustomerHoldSiblings(modal);`;

const CSS_APPEND = `

${CSS_MARKER}
.freight-fifo-hold-siblings,
.reserved-shipping-change-alert.is-hold-merge {
  margin-top: 8px;
  padding: 10px 12px;
  border: 2px solid #f0bd54;
  border-radius: 12px;
  background: #3a2a1d;
  color: #ffe19a;
  font-weight: 800;
  line-height: 1.45;
}
.reserved-shipping-change-alert.is-hold-merge {
  display: grid;
  gap: 8px;
}
.reserved-shipping-change-alert.is-hold-merge button {
  min-height: 44px;
  justify-self: start;
}
`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "hold-merge"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, COLLECT_OLD, COLLECT_NEW, "hold sibling helpers");
  js = replaceOnce(js, CACHE_OLD, CACHE_NEW, "hold merge dialog query");
  js = replaceOnce(js, RELATED_OLD, RELATED_NEW, "card collects hold siblings");
  js = replaceOnce(js, MERGEIDS_OLD, MERGEIDS_NEW, "merge ids include hold siblings");
  js = replaceOnce(js, ALERT_OLD, ALERT_NEW, "card sibling hold alert");
  js = replaceOnce(js, BTN_OLD, BTN_NEW, "merge button for hold siblings");
  js = replaceOnce(js, HOLDLIST_OLD, HOLDLIST_NEW, "search pops sibling holds");
  js = replaceOnce(js, OPEN_OLD, OPEN_NEW, "click 看全部寄庫單");
  js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "merge confirm includes 寄庫");
  js = replaceOnce(js, MERGETOAST_OLD, MERGETOAST_NEW, "merge toast generic");
  js = replaceOnce(js, SAVECOPY_OLD, SAVECOPY_NEW, "fifo modal sibling copy");
  js = replaceOnce(js, HOLDRESULT_OLD, HOLDRESULT_NEW, "fifo hold sibling banner");
  js = replaceOnce(js, TOAST_OLD, TOAST_NEW, "remind after 加入寄庫");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("hold sibling helper missing");
if (js.indexOf("data-reserved-hold-siblings") === -1) throw new Error("card sibling button missing");
if (js.indexOf("freightFifoRemindCustomerHoldSiblings") === -1) throw new Error("save reminder missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);

if (fs.existsSync(ADMIN_CSS)) {
  console.log("backup css", backup(ADMIN_CSS, "hold-merge"));
  let css = fs.readFileSync(ADMIN_CSS, "utf8");
  if (css.indexOf(CSS_MARKER) !== -1) {
    console.log("css already patched");
  } else {
    fs.writeFileSync(ADMIN_CSS, css + CSS_APPEND, "utf8");
    console.log("css patched");
  }
}

stampHtml(ROOT);
console.log("LINGZANZAN hold merge ok", STAMP);
