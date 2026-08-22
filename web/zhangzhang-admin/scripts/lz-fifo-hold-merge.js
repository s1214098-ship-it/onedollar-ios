"use strict";

function fifoHoldCustomerKey(row) {
  row = row || {};
  const customer = row.customer || {};
  const phone = String(customer.phone || row.customerPhone || row.phone || "").replace(/\D/g, "");
  if (phone.length >= 6) return "p:" + phone;
  const name = String(customer.name || row.customerName || row.name || "")
    .replace(/\s+/g, "")
    .toLowerCase();
  return name.length >= 2 ? "n:" + name : "";
}

function fifoHoldRowIsClosed(row) {
  const status = String((row && row.status) || "").toLowerCase();
  if ([
    "cancelled",
    "canceled",
    "shipped",
    "in_transit",
    "delivered",
    "completed",
    "returned",
    "converted",
  ].indexOf(status) !== -1) {
    return true;
  }
  if (String((row && row.convertedOrderId) || "").trim()) return true;
  if (String((row && row.convertedToOrderId) || "").trim()) return true;
  return false;
}

function fifoHoldRowIsActiveHold(row) {
  if (!row || fifoHoldRowIsClosed(row)) return false;
  const reason = String(row.reservedShippingHoldReason || "").trim();
  if (reason && reason !== "scheduled_ship") return true;
  const note = [
    row.shippingNote,
    row.reservedShippingNote,
    row.reservedShippingHoldReasonText,
  ].join(" ");
  return /寄庫|等待直播|等直播|先不出貨/.test(note);
}

function fifoHoldItemSummary(row) {
  const items = Array.isArray(row && row.items) ? row.items : [];
  if (!items.length) return "尚未填商品";
  return items
    .map(function (item) {
      item = item || {};
      const code = item.code || item.productCode || item.sku || item.title || "商品";
      const color = item.color || item.colorName || "";
      const size = item.size || item.sizeName || "";
      const qty = Math.max(0, Number(item.qty || item.quantity || 0)) || 1;
      return [code, color, size, "×" + qty].filter(Boolean).join(" ");
    })
    .join("、");
}

function fifoCollectCustomerHoldRows(orders, inquiries, target, excludeId) {
  const key = typeof target === "string" ? (target.indexOf(":") >= 0 ? target : "") : fifoHoldCustomerKey(target);
  const phone = typeof target === "string" && /^\d{6,}$/.test(target)
    ? target
    : String((target && ((target.customer && target.customer.phone) || target.customerPhone || target.phone)) || "").replace(/\D/g, "");
  const matchKey = key || (phone.length >= 6 ? "p:" + phone : "");
  if (!matchKey) return [];
  const skip = String(excludeId || (target && target.id) || "");
  const seen = Object.create(null);
  const rows = [];
  function add(row) {
    if (!row || !row.id) return;
    const id = String(row.id);
    if (seen[id]) return;
    if (skip && id === skip) return;
    if (!fifoHoldRowIsActiveHold(row)) return;
    if (fifoHoldCustomerKey(row) !== matchKey) return;
    seen[id] = true;
    rows.push(row);
  }
  (orders || []).forEach(add);
  (inquiries || []).forEach(add);
  return rows;
}

function fifoHoldGroupByCustomer(rows) {
  const groups = {};
  (rows || []).forEach(function (row) {
    const key = fifoHoldCustomerKey(row);
    if (!key) return;
    if (!groups[key]) groups[key] = [];
    groups[key].push(row);
  });
  return Object.keys(groups).map(function (key) {
    return { key: key, rows: groups[key] };
  }).filter(function (group) {
    return group.rows.length >= 2;
  });
}

function fifoHoldReminderMessage(siblings) {
  siblings = Array.isArray(siblings) ? siblings : [];
  if (!siblings.length) return "";
  const bits = siblings.map(function (row) {
    return String(row.id || "-") + "／" + fifoHoldItemSummary(row);
  });
  return "此客人已有 " + siblings.length + " 張寄庫單，打包時可合併一起出：" + bits.join("；");
}

function fifoShouldPopupHoldMerge(query, groups, lastQuery) {
  const q = String(query || "").trim();
  if (!q) return false;
  if (q === String(lastQuery || "").trim()) return false;
  return Array.isArray(groups) && groups.some(function (group) {
    return group && Array.isArray(group.rows) && group.rows.length >= 2;
  });
}

function fifoHoldMergeIds(row, siblings) {
  const ids = [];
  const seen = Object.create(null);
  function add(id) {
    id = String(id || "");
    if (!id || seen[id]) return;
    seen[id] = true;
    ids.push(id);
  }
  add(row && row.id);
  (siblings || []).forEach(function (sib) {
    add(sib && sib.id);
  });
  return ids;
}

function fifoHoldExpandVisibleWithSiblings(visibleRows, orders, inquiries) {
  const seen = Object.create(null);
  const rows = [];
  function add(row) {
    if (!row || !row.id) return;
    const id = String(row.id);
    if (seen[id]) return;
    seen[id] = true;
    rows.push(row);
  }
  (visibleRows || []).forEach(function (row) {
    add(row);
    fifoCollectCustomerHoldRows(orders, inquiries, row).forEach(add);
  });
  return rows;
}

function fifoHoldMergeButtonLabel(siblingCount) {
  const n = Math.max(0, Number(siblingCount || 0));
  if (n <= 0) return "";
  return "合併同客人 " + (n + 1) + " 張寄庫單一起出";
}

module.exports = {
  fifoHoldCustomerKey,
  fifoHoldRowIsClosed,
  fifoHoldRowIsActiveHold,
  fifoHoldItemSummary,
  fifoCollectCustomerHoldRows,
  fifoHoldGroupByCustomer,
  fifoHoldReminderMessage,
  fifoShouldPopupHoldMerge,
  fifoHoldMergeIds,
  fifoHoldExpandVisibleWithSiblings,
  fifoHoldMergeButtonLabel,
};
