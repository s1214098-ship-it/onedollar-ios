#!/usr/bin/env node
"use strict";

/**
 * 寄庫要出現在⑤正式出貨資料（物流單號上面），底部也要有「加入寄庫名單」。
 * ②超商／住家只留跳轉鈕，避免再把收款欄撐破。
 *
 * Live: assets/admin.js, assets/admin.css
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const {
  fifoHoldIsInSection5,
  fifoHoldHasShortcut,
  fifoHoldHasFooterSave,
  fifoHoldIsUnderShipping,
} = require("./lz-fifo-hold-button");
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260822-fifo-hold-4";
const JS_MARKER = "data-freight-fifo-hold-jump-btn";
const CSS_MARKER = "/* 20260822 fifo hold in section ⑤ and footer */";

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

const HOLD_V2 = `<div class="freight-fifo-reservation" data-freight-fifo-hold-panel><header><div><b>寄庫（先不出貨）</b><small>選原因後按「加入寄庫名單」。這不是超商／住家選項，地址仍照上面選的出。預約排單才填日期。</small></div><span data-freight-fifo-hold-status' + (fifoHoldActive ? ' class="is-on-hold"' : '') + '>' + escapeHtml(fifoHoldStatusCopy) + '</span></header><div><label>寄庫原因' + reservedHoldReasonSelectHtml(reservedHoldReason, 'data-freight-fifo-hold-reason') + '</label><label data-freight-fifo-hold-reason-other-wrap' + (reservedHoldReason === 'other' ? '' : ' hidden') + '>其他原因<input value="' + escapeHtml(reservedHoldReason === 'other' ? reservedHoldReasonText : '') + '" data-freight-fifo-hold-reason-other placeholder="請填寫其他原因"></label><div class="freight-fifo-reservation-actions"><button type="button" class="primary-button" data-freight-fifo-hold-save>' + escapeHtml(fifoHoldSaveCopy) + '</button><small>選寄庫原因後按這顆即可。</small></div><p data-freight-fifo-hold-result></p><label>預約出貨日期<span class="reservation-date-picker"><input type="date" min="' + escapeHtml(reservationMinDate) + '" value="' + escapeHtml(reservedShippingDate) + '" data-freight-fifo-reserved-date><button type="button" class="ghost-button reservation-calendar-button" data-open-reservation-calendar>📅 開啟日曆</button></span></label><label>預約備註<input value="' + escapeHtml(reservedShippingNote) + '" data-freight-fifo-reserved-note placeholder="例如：客戶指定週五一起出"></label></div></div>`;

const HOLD_SHORTCUT = `<div class="freight-fifo-hold-shortcut"><button type="button" class="primary-button" data-freight-fifo-hold-jump-btn>寄庫（先不出貨）</button><small>寄庫在下面⑤正式出貨資料。按這顆會跳過去選原因、加入寄庫名單。</small></div>`;

const FROM_LEFT_OLD = `}, { textarea: true, hiddenAttrs: 'data-freight-fifo-address' }) + '` + HOLD_V2 + `' + '<small data-freight-fifo-delivery-hint>'`;
const FROM_LEFT_NEW = `}, { textarea: true, hiddenAttrs: 'data-freight-fifo-address' }) + '` + HOLD_SHORTCUT + `' + '<small data-freight-fifo-delivery-hint>'`;

const TO_SECTION5_OLD = `<h4 class="freight-fifo-shipping-title">⑤ 正式出貨資料</h4><p class="freight-fifo-hold-jump">寄庫在上面②「超商出貨／住家出貨」欄下面，選原因後按「加入寄庫名單」。這裡只填物流公司與單號。</p><div class="freight-fifo-shipping-fields">`;
const TO_SECTION5_NEW = `<h4 class="freight-fifo-shipping-title">⑤ 正式出貨資料</h4>` + HOLD_V2 + `<div class="freight-fifo-shipping-fields">`;

const FOOTER_OLD = `    var finalActionsBar = modal.querySelector('.freight-fifo-final-actions');
    if (finalActionsBar && !finalActionsBar.querySelector('[data-freight-fifo-save-draft]')) {
      var confirmForDraft = finalActionsBar.querySelector('[data-freight-fifo-confirm]');
      var draftButtonHtml = '<button type="button" class="ghost-button" data-freight-fifo-save-draft>只存檔</button>';
      if (confirmForDraft) confirmForDraft.insertAdjacentHTML('beforebegin', draftButtonHtml);
      else finalActionsBar.insertAdjacentHTML('beforeend', draftButtonHtml);
    }`;

const FOOTER_NEW = `    var finalActionsBar = modal.querySelector('.freight-fifo-final-actions');
    if (finalActionsBar && !finalActionsBar.querySelector('[data-freight-fifo-save-draft]')) {
      var confirmForDraft = finalActionsBar.querySelector('[data-freight-fifo-confirm]');
      var draftButtonHtml = '<button type="button" class="ghost-button" data-freight-fifo-save-draft>只存檔</button>';
      if (confirmForDraft) confirmForDraft.insertAdjacentHTML('beforebegin', draftButtonHtml);
      else finalActionsBar.insertAdjacentHTML('beforeend', draftButtonHtml);
    }
    if (finalActionsBar && !finalActionsBar.querySelector('[data-freight-fifo-hold-footer]')) {
      var holdFooterHtml = '<button type="button" class="primary-button" data-freight-fifo-hold-save data-freight-fifo-hold-footer>' + escapeHtml(fifoHoldSaveCopy) + '</button>';
      var confirmForHold = finalActionsBar.querySelector('[data-freight-fifo-confirm]');
      if (confirmForHold) confirmForHold.insertAdjacentHTML('beforebegin', holdFooterHtml);
      else finalActionsBar.insertAdjacentHTML('beforeend', holdFooterHtml);
    }`;

