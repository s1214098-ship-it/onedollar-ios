#!/usr/bin/env node
"use strict";

/**
 * 完整電話不准拿訂單編號、物流單後 4 碼、或別人的電話去撞。
 * 出貨核對與訂單追蹤頁都要這樣。訂單追蹤頁若沒刷新 cache，會繼續誤打 14 筆。
 *
 * Cache-bust: admin.js / admin.css ?v=20260821-search-phone-2
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const STAMP = "20260821-search-phone-2";
const JS_MARKER = "function freightQueryLooksLikePhone(";
const TRACKING_MARKER = "freightQueryLooksLikePhone(query)";
const CSS_MARKER = "/* 20260821 tracking summary split */";

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
  if (src.indexOf(newStr) !== -1) {
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

const KEYS_OLD = `  function freightWorkbenchDigitSearchKeys(keyword) {
    var suffix = normalizeFreightIdentityNumber(keyword).replace(/\\D/g, '');
    if (suffix.length < 4) return [];
    var keys = [suffix];
    if (suffix.length > 4) {
      keys.push(suffix.slice(-4));
      keys.push(suffix.slice(0, 4));
      keys.push(suffix.slice(0, suffix.length - 1));
    }
    var unique = [];
    keys.forEach(function (key) {
      if (key.length >= 4 && unique.indexOf(key) === -1) unique.push(key);
    });
    return unique;
  }`;

const KEYS_NEW = `  function freightQueryLooksLikePhone(raw) {
    var digits = String(raw || '').replace(/\\D/g, '');
    return /^(0?9\\d{8}|0?8\\d{8,12}|62\\d{8,13})$/.test(digits);
  }

  function freightPhoneKey(raw) {
    var digits = String(raw || '').replace(/\\D/g, '');
    if (digits.charAt(0) === '0') return digits.slice(1);
    return digits;
  }

  function freightRowPhoneFields(row) {
    row = row || {};
    var customer = row.customer || {};
    var member = customer.member && typeof customer.member === 'object' ? customer.member : {};
    return [
      customer.phone,
      customer.customerPhone,
      member.phone,
      row.customerPhone,
      row.phone
    ];
  }

  function freightRowPhonesExact(row, query) {
    var q = freightPhoneKey(query);
    if (!q) return false;
    return freightRowPhoneFields(row).some(function (value) {
      var key = freightPhoneKey(value);
      return !!key && key === q;
    });
  }

  function freightWorkbenchDigitSearchKeys(keyword) {
    var suffix = normalizeFreightIdentityNumber(keyword).replace(/\\D/g, '');
    if (suffix.length < 4) return [];
    var keys = [suffix];
    if (freightQueryLooksLikePhone(suffix)) {
      if (suffix.charAt(0) === '0') keys.push(suffix.slice(1));
      else keys.push('0' + suffix);
    } else if (suffix.length > 4) {
      keys.push(suffix.slice(-4));
      keys.push(suffix.slice(0, 4));
      keys.push(suffix.slice(0, suffix.length - 1));
    }
    var unique = [];
    keys.forEach(function (key) {
      var min = freightQueryLooksLikePhone(suffix) ? 9 : 4;
      if (key.length >= min && unique.indexOf(key) === -1) unique.push(key);
    });
    return unique;
  }`;

const HAY_OLD = `  function freightDigitsHayMatch(hay, raw) {
    var keys = freightWorkbenchDigitSearchKeys(raw);
    var digits = String(hay || '').replace(/\\D/g, '');
    if (!digits) return false;
    if (!keys.length) {
      var q = String(raw || '').replace(/\\D/g, '');
      return q.length >= 4 && digits.indexOf(q) !== -1;
    }
    return keys.some(function (key) {
      return key.length >= 4 && digits.indexOf(key) !== -1;
    });
  }`;

const HAY_NEW = `  function freightDigitsHayMatch(hay, raw) {
    var keys = freightWorkbenchDigitSearchKeys(raw);
    var digits = String(hay || '').replace(/\\D/g, '');
    var q = String(raw || '').replace(/\\D/g, '');
    if (!digits) return false;
    if (freightQueryLooksLikePhone(q)) {
      return freightPhoneKey(digits) === freightPhoneKey(q);
    }
    if (!keys.length) return q.length >= 4 && digits.indexOf(q) !== -1;
    return keys.some(function (key) {
      return key.length >= 4 && digits.indexOf(key) !== -1;
    });
  }`;

const FIFO_OLD = `    if (phoneDigits.length >= 4 && phoneDigits === raw.replace(/[\\s\\-()+]/g, '')) {
      var rowPhone = freightFifoRowPhoneDigits(row);
      if (rowPhone && (rowPhone.indexOf(phoneDigits) !== -1 || rowPhone.slice(-phoneDigits.length) === phoneDigits)) return true;
      if (String(row.id || '').toUpperCase().indexOf(raw.toUpperCase()) !== -1) return true;
      return freightDigitsHayMatch([
        row.trackingNo,
        freightFifoTrackingJoin(row),
        row.supplierTrackingNo,
        row.freightTrackingNo,
        row.shippingTrackingNo
      ].join(' '), phoneDigits);
    }`;

const FIFO_NEW = `    if (phoneDigits.length >= 4 && phoneDigits === raw.replace(/[\\s\\-()+]/g, '')) {
      if (freightRowPhonesExact(row, phoneDigits)) return true;
      if (freightQueryLooksLikePhone(phoneDigits)) return false;
      var rowPhone = freightFifoRowPhoneDigits(row);
      if (rowPhone && (rowPhone.indexOf(phoneDigits) !== -1 || rowPhone.slice(-phoneDigits.length) === phoneDigits)) return true;
      return freightDigitsHayMatch([
        row.trackingNo,
        freightFifoTrackingJoin(row),
        row.supplierTrackingNo,
        row.freightTrackingNo,
        row.shippingTrackingNo
      ].join(' '), phoneDigits);
    }`;

const ORDER_PHONE_OLD = `      var phoneOnly = !!(query && adminOrderSearchIsPhoneOnly(query));
      var text = query ? (phoneOnly ? adminOrderPhoneSearchText(order) : adminOrderSearchText(order)) : '';
      var liveOrder = isAdminLiveOrder(order);
      var normalOrder = !liveOrder && (order.orderType || 'normal') !== 'preorder';
      var hasTrackingNo = !!String(order.trackingNo || '').trim();
      var queryHit = !query || orderTextMatches(text, query) || (phoneOnly && freightDigitsHayMatch([
        adminOrderPhoneSearchText(order),
        order.trackingNo,
        freightFifoTrackingJoin(order),
        order.supplierTrackingNo,
        order.freightTrackingNo,
        order.id
      ].join(' '), query));
      return queryHit &&`;

const ORDER_PHONE_NEW = `      var phoneOnly = !!(query && adminOrderSearchIsPhoneOnly(query));
      var queryDigits = String(query || '').replace(/\\D/g, '');
      var text = query ? (phoneOnly ? adminOrderPhoneSearchText(order) : adminOrderSearchText(order)) : '';
      var liveOrder = isAdminLiveOrder(order);
      var normalOrder = !liveOrder && (order.orderType || 'normal') !== 'preorder';
      var hasTrackingNo = !!String(order.trackingNo || '').trim();
      var queryHit = !query;
      if (query && freightQueryLooksLikePhone(queryDigits)) {
        queryHit = freightRowPhonesExact(order, queryDigits);
      } else if (query) {
        queryHit = orderTextMatches(text, query) || (phoneOnly && freightDigitsHayMatch([
          adminOrderPhoneSearchText(order),
          order.trackingNo,
          freightFifoTrackingJoin(order),
          order.supplierTrackingNo,
          order.freightTrackingNo
        ].join(' '), query));
      }
      return queryHit &&`;

const RESERVED_OLD = `  function reservedShippingMatchesQuery(order, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    var text = reservedShippingSearchText(order);
    if (text.indexOf(query) !== -1) return true;
    return freightDigitsHayMatch(text, query);
  }`;

const RESERVED_NEW = `  function reservedShippingMatchesQuery(order, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    if (freightQueryLooksLikePhone(query)) return freightRowPhonesExact(order, query);
    var text = reservedShippingSearchText(order);
    if (text.indexOf(query) !== -1) return true;
    return freightDigitsHayMatch(text, query);
  }`;

const PHP_OLD = `function query_hits_phone(string $phone, string $digits): bool {
    if ($phone === '' || $digits === '') return false;
    $len = strlen($digits);
    if ($len < 3) return false;
    if (str_starts_with($phone, $digits) || str_ends_with($phone, $digits)) return true;
    return $len >= 4 && str_contains($phone, $digits);
}
function query_digit_keys(string $digits): array {
    $digits = (string)(preg_replace('/\\D+/', '', $digits) ?? '');
    if (strlen($digits) < 4) return [];
    $keys = [$digits];
    if (strlen($digits) > 4) {
        $keys[] = substr($digits, -4);
        $keys[] = substr($digits, 0, 4);
        $keys[] = substr($digits, 0, -1);
    }`;

const PHP_NEW = `function query_phone_key(string $digits): string {
    $digits = (string)(preg_replace('/\\D+/', '', $digits) ?? '');
    if ($digits !== '' && $digits[0] === '0') return substr($digits, 1);
    return $digits;
}
function query_looks_like_phone(string $digits): bool {
    $digits = (string)(preg_replace('/\\D+/', '', $digits) ?? '');
    return (bool)preg_match('/^(0?9\\d{8}|0?8\\d{8,12}|62\\d{8,13})$/', $digits);
}
function query_hits_phone(string $phone, string $digits): bool {
    if ($phone === '' || $digits === '') return false;
    if (query_looks_like_phone($digits)) {
        $left = query_phone_key($phone);
        $right = query_phone_key($digits);
        return $left !== '' && $left === $right;
    }
    $len = strlen($digits);
    if ($len < 3) return false;
    if (str_starts_with($phone, $digits) || str_ends_with($phone, $digits)) return true;
    return $len >= 4 && str_contains($phone, $digits);
}
function query_digit_keys(string $digits): array {
    $digits = (string)(preg_replace('/\\D+/', '', $digits) ?? '');
    if (strlen($digits) < 4) return [];
    $keys = [$digits];
    if (query_looks_like_phone($digits)) {
        if ($digits[0] === '0') $keys[] = substr($digits, 1);
        else $keys[] = '0' . $digits;
    } elseif (strlen($digits) > 4) {
        $keys[] = substr($digits, -4);
        $keys[] = substr($digits, 0, 4);
        $keys[] = substr($digits, 0, -1);
    }`;

const PHP_MATCH_OLD = `        $matches = query_hits_phone($phone, $queryDigits)
            || str_contains(compact_key($orderId), $queryKey)
            || str_contains($trackingBlob, $queryKey)
            || query_hits_tracking($trackingBlob, $queryDigits);`;

const PHP_MATCH_NEW = `        $matches = query_hits_phone($phone, $queryDigits);
        if (!$matches && !query_looks_like_phone($queryDigits)) {
            $matches = str_contains($trackingBlob, $queryKey)
                || query_hits_tracking($trackingBlob, $queryDigits);
        }
        if (!$matches && !query_looks_like_phone($queryDigits) && preg_match('/[a-z]/i', $queryKey)) {
            $matches = str_contains(compact_key($orderId), $queryKey);
        }`;

const TRACKING_FILTER_OLD = `      if (query && !orderTextMatches(orderTrackingEntryText(entry), query)) return false;`;

const TRACKING_FILTER_NEW = `      if (query && freightQueryLooksLikePhone(query)) {
        var rec = entry.record || {};
        var ord = entry.order || {};
        if (!freightRowPhonesExact(rec, query) && !freightRowPhonesExact(ord, query)) return false;
      } else if (query && !orderTextMatches(orderTrackingEntryText(entry), query)) return false;`;

const TRACKING_ROWS_OLD = `      var sectionRows = orderTrackingFilterEntries(entries[category], category, now);
      var dataReady = orderTrackingSectionDataReady(category);
      globalRows = globalRows.concat(sectionRows);`;

const TRACKING_ROWS_NEW = `      var sectionRows = orderTrackingFilterEntries(entries[category], category, now);
      var dataReady = orderTrackingSectionDataReady(category);
      if (dataReady) {
        globalRows = globalRows.concat(sectionRows);
        trackingCategoryCounts[category] = sectionRows.length;
      }`;

const TRACKING_GLOBAL_OLD = `    var globalRows = [];
    // 搜尋時只展開有命中的分類。這保留平常的延遲載入，避免四區同時
    // 建立大量 DOM；也讓姓名／電話搜尋不必再手動猜要展開哪一區。`;

const TRACKING_GLOBAL_NEW = `    var globalRows = [];
    var trackingCategoryCounts = { ready: 0, preorder: 0, live: 0, inquiry: 0 };
    // 搜尋時只展開有命中的分類。這保留平常的延遲載入，避免四區同時
    // 建立大量 DOM；也讓姓名／電話搜尋不必再手動猜要展開哪一區。`;

const TRACKING_SUMMARY_OLD = `      if (orderTrackingAwaitingOrders) {
        summary.innerHTML = '<article><span>目前符合</span><strong>讀取中</strong></article><article><span>準備出貨</span><strong>讀取中</strong></article><article class="is-danger"><span>超過 12 天</span><strong>讀取中</strong></article><article><span>配送中</span><strong>讀取中</strong></article><article><span>到達門市</span><strong>讀取中</strong></article><article><span>取件完成</span><strong>讀取中</strong></article>';
      } else {
        var unshipped = globalRows.filter(orderTrackingIsUnshipped).length;
        var overdue = globalRows.filter(function (entry) { return orderTrackingIsOverdue(entry, now); }).length;
        var shipped = globalRows.filter(function (entry) { return entry.stage === 'shipped'; }).length;
        var arrivedStore = globalRows.filter(function (entry) { return entry.stage === 'arrived_store'; }).length;
        var delivered = globalRows.filter(function (entry) { return entry.stage === 'delivered'; }).length;
        summary.innerHTML = '<article><span>目前符合</span><strong>' + globalRows.length + '</strong></article><article><span>準備出貨</span><strong>' + unshipped + '</strong></article><article class="is-danger"><span>超過 12 天</span><strong>' + overdue + '</strong></article><article><span>配送中</span><strong>' + shipped + '</strong></article><article><span>到達門市</span><strong>' + arrivedStore + '</strong></article><article><span>取件完成</span><strong>' + delivered + '</strong></article>';
      }`;

const TRACKING_SUMMARY_NEW = `      if (orderTrackingAwaitingOrders || orderTrackingAwaitingInquiries) {
        summary.innerHTML = '<article><span>目前符合</span><strong>讀取中</strong></article><article><span>準備出貨</span><strong>讀取中</strong></article><article class="is-danger"><span>超過 12 天</span><strong>讀取中</strong></article><article><span>配送中</span><strong>讀取中</strong></article><article><span>到達門市</span><strong>讀取中</strong></article><article><span>取件完成</span><strong>讀取中</strong></article>';
      } else {
        var unshipped = globalRows.filter(orderTrackingIsUnshipped).length;
        var overdue = globalRows.filter(function (entry) { return orderTrackingIsOverdue(entry, now); }).length;
        var shipped = globalRows.filter(function (entry) { return entry.stage === 'shipped'; }).length;
        var arrivedStore = globalRows.filter(function (entry) { return entry.stage === 'arrived_store'; }).length;
        var delivered = globalRows.filter(function (entry) { return entry.stage === 'delivered'; }).length;
        var split = '現貨 ' + trackingCategoryCounts.ready + '／預購 ' + trackingCategoryCounts.preorder + '／直播 ' + trackingCategoryCounts.live + '／詢問 ' + trackingCategoryCounts.inquiry;
        summary.innerHTML = '<article><span>目前符合</span><strong>' + globalRows.length + '</strong><small class="order-tracking-summary-split">' + split + '</small></article><article><span>準備出貨</span><strong>' + unshipped + '</strong></article><article class="is-danger"><span>超過 12 天</span><strong>' + overdue + '</strong></article><article><span>配送中</span><strong>' + shipped + '</strong></article><article><span>到達門市</span><strong>' + arrivedStore + '</strong></article><article><span>取件完成</span><strong>' + delivered + '</strong></article>';
      }`;

const CSS_PATCH = `
${CSS_MARKER}
.order-tracking-summary article small.order-tracking-summary-split {
  display: block;
  margin-top: 4px;
  color: #c9c1ca;
  font-size: .72rem;
  font-weight: 700;
  line-height: 1.35;
}
`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
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

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "search-phone"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, KEYS_OLD, KEYS_NEW, "phone helpers + digit keys");
  js = replaceOnce(js, HAY_OLD, HAY_NEW, "phone-safe hay match");
  js = replaceOnce(js, FIFO_OLD, FIFO_NEW, "fifo phone exact, no order id");
  js = replaceOnce(js, ORDER_PHONE_OLD, ORDER_PHONE_NEW, "order search phone exact, no order id");
  js = replaceOnce(js, RESERVED_OLD, RESERVED_NEW, "reserved phone exact");
} else {
  console.log("js helpers already");
}
if (js.indexOf("freightRowPhonesExact(rec, query)") === -1) {
  js = replaceOnce(js, TRACKING_FILTER_OLD, TRACKING_FILTER_NEW, "tracking phone exact, no order id");
  js = replaceOnce(js, TRACKING_GLOBAL_OLD, TRACKING_GLOBAL_NEW, "tracking category counts");
  js = replaceOnce(js, TRACKING_ROWS_OLD, TRACKING_ROWS_NEW, "summary counts ready sections only");
  js = replaceOnce(js, TRACKING_SUMMARY_OLD, TRACKING_SUMMARY_NEW, "tracking summary split + wait inquiries");
} else {
  console.log("tracking page already patched");
}
fs.writeFileSync(ADMIN_JS, js);

