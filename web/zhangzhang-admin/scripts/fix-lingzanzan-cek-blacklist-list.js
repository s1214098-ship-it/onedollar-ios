#!/usr/bin/env node
"use strict";

/**
 * 查貨頁黑名單做成可點的整份清單，不用先打電話搜。
 * Cache-bust: cek-kirim.js/css ?v=20260820-cek-list-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.CEK_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "cek-kirim.js")));
const STAMP = "20260820-cek-list-1";
const LOOKUP = path.join(ROOT, "customer-shipping-lookup-api.php");
const JS_MARKER = "function setBoardTab(";

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

const SLICE_OLD = `        'blacklist' => array_slice($blacklist, 0, 80),
        'returnedCustomers' => array_slice($returnedCustomers, 0, 80),`;
const SLICE_NEW = `        'blacklist' => array_slice($blacklist, 0, 300),
        'returnedCustomers' => array_slice($returnedCustomers, 0, 300),`;

if (!fs.existsSync(LOOKUP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("cek.html", "cek.html");
copyUtf8("cek.html", "sales-phone.html");
copyUtf8("cek-kirim.css", "assets/cek-kirim.css");
copyUtf8("cek-kirim.js", "assets/cek-kirim.js");

console.log("backup lookup", backup(LOOKUP, "cek-list"));
let php = fs.readFileSync(LOOKUP, "utf8");
if (php.indexOf("array_slice($blacklist, 0, 300)") === -1) {
  php = replaceOnce(php, SLICE_OLD, SLICE_NEW, "blacklist list cap 300");
  fs.writeFileSync(LOOKUP, php, "utf8");
} else {
  console.log("already: blacklist list cap 300");
}

const html = fs.readFileSync(path.join(ROOT, "cek.html"), "utf8");
const js = fs.readFileSync(path.join(ROOT, "assets", "cek-kirim.js"), "utf8");
if (html.indexOf("cek-kirim.js?v=" + STAMP) === -1) throw new Error("stamp missing");
if (html.indexOf("data-cek-tabs") === -1) throw new Error("tabs missing");
if (js.indexOf(JS_MARKER) === -1) throw new Error("setBoardTab missing");
if (html.indexOf("\uFFFD") !== -1) throw new Error("cek html has FFFD");
console.log("LINGZANZAN cek blacklist list ok", STAMP);
