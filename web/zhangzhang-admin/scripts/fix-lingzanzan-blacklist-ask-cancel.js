#!/usr/bin/env node
"use strict";

/**
 * 訂單客人按「加入黑名單」後，順道問要不要一起退這張單。
 * 出貨核對「封鎖並取消」原本就會兩件事一起做，這裡不改那顆鈕。
 * 「解除黑名單」不問退單。不改 .is-active 金鈕。
 *
 * Cache-bust: admin.js / member-risk-v2.js ?v=20260822-bl-cancel-1
 */

const fs = require("fs");
const path = require("path");
const {
  blacklistAskCancelUsesExistingCancel,
  blacklistAskCancelHooksRiskToggle,
  blacklistAskCancelDoesNotRestyleActive,
} = require("./lz-blacklist-ask-cancel");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const RISK_JS = path.join(ROOT, "assets", "member-risk-v2.js");
const STAMP = "20260822-bl-cancel-1";

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

function stampFile(file, encoding) {
  if (!fs.existsSync(file)) return false;
  const html = fs.readFileSync(file, encoding);
  if (!/admin\.js|member-risk-v2\.js/.test(html)) return false;
  const next = html
    .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
    .replace(/member-risk-v2\.js(?:\?v=[^"']+)?/g, "member-risk-v2.js?v=" + STAMP);
  if (next === html) return false;
  if (encoding === "latin1") fs.writeFileSync(file, Buffer.from(next, "latin1"));
  else fs.writeFileSync(file, next, encoding);
  console.log("stamped", path.basename(file), encoding);
  return true;
}

const ADMIN_OLD = `  function executeFreightFifoQueueCancel(inquiryId, sourceButton) {
    inquiryId = String(inquiryId || '').trim();
    if (!inquiryId) return Promise.reject(new Error('找不到要取消的配貨單'));`;

const ADMIN_NEW = `  function blacklistShouldAskCancel(row) {
    if (!row) return false;
    var status = String(row.status || '').toLowerCase();
    var delivery = String(row.deliveryState || '').toLowerCase();
    if (['cancelled', 'canceled', 'returned', 'refunded', 'closed'].indexOf(status) !== -1) return false;
    if (['returned', 'delivered'].indexOf(delivery) !== -1) return false;
    return true;
  }

  function blacklistAskCancelMessage(name, recordId) {
    return '已把「' + (name || '這位客人') + '」列入黑名單。\\n這張訂單要不要一起取消、整單回庫？\\n' + (recordId ? recordId + '\\n' : '') + '確定＝退單（商品回倉）；取消＝只封鎖，訂單留著。';
  }

  function recordIdFromBlacklistButton(button) {
    if (!button || !button.closest) return '';
    var card = button.closest('[data-order-id], [data-order-card], [data-preorder-card], [data-preorder-id], .order-tracking-card, article');
    if (!card) return '';
    var attr = String(card.getAttribute('data-order-id') || card.getAttribute('data-order-card') || card.getAttribute('data-preorder-card') || card.getAttribute('data-preorder-id') || '').trim();
    if (attr) return attr;
    var nested = card.querySelector('[data-freight-fifo-cancel-order], [data-freight-fifo-blacklist], [data-freight-fifo-order], [data-freight-fifo-return-order]');
    return String((nested && (nested.getAttribute('data-freight-fifo-cancel-order') || nested.getAttribute('data-freight-fifo-blacklist') || nested.getAttribute('data-freight-fifo-order') || nested.getAttribute('data-freight-fifo-return-order'))) || '').trim();
  }

  function askCancelAfterBlacklist(detail) {
    detail = detail || {};
    var recordId = String(detail.recordId || recordIdFromBlacklistButton(detail.button) || '').trim();
    var name = String(detail.name || '這位客人');
    if (!recordId) return;
    var targets = resolveFreightFifoBlacklistTargets(recordId);
    var order = targets.order;
    var inquiry = targets.inquiry;
    if (!blacklistShouldAskCancel(order) && !blacklistShouldAskCancel(inquiry)) return;
    if (!window.confirm(blacklistAskCancelMessage(name, targets.orderId || targets.inquiryId || recordId))) return;
    if (order && blacklistShouldAskCancel(order) && targets.orderId) {
      openFreightFifoCancelOrder(targets.orderId, detail.button || null);
      return;
    }
    if (inquiry && blacklistShouldAskCancel(inquiry) && targets.inquiryId) {
      executeFreightFifoQueueCancel(targets.inquiryId, detail.button || null).then(function () {
        toast('已取消這張配貨單，庫存會依順位補給下一位。');
        if (typeof renderFreightCustomerShipmentQueue === 'function') renderFreightCustomerShipmentQueue();
      }).catch(function (error) {
        toast((error && error.message) || '黑名單已鎖，但這張單取消失敗。請到出貨核對手動取消。');
      });
    }
  }

  window.LingzanzanAdminActions = window.LingzanzanAdminActions || {};
  window.LingzanzanAdminActions.askCancelAfterBlacklist = askCancelAfterBlacklist;

  function executeFreightFifoQueueCancel(inquiryId, sourceButton) {
    inquiryId = String(inquiryId || '').trim();
    if (!inquiryId) return Promise.reject(new Error('找不到要取消的配貨單'));`;

const RISK_ATTR_OLD = `    button.setAttribute('data-risk-phone', customerPhone);
    button.setAttribute('data-risk-name', customerName);`;

const RISK_ATTR_NEW = `    var recordId = String((card.getAttribute && (card.getAttribute('data-order-id') || card.getAttribute('data-order-card') || card.getAttribute('data-preorder-card') || card.getAttribute('data-preorder-id'))) || '').trim();
    if (!recordId) {
      var idNode = card.querySelector && card.querySelector('[data-freight-fifo-cancel-order], [data-freight-fifo-blacklist], [data-freight-fifo-order], [data-freight-fifo-return-order]');
      recordId = String((idNode && (idNode.getAttribute('data-freight-fifo-cancel-order') || idNode.getAttribute('data-freight-fifo-blacklist') || idNode.getAttribute('data-freight-fifo-order') || idNode.getAttribute('data-freight-fifo-return-order'))) || '').trim();
    }
    button.setAttribute('data-risk-phone', customerPhone);
    button.setAttribute('data-risk-name', customerName);
    if (recordId) button.setAttribute('data-risk-record-id', recordId);
    else button.removeAttribute('data-risk-record-id');`;

const RISK_CLICK_OLD = `      orderRiskButton.disabled = true;
      orderRiskButton.textContent = text('儲存中…', 'Menyimpan…');
      save(payload).catch(function (error) {`;

const RISK_CLICK_NEW = `      orderRiskButton.disabled = true;
      orderRiskButton.textContent = text('儲存中…', 'Menyimpan…');
      save(payload).then(function () {
        if (payload.action !== 'save') return;
        var actions = window.LingzanzanAdminActions;
        if (actions && typeof actions.askCancelAfterBlacklist === 'function') {
          actions.askCancelAfterBlacklist({
            name: riskName,
            phone: riskPhone,
            recordId: orderRiskButton.getAttribute('data-risk-record-id') || '',
            button: orderRiskButton
          });
        }
      }).catch(function (error) {`;

if (!blacklistAskCancelDoesNotRestyleActive(ADMIN_NEW + RISK_ATTR_NEW + RISK_CLICK_NEW)) {
  throw new Error("refusing to restyle .is-active");
}

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(RISK_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "bl-cancel");
backup(RISK_JS, "bl-cancel");

let admin = fs.readFileSync(ADMIN_JS, "utf8");
admin = replaceOnce(admin, ADMIN_OLD, ADMIN_NEW, "askCancelAfterBlacklist helper");
fs.writeFileSync(ADMIN_JS, admin);

let risk = fs.readFileSync(RISK_JS, "utf8");
risk = replaceOnce(risk, RISK_ATTR_OLD, RISK_ATTR_NEW, "risk record id on toggle");
risk = replaceOnce(risk, RISK_CLICK_OLD, RISK_CLICK_NEW, "ask cancel after blacklist save");
fs.writeFileSync(RISK_JS, risk);

if (!blacklistAskCancelUsesExistingCancel(admin)) throw new Error("admin helper missing after patch");
if (!blacklistAskCancelHooksRiskToggle(risk)) throw new Error("risk toggle hook missing after patch");

let stamped = 0;
fs.readdirSync(ROOT).forEach(function (name) {
  if (!/\.html$/i.test(name)) return;
  if (/_backups|codex-backup/i.test(name)) return;
  const file = path.join(ROOT, name);
  if (name === "admin-reserved-shipping.html") {
    if (stampFile(file, "utf8")) stamped += 1;
    return;
  }
  if (stampFile(file, "latin1")) stamped += 1;
});
console.log("html stamped", stamped);
console.log("LINGZANZAN blacklist ask cancel ok", STAMP);