if (fs.existsSync(ADMIN_CSS)) {
  console.log("backup css", backup(ADMIN_CSS, "search-phone"));
  let css = fs.readFileSync(ADMIN_CSS, "utf8");
  if (css.indexOf(CSS_MARKER) === -1) {
    css = css.replace(/\s*$/, "") + "\n" + CSS_PATCH;
    console.log("css summary split");
  } else {
    console.log("css already");
  }
  fs.writeFileSync(ADMIN_CSS, css);
}
stampHtml(ROOT);

if (fs.existsSync(LOOKUP)) {
  console.log("backup lookup", backup(LOOKUP, "search-phone"));
  let php = fs.readFileSync(LOOKUP, "utf8");
  if (php.indexOf("function query_looks_like_phone(") === -1) {
    php = replaceOnce(php, PHP_OLD, PHP_NEW, "cek phone exact helpers");
  } else {
    console.log("lookup helpers already");
  }
  if (php.indexOf("!query_looks_like_phone($queryDigits)") === -1) {
    php = replaceOnce(php, PHP_MATCH_OLD, PHP_MATCH_NEW, "cek skip order id on phone");
  } else {
    console.log("lookup match already");
  }
  fs.writeFileSync(LOOKUP, php);
}

if (js.indexOf(JS_MARKER) === -1) throw new Error("phone helper missing");
if (js.indexOf("freightRowPhonesExact(row, phoneDigits)") === -1) throw new Error("fifo exact phone missing");
if (js.indexOf("freightRowPhonesExact(order, queryDigits)") === -1) throw new Error("order exact phone missing");
if (js.indexOf("freightRowPhonesExact(rec, query)") === -1) throw new Error("tracking phone exact missing");
if (js.indexOf("order-tracking-summary-split") === -1) throw new Error("tracking summary split missing");
console.log("done", STAMP);
