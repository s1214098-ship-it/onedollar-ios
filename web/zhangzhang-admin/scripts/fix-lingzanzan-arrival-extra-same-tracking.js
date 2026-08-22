#!/usr/bin/env node
"use strict";

/**
 * 到貨核對「加入這張物流單」會被 PHP 擋成重複單號。
 * 改成同單號追加（isSupplementalSplitReceipt + parentFreightItemId）。
 * 已確認的「本次多到」不再當成工作列／批次異常。
 *
 * Cache-bust: admin.js / admin.css ?v=20260821-arrival-extra-split-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "freight-tracking-api.php");
const STAMP = "20260821-arrival-extra-split-1";
const JS_MARKER = "function freightItemHasOpenWorklineIssue(";
const PHP_MARKER = "arrivalExtraOnTracking";

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

const ISSUE_HELPER = `  function freightItemHasOpenWorklineIssue(item) {
    item = item || {};
    if (item.issueType && item.issueType !== '正常') return true;
    var progress = String(item.progress || item.issueProgress || '');
    if (/缺件/.test(progress) || (/異常/.test(progress) && !/無異常/.test(progress))) return true;
    if (!item.quantityReconciliationRequired) return false;
    var diff = Number(item.receivingQuantityDifference || 0);
    if (diff > 0) return false;
    if ((item.arrivalExtraOnTracking || item.isSupplementalSplitReceipt) && diff >= 0) return false;
    return true;
  }

  function freightWorklineMetrics(mode) {`;

const ISSUE_OLD = `  function freightWorklineMetrics(mode) {`;

const ISSUE_FILTER_OLD = `    var issueItems = items.filter(function (item) {
      return (item.issueType && item.issueType !== '正常') || item.quantityReconciliationRequired || /缺件|異常|待處理/.test(String(item.progress || item.issueProgress || ''));
    }).length;`;

const ISSUE_FILTER_NEW = `    var issueItems = items.filter(function (item) {
      return freightItemHasOpenWorklineIssue(item);
    }).length;`;

const MISSING_OLD = `          return !!row.quantityReconciliationRequired || Number(row.receivingQuantityDifference || 0) < 0 || /缺件|短少|少件|數量不足|missing|shortage/i.test(statusWords) || (hasReceiptResult && expectedQty > actualQty);`;

const MISSING_NEW = `          var overageOnly = Number(row.receivingQuantityDifference || 0) > 0 || (hasReceiptResult && actualQty > expectedQty);
          return (!overageOnly && !!row.quantityReconciliationRequired) || Number(row.receivingQuantityDifference || 0) < 0 || /缺件|短少|少件|數量不足|missing|shortage/i.test(statusWords) || (hasReceiptResult && expectedQty > actualQty);`;

const EXTRA_RETURN_OLD = `      note: '到貨核對新增：同單號多款',
      arrivalExtraOnTracking: true,
      createdAt: now,
      updatedAt: now,
      receivingInspection: {
        lines: [{ actualQty: qty }],
        varianceReason: '同物流單加帶其他款，到貨核對時新增'
      }
    };`;

const EXTRA_RETURN_NEW = `      note: '到貨核對新增：同單號多款',
      arrivalExtraOnTracking: true,
      parentFreightItemId: String(sourceItem && (sourceItem.parentFreightItemId || sourceItem.id) || ''),
      parentInventoryOperationId: String(sourceItem && (sourceItem.inventoryOperationId || (sourceItem.inventoryReceiptClaim && sourceItem.inventoryReceiptClaim.operationId) || '') || ''),
      isSupplementalSplitReceipt: true,
      supplementalReceiptId: 'EXTRA-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7).toUpperCase(),
      supplementalSequence: typeof freightSupplementalReceiptSequence === 'function' ? freightSupplementalReceiptSequence(sourceItem) : 1,
      supplementalTrackingNo: trackingNo,
      supplementalReceivingPrefillLines: [{ sourceVariantIndex: 0, actualQty: qty }],
      supplementalReceivingPrefillTotal: qty,
      quantityReconciliationRequired: false,
      issueType: '',
      issueProgress: '',
      productFiledInventoryReceived: false,
      createdAt: now,
      updatedAt: now,
      receivingInspection: {
        lines: [{ actualQty: qty }],
        varianceReason: '同物流單加帶其他款，到貨核對時新增'
      }
    };`;

const EXTRA_SAVE_OLD = `    var sourceItems = freightArrivalItemsForTracking(batch, trackingNo);
    var sourceItem = sourceItems[0] || {};
    if (!panel || !batch || !trackingNo) { toast('找不到這張物流單，無法新增'); return; }`;

const EXTRA_SAVE_NEW = `    var sourceItems = freightArrivalItemsForTracking(batch, trackingNo);
    var sourceItem = sourceItems.find(function (item) {
      return item && item.id && !item.isSupplementalSplitReceipt;
    }) || sourceItems[0] || {};
    if (sourceItem.parentFreightItemId) sourceItem = freightItemById(sourceItem.parentFreightItemId) || sourceItem;
    if (!panel || !batch || !trackingNo) { toast('找不到這張物流單，無法新增'); return; }
    if (!String(sourceItem.id || '').trim()) { toast('找不到原物流明細，無法加入同單號其他款'); return; }`;

const OPEN_OLD = `    var items = freightArrivalItemsForTracking(batch, trackingNo);
    if (!batch || !items.length) {
      toast('找不到這張物流單的產品明細，未改任何數量');
      return;
    }
    if (items.every(function (item) { return freightItemClosesBatchQueueWithoutSelectionCheck(item); })) {
      showFreightArrivalItemActions(batch, trackingNo);
      toast('這張物流已正式入庫，不會重複寫入；可查看原入庫或重印條碼');
      return;
    }`;

const OPEN_NEW = `    var items = freightArrivalItemsForTracking(batch, trackingNo).filter(function (item) {
      return !freightItemClosesBatchQueueWithoutSelectionCheck(item);
    });
    if (!batch || !freightArrivalItemsForTracking(batch, trackingNo).length) {
      toast('找不到這張物流單的產品明細，未改任何數量');
      return;
    }
    if (!items.length) {
      showFreightArrivalItemActions(batch, trackingNo);
      toast('這張物流已正式入庫，不會重複寫入；可查看原入庫或重印條碼');
      return;
    }`;

const CONFIRM_OLD = `      return {
        itemId: itemId,
        actual: actual,
        destination: destination,
        canDirectReceive: canDirectReceive,
        body: {
          action: 'save-receiving-inspection',
          freightItemId: itemId,
          lines: lines,
          varianceReason: reason,
          final: canDirectReceive
        }
      };
    });`;

const CONFIRM_NEW = `      return {
        itemId: itemId,
        actual: actual,
        destination: destination,
        canDirectReceive: canDirectReceive,
        body: {
          action: 'save-receiving-inspection',
          freightItemId: itemId,
          lines: lines,
          varianceReason: reason,
          final: canDirectReceive
        }
      };
    }).filter(function (request) {
      return !freightItemClosesBatchQueueWithoutSelectionCheck(freightItemById(request.itemId) || {});
    });`;

const PHP_ALLOW_OLD = `function freight_allows_same_tracking_supplemental(array $item, array $items, string $normalizedTrackingNo): bool {
    if ($normalizedTrackingNo === '' || empty($item['isSupplementalSplitReceipt'])) return false;`;

const PHP_ALLOW_NEW = `function freight_allows_same_tracking_supplemental(array $item, array $items, string $normalizedTrackingNo): bool {
    if ($normalizedTrackingNo === '') return false;
    if (empty($item['isSupplementalSplitReceipt']) && empty($item['arrivalExtraOnTracking'])) return false;`;

const PHP_RECON_OLD = `    $items[$itemIndex]['quantityReconciliationRequired'] = $isFinal && $difference !== 0;
    if ($isFinal && $difference !== 0) {
        $items[$itemIndex]['quantityReconciliationEverOccurred'] = true;
        $items[$itemIndex]['quantityReconciliationStatus'] = 'pending';
        $items[$itemIndex]['quantityReconciliationNote'] = $reason;
        $items[$itemIndex]['quantityReconciliationUpdatedAt'] = $now;
    }`;

const PHP_RECON_NEW = `    $items[$itemIndex]['quantityReconciliationRequired'] = $isFinal && $difference < 0;
    if ($isFinal && $difference < 0) {
        $items[$itemIndex]['quantityReconciliationEverOccurred'] = true;
        $items[$itemIndex]['quantityReconciliationStatus'] = 'pending';
        $items[$itemIndex]['quantityReconciliationNote'] = $reason;
        $items[$itemIndex]['quantityReconciliationUpdatedAt'] = $now;
    } elseif ($isFinal && $difference > 0) {
        $items[$itemIndex]['quantityReconciliationRequired'] = false;
        $items[$itemIndex]['quantityReconciliationStatus'] = 'resolved';
        $items[$itemIndex]['quantityReconciliationStatusLabel'] = '本次多到已確認';
        $items[$itemIndex]['quantityReconciliationNote'] = $reason;
        $items[$itemIndex]['quantityReconciliationUpdatedAt'] = $now;
        $items[$itemIndex]['quantityReconciliationResolvedAt'] = $now;
    }`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
    const page = fs.readFileSync(file, "latin1");
    if (page.indexOf("admin.js") === -1 && page.indexOf("admin.css") === -1) return;
    const next = page
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
    if (next === page) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "arrival-extra-split"));
let js = fs.readFileSync(JS, "utf8");
if (js.indexOf(JS_MARKER) === -1) {
  js = replaceOnce(js, ISSUE_OLD, ISSUE_HELPER, "workline issue helper");
  js = replaceOnce(js, ISSUE_FILTER_OLD, ISSUE_FILTER_NEW, "workline issue filter");
} else {
  console.log("js helper already");
}
js = replaceOnce(js, MISSING_OLD, MISSING_NEW, "batch missing ignores overage");
js = replaceOnce(js, EXTRA_RETURN_OLD, EXTRA_RETURN_NEW, "extra item supplemental flags");
js = replaceOnce(js, EXTRA_SAVE_OLD, EXTRA_SAVE_NEW, "extra save parent");
js = replaceOnce(js, OPEN_OLD, OPEN_NEW, "quantity modal pending only");
js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "quantity save skip stocked");
try {
  new Function(js);
  console.log("js syntax ok");
} catch (error) {
  throw new Error("admin.js syntax: " + error.message);
}
fs.writeFileSync(JS, js);
console.log("admin.js written", js.length);

if (fs.existsSync(PHP)) {
  console.log("backup php", backup(PHP, "arrival-extra-split"));
  let php = fs.readFileSync(PHP, "utf8");
  php = replaceOnce(php, PHP_ALLOW_OLD, PHP_ALLOW_NEW, "php allow extra on same tracking");
  php = replaceOnce(php, PHP_RECON_OLD, PHP_RECON_NEW, "php overage not open recon");
  fs.writeFileSync(PHP, php);
}

stampHtml(ROOT);

if (js.indexOf(JS_MARKER) === -1) throw new Error("workline helper missing");
if (js.indexOf("isSupplementalSplitReceipt: true") === -1) throw new Error("extra supplemental flag missing");
console.log("done", STAMP);
