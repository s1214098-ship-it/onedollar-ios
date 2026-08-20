#!/usr/bin/env node
"use strict";

/**
 * 豪鴻訂單明細有品名，簽收後同步卻不把未建檔包裹寫進領讚讚。
 * 改成簽收後仍串聯品名；對照表用訂單明細 Goods 名稱。
 * 只讀豪鴻訂單／包裹，不改豪鴻網站集運。
 *
 * Cache-bust: admin.js ?v=20260820-hh-names-1
 */

const fs = require("fs");
const path = require("path");
const { spawnSync } = require("child_process");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = process.env.HH_PAGES || [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "haohong-logistics-api.php")));
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const HOURLY = path.join(ROOT, "scripts", "haohong-hourly-sync.js");
const SYNC_API = path.join(ROOT, "haohong-sync-api.php");
const FREIGHT = path.join(ROOT, "data", "freight-forwarding-tracking.json");
const SNAPSHOT = path.join(ROOT, "data", "haohong-logistics-snapshot.json");
const STAMP = "20260820-hh-names-1";
const MARKER = "haohongPreferProductName(";

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

function stampAdminJs(names) {
  names.forEach((name) => {
    const file = path.join(ROOT, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
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

function trackKey(value) {
  return String(value || "").replace(/\s+/g, "").toUpperCase();
}

function placeholderName(value) {
  const name = String(value || "").trim();
  return !name || name === "豪鴻待補產品名稱" || name === "後台尚未建檔";
}

function itemTrackKeys(item) {
  const keys = [item.trackingNo, item.haohongTrackingNo]
    .concat(Array.isArray(item.trackingNumbers) ? item.trackingNumbers : [])
    .map(trackKey)
    .filter(Boolean);
  return keys;
}

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

const ADMIN_HELPER_OLD = `  function applyHaoHongOrderData(order) {`;
const ADMIN_HELPER_NEW = `  function haohongPreferProductName(current, incoming) {
    var nowName = String(current || '').trim();
    var next = String(incoming || '').trim();
    if (next && (!nowName || nowName === '豪鴻待補產品名稱' || nowName === '後台尚未建檔')) return next;
    return nowName || next || '豪鴻待補產品名稱';
  }

  function applyHaoHongOrderData(order) {`;

const ADMIN_NAME_OLD = `        haohongWeightUpdatedAt: now,
        purchaseContentRaw: item.purchaseContentRaw || row.note || '',`;
const ADMIN_NAME_NEW = `        haohongWeightUpdatedAt: now,
        productName: haohongPreferProductName(item.productName, row.productName),
        purchaseContentRaw: item.purchaseContentRaw || row.note || '',`;

const ADMIN_IMPORT_OLD = `    var imported = 0;
    if (!(order.status && /簽收|签收/.test(order.status))) {
      (order.rows || []).forEach(function (row, rowIndex) {`;
const ADMIN_IMPORT_NEW = `    var imported = 0;
    (order.rows || []).forEach(function (row, rowIndex) {`;

const ADMIN_SKIP_OLD = `      imported += 1;
    });
    }
    var signed = !!(order.status && /簽收|签收/.test(order.status));
    if (signed && matchedItemCount === 0) {
      return { batchId: batchId, batchNo: batchNo, packageCount: packageCount, matchedItemCount: matchedItemCount, imported: 0, skipped: true };
    }`;
const ADMIN_SKIP_NEW = `      imported += 1;
    });
    var signed = !!(order.status && /簽收|签收/.test(order.status));
    if (signed && matchedItemCount === 0 && imported === 0) {
      return { batchId: batchId, batchNo: batchNo, packageCount: packageCount, matchedItemCount: matchedItemCount, imported: 0, skipped: true };
    }`;

const HOURLY_NAME_OLD = `      haohongWeightUpdatedAt: now,
      purchaseContentRaw: item.purchaseContentRaw || row.note || '',`;
const HOURLY_NAME_NEW = `      haohongWeightUpdatedAt: now,
      productName: (function () {
        var nowName = String(item.productName || '').trim();
        var next = String(row.productName || '').trim();
        if (next && (!nowName || nowName === '豪鴻待補產品名稱' || nowName === '後台尚未建檔')) return next;
        return nowName || next || item.productName;
      })(),
      purchaseContentRaw: item.purchaseContentRaw || row.note || '',`;

const HOURLY_IMPORT_OLD = `  const signed = !!(order.status && /簽收|签收/.test(order.status));
  let imported = 0;
  if (!signed) {
    (order.rows || []).forEach((row, rowIndex) => {`;
const HOURLY_IMPORT_NEW = `  const signed = !!(order.status && /簽收|签收/.test(order.status));
  let imported = 0;
  (order.rows || []).forEach((row, rowIndex) => {`;

const HOURLY_SKIP_OLD = `    imported += 1;
    });
  }
  if (signed && matchedItemCount === 0) {
    return { batchId, batchNo, packageCount, matchedItemCount, imported: 0, skipped: true };
  }`;
const HOURLY_SKIP_NEW = `    imported += 1;
    });
  if (signed && matchedItemCount === 0 && imported === 0) {
    return { batchId, batchNo, packageCount, matchedItemCount, imported: 0, skipped: true };
  }`;

const SYNC_HEADER_OLD = `header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');`;
const SYNC_HEADER_NEW = `if (PHP_SAPI !== 'cli') {
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}`;

const SYNC_IN_OLD = `if ($_SERVER['REQUEST_METHOD'] !== 'POST') hh_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
$raw = file_get_contents('php://input');
$incoming = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($incoming)) hh_respond(400, ['ok' => false, 'error' => 'Invalid JSON']);`;
const SYNC_IN_NEW = `if (PHP_SAPI === 'cli') {
    set_time_limit(0);
    $incoming = ['action' => $argv[1] ?? 'catalog', 'remember' => true];
} else {
if ($_SERVER['REQUEST_METHOD'] !== 'POST') hh_respond(405, ['ok' => false, 'error' => 'Method not allowed']);
$raw = file_get_contents('php://input');
$incoming = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($incoming)) hh_respond(400, ['ok' => false, 'error' => 'Invalid JSON']);
}`;

console.log("backup js", backup(ADMIN_JS, "hh-names"));
let admin = fs.readFileSync(ADMIN_JS, "utf8");
if (admin.indexOf(MARKER) === -1) {
  admin = replaceOnce(admin, ADMIN_HELPER_OLD, ADMIN_HELPER_NEW, "prefer HaoHong product name helper");
  admin = replaceOnce(admin, ADMIN_NAME_OLD, ADMIN_NAME_NEW, "copy HaoHong name onto matched items");
  admin = replaceOnce(admin, ADMIN_IMPORT_OLD, ADMIN_IMPORT_NEW, "import unmatched after signed");
  admin = replaceOnce(admin, ADMIN_SKIP_OLD, ADMIN_SKIP_NEW, "skip signed only when nothing imported");
} else {
  console.log("already: admin HaoHong name helper");
}
if (admin.indexOf(MARKER) === -1) throw new Error("admin helper missing");
fs.writeFileSync(ADMIN_JS, admin, "utf8");
stampAdminJs(["admin-freight.html", "admin-reserved-shipping.html", "admin-haohong-logistics.html"]);

if (fs.existsSync(HOURLY)) {
  console.log("backup hourly", backup(HOURLY, "hh-names"));
  let hourly = fs.readFileSync(HOURLY, "utf8");
  hourly = replaceOnce(hourly, HOURLY_NAME_OLD, HOURLY_NAME_NEW, "hourly copy HaoHong name");
  hourly = replaceOnce(hourly, HOURLY_IMPORT_OLD, HOURLY_IMPORT_NEW, "hourly import after signed");
  hourly = replaceOnce(hourly, HOURLY_SKIP_OLD, HOURLY_SKIP_NEW, "hourly skip only when empty");
  fs.writeFileSync(HOURLY, hourly, "utf8");
}

if (PAGES) {
  const src = path.join(PAGES, "haohong-logistics-api.php");
  const dest = path.join(ROOT, "haohong-logistics-api.php");
  if (fs.existsSync(src)) {
    backup(dest, "hh-names");
    fs.copyFileSync(src, dest);
    console.log("copied haohong-logistics-api.php");
  }
}

if (fs.existsSync(SYNC_API)) {
  console.log("backup sync", backup(SYNC_API, "hh-names"));
  let sync = fs.readFileSync(SYNC_API, "utf8");
  if (sync.indexOf("PHP_SAPI === 'cli'") === -1 && sync.indexOf('PHP_SAPI === "cli"') === -1) {
    sync = replaceOnce(sync, SYNC_HEADER_OLD, SYNC_HEADER_NEW, "cli skip headers");
    sync = replaceOnce(sync, SYNC_IN_OLD, SYNC_IN_NEW, "cli catalog action");
    fs.writeFileSync(SYNC_API, sync, "utf8");
  } else {
    console.log("already: sync cli catalog");
  }
}

const phpBin = "C:\\PHP82\\php.exe";
if (fs.existsSync(phpBin) || fs.existsSync("C:/PHP82/php.exe")) {
  console.log("pulling HaoHong catalog (read-only)...");
  const pulled = spawnSync(phpBin, [SYNC_API.replace(/\//g, "\\"), "catalog"], {
    encoding: "utf8",
    timeout: 240000,
    windowsHide: true,
  });
  const out = String(pulled.stdout || "").trim();
  const err = String(pulled.stderr || "").trim();
  if (pulled.status !== 0) {
    console.log("catalog exit", pulled.status, err.slice(0, 400), out.slice(0, 400));
  } else {
    console.log("catalog bytes", out.length, err ? ("stderr " + err.slice(0, 200)) : "ok");
    try {
      const payload = JSON.parse(out);
      if (payload && payload.ok) {
        // hh_respond already saved snapshot; keep a copy if missing
        if (!fs.existsSync(SNAPSHOT) && payload.orders) {
          atomicWriteJson(SNAPSHOT, payload);
          console.log("wrote snapshot fallback");
        }
        console.log("catalog orders", (payload.orders || []).length, "packages", (payload.packages || []).length);
      }
    } catch (e) {
      console.log("catalog json parse fail", e.message);
    }
  }
}

if (fs.existsSync(SNAPSHOT) && fs.existsSync(FREIGHT)) {
  const snap = JSON.parse(fs.readFileSync(SNAPSHOT, "utf8"));
  const freight = JSON.parse(fs.readFileSync(FREIGHT, "utf8"));
  freight.items = Array.isArray(freight.items) ? freight.items : [];
  freight.batches = Array.isArray(freight.batches) ? freight.batches : [];
  const now = new Date().toISOString();
  let imported = 0;
  let named = 0;
  (snap.orders || []).forEach((order) => {
    const batch = freight.batches.find((row) => {
      const ids = [row.id, row.batchNo, row.haohongOrderId, row.haohongOrderCode].map(String);
      return ids.indexOf(String(order.haohongOrderId || "")) >= 0 || ids.indexOf(String(order.haohongOrderCode || "")) >= 0;
    });
    const batchId = batch ? String(batch.id || "") : "";
    (order.rows || []).forEach((row) => {
      const key = trackKey(row.trackingNo);
      if (!key) return;
      const item = freight.items.find((rowItem) => itemTrackKeys(rowItem).indexOf(key) >= 0);
      if (item) {
        if (placeholderName(item.productName) && String(row.productName || "").trim()) {
          item.productName = String(row.productName).trim();
          item.updatedAt = now;
          named += 1;
        }
        return;
      }
      if (!batchId) return;
      freight.items.push({
        id: "HAOHONG-PACKAGE-" + key.replace(/[^A-Z0-9_-]/g, ""),
        date: String(row.receivedAt || order.orderDate || now).slice(0, 10),
        provider: "haohong",
        logisticsSource: "haohong",
        forwarder: "豪鴻集運倉",
        destinationWarehouse: "TW_BAOHUI",
        destinationSite: "寶輝據點",
        handlingPerItemTwd: 20,
        costMode: "haohong_weight_batch",
        trackingNo: row.trackingNo || "",
        haohongTrackingNo: row.trackingNo || "",
        haohongOrderId: order.haohongOrderId || "",
        haohongOrderCode: order.haohongOrderCode || "",
        batchId: batchId,
        productName: String(row.productName || "").trim() || "豪鴻待補產品名稱",
        purchaseContentRaw: row.note || "",
        note: row.note || "",
        quantity: Math.max(1, Number(row.quantity || 1)),
        actualWeightKg: Number(row.actualWeightKg || 0),
        volumeWeightKg: Number(row.volumeWeightKg || 0),
        billedWeightKg: Number(row.billedWeightKg || 0),
        progress: "到貨待分類",
        classificationStatus: "pending",
        requiresProductReview: true,
        issueType: "待分類",
        createdAt: now,
        updatedAt: now,
      });
      imported += 1;
    });
    if (batch) {
      const items = freight.items.filter((item) => String(item.batchId || "") === String(batch.id || ""));
      batch.packageRows = items.map((item) => Object.assign({}, item, { sourceItemId: item.id, snapshotAt: now }));
      batch.itemCount = items.reduce((sum, item) => sum + Math.max(1, Number(item.quantity || 1)), 0);
      batch.unitCount = batch.itemCount;
      batch.updatedAt = now;
    }
  });
  if (imported || named) {
    backup(FREIGHT, "hh-names");
    atomicWriteJson(FREIGHT, freight);
  }
  console.log("backfill imported", imported, "named", named);
}

console.log("LINGZANZAN HaoHong order names ok", STAMP);
