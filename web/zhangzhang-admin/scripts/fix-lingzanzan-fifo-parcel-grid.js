#!/usr/bin/env node
"use strict";

/**
 * 出貨單超商／物流包裹改成分隔線＋格子：單號、代收、貨態分開。
 * 不改 .is-active 金鈕，不 stamp 全站 HTML。
 *
 * Cache-bust: admin.js/css ?v=20260822-parcel-grid-1
 */

const fs = require("fs");
const path = require("path");
const {
  fifoParcelGridJsHasCells,
  fifoParcelGridJsKeepsStatusHook,
  fifoParcelGridJsRenumbersInHead,
  fifoParcelGridCssHasRules,
  fifoParcelGridDoesNotRestyleActive,
} = require("./lz-fifo-parcel-grid");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260822-parcel-grid-1";

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
  const names = ["admin-freight.html", "admin-reserved-shipping.html"];
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

const ROW_OLD = `    return '<div class="freight-fifo-parcel-row" data-freight-fifo-parcel-row>'
      + '<b>包裹 ' + (index + 1) + '</b>'
      + '<input data-freight-fifo-parcel-tracking' + firstTrackingAttr + ' value="' + escapeHtml(trackingNo) + '"' + disabled + ' placeholder="' + escapeHtml(placeholder) + '">'
      + '<input type="number" min="0" step="1" data-freight-fifo-parcel-amount' + autoAmountAttr + ' value="' + (amount > 0 ? amount : '') + '"' + disabled + ' placeholder="' + escapeHtml(amountPlaceholder) + '">'
      + '<em data-freight-fifo-parcel-status>' + escapeHtml(status) + '</em>'
      + (index === 0 ? '' : '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>')
      + '</div>';`;

const ROW_NEW = `    return '<div class="freight-fifo-parcel-row" data-freight-fifo-parcel-row>'
      + '<div class="freight-fifo-parcel-head">'
      + '<b>包裹 ' + (index + 1) + '</b>'
      + (index === 0 ? '' : '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>')
      + '</div>'
      + '<div class="freight-fifo-parcel-grid">'
      + '<label class="freight-fifo-parcel-cell"><span>物流單號</span>'
      + '<input data-freight-fifo-parcel-tracking' + firstTrackingAttr + ' value="' + escapeHtml(trackingNo) + '"' + disabled + ' placeholder="' + escapeHtml(placeholder) + '">'
      + '</label>'
      + '<label class="freight-fifo-parcel-cell"><span>代收金額</span>'
      + '<input type="number" min="0" step="1" data-freight-fifo-parcel-amount' + autoAmountAttr + ' value="' + (amount > 0 ? amount : '') + '"' + disabled + ' placeholder="' + escapeHtml(amountPlaceholder) + '">'
      + '</label>'
      + '</div>'
      + '<div class="freight-fifo-parcel-status"><span>貨態</span><em data-freight-fifo-parcel-status>' + escapeHtml(status || '尚未查到貨態') + '</em></div>'
      + '</div>';`;

const RENUM_OLD = `      if (index === 0) {
        if (remove) remove.remove();
      } else if (!remove) {
        row.insertAdjacentHTML('beforeend', '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>');
      }`;

const RENUM_NEW = `      if (index === 0) {
        if (remove) remove.remove();
      } else if (!remove) {
        var head = row.querySelector('.freight-fifo-parcel-head') || row;
        head.insertAdjacentHTML('beforeend', '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>');
      }`;

const CSS_OLD = `.freight-fifo-outbound-parcels {
  display: grid;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
  padding: 12px;
  border: 1px solid rgba(80, 229, 208, 0.4);
  border-radius: 14px;
  background: rgba(80, 229, 208, 0.07);
}
.freight-fifo-outbound-parcels > label {
  display: grid;
  gap: 4px;
  min-width: 0;
  color: #64f0cf;
  font-weight: 900;
}
.freight-fifo-outbound-parcels > label small {
  color: #c8becb;
  font-weight: 650;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
.freight-fifo-outbound-parcels [data-freight-fifo-parcel-rows] {
  display: grid;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
}
.freight-fifo-parcel-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) minmax(72px, 110px) auto;
  gap: 8px;
  align-items: center;
  min-width: 0;
  max-width: 100%;
  padding: 8px 9px;
  border: 1px solid rgba(255, 248, 237, 0.14);
  border-radius: 11px;
  background: rgba(12, 10, 14, 0.35);
}
.freight-fifo-parcel-row > b {
  color: #ffe09a;
  white-space: nowrap;
}
.freight-fifo-parcel-row input {
  min-width: 0;
  width: 100%;
  max-width: 100%;
}
.freight-fifo-parcel-row em {
  grid-column: 1 / -1;
  min-width: 0;
  color: #b9f6ca;
  font-style: normal;
  font-weight: 800;
  line-height: 1.4;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.freight-fifo-parcel-row [data-freight-fifo-parcel-remove] {
  justify-self: end;
  white-space: nowrap;
}`;

