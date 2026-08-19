#!/usr/bin/env node
"use strict";

/**
 * 搜尋訂單／電話／物流單時，14 天日期窗會把已完成配送的單擋住。
 * FIFO 搜尋也只找待出貨／配送中／取消，找不到已完成。
 * 有關鍵字就略過日期；出貨核對搜尋補上「已完成配送」區塊。
 *
 * Cache-bust: admin.js ?v=20260819-search-delivered-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260819-search-delivered-1";
const PREV = "20260819-card-price-1";
const MARKER = "searchedDeliveredOrders";

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
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    const html = fs.readFileSync(file, "latin1");
    if (!/admin\.js/.test(html)) continue;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const SKIP_OLD = `      var skipDate = query && /欠客戶|缺他|公司欠/.test(query);`;
const SKIP_NEW = `      var skipDate = !!query;`;

const SEARCHED_OLD = `    var searchedReturnedOrders = searching
      ? (state.orders || []).filter(function (order) {
        if (!order || !freightFifoRowMatchesSearch(order)) return false;
        var status = String(order.status || '').toLowerCase();
        var delivery = String(order.deliveryState || '').toLowerCase();
        return ['returned', 'cancelled'].indexOf(status) !== -1 || delivery === 'returned';
      }).sort(function (a, b) {
        return freightFifoCreatedTime(b) - freightFifoCreatedTime(a);
      }).slice(0, 20)
      : [];`;

const SEARCHED_NEW = `    var searchedReturnedOrders = searching
      ? (state.orders || []).filter(function (order) {
        if (!order || !freightFifoRowMatchesSearch(order)) return false;
        var status = String(order.status || '').toLowerCase();
        var delivery = String(order.deliveryState || '').toLowerCase();
        return ['returned', 'cancelled'].indexOf(status) !== -1 || delivery === 'returned';
      }).sort(function (a, b) {
        return freightFifoCreatedTime(b) - freightFifoCreatedTime(a);
      }).slice(0, 20)
      : [];
    var searchedDeliveredOrders = searching
      ? (state.orders || []).filter(function (order) {
        if (!order || !freightFifoRowMatchesSearch(order)) return false;
        var status = String(order.status || '').toLowerCase();
        var delivery = String(order.deliveryState || '').toLowerCase();
        return ['delivered', 'completed'].indexOf(status) !== -1 || delivery === 'delivered';
      }).sort(function (a, b) {
        return freightFifoCreatedTime(b) - freightFifoCreatedTime(a);
      }).slice(0, 20)
      : [];`;

const RETURNED_SECTION_OLD = `    var returnedSection = searchedReturnedOrders.length ? '<section class="freight-fifo-search-pending is-returned">`;
const RETURNED_SECTION_NEW = `    var deliveredSection = searchedDeliveredOrders.length ? '<section class="freight-fifo-search-pending is-delivered"><header><div><b>已完成配送／自己送，仍可打開原單</b><small>這張單已經出完，不在待補物流裡。搜尋姓名、電話、訂單編號或物流單號就會出現。</small></div><strong>' + searchedDeliveredOrders.length + ' 張</strong></header>' + searchedDeliveredOrders.map(function (order) {
      var customer = order.customer || {};
      var status = String(order.statusLabel || order.status || '已完成配送');
      return '<article><div><b>' + escapeHtml(customer.name || order.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || order.customerPhone || '未填電話') + '</span><small>' + escapeHtml((order.id || '-') + '／' + status + (order.trackingNo ? '／' + order.trackingNo : '') + (order.shippingCarrier ? '／' + order.shippingCarrier : '')) + '</small></div><div class="freight-fifo-search-pending-actions"><strong class="freight-fifo-search-pending-status">已完成</strong><button type="button" class="ghost-button" data-freight-fifo-order="' + escapeHtml(order.id || '') + '">開啟原單</button></div></article>';
    }).join('') + '</section>' : '';
    var returnedSection = searchedReturnedOrders.length ? '<section class="freight-fifo-search-pending is-returned">`;

const CONCAT_OLD = ` + mergeToolbarHtml + returnedSection + notReadySection + `;
const CONCAT_NEW = ` + mergeToolbarHtml + deliveredSection + returnedSection + notReadySection + `;

console.log("backup js", backup(ADMIN_JS, "search-delivered"));
let src = fs.readFileSync(ADMIN_JS, "utf8");
if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(src, SKIP_OLD, SKIP_NEW, "skip date when searching");
  src = replaceOnce(src, SEARCHED_OLD, SEARCHED_NEW, "fifo delivered search rows");
  src = replaceOnce(src, RETURNED_SECTION_OLD, RETURNED_SECTION_NEW, "fifo delivered search section");
  src = replaceOnce(src, CONCAT_OLD, CONCAT_NEW, "fifo concat delivered section");
  fs.writeFileSync(ADMIN_JS, src);
  console.log("js written", src.length);
} else {
  console.log("js already patched");
}
stampHtml(ROOT);
console.log("LINGZANZAN search finds delivered:", STAMP);
