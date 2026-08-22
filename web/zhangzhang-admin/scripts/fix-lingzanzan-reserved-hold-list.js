#!/usr/bin/env node
"use strict";

/**
 * 預約出貨頁補上寄庫名單區塊，搜「寄庫」或電話要找得到寄庫單。
 * 不改 .is-active 金鈕。HTML 此頁是 UTF-8。
 *
 * Cache-bust: admin.js ?v=20260822-hold-list-1
 */

const fs = require("fs");
const path = require("path");
const {
  reservedShippingHoldListHtmlHasMount,
  reservedShippingHoldQuickHasButton,
  reservedShippingHoldJsHasSearchAlias,
  reservedShippingHoldDoesNotRestyleActive,
} = require("./lz-reserved-hold-list");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const HTML = path.join(ROOT, "admin-reserved-shipping.html");
const STAMP = "20260822-hold-list-1";

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

const SEARCH_OLD = `  function reservedShippingSearchText(order) {
    var customer = order.customer || {};
    return [
      order.id, customer.name, order.customerName, customer.phone, order.customerPhone, order.phone, customer.address,
      order.reservedShippingDate, order.reservedShippingNote, order.reservedShippingHoldReason, order.reservedShippingHoldReasonText, order.reservedShippingBy,
      order.shippingNote, order.trackingNo, order.supplierTrackingNo, order.freightTrackingNo, freightFifoTrackingJoin(order),
      (order.items || []).map(function (item) { return [item.code, item.title, item.name, item.color, item.size].join(' '); }).join(' ')
    ].join(' ').toLowerCase();
  }

  function reservedShippingMatchesQuery(order, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    var reservedPhones = freightExtractCompletePhones(query);
    if (reservedPhones.length) return reservedPhones.some(function (phone) { return freightRowPhonesExact(order, phone); });
    var text = reservedShippingSearchText(order);
    if (text.indexOf(query) !== -1) return true;
    return freightDigitsHayMatch(text, query);
  }`;

const SEARCH_NEW = `  function reservedShippingSearchText(order) {
    var customer = order.customer || {};
    var holdReason = String(order.reservedShippingHoldReason || '').trim();
    return [
      order.id, customer.name, order.customerName, customer.phone, order.customerPhone, order.phone, customer.address,
      order.reservedShippingDate, order.reservedShippingNote, order.reservedShippingHoldReason, order.reservedShippingHoldReasonText, order.reservedShippingBy,
      reservedHoldReasonLabel(holdReason),
      (holdReason && holdReason !== 'scheduled_ship' ? '寄庫 已寄庫 先不出貨 hold' : ''),
      order.shippingNote, order.trackingNo, order.supplierTrackingNo, order.freightTrackingNo, freightFifoTrackingJoin(order),
      (order.items || []).map(function (item) { return [item.code, item.title, item.name, item.color, item.size].join(' '); }).join(' ')
    ].join(' ').toLowerCase();
  }

  function reservedShippingQueryWantsHold(query) {
    return /寄庫|已寄庫|先不出貨/.test(String(query || ''));
  }

  function reservedShippingMatchesQuery(order, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    var wantsHold = reservedShippingQueryWantsHold(query);
    if (wantsHold && reservedShippingLane(order) !== 'hold') return false;
    var reservedPhones = freightExtractCompletePhones(query);
    if (reservedPhones.length) return reservedPhones.some(function (phone) { return freightRowPhonesExact(order, phone); });
    var holdOnly = query.replace(/寄庫|已寄庫|先不出貨/g, ' ').replace(/\\s+/g, ' ').trim();
    if (wantsHold && !holdOnly) return reservedShippingLane(order) === 'hold';
    var text = reservedShippingSearchText(order);
    if (text.indexOf(query) !== -1 || (holdOnly && text.indexOf(holdOnly) !== -1)) return true;
    return freightDigitsHayMatch(text, holdOnly || query);
  }`;

const FILTER_OLD = `      if (filter === 'hold' && entry.lane !== 'hold') return false;
      if (filter === 'schedule' && entry.lane !== 'schedule') return false;
      if (filter === 'today' && date !== today) return false;
      if (filter === 'overdue' && !(date && date < today)) return false;
      if (filter === 'upcoming' && !(date && date > today)) return false;`;

const FILTER_NEW = `      if (filter === 'hold' && entry.lane !== 'hold') return false;
      if (filter === 'schedule' && entry.lane !== 'schedule') return false;
      if (entry.lane === 'hold') {
        if (filter === 'changed' && !changed) return false;
        return true;
      }
      if (filter === 'today' && date !== today) return false;
      if (filter === 'overdue' && !(date && date < today)) return false;
      if (filter === 'upcoming' && !(date && date > today)) return false;`;

const PRINT_OLD = `          if (filter === 'hold' && entry.lane !== 'hold') return false;
          if (filter === 'schedule' && entry.lane !== 'schedule') return false;
          if (filter === 'today' && date !== today) return false;
          if (filter === 'overdue' && !(date && date < today)) return false;
          if (filter === 'upcoming' && !(date && date > today)) return false;`;

