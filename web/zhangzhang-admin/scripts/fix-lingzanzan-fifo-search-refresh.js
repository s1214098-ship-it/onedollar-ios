#!/usr/bin/env node
"use strict";

/**
 * 集運搜尋先重抓 orders.json / 詢問單，避免剛打完的單還用舊記憶體或 IIS 304。
 * 不 stamp HTML，避免閃頁。
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const JS_MARKER = "refreshOrdersAndInquiries(true)";

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

const REFRESH_OLD = `  function refreshOrdersAndInquiries() {
    if (adminOrdersInquiryRefreshRequest) return adminOrdersInquiryRefreshRequest;
    adminOrdersInquiryRefreshRequest = Promise.all([
      loadJson('./data/orders.json').then(normalizeOrderRows).catch(function () { return state.orders || []; }),
      loadStockInquiries()
    ]).then(function (rows) {`;

const REFRESH_NEW = `  function refreshOrdersAndInquiries(force) {
    if (force) {
      adminOrdersInquiryRefreshRequest = null;
      adminStockInquiryRequests = {};
      Object.keys(adminJsonRequests).forEach(function (key) {
        if (/orders\\.json/i.test(String(key))) delete adminJsonRequests[key];
      });
    }
    if (adminOrdersInquiryRefreshRequest) return adminOrdersInquiryRefreshRequest;
    var ordersPath = force ? ('./data/orders.json?lz=' + Date.now()) : './data/orders.json';
    adminOrdersInquiryRefreshRequest = Promise.all([
      loadJson(ordersPath).then(normalizeOrderRows).catch(function () { return state.orders || []; }),
      loadStockInquiries()
    ]).then(function (rows) {`;

const SEARCH_OLD = `      if (result) result.innerHTML = '<small>' + (freightFifoQueueSearch ? '正在搜尋「' + escapeHtml(freightFifoQueueSearch) + '」' : '正在整理全部客戶') + '</small><strong>…</strong><span>請稍候</span>';
      scheduleFreightFifoQueueRender();`;

const SEARCH_NEW = `      if (result) result.innerHTML = '<small>' + (freightFifoQueueSearch ? '正在搜尋「' + escapeHtml(freightFifoQueueSearch) + '」' : '正在整理全部客戶') + '</small><strong>…</strong><span>請稍候</span>';
      if (typeof freightFifoInvalidateQueueCaches === 'function') freightFifoInvalidateQueueCaches();
      var goSearch = function () { scheduleFreightFifoQueueRender(); };
      if (typeof refreshOrdersAndInquiries === 'function') refreshOrdersAndInquiries(true).then(goSearch).catch(goSearch);
      else goSearch();`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "fifo-search-refresh"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, REFRESH_OLD, REFRESH_NEW, "force refresh orders");
  js = replaceOnce(js, SEARCH_OLD, SEARCH_NEW, "search refetches before render");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("search force refresh missing");
if (js.indexOf("orders.json?lz=") === -1) throw new Error("orders cache bust missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);
console.log("LINGZANZAN fifo search refresh ok");
console.log("no html stamp");
