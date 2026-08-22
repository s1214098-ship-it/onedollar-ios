#!/usr/bin/env node
"use strict";

/**
 * 廠商進貨單產品搜尋：相關顏色／尺寸可勾選後一次加入，不必一筆一筆點。
 * Cache-bust: inventory-freight-entry-20260810.js / inventory-freight-entry.css
 *             ?v=20260819-multi-size-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "inventory-freight-entry-20260810.js");
const CSS = path.join(ROOT, "assets", "inventory-freight-entry.css");
const HTML = path.join(ROOT, "admin-inventory-entry.html");
const STAMP = "20260819-multi-size-1";
const CSS_MARKER = "/* 20260819 entry multi-size checkboxes */";

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
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const MATCHES_OLD = `      return { sku: sku, rank: rank, index: index, code: product.code || product.productLine || product.id || '' };
    }).filter(function (row) { return row.rank < 999; }).sort(function (a, b) {
      return a.rank - b.rank || String(a.code).localeCompare(String(b.code), 'zh-Hant', { numeric: true }) || a.index - b.index;
    }).slice(0, 8).map(function (row) { return row.sku; });
  }`;

const MATCHES_NEW = `      return { sku: sku, rank: rank, index: index, code: product.code || product.productLine || product.id || '', color: text(sku.colorName || sku.color), size: text(sku.sizeName || sku.size) };
    }).filter(function (row) { return row.rank < 999; }).sort(function (a, b) {
      return a.rank - b.rank || String(a.code).localeCompare(String(b.code), 'zh-Hant', { numeric: true }) || String(a.color).localeCompare(String(b.color), 'zh-Hant') || String(a.size).localeCompare(String(b.size), 'zh-Hant', { numeric: true }) || a.index - b.index;
    }).slice(0, 40).map(function (row) { return row.sku; });
  }`;

const RENDER_OLD = `    var matches = standaloneSkuMatches(query).slice(0, 8);
    host._matches = matches;
    if (!matches.length) {
      host.innerHTML = '<p>查無相符產品；可改用完整產品編號、SKU 或條碼搜尋。</p>';
      host.hidden = false;
      return;
    }
    host.innerHTML = matches.map(function (sku, index) {
      var product = productById(sku.productId) || {};
      var productName = text(product.title || product.name || productCodeForSku(sku)) || '未命名產品';
      var code = productCodeForSku(sku) || text(sku.productId);
      var barcode = text(sku.companyBarcode || sku.barcode || sku.id || sku.sku) || '未建條碼';
      var color = text(sku.colorName || sku.color) || '未填顏色';
      var size = text(sku.sizeName || sku.size) || 'NO SIZE';
      var warehouse = text(sku.warehouseName || sku.warehouse) || '未指定倉別';
      var category = productCategory(product) || '未填分類';
      var productImage = firstProductImage(product, sku, {});
      var thumb = '<span class="purchase-receipt-suggest-thumb"><b>' + escapeHtml(String(code || '?').slice(0, 2)) + '</b>' +
        (productImage ? '<img src="' + escapeHtml(productImage) + '" alt="' + escapeHtml(productName) + '" onerror="this.remove()">' : '') + '</span>';
      return '<button type="button" data-purchase-receipt-sku-pick="' + index + '">' +
        thumb +
        '<b>' + escapeHtml(code) + '</b>' +
        '<span>' + escapeHtml(productName) + '</span>' +
        '<em>' + escapeHtml(barcode) + '</em>' +
        '<small>' + escapeHtml(color) + '</small>' +
        '<small>' + escapeHtml(size) + '</small>' +
        '<small>' + escapeHtml(category + '／' + warehouse) + '</small>' +
        '</button>';
    }).join('');
    host.hidden = false;
  }`;

