#!/usr/bin/env node
"use strict";

/**
 * 到貨數量核對：同一物流單常夾帶多款。頁面上方「＋ 新增其他款」
 * 可套現有產品條碼，或先建未建檔款（照片／價格／尺寸／數量），寫進這張單再繼續點貨。
 *
 * Cache-bust: admin.js / admin.css ?v=20260819-arrival-extra-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const CSS = path.join(ROOT, "assets", "admin.css");
const STAMP = "20260819-arrival-extra-1";
const CSS_MARKER = "/* 20260819 arrival extra product on same tracking */";
const JS_MARKER = "function openFreightQuantityExtraPanel(";

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

const HELPERS = `
  var freightQuantityConfirmRestore = null;

  function openFreightQuantityExtraPanel(modal) {
    var panel = modal && modal.querySelector('[data-freight-quantity-extra-panel]');
    if (!panel) return;
    panel.hidden = false;
    var search = panel.querySelector('[data-freight-quantity-extra-search]');
    if (search) search.focus();
  }

  function freightQuantityExistingMatches(query) {
    var wanted = String(query || '').trim().toLowerCase();
    if (wanted.length < 2) return [];
    var compact = wanted.replace(/[^a-z0-9\\u4e00-\\u9fff]+/g, '');
    var rows = [];
    var catalog = typeof unifiedProductRecognitionCatalog === 'function' ? unifiedProductRecognitionCatalog() : [];
    catalog.forEach(function (row) {
      var product = row.product || {};
      var skus = (row.skus || []).concat(row.forecastSkus || []);
      (skus.length ? skus : [null]).forEach(function (sku) {
        var code = String((sku && (sku.companyBarcode || sku.barcode || sku.id || sku.sku)) || product.code || '');
        var title = String(product.title || product.name || product.code || '');
        var color = String((sku && (sku.colorName || sku.color)) || '');
        var size = String((sku && (sku.sizeName || sku.size)) || 'NO SIZE');
        var hay = [title, product.code, code, color, size].join(' ').toLowerCase();
        var hayCompact = hay.replace(/[^a-z0-9\\u4e00-\\u9fff]+/g, '');
        if (hay.indexOf(wanted) === -1 && hayCompact.indexOf(compact) === -1) return;
        rows.push({
          product: product,
          sku: sku || {},
          title: title,
          code: String(product.code || product.productLine || ''),
          barcode: code,
          color: color || '未填顏色',
          size: size,
          image: String((sku && (sku.image || sku.colorImage)) || product.mainImage || (Array.isArray(product.images) && product.images[0]) || ''),
          costRmb: Number((sku && sku.costRmb) || product.costRmb || 0),
          costTwd: Number((sku && (sku.cost || sku.costTwd)) || product.cost || product.costTwd || 0)
        });
      });
    });
    return rows.slice(0, 12);
  }

  function renderFreightQuantityExistingMatches(panel, query) {
    var host = panel && panel.querySelector('[data-freight-quantity-extra-results]');
    if (!host) return;
    var matches = freightQuantityExistingMatches(query);
    panel._matches = matches;
    if (!String(query || '').trim()) {
      host.innerHTML = '<p>輸入名稱、款號或條碼後選擇；選用會帶入現有條碼、顏色與尺寸。</p>';
      return;
    }
    if (!matches.length) {
      host.innerHTML = '<p>沒有相符的現有產品。可在下方直接建未建檔款，稍後再轉正式商品。</p>';
      return;
    }
    host.innerHTML = matches.map(function (row, index) {
      var thumb = row.image
        ? '<img src="' + escapeHtml(row.image) + '" alt="">'
        : '<span>無圖</span>';
      return '<button type="button" data-freight-quantity-existing-pick="' + index + '">' +
        thumb +
        '<b>' + escapeHtml(row.code || row.barcode || '-') + '</b>' +
        '<span>' + escapeHtml(row.title || '未命名') + '</span>' +
        '<small>' + escapeHtml(row.color) + '／' + escapeHtml(row.size) + '／' + escapeHtml(row.barcode || '未建條碼') + '</small>' +
        '</button>';
    }).join('');
  }

  function fillFreightQuantityExtraFromMatch(panel, row) {
    if (!panel || !row) return;
    var set = function (sel, value) {
      var input = panel.querySelector(sel);
      if (input) input.value = value == null ? '' : value;
    };
    set('[data-freight-quantity-extra-name]', row.title || '');
    set('[data-freight-quantity-extra-color]', row.color === '未填顏色' ? '' : row.color);
    set('[data-freight-quantity-extra-size]', row.size || 'NO SIZE');
    set('[data-freight-quantity-extra-barcode]', row.barcode || '');
    set('[data-freight-quantity-extra-cost-rmb]', row.costRmb || '');
    set('[data-freight-quantity-extra-cost-twd]', row.costTwd || '');
    var qtyInput = panel.querySelector('[data-freight-quantity-extra-qty]');
    if (qtyInput && !String(qtyInput.value || '').trim()) qtyInput.value = '1';
    panel._picked = row;
    if (row.image) {
      panel._photo = row.image;
      var preview = panel.querySelector('[data-freight-quantity-extra-preview]');
      if (preview) preview.innerHTML = '<img src="' + escapeHtml(row.image) + '" alt="現有產品圖">';
    }
    toast('已帶入現有產品 ' + (row.code || row.barcode || '') + '，確認數量後按加入');
  }

  function snapshotFreightQuantityInputs(modal) {
    var snap = {};
    Array.prototype.forEach.call(modal.querySelectorAll('[data-freight-quantity-item]'), function (group) {
      snap[String(group.getAttribute('data-freight-quantity-item') || '')] = Array.prototype.map.call(
        group.querySelectorAll('[data-freight-quantity-actual]'),
        function (input) { return input.value; }
      );
    });
    return snap;
  }

  function restoreFreightQuantityInputs(modal, snap) {
    if (!snap) return;
    Object.keys(snap).forEach(function (itemId) {
      var group = modal.querySelector('[data-freight-quantity-item="' + itemId + '"]');
      if (!group) return;
      var values = snap[itemId] || [];
      Array.prototype.forEach.call(group.querySelectorAll('[data-freight-quantity-actual]'), function (input, index) {
        if (values[index] !== undefined) input.value = values[index];
      });
    });
  }

  function buildFreightQuantityExtraItem(sourceItem, batch, trackingNo, extra) {
    var now = new Date().toISOString();
    var qty = Math.max(1, Math.floor(Number(extra.qty || 1)));
    var color = String(extra.color || '').trim();
    var size = typeof canonicalNoSize === 'function' ? canonicalNoSize(extra.size || 'NO SIZE') : String(extra.size || 'NO SIZE').trim() || 'NO SIZE';
    var barcode = String(extra.barcode || '').trim();
    var image = String(extra.image || '').trim();
    var productId = String(extra.productId || '').trim();
    var skuId = String(extra.skuId || '').trim();
    var productCode = String(extra.productCode || '').trim();
    var productName = String(extra.productName || '').trim();
    var costRmb = Math.max(0, Number(extra.costRmb || 0));
    var costTwd = Math.max(0, Number(extra.costTwd || 0));
    var variant = {
      id: 'variant-1',
      productId: productId,
      skuId: skuId,
      productCode: productCode,
      productName: productName,
      color: color,
      size: size,
      quantity: qty,
      qty: qty,
      sampleBarcode: barcode,
      taiwanBarcode: barcode,
      image: image,
      costRmb: costRmb,
      barcodeCostTwd: costTwd
    };
    var colorImages = {};
    if (color && image) colorImages[color] = image;
    return {
      id: 'plog-' + Date.now() + '-' + Math.random().toString(16).slice(2),
      batchId: String(batch && batch.id || sourceItem.batchId || ''),
      trackingNo: trackingNo,
      trackingNumbers: [trackingNo],
      productName: productName,
      productCode: productCode,
      catalogProductCode: productCode,
      productId: productId,
      skuId: skuId,
      category: String(extra.category || sourceItem.category || '').trim(),
      color: color,
      size: size,
      quantity: qty,
      qty: qty,
      costRmb: costRmb,
      existingTwdCost: costTwd,
      sampleBarcode: barcode,
      taiwanBarcode: barcode,
      variants: [variant],
      images: image ? [image] : [],
      productImages: image ? [image] : [],
      colorImages: colorImages,
      logisticsSource: sourceItem.logisticsSource || 'haohong',
      progress: sourceItem.progress || '台灣收到待點貨',
      destinationWarehouse: sourceItem.destinationWarehouse || 'TW_BAOHUI',
      haohongTrackingNo: sourceItem.haohongTrackingNo || '',
      pendingLogisticsCode: sourceItem.pendingLogisticsCode || '',
      freightLinkNo: sourceItem.freightLinkNo || '',
      purchaseAccount: sourceItem.purchaseAccount || sourceItem.account || '',
      note: '到貨核對新增：同單號多款',
      arrivalExtraOnTracking: true,
      createdAt: now,
      updatedAt: now,
      receivingInspection: {
        lines: [{ actualQty: qty }],
        varianceReason: '同物流單加帶其他款，到貨核對時新增'
      }
    };
  }

  function saveFreightQuantityExtraItem(modal) {
    var panel = modal && modal.querySelector('[data-freight-quantity-extra-panel]');
    var trackingNo = String(modal && modal.getAttribute('data-freight-quantity-confirm') || '');
    var batchId = String(modal && modal.getAttribute('data-freight-arrival-status-modal') || '');
    var batch = freightBatchById(batchId);
    var sourceItems = freightArrivalItemsForTracking(batch, trackingNo);
    var sourceItem = sourceItems[0] || {};
    if (!panel || !batch || !trackingNo) { toast('找不到這張物流單，無法新增'); return; }
    var extra = {
      productName: String((panel.querySelector('[data-freight-quantity-extra-name]') || {}).value || '').trim(),
      color: String((panel.querySelector('[data-freight-quantity-extra-color]') || {}).value || '').trim(),
      size: String((panel.querySelector('[data-freight-quantity-extra-size]') || {}).value || 'NO SIZE').trim() || 'NO SIZE',
      qty: (panel.querySelector('[data-freight-quantity-extra-qty]') || {}).value,
      barcode: String((panel.querySelector('[data-freight-quantity-extra-barcode]') || {}).value || '').trim(),
      costRmb: (panel.querySelector('[data-freight-quantity-extra-cost-rmb]') || {}).value,
      costTwd: (panel.querySelector('[data-freight-quantity-extra-cost-twd]') || {}).value,
      image: panel._photo || ''
    };
    var picked = panel._picked || {};
    if (!(picked.product || picked.barcode) && extra.barcode) {
      var barcodeWanted = extra.barcode.toLowerCase();
      picked = (freightQuantityExistingMatches(extra.barcode) || []).find(function (row) {
        return String(row.barcode || '').toLowerCase() === barcodeWanted;
      }) || {};
    }
    extra.productId = String((picked.product && picked.product.id) || '').trim();
    extra.skuId = String((picked.sku && (picked.sku.id || picked.sku.sku)) || '').trim();
    extra.productCode = String((picked.code) || (picked.product && (picked.product.code || picked.product.productLine)) || '').trim();
    extra.category = String((picked.product && (picked.product.category || picked.product.categoryName)) || '').trim();
    if (!extra.image && picked.image) extra.image = String(picked.image || '');
    if (!extra.productName) { toast('請填產品名稱，或先搜尋套用現有產品'); return; }
    if (!Math.max(1, Math.floor(Number(extra.qty || 0)))) { toast('請填本次數量'); return; }
    var saveBtn = panel.querySelector('[data-freight-quantity-add-save]');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '正在加入…'; }
    var item = buildFreightQuantityExtraItem(sourceItem, batch, trackingNo, extra);
    freightQuantityConfirmRestore = snapshotFreightQuantityInputs(modal);
    saveFreightItemRecord(item, 'arrival-extra-' + Date.now()).then(function () {
      toast('已把「' + extra.productName + '」加進這張物流單');
      refreshFreightQuantityConfirmation(batchId, trackingNo);
    }).catch(function (error) {
      if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '加入這張物流單'; }
      toast(error.message || '新增失敗');
    });
  }

  function refreshFreightQuantityConfirmation(batchId, trackingNo) {
    var modal = document.querySelector('[data-freight-quantity-confirm]');
    if (modal) modal.remove();
    openFreightQuantityConfirmation(batchId, trackingNo);
  }

  function bindFreightQuantityExtraPanel(modal) {
    var panel = modal && modal.querySelector('[data-freight-quantity-extra-panel]');
    if (!panel) return;
    var search = panel.querySelector('[data-freight-quantity-extra-search]');
    var timer = 0;
    if (search) {
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { renderFreightQuantityExistingMatches(panel, search.value); }, 180);
      });
    }
    var file = panel.querySelector('[data-freight-quantity-extra-photo]');
    if (file) {
      file.addEventListener('change', function () {
        var picked = file.files && file.files[0];
        if (!picked || typeof fileToDataUrl !== 'function') return;
        fileToDataUrl(picked).then(function (dataUrl) {
          panel._photo = dataUrl;
          var preview = panel.querySelector('[data-freight-quantity-extra-preview]');
          if (preview) preview.innerHTML = dataUrl ? '<img src="' + dataUrl + '" alt="新增產品圖">' : '';
        });
      });
    }
    panel.addEventListener('paste', function (event) {
      var items = event.clipboardData && event.clipboardData.items;
      if (!items) return;
      Array.prototype.forEach.call(items, function (entry) {
        if (!entry.type || entry.type.indexOf('image/') !== 0) return;
        event.preventDefault();
        var blob = entry.getAsFile();
        if (!blob || typeof fileToDataUrl !== 'function') return;
        fileToDataUrl(blob).then(function (dataUrl) {
          panel._photo = dataUrl;
          var preview = panel.querySelector('[data-freight-quantity-extra-preview]');
          if (preview) preview.innerHTML = dataUrl ? '<img src="' + dataUrl + '" alt="貼上產品圖">' : '';
        });
      });
    });
    renderFreightQuantityExistingMatches(panel, '');
  }

`;

