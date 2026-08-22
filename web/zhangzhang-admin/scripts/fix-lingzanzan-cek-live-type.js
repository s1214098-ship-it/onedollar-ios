#!/usr/bin/env node
"use strict";

/**
 * 查貨：打幾個字就匹配，不用再按金色大按鈕。
 * 電話開頭、後三／四碼、中間連續數字、姓名、物流單號都能中。
 *
 * Cache-bust: cek-kirim.js/css ?v=20260820-cek-type-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260820-cek-type-1";
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const JS_MARKER = "function scheduleLiveSearch(";
const PHP_MARKER = "function query_hits_phone(";

if (!PAGES) throw new Error("missing lingzanzan-pages/cek-kirim.js");

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

function copyUtf8(srcName, destRel) {
  const src = path.join(PAGES, srcName);
  const dest = path.join(ROOT, destRel);
  fs.copyFileSync(src, dest);
  console.log("copied", destRel, fs.statSync(dest).size);
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

const DIGITS_OLD = `function digits($value): string {
    return (string)(preg_replace('/\\D+/', '', text($value, 100)) ?? '');
}`;

const DIGITS_NEW = `function digits($value): string {
    return (string)(preg_replace('/\\D+/', '', text($value, 100)) ?? '');
}
function query_hits_phone(string $phone, string $digits): bool {
    if ($phone === '' || $digits === '') return false;
    $len = strlen($digits);
    if ($len < 3) return false;
    if (str_starts_with($phone, $digits) || str_ends_with($phone, $digits)) return true;
    return $len >= 4 && str_contains($phone, $digits);
}`;

const MATCH_OLD = `    $matches = false;
    if ($queryType === 'phone_suffix') {
        $matches = mb_strlen($queryDigits) >= 3 && str_ends_with($phone, $queryDigits);
    } elseif ($queryType === 'number') {
        $matches = str_contains(compact_key($orderId), $queryKey)
            || str_contains(compact_key($tracking), $queryKey)
            || ($phone !== '' && str_ends_with($phone, $queryDigits));
    } else {
        $matches = str_contains(compact_key($name), $queryKey)
            || str_contains(compact_key($orderId), $queryKey)
            || str_contains(compact_key($tracking), $queryKey);
    }`;

const MATCH_NEW = `    $trackingBlob = compact_key($tracking);
    foreach (is_array($order['outboundParcels'] ?? null) ? $order['outboundParcels'] : [] as $parcel) {
        if (!is_array($parcel)) continue;
        $trackingBlob .= compact_key($parcel['trackingNo'] ?? '');
    }
    $matches = false;
    if ($queryDigits !== '' && mb_strlen($queryDigits) >= 3) {
        $matches = query_hits_phone($phone, $queryDigits)
            || str_contains(compact_key($orderId), $queryKey)
            || str_contains($trackingBlob, $queryKey);
    }
    if (!$matches && $queryType === 'name') {
        $matches = str_contains(compact_key($name), $queryKey)
            || str_contains(compact_key($orderId), $queryKey)
            || str_contains($trackingBlob, $queryKey);
    }`;

const AMBIG_OLD = `$ambiguous = $queryType === 'name' && count($identities) > 1;`;
const AMBIG_NEW = `$ambiguous = count($identities) > 1;`;

if (!fs.existsSync(LOOKUP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

console.log("backup lookup", backup(LOOKUP, "cek-type"));
let php = fs.readFileSync(LOOKUP, "utf8");
if (php.indexOf(PHP_MARKER) === -1) {
  php = replaceOnce(php, DIGITS_OLD, DIGITS_NEW, "query_hits_phone helper");
} else {
  console.log("already: query_hits_phone helper");
}
if (php.indexOf("query_hits_phone($phone, $queryDigits)") === -1) {
  php = replaceOnce(php, MATCH_OLD, MATCH_NEW, "prefix/contains phone match");
} else {
  console.log("already: prefix/contains phone match");
}
if (php.indexOf(AMBIG_NEW) === -1) {
  php = replaceOnce(php, AMBIG_OLD, AMBIG_NEW, "ambiguous any query");
} else {
  console.log("already: ambiguous any query");
}
if (php.indexOf(PHP_MARKER) === -1) throw new Error("query_hits_phone missing");
if (php.indexOf("query_hits_phone($phone, $queryDigits)") === -1) {
  throw new Error("phone match missing");
}
fs.writeFileSync(LOOKUP, php, "utf8");

const html = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
const js = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
if (html.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("stamp missing");
if (js.indexOf(JS_MARKER) === -1) throw new Error("live type search missing");
if (html.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN cek live type match ok", STAMP);
