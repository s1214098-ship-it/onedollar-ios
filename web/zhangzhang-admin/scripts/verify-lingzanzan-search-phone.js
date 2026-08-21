#!/usr/bin/env node
"use strict";

const fs = require("fs");
const path = require("path");
const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const QUERY = process.argv[2] || "0909364042";

function readJson(file) {
  if (!fs.existsSync(file)) return null;
  return JSON.parse(fs.readFileSync(file, "utf8"));
}
function listOf(value, keys) {
  if (Array.isArray(value)) return value;
  if (!value || typeof value !== "object") return [];
  for (const key of keys) {
    if (Array.isArray(value[key])) return value[key];
  }
  return [];
}

function freightQueryLooksLikePhone(raw) {
  var digits = String(raw || "").replace(/\D/g, "");
  return /^(0?9\d{8}|0?8\d{8,12}|62\d{8,13})$/.test(digits);
}
function freightPhoneKey(raw) {
  var digits = String(raw || "").replace(/\D/g, "");
  if (digits.charAt(0) === "0") return digits.slice(1);
  return digits;
}
function freightRowPhoneFields(row) {
  row = row || {};
  var customer = row.customer || {};
  var member = customer.member && typeof customer.member === "object" ? customer.member : {};
  return [customer.phone, customer.customerPhone, member.phone, row.customerPhone, row.phone];
}
function freightRowPhonesExact(row, query) {
  var q = freightPhoneKey(query);
  if (!q) return false;
  return freightRowPhoneFields(row).some(function (value) {
    return freightPhoneKey(value) === q;
  });
}

const js = fs.readFileSync(path.join(ROOT, "assets", "admin.js"), "utf8");
const html = fs.readFileSync(path.join(ROOT, "admin-freight.html"), "latin1");
const stamp = (html.match(/admin\.js(?:\?v=[^"']+)?/) || [])[0];
console.log("stamp", stamp);
console.log("has phone helper", js.indexOf("function freightQueryLooksLikePhone(") !== -1);
console.log("fifo exact", js.indexOf("freightRowPhonesExact(row, phoneDigits)") !== -1);
console.log("fifo still matches order id on digit branch", /phoneDigits === raw[\s\S]{0,400}row\.id \|\| ''/.test(js));

const q = QUERY.replace(/\D/g, "");
const orders = listOf(readJson(path.join(ROOT, "data", "orders.json")), ["orders", "items"]);
const state = readJson(path.join(ROOT, "data", "admin-state.json")) || {};
const stateOrders = listOf(state.orders, ["orders"]);
const customers = listOf(readJson(path.join(ROOT, "data", "customers.json")), ["customers", "items"]);

function summarize(row, source) {
  const customer = row.customer || {};
  return {
    source,
    id: row.id,
    name: customer.name || row.customerName,
    phone: customer.phone || row.customerPhone || row.phone,
    tracking: row.trackingNo || "",
  };
}

const orderHits = []
  .concat(orders.map((row) => ({ row, source: "orders" })))
  .concat(stateOrders.map((row) => ({ row, source: "admin-state" })))
  .filter((item) => freightRowPhonesExact(item.row, q))
  .map((item) => summarize(item.row, item.source));

const customerHits = customers
  .filter((row) => freightRowPhonesExact(row, q) || freightPhoneKey(row.phone) === freightPhoneKey(q))
  .map((row) => ({
    source: "customers",
    id: row.id,
    name: row.name,
    phone: row.phone,
    address: row.address || "",
  }));

const alongkong = []
  .concat(orders, stateOrders)
  .filter((row) => String(row && row.id || "") === "BYORDER-20260731-133E1D");

console.log("query", q, "looksLikePhone", freightQueryLooksLikePhone(q));
console.log("exact order hits", orderHits.length, JSON.stringify(orderHits.slice(0, 5), null, 2));
console.log("customer hits", customerHits.length, JSON.stringify(customerHits.slice(0, 5), null, 2));
if (alongkong[0]) {
  console.log(
    "Alongkong exact phone?",
    freightRowPhonesExact(alongkong[0], q),
    alongkong[0].trackingNo,
    (alongkong[0].customer || {}).phone
  );
}
if (freightQueryLooksLikePhone(q) && orderHits.some((hit) => hit.id === "BYORDER-20260731-133E1D")) {
  throw new Error("0909364042 still resolves to Alongkong");
}
console.log("verify ok");
