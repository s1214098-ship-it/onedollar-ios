#!/usr/bin/env node
"use strict";

/**
 * 查貨卡把姓名加大，並顯示物流代收金額（COD）。
 * Cache-bust: cek-kirim.js/css ?v=20260820-cek-cod-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260820-cek-cod-1";
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");

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

const HELPER_OLD = `    return implode(' · ', $parts);
}
function returning_soon(array $queue, ?int $remaining): bool {`;

const HELPER_NEW = `    return implode(' · ', $parts);
}
function collection_amount(array $order): int {
    $summary = isset($order['paymentSummary']) && is_array($order['paymentSummary']) ? $order['paymentSummary'] : [];
    $cod = max(0, (int)($summary['codAmount'] ?? ($summary['storeIdCodAmount'] ?? 0)));
    if ($cod <= 0) {
        foreach (is_array($order['outboundParcels'] ?? null) ? $order['outboundParcels'] : [] as $parcel) {
            if (!is_array($parcel)) continue;
            $cod += max(0, (int)($parcel['amount'] ?? ($parcel['codAmount'] ?? 0)));
        }
    }
    if ($cod <= 0) $cod = max(0, (int)($summary['balance'] ?? ($summary['receivableBalance'] ?? ($order['paymentBalance'] ?? 0))));
    return $cod;
}
function returning_soon(array $queue, ?int $remaining): bool {`;

const FIELD_OLD = `        'items' => $items,
        'blacklisted' => false,
        'blacklistReason' => '',
    ];`;

const FIELD_NEW = `        'items' => $items,
        'codAmount' => collection_amount($order),
        'blacklisted' => false,
        'blacklistReason' => '',
    ];`;

if (!fs.existsSync(LOOKUP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

console.log("backup lookup", backup(LOOKUP, "cek-cod"));
let php = fs.readFileSync(LOOKUP, "utf8");
if (php.indexOf("function collection_amount(") === -1) {
  php = replaceOnce(php, HELPER_OLD, HELPER_NEW, "collection_amount helper");
} else {
  console.log("already: collection_amount helper");
}
if (php.indexOf("'codAmount' => collection_amount($order)") === -1) {
  php = replaceOnce(php, FIELD_OLD, FIELD_NEW, "cek_row codAmount");
} else {
  console.log("already: cek_row codAmount");
}
if (php.indexOf("function collection_amount(") === -1) throw new Error("collection_amount missing");
fs.writeFileSync(LOOKUP, php, "utf8");

const html = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
const js = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
if (html.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("stamp missing");
if (js.indexOf("function codHtml(") === -1) throw new Error("codHtml missing");
if (html.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN cek name+COD ok", STAMP);
