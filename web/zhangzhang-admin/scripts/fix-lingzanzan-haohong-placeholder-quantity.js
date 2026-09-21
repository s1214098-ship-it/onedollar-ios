#!/usr/bin/env node
"use strict";

/**
 * 豪鴻／微信廠商佔位單（HAOHONG-PACKAGE-*，沒有顏色尺寸）到貨核對：
 * 1) 「新增不同產品到待批准」不再因沒有 SKU 直接 return；提示也不再被彈窗擋住。
 * 2) 沒有實收欄時不准把空 lines 送出，畫面改成佔位說明。
 * 3) freight.html 的 .admin-toast 原本沒有 CSS，工作列按鈕看起來像沒反應。
 *
 * Cache-bust: admin.js / admin.css ?v=20260921-haohong-placeholder-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260921-haohong-placeholder-1";
const JS_MARKER = "has-placeholder-source";
const CSS_MARKER = "/* 20260921 haohong placeholder quantity toast above receiving modal */";

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

const TOAST_OLD = `  function toast(message) {
    var el = document.querySelector('[data-admin-toast]');
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () {
      el.hidden = true;
    }, 2200);
  }`;

const TOAST_NEW = `  function toast(message) {
    var el = document.querySelector('[data-admin-toast]');
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
    el.style.zIndex = '30000';
    el.style.position = 'fixed';
    el.style.left = '50%';
    el.style.bottom = '22px';
    el.style.transform = 'translateX(-50%)';
    el.style.display = 'block';
    el.style.pointerEvents = 'none';
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () {
      el.hidden = true;
      el.style.display = '';
    }, 2800);
  }`;

const TITLE_OLD = `'<label>產品名稱<input type="text" data-freight-supplemental-new-title value="' + escapeHtml(parent.productName || '') + '" placeholder="輸入品名"></label>',`;

const TITLE_NEW = `'<label>產品名稱<input type="text" data-freight-supplemental-new-title value="' + escapeHtml((/^HAOHONG-PACKAGE-/i.test(String(parent.id || '')) || !freightVariantLines(parent).length) ? '' : (parent.productName || '')) + '" placeholder="輸入實物品名，不要填廠商名稱"></label>',`;

const SUPP_EARLY_OLD = `    var lines = freightVariantLines(parent);
    if (!lines.length) { toast('原物流沒有可追加的產品規格，請先到產品物流明細核對'); return; }
    var old = document.querySelector('[data-freight-supplemental-modal]');`;

const SUPP_EARLY_NEW = `    var lines = freightVariantLines(parent);
    var old = document.querySelector('[data-freight-supplemental-modal]');`;

const SUPP_MAP_OLD = `<div class="freight-supplemental-lines">' + lines.map(function (line, index) {`;

const SUPP_MAP_NEW = `<div class="freight-supplemental-lines">' + (lines.map(function (line, index) {`;

const SUPP_LINES_OLD = `}).join('') + '</div><label class="freight-supplemental-note">拆包備註`;

const SUPP_LINES_NEW = `}).join('') || '<article class="is-placeholder"><div><b>原物流還沒有顏色尺寸</b><span>豪鴻／微信廠商佔位單只帶廠商或品名。請用上方「同單號不同產品」把實物建檔；不要把廠商名稱當產品入庫。</span></div></article>') + '</div><label class="freight-supplemental-note">拆包備註`;

const SUPP_END_OLD = `    bindFreightSupplementalNewProductSearch(modal);
    updateTotal();
  }

  function createFreightSupplementalReceipt(parentId, control) {`;

const SUPP_END_NEW = `    bindFreightSupplementalNewProductSearch(modal);
    updateTotal();
    if (!lines.length) {
      modal.classList.add('has-placeholder-source');
      var createBtn = modal.querySelector('[data-freight-supplemental-create]');
      if (createBtn) createBtn.hidden = true;
      var sourceBox = modal.querySelector('.freight-supplemental-source small');
      if (sourceBox) sourceBox.textContent = '這張是佔位單，還沒有顏色尺寸；請用上方同單號不同產品建檔';
    }
  }

  function createFreightSupplementalReceipt(parentId, control) {`;

const QTY_MAP_OLD = `'<div class="freight-quantity-confirm-lines">' + lines.map(function (line, lineIndex) {`;

const QTY_MAP_NEW = `'<div class="freight-quantity-confirm-lines">' + (lines.map(function (line, lineIndex) {`;

const QTY_LINES_OLD = `        }).join('') + '</div>' +
        '<label class="freight-receiving-reason">數量不同原因（可先由系統自動註記）`;

const QTY_LINES_NEW = `        }).join('') || '<article class="is-placeholder"><div><b>這張還沒有顏色尺寸可點貨</b><small>豪鴻重量單／微信廠商佔位單沒有 SKU。請按右上「新增不同產品到待批准」把實物建檔後再核對；不要把廠商品名當產品入庫。</small></div></article>') + '</div>' +
        '<label class="freight-receiving-reason">數量不同原因（可先由系統自動註記）`;

const UPDATE_OLD = `      if (output) output.textContent = completed === inputs.length
        ? '本張實際收到合計 ' + total + ' 件；只以實收建檔與配貨，未到數量會保留追蹤'
        : '尚有 ' + (inputs.length - completed) + ' 個配色／尺寸未填實收數量';`;