const PANEL_HTML = `'<div class="freight-quantity-extra">' +
      '<aside class="freight-quantity-extra-panel" hidden data-freight-quantity-extra-panel>' +
        '<header><b>新增這張單的其他款</b><span>同一物流單常會夾帶多款。現有產品請搜尋套用舊條碼；沒建檔的先填照片、價格、尺寸與數量。</span></header>' +
        '<label>搜尋現有產品／條碼<input type="search" data-freight-quantity-extra-search placeholder="名稱、款號或條碼" autocomplete="off"></label>' +
        '<div class="freight-quantity-extra-results" data-freight-quantity-extra-results></div>' +
        '<div class="freight-quantity-extra-grid">' +
          '<label>產品名稱<input data-freight-quantity-extra-name placeholder="未建檔請手填；套用現有會自動帶入"></label>' +
          '<label>顏色<input data-freight-quantity-extra-color placeholder="例如 紅色"></label>' +
          '<label>尺寸<input data-freight-quantity-extra-size placeholder="NO SIZE 或 M / L"></label>' +
          '<label>本次數量<input type="number" min="1" step="1" inputmode="numeric" value="1" data-freight-quantity-extra-qty></label>' +
          '<label>成本人民幣<input type="number" min="0" step="0.01" data-freight-quantity-extra-cost-rmb placeholder="可空"></label>' +
          '<label>成本台幣<input type="number" min="0" step="1" data-freight-quantity-extra-cost-twd placeholder="可空"></label>' +
          '<label>現有條碼<input data-freight-quantity-extra-barcode placeholder="套用現有後會帶入"></label>' +
          '<label class="freight-quantity-extra-photo">照片（可上傳或直接貼上）<input type="file" accept="image/*" data-freight-quantity-extra-photo><span class="freight-quantity-extra-preview" data-freight-quantity-extra-preview></span></label>' +
        '</div>' +
        '<div class="freight-quantity-extra-actions"><button type="button" class="ghost-button" data-freight-quantity-add-cancel>取消</button><button type="button" class="primary-button" data-freight-quantity-add-save>加入這張物流單</button></div>' +
      '</aside>' +
      '</div>' + `;

