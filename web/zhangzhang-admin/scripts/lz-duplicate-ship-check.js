"use strict";

/**
 * Shared matching + penalty-once rules for duplicate same-order checks.
 * Keep this in sync with lingzanzan-pages/duplicate-ship-check.php.
 */

function duplicatePhoneKey(value) {
  var phone = String(value || "").replace(/\D/g, "");
  if (phone.length >= 11 && phone.indexOf("886") === 0) phone = "0" + phone.slice(3);
  return phone.length >= 8 ? phone.slice(-9) : "";
}

function duplicateCustomerKey(order) {
  order = order || {};
  var customer = order.customer || {};
  var recordId = String(
    customer.customerRecordId || customer.customerId || order.customerRecordId || order.customerId || ""
  ).trim();
  if (recordId) return "id:" + recordId;
  var phone = duplicatePhoneKey(customer.phone || order.customerPhone || order.phone);
  if (phone) return "p:" + phone;
  var name = String(customer.name || order.customerName || order.name || "")
    .replace(/\s+/g, "")
    .toLowerCase();
  return name.length >= 2 ? "n:" + name : "";
}

function duplicateProductKey(item) {
  item = item || {};
  var code = String(
    item.code || item.productCode || item.productId || item.sku || item.skuId || item.title || ""
  )
    .trim()
    .toUpperCase()
    .replace(/\s+/g, "");
  if (!code || code === "-" || code === "未選商品") return "";
  return (
    code +
    "|" +
    String(item.color || item.colorName || "").trim().toLowerCase() +
    "|" +
    String(item.size || item.sizeName || "").trim().toUpperCase()
  );
}

function duplicateProductKeySet(row) {
  var keys = {};
  (Array.isArray(row && row.items) ? row.items : []).forEach(function (item) {
    var key = duplicateProductKey(item);
    if (key) keys[key] = item;
  });
  return keys;
}

function duplicateLinkedIdMap(row) {
  var ids = {};
  if (!row) return ids;
  [
    row.id,
    row.orderId,
    row.convertedToOrderId,
    row.convertedOrderId,
    row.sourceInquiryId,
    row.inquiryId,
    row.preorderId,
    row.formalOrderId,
  ].forEach(function (value) {
    value = String(value || "").trim();
    if (value) ids[value] = true;
  });
  return ids;
}

function duplicateShipKind(order) {
  var status = String(order && (order.status || order.stage) || "").toLowerCase();
  if (["cancelled", "canceled", "deleted", "void", "refunded", "closed"].indexOf(status) !== -1) {
    return "";
  }
  var delivery = String(order && order.deliveryState || "").toLowerCase();
  if (delivery === "returned" || status === "returned") return "returned";
  if (["delivered", "completed", "shipped", "in_transit"].indexOf(status) !== -1) return "shipped";
  if (["in_transit", "arrived_store", "delivered", "shipped"].indexOf(delivery) !== -1) return "shipped";
  if (order && (order.shippedAt || order.trackingNo || order.shippingTrackingNo || order.deliveredAt)) {
    return "shipped";
  }
  return "open";
}

function duplicateKindLabel(kind) {
  return (
    {
      shipped: "已出貨／配送中或已取件",
      returned: "出過但已退回",
      open: "未出貨，同一商品還有一張",
      closed: "已結束",
    }[kind] || "請比對"
  );
}

function taipeiDayKey(value) {
  var raw = String(value || "").trim();
  if (/^\d{4}-\d{2}-\d{2}/.test(raw)) return raw.slice(0, 10);
  var dt = raw ? new Date(raw) : null;
  if (!dt || isNaN(dt.getTime())) return "";
  try {
    return dt.toLocaleDateString("en-CA", { timeZone: "Asia/Taipei" });
  } catch (error) {
    return dt.toISOString().slice(0, 10);
  }
}

function dateKeyAddDays(key, days) {
  var parts = String(key || "").slice(0, 10).split("-");
  if (parts.length < 3) return "";
  var dt = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]) + Number(days || 0)));
  return dt.toISOString().slice(0, 10);
}

function withinLastMonth(key, today) {
  today = today || taipeiDayKey(new Date().toISOString());
  var cutoff = dateKeyAddDays(today, -31);
  key = String(key || "").slice(0, 10);
  return !!key && !!cutoff && key >= cutoff && key <= today;
}

function recordDateKey(order) {
  return taipeiDayKey(
    order &&
      (order.shippedAt ||
        order.deliveredAt ||
        order.pickedUpAt ||
        order.shippingDate ||
        order.orderDate ||
        order.createdAt ||
        order.updatedAt ||
        "")
  );
}

