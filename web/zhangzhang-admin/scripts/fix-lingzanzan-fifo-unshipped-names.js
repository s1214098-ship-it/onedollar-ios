#!/usr/bin/env node
"use strict";

/**
 * FIFO 列印頁加「還沒出貨會員」名字清單，逗號分隔，可複製貼 LINE。
 * 含待配貨／已選／等通知／未交寄；不含配送中。出貨單仍可開。
 */

const fs = require("fs");
const path = require("path");
const {
  fifoPrintHasUnshippedNamesBox,
  fifoBoardHasCopyUnshippedNames,
} = require("./lz-fifo-unshipped-names");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const STAMP = "20260822-unshipped-names-1";

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
    if (html.indexOf("admin.js") === -1) return;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const HELPER_OLD = `  function printFreightFifoStageList(stage) {`;

const HELPER_NEW = `  function freightFifoRowMemberPhone(row) {
    row = row || {};
    var customer = row.customer || {};
    return String(customer.phone || row.customerPhone || row.phone || '').replace(/\\D/g, '');
  }

  function freightFifoRowMemberName(row) {
    row = row || {};
    var customer = row.customer || {};
    return String(customer.name || row.customerName || customer.displayName || '').trim();
  }

  function freightFifoLinePasteBlocked(row) {
    var phone = freightFifoRowMemberPhone(row);
    var id = String(row && (row.id || row.sourceInquiryId || row.convertedFromInquiryId || row.inquiryId) || '');
    return phone === '0937476065' || id === 'BYORDER-20260720-078596';
  }

  function freightFifoUnshippedMemberNames(printRows) {
    printRows = printRows || freightFifoStagePrintRows || {};
    var seen = {};
    var names = [];
    ['waiting', 'selected', 'wait_notify', 'formal'].forEach(function (stageName) {
      (printRows[stageName] || []).forEach(function (row) {
        if (!row || freightFifoLinePasteBlocked(row)) return;
        var name = freightFifoRowMemberName(row);
        if (!name || name === '未填客戶') return;
        var key = freightFifoRowMemberPhone(row) || name.toLowerCase();
        if (seen[key]) return;
        seen[key] = true;
        names.push(name);
      });
    });
    return names;
  }

  function copyFreightFifoUnshippedNames() {
    var names = freightFifoUnshippedMemberNames(freightFifoStagePrintRows);
    var text = names.join('，');
    if (!text) {
      toast('目前沒有還沒出貨的會員名字可複製');
      return;
    }
    function copied() {
      toast('已複製 ' + names.length + ' 位還沒出貨名字，可直接貼 LINE');
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(copied).catch(function () {
        window.prompt('複製下面名字後貼到 LINE', text);
      });
      return;
    }
    window.prompt('複製下面名字後貼到 LINE', text);
    copied();
  }

  function printFreightFifoStageList(stage) {`;

const BUTTON_OLD = `    return '<button type="button" class="ghost-button freight-fifo-print-stage" data-freight-fifo-print-stage="' + escapeHtml(stage) + '">列印給業務</button>';`;

const BUTTON_NEW = `    return '<button type="button" class="ghost-button freight-fifo-print-stage" data-freight-fifo-print-stage="' + escapeHtml(stage) + '">列印給業務</button><button type="button" class="ghost-button" data-freight-fifo-copy-unshipped-names>複製還沒出名字</button>';`;

const CLICK_OLD = `      if (printStageButton) {
        event.preventDefault();
        event.stopPropagation();
        printFreightFifoStageList(printStageButton.getAttribute('data-freight-fifo-print-stage') || '');
        return;
      }`;

const CLICK_NEW = `      if (printStageButton) {
        event.preventDefault();
        event.stopPropagation();
        printFreightFifoStageList(printStageButton.getAttribute('data-freight-fifo-print-stage') || '');
        return;
      }
      var copyUnshippedNames = event.target.closest ? event.target.closest('[data-freight-fifo-copy-unshipped-names]') : null;
      if (copyUnshippedNames) {
        event.preventDefault();
        event.stopPropagation();
        copyFreightFifoUnshippedNames();
        return;
      }`;

const STYLE_OLD = `footer label{border:1px solid #333;padding:4px 8px;font-weight:800}.no-print{float:right;padding:8px 14px;font-size:14px}@media print{body{margin:0}.no-print{display:none!important}}`;

const STYLE_NEW = `footer label{border:1px solid #333;padding:4px 8px;font-weight:800}.line-names{border:1.4px solid #111;border-radius:8px;padding:10px 12px;margin:0 0 12px;background:#f7f7f7}.line-names header{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin-bottom:8px}.line-names b{font-size:15px}.line-names small{display:block;color:#555;margin-top:2px}.line-names textarea{width:100%;min-height:72px;font-size:16px;line-height:1.55;padding:8px;border:1px solid #888;border-radius:6px}.line-names button{padding:8px 14px;font-size:14px;font-weight:800}.line-names-print{margin:0 0 12px;font-size:14px;line-height:1.6}.no-print{float:right;padding:8px 14px;font-size:14px}@media print{body{margin:0}.no-print{display:none!important}.line-names{display:none!important}}`;