const HEADER_OLD = `modal.innerHTML = '<section><header><div><small>ARRIVAL QUANTITY CHECK／到貨數量核對</small><h3>原集運數量保留，只填本次收到數量</h3><p>物流單號 ' + escapeHtml(trackingNo || '-') + '。原本設定的全部顏色、尺寸與數量不會刪除，也不用重新增加。</p></div><button type="button" class="ghost-button" data-freight-arrival-status-close>關閉</button></header>' +
      '<div class="freight-quantity-warning"><b>兩欄分開記錄，目前尚未改庫存</b><span>左欄「集運原數量」保留不改；右欄填「本次收到數量」。這次沒收到的顏色填 0，會保留為未到貨追蹤。</span></div>' +
      rows +`;

const HEADER_NEW = `modal.innerHTML = '<section><header><div><small>ARRIVAL QUANTITY CHECK／到貨數量核對</small><h3>原集運數量保留，只填本次收到數量</h3><p>物流單號 ' + escapeHtml(trackingNo || '-') + '。同一張單常會夾帶多款，可用右上「＋ 新增其他款」套現有條碼或先建未建檔產品。原本已列的顏色、尺寸與數量不會刪除。</p></div><div class="freight-quantity-confirm-head-actions"><button type="button" class="primary-button" data-freight-quantity-add-open>＋ 新增其他款</button><button type="button" class="ghost-button" data-freight-arrival-status-close>關閉</button></div></header>' +
      '<div class="freight-quantity-warning"><b>兩欄分開記錄，目前尚未改庫存</b><span>左欄「集運原數量」保留不改；右欄填「本次收到數量」。這次沒收到的顏色填 0，會保留為未到貨追蹤。</span></div>' +
      ${PANEL_HTML}
      rows +`;

