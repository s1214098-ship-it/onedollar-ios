#!/usr/bin/env node
"use strict";

/**
 * 業務查貨頁（cek.html）卡片可自己把客人鎖進黑名單。
 * 寫入既有 member-risk.json；打單頁會擋。管理後台才能解除。
 *
 * Cache-bust: cek-kirim.js/css ?v=20260820-sales-lock-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260820-sales-lock-1";
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
  if (!fs.existsSync(src)) throw new Error("missing " + src);
  const destDir = path.dirname(dest);
  if (!fs.existsSync(destDir)) fs.mkdirSync(destDir, { recursive: true });
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

const REQUIRE_OLD = `header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond(array $payload, int $status = 200): void {`;

const REQUIRE_NEW = `header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'member-risk-lib.php';

function respond(array $payload, int $status = 200): void {`;

const ROW_OLD = `        'deadlineMissing' => $state === 'arrived_store' && $deadline === '',
        'items' => $items,
    ];
};`;

const ROW_NEW = `        'deadlineMissing' => $state === 'arrived_store' && $deadline === '',
        'items' => $items,
        'blacklisted' => false,
        'blacklistReason' => '',
    ];
    if (function_exists('member_risk_find')) {
        $hit = member_risk_find(__DIR__, $phone, $name);
        if (is_array($hit)) {
            $cek['blacklisted'] = true;
            $cek['blacklistReason'] = text((string)($hit['reason'] ?? ''), 200);
        }
    }
    return $cek;
};`;

const ASSIGN_OLD = `    return [
        'orderId' => $orderId,`;

const ASSIGN_NEW = `    $cek = [
        'orderId' => $orderId,`;

if (!fs.existsSync(LOOKUP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

console.log("backup lookup", backup(LOOKUP, "sales-lock"));
let php = fs.readFileSync(LOOKUP, "utf8");
if (php.indexOf("member-risk-lib.php") === -1) {
  php = replaceOnce(php, REQUIRE_OLD, REQUIRE_NEW, "lookup require member-risk");
} else {
  console.log("already: lookup require member-risk");
}
if (php.indexOf("'blacklisted' => false") === -1) {
  php = replaceOnce(php, ASSIGN_OLD, ASSIGN_NEW, "cek_row assign");
  php = replaceOnce(php, ROW_OLD, ROW_NEW, "cek_row blacklisted");
} else {
  console.log("already: lookup blacklisted flag");
}
if (php.indexOf("member_risk_find") === -1) throw new Error("lookup missing member_risk_find");
fs.writeFileSync(LOOKUP, php, "utf8");

const html = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
if (html.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("cek stamp missing");
const js = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
if (js.indexOf("data-cek-lock-phone") === -1) throw new Error("lock button missing");
if (html.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN sales lock blacklist ok", STAMP);