function findDuplicateMatches(source, orders, inquiries, today) {
  if (!source) return [];
  var customerKey = duplicateCustomerKey(source);
  var productKeys = duplicateProductKeySet(source);
  if (!customerKey || !Object.keys(productKeys).length) return [];
  var skip = duplicateLinkedIdMap(source);
  var rows = [];
  function consider(order, sourceType) {
    if (!order || skip[String(order.id || "")]) return;
    if (duplicateCustomerKey(order) !== customerKey) return;
    var kind = duplicateShipKind(order);
    if (!kind || kind === "closed") return;
    var dateKey = recordDateKey(order);
    if (!withinLastMonth(dateKey, today)) return;
    var overlap = [];
    (Array.isArray(order.items) ? order.items : []).forEach(function (item) {
      var key = duplicateProductKey(item);
      if (key && productKeys[key]) overlap.push(item);
    });
    if (!overlap.length) return;
    skip[String(order.id || "")] = true;
    rows.push({
      id: String(order.id || ""),
      sourceType: sourceType,
      kind: kind,
      dateKey: dateKey,
      name: (order.customer && order.customer.name) || order.customerName || order.name || "-",
      phone: (order.customer && order.customer.phone) || order.customerPhone || order.phone || "-",
      tracking: order.trackingNo || order.shippingTrackingNo || "",
      carrier: order.shippingCarrier || order.carrier || "",
      delivery: order.deliveryState || order.status || kind,
      overlap: overlap,
    });
  }
  (orders || []).forEach(function (order) {
    consider(order, "order");
  });
  (inquiries || []).forEach(function (row) {
    consider(row, "inquiry");
  });
  rows.sort(function (a, b) {
    return String(b.dateKey).localeCompare(String(a.dateKey));
  });
  return rows.slice(0, 10);
}

function isShippedOrder(order) {
  return duplicateShipKind(order) === "shipped";
}

function orderHasDuplicateAck(order, acks, nowMs) {
  order = order || {};
  if (String(order.duplicateCheckAckAt || "").trim()) return true;
  if (String(order.duplicateCheckAckBy || "").trim()) return true;
  var orderId = String(order.id || "").trim();
  var customerKey = duplicateCustomerKey(order);
  var windowMs = 12 * 60 * 60 * 1000;
  nowMs = nowMs || Date.now();
  return (acks || []).some(function (row) {
    if (!row || row.status === "void") return false;
    if (orderId && String(row.orderId || "") === orderId) return true;
    if (!customerKey || String(row.customerKey || "") !== customerKey) return false;
    var at = Date.parse(String(row.ackedAt || ""));
    if (!at || Math.abs(nowMs - at) > windowMs) return false;
    return true;
  });
}

function orderAlreadyPenalized(order, errors) {
  order = order || {};
  if (String(order.duplicateNeglectPenaltyId || "").trim()) return true;
  var orderId = String(order.id || "").trim();
  var list = []
    .concat(Array.isArray(order.staffErrors) ? order.staffErrors : [])
    .concat(Array.isArray(errors) ? errors : []);
  return list.some(function (row) {
    if (!row) return false;
    if (String(row.penaltyKind || "") !== "duplicate_unacked") return false;
    if (orderId && String(row.orderId || "") && String(row.orderId || "") !== orderId) return false;
    return true;
  });
}

function shouldPenalizeDuplicateNeglect(order, matches, acks, errors, nowMs) {
  if (!isShippedOrder(order)) return false;
  if (!matches || !matches.length) return false;
  if (orderHasDuplicateAck(order, acks, nowMs)) return false;
  if (orderAlreadyPenalized(order, errors)) return false;
  return true;
}

function buildDuplicatePenalty(order, matches, staffName, nowIso) {
  var amount = 100;
  var points = 1;
  return {
    amountTwd: amount,
    scorePoints: points,
    countsTowardScore: true,
    penaltyKind: "duplicate_unacked",
    reason: "未核對重複相同訂單（系統查詢確認疏忽）",
    note: "同一客戶已出貨或未出貨的相同商品訂單未經確定核對。記 " + points + " 點，扣款 NT$" + amount + " 一次。",
    orderId: String(order && order.id || ""),
    customerName: (order && order.customer && order.customer.name) || order.customerName || order.name || "",
    customerPhone: (order && order.customer && order.customer.phone) || order.customerPhone || order.phone || "",
    trackingNo: (order && (order.trackingNo || order.shippingTrackingNo)) || "",
    matchIds: (matches || []).map(function (row) {
      return String(row.id || "");
    }).filter(Boolean),
    createdBy: staffName || "系統",
    createdAt: nowIso,
    status: "open",
  };
}

function duplicatePenaltyPrompt() {
  return "行政出貨人員必須先按「確定」核對。未核對者，若之後系統查詢確認疏忽，會記 1 點並扣款 NT$100（一次）。";
}

module.exports = {
  duplicatePhoneKey,
  duplicateCustomerKey,
  duplicateProductKey,
  duplicateProductKeySet,
  duplicateLinkedIdMap,
  duplicateShipKind,
  duplicateKindLabel,
  taipeiDayKey,
  dateKeyAddDays,
  withinLastMonth,
  recordDateKey,
  findDuplicateMatches,
  isShippedOrder,
  orderHasDuplicateAck,
  orderAlreadyPenalized,
  shouldPenalizeDuplicateNeglect,
  buildDuplicatePenalty,
  duplicatePenaltyPrompt,
};
