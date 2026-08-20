#!/usr/bin/env node
"use strict";

/**
 * 預購轉正式出貨、台灣現貨快速提撥時，扣了庫存卻沒寫 item.freightReceivedQty。
 * 出貨卡「產品名額與實際到貨狀態」用 HtmlLite，缺這個欄位就顯示
 * 「實際驗收到貨 0 件／尚未到貨」。庫存被扣成 0 後，現貨足夠也不能補。
 *
 * Cache-bust: admin.js ?v=20260820-tw-convert-arrived-1
 * HTML stamp is latin1 (byte-preserving), freight pages only.
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "stock-inquiry-api.php");
const ORDERS = path.join(ROOT, "data", "orders.json");
const STAMP = "20260820-tw-convert-arrived-1";
const JS_MARKER = "function freightFifoItemRecordedReceivedQty(";
const PHP_MARKER = "$mappedItem['freightReceivedQty'] = $takeQty;";
const SKIP_ORDER_IDS = {
  "BYORDER-20260720-078596": true,
};

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

function stampHtml(dir) {
  const names = ["admin-freight.html", "admin-reserved-shipping.html"];
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) {
      console.log("skip missing", name);
      return;
    }
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, "utf8").replace(/\0+$/g, "").trimEnd());
}

function writeJson(file, value) {
  const json = JSON.stringify(value, null, 2) + "\n";
  const tmp = file + ".tmp-tw-arrived-" + Date.now();
  fs.writeFileSync(tmp, json);
  try {
    fs.renameSync(tmp, file);
  } catch (error) {
    try {
      fs.copyFileSync(tmp, file);
    } finally {
      try {
        fs.unlinkSync(tmp);
      } catch (_) {}
    }
  }
}

function itemIsWaiting(item) {
  if (!item || typeof item !== "object") return true;
  if (item.waitingArrival || item.unfulfilledAtConvert || item.priorityPending) return true;
  const src = String(
    item.allocationSourceWarehouse || item.sourceWarehouse || item.warehouseCode || item.warehouse || ""
  ).toUpperCase();
  if (src.indexOf("PREORDER") >= 0 || src.indexOf("預購") >= 0 || src.indexOf("预购") >= 0) return true;
  return false;
}

function itemPhysicalWarehouse(item) {
  const src = String(
    item.sourceWarehouseCode ||
      item.freightReceivedWarehouse ||
      item.allocationSourceWarehouse ||
      item.sourceWarehouse ||
      ""
  ).toUpperCase();
  const sku = String(item.skuId || item.sku || "");
  return (
    /\b(TW|CN|ID|TAIWAN)\b/.test(src) ||
    src.indexOf("台灣") >= 0 ||
    src.indexOf("台湾") >= 0 ||
    src.indexOf("中國") >= 0 ||
    src.indexOf("中国") >= 0 ||
    src.indexOf("印尼") >= 0 ||
    /[-_](TW|CN|ID)$/i.test(sku)
  );
}

function orderAlreadyFulfilled(order) {
  return !!(
    order &&
    (order.inventoryDeducted ||
      Number(order.preorderAutoOutQty || 0) > 0 ||
      order.preorderTransferOutAt)
  );
}

function backfillOrders() {
  if (!fs.existsSync(ORDERS)) {
    console.log("skip backfill: no orders.json");
    return;
  }
  console.log("backup orders", backup(ORDERS, "tw-convert-arrived"));
  const orders = readJson(ORDERS);
  if (!Array.isArray(orders)) throw new Error("orders.json is not an array");
  let itemCount = 0;
  let orderCount = 0;
  const samples = [];
  orders.forEach((order) => {
    if (!order || SKIP_ORDER_IDS[String(order.id || "")]) return;
    if (!orderAlreadyFulfilled(order) || !Array.isArray(order.items)) return;
    let changed = false;
    order.items.forEach((item) => {
      if (!item || typeof item !== "object") return;
      if (item.freightReceivedQty !== undefined && item.freightReceivedQty !== null && item.freightReceivedQty !== "") {
        return;
      }
      if (itemIsWaiting(item) || !itemPhysicalWarehouse(item)) return;
      const qty = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
      item.freightReceivedQty = qty;
      if (!item.freightReceivedWarehouse) {
        const code = String(item.sourceWarehouseCode || "").toUpperCase();
        if (code === "TW" || code === "CN" || code === "ID") item.freightReceivedWarehouse = code;
        else if (/[-_]TW$/i.test(String(item.skuId || item.sku || ""))) item.freightReceivedWarehouse = "TW";
      }
      item.waitingArrival = false;
      itemCount += 1;
      changed = true;
      if (samples.length < 12) {
        samples.push({
          orderId: order.id,
          code: item.code || item.productCode || item.skuId,
          color: item.color || item.colorName,
          size: item.size || item.sizeName,
          qty,
        });
      }
    });
    if (changed) orderCount += 1;
  });
  if (!itemCount) {
    console.log("backfill: nothing to write");
    return;
  }
  writeJson(ORDERS, orders);
  console.log("backfill orders", orderCount, "items", itemCount);
  console.log("backfill samples", JSON.stringify(samples, null, 2));
}

const HELPERS = `
  function freightFifoItemWaitingArrival(item) {
    item = item || {};
    if (item.waitingArrival || item.unfulfilledAtConvert || item.priorityPending) return true;
    var src = String(item.allocationSourceWarehouse || item.sourceWarehouse || item.warehouseCode || item.warehouse || '').toUpperCase();
    return src.indexOf('PREORDER') !== -1 || src.indexOf('預購') !== -1 || src.indexOf('预购') !== -1;
  }
  function freightFifoItemRecordedReceivedQty(item, context) {
    item = item || {};
    context = context || {};
    var requested = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
    var hasArrival = item.freightReceivedQty !== undefined && item.freightReceivedQty !== null && item.freightReceivedQty !== '';
    if (hasArrival) return Math.min(requested, Math.max(0, Number(item.freightReceivedQty || 0)));
    if (freightFifoItemWaitingArrival(item)) return 0;
    var row = context.row || {};
    var isFormalOrder = !!context.formalOrder || (state.orders || []).some(function (order) {
      return String(order && order.id || '') === String(row.id || context.orderId || '');
    });
    if (!isFormalOrder) return 0;
    return requested;
  }
`;

const COVERED_OLD = `  function freightFifoItemCoveredQty(item) {
    item = item || {};
    var requested = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
    var received = Math.min(requested, Math.max(0, Number(item.freightReceivedQty || 0)));
    if (received >= requested) return requested;
    var taiwanQty = Math.max(0, Number(preorderItemReadyStockQty(item) || 0));
    return Math.min(requested, received + taiwanQty);
  }`;

const COVERED_NEW = HELPERS + `  function freightFifoItemCoveredQty(item, context) {
    item = item || {};
    context = context || {};
    var requested = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
    var received = Math.min(requested, freightFifoItemRecordedReceivedQty(item, context));
    if (received >= requested) return requested;
    var taiwanQty = Math.max(0, Number(preorderItemReadyStockQty(item) || 0));
    return Math.min(requested, received + taiwanQty);
  }`;

const STATE_OLD = `    items.forEach(function (item) {
      var requested = Math.max(1, Number(item && (item.requestedQty || item.qty || item.quantity) || 1));
      var received = Math.min(requested, Math.max(0, Number(item && item.freightReceivedQty || 0)));
      requestedQty += requested;
      freightQty += received;
      coveredQty += freightFifoItemCoveredQty(item);
    });`;

const STATE_NEW = `    items.forEach(function (item) {
      var requested = Math.max(1, Number(item && (item.requestedQty || item.qty || item.quantity) || 1));
      var ctx = { row: row, formalOrder: !!(row && (row.inventoryDeducted || row.preorderTransferOutAt || Number(row.preorderAutoOutQty || 0) > 0)) };
      var received = Math.min(requested, freightFifoItemRecordedReceivedQty(item, ctx));
      requestedQty += requested;
      freightQty += received;
      coveredQty += freightFifoItemCoveredQty(item, ctx);
    });`;

const LITE_OLD = `      var requested = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
      var freightReceived = Math.max(0, Number(item.freightReceivedQty || 0));
      var covered = context.row ? freightFifoItemCoveredQty(item) : freightReceived;`;

const LITE_NEW = `      var requested = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
      var freightReceived = Math.max(0, freightFifoItemRecordedReceivedQty(item, context));
      var covered = freightFifoItemCoveredQty(item, context);`;

const PHP_OLD = `                $mappedItem['qty'] = $takeQty;
                $mappedItem['quantity'] = $takeQty;
                $mappedItem['sourceWarehouseCode'] = $itemWarehouse;
                $mappedItem['sourceWarehouse'] = $itemProfile['name'];
                $mappedItem['freightReceivedWarehouse'] = $itemWarehouse;
                $mappedItem['customerReservedReceipt'] = $itemUsesCustomerReservation;
                $mappedItems[] = $mappedItem;`;

const PHP_NEW = `                $mappedItem['qty'] = $takeQty;
                $mappedItem['quantity'] = $takeQty;
                $mappedItem['sourceWarehouseCode'] = $itemWarehouse;
                $mappedItem['sourceWarehouse'] = $itemProfile['name'];
                $mappedItem['allocationSourceWarehouse'] = $itemWarehouse;
                $mappedItem['freightReceivedWarehouse'] = $itemWarehouse;
                $mappedItem['freightReceivedQty'] = $takeQty;
                $mappedItem['waitingArrival'] = false;
                $mappedItem['customerReservedReceipt'] = $itemUsesCustomerReservation;
                $mappedItems[] = $mappedItem;`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "tw-convert-arrived"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (js.indexOf(JS_MARKER) !== -1 && js.indexOf("var freightReceived = Math.max(0, Number(item.freightReceivedQty || 0));") === -1) {
  console.log("js already patched");
} else {
  js = replaceOnce(js, COVERED_OLD, COVERED_NEW, "covered qty uses convert-fulfilled receipt");
  js = replaceOnce(js, STATE_OLD, STATE_NEW, "arrival state uses recorded receipt");
  js = replaceOnce(js, LITE_OLD, LITE_NEW, "html lite uses recorded receipt");
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("js marker missing after patch");
if (js.indexOf("freightFifoItemRecordedReceivedQty(item, context)") === -1) {
  throw new Error("html lite still uses raw freightReceivedQty");
}
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);

console.log("backup php", backup(PHP, "tw-convert-arrived"));
let php = fs.readFileSync(PHP, "utf8");
if (php.indexOf(PHP_MARKER) !== -1) {
  console.log("php already patched");
} else {
  php = replaceOnce(php, PHP_OLD, PHP_NEW, "convert TW map writes freightReceivedQty");
}
if (php.indexOf(PHP_MARKER) === -1) throw new Error("php marker missing after patch");
fs.writeFileSync(PHP, php, "utf8");
console.log("php written", PHP, "len", php.length);

stampHtml(ROOT);
backfillOrders();
console.log("LINGZANZAN convert TW arrived ok", STAMP);
