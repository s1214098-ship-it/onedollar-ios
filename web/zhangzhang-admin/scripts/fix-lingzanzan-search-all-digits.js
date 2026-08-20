#!/usr/bin/env node
"use strict";

/**
 * 全後台數字搜尋：後碼／多打一位也能找到物流單。
 * 物流清單不再要滿 8 碼才當單號；FIFO／訂單數字不再只當電話；
 * 預約出貨、預購、豪鴻對照、查貨物流單號同樣可比對後碼。
 *
 * Cache-bust: admin.js ?v=20260820-search-all-1
 *             admin-haohong-logistics.js ?v=20260820-search-all-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const STAMP = "20260820-search-all-1";
const MARKER = "function freightDigitsHayMatch(";
const PAGES = [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-haohong-logistics.js")));

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

function stampAdminJs(dir) {
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
    console.log("stamped admin.js", name);
  }
  console.log("admin.js html stamped", n);
}

function stampHaohong(dir) {
  const file = path.join(dir, "admin-haohong-logistics.html");
  if (!fs.existsSync(file)) return;
  const html = fs.readFileSync(file, "latin1");
  const next = html.replace(
    /admin-haohong-logistics\.js(?:\?v=[^"']+)?/g,
    "admin-haohong-logistics.js?v=" + STAMP
  );
  if (next === html) return;
  fs.writeFileSync(file, Buffer.from(next, "latin1"));
  console.log("stamped admin-haohong-logistics.html");
}

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

const HELPER_OLD = `  function freightWorkbenchTrackingSuffixMatch(item, keyword) {
    var keys = freightWorkbenchDigitSearchKeys(keyword);
    if (!keys.length) return false;
    return freightWorkbenchIdentityValues(item).some(function (value) {
      var normalized = normalizeFreightIdentityNumber(value).replace(/\\D/g, '');
      if (!normalized) return false;
      return keys.some(function (suffix) {
        if (normalized.length < suffix.length) return false;
        if (normalized.slice(-suffix.length) === suffix) return true;
        return normalized.indexOf(suffix) !== -1;
      });
    });
  }`;

const HELPER_NEW = `  function freightWorkbenchTrackingSuffixMatch(item, keyword) {
    var keys = freightWorkbenchDigitSearchKeys(keyword);
    if (!keys.length) return false;
    return freightWorkbenchIdentityValues(item).some(function (value) {
      var normalized = normalizeFreightIdentityNumber(value).replace(/\\D/g, '');
      if (!normalized) return false;
      return keys.some(function (suffix) {
        if (normalized.length < suffix.length) return false;
        if (normalized.slice(-suffix.length) === suffix) return true;
        return normalized.indexOf(suffix) !== -1;
      });
    });
  }

  function freightDigitsQuery(raw) {
    var compact = String(raw || '').trim().replace(/\\s+/g, '');
    var digits = compact.replace(/\\D/g, '');
    return /^\\d+$/.test(compact) && digits.length >= 4;
  }

  function freightDigitsHayMatch(hay, raw) {
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

const FLAG_OLD = `    var freightTrackingLookup = keyword.replace(/[^a-z0-9]/g, '');
    var freightSearchIsTrackingLookup = freightTrackingLookup.length >= 8 && /\\d/.test(freightTrackingLookup);`;

const FLAG_NEW = `    var freightTrackingLookup = keyword.replace(/[^a-z0-9]/g, '');
    var freightDigitQuery = freightDigitsQuery(freightSearchRaw);
    var freightSearchIsTrackingLookup = freightDigitQuery || (freightTrackingLookup.length >= 8 && /\\d/.test(freightTrackingLookup));`;

const ITEM_OLD = `      if (!keyword) return true;
      if (freightSearchIsTrackingLookup) {
        return [item.trackingNo, item.haohongTrackingNo, item.pendingLogisticsCode, item.pendingTrackingCode, item.purchaseOrderNo, item.freightLinkNo, item.freightPurchaseGroupNo, item.customerOrderNo, item.inquiryId, item.orderId].some(function (value) {
          return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '').indexOf(freightTrackingLookup) !== -1;
        });
      }`;

const ITEM_NEW = `      if (!keyword) return true;
      if (freightSearchIsTrackingLookup) {
        if (freightDigitQuery) return freightWorkbenchTrackingSuffixMatch(item, freightSearchRaw);
        return [item.trackingNo, item.haohongTrackingNo, item.pendingLogisticsCode, item.pendingTrackingCode, item.purchaseOrderNo, item.freightLinkNo, item.freightPurchaseGroupNo, item.customerOrderNo, item.inquiryId, item.orderId].some(function (value) {
          return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '').indexOf(freightTrackingLookup) !== -1;
        });
      }`;

const BATCH_OLD = `      if (!keyword) return true;
      if (freightSearchIsTrackingLookup) {
        var batchTrackingValues = [batch.batchNo, batch.logisticsTrackingNo, batch.taiwanTrackingNo, batch.customsNo, batch.consolidationNo];
        freightBatchPackageRows(batch).forEach(function (item) {
          batchTrackingValues.push(item.trackingNo, item.haohongTrackingNo, item.pendingLogisticsCode, item.pendingTrackingCode, item.purchaseOrderNo);
        });
        return batchTrackingValues.some(function (value) {
          return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '').indexOf(freightTrackingLookup) !== -1;
        });
      }`;

const BATCH_NEW = `      if (!keyword) return true;
      if (freightSearchIsTrackingLookup) {
        if (freightDigitQuery) {
          return freightWorkbenchTrackingSuffixMatch(batch, freightSearchRaw)
            || freightBatchPackageRows(batch).some(function (item) {
              return freightWorkbenchTrackingSuffixMatch(item, freightSearchRaw);
            });
        }
        var batchTrackingValues = [batch.batchNo, batch.logisticsTrackingNo, batch.taiwanTrackingNo, batch.customsNo, batch.consolidationNo];
        freightBatchPackageRows(batch).forEach(function (item) {
          batchTrackingValues.push(item.trackingNo, item.haohongTrackingNo, item.pendingLogisticsCode, item.pendingTrackingCode, item.purchaseOrderNo);
        });
        return batchTrackingValues.some(function (value) {
          return String(value || '').toLowerCase().replace(/[^a-z0-9]/g, '').indexOf(freightTrackingLookup) !== -1;
        });
      }`;

const FIFO_OLD = `    if (phoneDigits.length >= 4 && phoneDigits === raw.replace(/[\\s\\-()+]/g, '')) {
      var rowPhone = freightFifoRowPhoneDigits(row);
      if (rowPhone && (rowPhone.indexOf(phoneDigits) !== -1 || rowPhone.slice(-phoneDigits.length) === phoneDigits)) return true;
      return String(row.id || '').toUpperCase().indexOf(raw.toUpperCase()) !== -1;
    }`;

const FIFO_NEW = `    if (phoneDigits.length >= 4 && phoneDigits === raw.replace(/[\\s\\-()+]/g, '')) {
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

const ORDER_TEXT_OLD = `  function orderTextMatches(text, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    return searchMatchesParts(text, query);
  }`;

const ORDER_TEXT_NEW = `  function orderTextMatches(text, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    if (searchMatchesParts(text, query)) return true;
    return freightDigitsQuery(query) && freightDigitsHayMatch(text, query);
  }`;

const ORDER_PHONE_OLD = `      var text = query ? (adminOrderSearchIsPhoneOnly(query) ? adminOrderPhoneSearchText(order) : adminOrderSearchText(order)) : '';
      var liveOrder = isAdminLiveOrder(order);
      var normalOrder = !liveOrder && (order.orderType || 'normal') !== 'preorder';
      var hasTrackingNo = !!String(order.trackingNo || '').trim();
      return (!query || orderTextMatches(text, query)) &&`;

const ORDER_PHONE_NEW = `      var phoneOnly = !!(query && adminOrderSearchIsPhoneOnly(query));
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

const RESERVED_OLD = `  function reservedShippingSearchText(order) {
    var customer = order.customer || {};
    return [
      order.id, customer.name, order.customerName, customer.phone, order.customerPhone, order.phone, customer.address,
      order.reservedShippingDate, order.reservedShippingNote, order.reservedShippingHoldReason, order.reservedShippingHoldReasonText, order.reservedShippingBy,
      order.shippingNote,
      (order.items || []).map(function (item) { return [item.code, item.title, item.name, item.color, item.size].join(' '); }).join(' ')
    ].join(' ').toLowerCase();
  }`;

const RESERVED_NEW = `  function reservedShippingSearchText(order) {
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
    var text = reservedShippingSearchText(order);
    if (text.indexOf(query) !== -1) return true;
    return freightDigitsHayMatch(text, query);
  }`;

const PREORDER_OLD = `    return (!normalizedQuery || text.indexOf(normalizedQuery) !== -1 || (compactQuery && compactText.indexOf(compactQuery) !== -1)) &&`;

const PREORDER_NEW = `    return (!normalizedQuery || text.indexOf(normalizedQuery) !== -1 || (compactQuery && compactText.indexOf(compactQuery) !== -1) || freightDigitsHayMatch(text, normalizedQuery)) &&`;

const PHP_HELPER_OLD = `function query_hits_phone(string $phone, string $digits): bool {
    if ($phone === '' || $digits === '') return false;
    $len = strlen($digits);
    if ($len < 3) return false;
    if (str_starts_with($phone, $digits) || str_ends_with($phone, $digits)) return true;
    return $len >= 4 && str_contains($phone, $digits);
}`;

const PHP_HELPER_NEW = `function query_hits_phone(string $phone, string $digits): bool {
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
    }
    $out = [];
    foreach ($keys as $key) {
        if (strlen($key) >= 4 && !in_array($key, $out, true)) $out[] = $key;
    }
    return $out;
}
function query_hits_tracking(string $blob, string $digits): bool {
    $blob = (string)(preg_replace('/\\D+/', '', $blob) ?? '');
    if ($blob === '') return false;
    foreach (query_digit_keys($digits) as $key) {
        if (str_contains($blob, $key)) return true;
    }
    return false;
}`;

const PHP_MATCH_OLD = `        $matches = query_hits_phone($phone, $queryDigits)
            || str_contains(compact_key($orderId), $queryKey)
            || str_contains($trackingBlob, $queryKey);`;

const PHP_MATCH_NEW = `        $matches = query_hits_phone($phone, $queryDigits)
            || str_contains(compact_key($orderId), $queryKey)
            || str_contains($trackingBlob, $queryKey)
            || query_hits_tracking($trackingBlob, $queryDigits);`;

console.log("backup js", backup(ADMIN_JS, "search-all"));
let src = fs.readFileSync(ADMIN_JS, "utf8");
if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(src, HELPER_OLD, HELPER_NEW, "digit hay helper");
  src = replaceOnce(src, FLAG_OLD, FLAG_NEW, "freight list digit query");
  src = replaceOnce(src, ITEM_OLD, ITEM_NEW, "freight list item digit match");
  src = replaceOnce(src, BATCH_OLD, BATCH_NEW, "freight list batch digit match");
  src = replaceOnce(src, FIFO_OLD, FIFO_NEW, "fifo tracking digit match");
  src = replaceOnce(src, ORDER_TEXT_OLD, ORDER_TEXT_NEW, "order text digit match");
  src = replaceOnce(src, ORDER_PHONE_OLD, ORDER_PHONE_NEW, "order phone-only also tracking");
  src = replaceOnce(src, RESERVED_OLD, RESERVED_NEW, "reserved shipping tracking");
  src = replaceOnce(src, PREORDER_OLD, PREORDER_NEW, "preorder digit match");
} else {
  console.log("already: digit hay helper");
}

src = src.replace(
  /reservedShippingSearchText\((order|entry\.row)\)\.indexOf\(query\) !== -1/g,
  "reservedShippingMatchesQuery($1, query)"
);
src = src.replace(
  /reservedShippingSearchText\((order|entry\.row)\)\.indexOf\(query\) === -1/g,
  "!reservedShippingMatchesQuery($1, query)"
);

if (src.indexOf(MARKER) === -1) throw new Error("digit hay helper missing");
if (src.indexOf("freightDigitQuery = freightDigitsQuery(freightSearchRaw)") === -1) {
  throw new Error("freight list digit query missing");
}
if (src.indexOf("reservedShippingMatchesQuery") === -1) throw new Error("reserved helper missing");
fs.writeFileSync(ADMIN_JS, src, "utf8");
stampAdminJs(ROOT);

if (PAGES) {
  const srcJs = path.join(PAGES, "admin-haohong-logistics.js");
  const destJs = path.join(ROOT, "assets", "admin-haohong-logistics.js");
  if (fs.existsSync(srcJs)) {
    if (fs.existsSync(destJs)) backup(destJs, "search-all-haohong");
    fs.copyFileSync(srcJs, destJs);
    console.log("copied admin-haohong-logistics.js", fs.statSync(destJs).size);
  }
}
stampHaohong(ROOT);

if (fs.existsSync(LOOKUP)) {
  console.log("backup lookup", backup(LOOKUP, "search-all"));
  let php = fs.readFileSync(LOOKUP, "utf8");
  if (php.indexOf("function query_hits_tracking(") === -1) {
    php = replaceOnce(php, PHP_HELPER_OLD, PHP_HELPER_NEW, "cek tracking digit keys");
  } else {
    console.log("already: cek tracking digit keys");
  }
  if (php.indexOf("query_hits_tracking($trackingBlob, $queryDigits)") === -1) {
    php = replaceOnce(php, PHP_MATCH_OLD, PHP_MATCH_NEW, "cek tracking extra-digit match");
  } else {
    console.log("already: cek tracking extra-digit match");
  }
  if (php.indexOf("function query_hits_tracking(") === -1) throw new Error("cek tracking helper missing");
  fs.writeFileSync(LOOKUP, php, "utf8");
}

console.log("LINGZANZAN search-all digits ok", STAMP);
