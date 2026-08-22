"use strict";

function fifoExactSkuId(item) {
  return String((item && (item.skuId || item.sku)) || "").trim().toUpperCase();
}

function fifoWarehouseIsTaiwan(value) {
  var code = String(value || "").trim().toUpperCase();
  return code === "TW" || code === "TAIWAN" || code.indexOf("台灣") !== -1 || code.indexOf("TAIWAN") !== -1;
}

function fifoItemIsTaiwanSource(item) {
  return fifoWarehouseIsTaiwan(
    item && (
      item.sourceWarehouseCode
      || item.sourceWarehouse
      || item.allocationSourceWarehouse
      || item.warehouseCode
      || item.warehouse
    )
  );
}

function fifoExactTaiwanSkuStockQty(item, skus) {
  var skuId = fifoExactSkuId(item);
  if (!skuId) return 0;
  var qty = 0;
  (skus || []).forEach(function (sku) {
    if (!sku) return;
    var id = String(sku.id || sku.sku || "").trim().toUpperCase();
    if (id !== skuId) return;
    if (!fifoWarehouseIsTaiwan(sku.warehouseCode || sku.warehouse || sku.warehouseName)) return;
    qty += Math.max(0, Number(sku.stock || 0));
  });
  return qty;
}

function fifoItemExactTaiwanSkuReady(item, skus) {
  if (!item) return false;
  if (!fifoItemIsTaiwanSource(item)) return false;
  var requested = Math.max(1, Number(item.requestedQty || item.qty || item.quantity || 1));
  return fifoExactTaiwanSkuStockQty(item, skus) >= requested;
}

function fifoRowExactTaiwanSkuReady(row, skus) {
  var items = Array.isArray(row && row.items) ? row.items : [];
  return items.length > 0 && items.every(function (item) {
    return fifoItemExactTaiwanSkuReady(item, skus);
  });
}

function fifoReadyQtyCountsExactSku(adminJs) {
  var src = String(adminJs || "");
  return src.indexOf("var exactSkuHit = !!(exactSkuId && thisSkuId && exactSkuId === thisSkuId)") !== -1
    && src.indexOf("if (exactSkuHit || (matches && preorderSupplyVariantMatches") !== -1;
}

function fifoConfirmRechecksTaiwanPriority(adminJs) {
  var src = String(adminJs || "");
  return src.indexOf("(!orderId && freightFifoTaiwanPriorityReady(confirmRow))") !== -1;
}

function fifoPreorderKeepSingleAddress(existing, shipping) {
  existing = existing || {};
  var customer = existing.customer || {};
  var hadStore = String(existing.storeAddress || customer.storeAddress || "").trim();
  var hadHome = String(existing.homeAddress || existing.address || customer.homeAddress || customer.address || "").trim();
  var next = {
    storeAddress: String((shipping && shipping.storeAddress) || "").trim(),
    homeAddress: String((shipping && shipping.homeAddress) || "").trim(),
    addressPrimary: String((shipping && shipping.addressPrimary) || "").trim() || "home",
    address: String((shipping && shipping.address) || "").trim(),
    deliveryType: (shipping && shipping.deliveryType) || "",
    deliveryLabel: (shipping && shipping.deliveryLabel) || ""
  };
  if (!hadStore && next.addressPrimary !== "store") next.storeAddress = "";
  if (!hadHome && next.addressPrimary !== "home") next.homeAddress = "";
  if (next.addressPrimary === "store") next.address = next.storeAddress;
  else next.address = next.homeAddress;
  return next;
}

function fifoPreorderKeepsSingleAddress(adminJs) {
  var src = String(adminJs || "");
  return src.indexOf("fifoPreorderKeepSingleAddress(") !== -1
    || src.indexOf("hadStore && shipping.addressPrimary !== 'store'") !== -1;
}

function fifoPhpConvertUsesExactSku(php) {
  var src = String(php || "");
  return src.indexOf("$useExactLineSku = is_array($baseSku)") !== -1
    && src.indexOf("$useExactLineSku && ($directStockAllocation") !== -1;
}

module.exports = {
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
};
