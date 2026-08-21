#!/usr/bin/env node
"use strict";

/**
 * 改回待配貨：未交寄時勾選即可送出。留存超商單號若沒填原因，
 * 自動記「核對區改回待配貨，清除未交寄留存超商單號」。
 *
 * Cache-bust: admin.js ?v=20260821-revert-waiting-2
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "order-admin-api-v6.php");
const STAMP = "20260821-revert-waiting-2";
const JS_MARKER = "核對區改回待配貨，清除未交寄留存超商單號";

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

const PHP_OLD = `    if ($leftoverTracking !== '' && $reason === '') {
        respond(['ok' => false, 'error' => '這張還留有超商／物流單號 ' . $leftoverTracking . '。若已刪除或作廢，請填原因後再改回待配貨'], 409);
    }`;

const PHP_NEW = `    if ($leftoverTracking !== '' && $reason === '') {
        $reason = '核對區改回待配貨，清除未交寄留存超商單號';
    }`;

const JS_READY_OLD = `    var updateRevertReady = function () {
      var reason = revertReasonValue();
      var needReason = !!leftoverTracking;
      var ready = checkbox.checked && (!needReason || reason.length >= 2);
      confirmButton.disabled = !ready;
      status.textContent = !checkbox.checked
        ? (needReason ? '請填原因並勾選確認後才可送出' : '勾選確認後才可送出')
        : (needReason && reason.length < 2 ? '這張還留有超商單號，請填已刪除／作廢原因' : '可以送出');
    };`;

const JS_READY_NEW = `    var updateRevertReady = function () {
      confirmButton.disabled = !checkbox.checked;
      status.textContent = checkbox.checked
        ? (leftoverTracking ? '可以送出；留存超商單號會一併從核對單清掉' : '可以送出')
        : '勾選確認後才可送出';
    };`;

const JS_CLICK_OLD = `      var reason = revertReasonValue();
      if (!checkbox.checked || confirmButton.disabled) return;
      if (leftoverTracking && reason.length < 2) {
        status.textContent = '這張還留有超商單號，請填已刪除／作廢原因';
        return;
      }`;

const JS_CLICK_NEW = `      var reason = revertReasonValue();
      if (!checkbox.checked || confirmButton.disabled) return;
      if (leftoverTracking && reason.length < 2) reason = '核對區改回待配貨，清除未交寄留存超商單號';`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
    const page = fs.readFileSync(file, "latin1");
    if (page.indexOf("admin.js") === -1) return;
    const next = page.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === page) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(JS) || !fs.existsSync(PHP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "revert-waiting-2"));
let js = fs.readFileSync(JS, "utf8");
js = replaceOnce(js, JS_READY_OLD, JS_READY_NEW, "checkbox only enables confirm");
js = replaceOnce(js, JS_CLICK_OLD, JS_CLICK_NEW, "default void reason");
try {
  new Function(js);
  console.log("js syntax ok");
} catch (error) {
  throw new Error("admin.js syntax: " + error.message);
}
fs.writeFileSync(JS, js);

console.log("backup php", backup(PHP, "revert-waiting-2"));
let php = fs.readFileSync(PHP, "utf8");
php = replaceOnce(php, PHP_OLD, PHP_NEW, "php default void reason");
fs.writeFileSync(PHP, php);

stampHtml(ROOT);
if (js.indexOf(JS_MARKER) === -1) throw new Error("default reason missing in js");
if (php.indexOf(JS_MARKER) === -1) throw new Error("default reason missing in php");
console.log("done", STAMP);
