#!/usr/bin/env node
"use strict";

/**
 * 預購轉正式：行上已經是台灣倉 SKU 且該 SKU 有現貨時，不要因顏色標籤不一致擋住出貨。
 * 確認送出時再核對一次台灣現貨。預購單沒有的第二地址不要建。
 * 不 stamp HTML。
 */

const fs = require("fs");
const path = require("path");
const {
  fifoReadyQtyCountsExactSku,
  fifoConfirmRechecksTaiwanPriority,
  fifoPreorderKeepsSingleAddress,
  fifoPhpConvertUsesExactSku,
} = require("./lz-fifo-tw-sku-ready");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "stock-inquiry-api.php");

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

const READY_OLD = `      var matches = identityList.some(function (identity) { return skuIdentities.has(identity); });
      if (matches && preorderSupplyVariantMatches(item, sku.colorName || sku.color, sku.sizeName || sku.size, sku.companyBarcode || sku.barcode || product.code || sku.productId)) {
        readyQty += Math.max(0, Number(sku.stock || 0));
      }`;

const READY_NEW = `      var exactSkuId = String(item && (item.skuId || item.sku) || '').trim().toUpperCase();
      var thisSkuId = String(sku.id || sku.sku || '').trim().toUpperCase();
      var exactSkuHit = !!(exactSkuId && thisSkuId && exactSkuId === thisSkuId);
      var matches = identityList.some(function (identity) { return skuIdentities.has(identity); });
      if (exactSkuHit || (matches && preorderSupplyVariantMatches(item, sku.colorName || sku.color, sku.sizeName || sku.size, sku.companyBarcode || sku.barcode || product.code || sku.productId))) {
        readyQty += Math.max(0, Number(sku.stock || 0));
      }`;

const CONFIRM_OLD = `    var hasTaiwanStockAllocation = String(modal.getAttribute('data-taiwan-stock-allocation-ready') || '') === '1'
      || (!orderId && freightFifoRowTaiwanStockReady(confirmRow));`;

const CONFIRM_NEW = `    var hasTaiwanStockAllocation = String(modal.getAttribute('data-taiwan-stock-allocation-ready') || '') === '1'
      || (!orderId && freightFifoRowTaiwanStockReady(confirmRow))
      || (!orderId && freightFifoTaiwanPriorityReady(confirmRow));`;

const ADDRESS_OLD = `  function persistFreightFifoCustomerDetails(modal) {
    if (!modal) return Promise.reject(new Error('找不到客戶配送視窗'));
    var preorderId = modal.getAttribute('data-preorder-id') || '';
    var orderId = modal.getAttribute('data-order-id') || '';
    var name = String((modal.querySelector('[data-freight-fifo-name]') || {}).value || '').trim();
    var shipping = readCustomerShippingAddressFields(modal);
    var address = shipping.address;`;

const ADDRESS_NEW = `  function persistFreightFifoCustomerDetails(modal) {
    if (!modal) return Promise.reject(new Error('找不到客戶配送視窗'));
    var preorderId = modal.getAttribute('data-preorder-id') || '';
    var orderId = modal.getAttribute('data-order-id') || '';
    var name = String((modal.querySelector('[data-freight-fifo-name]') || {}).value || '').trim();
    var shipping = readCustomerShippingAddressFields(modal);
    if (preorderId) {
      var existingPreorderForAddress = (state.inquiries || []).find(function (row) { return String(row && row.id || '') === String(preorderId); }) || {};
      var existingCustomerForAddress = existingPreorderForAddress.customer || {};
      var hadStore = String(existingPreorderForAddress.storeAddress || existingCustomerForAddress.storeAddress || '').trim();
      var hadHome = String(existingPreorderForAddress.homeAddress || existingPreorderForAddress.address || existingCustomerForAddress.homeAddress || existingCustomerForAddress.address || '').trim();
      if (!hadStore && shipping.addressPrimary !== 'store') shipping.storeAddress = '';
      if (!hadHome && shipping.addressPrimary !== 'home') shipping.homeAddress = '';
      shipping.address = shipping.addressPrimary === 'store' ? shipping.storeAddress : shipping.homeAddress;
    }
    var address = shipping.address;`;

const PHP_OLD = `            if ($manualPhysicalAllocation && ($legacyManualPriorityItem || $itemHasManualPhysicalFlag) && is_array($baseSku) && receipt_sku_is_warehouse($baseSku, $itemProfile)) {
                $baseAvailableQty = max(0, (int)($remainingBySku[$originalSkuId] ?? 0));
                if ($originalSkuId !== '' && ($itemUsesCustomerReservation || $baseAvailableQty >= $wantedQty)) {`;

const PHP_NEW = `            $useExactLineSku = is_array($baseSku) && receipt_sku_is_warehouse($baseSku, $itemProfile) && $originalSkuId !== '';
            if ($useExactLineSku && ($directStockAllocation || ($manualPhysicalAllocation && ($legacyManualPriorityItem || $itemHasManualPhysicalFlag)))) {
                $baseAvailableQty = max(0, (int)($remainingBySku[$originalSkuId] ?? 0));
                if ($originalSkuId !== '' && ($itemUsesCustomerReservation || $baseAvailableQty >= $wantedQty)) {`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(ADMIN_JS, "fifo-tw-sku-ready"));
let js = fs.readFileSync(ADMIN_JS, "utf8");
if (fifoReadyQtyCountsExactSku(js) && fifoConfirmRechecksTaiwanPriority(js) && fifoPreorderKeepsSingleAddress(js)) {
  console.log("js already patched");
} else {
  if (!fifoReadyQtyCountsExactSku(js)) js = replaceOnce(js, READY_OLD, READY_NEW, "exact TW sku counts as ready stock");
  if (!fifoConfirmRechecksTaiwanPriority(js)) js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "confirm rechecks taiwan priority");
  if (!fifoPreorderKeepsSingleAddress(js)) js = replaceOnce(js, ADDRESS_OLD, ADDRESS_NEW, "preorder does not create second address");
}
if (!fifoReadyQtyCountsExactSku(js)) throw new Error("exact sku readyQty missing");
if (!fifoConfirmRechecksTaiwanPriority(js)) throw new Error("confirm taiwan priority missing");
if (!fifoPreorderKeepsSingleAddress(js)) throw new Error("preorder single address missing");
fs.writeFileSync(ADMIN_JS, js, "utf8");
console.log("js written", ADMIN_JS, "len", js.length);

if (fs.existsSync(PHP)) {
  console.log("backup php", backup(PHP, "fifo-tw-sku-ready"));
  let php = fs.readFileSync(PHP, "utf8");
  if (fifoPhpConvertUsesExactSku(php)) {
    console.log("php already patched");
  } else {
    php = replaceOnce(php, PHP_OLD, PHP_NEW, "convert deducts exact TW sku");
  }
  if (!fifoPhpConvertUsesExactSku(php)) throw new Error("php exact sku convert missing");
  fs.writeFileSync(PHP, php, "utf8");
  console.log("php written", PHP, "len", php.length);
}

console.log("LINGZANZAN fifo tw sku ready ok");
console.log("no html stamp");
