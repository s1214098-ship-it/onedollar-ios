#!/usr/bin/env node
"use strict";

const {
  fifoOrdersFetchPath,
  fifoSearchShouldForceRefresh,
  fifoForceRefreshClears,
  fifoAssetPollShouldReload,
} = require("./lz-fifo-search-refresh");

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

assert(fifoOrdersFetchPath(false) === "./data/orders.json", "normal load keeps cacheable path");
assert(fifoOrdersFetchPath(true, 1720000000000) === "./data/orders.json?lz=1720000000000", "search busts IIS 304");
assert(fifoSearchShouldForceRefresh("submit") === true, "search submit refetches");
assert(fifoSearchShouldForceRefresh("render") === false, "plain rerender does not refetch");

const cleared = fifoForceRefreshClears(
  { "./data/orders.json": Promise.resolve([]), "./data/products.json": 1 },
  { "admin::": 1 }
);
assert(cleared.orders === null, "force drops in-flight orders refresh");
assert(!cleared.json["./data/orders.json"], "force drops coalesced orders.json");
assert(cleared.json["./data/products.json"] === 1, "other json stays");

assert(fifoAssetPollShouldReload({ v: "100" }, "100") === false, "same version does not reload");
assert(fifoAssetPollShouldReload({ v: "200" }, "100") === true, "old auto boot would reload on new v");
assert(fifoAssetPollShouldReload({ v: "200", auto: false }, "100") === false, "auto false never reloads");
assert(fifoAssetPollShouldReload({ ok: true }, "100") === false, "poll without v never reloads");
assert(fifoAssetPollShouldReload({ v: "" }, "100") === false, "empty v never reloads");

console.log("LINGZANZAN fifo search refresh tests ok");
