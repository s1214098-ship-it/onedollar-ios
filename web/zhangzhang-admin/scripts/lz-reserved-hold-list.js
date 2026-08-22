"use strict";

function reservedShippingQueryWantsHold(query) {
  return /寄庫|已寄庫|先不出貨/.test(String(query || ""));
}

function reservedShippingHoldSearchHay(order) {
  order = order || {};
  const reason = String(order.reservedShippingHoldReason || "").trim();
  if (reason && reason !== "scheduled_ship") return "寄庫 已寄庫 先不出貨 hold";
  return "";
}

function reservedShippingMatchesHoldQuery(order, query, opts) {
  opts = opts || {};
  query = String(query || "").trim().toLowerCase();
  if (!query) return true;
  const lane = String(opts.lane || "");
  const wantsHold = reservedShippingQueryWantsHold(query);
  if (wantsHold && lane !== "hold") return false;
  const phones = Array.isArray(opts.phones) ? opts.phones : [];
  if (phones.length) return opts.phoneHit === true;
  const rest = query.replace(/寄庫|已寄庫|先不出貨/g, " ").replace(/\s+/g, " ").trim();
  if (wantsHold && !rest) return lane === "hold";
  return opts.textHit === true;
}

function reservedShippingDateFilterKeepsHold(filter, lane) {
  if (lane !== "hold") return null;
  if (filter === "schedule") return false;
  return true;
}

function reservedShippingHoldListHtmlHasMount(html) {
  return String(html || "").indexOf("data-reserved-hold-list") !== -1
    && String(html || "").indexOf("寄庫名單") !== -1;
}

function reservedShippingHoldQuickHasButton(html) {
  return String(html || "").indexOf('data-reserved-quick="hold"') !== -1;
}

function reservedShippingHoldJsHasSearchAlias(adminJs) {
  const js = String(adminJs || "");
  return js.indexOf("reservedShippingQueryWantsHold") !== -1
    && js.indexOf("寄庫 已寄庫 先不出貨 hold") !== -1
    && js.indexOf("entry.lane === 'hold'") !== -1;
}

function reservedShippingHoldDoesNotRestyleActive(snippet) {
  return String(snippet || "").indexOf(".is-active") === -1;
}

module.exports = {
  reservedShippingQueryWantsHold,
  reservedShippingHoldSearchHay,
  reservedShippingMatchesHoldQuery,
  reservedShippingDateFilterKeepsHold,
  reservedShippingHoldListHtmlHasMount,
  reservedShippingHoldQuickHasButton,
  reservedShippingHoldJsHasSearchAlias,
  reservedShippingHoldDoesNotRestyleActive,
};
