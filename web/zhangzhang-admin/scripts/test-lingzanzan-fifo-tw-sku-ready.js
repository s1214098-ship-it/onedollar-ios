#!/usr/bin/env node
"use strict";

const {
  fifoExactSkuId,
  fifoWarehouseIsTaiwan,
  fifoItemIsTaiwanSource,
  fifoExactTaiwanSkuStockQty,
  fifoItemExactTaiwanSkuReady,
  fifoRowExactTaiwanSkuReady,
  fifoReadyQtyCountsExactSku,
  fifoConfirmRechecksTaiwanPriority,
  fifoPreorderKeepSingleAddress,
  fifoPreorderKeepsSingleAddress,
  fifoPhpConvertUsesExactSku,
} = require("./lz-fifo-tw-sku-ready");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(fifoWarehouseIsTaiwan("TW") === true, "TW is taiwan");
assert(fifoWarehouseIsTaiwan("台灣倉") === true, "台灣倉 is taiwan");
assert(fifoWarehouseIsTaiwan("CN") === false, "CN is not taiwan");

const ameY = {
  skuId: "SY41-01-NO-SIZE",
  sku: "SY41-01-NO-SIZE",
  color: "卡其色(Dril)",
  size: "NO SIZE",
  qty: 1,
  sourceWarehouse: "台灣倉",
  sourceWarehouseCode: "TW",
};
const pi35 = {
  skuId: "PI35-02-XL",
  color: "紅色(MERAL)",
  size: "XL",
  qty: 1,
  sourceWarehouse: "台灣倉",
};
const k340 = {
  skuId: "K340-01-NO-SIZE",
  color: "棕色(cokelat)",
  size: "NO SIZE",
  qty: 1,
  sourceWarehouse: "台灣倉",
};
const skus = [
  { id: "SY41-01-NO-SIZE", sku: "SY41-01-NO-SIZE", color: "黑(無盒)", stock: 1, warehouse: "台灣倉", warehouseCode: "TW" },
  { id: "PI35-02-XL", stock: 1, warehouse: "台灣倉", warehouseCode: "TW" },
  { id: "K340-01-NO-SIZE", stock: 1, warehouse: "台灣倉", warehouseCode: "TW" },
];

assert(fifoExactSkuId(ameY) === "SY41-01-NO-SIZE", "exact sku id");
assert(fifoItemIsTaiwanSource(ameY) === true, "AMEY SY41 is already 台灣倉");
assert(fifoExactTaiwanSkuStockQty(ameY, skus) === 1, "counts exact TW sku even when color label differs");
assert(fifoItemExactTaiwanSkuReady(ameY, skus) === true, "SY41 卡其 line is ready via exact sku");
assert(fifoRowExactTaiwanSkuReady({ items: [ameY, pi35, k340] }, skus) === true, "AMEY three TW lines are ready");
assert(fifoItemExactTaiwanSkuReady(ameY, [{ id: "SY41-01-NO-SIZE", stock: 1, warehouse: "中國倉", warehouseCode: "CN" }]) === false, "CN sku is not taiwan ready");
assert(fifoItemExactTaiwanSkuReady(ameY, [{ id: "SY41-01-NO-SIZE", stock: 0, warehouse: "台灣倉", warehouseCode: "TW" }]) === false, "zero stock is not ready");
assert(fifoItemExactTaiwanSkuReady({ skuId: "SY41-01-NO-SIZE", qty: 1, sourceWarehouse: "預購倉" }, skus) === false, "preorder warehouse still needs 用台灣現貨");

const patchedJs = [
  "var exactSkuId = String(item && (item.skuId || item.sku) || '').trim().toUpperCase();",
  "var thisSkuId = String(sku.id || sku.sku || '').trim().toUpperCase();",
  "var exactSkuHit = !!(exactSkuId && thisSkuId && exactSkuId === thisSkuId);",
  "if (exactSkuHit || (matches && preorderSupplyVariantMatches(item, sku.colorName || sku.color, sku.sizeName || sku.size, sku.companyBarcode || sku.barcode || product.code || sku.productId))) {",
  "|| (!orderId && freightFifoTaiwanPriorityReady(confirmRow));",
  "if (!hadStore && shipping.addressPrimary !== 'store') shipping.storeAddress = '';",
].join("\n");
assert(fifoReadyQtyCountsExactSku(patchedJs) === true, "detects exact-sku readyQty patch");
assert(fifoReadyQtyCountsExactSku("if (matches && preorderSupplyVariantMatches") === false, "old color-only match is not the patch");
assert(fifoConfirmRechecksTaiwanPriority(patchedJs) === true, "confirm rechecks taiwan priority");
assert(fifoPreorderKeepsSingleAddress(patchedJs) === true, "detects preorder single-address keep");
assert(fifoPhpConvertUsesExactSku("$useExactLineSku = is_array($baseSku);\nif ($useExactLineSku && ($directStockAllocation || $manualPhysicalAllocation)) {") === true, "detects PHP exact-sku convert");
assert(fifoPhpConvertUsesExactSku("if ($manualPhysicalAllocation && ($legacyManualPriorityItem") === false, "old manual-only convert is not the patch");

const kept = fifoPreorderKeepSingleAddress(
  { homeAddress: "屏東縣竹田鄉鳳明村鳳平路2巷9號(HCT)", storeAddress: "" },
  { storeAddress: "7-11 德隆店(274403)", homeAddress: "屏東縣竹田鄉鳳明村鳳平路2巷9號(HCT)", addressPrimary: "home" }
);
assert(kept.storeAddress === "", "preorder home shipment does not create a second store address");
assert(kept.homeAddress.indexOf("竹田") !== -1, "keeps existing home address");

const switched = fifoPreorderKeepSingleAddress(
  { homeAddress: "屏東縣竹田鄉鳳明村鳳平路2巷9號(HCT)", storeAddress: "" },
  { storeAddress: "7-11 德隆店(274403)", homeAddress: "屏東縣竹田鄉鳳明村鳳平路2巷9號(HCT)", addressPrimary: "store" }
);
assert(switched.storeAddress.indexOf("7-11") !== -1, "choosing 超商 as primary still writes that address");
assert(switched.address.indexOf("7-11") !== -1, "primary store uses store as shipping address");

console.log("LINGZANZAN fifo tw sku ready tests ok");