const RENDER_NEW = `    var matches = standaloneSkuMatches(query);
    host._matches = matches;
    if (!matches.length) {
      host.innerHTML = '<p>查無相符產品；可改用完整產品編號、SKU 或條碼搜尋。</p>';
      host.hidden = false;
      return;
    }
    var rows = matches.map(function (sku, index) {
      var product = productById(sku.productId) || {};
      var productName = text(product.title || product.name || productCodeForSku(sku)) || '未命名產品';
      var code = productCodeForSku(sku) || text(sku.productId);
      var barcode = text(sku.companyBarcode || sku.barcode || sku.id || sku.sku) || '未建條碼';
      var color = text(sku.colorName || sku.color) || '未填顏色';
      var size = text(sku.sizeName || sku.size) || 'NO SIZE';
      var warehouse = text(sku.warehouseName || sku.warehouse) || '未指定倉別';
      var category = productCategory(product) || '未填分類';
      var productImage = firstProductImage(product, sku, {});
      var thumb = '<span class="purchase-receipt-suggest-thumb"><b>' + escapeHtml(String(code || '?').slice(0, 2)) + '</b>' +
        (productImage ? '<img src="' + escapeHtml(productImage) + '" alt="' + escapeHtml(productName) + '" onerror="this.remove()">' : '') + '</span>';
      return '<article class="purchase-receipt-suggest-row" data-purchase-receipt-sku-pick="' + index + '">' +
        '<label class="purchase-receipt-suggest-check"><input type="checkbox" data-purchase-receipt-sku-check="' + index + '"><span></span></label>' +
        thumb +
        '<b>' + escapeHtml(code) + '</b>' +
        '<span>' + escapeHtml(productName) + '</span>' +
        '<em>' + escapeHtml(barcode) + '</em>' +
        '<small>' + escapeHtml(color) + '</small>' +
        '<strong>' + escapeHtml(size) + '</strong>' +
        '<small>' + escapeHtml(category + '／' + warehouse) + '</small>' +
        '</article>';
    }).join('');
    host.innerHTML = '<div class="purchase-receipt-suggest-toolbar">' +
      '<label class="purchase-receipt-suggest-all"><input type="checkbox" data-purchase-receipt-sku-check-all><span>全選目前結果</span></label>' +
      '<button type="button" class="primary-button" data-purchase-receipt-add-checked>加入已勾選尺寸／Tambah ukuran</button>' +
      '<small>可勾多個尺寸一次加入；每筆預設 1 件，加入後再改數量。</small>' +
      '</div>' + rows;
    host.hidden = false;
  }`;

const HELPERS = `
  function checkedStandaloneSkus() {
    var host = document.querySelector('[data-purchase-receipt-product-suggest]');
    if (!host || !Array.isArray(host._matches)) return [];
    return Array.prototype.map.call(host.querySelectorAll('[data-purchase-receipt-sku-check]:checked'), function (input) {
      return host._matches[Number(input.getAttribute('data-purchase-receipt-sku-check'))];
    }).filter(Boolean);
  }

  function syncStandaloneSuggestChecks() {
    var host = document.querySelector('[data-purchase-receipt-product-suggest]');
    if (!host) return;
    var boxes = Array.prototype.slice.call(host.querySelectorAll('[data-purchase-receipt-sku-check]'));
    boxes.forEach(function (input) {
      var row = input.closest('[data-purchase-receipt-sku-pick]');
      if (row) row.classList.toggle('is-checked', !!input.checked);
    });
    var all = host.querySelector('[data-purchase-receipt-sku-check-all]');
    if (all) {
      var n = boxes.filter(function (input) { return input.checked; }).length;
      all.checked = boxes.length > 0 && n === boxes.length;
      all.indeterminate = n > 0 && n < boxes.length;
    }
  }

  function addStandaloneSkuRecords(skus) {
    skus = (skus || []).filter(Boolean);
    if (!skus.length) {
      standaloneMessage('請先勾選要加入的尺寸。', 'error');
      return;
    }
    syncStandaloneLinesFromDom();
    skus.forEach(function (sku) {
      var product = productById(sku.productId) || {};
      var barcode = text(sku.companyBarcode || sku.barcode || sku.legacyBarcode || sku.mappingCode || sku.id || sku.sku);
      state.standaloneLines.push(standaloneLineFromSku(sku, {
        productName: text(product.title || product.name || productCodeForSku(sku)),
        category: productCategory(product),
        productCode: text(product.code || product.productLine || product.id),
        sampleBarcode: barcode,
        taiwanBarcode: barcode
      }, null));
    });
    clearStandaloneItemEditor();
    var input = document.querySelector('[data-purchase-receipt-sku-search]');
    if (input) input.focus();
    renderStandaloneLines();
    standaloneMessage('已加入 ' + skus.length + ' 個尺寸，可在下方改數量。', 'ok');
  }

`;

