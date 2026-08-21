#!/usr/bin/env node
"use strict";

/**
 * 會員管理「查詢」只讀下方搜尋框，上方姓名／電話不會拿去查，
 * 命中後也不會把電話、地址帶回表單，看起來就像查不到資料。
 *
 * Cache-bust: admin.js ?v=20260821-member-search-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260821-member-search-1";
const JS_MARKER = "function runMemberSearch()";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1) {
    console.log("already:", label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, "\r\n");
    to = newStr.replace(/\n/g, "\r\n");
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

const HELPERS_OLD = `  function renderPeopleLists() {
    var memberSummary = document.querySelector('[data-member-summary]');
    var memberList = document.querySelector('[data-member-list]');`;

const HELPERS_NEW = `  function memberSearchInputQuery() {
    var search = document.querySelector('[data-member-search]');
    return String(search && search.value || '').trim();
  }

  function syncMemberSearchFromForm() {
    var search = document.querySelector('[data-member-search]');
    if (!search || String(search.value || '').trim()) return;
    var name = String((document.querySelector('[data-member-name]') || {}).value || '').trim();
    var phone = String((document.querySelector('[data-member-phone]') || {}).value || '').trim();
    var seed = name || phone;
    if (seed) search.value = seed;
  }

  function memberMatchesSearchQuery(item, query) {
    query = String(query || '').trim().toLowerCase();
    if (!query) return true;
    if (memberSearchRank(item, query) < 9999) return true;
    var text = [item.name, item.phone, item.address, item.note, item.deliveryLabel, item.shippingFee, item.customerTypeLabel].join(' ').toLowerCase();
    var looseQuery = searchLooseNormalize(query);
    var phoneQuery = searchPhoneDigits(query);
    return (looseQuery && searchLooseNormalize(text).indexOf(looseQuery) !== -1)
      || (phoneQuery && searchPhoneDigits([item.phone, text].join(' ')).indexOf(phoneQuery) !== -1);
  }

  function rankedMemberSearchHits() {
    var query = memberSearchInputQuery();
    return (state.members || []).map(function (item, index) {
      return { item: item, index: index, rank: memberSearchRank(item, query) };
    }).filter(function (entry) {
      return memberMatchesSearchQuery(entry.item, query);
    }).sort(function (a, b) {
      if (a.rank !== b.rank) return a.rank - b.rank;
      var nameA = searchLooseNormalize(a.item.name || '');
      var nameB = searchLooseNormalize(b.item.name || '');
      if (nameA !== nameB) return nameA.localeCompare(nameB, 'zh-Hant', { numeric: true });
      return a.index - b.index;
    });
  }

  function applyMemberSearchHit() {
    var query = memberSearchInputQuery();
    if (!(state.members || []).length) {
      toast('會員資料尚未載入完成，請稍候再查');
      return false;
    }
    if (!query) {
      toast('請輸入姓名或電話後再查詢');
      return false;
    }
    var hits = rankedMemberSearchHits();
    if (!hits.length) {
      toast('查無會員：' + query);
      return false;
    }
    var first = hits[0].item || {};
    startMemberEdit(hits[0].index, {
      scroll: true,
      toast: hits.length === 1
        ? ('已帶入會員：' + (first.name || first.phone || query))
        : ('找到 ' + hits.length + ' 筆，已帶入最接近的：' + (first.name || first.phone || query))
    });
    return true;
  }

  function runMemberSearch() {
    syncMemberSearchFromForm();
    renderPeopleLists();
    applyMemberSearchHit();
  }

  function bindMemberSearchEnter(selector) {
    bindIf(selector, 'keydown', function (event) {
      if (!event || event.key !== 'Enter') return;
      event.preventDefault();
      runMemberSearch();
    });
  }

  function renderPeopleLists() {
    var memberSummary = document.querySelector('[data-member-summary]');
    var memberList = document.querySelector('[data-member-list]');`;

const LIMIT_OLD = `    if (memberSummary) memberSummary.textContent = '會員總數 ' + state.members.length + ' 筆，符合 ' + filteredMembers.length + ' 筆，目前顯示前 10 筆。';
    if (memberList) memberList.innerHTML = filteredMembers.slice(0, 10).map(function (entry) {`;

const LIMIT_NEW = `    var memberDisplayLimit = memberQuery ? 50 : 10;
    if (memberSummary) memberSummary.textContent = '會員總數 ' + state.members.length + ' 筆，符合 ' + filteredMembers.length + ' 筆，目前顯示前 ' + Math.min(memberDisplayLimit, filteredMembers.length) + ' 筆。';
    if (memberList) memberList.innerHTML = filteredMembers.slice(0, memberDisplayLimit).map(function (entry) {`;

const TOAST_OLD = `    if (options && options.scroll) {
      var membersPanel = document.querySelector('#members');
      if (membersPanel && membersPanel.scrollIntoView) membersPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(function () { if (nameInput) nameInput.focus(); }, 250);
    }
    toast('正在編輯會員：' + (editMember.name || editMember.phone || '會員'));
    return true;`;

const TOAST_NEW = `    if (options && options.scroll) {
      var membersPanel = document.querySelector('#members');
      if (membersPanel && membersPanel.scrollIntoView) membersPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(function () { if (nameInput) nameInput.focus(); }, 250);
    }
    toast((options && options.toast) || ('正在編輯會員：' + (editMember.name || editMember.phone || '會員')));
    return true;`;

const BIND_OLD = `    bindIf('[data-member-search-submit]', 'click', renderPeopleLists);
    bindIf('[data-member-search-reset]', 'click', function () {`;

const BIND_NEW = `    bindIf('[data-member-search-submit]', 'click', runMemberSearch);
    bindMemberSearchEnter('[data-member-search]');
    bindMemberSearchEnter('[data-member-name]');
    bindMemberSearchEnter('[data-member-phone]');
    bindIf('[data-member-search-reset]', 'click', function () {`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
    const page = fs.readFileSync(file, "latin1");
    if (page.indexOf("admin.js") === -1) return;
    const next = page.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === page) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "member-search-fill"));
let js = fs.readFileSync(JS, "utf8");
js = replaceOnce(js, HELPERS_OLD, HELPERS_NEW, "member search helpers");
js = replaceOnce(js, LIMIT_OLD, LIMIT_NEW, "show 50 matches when searching");
js = replaceOnce(js, TOAST_OLD, TOAST_NEW, "allow search fill toast");
js = replaceOnce(js, BIND_OLD, BIND_NEW, "query uses form and fills member");
try {
  new Function(js);
  console.log("js syntax ok");
} catch (error) {
  throw new Error("admin.js syntax: " + error.message);
}
fs.writeFileSync(JS, js);
stampHtml(ROOT);
if (js.indexOf(JS_MARKER) === -1) throw new Error("runMemberSearch missing");
if (js.indexOf("bindIf('[data-member-search-submit]', 'click', runMemberSearch)") === -1) {
  throw new Error("search submit still bound to render only");
}
console.log("done", STAMP);
