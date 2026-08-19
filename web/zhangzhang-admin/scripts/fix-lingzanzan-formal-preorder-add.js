#!/usr/bin/env node
"use strict";

/**
 * 已轉正式現貨單的加品來源倉補回「預購倉／等待到貨」。
 * 選預購倉只加入等待，不扣台灣／印尼／中國倉實體庫存。
 *
 * Cache-bust: admin.js ?v=20260819-formal-preorder-add-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "order-admin-api-v6.php");
const STAMP = "20260819-formal-preorder-add-1";
const JS_MARKER = "預購倉／等待到貨</option><option value=\"TW\">台灣倉</option><option value=\"ID\">印尼倉</option>' + china;\n  }";
const PHP_MARKER = "function item_is_preorder_wait(array $item): bool";

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
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const OPTIONS_OLD = `  function freightFifoWarehouseSourceOptionsHtml(hasFormalOrder) {
    var china = canUseChinaWarehouseForTaiwanLiveOrder() ? '<option value="CN">中國倉</option>' : '';
    return hasFormalOrder
      ? '<option value="TW">台灣倉</option><option value="ID">印尼倉</option>' + china
      : '<option value="PREORDER">預購倉／等待到貨</option><option value="TW">台灣倉</option><option value="ID">印尼倉</option>' + china;
  }`;

const OPTIONS_NEW = `  function freightFifoWarehouseSourceOptionsHtml(hasFormalOrder) {
    var china = canUseChinaWarehouseForTaiwanLiveOrder() ? '<option value="CN">中國倉</option>' : '';
    return '<option value="PREORDER">預購倉／等待到貨</option><option value="TW">台灣倉</option><option value="ID">印尼倉</option>' + china;
  }`;

const EMPTY_OLD = `這個倉目前沒有符合且有庫存的正式 SKU。已轉正式單不能從預購倉加品。`;
const EMPTY_NEW = `這個倉目前沒有符合且有庫存的正式 SKU。可把來源倉改成「預購倉／等待到貨」加入等待商品。`;

const DESC1_OLD = `'這張已是正式現貨訂單，只能追加實際有庫存的台灣／中國／印尼倉商品。'`;
const DESC1_NEW = `'這張已是正式現貨訂單。實體倉追加會立刻扣庫存；來源選「預購倉／等待到貨」只加入等待，不扣庫存。'`;

const DESC2_OLD = `'這張已是正式現貨訂單，行政人員只能追加台灣倉或印尼倉現貨；中國倉轉台灣現貨單只有管理者可以操作。'`;
const DESC2_NEW = `'這張已是正式現貨訂單。行政可追加台灣倉、印尼倉現貨，或選「預購倉／等待到貨」加入等待；中國倉轉台灣現貨單只有管理者可以操作。'`;

const DESC3_OLD = `'這張已是正式現貨訂單，只能追加實際有庫存的台灣／中國／印尼倉商品；確認後立即扣正確倉庫庫存並重算訂單總額。合併後仍可直接加品、改價、刪品，不必先解除合併。'`;
const DESC3_NEW = `'這張已是正式現貨訂單。實體倉追加會立刻扣庫存並重算總額；來源選「預購倉／等待到貨」只加入等待，不扣庫存。合併後仍可直接加品、改價、刪品，不必先解除合併。'`;

const DESC4_OLD = `'這張已是正式現貨訂單，行政人員只能追加台灣倉或印尼倉現貨；中國倉轉台灣現貨單只有管理者可以操作。合併後仍可直接加品、改價、刪品，不必先解除合併。'`;
const DESC4_NEW = `'這張已是正式現貨訂單。行政可追加台灣倉、印尼倉現貨，或選「預購倉／等待到貨」加入等待；中國倉轉台灣現貨單只有管理者可以操作。合併後仍可直接加品、改價、刪品，不必先解除合併。'`;

const BLOCK_OLD = `      if ((order || formalOrderId) && source === 'PREORDER') {
        if (status) status.textContent = '尚未送出：正式現貨訂單只能追加已有實體庫存的商品。';
        toast('正式出貨單只能追加已有實體庫存的商品');
        return;
      }
      var sku = selected.sku || {};`;

const BLOCK_NEW = `      var sku = selected.sku || {};`;

const PHP_HELPER = `function item_is_preorder_wait(array $item): bool {
    $source = strtoupper(trim((string)(
        $item['allocationSourceWarehouse']
        ?? $item['sourceWarehouse']
        ?? $item['warehouseCode']
        ?? $item['warehouse']
        ?? ''
    )));
    if (in_array($source, ['PREORDER', 'PREORDER_WAREHOUSE'], true)) return true;
    if (strpos($source, '預購') !== false || strpos($source, '预购') !== false) return true;
    return !empty($item['waitingArrival']);
}

function consume_items(array $items, string $skusFile, string $stateFile): void {`;

const PHP_CONSUME_OLD = `    if ($usesChina) require_admin_for_china_taiwan_live_stock($adminSessionsFile, $stateFile);
    consume_items($items, $skusFile, $stateFile);
    $orders[$index]['inventoryDeducted'] = true;`;

const PHP_CONSUME_NEW = `    if ($usesChina) require_admin_for_china_taiwan_live_stock($adminSessionsFile, $stateFile);
    $stockItems = [];
    foreach ($items as &$item) {
        if (!is_array($item)) continue;
        if (item_is_preorder_wait($item)) {
            $item['allocationSourceWarehouse'] = 'PREORDER';
            $item['freightReceivedQty'] = 0;
            $item['waitingArrival'] = true;
            $item['manualPriorityAllocation'] = true;
            continue;
        }
        $stockItems[] = $item;
    }
    unset($item);
    if ($stockItems) {
        consume_items($stockItems, $skusFile, $stateFile);
        $orders[$index]['inventoryDeducted'] = true;
    }`;

const PHP_ADJUST_OLD = `    if (!empty($orders[$index]['inventoryDeducted'])) {
        $oldQty = max(0, (int)($oldItem['qty'] ?? $oldItem['quantity'] ?? 0));
        $newSku = trim((string)($newItem['skuId'] ?? $newItem['sku'] ?? ''));
        if ($replacement && $newSku !== $oldSku) {
            restock_items([$oldItem], $skusFile, $stateFile);
            consume_items([$newItem], $skusFile, $stateFile);
        } elseif ($qty > $oldQty) {
            $delta = $newItem;
            $delta['qty'] = $qty - $oldQty;
            $delta['quantity'] = $qty - $oldQty;
            consume_items([$delta], $skusFile, $stateFile);
        } elseif ($qty < $oldQty) {
            $delta = $oldItem;
            $delta['qty'] = $oldQty - $qty;
            $delta['quantity'] = $oldQty - $qty;
            restock_items([$delta], $skusFile, $stateFile);
        }
    }`;

const PHP_ADJUST_NEW = `    $newIsPreorder = $allocationSource === 'PREORDER' || item_is_preorder_wait($newItem) || ($replacement && item_is_preorder_wait($replacement));
    $oldWasPreorder = item_is_preorder_wait($oldItem);
    if ($newIsPreorder) {
        $newItem['allocationSourceWarehouse'] = 'PREORDER';
        $newItem['freightReceivedQty'] = 0;
        $newItem['waitingArrival'] = true;
        $newItem['manualPriorityAllocation'] = true;
    } elseif ($replacement && in_array($allocationSource, ['TW', 'CN', 'ID'], true)) {
        $newItem['allocationSourceWarehouse'] = $allocationSource;
        $newItem['freightReceivedWarehouse'] = $allocationSource;
        $newItem['freightReceivedQty'] = $qty;
        $newItem['waitingArrival'] = false;
    }
    $items[$itemIndex] = $newItem;
    if (!empty($orders[$index]['inventoryDeducted'])) {
        $oldQty = max(0, (int)($oldItem['qty'] ?? $oldItem['quantity'] ?? 0));
        $newSku = trim((string)($newItem['skuId'] ?? $newItem['sku'] ?? ''));
        if ($replacement && $newSku !== $oldSku) {
            if (!$oldWasPreorder) restock_items([$oldItem], $skusFile, $stateFile);
            if (!$newIsPreorder) consume_items([$newItem], $skusFile, $stateFile);
        } elseif ($qty > $oldQty) {
            if (!$newIsPreorder) {
                $delta = $newItem;
                $delta['qty'] = $qty - $oldQty;
                $delta['quantity'] = $qty - $oldQty;
                consume_items([$delta], $skusFile, $stateFile);
            }
        } elseif ($qty < $oldQty) {
            if (!$oldWasPreorder) {
                $delta = $oldItem;
                $delta['qty'] = $oldQty - $qty;
                $delta['quantity'] = $oldQty - $qty;
                restock_items([$delta], $skusFile, $stateFile);
            }
        }
    }`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/admin\.js/.test(html)) continue;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, `admin.js?v=${STAMP}`);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n++;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

function main() {
  console.log("backup js", backup(JS, "formal-preorder-add"));
  console.log("backup php", backup(PHP, "formal-preorder-add"));
  let js = fs.readFileSync(JS, "utf8");
  if (js.includes(JS_MARKER) && !js.includes("已轉正式單不能從預購倉加品")) {
    console.log("js already patched");
  } else {
    js = replaceOnce(js, OPTIONS_OLD, OPTIONS_NEW, "warehouse options include PREORDER");
    js = replaceOnce(js, EMPTY_OLD, EMPTY_NEW, "empty-state hint");
    js = replaceOnce(js, DESC1_OLD, DESC1_NEW, "list-add china description");
    js = replaceOnce(js, DESC2_OLD, DESC2_NEW, "list-add staff description");
    js = replaceOnce(js, DESC3_OLD, DESC3_NEW, "workbench china description");
    js = replaceOnce(js, DESC4_OLD, DESC4_NEW, "workbench staff description");
    js = replaceOnce(js, BLOCK_OLD, BLOCK_NEW, "remove formal PREORDER submit block");
    fs.writeFileSync(JS, js);
    console.log("js written", js.length);
    try {
      new Function(js);
      console.log("js syntax ok");
    } catch (error) {
      throw new Error("admin.js syntax: " + error.message);
    }
  }

  let php = fs.readFileSync(PHP, "utf8");
  if (php.includes(PHP_MARKER)) {
    console.log("php already patched");
  } else {
    php = replaceOnce(
      php,
      "function consume_items(array $items, string $skusFile, string $stateFile): void {",
      PHP_HELPER,
      "php preorder wait helper"
    );
    php = replaceOnce(php, PHP_CONSUME_OLD, PHP_CONSUME_NEW, "append-items skip consume for PREORDER");
    php = replaceOnce(php, PHP_ADJUST_OLD, PHP_ADJUST_NEW, "adjust-item skip consume for PREORDER");
    fs.writeFileSync(PHP, php);
    console.log("php written", php.length);
  }
  stampHtml(ROOT);
}

main();