const ADD_OLD = `  function addStandaloneSku(value) {
    var selectedSku = state.selectedStandaloneSku;
    var matches = selectedSku ? [selectedSku] : standaloneSkuMatches(value);
    if (!selectedSku && matches.length > 1) { standaloneMessage('找到多個顏色或尺寸，請先從下方相關產品列選擇正確規格。', 'error'); renderStandaloneSkuSuggest(value); return; }`;

const ADD_NEW = `  function addStandaloneSku(value) {
    var checkedSkus = checkedStandaloneSkus();
    if (checkedSkus.length) { addStandaloneSkuRecords(checkedSkus); return; }
    var selectedSku = state.selectedStandaloneSku;
    var matches = selectedSku ? [selectedSku] : standaloneSkuMatches(value);
    if (!selectedSku && matches.length > 1) { standaloneMessage('找到多個顏色或尺寸，請勾選要加入的尺寸，再按「加入已勾選尺寸」。', 'error'); renderStandaloneSkuSuggest(value); return; }`;

const CLICK_OLD = `    var standaloneSkuPick = event.target.closest('[data-purchase-receipt-sku-pick]');
    if (standaloneSkuPick) {
      event.preventDefault();
      var standaloneSuggest = standaloneSkuPick.closest('[data-purchase-receipt-product-suggest]');
      selectStandaloneSkuRecord(standaloneSuggest && standaloneSuggest._matches ? standaloneSuggest._matches[Number(standaloneSkuPick.getAttribute('data-purchase-receipt-sku-pick'))] : null);
      return;
    }`;

const CLICK_NEW = `    var addChecked = event.target.closest('[data-purchase-receipt-add-checked]');
    if (addChecked) {
      event.preventDefault();
      addStandaloneSkuRecords(checkedStandaloneSkus());
      return;
    }
    var standaloneSkuPick = event.target.closest('[data-purchase-receipt-sku-pick]');
    if (standaloneSkuPick) {
      if (event.target.closest('[data-purchase-receipt-sku-check]')) return;
      event.preventDefault();
      var box = standaloneSkuPick.querySelector('[data-purchase-receipt-sku-check]');
      if (box) {
        box.checked = !box.checked;
        syncStandaloneSuggestChecks();
      }
      return;
    }`;

const CHANGE_LISTENER = `
  root.addEventListener('change', function (event) {
    if (event.target.matches('[data-purchase-receipt-sku-check-all]')) {
      var on = !!event.target.checked;
      document.querySelectorAll('[data-purchase-receipt-sku-check]').forEach(function (input) { input.checked = on; });
      syncStandaloneSuggestChecks();
      return;
    }
    if (event.target.matches('[data-purchase-receipt-sku-check]')) syncStandaloneSuggestChecks();
  });
`;