const BIND_OLD = `    document.body.appendChild(modal);
    var updateTotal = function () {
      var inputs = Array.from(modal.querySelectorAll('[data-freight-quantity-actual]'));`;

const BIND_NEW = `    document.body.appendChild(modal);
    restoreFreightQuantityInputs(modal, freightQuantityConfirmRestore);
    freightQuantityConfirmRestore = null;
    bindFreightQuantityExtraPanel(modal);
    var updateTotal = function () {
      var inputs = Array.from(modal.querySelectorAll('[data-freight-quantity-actual]'));`;

const CLICK_OLD = `      var freightQuantitySave = event.target.closest ? event.target.closest('[data-freight-quantity-confirm-save]') : null;
      if (freightQuantitySave) {
        event.preventDefault();
        saveFreightQuantityConfirmation(freightQuantitySave);
        return;
      }`;

const CLICK_NEW = `      var freightQuantityAddOpen = event.target.closest ? event.target.closest('[data-freight-quantity-add-open]') : null;
      if (freightQuantityAddOpen) {
        event.preventDefault();
        openFreightQuantityExtraPanel(freightQuantityAddOpen.closest('[data-freight-quantity-confirm]'));
        return;
      }
      var freightQuantityAddCancel = event.target.closest ? event.target.closest('[data-freight-quantity-add-cancel]') : null;
      if (freightQuantityAddCancel) {
        event.preventDefault();
        var cancelPanel = freightQuantityAddCancel.closest('[data-freight-quantity-extra-panel]');
        if (cancelPanel) cancelPanel.hidden = true;
        return;
      }
      var freightQuantityExistingPick = event.target.closest ? event.target.closest('[data-freight-quantity-existing-pick]') : null;
      if (freightQuantityExistingPick) {
        event.preventDefault();
        var pickPanel = freightQuantityExistingPick.closest('[data-freight-quantity-extra-panel]');
        fillFreightQuantityExtraFromMatch(pickPanel, pickPanel && pickPanel._matches ? pickPanel._matches[Number(freightQuantityExistingPick.getAttribute('data-freight-quantity-existing-pick'))] : null);
        return;
      }
      var freightQuantityAddSave = event.target.closest ? event.target.closest('[data-freight-quantity-add-save]') : null;
      if (freightQuantityAddSave) {
        event.preventDefault();
        saveFreightQuantityExtraItem(freightQuantityAddSave.closest('[data-freight-quantity-confirm]'));
        return;
      }
      var freightQuantitySave = event.target.closest ? event.target.closest('[data-freight-quantity-confirm-save]') : null;
      if (freightQuantitySave) {
        event.preventDefault();
        saveFreightQuantityConfirmation(freightQuantitySave);
        return;
      }`;

