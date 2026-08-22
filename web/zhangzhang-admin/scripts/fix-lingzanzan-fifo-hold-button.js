#!/usr/bin/env node
"use strict";

/**
 * 出貨核對單把寄庫放到②超商／住家出貨欄下面，並加「加入寄庫名單」按鈕。
 * 已在寄庫的單顯示「此單已在寄庫名單」。不是第三個地址選項。
 *
 * Live: assets/admin.js, assets/admin.css
 * Cache-bust: file mtime (asset-boot) + stamp fallback
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const {
  fifoHoldIsUnderShipping,
  fifoHoldIsAfterShare,
} = require("./lz-fifo-hold-button");
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260822-fifo-hold-2";
const JS_MARKER = "data-freight-fifo-hold-save";
const CSS_MARKER = "/* 20260822 fifo hold button: 寄庫 visible under customer */";
const CSS_INLINE_MARKER = "/* 20260822 fifo hold inline under 超商住家 */";

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
  const names = [
    "admin-freight.html",
    "admin-reserved-shipping.html",
    "admin-orders.html",
    "admin-preorders.html",
    "admin-order-tracking.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1 && html.indexOf("admin.css") === -1) return;
    const next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const VARS_OLD = `    var reservedHoldReasonText = String(
      row.reservedShippingHoldReasonText
      || (order && order.reservedShippingHoldReasonText)
      || (preorder && preorder.reservedShippingHoldReasonText)
      || ''
    ).trim();
    var reservationMinDate = new Date().toISOString().slice(0, 10);`;

const VARS_NEW = `    var reservedHoldReasonText = String(
      row.reservedShippingHoldReasonText
      || (order && order.reservedShippingHoldReasonText)
      || (preorder && preorder.reservedShippingHoldReasonText)
      || ''
    ).trim();
    var fifoHoldActive = !!(reservedHoldReason && reservedHoldReason !== 'scheduled_ship');
    var fifoHoldStatusCopy = fifoHoldActive
      ? ('此單已在寄庫名單' + (reservedHoldReason === 'other'
        ? (reservedHoldReasonText ? '／' + reservedHoldReasonText : '')
        : (reservedHoldReasonLabel(reservedHoldReason) ? '／' + reservedHoldReasonLabel(reservedHoldReason) : '')))
      : '尚未寄庫';
    var fifoHoldSaveCopy = fifoHoldActive ? '更新寄庫原因' : '加入寄庫名單';
    var reservationMinDate = new Date().toISOString().slice(0, 10);`;

const MOVE_FROM_OLD = `<h4 class="freight-fifo-shipping-title">⑤ 正式出貨資料</h4><div class="freight-fifo-reservation"><header><div><b>預約出貨日期／寄庫原因</b><small>預約排單：填指定出貨日即可。寄庫：選原因後按下方按鈕，會跟出貨一樣建單，之後到寄庫名單拉出來出貨。日期可空白。</small></div><span>日期到會提醒</span></header><div><label>預約日期<span class="reservation-date-picker"><input type="date" min="' + escapeHtml(reservationMinDate) + '" value="' + escapeHtml(reservedShippingDate) + '" data-freight-fifo-reserved-date><button type="button" class="ghost-button reservation-calendar-button" data-open-reservation-calendar>📅 開啟日曆</button></span></label><label>預約備註<input value="' + escapeHtml(reservedShippingNote) + '" data-freight-fifo-reserved-note placeholder="例如：客戶指定週五一起出"></label><label>寄庫原因' + reservedHoldReasonSelectHtml(reservedHoldReason, 'data-freight-fifo-hold-reason') + '</label><label data-freight-fifo-hold-reason-other-wrap' + (reservedHoldReason === 'other' ? '' : ' hidden') + '>其他原因<input value="' + escapeHtml(reservedHoldReason === 'other' ? reservedHoldReasonText : '') + '" data-freight-fifo-hold-reason-other placeholder="請填寫其他原因"></label></div></div><div class="freight-fifo-shipping-fields">`;

const MOVE_FROM_NEW = `<h4 class="freight-fifo-shipping-title">⑤ 正式出貨資料</h4><p class="freight-fifo-hold-jump">寄庫在上面②「超商出貨／住家出貨」欄下面，選原因後按「加入寄庫名單」。這裡只填物流公司與單號。</p><div class="freight-fifo-shipping-fields">`;

const HOLD_V2 = `<div class="freight-fifo-reservation" data-freight-fifo-hold-panel><header><div><b>寄庫（先不出貨）</b><small>選原因後按「加入寄庫名單」。這不是超商／住家選項，地址仍照上面選的出。預約排單才填日期。</small></div><span data-freight-fifo-hold-status' + (fifoHoldActive ? ' class="is-on-hold"' : '') + '>' + escapeHtml(fifoHoldStatusCopy) + '</span></header><div><label>寄庫原因' + reservedHoldReasonSelectHtml(reservedHoldReason, 'data-freight-fifo-hold-reason') + '</label><label data-freight-fifo-hold-reason-other-wrap' + (reservedHoldReason === 'other' ? '' : ' hidden') + '>其他原因<input value="' + escapeHtml(reservedHoldReason === 'other' ? reservedHoldReasonText : '') + '" data-freight-fifo-hold-reason-other placeholder="請填寫其他原因"></label><div class="freight-fifo-reservation-actions"><button type="button" class="primary-button" data-freight-fifo-hold-save>' + escapeHtml(fifoHoldSaveCopy) + '</button><small>選寄庫原因後按這顆即可。</small></div><p data-freight-fifo-hold-result></p><label>預約出貨日期<span class="reservation-date-picker"><input type="date" min="' + escapeHtml(reservationMinDate) + '" value="' + escapeHtml(reservedShippingDate) + '" data-freight-fifo-reserved-date><button type="button" class="ghost-button reservation-calendar-button" data-open-reservation-calendar>📅 開啟日曆</button></span></label><label>預約備註<input value="' + escapeHtml(reservedShippingNote) + '" data-freight-fifo-reserved-note placeholder="例如：客戶指定週五一起出"></label></div></div>`;

const AFTER_SHIP_OLD = `}, { textarea: true, hiddenAttrs: 'data-freight-fifo-address' }) + '<small data-freight-fifo-delivery-hint>'`;
const AFTER_SHIP_NEW = `}, { textarea: true, hiddenAttrs: 'data-freight-fifo-address' }) + '` + HOLD_V2 + `' + '<small data-freight-fifo-delivery-hint>'`;

const INSERT_BEFORE_OLD = `<button type="button" class="primary-button" data-freight-fifo-share>LINE 傳送／出貨小卡</button></div></div><div><h4>③ 產品名額與實際到貨狀態</h4>`;

const INSERT_BEFORE_NEW = `<button type="button" class="primary-button" data-freight-fifo-share>LINE 傳送／出貨小卡</button></div></div><div class="freight-fifo-reservation" data-freight-fifo-hold-panel><header><div><b>寄庫／預約出貨</b><small>寄庫：選原因後按「加入寄庫名單」，不必滾到最下面。預約排單：填指定出貨日後再按底部儲存。日期可空白。</small></div><span data-freight-fifo-hold-status' + (fifoHoldActive ? ' class="is-on-hold"' : '') + '>' + escapeHtml(fifoHoldStatusCopy) + '</span></header><div><label>預約日期<span class="reservation-date-picker"><input type="date" min="' + escapeHtml(reservationMinDate) + '" value="' + escapeHtml(reservedShippingDate) + '" data-freight-fifo-reserved-date><button type="button" class="ghost-button reservation-calendar-button" data-open-reservation-calendar>📅 開啟日曆</button></span></label><label>預約備註<input value="' + escapeHtml(reservedShippingNote) + '" data-freight-fifo-reserved-note placeholder="例如：客戶指定週五一起出"></label><label>寄庫原因' + reservedHoldReasonSelectHtml(reservedHoldReason, 'data-freight-fifo-hold-reason') + '</label><label data-freight-fifo-hold-reason-other-wrap' + (reservedHoldReason === 'other' ? '' : ' hidden') + '>其他原因<input value="' + escapeHtml(reservedHoldReason === 'other' ? reservedHoldReasonText : '') + '" data-freight-fifo-hold-reason-other placeholder="請填寫其他原因"></label><div class="freight-fifo-reservation-actions"><button type="button" class="primary-button" data-freight-fifo-hold-save>' + escapeHtml(fifoHoldSaveCopy) + '</button><small>選寄庫原因後按這顆即可，會寫進寄庫名單。</small></div><p data-freight-fifo-hold-result></p></div></div><div><h4>③ 產品名額與實際到貨狀態</h4>`;

const HELPERS_OLD = `  function reservedHoldReasonFromHost(host, reasonSelector, otherSelector, required) {`;

const HELPERS_NEW = `  function freightFifoHoldReasonIsHold(code) {
    code = String(code || '').trim();
    return !!(code && code !== 'scheduled_ship');
  }

  function saveFreightFifoHold(button) {
    var modal = button && button.closest('[data-freight-fifo-modal]');
    if (!modal) return;
    var localResult = modal.querySelector('[data-freight-fifo-hold-result]') || modal.querySelector('[data-freight-fifo-result]');
    var hold = reservedHoldReasonFromHost(modal, '[data-freight-fifo-hold-reason]', '[data-freight-fifo-hold-reason-other]', true);
    if (hold.error) {
      showFreightFifoSubmitProblem(modal, localResult, hold.error, modal.querySelector('[data-freight-fifo-hold-reason]'));
      return;
    }
    if (!freightFifoHoldReasonIsHold(hold.code)) {
      showFreightFifoSubmitProblem(modal, localResult, '寄庫請先選擇原因。「預約指定出貨日」是預約排單，不是寄庫。', modal.querySelector('[data-freight-fifo-hold-reason]'));
      return;
    }
    saveFreightFifoDraft(button);
  }

  function reservedHoldReasonFromHost(host, reasonSelector, otherSelector, required) {`;

const CLICK_OLD = `      var freightFifoSaveDraft = event.target.closest ? event.target.closest('[data-freight-fifo-save-draft]') : null;
      if (freightFifoSaveDraft) {
        event.preventDefault();
        saveFreightFifoDraft(freightFifoSaveDraft);
        return;
      }`;

const CLICK_NEW = `      var freightFifoHoldSave = event.target.closest ? event.target.closest('[data-freight-fifo-hold-save]') : null;
      if (freightFifoHoldSave) {
        event.preventDefault();
        saveFreightFifoHold(freightFifoHoldSave);
        return;
      }
      var freightFifoSaveDraft = event.target.closest ? event.target.closest('[data-freight-fifo-save-draft]') : null;
      if (freightFifoSaveDraft) {
        event.preventDefault();
        saveFreightFifoDraft(freightFifoSaveDraft);
        return;
      }`;

const SUCCESS_OLD = `    }).then(function () {
      button.disabled = false;
      button.textContent = '已存檔';
      if (result) {
        result.style.color = '#b9f6ca';
        result.style.fontWeight = '800';
        result.textContent = '已存檔。客戶、超商、物流公司與單號已寫入；轉正式現貨單仍由管理者操作。';
      }
      toast('已存檔，尚未轉正式出貨單');
    }).catch(function (error) {
      button.disabled = false;
      button.textContent = originalLabel || '只存檔';`;

const SUCCESS_NEW = `    }).then(function () {
      button.disabled = false;
      var savedHold = button.hasAttribute('data-freight-fifo-hold-save');
      button.textContent = savedHold ? '已加入寄庫名單' : '已存檔';
      var holdStatus = modal.querySelector('[data-freight-fifo-hold-status]');
      var holdResult = modal.querySelector('[data-freight-fifo-hold-result]');
      if (savedHold && holdStatus) {
        var holdNow = reservedHoldReasonFromHost(modal, '[data-freight-fifo-hold-reason]', '[data-freight-fifo-hold-reason-other]', false);
        holdStatus.classList.add('is-on-hold');
        holdStatus.textContent = holdNow && freightFifoHoldReasonIsHold(holdNow.code)
          ? ('此單已在寄庫名單' + (holdNow.text ? '／' + holdNow.text : ''))
          : '此單已在寄庫名單';
      }
      if (result) {
        result.style.color = '#b9f6ca';
        result.style.fontWeight = '800';
        result.textContent = savedHold
          ? '已加入寄庫名單。之後到預約出貨頁的寄庫名單拉出來出貨。'
          : '已存檔。客戶、超商、物流公司與單號已寫入；轉正式現貨單仍由管理者操作。';
      }
      if (holdResult) {
        holdResult.style.color = '#b9f6ca';
        holdResult.style.fontWeight = '800';
        holdResult.textContent = savedHold ? '已寫進寄庫名單。' : holdResult.textContent;
      }
      toast(savedHold ? '已加入寄庫名單' : '已存檔，尚未轉正式出貨單');
    }).catch(function (error) {
      button.disabled = false;
      button.textContent = originalLabel || (button.hasAttribute('data-freight-fifo-hold-save') ? '加入寄庫名單' : '只存檔');`;

const CHANGE_OLD = `          result.textContent = '已選寄庫。按下方按鈕會跟出貨一樣建單／存檔，之後到寄庫名單拉出來填單號出貨。現在不必選物流公司。';`;

const CHANGE_NEW = `          result.textContent = '已選寄庫。按「加入寄庫名單」會寫進寄庫名單，之後到預約出貨頁的寄庫名單拉出來出貨。現在不必選物流公司。';`;

const JUMP_OLD = `寄庫與預約出貨在上面「② 客戶資料」下面，選原因後按「加入寄庫名單」。這裡只填物流公司與單號。`;
const JUMP_NEW = `寄庫在上面②「超商出貨／住家出貨」欄下面，選原因後按「加入寄庫名單」。這裡只填物流公司與單號。`;

const CSS_APPEND = `

${CSS_MARKER}
.freight-fifo-reservation-actions,
.freight-fifo-reservation > div > [data-freight-fifo-hold-result] {
  grid-column: 1 / -1;
}
.freight-fifo-reservation-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin-top: 4px;
}
.freight-fifo-reservation-actions small {
  color: #fff8ed;
  font-weight: 700;
  line-height: 1.4;
}
[data-freight-fifo-hold-save] {
  min-height: 48px !important;
  padding: 10px 18px !important;
  border: 0 !important;
  border-radius: 12px;
  background: #f4bd4d !important;
  color: #21150d !important;
  -webkit-text-fill-color: #21150d !important;
  font-size: 16px !important;
  font-weight: 900 !important;
}
[data-freight-fifo-hold-status]:not(.is-on-hold) {
  background: #3b301a !important;
  color: #ffe6a8 !important;
}
[data-freight-fifo-hold-status].is-on-hold {
  background: #123c34 !important;
  color: #64f0cf !important;
}
.freight-fifo-hold-jump {
  margin: 8px 0 12px;
  color: #ffe19a;
  font-weight: 800;
  line-height: 1.45;
}
[data-freight-fifo-hold-result] {
  margin: 0;
  min-height: 1.2em;
  font-weight: 800;
}
`;

const CSS_INLINE_APPEND = `

${CSS_INLINE_MARKER}
.freight-fifo-detail > .freight-fifo-reservation {
  margin-top: 8px;
  grid-column: 1 / -1;
}
.freight-fifo-detail > .freight-fifo-reservation > header b {
  font-size: 18px;
}
`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup admin js", backup(ADMIN_JS, "fifo-hold"));
let admin = fs.readFileSync(ADMIN_JS, "utf8");
if (admin.indexOf(JS_MARKER) !== -1 && admin.indexOf("function saveFreightFifoHold") !== -1) {
  console.log("admin js helpers already patched");
} else {
  admin = replaceOnce(admin, VARS_OLD, VARS_NEW, "hold status copies");
  admin = replaceOnce(admin, MOVE_FROM_OLD, MOVE_FROM_NEW, "remove reservation from section 5");
  admin = replaceOnce(admin, HELPERS_OLD, HELPERS_NEW, "saveFreightFifoHold helper");
  admin = replaceOnce(admin, CLICK_OLD, CLICK_NEW, "hold save click");
  admin = replaceOnce(admin, SUCCESS_OLD, SUCCESS_NEW, "draft success hold label");
  admin = replaceOnce(admin, CHANGE_OLD, CHANGE_NEW, "hold change hint");
}

if (fifoHoldIsAfterShare(admin)) {
  admin = replaceOnce(admin, INSERT_BEFORE_NEW, INSERT_BEFORE_OLD, "remove hold from after LINE share");
}

if (admin.indexOf("⑤ 正式出貨資料") !== -1 && /⑤ 正式出貨資料<\/h4><div class="freight-fifo-reservation"/.test(admin)) {
  admin = replaceOnce(admin, MOVE_FROM_OLD, MOVE_FROM_NEW, "remove reservation from section 5");
}

if (!fifoHoldIsUnderShipping(admin)) {
  admin = replaceOnce(admin, AFTER_SHIP_OLD, AFTER_SHIP_NEW, "put hold under 超商/住家");
}

if (admin.indexOf(JUMP_OLD) !== -1) {
  admin = replaceOnce(admin, JUMP_OLD, JUMP_NEW, "jump note points at shipping field");
}

fs.writeFileSync(ADMIN_JS, admin, "utf8");
if (admin.indexOf(JS_MARKER) === -1) throw new Error("hold save button missing");
if (admin.indexOf("function saveFreightFifoHold") === -1) throw new Error("saveFreightFifoHold missing");
if (admin.indexOf("此單已在寄庫名單") === -1) throw new Error("hold status copy missing");
if (admin.indexOf("寄庫（先不出貨）") === -1) throw new Error("hold title missing");
if (!fifoHoldIsUnderShipping(admin)) throw new Error("hold is not under 超商/住家");
if (fifoHoldIsAfterShare(admin)) throw new Error("hold still after LINE share");
if (admin.indexOf("⑤ 正式出貨資料") !== -1 && /⑤ 正式出貨資料<\/h4><div class="freight-fifo-reservation"/.test(admin)) {
  throw new Error("reservation still under section 5");
}

console.log("backup admin css", backup(ADMIN_CSS, "fifo-hold"));
let css = fs.readFileSync(ADMIN_CSS, "utf8");
if (css.indexOf(CSS_MARKER) !== -1) {
  console.log("admin css hold button already patched");
} else {
  css = css.replace(/\s*$/, "\n") + CSS_APPEND;
  console.log("patched: hold button css");
}
if (css.indexOf(CSS_INLINE_MARKER) !== -1) {
  console.log("admin css inline hold already patched");
} else {
  css = css.replace(/\s*$/, "\n") + CSS_INLINE_APPEND;
  console.log("patched: hold inline under shipping css");
}
fs.writeFileSync(ADMIN_CSS, css, "utf8");

stampHtml(ROOT);
console.log("LINGZANZAN fifo hold button ok", STAMP);
