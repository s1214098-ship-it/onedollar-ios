#!/usr/bin/env node
"use strict";

/**
 * 豪鴻已簽收 = 寶輝已驗收，直接帶入已簽收完成，不再建檔或核對。
 * 只把「已簽收」當完成；「待簽收」仍要看。
 * Cache-bust: admin-haohong-logistics.js/css ?v=20260820-signed-done-1
 * admin.js ?v=20260820-signed-done-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.HH_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "admin-haohong-logistics.js")));
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const HOURLY = path.join(ROOT, "scripts", "haohong-hourly-sync.js");
const FREIGHT = path.join(ROOT, "data", "freight-forwarding-tracking.json");
const SNAPSHOT = path.join(ROOT, "data", "haohong-logistics-snapshot.json");
const STAMP = "20260820-signed-done-1";
const MARKER = "haohongOrderIsSigned(";

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

function stampLatin1(names, replacements) {
  names.forEach((name) => {
    const file = path.join(ROOT, name);
    if (!fs.existsSync(file)) return;
    let html = fs.readFileSync(file, "latin1");
    const before = html;
    replacements.forEach((pair) => {
      html = html.replace(pair[0], pair[1]);
    });
    if (html === before) return;
    fs.writeFileSync(file, Buffer.from(html, "latin1"));
    console.log("stamped", name);
  });
}

function atomicWriteJson(file, data) {
  const tmp = file + ".tmp-" + Date.now();
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), "utf8");
  try {
    fs.copyFileSync(tmp, file);
    try { fs.unlinkSync(tmp); } catch (e) {}
  } catch (e) {
    fs.renameSync(tmp, file);
  }
}

function orderIsSigned(status) {
  const text = String(status || "");
  if (/待[簽签]收|未[簽签]收/.test(text)) return false;
  return /已[簽签]收/.test(text) || text.indexOf("已簽收完成") !== -1;
}

function trackKey(value) {
  return String(value || "").replace(/\s+/g, "").toUpperCase();
}

function itemTrackKeys(item) {
  return [item.trackingNo, item.haohongTrackingNo]
    .concat(Array.isArray(item.trackingNumbers) ? item.trackingNumbers : [])
    .map(trackKey)
    .filter(Boolean);
}

function copyUtf8(srcName, destRel) {
  const src = path.join(PAGES, srcName);
  const dest = path.join(ROOT, destRel);
  if (!fs.existsSync(src)) throw new Error("missing " + srcName);
  if (fs.existsSync(dest)) backup(dest, "signed-done");
  fs.copyFileSync(src, dest);
  console.log("copied", destRel, fs.statSync(dest).size);
}

if (!PAGES) throw new Error("missing lingzanzan-pages");
if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

copyUtf8("haohong-logistics-api.php", "haohong-logistics-api.php");
copyUtf8("admin-haohong-logistics.js", "assets/admin-haohong-logistics.js");
copyUtf8("admin-haohong-logistics.css", "assets/admin-haohong-logistics.css");

stampLatin1(["admin-haohong-logistics.html", "admin-freight.html", "admin-reserved-shipping.html"], [
  [/admin-haohong-logistics\.js(?:\?v=[^"']+)?/g, "admin-haohong-logistics.js?v=" + STAMP],
  [/admin-haohong-logistics\.css(?:\?v=[^"']+)?/g, "admin-haohong-logistics.css?v=" + STAMP],
  [/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP],
]);

const ADMIN_HELPER_OLD = `  function applyHaoHongOrderData(order) {`;
const ADMIN_HELPER_NEW = `  function haohongOrderIsSigned(status) {
    var text = String(status || '');
    if (/待[簽签]收|未[簽签]收/.test(text)) return false;
    return /已[簽签]收/.test(text);
  }

  function applyHaoHongOrderData(order) {`;

const ADMIN_PROGRESS_OLD = `        progress: order.status && /簽收|签收/.test(order.status) ? '配送中' : (item.progress || '已集運'),
        updatedAt: now`;
const ADMIN_PROGRESS_NEW = `        progress: haohongOrderIsSigned(order.status) ? '已簽收完成' : (item.progress || '已集運'),
        classificationStatus: haohongOrderIsSigned(order.status) ? (item.classificationStatus === 'pending' ? 'received' : item.classificationStatus) : item.classificationStatus,
        requiresProductReview: haohongOrderIsSigned(order.status) ? false : item.requiresProductReview,
        issueType: haohongOrderIsSigned(order.status) && String(item.issueType || '') === '待分類' ? '' : item.issueType,
        updatedAt: now`;

const ADMIN_IMPORT_OLD = `    var imported = 0;
    (order.rows || []).forEach(function (row, rowIndex) {`;
const ADMIN_IMPORT_NEW = `    var imported = 0;
    if (!haohongOrderIsSigned(order.status)) {
    (order.rows || []).forEach(function (row, rowIndex) {`;

const ADMIN_SKIP_OLD = `      imported += 1;
    });
    var signed = !!(order.status && /簽收|签收/.test(order.status));`;
const ADMIN_SKIP_NEW = `      imported += 1;
    });
    }
    var signed = haohongOrderIsSigned(order.status);`;

const ADMIN_STATUS_OLD = `status: order.status && /簽收|签收/.test(order.status) ? '配送中' : (existingBatch && existingBatch.status || '已集運')`;
const ADMIN_STATUS_NEW = `status: haohongOrderIsSigned(order.status) ? '已簽收完成' : (existingBatch && existingBatch.status || '已集運')`;

backup(ADMIN_JS, "signed-done");
let admin = fs.readFileSync(ADMIN_JS, "utf8");
if (admin.indexOf(MARKER) === -1) {
  admin = replaceOnce(admin, ADMIN_HELPER_OLD, ADMIN_HELPER_NEW, "signed helper");
} else {
  console.log("already: admin signed helper");
}
admin = replaceOnce(admin, ADMIN_PROGRESS_OLD, ADMIN_PROGRESS_NEW, "signed item progress");
admin = replaceOnce(admin, ADMIN_IMPORT_OLD, ADMIN_IMPORT_NEW, "do not import signed unmatched");
admin = replaceOnce(admin, ADMIN_SKIP_OLD, ADMIN_SKIP_NEW, "signed skip after no import");
admin = replaceOnce(admin, ADMIN_STATUS_OLD, ADMIN_STATUS_NEW, "signed batch status");
if (admin.indexOf(MARKER) === -1) throw new Error("admin signed helper missing");
fs.writeFileSync(ADMIN_JS, admin, "utf8");

if (fs.existsSync(HOURLY)) {
  backup(HOURLY, "signed-done");
  let hourly = fs.readFileSync(HOURLY, "utf8");
  const HOURLY_SIGNED_OLD = `  const signed = !!(order.status && /簽收|签收/.test(order.status));
  let imported = 0;
  (order.rows || []).forEach((row, rowIndex) => {`;
  const HOURLY_SIGNED_NEW = `  const signed = (function (status) {
    const text = String(status || '');
    if (/待[簽签]收|未[簽签]收/.test(text)) return false;
    return /已[簽签]收/.test(text);
  })(order.status);
  let imported = 0;
  if (!signed) {
  (order.rows || []).forEach((row, rowIndex) => {`;
  const HOURLY_SKIP_OLD = `    imported += 1;
    });
  if (signed && matchedItemCount === 0 && imported === 0) {`;
  const HOURLY_SKIP_NEW = `    imported += 1;
    });
  }
  if (signed && matchedItemCount === 0 && imported === 0) {`;
  const HOURLY_PROGRESS_OLD = `progress: order.status && /簽收|签收/.test(order.status) ? '配送中' : (item.progress || '已集運')`;
  const HOURLY_PROGRESS_NEW = `progress: (function (status) { const text = String(status || ''); if (/待[簽签]收|未[簽签]收/.test(text)) return false; return /已[簽签]收/.test(text); })(order.status) ? '已簽收完成' : (item.progress || '已集運')`;
  const HOURLY_STATUS_OLD = `status: signed ? '配送中' : (existingBatch && existingBatch.status || '已集運')`;
  const HOURLY_STATUS_NEW = `status: signed ? '已簽收完成' : (existingBatch && existingBatch.status || '已集運')`;
  hourly = replaceOnce(hourly, HOURLY_SIGNED_OLD, HOURLY_SIGNED_NEW, "hourly signed import gate");
  hourly = replaceOnce(hourly, HOURLY_SKIP_OLD, HOURLY_SKIP_NEW, "hourly close import gate");
  hourly = replaceOnce(hourly, HOURLY_PROGRESS_OLD, HOURLY_PROGRESS_NEW, "hourly signed item progress");
  hourly = replaceOnce(hourly, HOURLY_STATUS_OLD, HOURLY_STATUS_NEW, "hourly signed batch status");
  fs.writeFileSync(HOURLY, hourly, "utf8");
}

if (fs.existsSync(SNAPSHOT) && fs.existsSync(FREIGHT)) {
  const snap = JSON.parse(fs.readFileSync(SNAPSHOT, "utf8"));
  const freight = JSON.parse(fs.readFileSync(FREIGHT, "utf8"));
  freight.items = Array.isArray(freight.items) ? freight.items : [];
  freight.batches = Array.isArray(freight.batches) ? freight.batches : [];
  const now = new Date().toISOString();
  let itemDone = 0;
  let batchDone = 0;
  (snap.orders || []).forEach((order) => {
    if (!orderIsSigned(order.status)) return;
    const ids = [order.haohongOrderId, order.haohongOrderCode].map(String).filter(Boolean);
    const tracks = {};
    (order.rows || []).forEach((row) => {
      const key = trackKey(row.trackingNo);
      if (key) tracks[key] = true;
    });
    freight.items.forEach((item) => {
      const hit = ids.indexOf(String(item.haohongOrderId || "")) >= 0
        || ids.indexOf(String(item.haohongOrderCode || "")) >= 0
        || ids.some((id) => String(item.batchId || "").indexOf(id) >= 0)
        || itemTrackKeys(item).some((key) => tracks[key]);
      if (!hit) return;
      if (item.progress === "已簽收完成" && item.requiresProductReview !== true && item.classificationStatus !== "pending") return;
      item.progress = "已簽收完成";
      if (item.classificationStatus === "pending") item.classificationStatus = "received";
      item.requiresProductReview = false;
      if (String(item.issueType || "") === "待分類") item.issueType = "";
      item.updatedAt = now;
      itemDone += 1;
    });
    freight.batches.forEach((batch) => {
      const hit = [batch.id, batch.batchNo, batch.haohongOrderId, batch.haohongOrderCode]
        .map(String)
        .some((value) => ids.indexOf(value) >= 0 || ids.some((id) => value.indexOf(id) >= 0));
      if (!hit) return;
      if (batch.status === "已簽收完成") return;
      batch.status = "已簽收完成";
      batch.updatedAt = now;
      batchDone += 1;
    });
  });
  if (itemDone || batchDone) {
    backup(FREIGHT, "signed-done");
    atomicWriteJson(FREIGHT, freight);
  }
  console.log("backfill signed-done items", itemDone, "batches", batchDone);
}

const js = fs.readFileSync(path.join(ROOT, "assets", "admin-haohong-logistics.js"), "utf8");
const css = fs.readFileSync(path.join(ROOT, "assets", "admin-haohong-logistics.css"), "utf8");
const api = fs.readFileSync(path.join(ROOT, "haohong-logistics-api.php"), "utf8");
if (js.indexOf("data-haohong-status-filter") === -1) throw new Error("status filter missing");
if (css.indexOf("is-signed-done") === -1) throw new Error("signed css missing");
if (api.indexOf("hh_table_order_signed") === -1) throw new Error("php signed helper missing");
console.log("LINGZANZAN HaoHong signed-done ok", STAMP);