const CSS_NEW = `/* 20260822 parcel grid: 超商／物流包裹分隔線與格子 */
.freight-fifo-outbound-parcels {
  display: grid;
  gap: 12px;
  min-width: 0;
  max-width: 100%;
  padding: 14px;
  border: 1px solid rgba(80, 229, 208, 0.45);
  border-radius: 14px;
  background: rgba(80, 229, 208, 0.07);
}
.freight-fifo-outbound-parcels > label {
  display: grid;
  gap: 4px;
  min-width: 0;
  color: #64f0cf;
  font-weight: 900;
}
.freight-fifo-outbound-parcels > label small {
  color: #c8becb;
  font-weight: 650;
  line-height: 1.45;
  overflow-wrap: anywhere;
}
.freight-fifo-outbound-parcels [data-freight-fifo-parcel-rows] {
  display: grid;
  gap: 0;
  min-width: 0;
  max-width: 100%;
  border: 1px solid rgba(255, 248, 237, 0.18);
  border-radius: 12px;
  overflow: hidden;
  background: rgba(12, 10, 14, 0.28);
}
.freight-fifo-parcel-row {
  display: grid;
  gap: 0;
  min-width: 0;
  max-width: 100%;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
}
.freight-fifo-parcel-row + .freight-fifo-parcel-row {
  border-top: 1px solid rgba(255, 248, 237, 0.16);
}
.freight-fifo-parcel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 12px;
  border-bottom: 1px solid rgba(255, 248, 237, 0.14);
  background: rgba(244, 189, 77, 0.08);
}
.freight-fifo-parcel-head > b {
  color: #ffe09a;
  white-space: nowrap;
  font-weight: 900;
}
.freight-fifo-parcel-head [data-freight-fifo-parcel-remove] {
  justify-self: end;
  white-space: nowrap;
}
.freight-fifo-parcel-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(112px, 0.7fr);
  min-width: 0;
}
.freight-fifo-parcel-cell {
  display: grid;
  gap: 5px;
  min-width: 0;
  margin: 0;
  padding: 10px 12px;
  color: #ffe09a;
  font-weight: 800;
}
.freight-fifo-parcel-cell + .freight-fifo-parcel-cell {
  border-left: 1px solid rgba(255, 248, 237, 0.16);
}
.freight-fifo-parcel-cell > span {
  color: #c8becb;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
}
.freight-fifo-parcel-row input {
  min-width: 0;
  width: 100%;
  max-width: 100%;
}
.freight-fifo-parcel-status {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 8px 12px;
  align-items: start;
  padding: 9px 12px 11px;
  border-top: 1px dashed rgba(100, 240, 207, 0.38);
  color: #c8becb;
  font-size: 12px;
  font-weight: 700;
}
.freight-fifo-parcel-status em {
  min-width: 0;
  color: #b9f6ca;
  font-style: normal;
  font-weight: 800;
  line-height: 1.4;
  overflow-wrap: anywhere;
  word-break: break-word;
}
@media (max-width: 720px) {
  .freight-fifo-parcel-grid {
    grid-template-columns: 1fr;
  }
  .freight-fifo-parcel-cell + .freight-fifo-parcel-cell {
    border-left: 0;
    border-top: 1px solid rgba(255, 248, 237, 0.14);
  }
  .freight-fifo-parcel-head [data-freight-fifo-parcel-remove] {
    justify-self: stretch;
  }
}`;

if (!fifoParcelGridDoesNotRestyleActive(CSS_NEW) || !fifoParcelGridDoesNotRestyleActive(ROW_NEW)) {
  throw new Error("refusing to restyle .is-active");
}

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(ADMIN_CSS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "parcel-grid");
backup(ADMIN_CSS, "parcel-grid");
let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, ROW_OLD, ROW_NEW, "parcel row labeled grid");
js = replaceOnce(js, RENUM_OLD, RENUM_NEW, "renumber insert remove into head");
fs.writeFileSync(ADMIN_JS, js);

let css = fs.readFileSync(ADMIN_CSS, "utf8");
css = replaceOnce(css, CSS_OLD, CSS_NEW, "parcel separators and grid css");
fs.writeFileSync(ADMIN_CSS, css);

if (!fifoParcelGridJsHasCells(js)) throw new Error("parcel cells missing after js patch");
if (!fifoParcelGridJsKeepsStatusHook(js)) throw new Error("parcel data hooks missing after js patch");
if (!fifoParcelGridJsRenumbersInHead(js)) throw new Error("renumber head missing after js patch");
if (!fifoParcelGridCssHasRules(css)) throw new Error("parcel grid css missing after css patch");

stampHtml(ROOT);
console.log("LINGZANZAN fifo parcel grid ok", STAMP);