const CSS_PATCH = `
${CSS_MARKER}
.freight-quantity-confirm-head-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;flex:0 0 auto}
.freight-quantity-confirm-head-actions .primary-button{min-height:42px;padding:0 14px;white-space:nowrap}
.freight-quantity-extra{margin:0 0 12px}
.freight-quantity-extra-panel{display:grid;gap:10px;margin:0;padding:14px;border:2px solid #49a38f;border-radius:14px;background:#eefaf6;color:#14342f}
.freight-quantity-extra-panel[hidden]{display:none!important}
.freight-quantity-extra-panel>header{display:grid;gap:4px}
.freight-quantity-extra-panel>header b{color:#0f5c4c;font-size:16px}
.freight-quantity-extra-panel>header span{color:#2f5d54;font-weight:700;line-height:1.45}
.freight-quantity-extra-panel label{display:grid;gap:4px;margin:0;padding:0;background:transparent;color:#0f5c4c;font-weight:800}
.freight-quantity-extra-panel input,.freight-quantity-extra-panel input[type="search"],.freight-quantity-extra-panel input[type="number"]{width:100%;min-height:40px;padding:8px 10px;border:1px solid #9bc9be;border-radius:10px;background:#fff;color:#14231f}
.freight-quantity-extra-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.freight-quantity-extra-photo{grid-column:1/-1}
.freight-quantity-extra-preview{display:block;margin-top:6px}
.freight-quantity-extra-preview img{max-width:120px;max-height:120px;object-fit:cover;border-radius:10px;background:#fff}
.freight-quantity-extra-results{display:grid;gap:6px;max-height:220px;overflow:auto}
.freight-quantity-extra-results p{margin:0;color:#2f5d54}
.freight-quantity-extra-results button{display:grid;grid-template-columns:48px minmax(0,1fr);gap:2px 10px;align-items:center;width:100%;padding:8px;border:1px solid #b7d8cf;border-radius:10px;background:#fff;color:#14231f;text-align:left;cursor:pointer}
.freight-quantity-extra-results button img,.freight-quantity-extra-results button>span:first-child{grid-row:1/span 3;width:48px;height:48px;object-fit:cover;border-radius:8px;background:#dceee9}
.freight-quantity-extra-results button b{color:#0f5c4c}
.freight-quantity-extra-results button small{color:#4d726a}
.freight-quantity-extra-actions{display:flex;justify-content:flex-end;gap:8px}
@media(max-width:720px){
  .freight-quantity-extra-grid{grid-template-columns:1fr}
  .freight-quantity-confirm-head-actions{width:100%}
  .freight-quantity-confirm-head-actions .primary-button,.freight-quantity-confirm-head-actions .ghost-button{flex:1}
}
`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "utf8");
    if (!/admin\\.js|admin\\.css/.test(html) && !/admin\.js|admin\.css/.test(html)) continue;
    const next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, `admin.js?v=${STAMP}`)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, `admin.css?v=${STAMP}`);
    if (next === html) continue;
    fs.writeFileSync(file, next);
    n++;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

