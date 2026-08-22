"use strict";

function fifoCustomerPhoneDigits(row) {
  row = row || {};
  const customer = row.customer || {};
  return String(customer.phone || row.customerPhone || row.phone || "").replace(/\D/g, "");
}

function fifoCustomerOrderStatusKey(row) {
  row = row || {};
  const status = String(row.status || "").toLowerCase();
  const delivery = String(row.deliveryState || "").toLowerCase();
  if (status === "cancelled" || status === "canceled") return "cancelled";
  if (status === "returned" || delivery === "returned") return "returned";
  if (status === "delivered" || status === "completed" || delivery === "delivered") return "delivered";
  if (status === "confirming" || status === "draft" || status === "pending") return "draft";
  if (delivery === "in_transit" || status === "shipped" || status === "in_transit") return "transit";
  if (delivery === "arrived_store") return "arrived";
  return "open";
}

function fifoCustomerOrderStatusLabel(row) {
  const key = fifoCustomerOrderStatusKey(row);
  if (key === "cancelled") return "已取消";
  if (key === "returned") return "已退回";
  if (key === "delivered") return "已完成配送";
  if (key === "draft") return "已打單／接單確認中";
  if (key === "transit") return "配送中";
  if (key === "arrived") return "已到店";
  return String(row && (row.statusLabel || row.status) || "出貨單");
}

function fifoCustomerOrderItemLine(item) {
  item = item || {};
  const code = item.code || item.productCode || item.sku || "-";
  const title = item.title || item.productName || item.name || "";
  const color = item.color || item.colorName || "未填色";
  const size = item.size || item.sizeName || "均碼";
  const qty = Math.max(0, Number(item.qty || item.quantity || 0));
  return [code, title, "色 " + color, "尺碼 " + size, "×" + qty].filter(Boolean).join("／");
}

function fifoCustomerOrderOpenKind(row, orders) {
  const id = String(row && row.id || "");
  if (!id) return "inquiry";
  if (Array.isArray(orders) && orders.some((order) => String(order && order.id || "") === id)) return "order";
  if (String(row && row.source || "") === "live-order") return "order";
  if (/^LIVE-/i.test(id)) return "order";
  return "inquiry";
}

function fifoWorkbenchWhyHidden(row) {
  row = row || {};
  const id = String(row.id || "");
  const status = String(row.status || "").toLowerCase();
  const source = String(row.source || "");
  const hasInquiryLink = !!(row.sourceInquiryId || row.convertedFromInquiryId || row.inquiryId);
  if ((source === "live-order" || /^LIVE-/i.test(id)) && !hasInquiryLink) {
    return "live-draft-not-in-fifo-buckets";
  }
  if (status === "cancelled" && /^BYORDER-/i.test(id)) {
    return "cancelled-inquiry-not-in-order-returned";
  }
  if (status === "confirming" || status === "draft") {
    return "draft-not-ready-to-pick";
  }
  return "";
}

function fifoCollectCustomerOrders(orders, inquiries, matchesSearch) {
  const seen = Object.create(null);
  const rows = [];
  function add(row) {
    if (!row || !row.id) return;
    const id = String(row.id);
    if (seen[id]) return;
    if (typeof matchesSearch === "function" && !matchesSearch(row)) return;
    seen[id] = true;
    rows.push(row);
  }
  (orders || []).forEach(add);
  (inquiries || []).forEach(add);
  return rows;
}

function fifoShouldAutoOpenCustomerOrders(query, rows, lastQuery) {
  const q = String(query || "").trim();
  if (!q || !Array.isArray(rows) || !rows.length) return false;
  return q !== String(lastQuery || "");
}

module.exports = {
  fifoCustomerPhoneDigits,
  fifoCustomerOrderStatusKey,
  fifoCustomerOrderStatusLabel,
  fifoCustomerOrderItemLine,
  fifoCustomerOrderOpenKind,
  fifoWorkbenchWhyHidden,
  fifoCollectCustomerOrders,
  fifoShouldAutoOpenCustomerOrders,
};
