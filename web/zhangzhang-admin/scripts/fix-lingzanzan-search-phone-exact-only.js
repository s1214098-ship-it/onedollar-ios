#!/usr/bin/env node
"use strict";

/**
 * 完整電話就算夾在 LINE 貼上、店號、破折號裡，也只准打到同一支號碼。
 * 不准再用後 4 碼、開頭相符、或整段文字去撞別人的訂單／電話。
 *
 * Cache-bust: admin.js ?v=20260821-search-phone-3
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const STAMP = "20260821-search-phone-3";
const JS_MARKER = "function freightExtractCompletePhones(";

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

const LOOKS_OLD = `    return /^(0?9\\d{8}|0?8\\d{8,12}|62\\d{8,13})$/.test(digits);`;

const LOOKS_NEW = `    return /^(0?9\\d{8}|0[2-8]\\d{7,9}|0?8\\d{8,12}|62\\d{8,13})$/.test(digits);`;

const HELPER_OLD = `  function freightRowPhonesExact(row, query) {
    var q = freightPhoneKey(query);
    if (!q) return false;
    return freightRowPhoneFields(row).some(function (value) {
      var key = freightPhoneKey(value);
      return !!key && key === q;
    });
  }`;

const HELPER_NEW = `  function freightRowPhonesExact(row, query) {
    var q = freightPhoneKey(query);
    if (!q) return false;
    return freightRowPhoneFields(row).some(function (value) {
      var key = freightPhoneKey(value);
      return !!key && key === q;
    });
  }

  function freightExtractCompletePhones(raw) {
    var s = String(raw || '');
    var found = [];
    function add(value) {
      var digits = String(value || '').replace(/\\D/g, '');
      if (freightQueryLooksLikePhone(digits) && found.indexOf(digits) === -1) found.push(digits);
    }
    add(s);
    (s.match(/\\d+/g) || []).forEach(add);
    var joined = s.replace(/[\\s\\-()+]/g, '');
    if (/^\\d+$/.test(joined)) add(joined);
    return found;
  }

  function freightQueryWithoutPhones(raw, phones) {
    var s = String(raw || '');
    (phones || []).forEach(function (phone) {
      var d = String(phone || '').replace(/\\D/g, '');
      if (!d) return;
      s = s.replace(new RegExp(d.split('').join('[\\\\s\\\\-()+]*'), 'g'), ' ');
    });
    return s.replace(/[\\s\\-()+,，]+/g, ' ').trim();
  }

  function freightTextHasExactPhone(text, phone) {
    var q = freightPhoneKey(phone);
    if (!q) return false;
    return freightExtractCompletePhones(text).some(function (value) {
      return freightPhoneKey(value) === q;
    });
  }`;

const FIFO_HEAD_OLD = `    var raw = String(freightFifoQueueSearch || '').trim();
    if (!raw) return true;
    row = row || {};
    var customer = row.customer || {};
    var phoneDigits = raw.replace(/\\D/g, '');`;

const FIFO_HEAD_NEW = `    var raw = String(freightFifoQueueSearch || '').trim();
    if (!raw) return true;
    row = row || {};
    var customer = row.customer || {};
    var extractedPhones = freightExtractCompletePhones(raw);
    if (extractedPhones.length) {
      if (!extractedPhones.some(function (phone) { return freightRowPhonesExact(row, phone); })) return false;
      raw = freightQueryWithoutPhones(raw, extractedPhones);
      if (!raw) return true;
    }
    var phoneDigits = raw.replace(/\\D/g, '');`;

const ORDER_TEXT_OLD = `  function orderTextMatches(text, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    if (searchMatchesParts(text, query)) return true;
    return freightDigitsQuery(query) && freightDigitsHayMatch(text, query);
  }`;

const ORDER_TEXT_NEW = `  function orderTextMatches(text, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    var phones = freightExtractCompletePhones(query);
    if (phones.length) {
      if (!phones.some(function (phone) { return freightTextHasExactPhone(text, phone); })) return false;
      var rest = freightQueryWithoutPhones(query, phones);
      if (!rest) return true;
      return searchMatchesParts(text, rest);
    }
    if (searchMatchesParts(text, query)) return true;
    return freightDigitsQuery(query) && freightDigitsHayMatch(text, query);
  }`;

const TRACKING_OLD = `      if (query && freightQueryLooksLikePhone(query)) {
        var rec = entry.record || {};
        var ord = entry.order || {};
        if (!freightRowPhonesExact(rec, query) && !freightRowPhonesExact(ord, query)) return false;
      } else if (query && !orderTextMatches(orderTrackingEntryText(entry), query)) return false;`;

const TRACKING_NEW = `      var trackingPhones = query ? freightExtractCompletePhones(query) : [];
      if (trackingPhones.length) {
        var rec = entry.record || {};
        var ord = entry.order || {};
        if (!trackingPhones.some(function (phone) { return freightRowPhonesExact(rec, phone) || freightRowPhonesExact(ord, phone); })) return false;
      } else if (query && !orderTextMatches(orderTrackingEntryText(entry), query)) return false;`;

const RESERVED_OLD = `    if (freightQueryLooksLikePhone(query)) return freightRowPhonesExact(order, query);`;

const RESERVED_NEW = `    var reservedPhones = freightExtractCompletePhones(query);
    if (reservedPhones.length) return reservedPhones.some(function (phone) { return freightRowPhonesExact(order, phone); });`;

const PREORDER_OLD = `    return (!normalizedQuery || text.indexOf(normalizedQuery) !== -1 || (compactQuery && compactText.indexOf(compactQuery) !== -1) || freightDigitsHayMatch(text, normalizedQuery)) &&`;

const PREORDER_NEW = `    if (normalizedQuery) {
      var preorderPhones = freightExtractCompletePhones(normalizedQuery);
      if (preorderPhones.length) {
        if (!preorderPhones.some(function (phone) { return freightRowPhonesExact(row, phone) || freightTextHasExactPhone(text, phone); })) return false;
      } else if (!(text.indexOf(normalizedQuery) !== -1 || (compactQuery && compactText.indexOf(compactQuery) !== -1) || freightDigitsHayMatch(text, normalizedQuery))) return false;
    }
    return true &&`;

const RANK_OLD = `      if (digits && phone === digits) best = Math.min(best, 0);
      if (raw && text.indexOf(raw) === 0) best = Math.min(best, 1);
      if (compact && loose.indexOf(compact) === 0) best = Math.min(best, 1);
      if (digits && phone.indexOf(digits) === 0) best = Math.min(best, 1);
      if (digits && digits.length >= 4 && phone.slice(-digits.length) === digits) best = Math.min(best, 2);
      if (raw && raw.length > 1 && text.indexOf(raw) !== -1) best = Math.min(best, 3);
      if (compact && compact.length > 1 && loose.indexOf(compact) !== -1) best = Math.min(best, 3);
      if (digits && phone.indexOf(digits) !== -1) best = Math.min(best, 3);`;

const RANK_NEW = `      if (freightQueryLooksLikePhone(digits) || freightExtractCompletePhones(raw).length) {
        var want = freightPhoneKey((freightExtractCompletePhones(raw)[0] || digits));
        if (want && freightPhoneKey(phone) === want) best = Math.min(best, 0);
      } else {
        if (digits && phone === digits) best = Math.min(best, 0);
        if (raw && text.indexOf(raw) === 0) best = Math.min(best, 1);
        if (compact && loose.indexOf(compact) === 0) best = Math.min(best, 1);
        if (digits && phone.indexOf(digits) === 0) best = Math.min(best, 1);
        if (digits && digits.length >= 4 && phone.slice(-digits.length) === digits) best = Math.min(best, 2);
        if (raw && raw.length > 1 && text.indexOf(raw) !== -1) best = Math.min(best, 3);
        if (compact && compact.length > 1 && loose.indexOf(compact) !== -1) best = Math.min(best, 3);
        if (digits && phone.indexOf(digits) !== -1) best = Math.min(best, 3);
      }`;

const MEMBER_OLD = `    if (digits && phone === digits) best = Math.min(best, 0);
    if (compact && name.indexOf(compact) === 0) best = Math.min(best, 1);
    if (digits && phone.indexOf(digits) === 0) best = Math.min(best, 1);
    if (digits && digits.length >= 4 && phone.slice(-digits.length) === digits) best = Math.min(best, 2);`;

const MEMBER_NEW = `    if (freightQueryLooksLikePhone(digits) || freightExtractCompletePhones(raw).length) {
      var wantPhone = freightPhoneKey((freightExtractCompletePhones(raw)[0] || digits));
      if (wantPhone && freightPhoneKey(phone) === wantPhone) best = Math.min(best, 0);
    } else {
    if (digits && phone === digits) best = Math.min(best, 0);
    if (compact && name.indexOf(compact) === 0) best = Math.min(best, 1);
    if (digits && phone.indexOf(digits) === 0) best = Math.min(best, 1);
    if (digits && digits.length >= 4 && phone.slice(-digits.length) === digits) best = Math.min(best, 2);
    }`;

const MEMBER_TAIL_OLD = `    if (digits && phone.indexOf(digits) > 0) best = Math.min(best, 3);`;

const MEMBER_TAIL_NEW = `    if (!freightQueryLooksLikePhone(digits) && !freightExtractCompletePhones(raw).length && digits && phone.indexOf(digits) > 0) best = Math.min(best, 3);`;

const PHP_LOOKS_OLD = `    return (bool)preg_match('/^(0?9\\d{8}|0?8\\d{8,12}|62\\d{8,13})$/', $digits);`;

const PHP_LOOKS_NEW = `    return (bool)preg_match('/^(0?9\\d{8}|0[2-8]\\d{7,9}|0?8\\d{8,12}|62\\d{8,13})$/', $digits);`;

const PHP_MATCH_OLD = `        $matches = query_hits_phone($phone, $queryDigits);
        if (!$matches && !query_looks_like_phone($queryDigits)) {
            $matches = str_contains($trackingBlob, $queryKey)
                || query_hits_tracking($trackingBlob, $queryDigits);
        }
        if (!$matches && !query_looks_like_phone($queryDigits) && preg_match('/[a-z]/i', $queryKey)) {
            $matches = str_contains(compact_key($orderId), $queryKey);
        }`;

const PHP_MATCH_NEW = `        $phoneQuery = query_looks_like_phone($queryDigits);
        if (!$phoneQuery && preg_match('/(0?9\\d{8})/', $queryDigits, $m)) {
            $phoneQuery = true;
            $queryDigits = $m[1];
        }
        $matches = $phoneQuery
            ? query_hits_phone($phone, $queryDigits)
            : (query_hits_phone($phone, $queryDigits)
                || str_contains($trackingBlob, $queryKey)
                || query_hits_tracking($trackingBlob, $queryDigits)
                || (preg_match('/[a-z]/i', $queryKey) && str_contains(compact_key($orderId), $queryKey)));`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
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

console.log("backup js", backup(ADMIN_JS, "search-phone-exact"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, LOOKS_OLD, LOOKS_NEW, "widen complete-phone detector");
  js = replaceOnce(js, HELPER_OLD, HELPER_NEW, "extract complete phones");
  js = replaceOnce(js, FIFO_HEAD_OLD, FIFO_HEAD_NEW, "fifo extract phone first");
  js = replaceOnce(js, ORDER_TEXT_OLD, ORDER_TEXT_NEW, "order text exact phone");
  js = replaceOnce(js, TRACKING_OLD, TRACKING_NEW, "tracking extract phone");
  js = replaceOnce(js, RESERVED_OLD, RESERVED_NEW, "reserved extract phone");
  js = replaceOnce(js, PREORDER_OLD, PREORDER_NEW, "preorder extract phone");
  js = replaceOnce(js, RANK_OLD, RANK_NEW, "search rank exact phone");
  js = replaceOnce(js, MEMBER_OLD, MEMBER_NEW, "member rank exact phone");
  js = replaceOnce(js, MEMBER_TAIL_OLD, MEMBER_TAIL_NEW, "member rank skip contains");
} else {
  console.log("js already patched");
}
fs.writeFileSync(ADMIN_JS, js);
stampHtml(ROOT);

if (fs.existsSync(LOOKUP)) {
  console.log("backup lookup", backup(LOOKUP, "search-phone-exact"));
  let php = fs.readFileSync(LOOKUP, "utf8");
  if (php.indexOf("0[2-8]\\d{7,9}") === -1) {
    php = replaceOnce(php, PHP_LOOKS_OLD, PHP_LOOKS_NEW, "cek widen phone");
  } else {
    console.log("cek looks already");
  }
  if (php.indexOf("$phoneQuery = query_looks_like_phone($queryDigits)") === -1) {
    php = replaceOnce(php, PHP_MATCH_OLD, PHP_MATCH_NEW, "cek extract phone");
  } else {
    console.log("cek match already");
  }
  fs.writeFileSync(LOOKUP, php);
}

if (js.indexOf(JS_MARKER) === -1) throw new Error("extract helper missing");
if (js.indexOf("extractedPhones.length") === -1) throw new Error("fifo extract missing");
console.log("done", STAMP);