function main() {
  console.log("backup js", backup(JS, "arrival-extra"));
  console.log("backup css", backup(CSS, "arrival-extra"));
  let js = fs.readFileSync(JS, "utf8");
  if (js.includes(JS_MARKER)) {
    console.log("js already patched");
  } else {
    js = replaceOnce(
      js,
      "  function openFreightQuantityConfirmation(batchId, trackingNo) {",
      HELPERS + "  function openFreightQuantityConfirmation(batchId, trackingNo) {",
      "helpers"
    );
    js = replaceOnce(js, HEADER_OLD, HEADER_NEW, "modal header+panel");
    js = replaceOnce(js, BIND_OLD, BIND_NEW, "bind extra panel");
    js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "click handlers");
    fs.writeFileSync(JS, js);
    console.log("js written", js.length);
    try {
      new Function(js);
      console.log("js syntax ok");
    } catch (error) {
      throw new Error("admin.js syntax: " + error.message);
    }
  }
  let css = fs.readFileSync(CSS, "utf8");
  if (css.includes(CSS_MARKER)) {
    console.log("css already patched");
  } else {
    fs.writeFileSync(CSS, css.replace(/\s*$/, "") + "\n" + CSS_PATCH);
    console.log("css patched", fs.statSync(CSS).size);
  }
  stampHtml(ROOT);
}

main();
