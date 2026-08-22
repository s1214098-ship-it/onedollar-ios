#!/usr/bin/env node
"use strict";

/**
 * 出貨單包裹改成表格清單：表頭＋一包一列，單號／代收／貨態對齊。
 * 不再做成卡片蛇形堆疊。不改 .is-active 金鈕。
 *
 * Cache-bust: admin.js/css ?v=20260822-parcel-list-1
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
const STAMP = "20260822-parcel-list-1";

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

const ROW_NEW = `    return '<div class="freight-fifo-parcel-row" data-freight-fifo-parcel-row>'
      + '<b>' + (index + 1) + '</b>'
      + '<input data-freight-fifo-parcel-tracking' + firstTrackingAttr + ' value="' + escapeHtml(trackingNo) + '"' + disabled + ' placeholder="' + escapeHtml(placeholder) + '">'
      + '<input type="number" min="0" step="1" data-freight-fifo-parcel-amount' + autoAmountAttr + ' value="' + (amount > 0 ? amount : '') + '"' + disabled + ' placeholder="' + escapeHtml(amountPlaceholder) + '">'
      + '<em data-freight-fifo-parcel-status>' + escapeHtml(status || '尚未查到貨態') + '</em>'
      + (index === 0 ? '<span class="freight-fifo-parcel-action"></span>' : '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>')
      + '</div>';`;

const WRAP_OLD = `      + '<div data-freight-fifo-parcel-rows>' + parcels.map(function (parcel, index) {
        return freightFifoParcelRowHtml(parcel, index, options);
      }).join('') + '</div>'`;

const WRAP_NEW = `      + '<div data-freight-fifo-parcel-rows>'
      + '<div class="freight-fifo-parcel-thead"><span>包裹</span><span>物流單號</span><span>代收金額</span><span>貨態</span><span></span></div>'
      + parcels.map(function (parcel, index) {
        return freightFifoParcelRowHtml(parcel, index, options);
      }).join('') + '</div>'`;

const TITLE_OLD = `      if (title) title.textContent = '包裹 ' + (index + 1);`;
const TITLE_NEW = `      if (title) title.textContent = String(index + 1);`;

const RENUM_OLD = `      if (index === 0) {
        if (remove) remove.remove();
      } else if (!remove) {
        var head = row.querySelector('.freight-fifo-parcel-head') || row;
        head.insertAdjacentHTML('beforeend', '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>');
      }`;

const RENUM_NEW = `      if (index === 0) {
        if (remove) remove.remove();
        if (!row.querySelector('.freight-fifo-parcel-action')) {
          row.insertAdjacentHTML('beforeend', '<span class="freight-fifo-parcel-action"></span>');
        }
      } else if (!remove) {
        var placeholder = row.querySelector('.freight-fifo-parcel-action');
        if (placeholder) placeholder.remove();
        row.insertAdjacentHTML('beforeend', '<button type="button" class="ghost-button" data-freight-fifo-parcel-remove>刪這包</button>');
      }`;

const ADD_OLD = `    wrap.insertAdjacentHTML('beforeend', freightFifoParcelRowHtml({}, wrap.children.length, { disabled: disabled }));`;
const ADD_NEW = `    var count = wrap.querySelectorAll('[data-freight-fifo-parcel-row]').length;
    wrap.insertAdjacentHTML('beforeend', freightFifoParcelRowHtml({}, count, { disabled: disabled }));`;

const CSS_OLD = `/* 20260822 parcel grid: 超商／物流包裹分隔線與格子 */
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

const CSS_NEW = `/* 20260822 parcel list: 表頭＋一包一列，給業務截圖用 */
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
  overflow-x: auto;
  border: 1px solid rgba(255, 248, 237, 0.22);
  border-radius: 10px;
  background: rgba(12, 10, 14, 0.35);
}
.freight-fifo-parcel-thead,
.freight-fifo-parcel-row {
  display: grid;
  grid-template-columns: 52px minmax(168px, 1.35fr) 108px minmax(160px, 1.45fr) 76px;
  align-items: stretch;
  min-width: 640px;
  max-width: none;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
}
.freight-fifo-parcel-thead {
  background: rgba(244, 189, 77, 0.16);
  border-bottom: 1px solid rgba(255, 248, 237, 0.22);
  color: #ffe09a;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.04em;
}
.freight-fifo-parcel-thead > span,
.freight-fifo-parcel-row > * {
  min-width: 0;
  padding: 10px 10px;
  border-right: 1px solid rgba(255, 248, 237, 0.16);
}
.freight-fifo-parcel-thead > span:last-child,
.freight-fifo-parcel-row > *:last-child {
  border-right: 0;
}
.freight-fifo-parcel-row + .freight-fifo-parcel-row {
  border-top: 1px solid rgba(255, 248, 237, 0.16);
}
.freight-fifo-parcel-row > b {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #ffe09a;
  font-weight: 900;
}
.freight-fifo-parcel-row input {
  min-width: 0;
  width: 100%;
  max-width: 100%;
  align-self: center;
}
.freight-fifo-parcel-row em {
  display: flex;
  align-items: center;
  min-width: 0;
  color: #b9f6ca;
  font-style: normal;
  font-weight: 800;
  line-height: 1.4;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.freight-fifo-parcel-row [data-freight-fifo-parcel-remove],
.freight-fifo-parcel-row .freight-fifo-parcel-action {
  display: flex;
  align-items: center;
  justify-content: center;
  white-space: nowrap;
}`;

const SNAKE_OLD = `@media (max-width: 720px) {
  .freight-fifo-parcel-row {
    grid-template-columns: 1fr;
  }
  .freight-fifo-parcel-row [data-freight-fifo-parcel-remove] {
    justify-self: stretch;
  }
}`;

const SNAKE_NEW = `/* keep parcel list columns aligned for screenshots; do not stack into a snake */`;

if (!fifoParcelGridDoesNotRestyleActive(CSS_NEW) || !fifoParcelGridDoesNotRestyleActive(ROW_NEW)) {
  throw new Error("refusing to restyle .is-active");
}

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(ADMIN_CSS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "parcel-list");
backup(ADMIN_CSS, "parcel-list");
let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, ROW_OLD, ROW_NEW, "parcel row table cells");
js = replaceOnce(js, WRAP_OLD, WRAP_NEW, "parcel table header");
js = replaceOnce(js, TITLE_OLD, TITLE_NEW, "renumber as list index");
js = replaceOnce(js, RENUM_OLD, RENUM_NEW, "renumber keep action column");
js = replaceOnce(js, ADD_OLD, ADD_NEW, "add row counts parcels not header");
fs.writeFileSync(ADMIN_JS, js);

let css = fs.readFileSync(ADMIN_CSS, "utf8");
css = replaceOnce(css, CSS_OLD, CSS_NEW, "parcel table list css");
css = replaceOnce(css, SNAKE_OLD, SNAKE_NEW, "remove snake stack media query");
fs.writeFileSync(ADMIN_CSS, css);

if (!fifoParcelGridJsHasCells(js)) throw new Error("parcel table header missing after js patch");
if (!fifoParcelGridJsKeepsStatusHook(js)) throw new Error("parcel data hooks missing after js patch");
if (!fifoParcelGridJsRenumbersInHead(js)) throw new Error("parcel row counting missing after js patch");
if (!fifoParcelGridCssHasRules(css)) throw new Error("parcel table css missing after css patch");

stampHtml(ROOT);
console.log("LINGZANZAN fifo parcel list ok", STAMP);
