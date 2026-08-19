#!/usr/bin/env node
"use strict";

/**
 * FIFO 出貨卡摺疊時卡片上沒有電話，markPage 拿整張卡片 textContent 去對黑名單／歷史單。
 * FATMA(CINTA) 會對到 CINTA(Cinta remaja titok)，警告裡還出現別人的電話與舊單。
 * 改成先讀這張卡自己的姓名／電話欄，不要掃整張卡片。
 *
 * Cache-bust: member-risk-v2.js / admin-navigation.js ?v=20260819-blacklist-phone-2
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const RISK_JS = path.join(ROOT, "assets", "member-risk-v2.js");
const NAV_JS = path.join(ROOT, "assets", "admin-navigation.js");
const STAMP = "20260819-blacklist-phone-2";
const MARKER = "function customerIdentityFromTarget(target)";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
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

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "utf8");
    if (!/member-risk-v2\.(js|css)|admin-navigation\.js/.test(html)) continue;
    const next = html
      .replace(/member-risk-v2\.css(?:\?v=[^"']+)?/g, "member-risk-v2.css?v=" + STAMP)
      .replace(/member-risk-v2\.js(?:\?v=[^"']+)?/g, "member-risk-v2.js?v=" + STAMP)
      .replace(/admin-navigation\.js(?:\?v=[^"']+)?/g, "admin-navigation.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, next);
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const IDENTITY_FN = `  function customerIdentityFromTarget(target) {
    var identity = { name: '', phone: '' };
    if (!target || !target.querySelector) return identity;
    var nameInput = target.querySelector('[data-freight-fifo-name], [data-member-name], [data-customer-name], [data-order-name], [data-admin-shipment-name], [data-manual-customer-name], [data-live-customer-name]');
    var phoneInput = target.querySelector('[data-freight-fifo-phone], [data-member-phone], [data-customer-phone], [data-order-phone], [data-admin-shipment-phone], [data-manual-customer-phone], [data-live-customer-phone], input[type="tel"]');
    if (nameInput) identity.name = String(nameInput.value || nameInput.textContent || '').replace(/黑名單/g, '').trim();
    if (phoneInput) identity.phone = String(phoneInput.value || phoneInput.textContent || '').trim();
    if (!identity.name) {
      var nameNode = target.querySelector('.freight-fifo-active-copy > b, .order-tracking-customer > b, .order-first-customer > b');
      identity.name = String((nameNode && nameNode.textContent) || '').replace(/黑名單/g, '').trim();
    }
    if (!identity.phone) {
      var phoneNode = target.querySelector('[data-freight-fifo-phone-preview]');
      var preview = String((phoneNode && (phoneNode.value || phoneNode.textContent)) || '').replace(/\\D/g, '');
      if (/^09\\d{8}$/.test(preview)) identity.phone = preview;
    }
    return identity;
  }

`;

const MARK_OLD = `    document.querySelectorAll(targetSelector).forEach(function (target) {
      setRiskBadge(target, riskFromText(target.textContent), returnFromText(target.textContent), orderActivityFromText(target.textContent), orderActivityContextFromTarget(target));
      installOrderRiskAction(target);
    });`;

const MARK_NEW = `    document.querySelectorAll(targetSelector).forEach(function (target) {
      var identity = customerIdentityFromTarget(target);
      var risk = identity.phone || identity.name
        ? riskByCustomer(identity.phone, identity.name)
        : riskFromText(target.textContent);
      var returned = identity.phone || identity.name
        ? returnByCustomer(identity.phone, identity.name)
        : returnFromText(target.textContent);
      var orderActivity = identity.phone || identity.name
        ? orderActivityByCustomer(identity.phone, identity.name)
        : orderActivityFromText(target.textContent);
      setRiskBadge(target, risk, returned, orderActivity, orderActivityContextFromTarget(target));
      installOrderRiskAction(target);
    });`;

const ACT_OLD = `  function orderActivityFromText(value) {
    var text = String(value || '');
    var digits = phone(text);
    var normalizedText = nameKey(text);
    var phoneMatch = orderHistoryRows.find(function (row) {
      var historyPhone = rowPhone(row);
      return historyPhone && digits.indexOf(historyPhone) !== -1;
    });
    if (phoneMatch) return phoneMatch;
    return orderHistoryRows.find(function (row) {
      var historyName = rowName(row);
      return historyName && historyName.length >= 2 && normalizedText.indexOf(historyName) !== -1;
    }) || null;
  }`;

const ACT_NEW = `  function orderActivityFromText(value) {
    var text = String(value || '');
    var mobiles = extractMobiles(text);
    if (mobiles.length) {
      return orderHistoryRows.find(function (row) {
        return mobiles.some(function (m) { return phoneHit(m, row && row.phone); });
      }) || null;
    }
    var normalizedText = nameKey(text);
    if (!normalizedText || normalizedText.length > 80) return null;
    return orderHistoryRows.find(function (row) {
      return rowName(row) === normalizedText;
    }) || null;
  }`;

const RET_OLD = `  function returnFromText(value) {
    var text = String(value || '');
    var digits = phone(text);
    var normalizedText = nameKey(text);
    var phoneMatch = returnRows.find(function (row) {
      var historyPhone = rowPhone(row);
      return historyPhone && digits.indexOf(historyPhone) !== -1;
    });
    if (phoneMatch) return phoneMatch;
    return returnRows.find(function (row) {
      var historyName = rowName(row);
      return historyName && historyName.length >= 2 && normalizedText.indexOf(historyName) !== -1;
    }) || null;
  }`;

const RET_NEW = `  function returnFromText(value) {
    var text = String(value || '');
    var mobiles = extractMobiles(text);
    if (mobiles.length) {
      return returnRows.find(function (row) {
        return mobiles.some(function (m) { return phoneHit(m, row && row.phone); });
      }) || null;
    }
    var normalizedText = nameKey(text);
    if (!normalizedText || normalizedText.length > 80) return null;
    return returnRows.find(function (row) {
      return rowName(row) === normalizedText;
    }) || null;
  }`;

console.log("backup js", backup(RISK_JS, "blacklist-identity"));
let src = fs.readFileSync(RISK_JS, "utf8");
if (src.indexOf(MARKER) === -1) {
  src = replaceOnce(
    src,
    "  function markPage() {",
    IDENTITY_FN + "  function markPage() {",
    "identity helper"
  );
  src = replaceOnce(src, MARK_OLD, MARK_NEW, "markPage uses card identity");
  src = replaceOnce(src, ACT_OLD, ACT_NEW, "orderActivityFromText");
  src = replaceOnce(src, RET_OLD, RET_NEW, "returnFromText");
  fs.writeFileSync(RISK_JS, src);
  console.log("js written", src.length);
} else {
  console.log("js already patched");
}

console.log("backup nav", backup(NAV_JS, "blacklist-identity"));
let nav = fs.readFileSync(NAV_JS, "utf8");
const nextNav = nav
  .replace(/member-risk-v2\.css\?v=[^"']+/g, "member-risk-v2.css?v=" + STAMP)
  .replace(/member-risk-v2\.js\?v=[^"']+/g, "member-risk-v2.js?v=" + STAMP);
if (nextNav === nav) {
  if (nav.indexOf("member-risk-v2.js?v=" + STAMP) === -1) throw new Error("missing nav member-risk stamp");
  console.log("nav already stamped");
} else {
  fs.writeFileSync(NAV_JS, nextNav);
  console.log("nav stamped");
}

stampHtml(ROOT);
console.log("LINGZANZAN blacklist card identity:", STAMP);
