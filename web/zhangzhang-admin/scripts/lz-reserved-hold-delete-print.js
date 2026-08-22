"use strict";

function reservedHoldWarehouseSummary(order) {
  order = order || {};
  const seen = Object.create(null);
  const items = Array.isArray(order.items) ? order.items : [];
  items.forEach(function (item) {
    if (!item) return;
    const label = String(
      item.sourceWarehouse
      || item.warehouseName
      || item.warehouse
      || item.sourceWarehouseCode
      || item.warehouseCode
      || ""
    ).trim();
    if (label) seen[label] = true;
  });
  const labels = Object.keys(seen);
  if (labels.length) return labels.join("、");
  return String(order.warehouse || order.warehouseCode || "原倉").trim() || "原倉";
}

function reservedHoldDeleteConfirmText(opts) {
  opts = opts || {};
  const name = String(opts.name || "未填姓名");
  const phone = String(opts.phone || "未填電話");
  const warehouse = String(opts.warehouse || "原倉");
  const items = String(opts.items || "商品");
  return (
    "確定刪除這張寄庫單，並把商品撥回原本倉庫？\n"
    + name + "／" + phone + "\n"
    + items + "\n"
    + "庫存回到：" + warehouse + "\n"
    + "訂單會刪除。若只要移出名單、訂單留著，請按「取消寄庫」。"
  );
}

function reservedHoldDeleteBlocksShipped(order) {
  order = order || {};
  const status = String(order.status || "").toLowerCase();
  const delivery = String(order.deliveryState || "").toLowerCase();
  return status === "shipped"
    || status === "in_transit"
    || delivery === "in_transit"
    || delivery === "arrived_store"
    || delivery === "delivered";
}

function reservedHoldDeleteUsesDeleteAction(js) {
  const src = String(js || "");
  return src.indexOf("data-reserved-hold-delete") !== -1
    && src.indexOf("function deleteReservedHoldAndRestock(") !== -1
    && src.indexOf("刪除寄庫並撥回倉庫") !== -1
    && src.indexOf("action: 'delete'") !== -1;
}

function reservedHoldPrintButtonInHtml(html) {
  return String(html || "").indexOf('data-reserved-print="hold"') !== -1
    && String(html || "").indexOf("列印寄庫清單給業務") !== -1;
}

function reservedHoldDoesNotRestyleActive(snippet) {
  return String(snippet || "").indexOf(".is-active") === -1;
}

module.exports = {
  reservedHoldWarehouseSummary,
  reservedHoldDeleteConfirmText,
  reservedHoldDeleteBlocksShipped,
  reservedHoldDeleteUsesDeleteAction,
  reservedHoldPrintButtonInHtml,
  reservedHoldDoesNotRestyleActive,
};