const PRINT_NEW = `          if (filter === 'hold' && entry.lane !== 'hold') return false;
          if (filter === 'schedule' && entry.lane !== 'schedule') return false;
          if (entry.lane === 'hold') {
            if (filter === 'changed' && !changed) return false;
            return true;
          }
          if (filter === 'today' && date !== today) return false;
          if (filter === 'overdue' && !(date && date < today)) return false;
          if (filter === 'upcoming' && !(date && date > today)) return false;`;

const EMPTY_OLD = `    if (list) list.innerHTML = scheduled.length ? scheduled.map(function (entry) { return reservedShippingCardHtml(entry.row, today, entry.lane, entry.source); }).join('') : '<div class="reserved-shipping-empty">目前沒有符合條件的預約排單。</div>';`;

const EMPTY_NEW = `    if (list) list.innerHTML = scheduled.length ? scheduled.map(function (entry) { return reservedShippingCardHtml(entry.row, today, entry.lane, entry.source); }).join('') : '<div class="reserved-shipping-empty">' + (reservedShippingQueryWantsHold(query) ? '寄庫單不在預約排單，請看下面「寄庫名單」。' : '目前沒有符合條件的預約排單。') + '</div>';`;

const CAND_OLD = `        var candidates = (state.orders || []).filter(function (order) {
          if (!reservedShippingIsOpen(order) || String(order.reservedShippingStatus || '') === 'reserved') return false;
          return reservedShippingMatchesQuery(order, query);
        }).slice(0, 30);`;

const CAND_NEW = `        var candidates = (state.orders || []).filter(function (order) {
          if (!reservedShippingIsOpen(order) || reservedShippingLane(order)) return false;
          if (reservedShippingQueryWantsHold(query)) return false;
          return reservedShippingMatchesQuery(order, query);
        }).slice(0, 30);`;

const QUICK_OLD = `<option value="changed">客戶有新增商品</option></select></label>
          <div><button type="button" data-reserved-quick="today">今天要出</button><button type="button" data-reserved-quick="overdue">已逾期</button><button type="button" data-reserved-quick="upcoming">未來排程</button><button type="button" data-reserved-quick="changed">有新增商品</button><button type="button" data-reserved-quick="all">全部</button></div>`;

const QUICK_NEW = `<option value="changed">客戶有新增商品</option><option value="hold">寄庫名單</option></select></label>
          <div><button type="button" data-reserved-quick="today">今天要出</button><button type="button" data-reserved-quick="overdue">已逾期</button><button type="button" data-reserved-quick="upcoming">未來排程</button><button type="button" data-reserved-quick="changed">有新增商品</button><button type="button" data-reserved-quick="hold">寄庫</button><button type="button" data-reserved-quick="all">全部</button></div>`;

const SECTION_OLD = `        <section class="reserved-shipping-current">
          <header><div><p>SHIPPING CALENDAR</p><h2>已預約排單</h2><span>依指定日期由近到遠排列；紅色逾期、黃色今天、綠色未來。</span></div></header>
          <div class="reserved-shipping-list" data-reserved-list><p>正在讀取…</p></div>
        </section>
        <section class="reserved-shipping-add">`;

const SECTION_NEW = `        <section class="reserved-shipping-current">
          <header><div><p>SHIPPING CALENDAR</p><h2>已預約排單</h2><span>依指定日期由近到遠排列；紅色逾期、黃色今天、綠色未來。</span></div></header>
          <div class="reserved-shipping-list" data-reserved-list><p>正在讀取…</p></div>
        </section>
        <section class="reserved-shipping-hold">
          <header><div><p>HOLD LIST</p><h2>寄庫名單</h2><span>先不出貨的單在這裡。搜電話、姓名，或直接打「寄庫」。今天要出／逾期只篩上面預約排單，不會把寄庫藏起來。</span></div></header>
          <div class="reserved-shipping-list" data-reserved-hold-list><p>正在讀取…</p></div>
        </section>
        <section class="reserved-shipping-add">`;

if (!reservedShippingHoldDoesNotRestyleActive(SEARCH_NEW + FILTER_NEW + SECTION_NEW + QUICK_NEW)) {
  throw new Error("refusing to restyle .is-active");
}

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(HTML)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "hold-list");
backup(HTML, "hold-list");

let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, SEARCH_OLD, SEARCH_NEW, "hold search aliases");
js = replaceOnce(js, FILTER_OLD, FILTER_NEW, "date chips keep hold list");
js = replaceOnce(js, PRINT_OLD, PRINT_NEW, "print date chips keep hold list");
js = replaceOnce(js, EMPTY_OLD, EMPTY_NEW, "empty calendar points to hold list");
js = replaceOnce(js, CAND_OLD, CAND_NEW, "寄庫 search skips add-reservation");
fs.writeFileSync(ADMIN_JS, js);

let html = fs.readFileSync(HTML, "utf8");
html = replaceOnce(html, QUICK_OLD, QUICK_NEW, "寄庫 filter chip");
html = replaceOnce(html, SECTION_OLD, SECTION_NEW, "hold list mount");
html = html
  .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
  .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
fs.writeFileSync(HTML, html);

if (!reservedShippingHoldJsHasSearchAlias(js)) throw new Error("hold search missing after js patch");
if (!reservedShippingHoldListHtmlHasMount(html)) throw new Error("hold list mount missing after html patch");
if (!reservedShippingHoldQuickHasButton(html)) throw new Error("hold quick button missing after html patch");

console.log("LINGZANZAN reserved hold list ok", STAMP);
