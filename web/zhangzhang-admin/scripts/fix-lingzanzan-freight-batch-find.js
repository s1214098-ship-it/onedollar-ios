#!/usr/bin/env node
"use strict";

/**
 * 物流批次工作台：剛新增的物流單用後碼／中間數字就能找到。
 * 33520 這種多打一位的數字，也能對到 7902620993352（後碼 3352）。
 * 找不到時列出最近還沒入批次的單。
 *
 * Cache-bust: admin.js ?v=20260820-batch-find-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260820-batch-find-1";
const MARKER = "function freightWorkbenchDigitSearchKeys(";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
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

function stampFreight(dir) {
  ["admin-freight.html", "admin-reserved-shipping.html"].forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    console.log("stamped", name);
  });
}

const ID_OLD = `  function freightWorkbenchIdentityValues(item) {
    item = item || {};
    var values = [
      item.trackingNo,
      item.haohongTrackingNo,
      item.pendingLogisticsCode,
      item.pendingTrackingCode,
      item.purchaseOrderNo,
      item.freightLinkNo,
      item.batchNo,
      item.id,
      item.customsNo,
      item.consolidationNo,
      item.logisticsTrackingNo,
      item.taiwanTrackingNo,
      item.haohongOrderCode,
      item.haohongOrderId
    ];`;

const ID_NEW = `  function freightWorkbenchIdentityValues(item) {
    item = item || {};
    var values = [
      item.trackingNo,
      item.haohongTrackingNo,
      item.pendingLogisticsCode,
      item.pendingTrackingCode,
      item.purchaseOrderNo,
      item.purchaseOrderLast6,
      item.customerOrderNo,
      item.freightLinkNo,
      item.batchNo,
      item.id,
      item.customsNo,
      item.consolidationNo,
      item.logisticsTrackingNo,
      item.taiwanTrackingNo,
      item.haohongOrderCode,
      item.haohongOrderId,
      item.productCode,
      item.catalogProductCode,
      item.sampleBarcode,
      item.productFiledBarcode,
      item.customerSupplyBarcode
    ];`;

const MATCH_OLD = `  function freightWorkbenchTrackingSuffixMatch(item, keyword) {
    var suffix = normalizeFreightIdentityNumber(keyword).replace(/\\D/g, '');
    if (suffix.length < 4) return false;
    return freightWorkbenchIdentityValues(item).some(function (value) {
      var normalized = normalizeFreightIdentityNumber(value).replace(/\\D/g, '');
      return normalized.length >= suffix.length && normalized.slice(-suffix.length) === suffix;
    });
  }`;

const MATCH_NEW = `  function freightWorkbenchDigitSearchKeys(keyword) {
    var suffix = normalizeFreightIdentityNumber(keyword).replace(/\\D/g, '');
    if (suffix.length < 4) return [];
    var keys = [suffix];
    if (suffix.length > 4) {
      keys.push(suffix.slice(-4));
      keys.push(suffix.slice(0, 4));
      keys.push(suffix.slice(0, suffix.length - 1));
    }
    var unique = [];
    keys.forEach(function (key) {
      if (key.length >= 4 && unique.indexOf(key) === -1) unique.push(key);
    });
    return unique;
  }

  function freightWorkbenchTrackingSuffixMatch(item, keyword) {
    var keys = freightWorkbenchDigitSearchKeys(keyword);
    if (!keys.length) return false;
    return freightWorkbenchIdentityValues(item).some(function (value) {
      var normalized = normalizeFreightIdentityNumber(value).replace(/\\D/g, '');
      if (!normalized) return false;
      return keys.some(function (suffix) {
        if (normalized.length < suffix.length) return false;
        if (normalized.slice(-suffix.length) === suffix) return true;
        return normalized.indexOf(suffix) !== -1;
      });
    });
  }`;

const RECENT_OLD = `    var emptyHtml = !batchHitHtml && !itemHtml
      ? '<p>找不到待選物流或批號；若剛加入本批，代表已從待選清單移除並保留在上方本批明細。</p>'
      : '';
    resultHost.innerHTML = batchHitHtml + itemHtml + emptyHtml;
  }`;

const RECENT_NEW = `    var emptyHtml = !batchHitHtml && !itemHtml
      ? '<p>找不到待選物流或批號；若剛加入本批，代表已從待選清單移除並保留在上方本批明細。</p>' + freightWorkbenchRecentUnbatchedHtml()
      : '';
    resultHost.innerHTML = batchHitHtml + itemHtml + emptyHtml;
  }

  function freightWorkbenchRecentUnbatchedHtml() {
    var rows = normalizeFreightTracking(state.freightTracking).items.filter(function (item) {
      return !item.batchId && !freightItemLeavesBatchSelection(item);
    }).slice().sort(function (a, b) {
      return String(b.updatedAt || b.createdAt || '').localeCompare(String(a.updatedAt || a.createdAt || ''));
    }).slice(0, 8);
    if (!rows.length) return '';
    return '<p><b>最近新增還沒入批次：</b>可直接勾選，不必打完整物流單號。</p>' + rows.map(function (item) {
      var id = String(item.id || '');
      return '<article><label class="freight-workbench-check"><input type="checkbox" data-freight-workbench-check="' + escapeHtml(id) + '"><span>勾選</span></label><div><b>' + escapeHtml(item.trackingNo || item.pendingLogisticsCode || '未填物流單號') + '</b><small>' + escapeHtml(item.productName || item.productCode || '未命名產品') + '／' + Math.max(1, Number(item.quantity || 1)) + ' 件</small></div><button type="button" class="primary-button" data-freight-workbench-add="' + escapeHtml(id) + '">加入本批</button></article>';
    }).join('');
  }`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "batch-find"));
let src = fs.readFileSync(ADMIN_JS, "utf8");
if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(src, ID_OLD, ID_NEW, "workbench identity values");
  src = replaceOnce(src, MATCH_OLD, MATCH_NEW, "digit contains + extra-zero keys");
  src = replaceOnce(src, RECENT_OLD, RECENT_NEW, "recent unbatched fallback");
} else {
  console.log("already: digit search keys");
}
if (src.indexOf(MARKER) === -1) throw new Error("digit search keys missing");
if (src.indexOf("freightWorkbenchRecentUnbatchedHtml") === -1) throw new Error("recent unbatched missing");
fs.writeFileSync(ADMIN_JS, src, "utf8");
stampFreight(ROOT);
console.log("LINGZANZAN freight batch find ok", STAMP);