const UPDATE_NEW = `      if (output) {
        if (!inputs.length) {
          output.textContent = '這張還沒有顏色尺寸可點貨。請先按右上「新增不同產品到待批准」把實物建檔，不要把佔位品名當產品入庫。';
          output.setAttribute('data-state', 'error');
        } else {
          output.textContent = completed === inputs.length
            ? '本張實際收到合計 ' + total + ' 件；只以實收建檔與配貨，未到數量會保留追蹤'
            : '尚有 ' + (inputs.length - completed) + ' 個配色／尺寸未填實收數量';
        }
      }`;

const SAVE_OLD = `    var statusOutput = modal.querySelector('[data-freight-quantity-total]');
    if (blank || invalid) {`;

const SAVE_NEW = `    var statusOutput = modal.querySelector('[data-freight-quantity-total]');
    if (!actualInputs.length) {
      if (statusOutput) {
        statusOutput.textContent = '尚未儲存：這張還沒有顏色尺寸可點貨。請先按「新增不同產品到待批准」把實物建檔。';
        statusOutput.setAttribute('data-state', 'error');
      }
      toast('這張還沒有顏色尺寸。請先新增實際收到的產品，再核對數量');
      return;
    }
    if (blank || invalid) {`;

const REQUESTS_OLD = `    });
    control.disabled = true;
    control.textContent = '正在暫存本次實收數量…';`;

const REQUESTS_NEW = `    }).filter(function (request) {
      return ((request.body && request.body.lines) || []).length > 0;
    });
    if (!requests.length) {
      if (statusOutput) {
        statusOutput.textContent = '尚未儲存：這張還沒有可點貨的顏色尺寸。請先新增實際收到的產品。';
        statusOutput.setAttribute('data-state', 'error');
      }
      toast('這張還沒有顏色尺寸。請先新增實際收到的產品，再核對數量');
      return;
    }
    control.disabled = true;
    control.textContent = '正在暫存本次實收數量…';`;

const CSS_APPEND = `
${CSS_MARKER}
.admin-toast,
[data-admin-toast] {
  position: fixed !important;
  left: 50% !important;
  bottom: 22px !important;
  z-index: 30000 !important;
  transform: translateX(-50%) !important;
  border-radius: 999px;
  padding: 12px 18px;
  color: #110d12;
  background: var(--gold);
  box-shadow: 0 20px 44px rgba(0,0,0,.26);
  font-weight: 950;
  pointer-events: none;
}
.admin-toast[hidden],
[data-admin-toast][hidden] {
  display: none !important;
}
.freight-quantity-confirm-lines article.is-placeholder,
.freight-supplemental-lines article.is-placeholder {
  display: grid;
  gap: 6px;
  padding: 14px 16px;
  border: 1px dashed #d4a73a;
  border-radius: 12px;
  background: #fff8e6;
  color: #5c3b08;
}
.freight-quantity-confirm-lines article.is-placeholder b,
.freight-supplemental-lines article.is-placeholder b {
  color: #5c3b08;
}
.freight-supplemental-modal.has-placeholder-source [data-freight-supplemental-create] {
  display: none !important;
}
`;

if (!fs.existsSync(JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "haohong-placeholder"));
let js = fs.readFileSync(JS, "utf8");
js = replaceOnce(js, TOAST_OLD, TOAST_NEW, "toast above workbench");
js = replaceOnce(js, TITLE_OLD, TITLE_NEW, "placeholder product title empty");
js = replaceOnce(js, SUPP_EARLY_OLD, SUPP_EARLY_NEW, "open supplemental without sku lines");
js = replaceOnce(js, SUPP_MAP_OLD, SUPP_MAP_NEW, "supplemental lines paren");
js = replaceOnce(js, SUPP_LINES_OLD, SUPP_LINES_NEW, "supplemental empty-state");
js = replaceOnce(js, SUPP_END_OLD, SUPP_END_NEW, "hide split-qty create on placeholder");
js = replaceOnce(js, QTY_MAP_OLD, QTY_MAP_NEW, "quantity lines paren");
js = replaceOnce(js, QTY_LINES_OLD, QTY_LINES_NEW, "quantity modal empty-state");
js = replaceOnce(js, UPDATE_OLD, UPDATE_NEW, "quantity total placeholder copy");
js = replaceOnce(js, SAVE_OLD, SAVE_NEW, "refuse empty quantity save");
js = replaceOnce(js, REQUESTS_OLD, REQUESTS_NEW, "skip empty inspection groups");
try {
  new Function(js);
  console.log("js syntax ok");
} catch (error) {
  throw new Error("admin.js syntax: " + error.message);
}
if (js.indexOf(JS_MARKER) === -1) throw new Error("placeholder marker missing");
if (js.indexOf("原物流沒有可追加的產品規格") !== -1) throw new Error("early return still present");
fs.writeFileSync(JS, js, "utf8");
console.log("admin.js written", JS, "len", js.length);

if (!fs.existsSync(CSS)) throw new Error("missing admin.css");
console.log("backup css", backup(CSS, "haohong-placeholder"));
let css = fs.readFileSync(CSS, "utf8");
if (css.indexOf(CSS_MARKER) !== -1) {
  console.log("admin css already patched");
} else {
  css = css.replace(/\s*$/, "\n") + CSS_APPEND;
  console.log("patched: toast + placeholder css");
}
fs.writeFileSync(CSS, css, "utf8");
if (css.indexOf(CSS_MARKER) === -1) throw new Error("placeholder css missing");

stampHtml(ROOT);
console.log("LINGZANZAN haohong placeholder quantity ok", STAMP);
