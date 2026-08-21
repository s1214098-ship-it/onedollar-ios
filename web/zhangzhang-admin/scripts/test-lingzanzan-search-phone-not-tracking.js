#!/usr/bin/env node
"use strict";

/**
 * Local rules for: complete phone must not hit tracking last-4, other phones, or order ids.
 */

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
    var key = freightPhoneKey(value);
    return !!key && key === q;
  });
}

function freightWorkbenchDigitSearchKeys(keyword) {
  var suffix = String(keyword || "").replace(/\D/g, "");
  if (suffix.length < 4) return [];
  var keys = [suffix];
  if (freightQueryLooksLikePhone(suffix)) {
    if (suffix.charAt(0) === "0") keys.push(suffix.slice(1));
    else keys.push("0" + suffix);
  } else if (suffix.length > 4) {
    keys.push(suffix.slice(-4));
    keys.push(suffix.slice(0, 4));
    keys.push(suffix.slice(0, suffix.length - 1));
  }
  var unique = [];
  keys.forEach(function (key) {
    var min = freightQueryLooksLikePhone(suffix) ? 9 : 4;
    if (key.length >= min && unique.indexOf(key) === -1) unique.push(key);
  });
  return unique;
}

function freightDigitsHayMatch(hay, raw) {
  var keys = freightWorkbenchDigitSearchKeys(raw);
  var digits = String(hay || "").replace(/\D/g, "");
  var q = String(raw || "").replace(/\D/g, "");
  if (!digits) return false;
  if (freightQueryLooksLikePhone(q)) {
    return freightPhoneKey(digits) === freightPhoneKey(q);
  }
  if (!keys.length) return q.length >= 4 && digits.indexOf(q) !== -1;
  return keys.some(function (key) {
    return key.length >= 4 && digits.indexOf(key) !== -1;
  });
}

function fifoDigitMatch(row, raw) {
  var phoneDigits = String(raw || "").replace(/\D/g, "");
  if (!(phoneDigits.length >= 4 && phoneDigits === String(raw || "").replace(/[\s\-()+]/g, ""))) {
    return "keyword";
  }
  if (freightRowPhonesExact(row, phoneDigits)) return true;
  if (freightQueryLooksLikePhone(phoneDigits)) return false;
  var rowPhone = freightRowPhoneFields(row).join(" ").replace(/\D/g, "");
  if (rowPhone && (rowPhone.indexOf(phoneDigits) !== -1 || rowPhone.slice(-phoneDigits.length) === phoneDigits)) {
    return true;
  }
  return freightDigitsHayMatch(
    [row.trackingNo, row.supplierTrackingNo, row.freightTrackingNo, row.shippingTrackingNo].join(" "),
    phoneDigits
  );
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

const alongkong = {
  id: "BYORDER-20260731-133E1D",
  trackingNo: "E16759034042",
  customer: { phone: "0987147505", name: "Alongkong" },
};
const shofi = {
  id: "BYORDER-NONE",
  trackingNo: "",
  customer: { phone: "0909364042", name: "Shofi" },
};
const shofiNoZero = {
  id: "BYORDER-NONE2",
  trackingNo: "E11111111111",
  customer: { phone: "909364042", name: "Shofi2" },
};

assert(freightQueryLooksLikePhone("0909364042"), "tw mobile is phone");
assert(freightQueryLooksLikePhone("909364042"), "tw mobile without 0 is phone");
assert(!freightQueryLooksLikePhone("16759034042"), "7-11 digits are not a phone");
assert(fifoDigitMatch(alongkong, "0909364042") === false, "Shofi phone must not hit Alongkong tracking 4042");
assert(fifoDigitMatch(alongkong, "0987147505") === true, "Alongkong exact phone still hits");
assert(fifoDigitMatch(shofi, "0909364042") === true, "exact phone hits");
assert(fifoDigitMatch(shofi, "909364042") === true, "phone without leading 0 still hits");
assert(fifoDigitMatch(shofiNoZero, "0909364042") === true, "stored without 0 still hits");
assert(fifoDigitMatch(alongkong, "4042") === true, "short suffix may still hit tracking");
assert(
  freightDigitsHayMatch(alongkong.id + " " + alongkong.trackingNo, "0909364042") === false,
  "hay must not match order id or tracking last-4"
);
assert(
  freightWorkbenchDigitSearchKeys("0909364042").indexOf("4042") === -1,
  "complete phone keys must not include last-4"
);
assert(
  String(alongkong.id).toUpperCase().indexOf("0909364042") === -1,
  "sanity: phone is not a substring of this order id"
);

function trackingPhoneMatch(entry, query) {
  if (!freightQueryLooksLikePhone(query)) return "not-phone";
  return freightRowPhonesExact(entry.record || {}, query) || freightRowPhonesExact(entry.order || {}, query);
}

const alongkongEntry = {
  record: alongkong,
  order: alongkong,
};
const shofiEntry = {
  record: shofi,
  order: shofi,
};
assert(trackingPhoneMatch(alongkongEntry, "0909364042") === false, "tracking page must not hit Alongkong for Shofi phone");
assert(trackingPhoneMatch(shofiEntry, "0909364042") === true, "tracking page hits exact phone");
assert(trackingPhoneMatch(alongkongEntry, "0987147505") === true, "tracking page hits Alongkong own phone");

console.log("search-phone rules ok");
