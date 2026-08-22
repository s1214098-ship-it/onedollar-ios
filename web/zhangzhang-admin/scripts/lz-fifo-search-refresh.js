"use strict";

function fifoOrdersFetchPath(force, now) {
  const base = "./data/orders.json";
  if (!force) return base;
  return base + "?lz=" + String(now || Date.now());
}

function fifoSearchShouldForceRefresh(reason) {
  return reason === "submit" || reason === "after-save" || reason === "open-page";
}

function fifoForceRefreshClears(inflightOrders, inflightInquiries) {
  return {
    orders: null,
    inquiries: {},
    json: Object.keys(inflightOrders || {}).filter(function (key) {
      return !/orders\.json/i.test(String(key));
    }).reduce(function (next, key) {
      next[key] = inflightOrders[key];
      return next;
    }, {}),
  };
}

function fifoAssetPollShouldReload(next, currentV) {
  if (!next || !next.v) return false;
  if (next.auto === false) return false;
  return String(next.v) !== String(currentV || "");
}

module.exports = {
  fifoOrdersFetchPath,
  fifoSearchShouldForceRefresh,
  fifoForceRefreshClears,
  fifoAssetPollShouldReload,
};