const CLICK_OLD = `      var freightFifoHoldSave = event.target.closest ? event.target.closest('[data-freight-fifo-hold-save]') : null;
      if (freightFifoHoldSave) {
        event.preventDefault();
        saveFreightFifoHold(freightFifoHoldSave);
        return;
      }`;

const CLICK_NEW = `      var freightFifoHoldJump = event.target.closest ? event.target.closest('[data-freight-fifo-hold-jump-btn]') : null;
      if (freightFifoHoldJump) {
        event.preventDefault();
        var holdModal = freightFifoHoldJump.closest('[data-freight-fifo-modal]') || document.querySelector('[data-freight-fifo-modal]');
        var holdPanel = holdModal && holdModal.querySelector('[data-freight-fifo-hold-panel]');
        if (holdPanel) {
          holdPanel.classList.add('is-flash');
          if (holdPanel.scrollIntoView) holdPanel.scrollIntoView({ behavior: 'smooth', block: 'center' });
          window.setTimeout(function () { holdPanel.classList.remove('is-flash'); }, 1800);
        }
        return;
      }
      var freightFifoHoldSave = event.target.closest ? event.target.closest('[data-freight-fifo-hold-save]') : null;
      if (freightFifoHoldSave) {
        event.preventDefault();
        saveFreightFifoHold(freightFifoHoldSave);
        return;
      }`;

const CSS_APPEND = `

${CSS_MARKER}
.freight-fifo-hold-shortcut {
  display: grid;
  gap: 6px;
  margin-top: 8px;
  grid-column: 1 / -1;
  min-width: 0;
  max-width: 100%;
}
.freight-fifo-hold-shortcut small {
  color: #ffe19a;
  font-weight: 800;
  line-height: 1.4;
}
[data-freight-fifo-hold-jump-btn] {
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
[data-freight-fifo-hold-panel].is-flash {
  box-shadow: 0 0 0 3px #f4bd4d, 0 0 18px rgba(244, 189, 77, .55);
}
.freight-fifo-shipping-title {
  scroll-margin-top: 12px;
}
`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup admin js", backup(ADMIN_JS, "fifo-hold-section5"));
let admin = fs.readFileSync(ADMIN_JS, "utf8");
if (fifoHoldIsInSection5(admin) && fifoHoldHasShortcut(admin) && fifoHoldHasFooterSave(admin)) {
  console.log("admin js already patched");
} else {
  if (fifoHoldIsUnderShipping(admin)) {
    admin = replaceOnce(admin, FROM_LEFT_OLD, FROM_LEFT_NEW, "② shortcut instead of full panel");
  } else if (!fifoHoldHasShortcut(admin)) {
    throw new Error("cannot find hold panel under ② to convert into shortcut");
  } else {
    console.log("already: ② shortcut");
  }
  if (!fifoHoldIsInSection5(admin)) {
    admin = replaceOnce(admin, TO_SECTION5_OLD, TO_SECTION5_NEW, "put hold panel in section ⑤");
  } else {
    console.log("already: section ⑤ panel");
  }
  if (!fifoHoldHasFooterSave(admin)) {
    admin = replaceOnce(admin, FOOTER_OLD, FOOTER_NEW, "footer 加入寄庫名單");
  } else {
    console.log("already: footer hold save");
  }
  if (admin.indexOf("freightFifoHoldJump") === -1) {
    admin = replaceOnce(admin, CLICK_OLD, CLICK_NEW, "jump click scrolls to ⑤");
  } else {
    console.log("already: jump click");
  }
}

if (!fifoHoldIsInSection5(admin)) throw new Error("hold panel not in section ⑤");
if (!fifoHoldHasShortcut(admin)) throw new Error("② shortcut missing");
if (!fifoHoldHasFooterSave(admin)) throw new Error("footer 加入寄庫名單 missing");
if (fifoHoldIsUnderShipping(admin)) throw new Error("full hold panel still under ②");
if (admin.indexOf("function saveFreightFifoHold") === -1) throw new Error("saveFreightFifoHold missing");
fs.writeFileSync(ADMIN_JS, admin, "utf8");
console.log("js written", ADMIN_JS, "len", admin.length);

console.log("backup admin css", backup(ADMIN_CSS, "fifo-hold-section5"));
let css = fs.readFileSync(ADMIN_CSS, "utf8");
if (css.indexOf(CSS_MARKER) !== -1) {
  console.log("admin css already patched");
} else {
  css = css.replace(/\s*$/, "\n") + CSS_APPEND;
  console.log("patched: section ⑤ hold css");
}
fs.writeFileSync(ADMIN_CSS, css, "utf8");
if (css.indexOf(CSS_MARKER) === -1) throw new Error("section ⑤ hold css missing");

stampHtml(ROOT);
console.log("LINGZANZAN fifo hold in section ⑤ ok", STAMP);
