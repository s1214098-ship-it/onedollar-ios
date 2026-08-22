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
  if (delivery === "in_transit" || status === "shipped" || status === "in_transit") return "transit";
  if (delivery === "arrived_store") return "arrived";
  if (fifoCustomerOrderIsHold(row)) return "hold";
  if (status === "confirming" || status === "draft" || status === "pending") return "draft";
  return "open";
}

function fifoCustomerOrderStatusLabel(row) {
  const key = fifoCustomerOrderStatusKey(row);
  if (key === "cancelled") return "已取消";
  if (key === "returned") return "已退回";
  if (key === "delivered") return "已完成配送";
  if (key === "hold") return "已寄庫";
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

function fifoCustomerOrderIsHold(row) {
  row = row || {};
  const reason = String(row.reservedShippingHoldReason || "").trim();
  if (reason && reason !== "scheduled_ship") return true;
  const note = [row.shippingNote, row.reservedShippingNote, row.reservedShippingHoldReasonText].join(" ");
  return /寄庫|等待直播|等直播|先不出貨/.test(note);
}

function fifoCustomerOrderCanMerge(row) {
  if (!row || row.cancelled === true) return false;
  const id = String(row.id || "").trim();
  if (!id) return false;
  const delivery = String(row.deliveryState || "").toLowerCase();
  if (["arrived_store", "in_transit", "delivered", "returned", "shipped"].indexOf(delivery) !== -1) return false;
  const key = fifoCustomerOrderStatusKey(row);
  if (["cancelled", "returned", "delivered", "transit", "arrived"].indexOf(key) !== -1) return false;
  const status = String(row.status || "").toLowerCase();
  if (
    ["shipped", "in_transit", "delivered", "completed", "returned", "cancelled", "canceled", "refunded", "closed"].indexOf(
      status
    ) !== -1
  ) {
    return false;
  }
  if (fifoCustomerOrderIsHold(row)) return false;
  return true;
}

function fifoCustomerOrderMergeIds(rows, selectedIds) {
  const ids = [];
  const seen = Object.create(null);
  (rows || []).forEach(function (row) {
    if (!fifoCustomerOrderCanMerge(row)) return;
    const id = String(row.id || "").trim();
    if (!id || seen[id]) return;
    seen[id] = true;
    ids.push(id);
  });
  if (!Array.isArray(selectedIds)) return ids;
  const wanted = Object.create(null);
  selectedIds.forEach(function (id) {
    const key = String(id || "").trim();
    if (key) wanted[key] = true;
  });
  return ids.filter(function (id) {
    return wanted[id];
  });
}

function fifoCustomerOrderMergeButtonLabel(count, mode) {
  const n = Math.max(0, Number(count || 0));
  if (mode === "idle") return n >= 2 ? "請勾選要合併的單" : "";
  if (n < 2) return n === 1 && mode === "picked" ? "再勾選至少 1 張才能合併" : "";
  if (mode === "picked") return "合併已勾選 " + n + " 張一起出";
  return "合併未出貨 " + n + " 張一起出";
}

function fifoCustomerOrderResolveMergeIds(rows, orders, selectedIds) {
  const orderIds = Object.create(null);
  (orders || []).forEach(function (order) {
    const id = String(order && order.id || "").trim();
    if (id) orderIds[id] = true;
  });
  const resolved = [];
  const skipped = [];
  const seen = Object.create(null);
  fifoCustomerOrderMergeIds(rows, selectedIds).forEach(function (id) {
    const row = (rows || []).find(function (item) {
      return item && String(item.id || "") === id;
    }) || {};
    const converted = String(row.convertedOrderId || row.convertedToOrderId || row.orderId || "").trim();
    if (orderIds[id]) {
      if (!seen[id]) {
        seen[id] = true;
        resolved.push(id);
      }
      return;
    }
    if (converted && orderIds[converted]) {
      if (!seen[converted]) {
        seen[converted] = true;
        resolved.push(converted);
      }
      return;
    }
    skipped.push(id);
  });
  return { resolved: resolved, skipped: skipped };
}

function fifoCustomerOrderMergeForbidden(rows) {
  return (rows || []).some(function (row) {
    const id = String((row && row.id) || "");
    const converted = String((row && (row.convertedOrderId || row.convertedToOrderId)) || "");
    const phone = fifoCustomerPhoneDigits(row);
    return id === "BYORDER-20260720-078596" || converted === "BYORDER-20260720-078596" || phone === "16346546308";
  });
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
  fifoCustomerOrderIsHold,
  fifoCustomerOrderCanMerge,
  fifoCustomerOrderMergeIds,
  fifoCustomerOrderMergeButtonLabel,
  fifoCustomerOrderResolveMergeIds,
  fifoCustomerOrderMergeForbidden,
};
