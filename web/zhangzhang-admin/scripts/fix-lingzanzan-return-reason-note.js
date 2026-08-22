#!/usr/bin/env node
"use strict";

/**
 * 退貨有選下拉原因就不用再打備註。只有「其他」要另填。
 * 不 stamp 全站 HTML。
 *
 * Cache-bust: admin.js ?v=20260822-return-note-1
 */

const fs = require("fs");
const path = require("path");
const {
  returnReasonFilledNote,
} = require("./lz-return-reason-duplicate");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const API_PHP = path.join(ROOT, "order-admin-api-v6.php");
const STAMP = "20260822-return-note-1";

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

function stampHtml(dir) {
  const names = ["admin-freight.html", "admin-reserved-shipping.html", "admin-orders.html", "admin-order-tracking.html"];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const LABEL_OLD = `+ '<label class="freight-pending-priority-reason"><span>出貨備註／理由（必填）</span><textarea data-return-order-note maxlength="500" rows="3" placeholder="例如：門市退回、客人說地址填錯、包裹多出一件未訂商品"></textarea></label>'`;

const LABEL_NEW = `+ '<label class="freight-pending-priority-reason"><span>出貨備註（選填）</span><textarea data-return-order-note maxlength="500" rows="3" placeholder="已選原因就不用再打；有補充再填"></textarea></label>'`;

const FOOTER_OLD = `請選原因、填備註，並勾選確認`;
const FOOTER_NEW = `請選原因並勾選確認`;

const SYNC_OLD = `        status.textContent = isOther ? '請填其他原因、備註，並勾選確認' : '請選原因、填備註，並勾選確認';`;
const SYNC_NEW = `        status.textContent = isOther ? '請填其他原因並勾選確認' : '請選原因並勾選確認';`;

const CONFIRM_OLD = `        var reasonDetail = String((otherInput && otherInput.value) || '').trim();
        var note = String((modal.querySelector('[data-return-order-note]') || {}).value || '').trim();
        if (!reasonCode) { status.textContent = '請先選擇退貨原因'; return; }
        if (reasonCode === 'other' && !reasonDetail) { status.textContent = '請填入其他原因，例如多打單故取消'; if (otherInput) otherInput.focus(); return; }
        if (!note) { status.textContent = '請填寫出貨備註，管理層會用來跟業務核對'; return; }`;

const CONFIRM_NEW = `        var reasonDetail = String((otherInput && otherInput.value) || '').trim();
        var note = String((modal.querySelector('[data-return-order-note]') || {}).value || '').trim();
        if (!reasonCode) { status.textContent = '請先選擇退貨原因'; return; }
        if (reasonCode === 'other' && !reasonDetail) { status.textContent = '請填入其他原因，例如多打單故取消'; if (otherInput) otherInput.focus(); return; }
        if (!note) {
          var selectedLabel = String((reasonSelect && reasonSelect.selectedOptions && reasonSelect.selectedOptions[0] && reasonSelect.selectedOptions[0].textContent) || '');
          note = reasonCode === 'other'
            ? ('其他：' + reasonDetail)
            : selectedLabel.replace(/^\\d+\\.\\s*/, '').replace(/（[^）]*）/g, '').replace(/\\([^)]*\\)/g, '').trim();
        }
        if (!note) { status.textContent = '請先選擇退貨原因'; return; }`;

const PHP_OLD = `    $note = trim((string)($payload['note'] ?? ''));
    if ($note === '') respond(['ok' => false, 'error' => '請填寫出貨備註，管理層會用來跟業務核對'], 400);`;

const PHP_NEW = `    $note = trim((string)($payload['note'] ?? ''));
    if ($note === '') {
        if ($reasonCode === 'other' && $reasonDetail !== '') {
            $note = '其他：' . $reasonDetail;
        } elseif ($reasonCode !== 'other' && isset($reasons[$reasonCode]['label'])) {
            $note = (string)$reasons[$reasonCode]['label'];
        }
    }
    if ($note === '') respond(['ok' => false, 'error' => '請選擇退貨原因；已選原因就不用再打備註'], 400);`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

if (returnReasonFilledNote("duplicate_order", "8. 重複打單", "", "") !== "重複打單") {
  throw new Error("helper mismatch");
}

backup(ADMIN_JS, "return-note");
let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, LABEL_OLD, LABEL_NEW, "note label optional");
js = replaceOnce(js, SYNC_OLD, SYNC_NEW, "status no longer asks to retype");
if (js.indexOf(FOOTER_OLD) !== -1) {
  js = replaceOnce(js, FOOTER_OLD, FOOTER_NEW, "footer status optional note");
} else {
  console.log("already or missing:", "footer status optional note");
}
js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "submit fills note from dropdown");
fs.writeFileSync(ADMIN_JS, js);

if (fs.existsSync(API_PHP)) {
  backup(API_PHP, "return-note");
  let api = fs.readFileSync(API_PHP, "utf8");
  api = replaceOnce(api, PHP_OLD, PHP_NEW, "api accepts selected reason as note");
  fs.writeFileSync(API_PHP, api);
}

if (js.indexOf("出貨備註（選填）") === -1) throw new Error("optional note label missing");
if (js.indexOf("已選原因就不用再打") === -1) throw new Error("placeholder missing");
if (js.indexOf("請選原因並勾選確認") === -1) throw new Error("status copy missing");
if (js.indexOf("selectedLabel.replace") === -1) throw new Error("dropdown fill missing");

stampHtml(ROOT);
console.log("LINGZANZAN return selected reason skips retype ok", STAMP);