const BODY_OLD = `      '</style></head><body><button class="no-print" onclick="window.print()">列印給業務</button>',
      '<h1>第' + escapeHtml(String(meta.no)) + '區　' + escapeHtml(meta.title) + '</h1>',
      '<p class="sub">給業務核對用：客戶名單＋購買商品　' + escapeHtml(meta.hint) + '<br>' + escapeHtml(filterBits.join('｜') || '目前這一區全部客戶') + '<br>列印時間 ' + escapeHtml(printedAt) + '</p>',
      '<section class="metrics"><article><span>客戶</span><strong>' + Object.keys(customerKeys).length + ' 位</strong></article><article><span>訂單／卡片</span><strong>' + rows.length + ' 張</strong></article><article><span>商品總數</span><strong>' + totalQty + ' 件</strong></article></section>',
      cards,
      '<script>window.onload=function(){setTimeout(function(){window.focus();window.print();},350);};<\\/script></body></html>'`;

const BODY_NEW = `      '</style></head><body><button class="no-print" type="button" onclick="copyUnshippedNames()">複製還沒出名字</button><button class="no-print" onclick="window.print()">列印給業務</button>',
      '<h1>第' + escapeHtml(String(meta.no)) + '區　' + escapeHtml(meta.title) + '</h1>',
      '<p class="sub">給業務核對用：客戶名單＋購買商品　' + escapeHtml(meta.hint) + '<br>' + escapeHtml(filterBits.join('｜') || '目前這一區全部客戶') + '<br>列印時間 ' + escapeHtml(printedAt) + '</p>',
      '<section class="metrics"><article><span>客戶</span><strong>' + Object.keys(customerKeys).length + ' 位</strong></article><article><span>訂單／卡片</span><strong>' + rows.length + ' 張</strong></article><article><span>商品總數</span><strong>' + totalQty + ' 件</strong></article></section>',
      '<section class="line-names no-print"><header><div><b>還沒出貨會員（貼 LINE）</b><small>名字、逗號分隔。含待配貨／已選／等通知／未交寄，不含配送中。</small></div><button type="button" onclick="copyUnshippedNames()">複製名字</button></header><textarea id="unshipped-names" data-unshipped-names readonly>' + escapeHtml(unshippedLine) + '</textarea></section>',
      '<p class="line-names-print"><b>還沒出貨：</b>' + escapeHtml(unshippedLine || '目前沒有') + '</p>',
      cards,
      '<script>function copyUnshippedNames(){var el=document.getElementById("unshipped-names");if(!el)return;el.focus();el.select();try{document.execCommand("copy");}catch(e){}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(el.value);} }window.onload=function(){setTimeout(function(){window.focus();window.print();},350);};<\\/script></body></html>'`;

const VARS_OLD = `    if (freightFifoStatusFilter && freightFifoStatusFilter !== 'all') filterBits.push('配送狀態篩選中');
    var printedAt = new Date().toLocaleString('zh-TW', { hour12: false });
    var printBaseHref = new URL('.', window.location.href).href;`;

const VARS_NEW = `    if (freightFifoStatusFilter && freightFifoStatusFilter !== 'all') filterBits.push('配送狀態篩選中');
    var printedAt = new Date().toLocaleString('zh-TW', { hour12: false });
    var unshippedNames = freightFifoUnshippedMemberNames(freightFifoStagePrintRows);
    var unshippedLine = unshippedNames.join('，');
    var printBaseHref = new URL('.', window.location.href).href;`;

if (!fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "unshipped-names");
let src = fs.readFileSync(ADMIN_JS, "utf8");
src = replaceOnce(src, HELPER_OLD, HELPER_NEW, "unshipped name helpers");
src = replaceOnce(src, BUTTON_OLD, BUTTON_NEW, "board copy-unshipped button");
src = replaceOnce(src, CLICK_OLD, CLICK_NEW, "copy-unshipped click");
src = replaceOnce(src, VARS_OLD, VARS_NEW, "print unshipped name vars");
src = replaceOnce(src, STYLE_OLD, STYLE_NEW, "print line-names css");
src = replaceOnce(src, BODY_OLD, BODY_NEW, "print unshipped names box");
fs.writeFileSync(ADMIN_JS, src);

if (!fifoBoardHasCopyUnshippedNames(src)) throw new Error("board copy button missing");
if (src.indexOf("function freightFifoUnshippedMemberNames(") === -1) throw new Error("helper missing");
if (src.indexOf("data-unshipped-names") === -1) throw new Error("print textarea missing");
if (!fifoPrintHasUnshippedNamesBox(src)) throw new Error("print names box missing");

stampHtml(ROOT);
console.log("LINGZANZAN fifo unshipped names ok", STAMP);
