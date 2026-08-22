#!/usr/bin/env node
"use strict";

/**
 * 退貨原因加「8. 重複打單」。不用再走「其他」手填。
 * 先不列入評分；未核對就出貨仍用 duplicate_unacked 扣點。
 *
 * Live: assets/admin.js, shipping-loss.php, order-admin-api-v6.php
 */

const fs = require("fs");
const path = require("path");
const {
  hasDuplicateOrderReason,
  insertDuplicateOrderJsOptions,
  insertDuplicateOrderPhpMap,
  insertDuplicateOrderApiError,
  insertDuplicateOrderHint,
  insertDuplicateOrderHtmlOption,
} = require("./lz-return-reason-duplicate");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const LOSS_PHP = path.join(ROOT, "shipping-loss.php");
const API_PHP = path.join(ROOT, "order-admin-api-v6.php");
const RETURNS_HTML = path.join(ROOT, "admin-customer-returns.html");
const STAMP = "20260822-return-dup-1";
const JS_MARKER = "value: 'duplicate_order'";

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

function stampHtml(dir) {
  const names = [
    "admin-freight.html",
    "admin-reserved-shipping.html",
    "admin-orders.html",
    "admin-order-tracking.html",
    "admin-preorders.html",
    "admin-live.html",
    "admin.html",
    "admin-customer-returns.html",
  ];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
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

console.log("backup js", backup(ADMIN_JS, "return-dup"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1 && js.indexOf("8. 重複打單") !== -1) {
  console.log("js already patched");
} else {
  const next = insertDuplicateOrderHint(insertDuplicateOrderJsOptions(js));
  if (next === js) throw new Error("admin.js missing return reason snippets");
  if (next.indexOf(JS_MARKER) === -1) throw new Error("duplicate_order not inserted into admin.js");
  js = next;
  fs.writeFileSync(ADMIN_JS, js, "utf8");
  console.log("patched admin.js");
}

if (fs.existsSync(LOSS_PHP)) {
  console.log("backup loss", backup(LOSS_PHP, "return-dup"));
  let loss = fs.readFileSync(LOSS_PHP, "utf8");
  const next = insertDuplicateOrderPhpMap(loss);
  if (next === loss && loss.indexOf("'duplicate_order' =>") === -1) {
    throw new Error("shipping-loss.php missing reason map snippet");
  }
  if (next !== loss) {
    fs.writeFileSync(LOSS_PHP, next, "utf8");
    console.log("patched shipping-loss.php");
  } else {
    console.log("loss already patched");
  }
}

if (fs.existsSync(API_PHP)) {
  console.log("backup api", backup(API_PHP, "return-dup"));
  let api = fs.readFileSync(API_PHP, "utf8");
  const next = insertDuplicateOrderApiError(api);
  if (next === api && api.indexOf("重複打單、其他") === -1) {
    throw new Error("order-admin-api-v6.php missing reason error snippet");
  }
  if (next !== api) {
    fs.writeFileSync(API_PHP, next, "utf8");
    console.log("patched order-admin-api-v6.php");
  } else {
    console.log("api error already patched");
  }
}

if (fs.existsSync(RETURNS_HTML)) {
  const html = fs.readFileSync(RETURNS_HTML, "utf8");
  const next = insertDuplicateOrderHtmlOption(html);
  if (next !== html) {
    console.log("backup returns html", backup(RETURNS_HTML, "return-dup"));
    fs.writeFileSync(RETURNS_HTML, next, "utf8");
    console.log("patched admin-customer-returns.html");
  } else {
    console.log("returns html skip (no hardcoded other option)");
  }
}

if (!hasDuplicateOrderReason(fs.readFileSync(ADMIN_JS, "utf8"))) {
  throw new Error("admin.js still missing duplicate_order");
}
if (fs.existsSync(LOSS_PHP) && !hasDuplicateOrderReason(fs.readFileSync(LOSS_PHP, "utf8"))) {
  throw new Error("shipping-loss.php still missing duplicate_order");
}

stampHtml(ROOT);
console.log("LINGZANZAN return reason 重複打單 ok", STAMP);
