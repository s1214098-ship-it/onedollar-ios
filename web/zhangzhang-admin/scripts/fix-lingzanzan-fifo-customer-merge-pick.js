#!/usr/bin/env node
"use strict";

/**
 * 相同客戶出貨單：先勾選再合併。沒勾的不會併入。
 * 開啟出貨單仍可單獨開。不 stamp 全站 HTML。
 *
 * Cache-bust: admin.js ?v=20260822-merge-pick-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260822-merge-pick-1";
const JS_MARKER = "data-freight-fifo-customer-order-merge-pick";

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

const BIND_STYLE_OLD = `    if (document.head) document.head.appendChild(style);
  }

  function freightFifoCustomerOrderArticleHtml(row, forDialog) {`;

const BIND_STYLE_NEW = `    if (document.head) document.head.appendChild(style);
    freightFifoBindCustomerOrderMergePicks();
  }

  function freightFifoCustomerOrderArticleHtml(row, forDialog) {`;

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

const HELPERS_OLD = `  function freightFifoCustomerOrderMergeButtonHtml(rows, forDialog) {
    var ids = freightFifoCustomerOrderMergeIds(rows);
    var label = freightFifoCustomerOrderMergeButtonLabel(ids.length);
    if (!label) return '';
    var cls = forDialog ? 'is-go' : 'primary-button';
    return '<button type="button" class="' + cls + '" data-freight-fifo-customer-orders-merge="' + escapeHtml(ids.join(',')) + '">' + escapeHtml(label) + '</button>';
  }`;

const HELPERS_NEW = `  function freightFifoSelectedCustomerOrderMergeIds(root) {
    if (!root || !root.querySelectorAll) return [];
    var boxes = root.querySelectorAll('[data-freight-fifo-customer-order-merge-pick]:checked');
    var ids = [];
    var seen = {};
    Array.prototype.forEach.call(boxes, function (box) {
      var id = String((box && box.value) || '').trim();
      if (!id || seen[id]) return;
      seen[id] = true;
      ids.push(id);
    });
    return ids;
  }

  function freightFifoSyncCustomerOrderMergeButton(root) {
    if (!root || !root.querySelector) return;
    var btn = root.querySelector('[data-freight-fifo-customer-orders-merge]');
    if (!btn) return;
    var ids = freightFifoSelectedCustomerOrderMergeIds(root);
    var n = ids.length;
    btn.disabled = n < 2;
    btn.textContent = n >= 2
      ? ('合併已勾選 ' + n + ' 張一起出')
      : (n === 1 ? '再勾選至少 1 張才能合併' : '請勾選要合併的單');
    btn.classList.toggle('is-wait', n < 2);
  }

  function freightFifoBindCustomerOrderMergePicks() {
    if (typeof document === 'undefined' || !document.addEventListener) return;
    if (document.documentElement && document.documentElement.getAttribute('data-fifo-merge-pick-bound') === '1') return;
    if (document.documentElement) document.documentElement.setAttribute('data-fifo-merge-pick-bound', '1');
    document.addEventListener('change', function (event) {
      var input = event.target && event.target.closest ? event.target.closest('[data-freight-fifo-customer-order-merge-pick]') : null;
      if (!input) return;
      var article = input.closest ? input.closest('article') : null;
      if (article) article.classList.toggle('is-picked', !!input.checked);
      var root = input.closest ? (input.closest('[data-freight-customer-orders-dialog]') || input.closest('.is-customer-orders')) : null;
      freightFifoSyncCustomerOrderMergeButton(root);
    });
  }

  function freightFifoCustomerOrderMergeButtonHtml(rows, forDialog) {
    var ids = freightFifoCustomerOrderMergeIds(rows);
    if (ids.length < 2) return '';
    var cls = forDialog ? 'is-go is-wait' : 'primary-button';
    freightFifoBindCustomerOrderMergePicks();
    return '<button type="button" class="' + cls + '" disabled data-freight-fifo-customer-orders-merge="1">請勾選要合併的單</button>';
  }`;

const STYLE_OLD = `      + '.freight-customer-orders-actions .is-stop{border:1px solid #cbb7c4;background:#3a2e3d;color:#fff8ed}';`;

const STYLE_NEW = `      + '.freight-customer-orders-actions .is-stop{border:1px solid #cbb7c4;background:#3a2e3d;color:#fff8ed}'
      + '.freight-customer-orders-pick{display:flex;align-items:center;gap:8px;margin:0;color:#f0bd54;font-weight:900;cursor:pointer}'
      + '.freight-customer-orders-pick input{width:22px;height:22px;accent-color:#f0bd54}'
      + '.freight-customer-orders-pick-skip{color:#cbb7c4}'
      + '.freight-customer-orders-item.is-picked,.freight-fifo-search-pending.is-customer-orders article.is-picked{border-color:#f0bd54;box-shadow:inset 0 0 0 1px #f0bd54}'
      + '.freight-customer-orders-actions .is-go[disabled],.freight-fifo-search-pending.is-customer-orders .primary-button[disabled]{opacity:.55;cursor:not-allowed}';`;

const ARTICLE_OLD = `    var openBtn = '<button type="button" class="' + (forDialog ? 'is-go' : 'primary-button') + '" data-similar-ship-ok="1" data-freight-fifo-customer-order-open="' + escapeHtml(row.id || '') + '" data-freight-fifo-customer-order-kind="' + escapeHtml(kind) + '">開啟出貨單（可加商品）</button>';
    var holdBtn = statusKey === 'hold'
      ? '<button type="button" class="' + (forDialog ? 'is-stop' : 'ghost-button') + '" data-freight-fifo-customer-hold-open="' + escapeHtml(row.id || '') + '">看寄庫名單</button>'
      : '';
    if (forDialog) {
      return '<article class="freight-customer-orders-item is-' + escapeHtml(statusKey) + '"><b>' + escapeHtml(statusLabel) + '｜' + escapeHtml(String(row.createdAt || row.orderDate || '').slice(0, 16).replace('T', ' ') || '-') + '</b>'
        + '<span>' + escapeHtml(row.id || '-') + (row.trackingNo || row.shippingTrackingNo ? '／物流 ' + (row.trackingNo || row.shippingTrackingNo) : '／還沒有物流單號') + '</span>'
        + itemHtml
        + '<div class="freight-customer-orders-actions">' + openBtn + holdBtn + '</div></article>';
    }`;

const ARTICLE_NEW = `    var openBtn = '<button type="button" class="' + (forDialog ? 'is-go' : 'primary-button') + '" data-similar-ship-ok="1" data-freight-fifo-customer-order-open="' + escapeHtml(row.id || '') + '" data-freight-fifo-customer-order-kind="' + escapeHtml(kind) + '">開啟出貨單（可加商品）</button>';
    var holdBtn = statusKey === 'hold'
      ? '<button type="button" class="' + (forDialog ? 'is-stop' : 'ghost-button') + '" data-freight-fifo-customer-hold-open="' + escapeHtml(row.id || '') + '">看寄庫名單</button>'
      : '';
    var canMerge = freightFifoCustomerOrderCanMerge(row);
    var pickHtml = canMerge
      ? '<label class="freight-customer-orders-pick"><input type="checkbox" data-freight-fifo-customer-order-merge-pick value="' + escapeHtml(row.id || '') + '"> 合併這張</label>'
      : (forDialog ? '<small class="freight-customer-orders-pick-skip">這張不能合併</small>' : '');
    if (forDialog) {
      return '<article class="freight-customer-orders-item is-' + escapeHtml(statusKey) + '">' + pickHtml + '<b>' + escapeHtml(statusLabel) + '｜' + escapeHtml(String(row.createdAt || row.orderDate || '').slice(0, 16).replace('T', ' ') || '-') + '</b>'
        + '<span>' + escapeHtml(row.id || '-') + (row.trackingNo || row.shippingTrackingNo ? '／物流 ' + (row.trackingNo || row.shippingTrackingNo) : '／還沒有物流單號') + '</span>'
        + itemHtml
        + '<div class="freight-customer-orders-actions">' + openBtn + holdBtn + '</div></article>';
    }`;

const SECTION_OLD = `    return '<section class="freight-fifo-search-pending is-customer-orders"><header><div><b>此客戶已打單／出貨單內容</b><small>待配貨四格只列出可配貨的單。直播打單還在「接單確認中」、或已取消的詢問單，會出現在這裡。未出貨單可合併一起出（已完成配送與寄庫不併入）。每張仍可單獨開出貨單。</small></div><strong>' + rows.length + ' 張</strong><button type="button" class="primary-button" data-freight-fifo-customer-orders-open>彈出訂單內容</button>' + mergeBtn + '</header>'`;

const SECTION_NEW = `    return '<section class="freight-fifo-search-pending is-customer-orders"><header><div><b>此客戶已打單／出貨單內容</b><small>待配貨四格只列出可配貨的單。直播打單還在「接單確認中」、或已取消的詢問單，會出現在這裡。未出貨單請先勾選再合併（已完成配送與寄庫不能勾）。每張仍可單獨開出貨單。</small></div><strong>' + rows.length + ' 張</strong><button type="button" class="primary-button" data-freight-fifo-customer-orders-open>彈出訂單內容</button>' + mergeBtn + '</header>'`;

const DIALOG_OLD = `      + '<p>' + escapeHtml(customer.name || first.customerName || '未填客戶') + '／' + escapeHtml(customer.phone || first.customerPhone || freightFifoQueueSearch || '-') + '。下面是搜尋到的訂單內容。未出貨單可合併一起出；已完成配送與寄庫單不併入。每張出貨單仍可單獨開啟。</p>'`;

const DIALOG_NEW = `      + '<p>' + escapeHtml(customer.name || first.customerName || '未填客戶') + '／' + escapeHtml(customer.phone || first.customerPhone || freightFifoQueueSearch || '-') + '。請先勾選要合併的單，再按下面合併。沒勾的不會併進去。已完成配送與寄庫單不能勾。每張出貨單仍可單獨開啟。</p>'`;

const MERGE_CLICK_OLD = `        freightFifoMergeCustomerOrders(mergeBtn.getAttribute('data-freight-fifo-customer-orders-merge') || '', mergeBtn);`;

const MERGE_CLICK_NEW = `        var mergeRoot = mergeBtn.closest ? (mergeBtn.closest('[data-freight-customer-orders-dialog]') || mergeBtn.closest('.is-customer-orders')) : null;
        var pickedIds = freightFifoSelectedCustomerOrderMergeIds(mergeRoot);
        if (pickedIds.length < 2) {
          toast('請先勾選至少 2 張要合併的單');
          return;
        }
        freightFifoMergeCustomerOrders(pickedIds.join(','), mergeBtn);`;

const LIST_MERGE_CLICK_OLD = `        freightFifoMergeCustomerOrders(customerOrdersMerge.getAttribute('data-freight-fifo-customer-orders-merge') || '', customerOrdersMerge);`;

const LIST_MERGE_CLICK_NEW = `        var listMergeRoot = customerOrdersMerge.closest ? customerOrdersMerge.closest('.is-customer-orders') : null;
        var listPickedIds = freightFifoSelectedCustomerOrderMergeIds(listMergeRoot);
        if (listPickedIds.length < 2) {
          toast('請先勾選至少 2 張要合併的單');
          return;
        }
        freightFifoMergeCustomerOrders(listPickedIds.join(','), customerOrdersMerge);`;

const CONFIRM_OLD = `    var copy = '確定把這位客戶未出貨的 ' + found.resolved.length + ' 張訂單合併一起出嗎？運費只保留一次。已完成配送與寄庫單不會併入；每張出貨單仍可單獨開啟。';`;

const CONFIRM_NEW = `    var copy = '確定把已勾選的 ' + found.resolved.length + ' 張訂單合併一起出嗎？沒勾的不會併入。運費只保留一次。已完成配送與寄庫單不會併入；每張出貨單仍可單獨開啟。';`;

const LIST_ARTICLE_OLD = `    return '<article class="is-' + escapeHtml(statusKey) + '"><div><b>' + escapeHtml(customer.name || row.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || row.customerPhone || '未填電話') + '</span><small>' + escapeHtml((row.id || '-') + '／' + statusLabel) + '</small></div>'`;

const LIST_ARTICLE_NEW = `    return '<article class="is-' + escapeHtml(statusKey) + '">' + pickHtml + '<div><b>' + escapeHtml(customer.name || row.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || row.customerPhone || '未填電話') + '</span><small>' + escapeHtml((row.id || '-') + '／' + statusLabel) + '</small></div>'`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "merge-pick"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, HELPERS_OLD, HELPERS_NEW, "merge button waits for picks");
  js = replaceOnce(js, STYLE_OLD, STYLE_NEW, "pick checkbox style");
  js = replaceOnce(js, BIND_STYLE_OLD, BIND_STYLE_NEW, "bind pick changes");
  js = replaceOnce(js, ARTICLE_OLD, ARTICLE_NEW, "dialog pick checkbox");
  js = replaceOnce(js, LIST_ARTICLE_OLD, LIST_ARTICLE_NEW, "list pick checkbox");
  js = replaceOnce(js, SECTION_OLD, SECTION_NEW, "section hint");
  js = replaceOnce(js, DIALOG_OLD, DIALOG_NEW, "dialog hint");
  js = replaceOnce(js, MERGE_CLICK_OLD, MERGE_CLICK_NEW, "dialog merge uses checked ids");
  js = replaceOnce(js, LIST_MERGE_CLICK_OLD, LIST_MERGE_CLICK_NEW, "list merge uses checked ids");
  js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "confirm selected only");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("pick checkbox missing after patch");
if (js.indexOf("合併已勾選") === -1) throw new Error("picked button label missing");
if (js.indexOf("開啟出貨單（可加商品）") === -1) throw new Error("open shipment button missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
stampHtml(ROOT);
console.log("LINGZANZAN fifo customer merge pick ok", STAMP);
