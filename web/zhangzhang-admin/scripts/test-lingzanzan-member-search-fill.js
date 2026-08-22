#!/usr/bin/env node
"use strict";

function searchLooseNormalize(value) {
  return String(value || "").trim().toLowerCase().replace(/[\s\-()（）_+＋]/g, "");
}

function searchPhoneDigits(value) {
  return String(value || "").replace(/\D+/g, "");
}

function freightQueryLooksLikePhone(raw) {
  var digits = String(raw || "").replace(/\D/g, "");
  return /^(0?9\d{8}|0[2-8]\d{7,9}|0?8\d{8,12}|62\d{8,13})$/.test(digits);
}

function freightPhoneKey(raw) {
  var digits = String(raw || "").replace(/\D/g, "");
  if (digits.charAt(0) === "0") return digits.slice(1);
  return digits;
}

function freightExtractCompletePhones(raw) {
  var s = String(raw || "");
  var found = [];
  function add(value) {
    var digits = String(value || "").replace(/\D/g, "");
    if (freightQueryLooksLikePhone(digits) && found.indexOf(digits) === -1) found.push(digits);
  }
  add(s);
  (s.match(/\d+/g) || []).forEach(add);
  var joined = s.replace(/[\s\-()+]/g, "");
  if (/^\d+$/.test(joined)) add(joined);
  return found;
}

function memberSearchRank(item, query) {
  var raw = String(query || "").trim().toLowerCase();
  var compact = searchLooseNormalize(raw);
  var digits = searchPhoneDigits(raw);
  if (!raw && !compact && !digits) return 0;
  item = item || {};
  var name = searchLooseNormalize(item.name || "");
  var phone = searchPhoneDigits(item.phone || "");
  var address = searchLooseNormalize(item.address || "");
  var note = searchLooseNormalize(item.note || "");
  var delivery = searchLooseNormalize([item.deliveryLabel, item.deliveryType, item.customerTypeLabel].join(" "));
  var best = 9999;
  if (compact && name === compact) best = Math.min(best, 0);
  if (freightQueryLooksLikePhone(digits) || freightExtractCompletePhones(raw).length) {
    var wantPhone = freightPhoneKey(freightExtractCompletePhones(raw)[0] || digits);
    if (wantPhone && freightPhoneKey(phone) === wantPhone) best = Math.min(best, 0);
  } else {
    if (digits && phone === digits) best = Math.min(best, 0);
    if (compact && name.indexOf(compact) === 0) best = Math.min(best, 1);
    if (digits && phone.indexOf(digits) === 0) best = Math.min(best, 1);
    if (digits && digits.length >= 4 && phone.slice(-digits.length) === digits) best = Math.min(best, 2);
  }
  if (compact && compact.length > 1 && name.indexOf(compact) > 0) best = Math.min(best, 3);
  if (!freightQueryLooksLikePhone(digits) && !freightExtractCompletePhones(raw).length && digits && phone.indexOf(digits) > 0) {
    best = Math.min(best, 3);
  }
  if (compact && address.indexOf(compact) !== -1) best = Math.min(best, 4);
  if (compact && note.indexOf(compact) !== -1) best = Math.min(best, 5);
  if (compact && delivery.indexOf(compact) !== -1) best = Math.min(best, 6);
  return best;
}

function syncMemberSearchFromForm(searchValue, nameValue, phoneValue) {
  if (String(searchValue || "").trim()) return String(searchValue || "").trim();
  return String(nameValue || phoneValue || "").trim();
}

function rankedHits(members, query) {
  return members.map(function (item, index) {
    return { item: item, index: index, rank: memberSearchRank(item, query) };
  }).filter(function (entry) {
    return !query || entry.rank < 9999;
  }).sort(function (a, b) {
    if (a.rank !== b.rank) return a.rank - b.rank;
    return a.index - b.index;
  });
}

function assert(cond, label) {
  if (!cond) throw new Error("fail: " + label);
  console.log("ok:", label);
}

var members = [
  { name: "Anita Formoszza", phone: "0909545375", address: "7-11裕成南門市" },
  { name: "Aih/ahws LINE", phone: "0958446753", address: "尚無地址" },
  { name: "Aih(ahws LINE)", phone: "0958446753", address: "尚無地址" },
  { name: "Zoe", phone: "0912345678", address: "台北市" },
];

assert(syncMemberSearchFromForm("", "Aih(ahws LINE)", "") === "Aih(ahws LINE)", "empty search uses top name");
assert(syncMemberSearchFromForm("", "", "0958446753") === "0958446753", "empty search uses top phone");
assert(syncMemberSearchFromForm("Anita", "Aih(ahws LINE)", "") === "Anita", "typed search is kept");
assert(syncMemberSearchFromForm("", "", "") === "", "blank form stays blank");

var emptyHits = rankedHits(members, "");
assert(emptyHits.length === members.length, "empty query does not hide members");

var nameHits = rankedHits(members, "Aih(ahws LINE)");
assert(nameHits.length >= 1, "name query finds members");
assert(nameHits[0].item.name === "Aih(ahws LINE)", "exact name is the first fill");
assert(nameHits[0].item.phone === "0958446753", "filled member has phone");
assert(memberSearchRank(members[2], "Aih(ahws LINE)") === 0, "paren name is an exact loose match");
assert(memberSearchRank(members[1], "Aih(ahws LINE)") > 0, "slash variant is not exact");
assert(memberSearchRank(members[2], "Aih") < 9999, "short name still matches");
assert(freightExtractCompletePhones("Aih(ahws LINE)").length === 0, "name is not treated as a phone");

var phoneHits = rankedHits(members, "0958446753");
assert(phoneHits.length >= 1, "phone query finds members");
assert(phoneHits.every(function (hit) { return hit.item.phone === "0958446753"; }), "phone query stays on that number");
assert(rankedHits(members, "0958446753").every(function (hit) { return hit.item.name.indexOf("Aih") === 0; }), "phone does not pull unrelated names");

var miss = rankedHits(members, "DefinitelyNotAMember");
assert(miss.length === 0, "unknown name is 查無會員");

assert(memberSearchRank({ name: "Aih(ahws LINE)", phone: "0958446753" }, "0912345678") === 9999, "other complete phone does not hit Aih");

console.log("all member search fill tests passed");