const CSS_PATCH = `
${CSS_MARKER}
.purchase-receipt-suggest-toolbar{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;position:sticky;top:0;z-index:2;padding:4px 2px 8px;background:#15191a}
.purchase-receipt-suggest-toolbar small{color:#b9c7c3;font-size:12px}
.purchase-receipt-suggest-all{display:inline-flex;align-items:center;gap:8px;color:#fffaf4!important;font-weight:800;cursor:pointer}
.purchase-receipt-suggest-all input{width:18px;height:18px;accent-color:#68dcc3}
.purchase-receipt-suggest-toolbar .primary-button{min-height:40px;padding:8px 14px}
.purchase-receipt-suggest-row{display:grid;grid-template-columns:28px 58px minmax(90px,.7fr) minmax(160px,1.4fr) minmax(130px,.9fr) minmax(90px,.6fr) minmax(72px,.5fr) minmax(120px,.8fr);gap:10px;align-items:center;width:100%;min-height:54px;padding:10px 12px;border:1px solid rgba(255,255,255,.1);border-radius:11px;background:#211b24;color:#fffaf4!important;text-align:left;cursor:pointer}
.purchase-receipt-suggest-row:hover,.purchase-receipt-suggest-row.is-checked{outline:2px solid #68dcc3;background:#1a2c28}
.purchase-receipt-suggest-check{display:grid;place-items:center;margin:0;cursor:pointer}
.purchase-receipt-suggest-check input{width:18px;height:18px;accent-color:#68dcc3}
.purchase-receipt-suggest-row b{color:#f1c96f}
.purchase-receipt-suggest-row span{color:#fffaf4;font-size:14px}
.purchase-receipt-suggest-row em,.purchase-receipt-suggest-row small{color:#b9c7c3;font-style:normal;font-size:12px}
.purchase-receipt-suggest-row strong{color:#8ee9dc;font-size:16px;font-weight:900}
@media(max-width:760px){
  .purchase-receipt-suggest-row{grid-template-columns:28px 58px minmax(0,1fr)!important}
  .purchase-receipt-suggest-row>.purchase-receipt-suggest-thumb{grid-column:2;grid-row:1/span 6}
  .purchase-receipt-suggest-row>:not(.purchase-receipt-suggest-check):not(.purchase-receipt-suggest-thumb){grid-column:3}
}
`;

function stampHtml(html) {
  return html
    .replace(/inventory-freight-entry-20260810\.js(?:\?v=[^"']+)?/g, `inventory-freight-entry-20260810.js?v=${STAMP}`)
    .replace(/inventory-freight-entry\.css(?:\?v=[^"']+)?/g, `inventory-freight-entry.css?v=${STAMP}`);
}

function main() {
  console.log("backup js", backup(JS, "multi-size"));
  console.log("backup css", backup(CSS, "multi-size"));
  console.log("backup html", backup(HTML, "multi-size"));

  let js = fs.readFileSync(JS, "utf8");
  js = replaceOnce(js, MATCHES_OLD, MATCHES_NEW, "sku match limit/sort");
  js = replaceOnce(js, RENDER_OLD, RENDER_NEW, "suggest checkboxes");
  js = replaceOnce(
    js,
    "  function renderStandaloneSkuSuggest(value) {",
    HELPERS + "  function renderStandaloneSkuSuggest(value) {",
    "helpers"
  );
  js = replaceOnce(js, ADD_OLD, ADD_NEW, "add uses checked sizes");
  js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "row click toggles check");
  if (!js.includes("data-purchase-receipt-sku-check-all")) {
    throw new Error("check-all marker missing after patch");
  }
  if (!js.includes("matches('[data-purchase-receipt-sku-check-all]')")) {
    const mark = "  var standaloneSkuInput = document.querySelector('[data-purchase-receipt-sku-search]');";
    js = replaceOnce(js, mark, CHANGE_LISTENER + "\n" + mark, "change listener");
  } else {
    console.log("already: check-all change listener");
  }

  fs.writeFileSync(JS, js);
  console.log("js written", js.length);

  let css = fs.readFileSync(CSS, "utf8");
  if (css.includes(CSS_MARKER)) {
    css = css.replace(/\n\/\* 20260819 entry multi-size checkboxes \*\/[\s\S]*$/m, "");
  }
  fs.writeFileSync(CSS, css.replace(/\s*$/, "") + "\n" + CSS_PATCH);
  console.log("css patched");

  let html = fs.readFileSync(HTML, "utf8");
  html = stampHtml(html);
  html = html.replace(
    "先用關鍵字找產品；選取後會帶入分類、產品編號與既有條碼。新產品可依分類產生下一個產品編號，再加入本張正式進貨單。",
    "先用關鍵字找產品；相關顏色／尺寸可勾選後一次加入。新產品可依分類產生下一個產品編號，再加入本張正式進貨單。"
  );
  fs.writeFileSync(HTML, html);
  if (!html.includes("inventory-freight-entry-20260810.js?v=" + STAMP)) {
    throw new Error("html js stamp missing");
  }
  if (!html.includes("inventory-freight-entry.css?v=" + STAMP)) {
    throw new Error("html css stamp missing");
  }
  console.log("html stamped", STAMP);
}

main();
