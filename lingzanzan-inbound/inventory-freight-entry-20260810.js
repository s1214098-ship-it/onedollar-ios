(function () {
  'use strict';

  var page = document.querySelector('[data-inventory-freight-entry]');
  var root = document.querySelector('[data-inventory-entry-page]');
  if (!page || !root) return;

  var state = { freight: { batches: [], items: [] }, inquiries: [], products: [], skus: [], suppliers: [], platforms: [], categories: [], categoryEnglishNames: {}, receipts: [], standaloneLines: [], standaloneImages: {}, arrivalImages: {}, selectedStandaloneSku: null, lastReceivedPrintLines: [], lastReceivedPrintRows: [], lastReceivedSummary: null, receivedPrintPage: false, ready: false, stockDocsType: 'in', stockDocsPage: 1, outboundRows: [], outboundTotal: 0, outboundError: '' };
  var STOCK_DOCS_PAGE_SIZE = 20;
  var renderTimer = 0;

  function text(value) { return String(value == null ? '' : value).trim(); }
  function key(value) { return text(value).toLowerCase().replace(/[^a-z0-9\u4e00-\u9fff]+/g, ''); }
  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
    });
  }
  function money(value) { return 'NT$' + Math.round(Number(value || 0)).toLocaleString('zh-TW'); }

  function selfUseWarehouseCode(value) {
    var normalized = text(value).toUpperCase();
    if (normalized === 'CN' || normalized.indexOf('中國') !== -1 || normalized.indexOf('CHINA') !== -1) return 'CN';
    if (normalized === 'ID' || normalized.indexOf('印尼') !== -1 || normalized.indexOf('INDONESIA') !== -1) return 'ID';
    return 'TW';
  }

  function fetchJson(url) {
    return fetch(url, { cache: 'no-store' }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok || (payload && payload.ok === false)) throw new Error((payload && payload.error) || ('HTTP ' + response.status));
        return payload;
      });
    });
  }

  function postJson(payload) {
    var token = currentSessionToken();
    var headers = { 'Content-Type': 'application/json' };
    if (token) {
      headers.Authorization = 'Bearer ' + token;
      headers['X-Lingzanzan-Admin-Session'] = token;
    }
    return fetch('./stock-inquiry-api.php', {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (response) {
      return response.json().then(function (result) {
        if (!response.ok || !result.ok) throw new Error(result.error || '儲存失敗');
        return result;
      });
    });
  }

  function normalizeFreightData(payload) {
    var source = payload && payload.data && !Array.isArray(payload.data) ? payload.data : payload;
    if (source && source.data && !Array.isArray(source.data)) source = source.data;
    return source && (Array.isArray(source.items) || Array.isArray(source.batches))
      ? { batches: Array.isArray(source.batches) ? source.batches : [], items: Array.isArray(source.items) ? source.items : [] }
      : { batches: [], items: [] };
  }

  function orderById(id) {
    return state.inquiries.find(function (row) { return text(row.id) === text(id); }) || null;
  }

  function skuById(id) {
    return state.skus.find(function (row) { return text(row.id || row.sku) === text(id); }) || null;
  }

  function batchById(id) {
    return state.freight.batches.find(function (row) { return text(row.id) === text(id); }) || null;
  }

  function itemById(id) {
    return state.freight.items.find(function (row) { return text(row.id) === text(id); }) || null;
  }

  function freightValue(row, kind) {
    if (!row) return '';
    if (kind === 'batch') return text(row.id || row.customsNo || row.consolidationNo);
    return text(row.trackingNo || row.haohongTrackingNo || row.id);
  }

  function resolveFreight(value) {
    var wanted = key(value);
    if (!wanted) return null;
    var batch = state.freight.batches.find(function (row) {
      return [row.id, row.customsNo, row.consolidationNo, row.firstTrackingNo].some(function (field) { return key(field) === wanted; });
    });
    if (batch) return { kind: 'batch', batch: batch, item: null };
    var item = state.freight.items.find(function (row) {
      return [row.id, row.trackingNo, row.haohongTrackingNo, row.sampleBarcode, row.taiwanBarcode].some(function (field) { return key(field) === wanted; });
    });
    if (!item) return null;
    return { kind: 'item', batch: batchById(item.batchId), item: item };
  }

  function batchPackageRows(batch) {
    if (!batch) return [];
    var liveRows = state.freight.items.filter(function (row) {
      return text(row.batchId) === text(batch.id);
    });
    var snapshotRows = Array.isArray(batch.packageRows) ? batch.packageRows : [];
    var seen = new Set();
    return liveRows.concat(snapshotRows).filter(function (row, index) {
      var identity = text(row.sourceItemId || row.id) || [
        row.trackingNo,
        row.productCode || row.productName,
        row.color || row.colorName,
        row.size || row.sizeName,
        row.quantity || row.qty || 1
      ].map(key).join('|') || ('row-' + index);
      if (seen.has(identity)) return false;
      seen.add(identity);
      return true;
    });
  }

  function freightItemsForEntry(entry) {
    if (!entry) return [];
    if (entry.kind === 'batch') {
      return batchPackageRows(entry.batch);
    }
    var selected = entry.item || {};
    var trackingKey = key(selected.trackingNo || selected.haohongTrackingNo);
    if (!trackingKey) return selected.id ? [selected] : [];
    return state.freight.items.filter(function (row) {
      return [row.trackingNo, row.haohongTrackingNo].some(function (value) {
        return key(value) === trackingKey;
      });
    });
  }

  function matchingOrdersForFreight(items) {
    return state.inquiries.filter(function (order) {
      var isPreorder = text(order.orderType) === 'preorder' || text(order.status) === 'preorder_pending';
      if (!isPreorder || order.inventoryReceived) return false;
      return items.some(function (item) { return itemMatchesOrder(item, order); });
    });
  }

  function globalFreightLineHtml(item) {
    var qty = Math.max(1, Number(item.quantity || item.qty || 1));
    return [
      '<article class="inventory-freight-global-line">',
      '<div><b>' + escapeHtml(item.productCode || item.productName || '未填產品編號') + '</b><span>' + escapeHtml(item.productName || '未填產品名稱') + '</span></div>',
      '<span>顏色<b>' + escapeHtml(item.colorName || item.color || '-') + '</b></span>',
      '<span>尺寸<b>' + escapeHtml(item.sizeName || item.size || 'NO SIZE') + '</b></span>',
      '<span>數量<b>' + qty + '</b></span>',
      '<button type="button" class="ghost-button" data-freight-product-draft="' + escapeHtml(item.id || '') + '">帶入產品建檔</button>',
      '</article>'
    ].join('');
  }

  function renderGlobalFreightResult(value) {
    var result = document.querySelector('[data-inventory-freight-global-result]');
    if (!result) return;
    var entry = resolveFreight(value);
    if (!entry) {
      result.className = 'inventory-freight-global-result is-error';
      result.innerHTML = '<b>找不到這個物流單號。</b><span>請確認號碼完整，或先到物流集運建立該筆物流資料。</span>';
      return;
    }
    var items = freightItemsForEntry(entry);
    var totalQty = items.reduce(function (sum, item) { return sum + Math.max(1, Number(item.quantity || item.qty || 1)); }, 0);
    var selectedValue = entry.kind === 'batch' ? freightValue(entry.batch, 'batch') : freightValue(entry.item, 'item');
    result.className = 'inventory-freight-global-result is-found';
    result.innerHTML = [
      '<header><div><b>已找到物流單號 ' + escapeHtml(selectedValue) + '</b><span>' + items.length + ' 個品項／共 ' + totalQty + ' 件</span></div><em>' + escapeHtml((entry.batch && entry.batch.forwarder) || (entry.item && (entry.item.forwarder || entry.item.progress)) || '未填集運商') + '</em></header>',
      '<div class="inventory-freight-global-lines">' + items.map(globalFreightLineHtml).join('') + '</div>',
      '<footer><button type="button" class="primary-button" data-apply-global-freight-document="' + escapeHtml(selectedValue) + '">帶入正式進貨單</button><b>客戶配對由物流集運頁統一處理</b><span>沒有客戶訂單也可入庫，系統會保存為公司現貨／樣品。</span></footer>'
    ].join('');
  }

  function applyGlobalFreightToOrder(orderId, value) {
    var host = document.querySelector('[data-inventory-freight-order="' + CSS.escape(orderId) + '"]') || ensureInventoryOrderHost(orderId);
    if (host && !host.querySelector('[data-inventory-freight-link]')) renderOrder(host);
    var input = host && host.querySelector('[data-inventory-freight-link]');
    if (!host || !input) {
      var result = document.querySelector('[data-inventory-freight-global-result]');
      if (result) result.insertAdjacentHTML('beforeend', '<p class="inventory-freight-message is-error">找不到這張採購單卡片，請清除篩選條件後再試。</p>');
      return;
    }
    input.value = value;
    refreshPreview(host);
    host.classList.add('is-requested-order');
    if (host.scrollIntoView) host.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.setTimeout(function () { input.focus(); }, 150);
  }

  function inferredBatch(entry) {
    if (entry && entry.batch) return entry.batch;
    var item = entry && entry.item;
    var words = [item && item.progress, item && item.haohongTrackingNo, item && item.forwarder].join(' ');
    if (/豪鴻/.test(words)) return { id: '', costMode: 'haohong_weight', forwarder: '豪鴻物流', warehouseTaxType: 'tax_exempt', chargeType: 'shipping', amount: 0, itemCount: 0 };
    if (/自提/.test(words)) return { id: '', costMode: 'self_pickup', forwarder: '自提取回', warehouseTaxType: 'tax_exempt', chargeType: 'none', amount: 30, itemCount: 1, allocationPerItem: 30 };
    return null;
  }

  function chargeLabel(batch) {
    if (!batch) return '尚未建立集運批次';
    if (batch.costMode === 'haohong_weight') return '免稅／豪鴻重量運費';
    if (batch.costMode === 'self_pickup') return '自提／每件 NT$30';
    if (batch.chargeType === 'shipping') return '免稅、但有集運運費';
    if (batch.chargeType === 'none') return '免稅、也免運費';
    return '免運費、但有關稅／手續費';
  }

  function calculate(item, batch) {
    var rules = window.LingzanzanFreightCostRules;
    if (rules && typeof rules.calculate === 'function') return rules.calculate(item || {}, batch || null);
    var base = item && item.fixedTwdCost && Number(item.existingTwdCost || 0) > 0 ? Number(item.existingTwdCost) : Number(item && item.costRmb || 0) * 5;
    var allocation = batch && batch.chargeType !== 'none' && Number(batch.itemCount || 0) > 0 ? Number(batch.amount || 0) / Number(batch.itemCount) : 0;
    var shipping = batch && batch.costMode === 'haohong_weight' ? Number(item && item.weightKg || 0) * 8 * 5 : 0;
    return { base: base, allocation: allocation, haohongShipping: shipping, companyInternalCost: base + allocation + shipping };
  }

  function itemMatchesOrder(freightItem, order) {
    var freightKeys = [freightItem.productCode, freightItem.sampleBarcode, freightItem.taiwanBarcode, freightItem.productName].map(key).filter(Boolean);
    return (order.items || []).some(function (item) {
      var itemKeys = [item.code, item.sku, item.skuId, item.productId, item.title].map(key).filter(Boolean);
      return freightKeys.some(function (left) { return itemKeys.some(function (right) { return left === right || (left.length > 3 && right.indexOf(left) !== -1) || (right.length > 3 && left.indexOf(right) !== -1); }); });
    });
  }

  function fillOptions() {
    var list = document.querySelector('[data-inventory-freight-options]');
    if (!list) return;
    var batchOptions = state.freight.batches.map(function (batch) {
      var value = freightValue(batch, 'batch');
      var label = ['集運批次', batch.date, batch.forwarder, batch.customsNo || batch.consolidationNo, chargeLabel(batch)].filter(Boolean).join(' / ');
      return value ? '<option value="' + escapeHtml(value) + '" label="' + escapeHtml(label) + '"></option>' : '';
    });
    var itemOptions = state.freight.items.map(function (item) {
      var value = freightValue(item, 'item');
      var label = ['物流單號', item.progress || item.forwarder, item.productCode || item.productName, item.colorName || item.color, item.sizeName || item.size].filter(Boolean).join(' / ');
      return value ? '<option value="' + escapeHtml(value) + '" label="' + escapeHtml(label) + '"></option>' : '';
    });
    list.innerHTML = batchOptions.concat(itemOptions).join('');
    var summary = document.querySelector('[data-inventory-freight-summary]');
    if (summary) summary.textContent = state.freight.batches.length + ' 個集運批次／' + state.freight.items.length + ' 筆物流單號可帶入';
  }

  function savedFreightValue(order) {
    return text(order.freightTrackingNo || order.freightBatchId || order.supplierTrackingNo);
  }

  function suggestedFreight(order) {
    var matches = state.freight.items.filter(function (item) { return itemMatchesOrder(item, order); });
    return matches.length === 1 ? freightValue(matches[0], 'item') : '';
  }

  function arrivalKey(order, index) { return text(order && order.id) + ':' + index; }

  function requiresArrivalProof(order, entry) {
    var item = entry && entry.item || {};
    var source = [order && order.sales, order && order.salesName, order && order.purchasePlatform, order && order.platform, item.platform, item.importSource, item.ownerNote].join(' ');
    return /拚張|拚A|張張/i.test(source);
  }

  function receiptImageData(file) {
    return new Promise(function (resolve, reject) {
      if (!file) return resolve('');
      var reader = new FileReader();
      reader.onload = function () {
        var image = new Image();
        image.onload = function () {
          var max = 960;
          var scale = Math.min(1, max / Math.max(image.width || max, image.height || max));
          var canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(image.width * scale));
          canvas.height = Math.max(1, Math.round(image.height * scale));
          canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
          resolve(canvas.toDataURL('image/jpeg', .7));
        };
        image.onerror = function () { resolve(String(reader.result || '')); };
        image.src = String(reader.result || '');
      };
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  function receiptImageAssetKey(src) {
    var textValue = text(src);
    var match = textValue.match(/img-[a-f0-9]+/i);
    return match ? match[0].toLowerCase() : textValue;
  }

  function receiptColorLabelMatch(a, b) {
    var za = receiptChineseColor(a);
    var zb = receiptChineseColor(b);
    var ka = key(a);
    var kb = key(b);
    var kza = key(za);
    var kzb = key(zb);
    if (ka && kb && ka === kb) return true;
    if (kza && kzb && kza === kzb) return true;
    if (kza && kb && kza.length >= 2 && (kb.indexOf(kza) === 0 || kza.indexOf(kb) === 0)) return true;
    if (kzb && ka && kzb.length >= 2 && (ka.indexOf(kzb) === 0 || kzb.indexOf(ka) === 0)) return true;
    return false;
  }

  function firstProductImage(product, sku, source) {
    /* LZ_K325_COLOR_IMG_20260924: 先對顏色圖，不要拿主圖／紅色蓋掉黑色、白色。 */
    product = product || {};
    sku = sku || {};
    source = source || {};
    var wantedColor = text(source.colorName || source.color || sku.colorName || sku.color);
    var productColors = Array.isArray(product.colors) ? product.colors : [];
    var matched = [];
    var otherColorImages = [];
    productColors.forEach(function (color) {
      var labels = [color && color.colorName, color && color.name, color && color.color, color && color.code];
      var hit = !wantedColor || labels.some(function (label) { return label && receiptColorLabelMatch(wantedColor, label); });
      if (!hit && wantedColor && sku.colorCode && text(color && (color.code || color.colorCode)) === text(sku.colorCode)) hit = true;
      var img = color && (color.image || color.colorImage || color.imageUrl || color.photo);
      if (hit) matched.push(img);
      else if (img) otherColorImages.push(receiptImageAssetKey(img));
    });
    var fallbacks = [
      sku.colorImage, sku.lastArrivalImage, sku.image, sku.imageUrl, sku.photo
    ];
    if (!wantedColor) {
      fallbacks = fallbacks.concat([source.productImage, source.image, source.imageUrl, source.photo, product.mainImage, product.coverImage, product.image, product.imageUrl, product.photo]);
    }
    var wantedProduct = key(product.code || product.productLine || product.id || source.productCode || source.catalogProductCode);
    (state.freight && Array.isArray(state.freight.items) ? state.freight.items : []).forEach(function (item) {
      var itemProduct = key(item && (item.catalogProductCode || item.productCode || item.productFiledProductCode));
      var itemColor = text(item && (item.colorName || item.color));
      if (!wantedProduct || itemProduct !== wantedProduct) return;
      if (wantedColor && itemColor && !receiptColorLabelMatch(wantedColor, itemColor)) return;
      fallbacks.push(item.colorImage, item.productImage, item.image, item.mainImage);
      if (Array.isArray(item.productImages)) fallbacks = fallbacks.concat(item.productImages);
      if (Array.isArray(item.images)) fallbacks = fallbacks.concat(item.images);
    });
    if (Array.isArray(sku.images)) fallbacks = fallbacks.concat(sku.images);
    if (wantedColor) fallbacks = fallbacks.concat([product.mainImage, product.coverImage, product.image]);
    if (Array.isArray(product.images)) fallbacks = fallbacks.concat(product.images);
    var candidates = matched.concat(fallbacks);
    for (var i = 0; i < candidates.length; i += 1) {
      var candidate = candidates[i];
      if (candidate && typeof candidate === 'object') candidate = candidate.url || candidate.src || candidate.image || candidate.data || '';
      candidate = text(candidate);
      if (!candidate) continue;
      if (wantedColor && otherColorImages.indexOf(receiptImageAssetKey(candidate)) !== -1) continue;
      return candidate;
    }
    return '';
  }

  function receiptFullImage(src) {
    var url = text(src);
    if (!url) return '';
    return url.replace(/\/medium\//g, '/original/').replace(/\/thumb\//g, '/original/').replace(/\/small\//g, '/original/');
  }

  function receiptThumbZoomOverlay() {
    return document.querySelector('.receipt-thumb-zoom');
  }

  function receiptThumbZoomTarget(node) {
    if (!node || !node.closest) return null;
    var el = node.closest('[data-receipt-thumb-zoom]');
    if (!el || el.classList.contains('receipt-thumb-zoom')) return null;
    var url = text(el.getAttribute('data-receipt-thumb-zoom') || el.currentSrc || el.src || '');
    return url ? el : null;
  }

  function hideReceiptThumbHover() {
    var hover = document.querySelector('[data-receipt-thumb-hover]');
    if (hover) hover.classList.remove('is-open');
  }

  function ensureReceiptThumbHover() {
    var hover = document.querySelector('[data-receipt-thumb-hover]');
    if (hover) return hover;
    hover = document.createElement('div');
    hover.className = 'receipt-thumb-hover';
    hover.setAttribute('data-receipt-thumb-hover', '');
    hover.setAttribute('aria-hidden', 'true');
    hover.innerHTML = '<img alt="" data-receipt-thumb-hover-img draggable="false">';
    document.body.appendChild(hover);
    return hover;
  }

  function positionReceiptThumbHover(hover, anchor) {
    var pad = 10;
    var vw = window.innerWidth || document.documentElement.clientWidth || 0;
    var vh = window.innerHeight || document.documentElement.clientHeight || 0;
    var rect = anchor.getBoundingClientRect();
    var box = hover.getBoundingClientRect();
    var w = box.width || 280;
    var h = box.height || 280;
    var left = rect.right + 12;
    var top = rect.top + (rect.height / 2) - (h / 2);
    if (left + w + pad > vw) left = rect.left - w - 12;
    if (left < pad) left = pad;
    if (top < pad) top = pad;
    if (top + h + pad > vh) top = Math.max(pad, vh - h - pad);
    hover.style.left = Math.round(left) + 'px';
    hover.style.top = Math.round(top) + 'px';
  }

  function showReceiptThumbHover(anchor) {
    /* LZ_THUMB_HOVER_20260924: 滑鼠移上縮圖就放大，不勾選該列。 */
    if (!anchor || document.body.classList.contains('is-printing-received-barcodes')) return;
    var url = text(anchor.getAttribute('data-receipt-thumb-zoom') || anchor.currentSrc || anchor.src || '');
    if (!url) return;
    var caption = text(anchor.getAttribute('data-receipt-thumb-caption') || anchor.getAttribute('alt') || '');
    var hover = ensureReceiptThumbHover();
    var img = hover.querySelector('[data-receipt-thumb-hover-img]');
    var full = receiptFullImage(url);
    img.onload = function () { positionReceiptThumbHover(hover, anchor); };
    img.onerror = function () {
      if (img.getAttribute('src') !== url) img.src = url;
    };
    if (img.getAttribute('src') !== (full || url) && img.getAttribute('src') !== url) img.src = full || url;
    img.alt = caption || '產品縮圖放大';
    hover.classList.add('is-open');
    positionReceiptThumbHover(hover, anchor);
  }

  function closeReceiptThumbZoom() {
    var overlay = receiptThumbZoomOverlay();
    if (overlay) overlay.classList.remove('is-open');
  }

  function openReceiptThumbZoom(src, caption) {
    /* LZ_THUMB_ZOOM_20260924: 進貨單縮圖點一下放大，不勾選該列。 */
    var url = text(src);
    if (!url) return false;
    hideReceiptThumbHover();
    var overlay = receiptThumbZoomOverlay();
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'receipt-thumb-zoom';
      overlay.setAttribute('data-receipt-thumb-zoom-layer', '');
      overlay.innerHTML = '<button type="button" class="receipt-thumb-zoom-close" data-receipt-thumb-zoom-close aria-label="關閉放大圖">×</button>'
        + '<figure><img alt="產品縮圖放大" data-receipt-thumb-zoom-img><figcaption data-receipt-thumb-zoom-caption></figcaption></figure>';
      document.body.appendChild(overlay);
      overlay.addEventListener('click', function (event) {
        if (event.target === overlay || event.target.closest('[data-receipt-thumb-zoom-close]')) {
          event.preventDefault();
          closeReceiptThumbZoom();
        }
      });
    }
    var img = overlay.querySelector('[data-receipt-thumb-zoom-img]');
    var cap = overlay.querySelector('[data-receipt-thumb-zoom-caption]');
    if (!img) return false;
    var full = receiptFullImage(url);
    img.onerror = function () {
      if (img.getAttribute('src') !== url) img.src = url;
    };
    img.src = full || url;
    img.alt = text(caption) || '產品縮圖放大';
    if (cap) cap.textContent = text(caption);
    overlay.classList.add('is-open');
    return true;
  }

  function receiptThumbButtonHtml(src, caption, extraClass) {
    var url = text(src);
    var label = text(caption);
    var cls = 'purchase-receipt-suggest-thumb' + (extraClass ? ' ' + extraClass : '');
    if (!url) return '<span class="' + cls + ' is-empty"><b>無圖</b></span>';
    return '<button type="button" class="' + cls + '" data-receipt-thumb-zoom="' + escapeHtml(url) + '" data-receipt-thumb-caption="' + escapeHtml(label) + '" title="滑鼠移上放大">'
      + '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(label) + '" loading="lazy" draggable="false">'
      + '</button>';
  }

  function receiptLinesHtml(order, entry) {
    var freightItem = entry && entry.item;
    var batch = inferredBatch(entry);
    return (order.items || []).map(function (item, index) {
      var sku = skuById(item.skuId || item.sku) || {};
      var matchedFreight = freightItem && itemMatchesOrder(freightItem, { items: [item] }) ? freightItem : null;
      var source = matchedFreight || freightItem || {};
      var storedBarcode = text(item.receivedBarcode || sku.companyBarcode || sku.barcode || item.skuId || item.sku);
      var product = productById(item.productId || sku.productId) || productByCode(item.code || item.productCode || item.title) || {};
      var barcode = canonicalInboundBarcode({
        productCode: text(item.code || item.productCode || product.code || product.productLine),
        color: item.receivedColor || source.colorName || source.color || item.color || sku.colorName || sku.color,
        size: item.receivedSize || source.sizeName || source.size || item.size || sku.sizeName || sku.size || 'NO SIZE',
        unitCostTwd: sku.currentCostTwd || sku.cost
      }, sku, product, storedBarcode) || storedBarcode;
      var color = sanitizeInboundColorValue(item.receivedColor || source.colorName || source.color || item.color || sku.colorName || sku.color, { barcode: barcode, productId: item.productId || sku.productId, skuId: item.skuId || item.sku, productCode: item.code || item.productCode }, sku, productById(item.productId || sku.productId) || productByCode(item.code || item.productCode || item.title) || {}, barcode);
      var size = text(item.receivedSize || source.sizeName || source.size || item.size || sku.sizeName || sku.size || 'NO SIZE');
      var qty = Math.max(1, Number(item.qty || item.quantity || 1));
      var cost = calculate(Object.assign({}, source, { quantity: qty, costRmb: Number(source.costRmb || sku.costRmb || 0), baseTwdCost: Number(source.costRmb ? 0 : (sku.cost || 0)), existingTwdCost: Number(source.existingTwdCost || 0), fixedTwdCost: !!source.fixedTwdCost }), batch).companyInternalCost;
      if (!cost) cost = Number(sku.cost || 0);
      var savedLine = Array.isArray(order.freightReceiptLines) && order.freightReceiptLines[index] || {};
      var arrivalImages = state.arrivalImages && typeof state.arrivalImages === 'object' ? state.arrivalImages : {};
      var arrivalImage = arrivalImages[arrivalKey(order, index)] || savedLine.arrivalImage || item.arrivalImage || '';
      var productImage = firstProductImage(product, sku, item);
      var previewImage = arrivalImage || productImage;
      var proofRequired = requiresArrivalProof(order, entry);
      return [
        '<div class="inventory-receipt-line" data-inventory-receipt-line="' + index + '">',
        '<label>產品 / SKU<input value="' + escapeHtml(item.code || item.title || item.skuId || '-') + '" readonly data-receipt-product></label>',
        '<label>公司條碼<input value="' + escapeHtml(barcode) + '" data-receipt-barcode placeholder="掃描或輸入正式條碼"></label>',
        '<label>顏色<input value="' + escapeHtml(color) + '" data-receipt-color></label>',
        '<label>尺寸<input value="' + escapeHtml(size) + '" data-receipt-size></label>',
        '<label>實收數量<input type="number" min="1" step="1" value="' + qty + '" data-receipt-qty></label>',
        '<label class="inventory-receipt-photo">圖片縮圖／到貨照片' + (proofRequired ? '（必填）' : '') + '<input type="file" accept="image/*" capture="environment" data-receipt-photo><span data-receipt-photo-preview>' + (previewImage ? '<img src="' + escapeHtml(previewImage) + '" alt="商品或到貨照片"><b>' + (arrivalImage ? '已帶入到貨照，可重新上傳' : '產品縮圖，可上傳到貨照') + '</b>' : '<b>拍照或選擇圖片</b>') + '</span></label>',
        '<input type="hidden" value="' + escapeHtml(arrivalImage) + '" data-receipt-arrival-image>',
        '<input type="hidden" value="' + (proofRequired ? '1' : '0') + '" data-receipt-proof-required>',
        '<input type="hidden" value="' + escapeHtml(item.skuId || item.sku || '') + '" data-receipt-sku-id>',
        '<input type="hidden" value="' + escapeHtml(item.productId || '') + '" data-receipt-product-id>',
        '<input type="hidden" value="' + escapeHtml(cost) + '" data-receipt-cost>',
        '<small>入庫成本 ' + money(cost) + '／件；系統會依上方實際到貨倉尋找同顏色尺寸 SKU，沒有才建立該倉品項。</small>',
        '</div>'
      ].join('');
    }).join('');
  }

  function previewHtml(order, entry) {
    if (!entry) return '<p class="inventory-freight-message is-error">尚未對到物流資料。可輸入物流單號、集運批次號或關稅號搜尋。</p>';
    var batch = inferredBatch(entry);
    var item = entry.item || {};
    var cost = calculate(item, batch);
    var linkedItems = entry.kind === 'batch' ? batchPackageRows(entry.batch).length : 1;
    var waiting = !entry.batch && entry.kind === 'item';
    return [
      '<div class="inventory-freight-preview">',
      '<span>物流來源<b>' + escapeHtml((batch && batch.forwarder) || item.progress || item.forwarder || '未填集運商') + '</b></span>',
      '<span>計費規則<b>' + escapeHtml(chargeLabel(batch)) + '</b></span>',
      '<span>批次夾帶<b>' + linkedItems + ' 筆物流明細</b></span>',
      '<span>預估每件成本<b>' + money(cost.companyInternalCost || 0) + '</b></span>',
      '</div>',
      waiting ? '<p class="inventory-freight-message is-warning">這筆物流尚未綁定集運批次；目前先帶商品成本與已知重量運費，待物流區建立批次後再帶入稅金／運費分攤。</p>' : ''
    ].join('');
  }

  function localDate(value) {
    var date = value ? new Date(value) : new Date();
    if (Number.isNaN(date.getTime())) date = new Date();
    var offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 10);
  }

  function currentOperatorName() {
    try {
      var login = JSON.parse(localStorage.getItem('lingzanzan-v1-admin-login') || '{}');
      return text(login.name || login.account) || '管理者';
    } catch (error) { return '管理者'; }
  }

  function productById(id) {
    return state.products.find(function (row) { return text(row.id) === text(id); }) || null;
  }

  function productByCode(code) {
    var wanted = key(code);
    if (!wanted) return null;
    return state.products.find(function (row) { return [row.id, row.code, row.productLine, row.title].some(function (value) { return key(value) === wanted; }); }) || null;
  }

  function productCodeForSku(sku) {
    var product = productById(sku && sku.productId);
    return text(product && (product.code || product.productLine || product.title)) || text(sku && sku.productId);
  }

  function productCategory(product) {
    return text(product && (product.category || product.categoryName || product.productCategory));
  }

  function normalizeProductCode(value) {
    return text(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
  }

  function automaticCategoryEnglishName(category) {
    var raw = text(category);
    if (!raw) return '';
    if (state.categoryEnglishNames && state.categoryEnglishNames[raw]) return state.categoryEnglishNames[raw];
    var exact = {
      '短袖上衣': 'T-Shirt', '長袖上衣': 'Long Sleeve', '長袖': 'Long Sleeve', '襯衫': 'Shirt', '背心': 'Vest', '外套': 'Jacket',
      '套裝': 'Set', '洋裝': 'Dress', '連身褲': 'Jumpsuit', '長褲': 'Pants', '短褲': 'Shorts', '裙子': 'Skirt', '內著': 'Underwear',
      '睡衣': 'Sleepwear', '男裝': 'Menswear', '女裝': 'Womenswear', '童裝': 'Kidswear', '鞋類': 'Footwear', '鞋子': 'Footwear', '拖鞋': 'Slippers',
      '包包': 'Bag', '皮夾': 'Wallet', '帽子': 'Hat', '圍巾': 'Scarf', '飾品': 'Accessories', '美髮': 'Hair', '美妝': 'Beauty',
      '化妝品': 'Cosmetics', '保養': 'Skincare', '生活用品': 'Household', '廚房用品': 'Kitchenware', '餐具': 'Tableware', '餐碗類': 'Bowl',
      '杯壺': 'Cupware', '水壺/保溫杯': 'Bottle', '收納': 'Storage', '寢具': 'Bedding', '玩具': 'Toys', '3C 周邊': 'Electronics', '食品': 'Food', '其他': 'Goods'
    };
    if (exact[raw]) return exact[raw];
    var latin = raw.replace(/[^A-Za-z]/g, '');
    if (latin.length >= 3) return latin;
    var rules = [
      [/保溫杯|水壺|水杯|瓶/, 'Bottle'], [/杯|壺/, 'Cupware'], [/碗|盤|餐具/, 'Tableware'],
      [/短袖|T恤/i, 'T-Shirt'], [/長袖/, 'Long Sleeve'], [/襯衫/, 'Shirt'], [/背心/, 'Vest'], [/外套|夾克|大衣|風衣/, 'Jacket'],
      [/洋裝|連身裙/, 'Dress'], [/套裝/, 'Set'], [/短褲/, 'Shorts'], [/長褲|褲/, 'Pants'], [/裙/, 'Skirt'],
      [/鞋/, 'Footwear'], [/帽/, 'Hat'], [/包|袋/, 'Bag'], [/皮夾|錢包|錢夾/, 'Wallet'], [/飾品|首飾/, 'Accessories'],
      [/美髮/, 'Hair'], [/化妝|美妝/, 'Cosmetics'], [/保養|護膚/, 'Skincare'], [/寢具|床包|被子/, 'Bedding'],
      [/收納/, 'Storage'], [/廚房|鍋|刀具/, 'Kitchenware'], [/玩具|公仔/, 'Toys'], [/3C|手機|電子|充電|耳機/i, 'Electronics'],
      [/食品|零食|飲料/, 'Food'], [/家居|生活|日用/, 'Household']
    ];
    for (var index = 0; index < rules.length; index += 1) if (rules[index][0].test(raw)) return rules[index][1];
    return 'Goods';
  }

  function automaticCategoryPrefix(category) {
    return automaticCategoryEnglishName(category).replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 3) || 'GOO';
  }

  function lockedCategoryPrefix(category) {
    /* LZ_OLAN_JA_20260924: 外套用 JA，水壺/保溫杯才用 OLAN。 */
    var raw = text(category);
    if (!raw) return '';
    if (raw === '外套' || /夾克|大衣|風衣/.test(raw)) return 'JA';
    if (raw === '水壺/保溫杯' || /保溫杯|冰霸杯|水壺/.test(raw)) return 'OLAN';
    return '';
  }

  function standaloneCategoryNextProductCode(category) {
    var wanted = key(category);
    if (!wanted) return { code: '', seed: '', error: '請先輸入並選擇產品分類。' };
    var candidates = state.products.map(function (product, index) {
      var code = normalizeProductCode(product.code || product.productLine || '');
      return { product: product, index: index, code: code, match: code.match(/^([A-Z]+)(\d+)$/) };
    }).filter(function (row) {
      return row.match && key(productCategory(row.product)) === wanted;
    });
    candidates.sort(function (a, b) {
      var aTime = Date.parse(a.product.createdAt || '') || Number.MAX_SAFE_INTEGER;
      var bTime = Date.parse(b.product.createdAt || '') || Number.MAX_SAFE_INTEGER;
      return aTime - bTime || a.index - b.index;
    });
    var seed = candidates[0] || null;
    var locked = lockedCategoryPrefix(category);
    var prefix = locked || (seed ? seed.match[1] : automaticCategoryPrefix(category));
    var width = 3;
    if (locked) {
      state.products.forEach(function (product) {
        var match = normalizeProductCode(product.code || product.productLine || '').match(new RegExp('^' + prefix + '(\\d+)$'));
        if (match) width = Math.max(width, match[1].length);
      });
    } else if (seed) width = seed.match[2].length;
    var reservedCodes = state.products.concat((state.standaloneLines || []).map(function (line) {
      return { code: line.productCode || '' };
    }));
    var max = reservedCodes.reduce(function (current, product) {
      var match = normalizeProductCode(product.code || product.productLine || '').match(new RegExp('^' + prefix + '(\\d+)$'));
      return match ? Math.max(current, Number(match[1]) || 0) : current;
    }, seed ? (Number(seed.match[2]) || 0) : 0);
    return { code: prefix + String(max + 1).padStart(width, '0'), seed: seed ? seed.code : (text(category) + ' → ' + automaticCategoryEnglishName(category)), prefix: prefix };
  }

  function setStandaloneCodeStatus(message, kind) {
    var host = document.querySelector('[data-purchase-receipt-code-status]');
    if (!host) return;
    host.textContent = message || '';
    host.className = 'purchase-receipt-code-status' + (kind ? ' is-' + kind : '');
  }

  function generateStandaloneProductCode(force) {
    var category = text(document.querySelector('[data-purchase-receipt-category]') && document.querySelector('[data-purchase-receipt-category]').value);
    var input = document.querySelector('[data-purchase-receipt-product-code]');
    if (!input) return '';
    if (!force && input.value && input.dataset.receiptCodeSource !== 'auto') return input.value;
    var result = standaloneCategoryNextProductCode(category);
    if (!result.code) {
      if (force || category) setStandaloneCodeStatus(result.error, 'warning');
      return '';
    }
    input.value = result.code;
    input.dataset.receiptCodeSource = 'auto';
    setStandaloneCodeStatus('中文分類自動轉換：' + result.seed + '；已產生目前未使用的下一號 ' + result.code + '。', 'ok');
    return result.code;
  }

  function standaloneMessage(message, kind) {
    var host = document.querySelector('[data-purchase-receipt-message]');
    if (!host) return;
    if (state.receivedPrintPage && kind === 'error' && /請至少加入一個進貨項目/.test(String(message || ''))) return;
    host.textContent = message;
    host.className = 'purchase-receipt-message' + (kind ? ' is-' + kind : '');
  }

  function warehouseDisplayName(value) {
    var code = selfUseWarehouseCode(value);
    if (code === 'CN') return '中國倉';
    if (code === 'ID') return '印尼倉';
    if (code === 'TW') return '台灣倉';
    return text(value) || '所選倉庫';
  }

  function uniqueTextRows(values) {
    var seen = new Set();
    return (values || []).map(text).filter(function (value) {
      var identity = key(value);
      if (!identity || seen.has(identity)) return false;
      seen.add(identity);
      return true;
    });
  }

  function freightItemAlreadyReceived(item) {
    item = item || {};
    if (item.productFiledInventoryReceived === true) return true;
    var stage = String(item.inventoryStatus || item.receivingStage || '').toLowerCase();
    if (['received', 'inventory_received', 'completed', 'posted', 'applied'].indexOf(stage) >= 0) return true;
    if (/完成入庫|已帶入產品完成入庫/.test(String(item.progress || ''))) return true;
    var claim = item.inventoryReceiptClaim && typeof item.inventoryReceiptClaim === 'object' ? item.inventoryReceiptClaim : {};
    return String(claim.status || '').toLowerCase() === 'received';
  }

  function skuAlreadyCoversFreightBarcode(barcode, color, size) {
    var wanted = key(barcode);
    var wantedColor = key(color);
    var wantedSize = key(size || 'NO SIZE');
    if (!wanted) return false;
    return (state.skus || []).some(function (sku) {
      if (sku && sku.temporaryFreightSku) return false;
      var skuBarcode = key(sku.companyBarcode || sku.barcode || sku.legacyBarcode || '');
      if (skuBarcode !== wanted && key(sku.legacyBarcode || '') !== wanted && !skuExactBarcodeHit(sku, inboundBarcodeSearchKeys(barcode))) return false;
      if (wantedColor && receiptColorFamilyKey(sku, productById(sku.productId), color) !== receiptColorFamilyKey(sku, productById(sku.productId))) return false;
      if (wantedSize && key(sku.sizeName || sku.size || 'NO SIZE') !== wantedSize) return false;
      return true;
    });
  }

  function receiptChineseColor(value) {
    return text(value)
      .replace(/[（(][^）)]*[A-Za-z][^）)]*[）)]?/g, ' ')
      .replace(/[A-Za-z][A-Za-z\s_\-/]*/g, ' ')
      .replace(/[（()）]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function receiptColorFamilyKey(sku, product, extraColor) {
    /* LZ_GB2_COLOR_20260924: 咖色／咖啡色／棕色同一色，不要因倉別或印尼別名再拆一列。 */
    sku = sku || {};
    product = product || productById(sku.productId) || {};
    var raw = text(extraColor || sku.colorName || sku.color);
    var zh = receiptChineseColor(raw);
    var code = text(sku.colorCode || sku.colorNo);
    var blob = (raw + ' ' + zh + ' ' + code).toLowerCase();
    if (code === '902' || code === '912' || /棕|咖啡|咖色|cokelat|coklat/.test(blob)) return 'c:brown';
    if (code === '91' || /黑|hitam|hitem/.test(blob)) return 'c:91';
    if (code === '92' || (/白|putih|puti/.test(blob) && !/白藍|白黑|白綠|白紅/.test(zh))) return 'c:92';
    if (code === '952' || /淺灰/.test(blob)) return 'c:952';
    if (code === '99' || /灰|abu/.test(blob)) return 'c:99';
    var colors = Array.isArray(product.colors) ? product.colors : [];
    for (var i = 0; i < colors.length; i += 1) {
      var row = colors[i] || {};
      var rowCode = text(row.code || row.colorCode);
      var rowZh = receiptChineseColor(row.name || row.colorName || row.color);
      if (code && rowCode && code === rowCode) return 'c:' + rowCode;
      if (zh && rowZh && (zh === rowZh || zh.indexOf(rowZh) !== -1 || rowZh.indexOf(zh) !== -1)) return 'c:' + (rowCode || rowZh);
    }
    return 'c:' + (code || key(zh || raw) || 'nocolor');
  }

  function receiptDisplayColor(sku, product) {
    sku = sku || {};
    product = product || productById(sku.productId) || {};
    var family = receiptColorFamilyKey(sku, product);
    var colors = Array.isArray(product.colors) ? product.colors : [];
    var named = {
      'c:91': '黑色',
      'c:92': '白色',
      'c:952': '淺灰色',
      'c:99': '灰色'
    };
    if (family === 'c:brown') {
      var brown = colors.find(function (row) { return /棕/.test(text(row && (row.name || row.color || row.colorName))); })
        || colors.find(function (row) { return text(row && (row.code || row.colorCode)) === '902' || text(row && (row.code || row.colorCode)) === '912'; });
      if (brown) {
        var brownName = receiptChineseColor(brown.name || brown.colorName || brown.color) || text(brown.name || brown.color);
        if (/棕/.test(brownName) || key(product.code || product.productLine) === 'gb2') return /棕/.test(brownName) ? brownName : '棕色';
        return brownName || '棕色';
      }
      return key(product.code || product.productLine) === 'gb2' ? '棕色' : '咖啡色';
    }
    for (var i = 0; i < colors.length; i += 1) {
      var row = colors[i] || {};
      var rowCode = text(row.code || row.colorCode);
      var rowName = receiptChineseColor(row.name || row.colorName || row.color) || text(row.name || row.colorName || row.color);
      if (receiptColorFamilyKey({ colorName: row.name || row.color, colorCode: rowCode }, product) === family) {
        return rowName || named[family] || rowName;
      }
    }
    return named[family] || receiptChineseColor(sku.colorName || sku.color) || text(sku.colorName || sku.color) || '未填顏色';
  }

  function receiptWarehouseCode(sku) {
    var w = text(sku && (sku.warehouseCode || sku.warehouse || sku.warehouseName)).toUpperCase();
    if (w === 'TW' || w.indexOf('台灣') !== -1 || w.indexOf('TAIWAN') !== -1) return 'TW';
    if (w === 'CN' || w.indexOf('中國') !== -1 || w.indexOf('CHINA') !== -1) return 'CN';
    if (w === 'ID' || w.indexOf('印尼') !== -1 || w.indexOf('INDONESIA') !== -1) return 'ID';
    if (w === 'PREORDER' || w.indexOf('預購') !== -1 || (sku && (sku.preorderOnly || sku.temporaryFreightSku))) return 'PREORDER';
    return w || 'TW';
  }

  function receiptWantedWarehouse() {
    var el = document.querySelector('[data-purchase-receipt-warehouse]');
    var raw = text(el && el.value).toUpperCase();
    if (raw === 'CN') return 'CN';
    if (raw === 'ID') return 'ID';
    return 'TW';
  }

  function preferredReceiptSku(skus) {
    var wanted = receiptWantedWarehouse();
    return (skus || []).slice().sort(function (a, b) {
      var aw = receiptWarehouseCode(a);
      var bw = receiptWarehouseCode(b);
      return Number(bw === wanted) - Number(aw === wanted)
        || Number(Number(b.stock || 0) > 0) - Number(Number(a.stock || 0) > 0)
        || Number(!!a.temporaryFreightSku) - Number(!!b.temporaryFreightSku)
        || Number(aw === 'PREORDER') - Number(bw === 'PREORDER')
        || Number(bw === 'TW') - Number(aw === 'TW');
    })[0] || null;
  }

  function receiptProductLineCode(sku) {
    /* LZ_WH_STOCK_20260924: group by 款號, not warehouse SKU id / barcode-as-code. */
    sku = sku || {};
    var product = productById(sku.productId) || productByCode(sku.productCode || sku.productId) || {};
    var line = text(product.productLine || sku.productLine);
    if (line) return line.toUpperCase().replace(/[^A-Z0-9]/g, '');
    var code = text(product.code || sku.productCode || '');
    var compact = code.toUpperCase().replace(/[^A-Z0-9]/g, '');
    var parsed = compact ? receivedParseConcatBarcode(compact, {}, sku, product) : null;
    if (parsed && parsed.base && parsed.base.length < compact.length) return String(parsed.base).toUpperCase();
    if (compact && !looksLikeBarcodeQuery(compact)) return compact;
    return compact || text(product.id || sku.productId || '').toUpperCase();
  }

  function receiptProductGroupKey(sku) {
    sku = sku || {};
    var product = productById(sku.productId) || productByCode(sku.productCode || sku.productId) || {};
    var size = key(sku.sizeName || sku.size || 'NOSIZE') || 'nosize';
    return key(receiptProductLineCode(sku) || product.id || sku.productId || '') + '|' + receiptColorFamilyKey(sku, product) + '|' + size;
  }

  function receiptWeakProductImage(src) {
    return /brand-logo|placeholder|no[-_]?image/i.test(text(src));
  }

  function receiptGroupCatalogImage(list) {
    /* 預購倉 empty / freight temp must reuse 中國倉 catalog color photo. */
    var ranked = (list || []).slice().sort(function (a, b) {
      return Number(!!a.temporaryFreightSku) - Number(!!b.temporaryFreightSku)
        || Number(receiptWarehouseCode(a) === 'PREORDER') - Number(receiptWarehouseCode(b) === 'PREORDER')
        || Number(receiptWarehouseCode(b) === 'CN') - Number(receiptWarehouseCode(a) === 'CN');
    });
    var fallback = '';
    for (var i = 0; i < ranked.length; i += 1) {
      var sku = ranked[i] || {};
      var product = productById(sku.productId) || productByCode(sku.productCode) || {};
      if (product.temporaryFreightProduct) product = productByCode(product.productLine || product.code) || product;
      var img = firstProductImage(product, sku, sku) || text(sku.colorImage || sku.lastArrivalImage || sku.image || sku.imageUrl || product.mainImage);
      if (!img) continue;
      if (receiptWeakProductImage(img)) {
        if (!fallback) fallback = img;
        continue;
      }
      return img;
    }
    return fallback;
  }

  function receiptGroupProductCode(list, chosen) {
    var sku = chosen || (list && list[0]) || {};
    var line = receiptProductLineCode(sku);
    if (line) return line;
    for (var i = 0; i < (list || []).length; i += 1) {
      line = receiptProductLineCode(list[i]);
      if (line) return line;
    }
    return productCodeForSku(sku);
  }

  function receiptGroupCanonicalBarcode(list, chosen) {
    var wanted = receiptWantedWarehouse();
    var byWh = {};
    (list || []).forEach(function (sku) {
      var w = receiptWarehouseCode(sku);
      if (!byWh[w] || (byWh[w].temporaryFreightSku && !sku.temporaryFreightSku)) byWh[w] = sku;
    });
    var pick = byWh[wanted] || byWh.CN || byWh.TW || byWh.ID || byWh.PREORDER || chosen;
    return text(pick && (pick.companyBarcode || pick.barcode || pick.officialBarcode || pick.id || pick.sku));
  }

  function attachReceiptWarehouseGroup(chosen, list) {
    var stocks = { CN: 0, TW: 0, ID: 0, PREORDER: 0 };
    var byWh = { CN: null, TW: null, ID: null, PREORDER: null };
    var seen = {};
    (list || []).forEach(function (sku) {
      if (!sku) return;
      var id = text(sku.id || sku.sku || sku.companyBarcode || sku.barcode);
      if (id && seen[id]) return;
      if (id) seen[id] = true;
      var w = receiptWarehouseCode(sku);
      if (!Object.prototype.hasOwnProperty.call(stocks, w)) w = 'TW';
      stocks[w] += Math.max(0, Number(sku.stock || 0));
      var cur = byWh[w];
      if (!cur) byWh[w] = sku;
      else if (cur.temporaryFreightSku && !sku.temporaryFreightSku) byWh[w] = sku;
      else if (Number(sku.stock || 0) > Number(cur.stock || 0)) byWh[w] = sku;
    });
    chosen.receiptWarehouseStocks = stocks;
    chosen.receiptWarehouseSkus = byWh;
    chosen.receiptGroupSkus = list;
    chosen.receiptGroupImage = receiptGroupCatalogImage(list);
    chosen.receiptGroupProductCode = receiptGroupProductCode(list, chosen);
    chosen.receiptGroupBarcode = receiptGroupCanonicalBarcode(list, chosen);
    return chosen;
  }

  function receiptSkuForInbound(sku) {
    if (!sku) return sku;
    var wanted = receiptWantedWarehouse();
    var byWh = sku.receiptWarehouseSkus || {};
    var group = sku.receiptGroupSkus || [];
    var real = group.filter(function (row) {
      return row && receiptWarehouseCode(row) !== 'PREORDER' && !row.temporaryFreightSku;
    });
    var wantedSku = byWh[wanted];
    if (wantedSku && !wantedSku.temporaryFreightSku) return attachReceiptWarehouseGroup(wantedSku, group);
    var preferred = preferredReceiptSku(real.length ? real : group);
    return preferred ? attachReceiptWarehouseGroup(preferred, group) : sku;
  }

  function collapseStandaloneSkuMatches(skus) {
    /* LZ_WH_STOCK_20260924: one row per 款+色+尺, four warehouse stocks on the card. */
    var groups = {};
    var order = [];
    (skus || []).forEach(function (sku) {
      if (!sku) return;
      var groupKey = receiptProductGroupKey(sku);
      if (!groups[groupKey]) {
        groups[groupKey] = [];
        order.push(groupKey);
      }
      groups[groupKey].push(sku);
    });
    return order.map(function (groupKey) {
      var list = groups[groupKey];
      var real = list.filter(function (sku) {
        return receiptWarehouseCode(sku) !== 'PREORDER' && !sku.temporaryFreightSku;
      });
      var chosen = preferredReceiptSku(real.length ? real : list);
      return chosen ? attachReceiptWarehouseGroup(chosen, list) : null;
    }).filter(Boolean);
  }

  function skuAlreadyCoversFreightItem(item) {
    item = item || {};
    var barcode = text(item.customerSupplyBarcode || item.taiwanBarcode || item.sampleBarcode || item.productFiledBarcode);
    var color = text(item.color || item.colorName);
    if (skuAlreadyCoversFreightBarcode(barcode, color, item.size || item.sizeName)) return true;
    var code = text(item.catalogProductCode || item.productCode || item.productFiledProductCode);
    var product = productByCode(code) || productByCode(item.productName);
    if (!product) return false;
    var size = key(item.size || item.sizeName || 'NOSIZE') || 'nosize';
    var family = receiptColorFamilyKey({
      colorName: color,
      colorCode: item.colorCode || item.colorNo,
      productId: product.id
    }, product);
    return (state.skus || []).some(function (sku) {
      if (!sku || sku.temporaryFreightSku) return false;
      if (text(sku.productId) !== text(product.id)) return false;
      if ((key(sku.sizeName || sku.size || 'NOSIZE') || 'nosize') !== size) return false;
      return receiptColorFamilyKey(sku, product) === family;
    });
  }

  function catalogHasMatchingColor(product, skuLike) {
    /* LZ_OLAN72_SNOOPY_20260924: 物流暫存色不在正式 colors[] 時，不要偷掛到該產品。 */
    product = product || {};
    skuLike = skuLike || {};
    var colors = Array.isArray(product.colors) ? product.colors : [];
    if (!colors.length) return true;
    var family = receiptColorFamilyKey(skuLike, product);
    return colors.some(function (row) {
      row = row || {};
      return receiptColorFamilyKey({
        colorName: row.colorName || row.name || row.color,
        color: row.color || row.colorName || row.name,
        colorCode: row.code || row.colorCode,
        productId: product.id
      }, product) === family;
    });
  }

  function pruneCoveredFreightSkus() {
    /* LZ_OLAN66_WHITE_20260924: 物流暫存列不可蓋掉正式白／粉色圖，也不要同一色拆很多倉。 */
    state.skus = (state.skus || []).filter(function (sku) {
      if (!sku || !sku.temporaryFreightSku) return true;
      var product = productByCode(sku.productCode) || productById(sku.productId);
      if (!product || product.temporaryFreightProduct) return true;
      sku.productId = text(product.id);
      if (!catalogHasMatchingColor(product, sku)) return false;
      var catalogImage = firstProductImage(product, sku, sku);
      if (catalogImage) sku.colorImage = catalogImage;
      return !skuAlreadyCoversFreightItem({
        catalogProductCode: product.code || product.productLine,
        color: sku.colorName || sku.color,
        colorName: sku.colorName || sku.color,
        colorCode: sku.colorCode,
        size: sku.sizeName || sku.size,
        sampleBarcode: sku.companyBarcode || sku.barcode || sku.id
      });
    });
    state.products = (state.products || []).filter(function (product) {
      if (!product || !product.temporaryFreightProduct) return true;
      return (state.skus || []).some(function (sku) { return text(sku.productId) === text(product.id); });
    });
  }

  function mergePreorderFreightCatalog() {
    if (!(state.products || []).length) return;
    var items = state.freight && Array.isArray(state.freight.items) ? state.freight.items : [];
    items.forEach(function (item, index) {
      if (!item || freightItemAlreadyReceived(item)) return;
      var sourceId = text(item.id || ('freight-' + index));
      var title = text(item.productName || item.title || item.name);
      var duplicateTitle = title.replace(/\s+/g, '').toUpperCase();
      if (sourceId === 'FRTI-1787984256213-WBJYC' && duplicateTitle === 'HERMES240AY') return;
      var images = []
        .concat(Array.isArray(item.productImages) ? item.productImages : [])
        .concat([item.productImage, item.colorImage, item.image, item.mainImage])
        .map(text).filter(Boolean);
      if (!title || !images.length) return;
      var code = text(item.catalogProductCode || item.productCode || item.productFiledProductCode);
      var barcode = text(item.customerSupplyBarcode || item.sampleBarcode || item.taiwanBarcode || item.productFiledBarcode);
      var product = state.products.find(function (row) {
        return code && [row.id, row.code, row.productCode, row.productLine].some(function (value) { return key(value) === key(code); });
      }) || null;
      var productId = product ? text(product.id || product.code) : ('freight-photo:' + sourceId);
      if (!product) {
        product = {
          id: productId,
          code: code,
          productCode: code,
          title: title,
          name: title,
          mainImage: images[0],
          category: text(item.category || item.productCategory || '預購商品'),
          productMode: 'preorder',
          preorderOnly: true,
          temporaryFreightProduct: true,
          freightSourceId: sourceId
        };
        state.products.push(product);
      }
      var skuId = barcode || ('freight-photo-sku:' + sourceId);
      var exists = state.skus.some(function (sku) { return text(sku.id || sku.sku) === skuId; });
      if (exists || skuAlreadyCoversFreightItem(item)) return;
      var freightSku = {
        colorName: text(item.color || item.colorName || '未選顏色'),
        color: text(item.color || item.colorName || '未選顏色'),
        colorCode: text(item.colorCode || item.colorNo),
        productId: productId
      };
      if (!product.temporaryFreightProduct && !catalogHasMatchingColor(product, freightSku)) return;
      state.skus.push({
        id: skuId,
        sku: skuId,
        productId: productId,
        productCode: code,
        companyBarcode: barcode,
        barcode: barcode,
        colorName: freightSku.colorName,
        color: freightSku.color,
        colorCode: freightSku.colorCode,
        sizeName: text(item.size || item.sizeName || 'NO SIZE'),
        size: text(item.size || item.sizeName || 'NO SIZE'),
        colorImage: firstProductImage(product, freightSku, item) || images[0],
        warehouse: '預購倉',
        warehouseName: '預購倉',
        warehouseCode: 'PREORDER',
        stock: 0,
        temporaryFreightSku: true,
        freightSourceId: sourceId
      });
    });
    pruneCoveredFreightSkus();
  }

  function standaloneCategoryRows() {
    return uniqueTextRows((state.categories || []).concat((state.products || []).map(function (product) {
      return product.category || product.categoryName || product.productCategory || '';
    })));
  }

  function standaloneReferenceRows(kind, value) {
    var query = text(value);
    var wanted = key(query);
    if (!wanted) return [];
    if (kind === 'category') {
      return standaloneCategoryRows().map(function (name) {
        var compact = key(name);
        return { value: name, title: name, detail: '產品分類', rank: compact === wanted ? 0 : (compact.indexOf(wanted) === 0 ? 1 : (compact.indexOf(wanted) !== -1 ? 2 : 999)) };
      }).filter(function (row) { return row.rank < 999; }).sort(function (a, b) { return a.rank - b.rank || a.title.localeCompare(b.title, 'zh-Hant'); }).slice(0, 8);
    }
    var source = kind === 'supplier' ? state.suppliers : state.platforms;
    return (source || []).map(function (row) {
      var name = text(row.name || row.supplier || row.platform);
      var fields = [name, row.type, row.contact, row.note, row.returnRule].map(text).filter(Boolean);
      var compactName = key(name);
      var compactAll = key(fields.join(' '));
      var rank = compactName === wanted ? 0 : (compactName.indexOf(wanted) === 0 ? 1 : (compactAll.indexOf(wanted) !== -1 ? 2 : 999));
      return { value: name, title: name, detail: fields.slice(1, 3).join('／') || (kind === 'supplier' ? '廠商' : '採購平台'), rank: rank };
    }).filter(function (row) { return row.value && row.rank < 999; }).sort(function (a, b) { return a.rank - b.rank || a.title.localeCompare(b.title, 'zh-Hant'); }).slice(0, 8);
  }

  function standaloneReferenceSelectors(kind) {
    if (kind === 'supplier') return { input: '[data-purchase-receipt-supplier]', host: '[data-purchase-receipt-supplier-suggest]' };
    if (kind === 'platform') return { input: '[data-purchase-receipt-platform]', host: '[data-purchase-receipt-platform-suggest]' };
    return { input: '[data-purchase-receipt-category]', host: '[data-purchase-receipt-category-suggest]' };
  }

  function renderStandaloneReferenceSuggest(kind, value) {
    var selectors = standaloneReferenceSelectors(kind);
    var host = document.querySelector(selectors.host);
    if (!host) return;
    var query = text(value);
    if (!query) {
      host.hidden = true;
      host.innerHTML = '';
      return;
    }
    var rows = standaloneReferenceRows(kind, query);
    host.innerHTML = rows.length ? rows.map(function (row) {
      return '<button type="button" data-purchase-receipt-reference-kind="' + kind + '" data-purchase-receipt-reference-value="' + escapeHtml(row.value) + '"><b>' + escapeHtml(row.title) + '</b><small>' + escapeHtml(row.detail) + '</small></button>';
    }).join('') : '<p>沒有找到相關資料，可繼續手動輸入。</p>';
    host.hidden = false;
  }

  function hideStandaloneReferenceSuggest(kind) {
    var host = document.querySelector(standaloneReferenceSelectors(kind).host);
    if (host) { host.hidden = true; host.innerHTML = ''; }
  }

  function fillStandaloneOptions() {
    state.categories = standaloneCategoryRows();
    function fillSelect(selector, rows, placeholder) {
      var select = document.querySelector(selector);
      if (!select) return;
      var current = text(select.value);
      var names = uniqueTextRows(rows);
      if (current && names.indexOf(current) === -1) names.unshift(current);
      select.innerHTML = '<option value="">' + escapeHtml(placeholder) + '</option>' + names.map(function (name) { return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>'; }).join('');
      if (current) select.value = current;
    }
    var supplierNames = (state.suppliers || []).map(function (row) { return row.name || row.supplier || ''; })
      .concat((state.platforms || []).filter(function (row) { return row.masterKind === 'supplier'; }).map(function (row) { return row.name || ''; }))
      .concat((state.freight.batches || []).map(function (row) { return row.forwarder || row.provider || ''; }))
      .concat((state.receipts || []).map(function (row) { var doc = row.receivingDocument || row; return doc.supplier || ''; }));
    var platformNames = (state.platforms || []).filter(function (row) { return !row.masterKind || row.masterKind === 'platform'; }).map(function (row) { return row.name || row.platform || ''; })
      .concat((state.freight.items || []).map(function (row) { return row.platform || row.purchasePlatform || ''; }))
      .concat((state.receipts || []).map(function (row) { var doc = row.receivingDocument || row; return doc.purchasePlatform || ''; }));
    fillSelect('[data-purchase-receipt-supplier]', supplierNames, '請選擇已建檔廠商');
    fillSelect('[data-purchase-receipt-platform]', platformNames, '請選擇已建檔平台');
  }

  function looksLikeBarcodeQuery(value) {
    var compact = text(value).replace(/\s+/g, '');
    if (compact.length < 8) return false;
    if (/[\u4e00-\u9fff]/.test(compact)) return false;
    return /^[A-Za-z0-9\-_]+$/.test(compact) && /[A-Za-z]/.test(compact) && /\d/.test(compact);
  }

  function uniqueBarcodeKeys(values) {
    var seen = {};
    var out = [];
    (values || []).forEach(function (value) {
      var identity = key(value);
      if (!identity || seen[identity]) return;
      seen[identity] = true;
      out.push(identity);
    });
    return out;
  }

  function knownInboundColorCode(value) {
    var code = String(value || '').trim();
    return /^(91|92|93|94|95|96|98|99|902|904|907|912|952)$/.test(code) ? code : '';
  }

  function padReceiptSizeCode(value) {
    var raw = text(value).toUpperCase().replace(/[\s_\-]/g, '');
    if (!raw || raw === '00' || raw === 'NOSIZE' || raw === 'NO-SIZE') return '00';
    var mapped = receiptSizeCode(value);
    if (!mapped || mapped === '00' || mapped === 'NOSIZE' || mapped === 'NO-SIZE') return '00';
    if (/^\d$/.test(mapped)) return '0' + mapped;
    if (/^\d{2}$/.test(mapped)) return mapped;
    return '00';
  }

  function composeCanonicalCompanyBarcode(productCode, colorCode, sizeCode, cost) {
    /* LZ_BARCODE_FMT_20260924: {productCode}{colorCode}{sizeCode}P{cost}. NO SIZE=00. No C/S letters. */
    var base = String(productCode || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    var color = String(colorCode || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    var size = padReceiptSizeCode(sizeCode);
    var costPart = String(Math.max(0, Math.round(Number(cost || 0))));
    if (!base || !color || !costPart || costPart === '0') return '';
    return base + color + size + 'P' + costPart;
  }

  function peelSizeFromMidDigits(mid, skuColor) {
    mid = String(mid || '');
    skuColor = String(skuColor || '');
    var sizeTail = mid.slice(-2);
    if (sizeTail === '00') return { body: mid.slice(0, -2), sizeCode: '00' };
    if (/^0[1-8]$/.test(sizeTail)) {
      var body = mid.slice(0, -2);
      if ((skuColor && body.slice(-skuColor.length) === skuColor)
        || knownInboundColorCode(body.slice(-3))
        || knownInboundColorCode(body.slice(-2))) {
        return { body: body, sizeCode: sizeTail };
      }
    }
    return { body: mid, sizeCode: '00' };
  }

  function splitProductColorFromBody(letters, body, skuColor, productCode) {
    body = String(body || '');
    letters = String(letters || '');
    skuColor = String(skuColor || '');
    productCode = String(productCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (productCode && productCode.indexOf(letters) === 0) {
      var productDigits = productCode.slice(letters.length);
      if (body.indexOf(productDigits) === 0 && body.length > productDigits.length) {
        return { base: productCode, colorCode: body.slice(productDigits.length) };
      }
    }
    if (skuColor && body.length > skuColor.length && body.slice(-skuColor.length) === skuColor) {
      return { base: letters + body.slice(0, -skuColor.length), colorCode: skuColor };
    }
    if (knownInboundColorCode(body.slice(-3)) && body.length > 3) {
      return { base: letters + body.slice(0, -3), colorCode: body.slice(-3) };
    }
    if (knownInboundColorCode(body.slice(-2)) && body.length > 2) {
      return { base: letters + body.slice(0, -2), colorCode: body.slice(-2) };
    }
    if (body.length >= 5) return { base: letters + body.slice(0, -3), colorCode: body.slice(-3) };
    if (body.length >= 4) return { base: letters + body.slice(0, -2), colorCode: body.slice(-2) };
    return { base: letters + body, colorCode: skuColor || '' };
  }

  function canonicalInboundBarcode(line, sku, product, raw) {
    line = line || {};
    sku = sku || {};
    product = product || {};
    var stored = text(raw || line.barcode || line.companyBarcode || line.officialBarcode || sku.companyBarcode || sku.officialBarcode || sku.barcode || '');
    var productCode = text(line.productCode || (product && (product.code || product.productLine)) || sku.productCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    var colorCode = text(sku.colorCode || sku.colorNo || receiptColorCode(line.color || line.colorName || sku.colorName || sku.color));
    var sizeCode = padReceiptSizeCode(line.size || line.sizeName || sku.sizeName || sku.size);
    var cost = Math.max(0, Math.round(Number(line.unitCostTwd || line.cost || line.barcodeCostTwd || sku.currentCostTwd || sku.cost || 0)));
    var parsed = receivedParseConcatBarcode(stored, Object.assign({}, line, {
      productCode: productCode,
      color: line.color || line.colorName || sku.colorName || sku.color,
      size: line.size || line.sizeName || sku.sizeName || sku.size,
      unitCostTwd: cost
    }), sku, Object.assign({}, product, { code: productCode || (product && product.code) }));
    if (parsed) {
      productCode = parsed.base || productCode;
      colorCode = parsed.colorCode || colorCode;
      sizeCode = padReceiptSizeCode(parsed.sizeCode || sizeCode);
      cost = Number(parsed.cost || cost);
    }
    return composeCanonicalCompanyBarcode(productCode, colorCode, sizeCode, cost) || stored;
  }

  function inboundFormatAliases(raw, line, sku, product) {
    /* LZ_BARCODE_FMT_20260924: scan both new 編號顏色尺碼P成本 and old 編號P成本顏色 / C/S. */
    var compact = text(raw).replace(/\s+/g, '').toUpperCase();
    if (!compact) return [];
    var aliases = [compact];
    var parsed = receivedParseConcatBarcode(compact, line || {}, sku || {}, product || {});
    if (parsed && parsed.base && parsed.cost && parsed.colorCode) {
      var size = padReceiptSizeCode(parsed.sizeCode);
      var unpadded = String(Number(size));
      aliases.push(composeCanonicalCompanyBarcode(parsed.base, parsed.colorCode, size, parsed.cost));
      aliases.push(String(parsed.base).toUpperCase() + String(parsed.colorCode) + 'P' + String(parsed.cost));
      aliases.push(String(parsed.base).toUpperCase() + 'P' + String(parsed.cost) + String(parsed.colorCode));
      aliases.push(String(parsed.base).toUpperCase() + 'P' + String(parsed.cost) + 'C' + String(parsed.colorCode) + 'S' + size);
      aliases.push(String(parsed.base).toUpperCase() + 'C' + String(parsed.colorCode) + 'S' + size + 'P' + String(parsed.cost));
      if (size !== '00' && unpadded !== size) {
        aliases.push(String(parsed.base).toUpperCase() + String(parsed.colorCode) + unpadded + 'P' + String(parsed.cost));
      }
    }
    var match = compact.match(/^([A-Z]+)(\d+)P(\d+)$/);
    if (match) {
      var letters = match[1];
      var midDigits = match[2];
      var costPart = match[3];
      var peeled = peelSizeFromMidDigits(midDigits, '');
      [4, 3, 2].forEach(function (colorLen) {
        if (peeled.body.length > colorLen) {
          aliases.push(letters + peeled.body.slice(0, -colorLen) + 'P' + costPart + peeled.body.slice(-colorLen));
        }
        if (midDigits.length > colorLen) {
          aliases.push(letters + midDigits.slice(0, -colorLen) + 'P' + costPart + midDigits.slice(-colorLen));
        }
      });
    }
    return aliases.filter(Boolean);
  }

  function inboundPrintAliasesFromStored(raw) {
    return inboundFormatAliases(raw, {}, {}, {});
  }

  function inboundStoredAliasesFromPrint(raw) {
    return inboundFormatAliases(raw, {}, {}, {});
  }

  function inboundBarcodeSearchKeys(value) {
    var compact = text(value).replace(/\s+/g, '');
    return uniqueBarcodeKeys([compact].concat(inboundFormatAliases(compact, {}, {}, {})));
  }

  function skuBarcodeAliasKeys(sku) {
    sku = sku || {};
    var product = productById(sku.productId) || productByCode(sku.productCode) || {};
    var stored = text(sku.companyBarcode || sku.barcode || sku.officialBarcode || sku.legacyBarcode || sku.mappingCode || sku.id || sku.sku);
    var aliases = [
      sku.id, sku.sku, sku.companyBarcode, sku.barcode, sku.officialBarcode,
      sku.legacyBarcode, sku.mappingCode, stored, product.code, product.productLine
    ].concat(Array.isArray(sku.barcodeAliases) ? sku.barcodeAliases : [])
      .concat(Array.isArray(sku.linkedBarcodes) ? sku.linkedBarcodes : []);
    try {
      var printCode = canonicalInboundBarcode({
        productCode: text(product.code || product.productLine || sku.productCode),
        color: sku.colorName || sku.color,
        colorName: sku.colorName || sku.color,
        size: sku.sizeName || sku.size,
        sizeName: sku.sizeName || sku.size,
        unitCostTwd: sku.currentCostTwd || sku.cost
      }, sku, product, stored);
      if (printCode) aliases.push(printCode);
    } catch (error) {}
    inboundFormatAliases(stored, {
      productCode: text(product.code || product.productLine || sku.productCode),
      color: sku.colorName || sku.color,
      size: sku.sizeName || sku.size,
      unitCostTwd: sku.currentCostTwd || sku.cost
    }, sku, product).forEach(function (row) { aliases.push(row); });
    return uniqueBarcodeKeys(aliases);
  }

  function skuExactBarcodeHit(sku, queryKeys) {
    if (!queryKeys || !queryKeys.length) return false;
    return skuBarcodeAliasKeys(sku).some(function (field) { return queryKeys.indexOf(field) !== -1; });
  }

  function applyStandaloneSkuSelection(sku, options) {
    options = options || {};
    if (!sku) return;
    var product = productById(sku.productId) || {};
    state.selectedStandaloneSku = sku;
    var searchInput = document.querySelector('[data-purchase-receipt-sku-search]');
    var categoryInput = document.querySelector('[data-purchase-receipt-category]');
    var codeInput = document.querySelector('[data-purchase-receipt-product-code]');
    var barcodeInput = document.querySelector('[data-purchase-receipt-product-barcode]');
    if (!options.keepSearch && searchInput) {
      searchInput.value = [product.code || product.productLine || '', product.title || product.name || '', sku.colorName || sku.color || '', sku.sizeName || sku.size || 'NO SIZE'].filter(Boolean).join('／');
    }
    if (categoryInput) categoryInput.value = productCategory(product);
    if (codeInput) {
      codeInput.value = text(product.code || product.productLine || product.id);
      codeInput.dataset.receiptCodeSource = 'existing';
    }
    if (barcodeInput) barcodeInput.value = canonicalInboundBarcode({
      productCode: text(product.code || product.productLine || product.id),
      color: sku.colorName || sku.color,
      size: sku.sizeName || sku.size,
      unitCostTwd: sku.currentCostTwd || sku.cost
    }, sku, product, text(sku.companyBarcode || sku.barcode || sku.legacyBarcode || sku.mappingCode || sku.id || sku.sku));
    setStandaloneCodeStatus('已對到既有產品 ' + (product.code || product.productLine || product.id || '') + '；分類、產品編號與條碼已帶入，可直接加入進貨項目。', 'ok');
  }

  function standaloneSkuMatches(value) {
    // Freight data may finish loading after the formal SKU catalog. Merge on
    // demand as well so preorder-warehouse items are searchable immediately.
    mergePreorderFreightCatalog();
    var wanted = key(value);
    if (!wanted) return [];
    var queryKeys = inboundBarcodeSearchKeys(value);
    var barcodeLike = looksLikeBarcodeQuery(value);
    // Historical SKU rows remain in the catalog for audit purposes, but they
    // must never be selectable for a new purchase receipt. In particular,
    // legacyMislinked rows may still carry an old productId and image, which
    // would otherwise make another product's photo appear in these results.
    var catalogSkus = state.skus.filter(function (sku) {
      if (!sku || sku.archived === true || sku.legacyMislinked === true || sku.active === false) return false;
      return !/^(?:inactive|deleted|archived|removed)$/.test(text(sku.status).toLowerCase());
    });
    (state.freight.items || []).forEach(function (item, freightIndex) {
      if (freightItemAlreadyReceived(item)) return;
      var freightFields = [item.catalogProductCode, item.productCode, item.productName, item.customerSupplyBarcode, item.sampleBarcode, item.taiwanBarcode, item.color, item.size].map(key).filter(Boolean);
      [item.customerSupplyBarcode, item.taiwanBarcode, item.sampleBarcode, item.productFiledBarcode].forEach(function (barcode) {
        inboundBarcodeSearchKeys(barcode).forEach(function (aliasKey) { freightFields.push(aliasKey); });
      });
      if (!freightFields.some(function (field) { return field.indexOf(wanted) !== -1 || queryKeys.indexOf(field) !== -1; })) return;
      var code = text(item.catalogProductCode || item.productCode || item.productFiledProductCode);
      var barcode = text(item.customerSupplyBarcode || item.taiwanBarcode || item.sampleBarcode || item.productFiledBarcode);
      var product = productByCode(code) || productByCode(item.productName);
      if (!product) {
        var images = [].concat(Array.isArray(item.productImages) ? item.productImages : []).concat([item.productImage, item.image]).map(text).filter(Boolean);
        product = { id: 'freight-search:' + text(item.id || freightIndex), code: code, title: text(item.productName || code), name: text(item.productName || code), category: text(item.category || '預購商品'), mainImage: images[0] || '', preorderOnly: true, temporaryFreightProduct: true };
        state.products.push(product);
      }
      var duplicate = catalogSkus.some(function (sku) {
        var product = productById(sku.productId) || product;
        return (key(sku.companyBarcode || sku.barcode || sku.legacyBarcode || '') === key(barcode) || receiptColorFamilyKey(sku, product) === receiptColorFamilyKey({ colorName: item.colorName || item.color, colorCode: item.colorCode, productId: product.id }, product))
          && key(sku.sizeName || sku.size || 'NO SIZE') === key(item.sizeName || item.size || 'NO SIZE')
          && text(sku.productId) === text(product.id);
      });
      if (!duplicate && barcode && !skuAlreadyCoversFreightItem(item)) {
        var searchSku = {
          id: barcode || ('freight-search-sku:' + text(item.id || freightIndex)),
          sku: barcode || ('freight-search-sku:' + text(item.id || freightIndex)),
          productId: product.id,
          productCode: code,
          companyBarcode: barcode,
          barcode: barcode,
          colorName: text(item.colorName || item.color || '未選顏色'),
          color: text(item.colorName || item.color || '未選顏色'),
          colorCode: text(item.colorCode || item.colorNo),
          sizeName: text(item.sizeName || item.size || 'NO SIZE'),
          size: text(item.sizeName || item.size || 'NO SIZE'),
          warehouse: '預購倉',
          warehouseName: '預購倉',
          warehouseCode: 'PREORDER',
          stock: 0,
          temporaryFreightSku: true
        };
        if (!product.temporaryFreightProduct && !catalogHasMatchingColor(product, searchSku)) return;
        searchSku.colorImage = firstProductImage(product, searchSku, item);
        catalogSkus.push(searchSku);
      }
    });
    var ranked = catalogSkus.map(function (sku, index) {
      var product = productById(sku.productId) || {};
      var barcodeKeys = skuBarcodeAliasKeys(sku);
      var primary = barcodeKeys.slice();
      var colorBlob = (Array.isArray(product.colors) ? product.colors : []).map(function (row) {
        return [row && row.name, row && row.color, row && row.colorName, Array.isArray(row && row.aliases) ? row.aliases.join(' ') : ''].join(' ');
      }).join(' ');
      var aliasBlob = Array.isArray(product.nameAliases) ? product.nameAliases.join(' ') : '';
      var fields = primary.concat([product.title, product.name, product.brand, product.category, product.categoryName, product.productLine, product.code, sku.colorName, sku.color, sku.sizeName, sku.size, colorBlob, aliasBlob].map(key).filter(Boolean));
      var exactHit = barcodeKeys.some(function (field) { return queryKeys.indexOf(field) !== -1; });
      var rank = exactHit ? 0 : (barcodeLike ? 999 : (primary.some(function (field) { return field.indexOf(wanted) === 0; }) ? 1 : (fields.some(function (field) { return field.indexOf(wanted) !== -1; }) ? 2 : 999)));
      return { sku: sku, rank: rank, index: index, code: product.productLine || product.code || product.id || '', color: text(sku.colorName || sku.color), size: text(sku.sizeName || sku.size) };
    }).filter(function (row) { return row.rank < 999; }).sort(function (a, b) {
      return a.rank - b.rank || String(a.code).localeCompare(String(b.code), 'zh-Hant', { numeric: true }) || String(a.color).localeCompare(String(b.color), 'zh-Hant') || String(a.size).localeCompare(String(b.size), 'zh-Hant', { numeric: true }) || a.index - b.index;
    });
    return collapseStandaloneSkuMatches(ranked.map(function (row) { return row.sku; })).slice(0, 40);
  }


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
      sku = receiptSkuForInbound(sku) || sku;
      var product = productById(sku.productId) || {};
      var barcode = text(sku.companyBarcode || sku.barcode || sku.legacyBarcode || sku.mappingCode || sku.id || sku.sku);
      var productCode = text(sku.receiptGroupProductCode || product.productLine || product.code || product.id);
      state.standaloneLines.push(standaloneLineFromSku(sku, {
        productName: text(product.title || product.name || productCodeForSku(sku)),
        category: productCategory(product),
        productCode: productCode,
        sampleBarcode: barcode,
        taiwanBarcode: barcode,
        productImage: sku.receiptGroupImage || ''
      }, null));
    });
    clearStandaloneItemEditor();
    var input = document.querySelector('[data-purchase-receipt-sku-search]');
    if (input) input.focus();
    renderStandaloneLines();
    standaloneMessage('已加入 ' + skus.length + ' 個尺寸，可在下方改數量。', 'ok');
  }

  function renderStandaloneSkuSuggest(value) {
    var host = document.querySelector('[data-purchase-receipt-product-suggest]');
    if (!host) return;
    host.dataset.catalogSkuCount = String(state.skus.length);
    host.dataset.catalogProductCount = String(state.products.length);
    host.dataset.freightItemCount = String(state.freight.items.length);
    var query = text(value);
    if (!query) {
      host.hidden = true;
      host.innerHTML = '';
      host._matches = [];
      return;
    }
    var matches = standaloneSkuMatches(query);
    host._matches = matches;
    if (!matches.length) {
      host.innerHTML = looksLikeBarcodeQuery(query)
        ? '<p>查無相符產品；已用完整條碼絕對比對（含貼紙 P 在後與資料庫 P 在中間的同一碼）。請確認貼紙與庫存編號。</p>'
        : '<p>查無相符產品；可改用完整產品編號、SKU 或條碼搜尋。</p>';
      host.hidden = false;
      return;
    }
    if (looksLikeBarcodeQuery(query) && matches.length === 1) {
      applyStandaloneSkuSelection(matches[0], { keepSearch: true });
    }
    var rows = matches.map(function (sku, index) {
      var product = productById(sku.productId) || productByCode(sku.productCode || sku.productId) || {};
      var productName = text(product.title || product.name || productCodeForSku(sku)) || '未命名產品';
      var code = productCodeForSku(sku) || text(sku.productId);
      var barcode = canonicalInboundBarcode({
        productCode: code,
        color: sku.colorName || sku.color,
        size: sku.sizeName || sku.size,
        unitCostTwd: sku.currentCostTwd || sku.cost
      }, sku, product, text(sku.companyBarcode || sku.barcode || sku.id || sku.sku)) || text(sku.companyBarcode || sku.barcode || sku.id || sku.sku) || '未建條碼';
      var color = receiptDisplayColor(sku, product);
      var size = text(sku.sizeName || sku.size) || 'NO SIZE';
      var warehouse = text(sku.warehouseName || sku.warehouse) || '未指定倉別';
      var category = productCategory(product) || '未填分類';
      var productImage = firstProductImage(product, sku, {});
      var thumbCaption = [code, productName, color, size].filter(Boolean).join('／');
      var thumb = receiptThumbButtonHtml(productImage, thumbCaption);
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
      '<small>可勾多個尺寸一次加入；每筆預設 1 件，加入後再改數量。點縮圖可放大。</small>' +
      '<div class="purchase-receipt-suggest-toolbar-actions">' +
      '<label class="purchase-receipt-suggest-all"><input type="checkbox" data-purchase-receipt-sku-check-all><span>全選目前結果</span></label>' +
      '<button type="button" class="primary-button" data-purchase-receipt-add-checked>加入已勾選<span>Tambah ukuran</span></button>' +
      '</div></div><div class="purchase-receipt-suggest-list">' + rows + '</div>';
    host.hidden = false;
  }

  function selectStandaloneSkuRecord(sku) {
    if (!sku) return;
    applyStandaloneSkuSelection(sku);
    var suggest = document.querySelector('[data-purchase-receipt-product-suggest]');
    if (suggest) { suggest.hidden = true; suggest.innerHTML = ''; suggest._matches = []; }
  }

  function clearStandaloneSelectedProduct(options) {
    options = options || {};
    var searchInput = document.querySelector('[data-purchase-receipt-sku-search]');
    var categoryInput = document.querySelector('[data-purchase-receipt-category]');
    var codeInput = document.querySelector('[data-purchase-receipt-product-code]');
    var barcodeInput = document.querySelector('[data-purchase-receipt-product-barcode]');
    var codeSource = codeInput && codeInput.dataset.receiptCodeSource || '';
    var hadExisting = !!state.selectedStandaloneSku || codeSource === 'existing';
    var clearGenerated = !!options.clearGenerated && codeSource === 'auto';
    state.selectedStandaloneSku = null;
    if (!hadExisting && !clearGenerated) return;
    if (!options.keepSearch && searchInput) searchInput.value = '';
    if (!options.keepCategory && categoryInput) categoryInput.value = '';
    if ((hadExisting || clearGenerated) && codeInput) {
      codeInput.value = '';
      codeInput.dataset.receiptCodeSource = '';
    }
    if (hadExisting && barcodeInput) barcodeInput.value = '';
    setStandaloneCodeStatus('產品或分類已變更；舊的產品編號與條碼已清除，請重新選取或產生編號。', 'warning');
  }

  function standaloneLineFromSku(sku, source, batch) {
    sku = sku || {};
    source = source || {};
    var product = productById(sku.productId) || productByCode(source.productCode || source.productName) || {};
    var qty = Math.max(1, Number(source.quantity || source.qty || 1));
    var cost = calculate(Object.assign({}, source, {
      quantity: qty,
      baseTwdCost: Number(source.costRmb ? 0 : (sku.cost || 0)),
      costRmb: Number(source.costRmb || 0),
      existingTwdCost: Number(source.existingTwdCost || 0),
      fixedTwdCost: !!source.fixedTwdCost
    }), batch || null).companyInternalCost;
    if (!cost) cost = Number(sku.cost || 0);
    var temporaryProduct = !!(product.temporaryFreightProduct || sku.temporaryFreightSku || /^freight-(?:photo|search):/i.test(text(sku.productId)));
    var catalogProduct = productByCode(source.productCode || product.code || product.productLine || product.id) || productById(sku.productId);
    if (temporaryProduct && catalogProduct && !catalogProduct.temporaryFreightProduct) {
      temporaryProduct = false;
      product = catalogProduct;
    }
    var line = {
      key: 'PRL-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8),
      skuId: text(sku.id || sku.sku),
      productId: temporaryProduct ? text(catalogProduct && catalogProduct.id) : text((product && product.id) || sku.productId),
      productName: text(source.productName || product.title || product.name || source.productCode),
      category: text(source.category || productCategory(product)),
      productCode: text(source.productCode) || productCodeForSku(sku) || text(source.productName),
      barcode: '',
      barcodeAuto: true,
      barcodeManualEdited: false,
      color: sanitizeInboundColorValue(source.colorName || source.color || sku.colorName || sku.color, { productCode: text(source.productCode) || productCodeForSku(sku) || text(source.productName), barcode: text(source.taiwanBarcode || source.sampleBarcode || sku.companyBarcode || sku.barcode), skuId: text(sku.id || sku.sku), productId: text((product && product.id) || sku.productId) }, sku, product, text(source.taiwanBarcode || source.sampleBarcode || sku.companyBarcode || sku.barcode)),
      size: text(source.sizeName || source.size || sku.sizeName || sku.size) || 'NO SIZE',
      qty: qty,
      unitCostTwd: Math.max(0, Number(cost || 0)),
      arrivalImage: '',
      productImage: firstProductImage(product, sku, source),
      sourceTrackingNo: text(source.trackingNo || source.haohongTrackingNo)
    };
    line.barcode = canonicalInboundBarcode(line, sku, product, text(source.taiwanBarcode || source.sampleBarcode || sku.companyBarcode || sku.barcode || sku.id || sku.sku));
    return line;
  }

  function skuForFreightItem(item) {
    item = item || {};
    var directValues = [item.taiwanBarcode, item.sampleBarcode, item.skuId, item.sku];
    for (var i = 0; i < directValues.length; i += 1) {
      var direct = standaloneSkuMatches(directValues[i]);
      if (direct.length === 1 && !(direct[0] && direct[0].temporaryFreightSku)) return direct[0];
      var real = direct.find(function (sku) { return sku && !sku.temporaryFreightSku; });
      if (real) return real;
    }
    var product = productByCode(item.productCode || item.productName);
    if (!product) return null;
    var color = key(item.colorName || item.color);
    var size = key(item.sizeName || item.size || 'NO SIZE');
    var matches = state.skus.filter(function (sku) {
      if (!sku || sku.temporaryFreightSku) return false;
      return text(sku.productId) === text(product.id) && (!color || key(sku.colorName || sku.color) === color) && (!size || key(sku.sizeName || sku.size || 'NO SIZE') === size);
    });
    return matches[0] || state.skus.find(function (sku) { return sku && !sku.temporaryFreightSku && text(sku.productId) === text(product.id); }) || null;
  }

  function renderStandaloneLines() {
    var host = document.querySelector('[data-purchase-receipt-lines]');
    if (!host) return;
    if (!state.standaloneLines.length) {
      host.innerHTML = '<p class="empty-state">尚未加入進貨項目。</p>';
      renderStandaloneTotals();
      return;
    }
    host.innerHTML = state.standaloneLines.map(function (line, index) {
      var product = productById(line.productId) || productByCode(line.productCode) || {};
      var sku = skuById(line.skuId) || {};
      var cleanedColor = sanitizeInboundColorValue(line.color || line.colorName, line, sku, product, line.barcode);
      if (cleanedColor) line.color = cleanedColor;
      if (!line.barcodeManualEdited) {
        line.barcode = canonicalInboundBarcode(line, sku, product, line.barcode);
        line.barcodeAuto = true;
      }
      var liveImage = firstProductImage(product, sku, line);
      if (liveImage && !line.arrivalImage) line.productImage = liveImage;
      var previewImage = line.arrivalImage || liveImage || line.productImage || '';
      return [
        '<article class="purchase-receipt-line" data-purchase-receipt-line="' + escapeHtml(line.key) + '">',
        '<div class="purchase-receipt-line-media">',
        '<span class="purchase-receipt-line-index">' + (index + 1) + '</span>',
        receiptThumbButtonHtml(previewImage, [line.productCode, line.productName, line.color, line.size].filter(Boolean).join('／'), 'purchase-receipt-line-thumb'),
        '</div>',
        '<div class="purchase-receipt-line-fields">',
        '<label class="purchase-receipt-line-name">產品名稱<input value="' + escapeHtml(line.productName || line.productCode || '') + '" data-standalone-line-product-name><small>' + escapeHtml(line.skuId || '尚未對到既有 SKU') + '</small></label>',
        '<label>產品分類<input value="' + escapeHtml(line.category || '') + '" data-standalone-line-category></label>',
        '<label>產品編號<input value="' + escapeHtml(line.productCode || '') + '" data-standalone-line-product-code></label>',
        '<label class="purchase-receipt-line-barcode">公司條碼<input value="' + escapeHtml(line.barcode || '') + '" data-standalone-line-barcode data-barcode-auto="' + (line.barcodeAuto ? '1' : '0') + '" data-barcode-manual="' + (line.barcodeManualEdited ? '1' : '0') + '"><small>' + (line.barcodeManualEdited ? '手動條碼不會被覆蓋' : '會隨選好的顏色／尺寸更新') + '</small></label>',
        '<label class="purchase-receipt-line-qty">實收數量<input type="number" min="1" step="1" value="' + Math.max(1, Number(line.qty || 1)) + '" data-standalone-line-qty></label>',
        '<label class="purchase-receipt-line-cost">單件成本<input type="number" min="0" step="1" value="' + Math.max(0, Number(line.unitCostTwd || 0)) + '" data-standalone-line-cost></label>',
        '<label class="purchase-receipt-line-color">顏色<div class="purchase-receipt-color-picker"><select data-standalone-line-color-preset>' + standaloneColorOptionsHtml(line.color || '') + '</select><input value="' + escapeHtml(line.color || '') + '" data-standalone-line-color placeholder="可手動輸入新顏色"></div></label>',
        '<label class="purchase-receipt-line-size">尺寸<div class="purchase-receipt-size-picker"><select data-standalone-line-size-preset>' + standaloneSizeOptionsHtml(line.category, line.size || 'NO SIZE') + '</select><input value="' + escapeHtml(line.size || 'NO SIZE') + '" data-standalone-line-size placeholder="可手動輸入其他尺寸"></div></label>',
        '<div class="purchase-receipt-line-actions"><button type="button" class="ghost-button" data-purchase-receipt-add-variant="' + escapeHtml(line.key) + '">＋同款加色尺</button><button type="button" class="danger-button" data-purchase-receipt-remove-line="' + escapeHtml(line.key) + '">移除</button></div>',
        '</div>',
        '<label class="purchase-receipt-line-photo">到貨照片<input type="file" accept="image/*" capture="environment" data-standalone-line-photo><span data-standalone-line-photo-preview>' + (previewImage ? '<img src="' + escapeHtml(previewImage) + '" alt="商品或到貨照片" data-receipt-thumb-zoom="' + escapeHtml(previewImage) + '" data-receipt-thumb-caption="' + escapeHtml([line.productCode, line.color].filter(Boolean).join('／')) + '"><b>' + (line.arrivalImage ? '已上傳，入庫後設為此色圖' : '點縮圖可放大，也可改到貨照片') + '</b>' : '<b>點此上傳到貨照片</b>') + '</span></label>',
        '<input type="hidden" value="' + escapeHtml(line.skuId || '') + '" data-standalone-line-sku>',
        '<input type="hidden" value="' + escapeHtml(line.productId || '') + '" data-standalone-line-product>',
        '</article>'
      ].join('');
    }).join('');
    renderStandaloneTotals();
  }

  function standaloneSizePresets(category) {
    var normalized = key(category);
    if (/鞋|靴|sneaker|shoe/.test(normalized)) {
      return Array.from({ length: 17 }, function (_, index) { return String(28 + index); });
    }
    if (/行李箱|旅行箱|拉桿箱|登機箱|luggage|suitcase/.test(normalized)) {
      return Array.from({ length: 9 }, function (_, index) { return String(20 + index * 2); });
    }
    if (/衣|褲|裙|外套|上衣|內衣|泳裝|服飾|tshirt|shirt|pants|dress|jacket/.test(normalized)) {
      return ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL'];
    }
    return ['NO SIZE'];
  }

  function standaloneColorPresets() {
    var values = [];
    (state.products || []).forEach(function (product) {
      (Array.isArray(product.colors) ? product.colors : []).forEach(function (color) { values.push(text(color.name || color.color || color.code)); });
    });
    (state.skus || []).forEach(function (sku) { values.push(text(sku.colorName || sku.color)); });
    return uniqueTextRows(values).filter(function (color) { return color && !receivedIsPlaceholderColor(color); }).sort(function (a, b) { return a.localeCompare(b, 'zh-Hant', { numeric: true }); });
  }

  function standaloneColorOptionsHtml(current) {
    current = text(current);
    var options = standaloneColorPresets();
    if (current && options.indexOf(current) === -1) options.unshift(current);
    return '<option value="">選擇現有顏色</option>' + options.map(function (color) { return '<option value="' + escapeHtml(color) + '"' + (color === current ? ' selected' : '') + '>' + escapeHtml(color) + '</option>'; }).join('') + '<option value="__custom__">新增其他顏色…</option>';
  }

  function standaloneSizeOptionsHtml(category, current) {
    var options = standaloneSizePresets(category);
    current = text(current);
    if (options.indexOf(current) === -1) options.push(current);
    options = options.filter(Boolean);
    return (!current ? '<option value="" selected>請選尺寸</option>' : '') + options.map(function (size) { return '<option value="' + escapeHtml(size) + '"' + (size === current ? ' selected' : '') + '>' + escapeHtml(size) + '</option>'; }).join('') + '<option value="__custom__">自訂尺寸…</option>';
  }

  function refreshStandaloneSizePicker(host) {
    if (!host) return;
    var category = text(host.querySelector('[data-standalone-line-category]') && host.querySelector('[data-standalone-line-category]').value);
    var sizeInput = host.querySelector('[data-standalone-line-size]');
    var picker = host.querySelector('[data-standalone-line-size-preset]');
    if (!picker || !sizeInput) return;
    var presets = standaloneSizePresets(category);
    var current = text(sizeInput.value);
    if (presets.length > 1 && presets.indexOf(current) === -1) {
      current = '';
      sizeInput.value = '';
    }
    picker.innerHTML = standaloneSizeOptionsHtml(category, current);
  }

  function addStandaloneVariantLine(sourceKey) {
    syncStandaloneLinesFromDom();
    var source = state.standaloneLines.find(function (line) { return line.key === sourceKey; });
    if (!source) return;
    state.standaloneLines.push(Object.assign({}, source, {
      key: 'PRL-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8),
      skuId: '', barcode: '', barcodeAuto: true, barcodeManualEdited: false, color: '', size: '', qty: 1, arrivalImage: '', productImage: ''
    }));
    renderStandaloneLines();
    var rows = document.querySelectorAll('[data-purchase-receipt-line]');
    var created = rows.length ? rows[rows.length - 1] : null;
    var colorInput = created && created.querySelector('[data-standalone-line-color]');
    if (created && created.scrollIntoView) created.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (colorInput) colorInput.focus();
    standaloneMessage('已新增同產品的新規格；請填新條碼、顏色、尺寸，並上傳該顏色圖片。', 'ok');
  }

  function automaticVariantBarcode(line) {
    /* LZ_BARCODE_FMT_20260924: 編號+顏色+尺碼+P(成本), no C/S letters. */
    var productCode = text(line.productCode).toUpperCase().replace(/[^A-Z0-9]/g, '');
    var color = text(line.color);
    var size = text(line.size);
    if (!productCode || !color || !size) return '';
    var cost = Math.max(0, Math.round(Number(line.unitCostTwd || line.cost || 0)));
    var colorCode = receiptColorCode(color);
    var sizeCode = padReceiptSizeCode(size);
    var candidate = composeCanonicalCompanyBarcode(productCode, colorCode, sizeCode, cost);
    if (!candidate) return '';
    return candidate;
  }

  function receiptColorCode(value) {
    var raw = text(value).toUpperCase().replace(/[\s()（）+＋\-_]/g, '');
    var aliases = {
      '黑色': '91', '黑': '91', 'HITEM': '91',
      '白色': '92', '白': '92', 'PUTI': '92', 'PUTIH': '92',
      '紅色': '93', '紅': '93', 'MERAL': '93', 'MERAH': '93',
      '黃色': '94', '黃': '94', 'KUR': '94', 'KUNING': '94',
      '綠色': '95', '綠': '95', 'HIGAU': '95', 'HIJAU': '95',
      '藍色': '96', '藍': '96', 'BIRU': '96',
      '粉紅色': '98', '粉紅': '98', '粉色': '98', 'PINK': '98',
      '灰色': '99', '灰': '99', 'ABU': '99',
      '卡其色': '904', 'DRIL': '904',
      '咖啡色': '902', '咖色': '902', 'COKELAT': '902',
      '圖片色': '907', 'PICSCOLOR': '907'
    };
    if (aliases[raw]) return aliases[raw];
    for (var name in aliases) {
      if (raw.indexOf(name) !== -1) return aliases[name];
    }
    return '';
  }

  function receiptSizeCode(value) {
    var raw = text(value).toUpperCase().replace(/[\s_]/g, '');
    if (!raw || raw === 'NOSIZE' || raw === 'NO-SIZE') return '00';
    var map = { XS: '1', S: '2', M: '3', L: '4', XL: '5', '2XL': '6', XXL: '6', '3XL': '7', XXXL: '7', '4XL': '8' };
    return map[raw] || '00';
  }

  function refreshAutomaticVariantBarcode(host) {
    var line = state.standaloneLines.find(function (row) { return row.key === (host && host.getAttribute('data-purchase-receipt-line')); });
    if (!line) return;
    var barcodeInput = host.querySelector('[data-standalone-line-barcode]');
    if (!barcodeInput || barcodeInput.dataset.barcodeManual === '1') return;
    line.productCode = text(host.querySelector('[data-standalone-line-product-code]') && host.querySelector('[data-standalone-line-product-code]').value);
    line.color = sanitizeInboundColorValue(host.querySelector('[data-standalone-line-color]') && host.querySelector('[data-standalone-line-color]').value, line, skuById(line.skuId), productById(line.productId) || productByCode(line.productCode), (host.querySelector('[data-standalone-line-barcode]') && host.querySelector('[data-standalone-line-barcode]').value) || line.barcode);
    var colorFieldLive = host.querySelector('[data-standalone-line-color]');
    if (colorFieldLive && line.color) colorFieldLive.value = line.color;
    line.size = text(host.querySelector('[data-standalone-line-size]') && host.querySelector('[data-standalone-line-size]').value);
    line.unitCostTwd = Math.max(0, Number(host.querySelector('[data-standalone-line-cost]') && host.querySelector('[data-standalone-line-cost]').value || line.unitCostTwd || 0));
    var matchedSku = state.skus.find(function (sku) {
      var skuProduct = productById(sku.productId) || {};
      var sameProduct = (line.productId && text(sku.productId) === text(line.productId)) || key(skuProduct.code || skuProduct.productLine || sku.productCode) === key(line.productCode);
      return sameProduct && key(sku.colorName || sku.color) === key(line.color) && key(sku.sizeName || sku.size || 'NO SIZE') === key(line.size || 'NO SIZE');
    });
    var product = productById(line.productId) || productByCode(line.productCode) || {};
    line.barcode = canonicalInboundBarcode(line, matchedSku || {}, product, matchedSku && (matchedSku.companyBarcode || matchedSku.barcode || matchedSku.id || matchedSku.sku) || line.barcode) || automaticVariantBarcode(line);
    line.barcodeAuto = true;
    line.barcodeManualEdited = false;
    line.productImage = firstProductImage(product, matchedSku || {}, line);
    var thumbHost = host.querySelector('.purchase-receipt-line-thumb');
    if (line.productImage && thumbHost && thumbHost.tagName === 'BUTTON') {
      var caption = [line.productCode, line.productName, line.color, line.size].filter(Boolean).join('／');
      thumbHost.setAttribute('data-receipt-thumb-zoom', line.productImage);
      thumbHost.setAttribute('data-receipt-thumb-caption', caption);
      var img = thumbHost.querySelector('img');
      if (img) img.src = line.productImage;
    }
    var photoImg = host.querySelector('[data-standalone-line-photo-preview] img');
    if (line.productImage && photoImg) {
      photoImg.src = line.productImage;
      photoImg.setAttribute('data-receipt-thumb-zoom', line.productImage);
    }
    barcodeInput.dataset.barcodeAuto = '1';
    barcodeInput.value = line.barcode;
  }

  function syncStandaloneLinesFromDom() {
    document.querySelectorAll('[data-purchase-receipt-line]').forEach(function (host) {
      var line = state.standaloneLines.find(function (row) { return row.key === host.getAttribute('data-purchase-receipt-line'); });
      if (!line) return;
      line.productName = text(host.querySelector('[data-standalone-line-product-name]') && host.querySelector('[data-standalone-line-product-name]').value);
      line.category = text(host.querySelector('[data-standalone-line-category]') && host.querySelector('[data-standalone-line-category]').value);
      line.productCode = text(host.querySelector('[data-standalone-line-product-code]') && host.querySelector('[data-standalone-line-product-code]').value);
      line.barcode = text(host.querySelector('[data-standalone-line-barcode]') && host.querySelector('[data-standalone-line-barcode]').value);
      line.barcodeAuto = !!(host.querySelector('[data-standalone-line-barcode]') && host.querySelector('[data-standalone-line-barcode]').dataset.barcodeAuto === '1');
      line.barcodeManualEdited = !!(host.querySelector('[data-standalone-line-barcode]') && host.querySelector('[data-standalone-line-barcode]').dataset.barcodeManual === '1');
      line.color = sanitizeInboundColorValue(host.querySelector('[data-standalone-line-color]') && host.querySelector('[data-standalone-line-color]').value, line, skuById(line.skuId), productById(line.productId) || productByCode(line.productCode), line.barcode);
      var colorInput = host.querySelector('[data-standalone-line-color]');
      if (colorInput && line.color && colorInput.value !== line.color) colorInput.value = line.color;
      line.size = text(host.querySelector('[data-standalone-line-size]') && host.querySelector('[data-standalone-line-size]').value) || 'NO SIZE';
      line.qty = Math.max(1, Number(host.querySelector('[data-standalone-line-qty]') && host.querySelector('[data-standalone-line-qty]').value || 1));
      line.unitCostTwd = Math.max(0, Number(host.querySelector('[data-standalone-line-cost]') && host.querySelector('[data-standalone-line-cost]').value || 0));
    });
  }

  function renderStandaloneTotals() {
    syncStandaloneLinesFromDom();
    var host = document.querySelector('[data-purchase-receipt-totals]');
    if (!host) return;
    var qty = state.standaloneLines.reduce(function (sum, line) { return sum + Math.max(1, Number(line.qty || 1)); }, 0);
    var cost = state.standaloneLines.reduce(function (sum, line) { return sum + Math.max(1, Number(line.qty || 1)) * Math.max(0, Number(line.unitCostTwd || 0)); }, 0);
    host.innerHTML = '<span>品項 <b>' + state.standaloneLines.length + '</b></span><span>數量 <b>' + qty + '</b></span><span>成本合計 <b>' + money(cost) + '</b></span>';
  }

  function catalogSkuForReceiptLine(line, warehouse) {
    line = line || {};
    var product = productById(line.productId) || productByCode(line.productCode);
    var wantedBarcode = key(line.barcode);
    var wantedBarcodeKeys = wantedBarcode ? inboundBarcodeSearchKeys(line.barcode) : [];
    var wantedColor = key(line.color);
    var wantedSize = key(line.size || 'NO SIZE');
    var warehouseCode = selfUseWarehouseCode(warehouse || (document.querySelector('[data-purchase-receipt-warehouse]') && document.querySelector('[data-purchase-receipt-warehouse]').value) || '');
    var matches = (state.skus || []).filter(function (sku) {
      if (!sku || sku.temporaryFreightSku) return false;
      if (product && text(sku.productId) && text(sku.productId) !== text(product.id)) return false;
      var barcodeOk = !wantedBarcode || skuExactBarcodeHit(sku, wantedBarcodeKeys);
      var colorOk = !wantedColor || key(sku.colorName || sku.color) === wantedColor;
      var sizeOk = !wantedSize || key(sku.sizeName || sku.size || 'NO SIZE') === wantedSize;
      return barcodeOk && colorOk && sizeOk;
    });
    if (!matches.length && product) {
      matches = (state.skus || []).filter(function (sku) {
        if (!sku || sku.temporaryFreightSku) return false;
        if (text(sku.productId) !== text(product.id)) return false;
        var colorOk = !wantedColor || key(sku.colorName || sku.color) === wantedColor;
        var sizeOk = !wantedSize || key(sku.sizeName || sku.size || 'NO SIZE') === wantedSize;
        return colorOk && sizeOk;
      });
    }
    return matches.find(function (sku) { return selfUseWarehouseCode(sku.warehouse || sku.warehouseCode || sku.warehouseName) === warehouseCode; }) || matches[0] || null;
  }

  function attachExistingCatalogToLine(line) {
    line = line || {};
    var product = productById(line.productId) || productByCode(line.productCode);
    if (product && !product.temporaryFreightProduct) {
      line.productId = text(product.id);
      if (!line.productCode) line.productCode = text(product.code || product.productLine || product.id);
      if (!line.productName) line.productName = text(product.title || product.name || line.productCode);
      if (!line.category) line.category = productCategory(product);
    }
    var sku = skuById(line.skuId);
    if (sku && sku.temporaryFreightSku) sku = null;
    if (!sku) sku = catalogSkuForReceiptLine(line);
    if (sku) {
      line.skuId = text(sku.id || sku.sku);
      if (!line.productId) line.productId = text(sku.productId);
      if (!line.barcode) line.barcode = canonicalInboundBarcode(line, sku, product, text(sku.companyBarcode || sku.barcode || line.barcode));
    }
    return line;
  }

  function receivedLineBarcode(line, sku) {
    /* LZ_PRINT_RAW_20260924: print companyBarcode VERBATIM. Never rewrite into C/S/P/V3. */
    sku = sku || skuById(line && (line.skuId || line.sku)) || {};
    line = line || {};
    return text(
      sku.companyBarcode
      || sku.officialBarcode
      || line.companyBarcode
      || line.officialBarcode
      || sku.barcode
      || line.receivedBarcode
      || line.barcode
      || sku.legacyBarcode
      || sku.mappingCode
      || line.sampleBarcode
      || line.taiwanBarcode
      || line.labelBarcode
      || sku.id
      || sku.sku
    );
  }

  function receivedColorNameFromCode(code) {
    var map = {
      '91': '黑色', '92': '白色', '93': '紅色', '94': '黃色', '95': '綠色', '96': '藍色',
      '98': '粉紅色', '99': '灰色', '904': '卡其色', '902': '咖啡色', '912': '棕色', '952': '淺灰色'
    };
    return map[String(code || '').trim()] || '';
  }

  function receivedIsPlaceholderColor(value) {
    /* LZ_PRINT_QR_20260924_6: 圖片色 is catalog 907 / PICS COLOR, not a real colour.
       Also treat 顏色圖片 photo-label leftovers as placeholders. */
    return /圖片色|pics\s*color|picscolor|顏色圖片/.test(key(value));
  }

  function receivedPrintColorName(line, sku, product, barcode) {
    sku = sku || {};
    product = product || {};
    line = line || {};
    var raw = text(line.color || line.colorName || sku.colorName || sku.color);
    var display = '';
    try {
      display = receiptDisplayColor({ colorName: raw, colorCode: sku.colorCode || sku.colorNo, productId: sku.productId || line.productId }, product);
    } catch (error) {
      display = receiptChineseColor(raw) || text(raw);
    }
    if (display && !receivedIsPlaceholderColor(display)) return display;
    var code = text(sku.colorCode || sku.colorNo || receiptColorCode(raw));
    var parsed = receivedParseConcatBarcode(barcode || receivedLineBarcode(line, sku), line, sku, product);
    if (parsed && parsed.colorCode) code = code || parsed.colorCode;
    var fromCode = receivedColorNameFromCode(code);
    if (fromCode) return fromCode;
    var colors = Array.isArray(product.colors) ? product.colors : [];
    for (var i = 0; i < colors.length; i += 1) {
      var row = colors[i] || {};
      var rowName = receiptChineseColor(row.name || row.colorName || row.color) || text(row.name || row.colorName || row.color);
      if (rowName && !receivedIsPlaceholderColor(rowName)) return rowName;
    }
    return display && !receivedIsPlaceholderColor(display) ? display : (text(raw) && !receivedIsPlaceholderColor(raw) ? text(raw) : '-');
  }

  function sanitizeInboundColorValue(value, line, sku, product, barcode) {
    /* LZ_PRINT_QR_20260924_6: never persist 圖片色. Prefer barcode colour 904→卡其. */
    var raw = text(value);
    line = line || {};
    sku = sku || skuById(line.skuId || line.sku) || {};
    product = product || productById(line.productId || sku.productId) || productByCode(line.productCode) || {};
    barcode = barcode || receivedLineBarcode(line, sku) || text(line.barcode || line.companyBarcode);
    if (raw && !receivedIsPlaceholderColor(raw)) return raw;
    var resolved = receivedPrintColorName(Object.assign({}, line, { color: raw, colorName: raw }), sku, product, barcode);
    if (resolved && resolved !== '-' && !receivedIsPlaceholderColor(resolved)) return resolved;
    return '';
  }

  function receivedLineDisplayColor(line) {
    line = line || {};
    var sku = skuById(line.skuId || line.sku) || {};
    var product = productById(line.productId || sku.productId) || productByCode(line.productCode) || {};
    var barcode = receivedLineBarcode(line, sku);
    var shown = receivedPrintColorName(line, sku, product, barcode);
    if (shown && shown !== '-' && !receivedIsPlaceholderColor(shown)) return shown;
    return sanitizeInboundColorValue(line.color || line.colorName, line, sku, product, barcode) || text(line.color || line.colorName || '');
  }

  function receivedParseConcatBarcode(raw, line, sku, product) {
    var code = String(raw || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    var skuColor = text((sku && (sku.colorCode || sku.colorNo)) || receiptColorCode((line && (line.color || line.colorName)) || (sku && (sku.colorName || sku.color)) || ''));
    var costNum = Math.max(0, Math.round(Number((line && (line.unitCostTwd || line.cost || line.barcodeCostTwd)) || (sku && (sku.currentCostTwd || sku.cost)) || 0)));
    var productCode = text((line && line.productCode) || (product && (product.code || product.productLine)) || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    var sizeHint = (line && (line.size || line.sizeName)) || (sku && (sku.sizeName || sku.size)) || '';
    var v3 = code.match(/^([A-Z0-9]+)C([A-Z0-9]+)S([A-Z0-9]+)P(\d+)$/);
    if (v3) return { base: v3[1], colorCode: v3[2], sizeCode: padReceiptSizeCode(v3[3]), cost: v3[4] };
    var v2 = code.match(/^([A-Z0-9]+)P(\d+)C([A-Z0-9]+)S([A-Z0-9]+)$/);
    if (v2) return { base: v2[1], colorCode: v2[3], sizeCode: padReceiptSizeCode(v2[4]), cost: v2[2] };
    var tailP = code.match(/^([A-Z]+)(\d+)P(\d+)$/);
    if (!tailP) return null;
    var letters = tailP[1];
    var mid = tailP[2];
    var afterP = tailP[3];
    var isNewTail = afterP.length <= 4 && mid.length >= 5;
    var isOldMid = afterP.length >= 5;
    if (isNewTail) {
      var peeled = peelSizeFromMidDigits(mid, skuColor);
      var split = splitProductColorFromBody(letters, peeled.body, skuColor, productCode);
      if (split.colorCode) {
        return {
          base: split.base || productCode || (letters + peeled.body),
          colorCode: split.colorCode,
          sizeCode: peeled.sizeCode || padReceiptSizeCode(sizeHint),
          cost: afterP.replace(/^0+(?=\d)/, '')
        };
      }
    }
    if (isOldMid || !isNewTail) {
      var base = letters + mid;
      var digits = afterP;
      var colorCode = '';
      var cost = '';
      if (productCode && base !== productCode && letters + mid === productCode) base = productCode;
      if (skuColor && digits.length > skuColor.length && digits.slice(-skuColor.length) === skuColor) {
        colorCode = skuColor;
        cost = digits.slice(0, -skuColor.length);
      } else if (costNum > 0 && digits.indexOf(String(costNum)) === 0 && digits.length > String(costNum).length) {
        cost = String(costNum);
        colorCode = digits.slice(String(costNum).length);
      } else if (knownInboundColorCode(digits.slice(-3)) && digits.length > 3) {
        colorCode = digits.slice(-3);
        cost = digits.slice(0, -3);
      } else if (knownInboundColorCode(digits.slice(-2)) && digits.length > 2) {
        colorCode = digits.slice(-2);
        cost = digits.slice(0, -2);
      } else if (digits.length >= 5) {
        colorCode = digits.slice(-3);
        cost = digits.slice(0, -3);
      } else {
        if (isNewTail) return null;
        return null;
      }
      if (!cost || !colorCode) return null;
      return { base: productCode || (letters + mid), colorCode: colorCode, sizeCode: padReceiptSizeCode(sizeHint), cost: cost.replace(/^0+(?=\d)/, '') };
    }
    return null;
  }

  function receivedPrintBarcode(raw, line, sku, product) {
    /* LZ_BARCODE_FMT_20260924: print 編號+顏色+尺碼+P(成本). NO SIZE=00. No C/S. Keep window.print path. */
    return canonicalInboundBarcode(line, sku, product, raw) || String(raw || '').trim().toUpperCase();
  }

  function receivedBarcodePrintPayloads(lines) {
    var payloads = [];
    (Array.isArray(lines) ? lines : []).forEach(function (line) {
      var sku = skuById(line.skuId || line.sku) || {};
      var product = productById(line.productId || sku.productId) || productByCode(line.productCode) || {};
      var stored = receivedLineBarcode(line, sku);
      if (!stored) return;
      var barcode = receivedPrintBarcode(stored, line, sku, product) || stored;
      var quantity = Math.max(1, Math.min(99, Math.floor(Number(line.qty || line.quantity || 1))));
      var size = text(line.size || line.sizeName || sku.sizeName || sku.size) || 'NO SIZE';
      var payload = {
        barcode: barcode,
        storedBarcode: stored,
        barcodeSource: '正式入庫條碼',
        lockFormalBarcode: true,
        backendCode: text(sku.sku || sku.id || line.skuId),
        productCode: text(line.productCode || product.code || product.productLine || product.id || barcode),
        brand: text(product.brand || product.brandName),
        spec: text(product.spec || product.specification || product.variantSpec),
        color: receivedPrintColorName(line, sku, product, stored),
        size: size,
        title: text(line.productName || product.title || product.name || line.productCode || barcode)
      };
      for (var index = 0; index < quantity; index += 1) payloads.push(Object.assign({}, payload));
    });
    return payloads;
  }

  function receivedCode128Pattern(value) {
    var patterns = [
      '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
      '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
      '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
      '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
      '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
      '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
      '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
      '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
      '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
      '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
      '114131','311141','411131','211412','211214','211232','2331112'
    ];
    var raw = String(value || '').trim();
    if (!raw) return '';
    var codes = [104];
    for (var i = 0; i < raw.length; i += 1) {
      var code = raw.charCodeAt(i);
      if (code < 32 || code > 126) return '';
      codes.push(code - 32);
    }
    var sum = codes[0];
    for (var weight = 1; weight < codes.length; weight += 1) sum += codes[weight] * weight;
    codes.push(sum % 103);
    codes.push(106);
    return codes.map(function (item) { return patterns[item] || ''; }).join('');
  }

  function receivedCode128Svg(value) {
    /* LZ_PRINT_QR_20260924_9: AEQ918T2 203dpi thermal.
       Match 出貨 code128Svg: 1 module = 0.125mm (1 dot). JsBarcode-equivalent width:1, margin:10.
       Do NOT stretch SVG to 100% — that fattened bars until they blobbed.
       Payload is print code only; human text stays outside the SVG. */
    var pattern = receivedCode128Pattern(value);
    if (!pattern) return '';
    var quietModules = 10;
    var x = quietModules;
    var bars = [];
    for (var i = 0; i < pattern.length; i += 1) {
      var width = Number(pattern[i]);
      if (i % 2 === 0) {
        bars.push('<rect x="' + x + '" y="0" width="' + width + '" height="80"/>');
      }
      x += width;
    }
    var totalWidth = x + quietModules;
    var physicalWidthMm = (totalWidth * 0.125).toFixed(3);
    return '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" style="width:' + physicalWidthMm + 'mm;height:10.6mm;max-width:none" viewBox="0 0 ' + totalWidth + ' 80" preserveAspectRatio="none" shape-rendering="crispEdges"><rect width="' + totalWidth + '" height="80" fill="#fff"/><g fill="#000">' + bars.join('') + '</g></svg>';
  }

  function receivedQrCodeSvg(value) {
    /* LZ_PRINT_QR_20260924: same qrcode-generator SVG as admin inventory labels. Draw before print; never clone empty canvases. */
    value = String(value || '').trim();
    if (!value || value.length > 32 || /[^\x20-\x7e]/.test(value)) return '';
    if (typeof window === 'undefined' || typeof window.qrcode !== 'function') return '';
    var qr;
    try {
      qr = window.qrcode(value.length <= 14 ? 1 : 2, 'M');
      qr.addData(value, 'Byte');
      qr.make();
    } catch (qrError) {
      try {
        qr = window.qrcode(value.length <= 17 ? 1 : 2, 'L');
        qr.addData(value, 'Byte');
        qr.make();
      } catch (qrError2) {
        return '';
      }
    }
    var size = qr.getModuleCount();
    var quiet = 4;
    var canvas = size + quiet * 2;
    var rects = [];
    for (var row = 0; row < size; row += 1) {
      for (var col = 0; col < size; col += 1) {
        if (qr.isDark(row, col)) {
          rects.push('<rect x="' + (col + quiet) + '" y="' + (row + quiet) + '" width="1" height="1"/>');
        }
      }
    }
    return '<svg class="qr-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + canvas + ' ' + canvas + '" shape-rendering="crispEdges"><rect width="' + canvas + '" height="' + canvas + '" fill="#fff"/><g fill="#000">' + rects.join('') + '</g></svg>';
  }

  function ensureReceivedQrcodeLib(done) {
    if (typeof window.qrcode === 'function') {
      done();
      return;
    }
    var existing = document.querySelector('script[data-lz-qrcode-lib], script[src*="qrcode-generator"]');
    var finished = false;
    var finish = function () {
      if (finished) return;
      finished = true;
      done();
    };
    if (existing) {
      existing.addEventListener('load', finish);
      window.setTimeout(finish, 1200);
      return;
    }
    var script = document.createElement('script');
    script.src = './assets/vendor/qrcode-generator.min.js';
    script.setAttribute('data-lz-qrcode-lib', '');
    script.onload = finish;
    script.onerror = finish;
    document.head.appendChild(script);
    window.setTimeout(finish, 1800);
  }

  function receivedPrintCodeLineHtml(code) {
    var textCode = String(code || '').trim().toUpperCase();
    var match = textCode.match(/^(.*?)(P\d+)$/);
    if (!textCode) return '<div class="code-line is-empty">-</div>';
    if (!match || !match[1]) return '<div class="code-line is-single">' + escapeHtml(textCode) + '</div>';
    return '<div class="code-line is-split"><span class="code-body">' + escapeHtml(match[1]) + '</span><span class="code-cost">' + escapeHtml(match[2]) + '</span></div>';
  }

  function receivedBarcodeLabelHtml(payload) {
    var barcode = text(payload && payload.barcode);
    var qrMarkup = receivedQrCodeSvg(barcode) || ('<div class="qr-fallback"><b>QR</b><span>' + escapeHtml(barcode) + '</span></div>');
    var svg = receivedCode128Svg(barcode) || ('<div class="barcode-fallback">' + escapeHtml(barcode) + '</div>');
    var color = text(payload && payload.color) || '-';
    var size = text(payload && payload.size) || 'NO SIZE';
    return [
      '<div class="lz-recv-sticker qr-label">',
      '<div class="label-main">',
      '<div class="qr-area qr-area-main" aria-label="產品二維碼">' + qrMarkup + '</div>',
      '<div class="label-info">',
      receivedPrintCodeLineHtml(barcode),
      '<div class="product-line"><b>產品</b><span>' + escapeHtml(payload.productCode || '') + ' · ' + escapeHtml(payload.title || '') + '</span></div>',
      '<div class="variant-grid">',
      '<div class="variant-color"><b>顏色</b><span>' + escapeHtml(color) + '</span></div>',
      '<div class="variant-size"><b>尺寸</b><span>' + escapeHtml(size) + '</span></div>',
      '</div>',
      '</div>',
      '</div>',
      '<div class="barcode-area" aria-label="一維碼">' + svg + '</div>',
      '<div class="brand-note"><span>' + escapeHtml(barcode) + '</span></div>',
      '</div>'
    ].join('');
  }

  function receivedBarcodePrintDocument(payloads) {
    var labels = (payloads || []).map(receivedBarcodeLabelHtml).join('');
    return [
      '<!doctype html><html><head><meta charset="utf-8"><title>LINGZANZAN 入庫條碼</title>',
      '<style>',
      '@page{size:40mm 30mm;margin:0;}',
      'html,body{margin:0;padding:0;background:#fff;color:#000;font-family:Arial,"PingFang TC","Microsoft JhengHei",sans-serif;}',
      '.lz-recv-sticker{box-sizing:border-box;width:40mm;height:30mm;max-height:30mm;padding:.8mm 1mm .6mm;display:grid;grid-template-rows:13.8mm 10.6mm 2.8mm;gap:.3mm;overflow:hidden;break-after:page;page-break-after:always;break-inside:avoid;page-break-inside:avoid;}',
      '.lz-recv-sticker:last-child{break-after:auto;page-break-after:auto;}',
      '.label-main{min-width:0;height:13.8mm;display:grid;grid-template-columns:12.2mm minmax(0,1fr);gap:.6mm;align-items:start;overflow:hidden;}',
      '.qr-area-main{width:12.2mm;height:12.2mm;padding:.3mm;border:.22mm solid #111;box-sizing:border-box;background:#fff;}',
      '.qr-svg{width:100%;height:100%;display:block;background:#fff;shape-rendering:crispEdges;}',
      '.qr-fallback{box-sizing:border-box;width:100%;height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;border:.25mm dashed #111;font-size:5pt;font-weight:900;text-align:center;overflow:hidden;}',
      '.label-info{min-width:0;height:13.8mm;display:grid;grid-template-rows:4.6mm 3.2mm 5.4mm;gap:.3mm;overflow:hidden;}',
      '.code-line{font-size:5pt;font-weight:900;letter-spacing:-.03em;white-space:nowrap;overflow:hidden;}',
      '.product-line{font-size:3.8pt;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.variant-grid{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:5.4mm;gap:.35mm;align-content:end;}',
      '.variant-grid>div{box-sizing:border-box;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:flex-start;border:.2mm solid #222;border-radius:.4mm;padding:1.35mm .45mm .28mm .5mm;font-size:5pt;line-height:1.05;overflow:hidden;}',
      '.variant-grid b{display:block;margin:0 0 .12mm;padding:0;font-size:3.7pt;font-weight:900;line-height:1;}',
      '.variant-grid span{display:block;margin:0;padding:0;font-size:5.1pt;font-weight:950;line-height:1.05;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      '.barcode-area{box-sizing:border-box;display:flex;align-items:center;justify-content:center;height:10.6mm;padding:0;overflow:visible;background:#fff;}',
      '.barcode-svg{height:10.6mm;max-width:none;display:block;flex:0 0 auto;background:#fff;shape-rendering:crispEdges;image-rendering:pixelated;}',
      '.barcode-fallback{width:100%;height:100%;display:flex;align-items:center;justify-content:center;border:.22mm solid #111;font-size:6pt;font-weight:900;}',
      '.brand-note{display:flex;align-items:center;justify-content:center;height:2.8mm;border:0;font-size:4.3pt;font-weight:900;letter-spacing:.04em;line-height:1;overflow:hidden;}',
      '@media print{html,body{width:40mm!important}.lz-recv-sticker{width:40mm!important;height:30mm!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}svg,canvas,img,.qr-svg,.barcode-svg{display:block!important;visibility:visible!important}}',
      '</style></head><body>', labels, '</body></html>'
    ].join('');
  }

  function closeReceivedBarcodePrintOverlay() {
    var overlay = document.querySelector('[data-received-barcode-print-overlay]');
    if (overlay) overlay.remove();
    clearReceivedPrintMode();
  }

  function receivedPrintSheet() {
    var sheet = document.querySelector('[data-received-print-sheet]');
    if (sheet) return sheet;
    sheet = document.createElement('div');
    sheet.setAttribute('data-received-print-sheet', '');
    sheet.className = 'received-barcode-print-sheet';
    document.body.appendChild(sheet);
    return sheet;
  }

  function receivedPrintStyleText() {
    /* LZ_PRINT_QR_20260924_9: 203dpi 1-dot modules; hide overlay leftovers. */
    return [
      '@page{size:40mm 30mm;margin:0;}',
      '@media print{',
      'html,body{margin:0!important;padding:0!important;background:#fff!important;color:#000!important;height:auto!important;min-height:0!important;max-height:none!important;overflow:visible!important;visibility:visible!important;}',
      'html:before,html:after,body:before,body:after,*:before,*:after{display:none!important;content:none!important;}',
      'html body *{visibility:hidden!important;}',
      'html body [data-received-print-sheet],html body [data-received-print-sheet] *{visibility:visible!important;}',
      'body > *:not([data-received-print-sheet]){display:none!important;visibility:hidden!important;opacity:0!important;height:0!important;width:0!important;overflow:hidden!important;position:static!important;inset:auto!important;left:auto!important;top:auto!important;background:transparent!important;box-shadow:none!important;border:0!important;border-radius:0!important;}',
      '[data-received-barcode-print-overlay],[data-received-barcode-print-overlay] *,.inventory-barcode-print-overlay,.inventory-barcode-print-overlay *,.received-barcode-print-preview,.received-barcode-print-preview *,[data-received-print-preview],[data-received-print-preview] *,.freight-received-print-ready,.freight-received-print-ready *,.receipt-thumb-zoom,.receipt-thumb-zoom *,.login-gate,.login-card,.company-announcement-board,.company-announcement-board *,.company-announcement-modal,.company-announcement-modal *,.company-announcement-modal-card,.reserved-shipping-card,.freight-card-print-page,[data-purchase-receipt-print-picker],[data-purchase-receipt-print-picker] *{display:none!important;visibility:hidden!important;opacity:0!important;background:transparent!important;box-shadow:none!important;border:0!important;border-radius:0!important;position:static!important;inset:auto!important;width:0!important;height:0!important;overflow:hidden!important;}',
      '.admin-shell,.login-gate,.admin-top-actions,.sidebar,.admin-section-outline,.inventory-barcode-print-overlay,.inventory-barcode-print-overlay *,[data-purchase-receipt-print-picker],[data-stock-docs],.receipt-thumb-zoom,.toast,[data-inventory-label-purpose-picker],iframe,.reserved-shipping-card,.reserved-shipping-candidate,.freight-fifo-share-card,.freight-card-print-page,.freight-fifo-stage,.freight-fifo-reserved-quick,.company-announcement-board,.company-announcement-modal,.company-announcement-item,.company-announcement-modal-card,.company-announcement-head,[data-company-announcement-board],.freight-received-print-ready{display:none!important;}',
      '[data-received-print-sheet],.received-barcode-print-sheet{display:block!important;position:absolute!important;top:0!important;left:0!important;width:40mm!important;height:auto!important;min-height:0!important;margin:0!important;padding:0!important;border:0!important;background:#fff!important;color:#000!important;visibility:visible!important;border-radius:0!important;break-before:avoid!important;page-break-before:avoid!important;}',
      '[data-received-print-sheet] .lz-recv-sticker{box-sizing:border-box;width:40mm;height:30mm;max-height:30mm;margin:0;padding:.8mm 1mm .6mm;display:grid!important;grid-template-rows:13.8mm 10.6mm 2.8mm;gap:.3mm;overflow:hidden;border-radius:0!important;break-before:avoid;page-break-before:avoid;break-after:page;page-break-after:always;break-inside:avoid;page-break-inside:avoid;}',
      '[data-received-print-sheet] .lz-recv-sticker:first-child{break-before:avoid!important;page-break-before:avoid!important;margin-top:0!important;}',
      '[data-received-print-sheet] .lz-recv-sticker:last-child{break-after:auto;page-break-after:auto;}',
      '[data-received-print-sheet] .label-main{min-width:0;height:13.8mm;display:grid;grid-template-columns:12.2mm minmax(0,1fr);gap:.6mm;align-items:start;overflow:hidden;}',
      '[data-received-print-sheet] .label-info{min-width:0;height:13.8mm;display:grid;grid-template-rows:4.6mm 3.2mm 5.4mm;gap:.3mm;overflow:hidden;}',
      '[data-received-print-sheet] .variant-grid{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:5.8mm;gap:.4mm;align-content:end;overflow:hidden;}',
      '[data-received-print-sheet] .variant-grid>div{box-sizing:border-box;height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:flex-start;border:.2mm solid #222;border-radius:.4mm;padding:1.35mm .45mm .28mm .5mm;font-size:5pt;line-height:1.05;overflow:hidden;}',
      '[data-received-print-sheet] .variant-grid b{display:block;margin:0 0 .12mm;padding:0;font-size:3.7pt;font-weight:900;line-height:1;flex:0 0 auto;}',
      '[data-received-print-sheet] .variant-grid span{display:block;margin:0;padding:0;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:5.1pt;font-weight:950;line-height:1.05;}',
      '[data-received-print-sheet] .barcode-area{box-sizing:border-box;display:flex;align-items:center;justify-content:center;height:10.6mm;padding:0;overflow:visible;background:#fff;}',
      '[data-received-print-sheet] .barcode-svg{height:10.6mm;max-width:none!important;width:auto;flex:0 0 auto;display:block;background:#fff;shape-rendering:crispEdges;image-rendering:pixelated;}',
      '[data-received-print-sheet] .brand-note{display:flex;align-items:center;justify-content:center;height:2.8mm;border:0;font-size:4.3pt;font-weight:900;letter-spacing:.04em;line-height:1;overflow:hidden;}',
      '[data-received-print-sheet] .qr-area-main{width:12.2mm;height:12.2mm;padding:.3mm;border:.22mm solid #111;box-sizing:border-box;background:#fff;}',
      '[data-received-print-sheet] svg,[data-received-print-sheet] canvas,[data-received-print-sheet] img,[data-received-print-sheet] .qr-svg,[data-received-print-sheet] .barcode-svg{display:block!important;visibility:visible!important;}',
      '}'
    ].join('');
  }

  function ensureReceivedPrintStyle() {
    var style = document.getElementById('lz-received-print-style');
    if (!style) {
      style = document.createElement('style');
      style.id = 'lz-received-print-style';
      document.head.appendChild(style);
    }
    style.textContent = receivedPrintStyleText();
    return style;
  }

  function kickReceivedQrcodeLib() {
    if (typeof window.qrcode === 'function') return;
    if (document.querySelector('script[data-lz-qrcode-lib]')) return;
    var script = document.createElement('script');
    script.src = './assets/vendor/qrcode-generator.min.js?v=20260924-print-qr-9';
    script.setAttribute('data-lz-qrcode-lib', '');
    document.head.appendChild(script);
  }

  function clearReceivedPrintMode() {
    /* LZ_PRINT_QR_20260924_5: only when overlay closes — never on afterprint (Chrome fires that when the dialog opens). */
    document.body.classList.remove('is-printing-received-barcodes', 'is-received-print-page');
    document.documentElement.classList.remove('is-printing-received-barcodes', 'is-received-print-page');
    var sheet = document.querySelector('[data-received-print-sheet]');
    if (sheet) {
      try {
        sheet.style.removeProperty('display');
        sheet.style.removeProperty('visibility');
        sheet.style.removeProperty('position');
        sheet.style.removeProperty('top');
        sheet.style.removeProperty('left');
        sheet.style.removeProperty('margin');
        sheet.style.removeProperty('padding');
      } catch (clearErr) {}
    }
    var style = document.getElementById('lz-received-print-style');
    if (style) style.textContent = '';
  }

  function printReceivedBarcodeSheet(payloads) {
    /* LZ_PRINT_QR_20260924_7: never unhide overlay while Chrome print dialog is open. */
    kickReceivedQrcodeLib();
    var overlay = document.querySelector('[data-received-barcode-print-overlay]');
    if (overlay) {
      overlay.style.setProperty('display', 'none', 'important');
      overlay.setAttribute('hidden', '');
      overlay.setAttribute('data-print-hidden', '1');
    }
    var sheet = receivedPrintSheet();
    if (sheet.parentNode !== document.body || document.body.firstElementChild !== sheet) {
      document.body.insertBefore(sheet, document.body.firstChild);
    }
    sheet.innerHTML = (payloads || []).map(receivedBarcodeLabelHtml).join('');
    try {
      sheet.style.setProperty('display', 'block', 'important');
      sheet.style.setProperty('visibility', 'visible', 'important');
      sheet.style.setProperty('position', 'absolute', 'important');
      sheet.style.setProperty('top', '0', 'important');
      sheet.style.setProperty('left', '0', 'important');
      sheet.style.setProperty('margin', '0', 'important');
      sheet.style.setProperty('padding', '0', 'important');
    } catch (styleErr) {
      sheet.style.display = 'block';
      sheet.style.visibility = 'visible';
      sheet.style.position = 'absolute';
      sheet.style.top = '0';
      sheet.style.left = '0';
    }
    document.body.classList.add('is-printing-received-barcodes', 'is-received-print-page');
    document.documentElement.classList.add('is-printing-received-barcodes', 'is-received-print-page');
    ensureReceivedPrintStyle();
    window.focus();
    window.print();
    return true;
  }

  function downloadReceivedBarcodeDocument(html) {
    var blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = 'LINGZANZAN-receiving-barcode.html';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  function revealReceivedBarcodeOverlay(overlay) {
    if (!overlay || !overlay.isConnected) return;
    if (window.matchMedia && window.matchMedia('print').matches) return;
    overlay.removeAttribute('data-print-hidden');
    overlay.hidden = false;
    overlay.style.removeProperty('display');
  }

  function scheduleReceivedOverlayReveal(overlay) {
    if (!overlay) return;
    function tryReveal() { revealReceivedBarcodeOverlay(overlay); }
    window.addEventListener('focus', tryReveal);
    document.addEventListener('visibilitychange', tryReveal);
    if (window.matchMedia) {
      var mql = window.matchMedia('print');
      var onPrint = function (e) { if (e && !e.matches) tryReveal(); };
      if (mql.addEventListener) mql.addEventListener('change', onPrint);
      else if (mql.addListener) mql.addListener(onPrint);
    }
  }

  function showReceivedBarcodePrintOverlay(payloads, options) {
    /* LZ_PRINT_QR_20260924_7: overlay (white rounded preview card) stays display:none during print. */
    options = options || {};
    var existing = document.querySelector('[data-received-barcode-print-overlay]');
    if (existing) existing.remove();
    var html = receivedBarcodePrintDocument(payloads);
    if (!options.skipAutoPrint) printReceivedBarcodeSheet(payloads);
    var overlay = document.createElement('div');
    overlay.setAttribute('data-received-barcode-print-overlay', '');
    overlay.className = 'inventory-barcode-print-overlay';
    overlay.innerHTML = '<section role="dialog" aria-modal="true">'
      + '<header><div><small>RECEIVED BARCODE PREVIEW</small><h3>已勾選 ' + payloads.length + ' 張，列印視窗應已出現</h3></div>'
      + '<button type="button" class="ghost-button" data-label-overlay-close aria-label="關閉">×</button></header>'
      + '<div class="received-barcode-print-preview" data-received-print-preview>' + (payloads || []).map(receivedBarcodeLabelHtml).join('') + '</div>'
      + '<footer>'
      + '<button type="button" class="primary-button" data-label-overlay-print>再印一次</button>'
      + '<button type="button" class="ghost-button" data-label-overlay-download>下載列印檔</button>'
      + '<button type="button" class="ghost-button" data-label-overlay-close>關閉</button>'
      + '</footer></section>';
    overlay.hidden = true;
    overlay.setAttribute('data-print-hidden', '1');
    overlay.style.setProperty('display', 'none', 'important');
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay || event.target.closest('[data-label-overlay-close]')) {
        event.preventDefault();
        closeReceivedBarcodePrintOverlay();
        return;
      }
      if (event.target.closest('[data-label-overlay-print]')) {
        event.preventDefault();
        printReceivedBarcodeSheet(payloads);
        return;
      }
      if (!event.target.closest('[data-label-overlay-download]')) return;
      event.preventDefault();
      downloadReceivedBarcodeDocument(html);
    });
    document.body.appendChild(overlay);
    scheduleReceivedOverlayReveal(overlay);
    return true;
  }

  function openReceivedBarcodePrint(lines, options) {
    var payloads = receivedBarcodePrintPayloads(lines);
    if (!payloads.length) {
      standaloneMessage('目前沒有可列印的本次進貨條碼。', 'error');
      return false;
    }
    try {
      return showReceivedBarcodePrintOverlay(payloads, options);
    } catch (error) {
      standaloneMessage('列印失敗：' + (error && error.message || error), 'error');
      return false;
    }
  }

  function hideReceivedPrintPicker() {
    var picker = document.querySelector('[data-purchase-receipt-print-picker]');
    if (picker) picker.hidden = true;
  }

  function setCompleteActionsVisible(visible) {
    var completeActions = document.querySelector('[data-purchase-receipt-complete-actions]');
    if (completeActions) completeActions.hidden = !visible;
  }

  function setReceivedPrintPage(on, summary) {
    var builder = document.querySelector('[data-purchase-receipt-builder]');
    var complete = document.querySelector('[data-purchase-receipt-complete]');
    var formActions = document.querySelector('[data-purchase-receipt-form-actions]') || document.querySelector('.purchase-receipt-actions');
    var status = document.querySelector('[data-purchase-receipt-status]');
    var head = document.querySelector('.purchase-receipt-builder-head');
    var headTitle = head && head.querySelector('h3');
    var headHint = head && head.querySelector('span');
    var headEyebrow = head && head.querySelector('p');
    var confirmButton = document.querySelector('[data-purchase-receipt-confirm]');
    var printLast = document.querySelector('[data-purchase-receipt-print-last]');
    state.receivedPrintPage = !!on;
    if (builder) builder.classList.toggle('is-received-print-page', !!on);
    if (!on) {
      if (complete) complete.hidden = true;
      setCompleteActionsVisible(false);
      hideReceivedPrintPicker();
      if (formActions) formActions.hidden = false;
      if (confirmButton) {
        confirmButton.hidden = false;
        confirmButton.disabled = false;
      }
      if (printLast) printLast.hidden = !state.lastReceivedPrintLines.length;
      if (status) status.textContent = '新進貨單';
      if (headTitle) headTitle.textContent = '新增完整廠商進貨單';
      if (headHint) headHint.textContent = '像行政出貨單一樣填好單頭與進貨品項；按一次就會建立正式進貨單並增加倉庫數量。';
      if (headEyebrow) headEyebrow.textContent = 'PURCHASE RECEIPT／PENERIMAAN PEMBELIAN';
      return;
    }
    summary = summary || state.lastReceivedSummary || {};
    var receiptNo = text(summary.receiptNo);
    var warehouseLabel = text(summary.warehouseLabel) || '所選倉庫';
    var receivedQty = Number(summary.receivedQty || 0);
    if (complete) {
      var titleEl = complete.querySelector('[data-purchase-receipt-complete-title]');
      var metaEl = complete.querySelector('[data-purchase-receipt-complete-meta]');
      if (titleEl) titleEl.textContent = summary.historical
        ? ('歷史進貨單 ' + (receiptNo || ''))
        : ('進貨單 ' + (receiptNo || '') + ' 已入庫完成');
      if (metaEl) metaEl.textContent = summary.historical
        ? ([warehouseLabel + ' 已入庫 ' + receivedQty + ' 件', text(summary.supplier), text(summary.sourceOrder)].filter(Boolean).join(' · ') + '。這是歷史單，可列印條碼，不會再入庫。')
        : (warehouseLabel + ' 已增加 ' + receivedQty + ' 件庫存。這是列印條碼頁，進貨單已成立。');
      complete.hidden = false;
    }
    if (formActions) formActions.hidden = true;
    if (confirmButton) {
      confirmButton.hidden = true;
      confirmButton.disabled = true;
    }
    if (printLast) printLast.hidden = true;
    if (status) status.textContent = summary.historical ? '歷史進貨單' : '已進貨入庫';
    if (headTitle) headTitle.textContent = summary.historical ? '歷史進貨單／列印條碼' : '列印條碼頁';
    if (headHint) headHint.textContent = summary.historical
      ? '這是已入庫的歷史進貨單。請勾選要印的條碼；不要再按建立進貨單。'
      : '正式進貨單已完成並入庫。請勾選要印的條碼；不要再按建立進貨單。';
    if (headEyebrow) headEyebrow.textContent = summary.historical
      ? 'HISTORY RECEIPT／RIWAYAT PENERIMAAN'
      : 'PRINT BARCODES／CETAK BARCODE';
  }

  function enterReceivedPrintPage(summary, printLines) {
    /* LZ_RECV_PRINT_20260924_3: inbound already succeeded; stay on a print page, not an empty receiving form. */
    summary = summary || {};
    summary.documentId = text(summary.documentId) || text(document.querySelector('[data-purchase-receipt-id]') && document.querySelector('[data-purchase-receipt-id]').value);
    state.lastReceivedSummary = summary;
    state.standaloneLines = [];
    state.standaloneImages = {};
    state.lastReceivedPrintLines = (Array.isArray(printLines) ? printLines : []).slice();
    renderStandaloneLines();
    setReceivedPrintPage(true, summary);
    var pickerOpened = renderReceivedPrintPicker(state.lastReceivedPrintLines);
    setCompleteActionsVisible(!pickerOpened);
    if (summary.historical) {
      standaloneMessage('已開啟歷史進貨單 ' + (text(summary.receiptNo) || '') + '。' + (pickerOpened ? '可看單並勾選條碼列印；不會再入庫。' : '這張歷史單目前沒有可列印條碼。'), 'ok');
    } else {
      standaloneMessage('正式進貨單 ' + (text(summary.receiptNo) || '已建立') + ' 已完成，' + (text(summary.warehouseLabel) || '所選倉庫') + ' 已增加 ' + Number(summary.receivedQty || 0) + ' 件庫存。' + (pickerOpened ? ' 請勾選要列印的條碼與張數。' : ' 本次沒有可列印條碼。'), 'ok');
    }
    return pickerOpened;
  }

  function dismissReceivedPrintPicker() {
    hideReceivedPrintPicker();
    if (!state.receivedPrintPage) return;
    setReceivedPrintPage(true, state.lastReceivedSummary);
    setCompleteActionsVisible(true);
    standaloneMessage('正式進貨單已入庫完成。之後要印條碼可按「繼續列印條碼」，或再開一張新單。', 'ok');
  }

  function reopenReceivedPrintPicker() {
    if (!state.lastReceivedPrintLines.length) {
      standaloneMessage('目前沒有可列印的本次進貨條碼。', 'error');
      return false;
    }
    setReceivedPrintPage(true, state.lastReceivedSummary);
    var opened = renderReceivedPrintPicker(state.lastReceivedPrintLines);
    setCompleteActionsVisible(!opened);
    if (opened) standaloneMessage('請勾選要列印的條碼與張數。', 'ok');
    return opened;
  }

  function renderReceivedPrintPicker(lines) {
    var picker = document.querySelector('[data-purchase-receipt-print-picker]');
    var list = document.querySelector('[data-purchase-receipt-print-list]');
    if (!picker || !list) return false;
    var rows = (Array.isArray(lines) ? lines : []).filter(function (line) { return receivedLineBarcode(line); });
    state.lastReceivedPrintRows = rows;
    if (!rows.length) {
      picker.hidden = true;
      return false;
    }
    list.innerHTML = rows.map(function (line, index) {
      /* LZ_HIST_THUMB_POS_20260924: per-SKU thumb on print picker, not on history document cards. */
      var qty = Math.max(1, Math.min(99, Math.floor(Number(line.qty || line.quantity || 1))));
      var barcode = receivedLineBarcode(line);
      var colorLabel = receivedLineDisplayColor(line);
      var caption = [line.productCode || '', line.productName || '', colorLabel || '', line.size || line.sizeName || ''].filter(Boolean).join('／');
      var sourceIndex = (Array.isArray(lines) ? lines : []).indexOf(line);
      if (sourceIndex < 0) sourceIndex = index;
      return '<div class="purchase-receipt-print-row">' +
        '<input type="checkbox" checked data-purchase-receipt-print-check="' + index + '" aria-label="列印此品項">' +
        receiptThumbButtonHtml(stockDocLineThumb(line), caption, 'purchase-receipt-print-thumb') +
        '<span><b>' + escapeHtml(line.productCode || '') + '</b> ' + escapeHtml(line.productName || line.productCode || barcode) + '</span>' +
        '<em>' + escapeHtml(barcode) + '</em>' +
        '<small>' + escapeHtml((colorLabel || '') + '／' + (line.size || line.sizeName || 'NO SIZE')) + '</small>' +
        '<input type="number" min="1" max="99" step="1" value="' + qty + '" data-purchase-receipt-print-qty="' + index + '" aria-label="列印張數">' +
        '<button type="button" class="danger-button" data-purchase-receipt-delete-line="' + sourceIndex + '" data-delete-sku="' + escapeHtml(line.skuId || line.sku || '') + '" data-delete-barcode="' + escapeHtml(barcode) + '" data-delete-color="' + escapeHtml(colorLabel || line.color || line.colorName || '') + '" data-delete-size="' + escapeHtml(line.size || line.sizeName || '') + '">刪除</button>' +
        '</div>';
    }).join('');
    var selectAll = picker.querySelector('[data-purchase-receipt-print-all]');
    if (selectAll) selectAll.checked = true;
    picker.hidden = false;
    picker.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return true;
  }

  function selectedReceivedPrintLines() {
    var list = document.querySelector('[data-purchase-receipt-print-list]');
    var chosen = [];
    var rows = state.lastReceivedPrintRows || state.lastReceivedPrintLines || [];
    if (!list) return chosen;
    list.querySelectorAll('.purchase-receipt-print-row').forEach(function (row, index) {
      var check = row.querySelector('[data-purchase-receipt-print-check]');
      if (!check || !check.checked) return;
      var source = rows[index] || state.lastReceivedPrintLines[index];
      var qtyInput = row.querySelector('[data-purchase-receipt-print-qty]');
      var qty = Math.max(1, Math.min(99, Math.floor(Number(qtyInput && qtyInput.value || (source && (source.qty || source.quantity)) || 1))));
      var barcode = text(row.querySelector('em') && row.querySelector('em').textContent) || receivedLineBarcode(source);
      if (!source && !barcode) return;
      chosen.push(Object.assign({}, source || {}, { qty: qty, barcode: barcode || receivedLineBarcode(source) }));
    });
    return chosen;
  }

  function resetStandaloneReceipt(options) {
    options = options || {};
    var retainedPrintLines = options.keepLastPrint ? state.lastReceivedPrintLines.slice() : [];
    var retainedPrintRows = options.keepLastPrint ? (state.lastReceivedPrintRows || []).slice() : [];
    var retainedSummary = options.keepLastPrint ? state.lastReceivedSummary : null;
    state.standaloneLines = [];
    state.standaloneImages = {};
    state.lastReceivedPrintLines = retainedPrintLines;
    state.lastReceivedPrintRows = retainedPrintRows;
    state.lastReceivedSummary = retainedSummary;
    if (options.keepLastPrint && retainedPrintLines.length) {
      enterReceivedPrintPage(retainedSummary || {}, retainedPrintLines);
      return;
    }
    setReceivedPrintPage(false);
    var printLast = document.querySelector('[data-purchase-receipt-print-last]');
    if (printLast) printLast.hidden = true;
    hideReceivedPrintPicker();
    var now = new Date();
    var stamp = String(now.getHours()).padStart(2, '0') + String(now.getMinutes()).padStart(2, '0') + String(now.getSeconds()).padStart(2, '0');
    var values = {
      '[data-purchase-receipt-id]': '',
      '[data-purchase-receipt-no]': 'PREC-' + localDate().replace(/-/g, '') + '-' + stamp,
      '[data-purchase-receipt-date]': localDate(),
      '[data-purchase-receipt-supplier]': '',
      '[data-purchase-receipt-platform]': '',
      '[data-purchase-receipt-source-order]': '',
      '[data-purchase-receipt-warehouse]': 'TW',
      '[data-purchase-receipt-operator]': currentOperatorName(),
      '[data-purchase-receipt-freight]': '',
      '[data-purchase-receipt-note]': '',
      '[data-purchase-receipt-sku-search]': '',
      '[data-purchase-receipt-category]': '',
      '[data-purchase-receipt-product-code]': '',
      '[data-purchase-receipt-product-barcode]': ''
    };
    Object.keys(values).forEach(function (selector) { var input = document.querySelector(selector); if (input) input.value = values[selector]; });
    state.selectedStandaloneSku = null;
    var codeInput = document.querySelector('[data-purchase-receipt-product-code]');
    if (codeInput) codeInput.dataset.receiptCodeSource = '';
    ['supplier', 'platform', 'category'].forEach(hideStandaloneReferenceSuggest);
    var productSuggest = document.querySelector('[data-purchase-receipt-product-suggest]');
    if (productSuggest) {
      productSuggest.hidden = true;
      productSuggest.innerHTML = '';
      productSuggest._matches = [];
    }
    var confirmButton = document.querySelector('[data-purchase-receipt-confirm]');
    if (confirmButton) {
      confirmButton.disabled = false;
      confirmButton.hidden = false;
      confirmButton.textContent = '建立正式進貨單並入庫／Buat dokumen & tambah stok';
    }
    renderStandaloneLines();
    standaloneMessage('先填廠商與進貨項目；送出後會同時建立正式進貨單與倉庫庫存。');
    setStandaloneCodeStatus('先輸入分類關鍵字；系統會以該分類最早產品的編號字首，接續未使用的流水號。');
  }

  function clearStandaloneItemEditor() {
    state.selectedStandaloneSku = null;
    ['[data-purchase-receipt-sku-search]', '[data-purchase-receipt-category]', '[data-purchase-receipt-product-code]', '[data-purchase-receipt-product-barcode]'].forEach(function (selector) {
      var input = document.querySelector(selector);
      if (input) input.value = '';
    });
    var codeInput = document.querySelector('[data-purchase-receipt-product-code]');
    if (codeInput) codeInput.dataset.receiptCodeSource = '';
    var suggest = document.querySelector('[data-purchase-receipt-product-suggest]');
    if (suggest) { suggest.hidden = true; suggest.innerHTML = ''; suggest._matches = []; }
    hideStandaloneReferenceSuggest('category');
    setStandaloneCodeStatus('可繼續輸入下一個產品；空白欄位不會展開全部資料。');
  }

  function addStandaloneSkuRecord(sku, source) {
    source = source || {};
    syncStandaloneLinesFromDom();
    state.standaloneLines.push(standaloneLineFromSku(sku || {}, source, null));
    clearStandaloneItemEditor();
    var input = document.querySelector('[data-purchase-receipt-sku-search]');
    if (input) input.focus();
    renderStandaloneLines();
    standaloneMessage('已加入進貨項目，可繼續掃描下一個產品。', 'ok');
  }

  function addStandaloneSku(value) {
    var checkedSkus = checkedStandaloneSkus();
    if (checkedSkus.length) { addStandaloneSkuRecords(checkedSkus); return; }
    var selectedSku = state.selectedStandaloneSku;
    var matches = selectedSku ? [selectedSku] : standaloneSkuMatches(value);
    if (!selectedSku && matches.length > 1) { standaloneMessage('找到多個顏色或尺寸，請勾選要加入的尺寸，再按「加入已勾選尺寸」。', 'error'); renderStandaloneSkuSuggest(value); return; }
    if (!selectedSku && matches.length === 1) selectedSku = matches[0];
    var category = text(document.querySelector('[data-purchase-receipt-category]') && document.querySelector('[data-purchase-receipt-category]').value);
    var productCode = text(document.querySelector('[data-purchase-receipt-product-code]') && document.querySelector('[data-purchase-receipt-product-code]').value);
    var barcode = text(document.querySelector('[data-purchase-receipt-product-barcode]') && document.querySelector('[data-purchase-receipt-product-barcode]').value);
    var productName = text(value);
    if (selectedSku) {
      var product = productById(selectedSku.productId) || {};
      category = category || productCategory(product);
      productCode = productCode || text(product.code || product.productLine || product.id);
      barcode = canonicalInboundBarcode({
        productCode: productCode,
        color: selectedSku.colorName || selectedSku.color,
        size: selectedSku.sizeName || selectedSku.size,
        unitCostTwd: selectedSku.currentCostTwd || selectedSku.cost
      }, selectedSku, product, barcode || text(selectedSku.companyBarcode || selectedSku.barcode || selectedSku.legacyBarcode || selectedSku.mappingCode || selectedSku.id || selectedSku.sku));
      productName = text(product.title || product.name || productCode);
      applyStandaloneSkuSelection(selectedSku, { keepSearch: true });
      addStandaloneSkuRecord(selectedSku, { productName: productName || productCode, category: category, productCode: productCode, sampleBarcode: barcode, taiwanBarcode: barcode });
      return;
    }
    if (looksLikeBarcodeQuery(value)) {
      standaloneMessage('掃到的完整條碼沒有對到現有產品；不會要求先填分類。請改搜產品編號，或先輸入分類再建立新品。', 'error');
      renderStandaloneSkuSuggest(value);
      return;
    }
    if (!category) { standaloneMessage('請先輸入產品分類；分類會決定產品編號的第一個字首與流水號。', 'error'); document.querySelector('[data-purchase-receipt-category]')?.focus(); return; }
    if (!productCode) productCode = generateStandaloneProductCode(true);
    if (!productCode) { standaloneMessage('這個分類目前無法產生產品編號，請先建立分類首筆產品或手動填入產品編號。', 'error'); return; }
    addStandaloneSkuRecord(selectedSku || {}, { productName: productName || productCode, category: category, productCode: productCode, sampleBarcode: barcode, taiwanBarcode: barcode });
    if (!selectedSku) standaloneMessage('新產品已加入本張進貨單；送出前仍需補齊公司條碼、顏色與尺寸。', 'ok');
  }

  function applyFreightToStandalone(value) {
    var entry = resolveFreight(value);
    if (!entry) { standaloneMessage('找不到這個物流單號或集運批次。', 'error'); return; }
    syncStandaloneLinesFromDom();
    var items = freightItemsForEntry(entry);
    var batch = inferredBatch(entry);
    var added = 0;
    items.forEach(function (item) {
      var sku = skuForFreightItem(item);
      if (!sku) {
        var product = productByCode(item.productCode || item.productName) || {};
        sku = { id: '', sku: '', productId: product.id || '', companyBarcode: item.taiwanBarcode || item.sampleBarcode || '', color: item.colorName || item.color || '', size: item.sizeName || item.size || 'NO SIZE', cost: 0 };
      }
      state.standaloneLines.push(standaloneLineFromSku(sku, item, batch));
      added += 1;
    });
    var supplier = document.querySelector('[data-purchase-receipt-supplier]');
    var platform = document.querySelector('[data-purchase-receipt-platform]');
    var freight = document.querySelector('[data-purchase-receipt-freight]');
    if (supplier && batch) supplier.value = text(batch.forwarder || batch.provider);
    if (platform) platform.value = text((entry.batch && entry.batch.platform) || (entry.item && entry.item.platform));
    if (freight) freight.value = entry.kind === 'batch' ? freightValue(entry.batch, 'batch') : freightValue(entry.item, 'item');
    renderStandaloneLines();
    standaloneMessage('已從物流帶入 ' + added + ' 個進貨項目；請核對條碼、顏色、尺寸與實收數量。', added ? 'ok' : 'error');
  }

  function standaloneReceiptPayload(action) {
    syncStandaloneLinesFromDom();
    var documentNo = text(document.querySelector('[data-purchase-receipt-no]') && document.querySelector('[data-purchase-receipt-no]').value);
    var supplier = text(document.querySelector('[data-purchase-receipt-supplier]') && document.querySelector('[data-purchase-receipt-supplier]').value);
    var operatorName = text(document.querySelector('[data-purchase-receipt-operator]') && document.querySelector('[data-purchase-receipt-operator]').value);
    var platform = text(document.querySelector('[data-purchase-receipt-platform]') && document.querySelector('[data-purchase-receipt-platform]').value);
    var warehouse = text(document.querySelector('[data-purchase-receipt-warehouse]') && document.querySelector('[data-purchase-receipt-warehouse]').value) || 'TW';
    var freightValueInput = text(document.querySelector('[data-purchase-receipt-freight]') && document.querySelector('[data-purchase-receipt-freight]').value);
    var entry = resolveFreight(freightValueInput);
    var batch = inferredBatch(entry);
    var item = entry && entry.item || {};
    var entryItems = freightItemsForEntry(entry);
    var lines = state.standaloneLines.map(function (line) {
      var attached = attachExistingCatalogToLine(Object.assign({}, line));
      return { skuId: attached.skuId, productId: attached.productId, productName: attached.productName, category: attached.category, productCode: attached.productCode, barcode: attached.barcode, color: sanitizeInboundColorValue(attached.color, attached) || attached.color, size: attached.size || 'NO SIZE', qty: Math.max(1, Number(attached.qty || 1)), unitCostTwd: Math.max(0, Number(attached.unitCostTwd || 0)), arrivalImage: attached.arrivalImage || '', colorImage: attached.arrivalImage || attached.productImage || '', proofRequired: /拚張|拚A|張張/i.test(platform) };
    });
    if (!documentNo || !supplier || !operatorName) return { error: '請補齊進貨單號、廠商與經手人。' };
    if (!lines.length) return { error: '請至少加入一個進貨項目。' };
    if (action === 'confirm-purchase-receipt' && lines.some(function (line) { return !line.category || !line.productCode || !line.barcode || !line.color || !line.size; })) return { error: '正式入庫前，每個品項都必須補齊產品分類、產品編號、公司條碼、顏色與尺寸。' };
    if (action === 'confirm-purchase-receipt' && lines.some(function (line) { return line.proofRequired && !line.arrivalImage; })) return { error: '拚張／拚A／張張的進貨品項必須上傳到貨照片。' };
    return {
      action: action,
      documentId: text(document.querySelector('[data-purchase-receipt-id]') && document.querySelector('[data-purchase-receipt-id]').value),
      receivingDocument: {
        documentNo: documentNo,
        documentDate: text(document.querySelector('[data-purchase-receipt-date]') && document.querySelector('[data-purchase-receipt-date]').value) || localDate(),
        sourceOrderNo: text(document.querySelector('[data-purchase-receipt-source-order]') && document.querySelector('[data-purchase-receipt-source-order]').value),
        supplier: supplier,
        purchasePlatform: platform,
        operatorName: operatorName,
        arrivalWarehouse: warehouse,
        note: text(document.querySelector('[data-purchase-receipt-note]') && document.querySelector('[data-purchase-receipt-note]').value)
      },
      receiptLines: lines,
      arrivalWarehouse: warehouse,
      receivedBy: operatorName,
      freightTrackingNo: entry && entry.kind === 'item' ? freightValue(entry.item, 'item') : text(batch && (batch.firstTrackingNo || batch.logisticsTrackingNo || batch.id)),
      // One parcel tracking number may contain several product rows.  Claim
      // the individual freight item only when it is the sole row; an explicit
      // batch selection is the only action allowed to claim a whole batch.
      freightItemId: entry && entry.kind === 'item' && entryItems.length === 1 ? text(item.id) : '',
      freightBatchId: entry && entry.kind === 'batch' ? text(batch && batch.id) : '',
      freightForwarder: text(batch && batch.forwarder || item.forwarder || item.progress),
      freightCostMode: text(batch && batch.costMode),
      freightWarehouseTaxType: text(batch && batch.warehouseTaxType),
      freightChargeType: text(batch && batch.chargeType),
      freightAmountTwd: Number(batch && batch.amount || 0),
      freightAllocationTwd: Number(batch && (batch.allocationPerItem || (Number(batch.itemCount || 0) > 0 ? Number(batch.amount || 0) / Number(batch.itemCount) : 0)) || 0)
    };
  }

  function taipeiDay() {
    return new Date().toLocaleString('en-CA', { timeZone: 'Asia/Taipei' }).slice(0, 10);
  }

  function shiftDay(day, amount) {
    var parts = String(day || taipeiDay()).split('-').map(Number);
    if (parts.length !== 3 || parts.some(function (part) { return !Number.isFinite(part); })) return taipeiDay();
    var value = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + Number(amount || 0), 12, 0, 0));
    return value.toISOString().slice(0, 10);
  }

  function stockDocsPanel() {
    return document.querySelector('[data-stock-docs]');
  }

  function stockDocsRange() {
    var today = taipeiDay();
    var mode = text(document.querySelector('[data-stock-docs-date-mode]') && document.querySelector('[data-stock-docs-date-mode]').value) || 'range';
    var fromInput = document.querySelector('[data-stock-docs-date-from]');
    var toInput = document.querySelector('[data-stock-docs-date-to]');
    var from = text(fromInput && fromInput.value) || today;
    var to = mode === 'range' ? (text(toInput && toInput.value) || from) : from;
    if (mode === 'all') return { mode: mode, from: '', to: '' };
    if (to < from) {
      var original = from;
      from = to;
      to = original;
      if (fromInput) fromInput.value = from;
      if (toInput) toInput.value = to;
    }
    return { mode: mode, from: from, to: to };
  }

  function receiptDocumentDate(row) {
    var doc = row && (row.receivingDocument || row) || {};
    return text(doc.documentDate || row.receivedAt || row.createdAt || '').slice(0, 10);
  }

  function warehouseLabel(value) {
    var code = selfUseWarehouseCode(value);
    if (code === 'CN') return '中國倉';
    if (code === 'ID') return '印尼倉';
    return '台灣倉';
  }

  function stockDocsRawQuery() {
    return text(document.querySelector('[data-stock-docs-query]') && document.querySelector('[data-stock-docs-query]').value);
  }

  function receiptMatchesQuery(row, query) {
    if (!query) return true;
    var doc = row.receivingDocument || row;
    var hay = [
      row.id, doc.documentNo, doc.supplier, doc.purchasePlatform, doc.operatorName,
      doc.sourceOrderNo, doc.note, row.freightTrackingNo, row.freightBatchId
    ];
    (Array.isArray(row.receiptLines) ? row.receiptLines : []).forEach(function (line) {
      hay.push(line.productName, line.productCode, line.barcode, line.color, line.colorName, line.size, line.sizeName, line.skuId, line.title);
    });
    return key(hay.join(' ')).indexOf(query) >= 0;
  }

  function filteredInboundReceipts() {
    var range = stockDocsRange();
    var rawQuery = stockDocsRawQuery();
    var query = key(rawQuery);
    var ignoreRange = !!query;
    state.stockDocsSearchBypassedRange = ignoreRange && range.mode !== 'all';
    return state.receipts.filter(function (row) {
      var day = receiptDocumentDate(row);
      if (!ignoreRange && range.from && (!day || day < range.from || day > range.to)) return false;
      return receiptMatchesQuery(row, query);
    });
  }

  function stockDocSafeThumb(url) {
    url = text(url);
    if (!url || url.indexOf('data:image/') === 0) return '';
    return url;
  }

  function stockDocLineThumb(line) {
    line = line || {};
    var direct = stockDocSafeThumb(line.productImage || line.image || line.imageUrl || line.photo);
    if (direct) return direct;
    var product = productById(line.productId) || productByCode(line.productCode || line.catalogProductCode || line.code || line.productName) || {};
    var sku = skuById(line.skuId || line.sku) || {};
    return stockDocSafeThumb(firstProductImage(product, sku, line));
  }

  function stockDocThumbsHtml(urls, fallbackLabel) {
    var images = [];
    var seen = {};
    (urls || []).forEach(function (url) {
      url = stockDocSafeThumb(url);
      if (!url || seen[url]) return;
      seen[url] = true;
      if (images.length < 3) images.push(url);
    });
    if (!images.length) {
      return '<span class="stock-doc-thumbs is-empty"><b>' + escapeHtml(String(fallbackLabel || '?').replace(/[^0-9A-Za-z\u4e00-\u9fff]/g, '').slice(0, 2) || '?') + '</b></span>';
    }
    return '<span class="stock-doc-thumbs" data-count="' + images.length + '">' + images.map(function (url) {
      return '<img src="' + escapeHtml(url) + '" alt="" onerror="this.remove()">';
    }).join('') + '</span>';
  }

  function stockDocItemsSummary(lines) {
    var groups = [];
    var indexByToken = {};
    (lines || []).forEach(function (line) {
      var name = text(line.productName || line.title || line.name || line.productCode || line.code || '');
      var color = text(line.colorName || line.color || '');
      var size = text(line.sizeName || line.size || '');
      if (size && /^no[\s-]*size$/i.test(size)) size = '';
      var qty = Math.max(1, Number(line.qty || line.quantity || line.requestedQty || 1));
      var token = [name, color, size].filter(Boolean).join(' ');
      if (!token) return;
      if (indexByToken[token] == null) {
        indexByToken[token] = groups.length;
        groups.push({ token: token, qty: 0 });
      }
      groups[indexByToken[token]].qty += qty;
    });
    if (!groups.length) return '未填品名';
    var extra = groups.length > 3 ? ' 等' + groups.length + '款' : '';
    return groups.slice(0, 3).map(function (row) { return row.token + '×' + row.qty; }).join(' · ') + extra;
  }

  function syncStockDocsSearchNote(count, kindLabel) {
    var note = document.querySelector('[data-stock-docs-search-note]');
    if (!note) return;
    var query = stockDocsRawQuery();
    if (query && state.stockDocsSearchBypassedRange && count) {
      note.hidden = false;
      note.textContent = '搜尋「' + query + '」已跨全部日期，找到 ' + count + ' 張' + (kindLabel || '單據') + '；每列是訂單編號、購買日與品名。點列看單／印條碼。';
      return;
    }
    if (query && count) {
      note.hidden = false;
      note.textContent = '符合「' + query + '」共 ' + count + ' 張；點列可看歷史單與印條碼。';
      return;
    }
    note.hidden = true;
    note.textContent = '';
  }

  function isSelfUseInboundReceipt(row) {
    /* LZ_STOCK_HIST_20260924: 自用 only when the receipt itself is internal-use, never as inbound default. */
    row = row || {};
    var doc = row.receivingDocument || {};
    var blob = key([
      row.stockPurpose, row.goodsPurpose, row.directStockPurposeLabel, row.kind, row.type,
      doc.stockPurpose, doc.goodsPurpose, doc.note, row.note
    ].join(' '));
    if (blob.indexOf('自用') >= 0 || blob.indexOf('internaluse') >= 0 || blob.indexOf('selfuse') >= 0) return true;
    var lines = Array.isArray(row.receiptLines) ? row.receiptLines : [];
    if (!lines.length) return false;
    var internal = 0;
    var sellable = 0;
    lines.forEach(function (line) {
      var qty = Math.max(0, Number(line.qty || line.quantity || 0));
      var use = Math.max(0, Number(line.internalUseQty || 0));
      internal += use;
      sellable += line.sellableQty != null ? Math.max(0, Number(line.sellableQty)) : Math.max(0, qty - use);
    });
    return internal > 0 && sellable <= 0;
  }

  function inboundArticleHtml(row) {
    /* LZ_STOCK_HIST_20260924 + LZ_HIST_THUMB_POS_20260924: history is 看單/印條碼, no document-level product thumb. */
    var doc = row.receivingDocument || row;
    var receiptLines = Array.isArray(row.receiptLines) ? row.receiptLines : [];
    var id = text(row.id || '');
    var orderNo = text(doc.sourceOrderNo);
    var docNo = text(doc.documentNo || row.id);
    var title = orderNo || docNo || '-';
    var meta = [receiptDocumentDate(row) || '-', orderNo && docNo ? ('進貨 ' + docNo) : '', doc.supplier || '', warehouseLabel(doc.arrivalWarehouse)].filter(Boolean).join(' · ');
    var actionHtml;
    if (row.status === 'draft') {
      actionHtml = '<div class="stock-doc-actions"><button type="button" class="ghost-button" data-purchase-receipt-edit="' + escapeHtml(id) + '">帶入</button></div>';
    } else {
      actionHtml = '<div class="stock-doc-actions">' +
        '<button type="button" class="ghost-button" data-purchase-receipt-open="' + escapeHtml(id) + '">看單</button>' +
        '<button type="button" class="primary-button" data-purchase-receipt-print-doc="' + escapeHtml(id) + '">印條碼</button>' +
        (isSelfUseInboundReceipt(row) ? '<span class="purchase-receipt-self-use is-badge">自用</span>' : '') +
        '</div>';
    }
    return '<article class="stock-doc-row is-inbound is-compact" data-purchase-receipt-open-row="' + escapeHtml(id) + '">' +
      '<div class="stock-doc-main"><b>' + escapeHtml(title) + '</b><span>' + escapeHtml(meta) + '</span><p>' + escapeHtml(stockDocItemsSummary(receiptLines)) + '</p></div>' +
      '<strong class="' + (row.status === 'received' ? 'is-received' : '') + '">' + (row.status === 'received' ? '已入庫' : '待驗收') + ' ' + Number(row.totalQty || 0) + '件</strong>' +
      actionHtml + '</article>';
  }

  function receiptById(id) {
    id = text(id);
    if (!id) return null;
    return state.receipts.find(function (row) {
      var doc = row && row.receivingDocument || {};
      return text(row.id) === id || text(doc.documentNo) === id || text(doc.sourceOrderNo) === id;
    }) || null;
  }

  function fillHistoricalReceiptHeader(row) {
    var doc = row.receivingDocument || row;
    var values = {
      '[data-purchase-receipt-id]': row.id || '',
      '[data-purchase-receipt-no]': doc.documentNo || '',
      '[data-purchase-receipt-date]': doc.documentDate || receiptDocumentDate(row) || localDate(),
      '[data-purchase-receipt-supplier]': doc.supplier || '',
      '[data-purchase-receipt-platform]': doc.purchasePlatform || '',
      '[data-purchase-receipt-source-order]': doc.sourceOrderNo || '',
      '[data-purchase-receipt-warehouse]': doc.arrivalWarehouse || 'TW',
      '[data-purchase-receipt-operator]': doc.operatorName || currentOperatorName(),
      '[data-purchase-receipt-freight]': row.freightTrackingNo || row.freightBatchId || '',
      '[data-purchase-receipt-note]': doc.note || ''
    };
    Object.keys(values).forEach(function (selector) { var input = document.querySelector(selector); if (input) input.value = values[selector]; });
  }

  function openHistoricalInboundReceipt(row, options) {
    /* LZ_STOCK_HIST_20260924 */
    options = options || {};
    if (!row) {
      standaloneMessage('找不到這張歷史進貨單。', 'error');
      return false;
    }
    var doc = row.receivingDocument || row;
    var printLines = Array.isArray(row.receiptLines) ? row.receiptLines : [];
    fillHistoricalReceiptHeader(row);
    var opened = enterReceivedPrintPage({
      receiptNo: text(doc.documentNo || row.id || ''),
      warehouseLabel: warehouseDisplayName(doc.arrivalWarehouse || '') || warehouseLabel(doc.arrivalWarehouse),
      receivedQty: Number(row.totalQty || 0),
      supplier: text(doc.supplier),
      sourceOrder: text(doc.sourceOrderNo),
      documentId: text(row.id || ''),
      historical: true
    }, printLines);
    if (options.focusPrint && !opened) {
      standaloneMessage('這張歷史進貨單沒有可列印條碼。', 'error');
      return false;
    }
    document.querySelector('[data-purchase-receipt-builder]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return true;
  }

  function outboundArticleHtml(row) {
    var customer = row.customer && typeof row.customer === 'object' ? row.customer : {};
    var id = text(row.id || row.orderId);
    var day = text(row.orderDate || row.purchasedAt || row.date || row.createdAt).slice(0, 10);
    var name = text(row.name || customer.name || row.customerName) || '未填客戶';
    var status = text(row.statusLabel || row.status) || '出貨單';
    var items = Array.isArray(row.items) ? row.items : [];
    return '<article class="stock-doc-row is-outbound is-compact">' +
      '<div class="stock-doc-main"><b>' + escapeHtml(id || '-') + '</b><span>' + escapeHtml([day || '-', name].filter(Boolean).join(' · ')) + '</span><p>' + escapeHtml(stockDocItemsSummary(items)) + '</p></div>' +
      '<strong>' + escapeHtml(status) + '</strong>' +
      '<a class="ghost-button" href="./admin-order-tracking.html?q=' + encodeURIComponent(id) + '">開啟</a></article>';
  }

  function currentSessionToken() {
    try {
      var login = JSON.parse(localStorage.getItem('lingzanzan-v1-admin-login') || '{}');
      return text(login.sessionToken);
    } catch (error) { return ''; }
  }

  function syncStockDocsDateControls() {
    var modeEl = document.querySelector('[data-stock-docs-date-mode]');
    var from = document.querySelector('[data-stock-docs-date-from]');
    var to = document.querySelector('[data-stock-docs-date-to]');
    var today = taipeiDay();
    var mode = text(modeEl && modeEl.value) || 'range';
    if (from && !from.value) from.value = shiftDay(today, -6);
    if (to && !to.value) to.value = today;
    var disableDates = mode === 'all';
    if (from) from.disabled = disableDates;
    if (to) to.disabled = disableDates || mode === 'day';
    if (mode === 'day' && from && to) to.value = from.value;
    document.querySelectorAll('[data-stock-docs-quick]').forEach(function (button) {
      var wanted = button.getAttribute('data-stock-docs-quick');
      var active = (wanted === 'all' && mode === 'all')
        || (wanted === '7days' && mode === 'range' && from && to && from.value === shiftDay(today, -6) && to.value === today)
        || (wanted === 'today' && mode === 'day' && from && from.value === today)
        || (wanted === 'yesterday' && mode === 'day' && from && from.value === shiftDay(today, -1));
      button.classList.toggle('is-active', !!active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function applyStockDocsQuick(value) {
    var modeEl = document.querySelector('[data-stock-docs-date-mode]');
    var from = document.querySelector('[data-stock-docs-date-from]');
    var to = document.querySelector('[data-stock-docs-date-to]');
    var today = taipeiDay();
    if (value === 'yesterday') {
      if (modeEl) modeEl.value = 'day';
      if (from) from.value = shiftDay(today, -1);
      if (to) to.value = shiftDay(today, -1);
    } else if (value === '7days') {
      if (modeEl) modeEl.value = 'range';
      if (from) from.value = shiftDay(today, -6);
      if (to) to.value = today;
    } else if (value === 'all') {
      if (modeEl) modeEl.value = 'all';
    } else {
      if (modeEl) modeEl.value = 'day';
      if (from) from.value = today;
      if (to) to.value = today;
    }
    state.stockDocsPage = 1;
    syncStockDocsDateControls();
    renderReceiptHistory();
  }

  function setStockDocsType(type) {
    type = type === 'out' || type === 'all' ? type : 'in';
    state.stockDocsType = type;
    state.stockDocsPage = 1;
    document.querySelectorAll('[data-stock-docs-type]').forEach(function (button) {
      var on = button.getAttribute('data-stock-docs-type') === type;
      button.classList.toggle('is-active', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    renderReceiptHistory();
  }

  function stockDocsPagerHtml(page, pages, total, inboundCount, outboundCount) {
    var typeLabel = state.stockDocsType === 'out' ? '出貨單' : (state.stockDocsType === 'all' ? '進出貨單' : '進貨單');
    var extra = state.stockDocsType === 'all' ? '（進貨 ' + inboundCount + '／出貨 ' + outboundCount + '）' : '';
    return '<span>第 ' + page + '／' + pages + ' 頁 · 共 ' + total + ' 張' + typeLabel + extra + ' · 每頁 ' + STOCK_DOCS_PAGE_SIZE + ' 張</span><div>' +
      '<button type="button" data-stock-docs-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>上一頁</button>' +
      '<button type="button" data-stock-docs-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>下一頁</button></div>';
  }

  function outboundQueryUrl(page) {
    var range = stockDocsRange();
    var query = stockDocsRawQuery();
    var params = new URLSearchParams();
    if (query) params.set('q', query);
    else params.set('browse', '1');
    params.set('limit', String(STOCK_DOCS_PAGE_SIZE));
    params.set('page', String(page || 1));
    if (!query) {
      if (range.from) params.set('from', range.from);
      if (range.to) params.set('to', range.to);
    }
    params.set('_', String(Date.now()));
    return './admin-order-search-api.php?' + params.toString();
  }

  function loadOutboundDocuments(page) {
    var token = currentSessionToken();
    var headers = token ? { Authorization: 'Bearer ' + token, 'X-Lingzanzan-Admin-Session': token } : {};
    return fetch(outboundQueryUrl(page), { cache: 'no-store', headers: headers, credentials: 'same-origin' }).then(function (response) {
      return response.json().then(function (payload) {
        if (!response.ok || (payload && payload.ok === false)) throw new Error((payload && payload.error) || ('HTTP ' + response.status));
        return payload;
      });
    }).then(function (payload) {
      state.outboundRows = Array.isArray(payload.orders) ? payload.orders : [];
      state.outboundTotal = Number(payload.count || state.outboundRows.length || 0);
      state.outboundError = '';
    }).catch(function (error) {
      state.outboundRows = [];
      state.outboundTotal = 0;
      state.outboundError = error.message || '出貨單讀取失敗';
    });
  }

  function renderReceiptHistory() {
    var host = document.querySelector('[data-stock-docs-list]') || document.querySelector('[data-purchase-receipt-history-list]');
    var summary = document.querySelector('[data-stock-docs-summary]') || document.querySelector('[data-purchase-receipt-history] header strong');
    var pager = document.querySelector('[data-stock-docs-pager]');
    syncStockDocsDateControls();
    var inbound = filteredInboundReceipts();
    var type = state.stockDocsType || 'in';
    if (type === 'in') {
      var pages = Math.max(1, Math.ceil(inbound.length / STOCK_DOCS_PAGE_SIZE));
      state.stockDocsPage = Math.min(Math.max(1, state.stockDocsPage || 1), pages);
      var slice = inbound.slice((state.stockDocsPage - 1) * STOCK_DOCS_PAGE_SIZE, state.stockDocsPage * STOCK_DOCS_PAGE_SIZE);
      if (summary) summary.textContent = inbound.length + ' 張進貨單';
      syncStockDocsSearchNote(inbound.length, '進貨單');
      if (host) {
        var inboundEmpty = stockDocsRawQuery()
          ? '找不到「' + stockDocsRawQuery() + '」的進貨單。'
          : '這個日期區間沒有進貨單。可改選「近 7 天」或「全部日期」。';
        host.innerHTML = slice.length ? slice.map(inboundArticleHtml).join('') : '<p class="empty-state">' + escapeHtml(inboundEmpty) + '</p>';
      }
      if (pager) pager.innerHTML = inbound.length ? stockDocsPagerHtml(state.stockDocsPage, pages, inbound.length, inbound.length, 0) : '';
      return;
    }
    if (summary) summary.textContent = type === 'out' ? '讀取出貨單…' : '讀取進出貨單…';
    if (host) host.innerHTML = '<p class="empty-state">正在讀取出貨單…</p>';
    var requestedPage = state.stockDocsPage || 1;
    loadOutboundDocuments(requestedPage).then(function () {
      var outbound = state.outboundRows || [];
      var outboundTotal = Number(state.outboundTotal || outbound.length || 0);
      if (type === 'out') {
        var outPages = Math.max(1, Math.ceil(Math.max(outboundTotal, 1) / STOCK_DOCS_PAGE_SIZE));
        if (summary) summary.textContent = state.outboundError ? ('出貨單：' + state.outboundError) : (outboundTotal + ' 張出貨單');
        state.stockDocsSearchBypassedRange = !!stockDocsRawQuery() && stockDocsRange().mode !== 'all';
        syncStockDocsSearchNote(state.outboundError ? 0 : outboundTotal, '出貨單');
        if (host) host.innerHTML = state.outboundError
          ? '<p class="empty-state">出貨單讀取失敗：' + escapeHtml(state.outboundError) + '</p>'
          : (outbound.length ? outbound.map(outboundArticleHtml).join('') : '<p class="empty-state">' + escapeHtml(stockDocsRawQuery() ? ('找不到「' + stockDocsRawQuery() + '」的出貨單。') : '這個日期區間沒有出貨單。') + '</p>');
        if (pager) pager.innerHTML = outboundTotal && !state.outboundError ? stockDocsPagerHtml(requestedPage, outPages, outboundTotal, 0, outboundTotal) : '';
        return;
      }
      var combined = inbound.map(function (row) {
        return { kind: 'in', sort: receiptDocumentDate(row) + 'T23:59:59', row: row };
      }).concat(outbound.map(function (row) {
        return { kind: 'out', sort: text(row.date || row.orderDate || row.createdAt), row: row };
      })).sort(function (a, b) { return String(b.sort).localeCompare(String(a.sort)); });
      var total = inbound.length + outboundTotal;
      var pages = Math.max(1, Math.ceil(combined.length / STOCK_DOCS_PAGE_SIZE));
      state.stockDocsPage = Math.min(Math.max(1, requestedPage), pages);
      var slice = combined.slice((state.stockDocsPage - 1) * STOCK_DOCS_PAGE_SIZE, state.stockDocsPage * STOCK_DOCS_PAGE_SIZE);
      if (summary) summary.textContent = state.outboundError
        ? (inbound.length + ' 張進貨單；出貨單讀取失敗')
        : (inbound.length + ' 張進貨／' + outboundTotal + ' 張出貨');
      syncStockDocsSearchNote(state.outboundError ? inbound.length : total, '進出貨單');
      if (host) host.innerHTML = slice.length
        ? slice.map(function (item) { return item.kind === 'out' ? outboundArticleHtml(item.row) : inboundArticleHtml(item.row); }).join('')
        : '<p class="empty-state">' + escapeHtml(stockDocsRawQuery() ? ('找不到「' + stockDocsRawQuery() + '」的進出貨單。') : '這個日期區間沒有進出貨單。') + '</p>';
      if (pager) pager.innerHTML = combined.length ? stockDocsPagerHtml(state.stockDocsPage, pages, total, inbound.length, outboundTotal) : '';
    });
  }

  function loadReceiptHistory() {
    return fetchJson('./stock-inquiry-api.php?action=purchase-receipts&_=' + Date.now()).then(function (payload) {
      state.receipts = Array.isArray(payload.receipts) ? payload.receipts : [];
      renderReceiptHistory();
    });
  }

  function stockDocsFold() {
    /* LZ_STOCK_DOCS_FOLD_20260924 */
    return document.querySelector('[data-stock-docs-fold]');
  }

  function syncStockDocsFoldHint() {
    var fold = stockDocsFold();
    var hint = document.querySelector('[data-stock-docs-fold-hint]');
    if (!hint) return;
    hint.textContent = fold && fold.open ? '點此收合清單' : '點此展開查詢';
  }

  function expandStockDocsFold(open) {
    var fold = stockDocsFold();
    if (!fold) return;
    fold.open = open !== false;
    syncStockDocsFoldHint();
  }

  function bindStockDocsControls() {
    var panel = stockDocsPanel();
    if (!panel || panel.dataset.stockDocsBound === '1') return;
    panel.dataset.stockDocsBound = '1';
    var today = taipeiDay();
    var modeEl = document.querySelector('[data-stock-docs-date-mode]');
    var from = document.querySelector('[data-stock-docs-date-from]');
    var to = document.querySelector('[data-stock-docs-date-to]');
    if (modeEl) modeEl.value = 'range';
    if (from) from.value = shiftDay(today, -6);
    if (to) to.value = today;
    syncStockDocsDateControls();
    panel.addEventListener('click', function (event) {
      var typeBtn = event.target.closest('[data-stock-docs-type]');
      if (typeBtn) {
        event.preventDefault();
        setStockDocsType(typeBtn.getAttribute('data-stock-docs-type') || 'in');
        return;
      }
      var quick = event.target.closest('[data-stock-docs-quick]');
      if (quick) {
        event.preventDefault();
        applyStockDocsQuick(quick.getAttribute('data-stock-docs-quick') || 'today');
        return;
      }
      var pageBtn = event.target.closest('[data-stock-docs-page]');
      if (pageBtn && !pageBtn.disabled) {
        event.preventDefault();
        state.stockDocsPage = Math.max(1, Number(pageBtn.getAttribute('data-stock-docs-page') || 1));
        renderReceiptHistory();
      }
    });
    panel.addEventListener('change', function (event) {
      if (event.target.matches('[data-stock-docs-date-mode], [data-stock-docs-date-from], [data-stock-docs-date-to]')) {
        state.stockDocsPage = 1;
        syncStockDocsDateControls();
        renderReceiptHistory();
      }
    });
    var search = panel.querySelector('[data-stock-docs-query]');
    if (search) {
      var timer = 0;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          state.stockDocsPage = 1;
          if (String(search.value || '').trim()) expandStockDocsFold(true);
          renderReceiptHistory();
        }, 180);
      });
    }
    var fold = panel.querySelector('[data-stock-docs-fold]');
    if (fold) {
      fold.addEventListener('toggle', syncStockDocsFoldHint);
      syncStockDocsFoldHint();
    }
  }

  function fillStandaloneFromReceipt(row) {
    if (!row) return;
    setReceivedPrintPage(false);
    var doc = row.receivingDocument || row;
    var values = {
      '[data-purchase-receipt-id]': row.id || '', '[data-purchase-receipt-no]': doc.documentNo || '', '[data-purchase-receipt-date]': doc.documentDate || localDate(), '[data-purchase-receipt-supplier]': doc.supplier || '', '[data-purchase-receipt-platform]': doc.purchasePlatform || '', '[data-purchase-receipt-source-order]': doc.sourceOrderNo || '', '[data-purchase-receipt-warehouse]': doc.arrivalWarehouse || 'TW', '[data-purchase-receipt-operator]': doc.operatorName || currentOperatorName(), '[data-purchase-receipt-freight]': row.freightTrackingNo || row.freightBatchId || '', '[data-purchase-receipt-note]': doc.note || ''
    };
    Object.keys(values).forEach(function (selector) { var input = document.querySelector(selector); if (input) input.value = values[selector]; });
    state.standaloneLines = (row.receiptLines || []).map(function (line) {
      var sku = skuById(line.skuId) || {};
      var product = productById(line.productId || sku.productId) || {};
      return Object.assign({
        key: 'PRL-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8),
        productName: line.productName || product.title || product.name || line.productCode || '',
        category: line.category || productCategory(product),
        productCode: line.productCode || productCodeForSku(sku),
        productImage: firstProductImage(product, sku, line)
      }, line);
    });
    renderStandaloneLines();
    var status = document.querySelector('[data-purchase-receipt-status]'); if (status) status.textContent = '待驗收進貨單編輯中';
    var confirmButton = document.querySelector('[data-purchase-receipt-confirm]');
    if (confirmButton) {
      confirmButton.disabled = false;
      confirmButton.textContent = '建立正式進貨單並入庫／Buat dokumen & tambah stok';
    }
    standaloneMessage('已帶入舊待驗收單；修改後按一次即可建立正式進貨單並增加庫存，不必再另存草稿。', 'ok');
    document.querySelector('[data-purchase-receipt-builder]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function saveStandaloneReceipt(action, button) {
    if (state.receivedPrintPage && action === 'confirm-purchase-receipt') {
      standaloneMessage('這張進貨單已經入庫完成，請列印條碼或再開一張新單。', 'ok');
      return;
    }
    var payload = standaloneReceiptPayload(action);
    if (payload.error) { standaloneMessage(payload.error, 'error'); return; }
    var original = button.textContent;
    button.disabled = true;
    button.textContent = action === 'confirm-purchase-receipt' ? '建立正式進貨單並入庫中…／Sedang diproses…' : '儲存舊草稿中…';
    postJson(payload).then(function (result) {
      if (result.receipt) {
        var existing = state.receipts.findIndex(function (row) { return text(row.id) === text(result.receipt.id); });
        if (existing >= 0) state.receipts[existing] = result.receipt; else state.receipts.unshift(result.receipt);
        var id = document.querySelector('[data-purchase-receipt-id]'); if (id) id.value = result.receipt.id || '';
      }
      renderReceiptHistory();
      var receipt = result.receipt || {};
      var receiptDocument = receipt.receivingDocument || {};
      var inventory = result.inventory || {};
      var receiptNo = text(receiptDocument.documentNo || receipt.id || '');
      var warehouseLabel = warehouseDisplayName(inventory.warehouse || receiptDocument.arrivalWarehouse || '');
      var receivedQty = Number(inventory.totalQty || receipt.totalQty || 0);
      standaloneMessage(action === 'confirm-purchase-receipt'
        ? '正式進貨單 ' + (receiptNo || '已建立') + ' 已完成，' + (warehouseLabel || '所選倉庫') + ' 已增加 ' + receivedQty + ' 件庫存。'
        : '舊待驗收進貨單已保存到 NAS，尚未增加庫存。', 'ok');
      var status = document.querySelector('[data-purchase-receipt-status]'); if (status) status.textContent = action === 'confirm-purchase-receipt' ? '已進貨入庫' : '待驗收單已保存';
      button.textContent = action === 'confirm-purchase-receipt' ? '正式進貨單與庫存已完成／Selesai' : '舊待驗收單已儲存';
      if (action !== 'confirm-purchase-receipt') button.disabled = false;
      if (action === 'confirm-purchase-receipt') {
        var printLines = Array.isArray(receipt.receiptLines) && receipt.receiptLines.length ? receipt.receiptLines : payload.receiptLines;
        enterReceivedPrintPage({ receiptNo: receiptNo, warehouseLabel: warehouseLabel, receivedQty: receivedQty, documentId: text(receipt.id || '') }, printLines);
      }
    }).catch(function (error) {
      standaloneMessage('儲存失敗：' + (error.message || error), 'error');
      button.disabled = false;
      button.textContent = original;
    });
  }

  function receiptDocument(order) {
    var saved = order && order.receivingDocument && typeof order.receivingDocument === 'object' ? order.receivingDocument : {};
    var id = text(order && order.id);
    var suffix = id.replace(/[^a-z0-9]/gi, '').slice(-8).toUpperCase() || String(Date.now()).slice(-8);
    return {
      documentNo: text(saved.documentNo) || ('REC-' + localDate().replace(/-/g, '') + '-' + suffix),
      documentDate: text(saved.documentDate) || localDate(),
      sourceOrderNo: text(saved.sourceOrderNo) || id,
      supplier: text(saved.supplier) || text(order && order.supplierCarrier),
      purchasePlatform: text(saved.purchasePlatform) || text(order && (order.purchasePlatform || order.platform)),
      operatorName: text(saved.operatorName) || text(order && order.preorderReceivedBy) || '管理者',
      arrivalWarehouse: text(saved.arrivalWarehouse) || text(order && (order.arrivalWarehouse || order.preorderWarehouse)) || 'TW',
      note: text(saved.note) || text(order && order.purchaseNote)
    };
  }

  function receiptDocumentHtml(order) {
    var draft = receiptDocument(order);
    return [
      '<section class="inventory-receiving-document" data-inventory-receiving-document>',
      '<div class="inventory-receiving-document-head"><div><p>PURCHASE RECEIPT</p><h3>完整採購進貨單</h3><span>核對單頭、條碼、顏色、尺寸與數量後，一次建立正式進貨單並增加庫存。</span></div><strong>來源採購單 ' + escapeHtml(draft.sourceOrderNo) + '</strong></div>',
      '<div class="inventory-receiving-document-grid">',
      '<label>進貨單號<input value="' + escapeHtml(draft.documentNo) + '" data-receipt-document-no></label>',
      '<label>進貨日期<input type="date" value="' + escapeHtml(draft.documentDate) + '" data-receipt-document-date></label>',
      '<label>來源採購單<input value="' + escapeHtml(draft.sourceOrderNo) + '" readonly data-receipt-source-order></label>',
      '<label>採購平台<input value="' + escapeHtml(draft.purchasePlatform) + '" data-receipt-purchase-platform placeholder="例如：拚直、拼多多"></label>',
      '<label>供應商／集運商<input value="' + escapeHtml(draft.supplier) + '" data-receipt-supplier placeholder="例如：豪鴻、拼多多集運"></label>',
      '<label>入庫倉<select data-receipt-warehouse><option value="TW"' + (draft.arrivalWarehouse === 'TW' ? ' selected' : '') + '>台灣倉</option><option value="CN"' + (draft.arrivalWarehouse === 'CN' ? ' selected' : '') + '>中國倉</option><option value="ID"' + (draft.arrivalWarehouse === 'ID' ? ' selected' : '') + '>印尼倉</option></select></label>',
      '<label>經手人<input value="' + escapeHtml(draft.operatorName) + '" data-receipt-operator placeholder="輸入行政或進貨經手人"></label>',
      '<label class="inventory-receiving-note">進貨備註<input value="' + escapeHtml(draft.note) + '" data-receipt-note placeholder="短少、破損、補件或其他驗收說明"></label>',
      '</div>',
      '</section>'
    ].join('');
  }

  function receiptTotalsHtml(lines) {
    var qty = lines.reduce(function (sum, line) { return sum + Math.max(0, Number(line.qty || 0)); }, 0);
    var total = lines.reduce(function (sum, line) { return sum + Math.max(0, Number(line.qty || 0)) * Math.max(0, Number(line.unitCostTwd || 0)); }, 0);
    return '<div class="inventory-receiving-totals"><span>明細 <b>' + lines.length + ' 項</b></span><span>實收 <b>' + qty + ' 件</b></span><span>入庫成本合計 <b>' + money(total) + '</b></span></div>';
  }

  function renderOrder(host) {
    if (!host || host.dataset.freightEntryRendering === '1') return;
    var id = host.getAttribute('data-inventory-freight-order') || '';
    var order = orderById(id);
    if (!order) { host.innerHTML = '<p class="inventory-freight-message is-error">找不到採購單資料，請重新整理。</p>'; return; }
    var current = savedFreightValue(order) || suggestedFreight(order);
    var entry = resolveFreight(current);
    host.dataset.freightEntryRendering = '1';
    host.innerHTML = [
      receiptDocumentHtml(order),
      '<header><div><b>物流集運與條碼進貨</b><small>採購單 ' + escapeHtml(id) + '</small></div><small>' + (savedFreightValue(order) ? '已儲存物流連結' : (current ? '已找到唯一相符物流，請確認後儲存' : '請選物流資料')) + '</small></header>',
      '<div class="inventory-freight-link-row"><label>物流單號／集運批次<input list="inventory-freight-entry-options" value="' + escapeHtml(current) + '" data-inventory-freight-link autocomplete="off" placeholder="輸入或選擇物流單號、批次號、關稅號"></label></div>',
      '<div data-inventory-freight-preview>' + previewHtml(order, entry) + '</div>',
      '<div class="inventory-receipt-lines" data-inventory-receipt-lines>' + receiptLinesHtml(order, entry) + '</div>',
      '<div data-inventory-receiving-totals>' + receiptTotalsHtml(receiptLines(host)) + '</div>',
      '<div class="inventory-receiving-actions"><button type="button" class="primary-button" data-save-inventory-freight="' + escapeHtml(id) + '">建立正式進貨單並入庫</button></div>',
      '<p class="inventory-freight-message" data-inventory-freight-message>確認單頭、物流、條碼、顏色、尺寸與實收數量後，按一次即可完成單據與庫存。</p>'
    ].join('');
    refreshReceiptTotals(host);
    host.dataset.freightEntryReady = '1';
    host.dataset.freightEntryRendering = '0';
  }

  function renderAllOrders() {
    ensureInventoryOrderHost(requestedInquiryId());
    document.querySelectorAll('[data-inventory-freight-order]').forEach(function (host) {
      if (host.dataset.freightEntryReady !== '1') renderOrder(host);
    });
    focusRequestedOrder();
  }

  function requestedInquiryId() {
    try { return text(new URLSearchParams(location.search).get('inquiryId')); }
    catch (error) { return ''; }
  }

  function ensureInventoryOrderHost(orderId) {
    orderId = text(orderId);
    if (!orderId) return null;
    var selector = '[data-inventory-freight-order="' + CSS.escape(orderId) + '"]';
    var existing = document.querySelector(selector);
    if (existing) return existing;
    var panel = document.querySelector('[data-inventory-requested-order]');
    if (!panel) return null;
    panel.hidden = false;
    panel.querySelectorAll('[data-inventory-freight-order]').forEach(function (row) { row.remove(); });
    var loading = panel.querySelector(':scope > .inventory-freight-message');
    if (loading) loading.hidden = true;
    var host = document.createElement('section');
    host.className = 'inventory-freight-order is-requested-order';
    host.setAttribute('data-inventory-freight-order', orderId);
    host.innerHTML = '<p class="inventory-freight-message">正在帶入指定採購單、物流成本與驗收品項…</p>';
    panel.appendChild(host);
    return host;
  }

  function requestedFreightBatchId() {
    try { return text(new URLSearchParams(location.search).get('freightBatchId')); }
    catch (error) { return ''; }
  }

  function applyRequestedFreightBatch() {
    var id = requestedFreightBatchId();
    if (!id || root.dataset.requestedFreightBatchApplied === '1') return;
    var batch = batchById(id);
    var input = document.querySelector('[data-inventory-freight-global-input]');
    if (!batch || !input) return;
    root.dataset.requestedFreightBatchApplied = '1';
    input.value = freightValue(batch, 'batch');
    renderGlobalFreightResult(input.value);
    var search = document.querySelector('[data-inventory-freight-global-search]');
    if (search && search.scrollIntoView) window.setTimeout(function () {
      search.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 120);
  }

  function focusRequestedOrder() {
    var id = requestedInquiryId();
    if (!id) return;
    var host = document.querySelector('[data-inventory-freight-order="' + CSS.escape(id) + '"]') || ensureInventoryOrderHost(id);
    if (!host) return;
    document.querySelectorAll('[data-inventory-freight-order].is-requested-order').forEach(function (row) { row.classList.remove('is-requested-order'); });
    host.classList.add('is-requested-order');
    if (host.dataset.requestedFocused === '1') return;
    host.dataset.requestedFocused = '1';
    window.setTimeout(function () {
      if (host.scrollIntoView) host.scrollIntoView({ behavior: 'smooth', block: 'start' });
      var input = host.querySelector('[data-inventory-freight-link]');
      if (input) input.focus();
    }, 120);
  }

  function refreshPreview(host) {
    var id = host.getAttribute('data-inventory-freight-order') || '';
    var order = orderById(id);
    var input = host.querySelector('[data-inventory-freight-link]');
    var entry = resolveFreight(input && input.value);
    var preview = host.querySelector('[data-inventory-freight-preview]');
    var lines = host.querySelector('[data-inventory-receipt-lines]');
    if (preview) preview.innerHTML = previewHtml(order || { items: [] }, entry);
    if (lines && order) lines.innerHTML = receiptLinesHtml(order, entry);
    refreshReceiptTotals(host);
  }

  function refreshReceiptTotals(host) {
    var box = host && host.querySelector('[data-inventory-receiving-totals]');
    if (box) box.innerHTML = receiptTotalsHtml(receiptLines(host));
  }

  function receiptLines(host) {
    return Array.from(host.querySelectorAll('[data-inventory-receipt-line]')).map(function (line) {
      return {
        skuId: text(line.querySelector('[data-receipt-sku-id]') && line.querySelector('[data-receipt-sku-id]').value),
        productId: text(line.querySelector('[data-receipt-product-id]') && line.querySelector('[data-receipt-product-id]').value),
        barcode: text(line.querySelector('[data-receipt-barcode]') && line.querySelector('[data-receipt-barcode]').value),
        color: text(line.querySelector('[data-receipt-color]') && line.querySelector('[data-receipt-color]').value),
        size: text(line.querySelector('[data-receipt-size]') && line.querySelector('[data-receipt-size]').value) || 'NO SIZE',
        qty: Math.max(1, Number(line.querySelector('[data-receipt-qty]') && line.querySelector('[data-receipt-qty]').value || 1)),
        unitCostTwd: Math.max(0, Number(line.querySelector('[data-receipt-cost]') && line.querySelector('[data-receipt-cost]').value || 0))
        ,arrivalImage: text(line.querySelector('[data-receipt-arrival-image]') && line.querySelector('[data-receipt-arrival-image]').value)
        ,proofRequired: text(line.querySelector('[data-receipt-proof-required]') && line.querySelector('[data-receipt-proof-required]').value) === '1'
      };
    });
  }

  function payloadFor(id) {
    var host = document.querySelector('[data-inventory-freight-order="' + CSS.escape(id) + '"]');
    if (!host) return {};
    var value = text(host.querySelector('[data-inventory-freight-link]') && host.querySelector('[data-inventory-freight-link]').value);
    var entry = resolveFreight(value);
    if (!value || !entry) return { error: '請先選擇有效的物流單號或集運批次。' };
    var order = orderById(id) || {};
    var lines = receiptLines(host).map(function (line, lineIndex) {
      var sourceItem = Array.isArray(order.items) && order.items[lineIndex] || {};
      var sourceSku = skuById(line.skuId || sourceItem.skuId || sourceItem.sku) || {};
      var sourceProduct = productById(line.productId || sourceItem.productId || sourceSku.productId) || {};
      return Object.assign({}, line, {
        productId: line.productId || text(sourceProduct.id || sourceSku.productId),
        productName: text(sourceProduct.title || sourceProduct.name || sourceItem.title || sourceItem.productName || sourceItem.code),
        category: productCategory(sourceProduct),
        productCode: text(sourceProduct.code || sourceProduct.productLine || sourceItem.code || sourceProduct.id)
      });
    });
    if (!lines.length) return { error: '這張採購單沒有可入庫的產品品項。' };
    if (lines.some(function (line) { return !line.barcode || !line.color || !line.size || line.qty < 1; })) return { error: '請補齊每一項的公司條碼、顏色、尺寸與實收數量。' };
    if (lines.some(function (line) { return line.proofRequired && !line.arrivalImage; })) return { error: '拚張／拚A／張張的到貨品項必須先拍攝或上傳到貨照片。' };
    var batch = inferredBatch(entry);
    var item = entry.item || {};
    var entryItems = freightItemsForEntry(entry);
    var documentDraft = {
      documentNo: text(host.querySelector('[data-receipt-document-no]') && host.querySelector('[data-receipt-document-no]').value),
      documentDate: text(host.querySelector('[data-receipt-document-date]') && host.querySelector('[data-receipt-document-date]').value),
      sourceOrderNo: text(host.querySelector('[data-receipt-source-order]') && host.querySelector('[data-receipt-source-order]').value) || id,
      supplier: text(host.querySelector('[data-receipt-supplier]') && host.querySelector('[data-receipt-supplier]').value),
      purchasePlatform: text(host.querySelector('[data-receipt-purchase-platform]') && host.querySelector('[data-receipt-purchase-platform]').value),
      operatorName: text(host.querySelector('[data-receipt-operator]') && host.querySelector('[data-receipt-operator]').value),
      arrivalWarehouse: text(host.querySelector('[data-receipt-warehouse]') && host.querySelector('[data-receipt-warehouse]').value) || 'TW',
      note: text(host.querySelector('[data-receipt-note]') && host.querySelector('[data-receipt-note]').value),
      status: 'received'
    };
    if (!documentDraft.documentNo || !documentDraft.documentDate || !documentDraft.supplier || !documentDraft.operatorName) return { error: '請補齊進貨單號、進貨日期、廠商與經手人。' };
    return {
      action: 'confirm-purchase-receipt',
      freightTrackingNo: entry.kind === 'item' ? freightValue(item, 'item') : text(batch && (batch.firstTrackingNo || batch.customsNo || batch.id)),
      freightItemId: entry.kind === 'item' && entryItems.length === 1 ? text(item.id) : '',
      freightBatchId: entry.kind === 'batch' ? text(batch && batch.id) : '',
      freightForwarder: text(batch && batch.forwarder || item.forwarder || item.progress),
      freightCostMode: text(batch && batch.costMode),
      freightWarehouseTaxType: text(batch && batch.warehouseTaxType),
      freightChargeType: text(batch && batch.chargeType),
      freightAmountTwd: Number(batch && batch.amount || 0),
      freightAllocationTwd: Number(batch && (batch.allocationPerItem || (Number(batch.itemCount || 0) > 0 ? Number(batch.amount || 0) / Number(batch.itemCount) : 0)) || 0),
      receiptLines: lines,
      arrivalWarehouse: documentDraft.arrivalWarehouse,
      receivedBy: documentDraft.operatorName,
      receivingDocument: documentDraft
    };
  }

  function saveOrder(id, button) {
    var data = payloadFor(id);
    var host = document.querySelector('[data-inventory-freight-order="' + CSS.escape(id) + '"]');
    var message = host && host.querySelector('[data-inventory-freight-message]');
    if (data.error) { if (message) { message.textContent = data.error; message.className = 'inventory-freight-message is-error'; } return; }
    button.disabled = true;
    button.textContent = '建立正式進貨單並入庫中…';
    postJson(data).then(function (result) {
      var receipt = result.receipt || {};
      var receiptDocument = receipt.receivingDocument || {};
      var inventory = result.inventory || {};
      if (message) { message.textContent = '正式進貨單 ' + text(receiptDocument.documentNo || receipt.id || '已建立') + ' 已完成，' + text(inventory.warehouse || receiptDocument.arrivalWarehouse || '所選倉庫') + ' 已增加 ' + Number(inventory.totalQty || receipt.totalQty || 0) + ' 件庫存。'; message.className = 'inventory-freight-message is-ok'; }
      button.textContent = '正式進貨單與庫存已完成';
      loadReceiptHistory();
      var printLines = Array.isArray(receipt.receiptLines) && receipt.receiptLines.length ? receipt.receiptLines : data.receiptLines;
      openReceivedBarcodePrint(printLines);
    }).catch(function (error) {
      if (message) { message.textContent = '儲存失敗：' + (error.message || error); message.className = 'inventory-freight-message is-error'; }
      button.disabled = false;
      button.textContent = '重新建立正式進貨單並入庫';
    });
  }

  root.addEventListener('input', function (event) {
    if (event.target.matches('[data-purchase-receipt-supplier]')) {
      renderStandaloneReferenceSuggest('supplier', event.target.value);
      return;
    }
    if (event.target.matches('[data-purchase-receipt-platform]')) {
      renderStandaloneReferenceSuggest('platform', event.target.value);
      return;
    }
    if (event.target.matches('[data-purchase-receipt-category]')) {
      clearStandaloneSelectedProduct({ keepCategory: true, clearGenerated: true });
      renderStandaloneReferenceSuggest('category', event.target.value);
      generateStandaloneProductCode(false);
      return;
    }
    if (event.target.matches('[data-purchase-receipt-product-code]')) {
      var manualCode = event.target.value;
      clearStandaloneSelectedProduct({ keepCategory: true });
      event.target.value = manualCode;
      event.target.dataset.receiptCodeSource = 'manual';
      setStandaloneCodeStatus(event.target.value ? '產品編號已改為手動輸入；系統不會覆蓋。' : '產品編號已清空，可重新依分類產生。');
      return;
    }
    if (event.target.matches('[data-standalone-line-qty], [data-standalone-line-cost]')) {
      renderStandaloneTotals();
      if (event.target.matches('[data-standalone-line-cost]')) {
        refreshAutomaticVariantBarcode(event.target.closest('[data-purchase-receipt-line]'));
      }
      return;
    }
    if (event.target.matches('[data-standalone-line-color], [data-standalone-line-size], [data-standalone-line-product-code]')) {
      refreshAutomaticVariantBarcode(event.target.closest('[data-purchase-receipt-line]'));
      return;
    }
    if (event.target.matches('[data-standalone-line-category]')) {
      refreshStandaloneSizePicker(event.target.closest('[data-purchase-receipt-line]'));
      return;
    }
    if (event.target.matches('[data-standalone-line-barcode]')) {
      event.target.dataset.barcodeAuto = '0';
      event.target.dataset.barcodeManual = '1';
      var barcodeLine = state.standaloneLines.find(function (row) { return row.key === event.target.closest('[data-purchase-receipt-line]')?.getAttribute('data-purchase-receipt-line'); });
      if (barcodeLine) { barcodeLine.barcode = text(event.target.value); barcodeLine.barcodeAuto = false; barcodeLine.barcodeManualEdited = true; }
      return;
    }
    var host = event.target.closest('[data-inventory-freight-order]');
    if (!host) return;
    if (event.target.matches('[data-inventory-freight-link]')) {
      window.clearTimeout(renderTimer);
      renderTimer = window.setTimeout(function () { refreshPreview(host); }, 120);
      return;
    }
    if (event.target.matches('[data-receipt-qty], [data-receipt-cost]')) refreshReceiptTotals(host);
  });
  root.addEventListener('change', function (event) {
    if (event.target.matches('[data-purchase-receipt-print-all]')) {
      document.querySelectorAll('[data-purchase-receipt-print-check]').forEach(function (box) {
        box.checked = event.target.checked;
      });
      return;
    }
    if (event.target.matches('[data-standalone-line-color-preset]')) {
      var colorHost = event.target.closest('[data-purchase-receipt-line]');
      var colorField = colorHost && colorHost.querySelector('[data-standalone-line-color]');
      if (!colorField) return;
      if (event.target.value === '__custom__') {
        colorField.focus();
        colorField.select();
      } else {
        var picked = sanitizeInboundColorValue(event.target.value, state.standaloneLines.find(function (row) { return row.key === (colorHost && colorHost.getAttribute('data-purchase-receipt-line')); }) || {}, null, null, colorHost && colorHost.querySelector('[data-standalone-line-barcode]') && colorHost.querySelector('[data-standalone-line-barcode]').value);
        colorField.value = picked || event.target.value;
        if (receivedIsPlaceholderColor(event.target.value) && !picked) colorField.value = '';
        refreshAutomaticVariantBarcode(colorHost);
      }
      return;
    }
    if (event.target.matches('[data-standalone-line-size-preset]')) {
      var sizeHost = event.target.closest('[data-purchase-receipt-line]');
      var sizeField = sizeHost && sizeHost.querySelector('[data-standalone-line-size]');
      if (!sizeField) return;
      if (event.target.value === '__custom__') {
        sizeField.focus();
        sizeField.select();
      } else {
        sizeField.value = event.target.value;
        refreshAutomaticVariantBarcode(sizeHost);
      }
      return;
    }
    if (event.target.matches('[data-standalone-line-photo]')) {
      var standaloneHost = event.target.closest('[data-purchase-receipt-line]');
      var standaloneLine = state.standaloneLines.find(function (row) { return row.key === (standaloneHost && standaloneHost.getAttribute('data-purchase-receipt-line')); });
      var standaloneFile = event.target.files && event.target.files[0];
      if (!standaloneHost || !standaloneLine || !standaloneFile) return;
      receiptImageData(standaloneFile).then(function (dataUrl) {
        standaloneLine.arrivalImage = dataUrl;
        var preview = standaloneHost.querySelector('[data-standalone-line-photo-preview]');
        if (preview) preview.innerHTML = '<img src="' + escapeHtml(dataUrl) + '" alt="到貨照片"><b>已上傳</b>';
      });
      return;
    }
    if (event.target.matches('[data-receipt-photo]')) {
      var line = event.target.closest('[data-inventory-receipt-line]');
      var host = event.target.closest('[data-inventory-freight-order]');
      var order = orderById(host && host.getAttribute('data-inventory-freight-order'));
      var index = Number(line && line.getAttribute('data-inventory-receipt-line'));
      var file = event.target.files && event.target.files[0];
      if (!line || !order || !file) return;
      receiptImageData(file).then(function (dataUrl) {
        if (!state.arrivalImages || typeof state.arrivalImages !== 'object') state.arrivalImages = {};
        state.arrivalImages[arrivalKey(order, index)] = dataUrl;
        var hidden = line.querySelector('[data-receipt-arrival-image]');
        var preview = line.querySelector('[data-receipt-photo-preview]');
        if (hidden) hidden.value = dataUrl;
        if (preview) preview.innerHTML = '<img src="' + escapeHtml(dataUrl) + '" alt="到貨照片"><b>已帶入照片，可重新上傳</b>';
      });
      return;
    }
    if (event.target.matches('[data-inventory-freight-link]')) refreshPreview(event.target.closest('[data-inventory-freight-order]'));
    if (event.target.matches('[data-receipt-warehouse]')) {
      var warehouseHost = event.target.closest('[data-inventory-freight-order]');
      var warehouseId = warehouseHost && warehouseHost.getAttribute('data-inventory-freight-order');
      var externalWarehouse = warehouseId && document.querySelector('[data-preorder-fulfill="' + CSS.escape(warehouseId) + '"]');
      if (externalWarehouse) externalWarehouse.value = event.target.value;
    }
  });

  function currentReceivedDocumentId() {
    return text((state.lastReceivedSummary && state.lastReceivedSummary.documentId) || (document.querySelector('[data-purchase-receipt-id]') && document.querySelector('[data-purchase-receipt-id]').value));
  }

  function applyDeletedReceiptResult(result) {
    var receipt = result && result.receipt || null;
    if (receipt) {
      var existing = state.receipts.findIndex(function (row) { return text(row.id) === text(receipt.id); });
      if (existing >= 0) state.receipts[existing] = receipt; else state.receipts.unshift(receipt);
    }
    var printLines = receipt && Array.isArray(receipt.receiptLines) ? receipt.receiptLines.slice() : [];
    state.lastReceivedPrintLines = printLines;
    if (state.lastReceivedSummary) {
      state.lastReceivedSummary.receivedQty = Number(receipt && receipt.totalQty || 0);
      state.lastReceivedSummary.documentId = text(receipt && receipt.id || state.lastReceivedSummary.documentId);
    }
    renderReceiptHistory();
    if (result && result.voided) {
      hideReceivedPrintPicker();
      setCompleteActionsVisible(true);
      if (state.lastReceivedSummary) setReceivedPrintPage(true, state.lastReceivedSummary);
      var status = document.querySelector('[data-purchase-receipt-status]');
      if (status) status.textContent = '進貨單已作廢';
      standaloneMessage(result.message || '已刪除最後一項，整張進貨單已作廢，庫存已扣回。', 'ok');
      return;
    }
    var opened = renderReceivedPrintPicker(printLines);
    setCompleteActionsVisible(!opened);
    if (state.lastReceivedSummary) setReceivedPrintPage(true, state.lastReceivedSummary);
    standaloneMessage(result.message || '已從進貨單刪除此品項，庫存已扣回。', 'ok');
  }

  function deleteReceivedReceiptLine(button) {
    /* LZ_RECV_DEL_20260924: delete inbound SKU from a received/history/print page. Never print or re-inbound. */
    if (!button || button.disabled) return;
    var sourceIndex = Number(button.getAttribute('data-purchase-receipt-delete-line'));
    var lines = state.lastReceivedPrintLines || [];
    var line = lines[sourceIndex] || (state.lastReceivedPrintRows || [])[sourceIndex] || null;
    if (!line) {
      standaloneMessage('找不到要刪除的品項。', 'error');
      return;
    }
    var documentId = currentReceivedDocumentId();
    if (!documentId) {
      standaloneMessage('找不到進貨單號，無法刪除。請重新看單後再試。', 'error');
      return;
    }
    var label = [line.productCode || '', line.productName || '', line.color || line.colorName || '', line.size || line.sizeName || ''].filter(Boolean).join('／');
    var remaining = lines.length;
    var isLast = remaining <= 1;
    var ok = window.confirm(isLast
      ? ('這是這張進貨單的最後一項「' + label + '」。刪除後整張進貨單會作廢，並把已入庫數量從該 SKU 倉庫扣回。若庫存已賣掉、不夠扣回，系統會停止刪除。確定作廢整張進貨單？')
      : ('確定從這張已入庫進貨單刪除「' + label + '」？會把該品項的入庫數量從倉庫庫存扣回。若庫存已賣掉、不夠扣回，系統會停止刪除，品項會留在單上。列印勾選不會被這步影響。'));
    if (!ok) return;
    if (isLast) {
      var okVoid = window.confirm('請再確認：這會作廢整張進貨單並扣回庫存。確定刪除最後一項？');
      if (!okVoid) return;
    }
    var original = button.textContent;
    button.disabled = true;
    button.textContent = '刪除中…';
    postJson({
      action: 'delete-purchase-receipt-line',
      documentId: documentId,
      lineIndex: sourceIndex,
      skuId: text(button.getAttribute('data-delete-sku') || line.skuId || line.sku || ''),
      barcode: text(button.getAttribute('data-delete-barcode') || receivedLineBarcode(line)),
      color: text(button.getAttribute('data-delete-color') || line.color || line.colorName || ''),
      size: text(button.getAttribute('data-delete-size') || line.size || line.sizeName || ''),
      confirmVoid: !!isLast,
      receivedBy: currentOperatorName()
    }).then(function (result) {
      applyDeletedReceiptResult(result || {});
    }).catch(function (error) {
      standaloneMessage('刪除失敗：' + (error && error.message || error) + '。品項仍留在進貨單上。', 'error');
      button.disabled = false;
      button.textContent = original || '刪除';
    });
  }

  function handleReceivedLineDeleteClick(event) {
    var button = event.target.closest('[data-purchase-receipt-delete-line]');
    if (!button) return false;
    event.preventDefault();
    event.stopPropagation();
    if (typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    deleteReceivedReceiptLine(button);
    return true;
  }

  function handleReceivedPrintSelectedClick(event) {
    var printSelected = event.target.closest('[data-purchase-receipt-print-selected]');
    if (!printSelected) return false;
    event.preventDefault();
    event.stopPropagation();
    var chosen = selectedReceivedPrintLines();
    if (!chosen.length) {
      standaloneMessage('請先勾選要列印的條碼。', 'error');
      return true;
    }
    if (!openReceivedBarcodePrint(chosen)) standaloneMessage('目前沒有可列印的本次進貨條碼。', 'error');
    return true;
  }
  document.addEventListener('click', handleReceivedLineDeleteClick, true);
  document.addEventListener('click', handleReceivedPrintSelectedClick, true);

  document.addEventListener('pointerover', function (event) {
    var thumb = receiptThumbZoomTarget(event.target);
    if (!thumb) return;
    showReceiptThumbHover(thumb);
  }, true);

  document.addEventListener('pointerout', function (event) {
    var thumb = receiptThumbZoomTarget(event.target);
    if (!thumb) return;
    var related = event.relatedTarget;
    if (related && (thumb === related || (thumb.contains && thumb.contains(related)))) return;
    hideReceiptThumbHover();
  }, true);

  document.addEventListener('pointerdown', function (event) {
    var thumb = receiptThumbZoomTarget(event.target);
    if (!thumb) return;
    event.stopPropagation();
  }, true);

  document.addEventListener('scroll', hideReceiptThumbHover, true);
  window.addEventListener('blur', hideReceiptThumbHover);

  root.addEventListener('click', function (event) {
    var referencePick = event.target.closest('[data-purchase-receipt-reference-kind]');
    if (referencePick) {
      event.preventDefault();
      var kind = referencePick.getAttribute('data-purchase-receipt-reference-kind');
      var value = referencePick.getAttribute('data-purchase-receipt-reference-value') || '';
      var input = document.querySelector(standaloneReferenceSelectors(kind).input);
      if (input) input.value = value;
      hideStandaloneReferenceSuggest(kind);
      if (kind === 'category') {
        clearStandaloneSelectedProduct({ keepCategory: true, clearGenerated: true });
        generateStandaloneProductCode(false);
      }
      return;
    }
    var generateCode = event.target.closest('[data-purchase-receipt-generate-code]');
    if (generateCode) {
      event.preventDefault();
      clearStandaloneSelectedProduct({ keepCategory: true, clearGenerated: true });
      generateStandaloneProductCode(true);
      return;
    }
    var addChecked = event.target.closest('[data-purchase-receipt-add-checked]');
    if (addChecked) {
      event.preventDefault();
      addStandaloneSkuRecords(checkedStandaloneSkus());
      return;
    }
    var thumbZoom = receiptThumbZoomTarget(event.target);
    if (thumbZoom) {
      event.preventDefault();
      event.stopPropagation();
      openReceiptThumbZoom(thumbZoom.getAttribute('data-receipt-thumb-zoom') || (thumbZoom.currentSrc || thumbZoom.src || ''), thumbZoom.getAttribute('data-receipt-thumb-caption') || thumbZoom.getAttribute('alt') || '');
      return;
    }
    var standaloneSkuPick = event.target.closest('[data-purchase-receipt-sku-pick]');
    if (standaloneSkuPick) {
      if (event.target.closest('[data-purchase-receipt-sku-check]')) return;
      if (event.target.closest('[data-receipt-thumb-zoom]')) return;
      event.preventDefault();
      var box = standaloneSkuPick.querySelector('[data-purchase-receipt-sku-check]');
      if (box) {
        box.checked = !box.checked;
        syncStandaloneSuggestChecks();
      }
      return;
    }
    var globalSubmit = event.target.closest('[data-inventory-freight-global-submit]');
    if (globalSubmit) {
      event.preventDefault();
      var globalInput = document.querySelector('[data-inventory-freight-global-input]');
      renderGlobalFreightResult(globalInput && globalInput.value);
      return;
    }
    var globalApply = event.target.closest('[data-apply-global-freight]');
    if (globalApply) {
      event.preventDefault();
      applyGlobalFreightToOrder(globalApply.getAttribute('data-apply-global-freight') || '', globalApply.getAttribute('data-global-freight-value') || '');
      return;
    }
    var globalDocument = event.target.closest('[data-apply-global-freight-document]');
    if (globalDocument) {
      event.preventDefault();
      applyFreightToStandalone(globalDocument.getAttribute('data-apply-global-freight-document') || '');
      document.querySelector('[data-purchase-receipt-builder]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    var standaloneFreight = event.target.closest('[data-purchase-receipt-apply-freight]');
    if (standaloneFreight) {
      event.preventDefault();
      applyFreightToStandalone(text(document.querySelector('[data-purchase-receipt-freight]') && document.querySelector('[data-purchase-receipt-freight]').value));
      return;
    }
    var standaloneAdd = event.target.closest('[data-purchase-receipt-add-item]');
    if (standaloneAdd) {
      event.preventDefault();
      addStandaloneSku(text(document.querySelector('[data-purchase-receipt-sku-search]') && document.querySelector('[data-purchase-receipt-sku-search]').value));
      return;
    }
    var standaloneVariant = event.target.closest('[data-purchase-receipt-add-variant]');
    if (standaloneVariant) {
      event.preventDefault();
      addStandaloneVariantLine(standaloneVariant.getAttribute('data-purchase-receipt-add-variant') || '');
      return;
    }
    var standaloneRemove = event.target.closest('[data-purchase-receipt-remove-line]');
    if (standaloneRemove) {
      event.preventDefault();
      if (state.receivedPrintPage || event.target.closest('[data-purchase-receipt-delete-line]')) return;
      syncStandaloneLinesFromDom();
      state.standaloneLines = state.standaloneLines.filter(function (line) { return line.key !== standaloneRemove.getAttribute('data-purchase-receipt-remove-line'); });
      renderStandaloneLines();
      standaloneMessage('已從本張進貨單草稿移除該品項；尚未入庫，不會改庫存。', 'ok');
      return;
    }
    var standaloneClear = event.target.closest('[data-purchase-receipt-clear]');
    if (standaloneClear) { event.preventDefault(); resetStandaloneReceipt(); return; }
    var standalonePrint = event.target.closest('[data-purchase-receipt-print-last]');
    if (standalonePrint) {
      event.preventDefault();
      if (state.lastReceivedPrintLines.length) {
        if (state.lastReceivedSummary) setReceivedPrintPage(true, state.lastReceivedSummary);
        reopenReceivedPrintPicker();
      }
      else if (!openReceivedBarcodePrint(state.lastReceivedPrintLines)) standaloneMessage('目前沒有可列印的本次進貨條碼。', 'error');
      return;
    }
    if (handleReceivedLineDeleteClick(event)) return;
    if (handleReceivedPrintSelectedClick(event)) return;
    var printDismiss = event.target.closest('[data-purchase-receipt-print-dismiss]');
    if (printDismiss) {
      event.preventDefault();
      dismissReceivedPrintPicker();
      return;
    }
    var printAgain = event.target.closest('[data-purchase-receipt-print-again]');
    if (printAgain) {
      event.preventDefault();
      reopenReceivedPrintPicker();
      return;
    }
    var startNewReceipt = event.target.closest('[data-purchase-receipt-new]');
    if (startNewReceipt) {
      event.preventDefault();
      resetStandaloneReceipt();
      return;
    }
    var standaloneSave = event.target.closest('[data-purchase-receipt-save]');
    if (standaloneSave) { event.preventDefault(); saveStandaloneReceipt('save-purchase-receipt', standaloneSave); return; }
    var standaloneConfirm = event.target.closest('[data-purchase-receipt-confirm]');
    if (standaloneConfirm) { event.preventDefault(); saveStandaloneReceipt('confirm-purchase-receipt', standaloneConfirm); return; }
    var printDoc = event.target.closest('[data-purchase-receipt-print-doc]');
    if (printDoc) {
      event.preventDefault();
      openHistoricalInboundReceipt(receiptById(printDoc.getAttribute('data-purchase-receipt-print-doc')), { focusPrint: true });
      return;
    }
    var openDoc = event.target.closest('[data-purchase-receipt-open]');
    if (openDoc) {
      event.preventDefault();
      openHistoricalInboundReceipt(receiptById(openDoc.getAttribute('data-purchase-receipt-open')));
      return;
    }
    var openRow = event.target.closest('[data-purchase-receipt-open-row]');
    if (openRow && !event.target.closest('button,a,input,select,textarea,label')) {
      event.preventDefault();
      var historical = receiptById(openRow.getAttribute('data-purchase-receipt-open-row'));
      if (historical && historical.status === 'draft') fillStandaloneFromReceipt(historical);
      else openHistoricalInboundReceipt(historical);
      return;
    }
    var standaloneEdit = event.target.closest('[data-purchase-receipt-edit]');
    if (standaloneEdit) {
      event.preventDefault();
      fillStandaloneFromReceipt(receiptById(standaloneEdit.getAttribute('data-purchase-receipt-edit')) || state.receipts.find(function (row) { return text(row.id) === text(standaloneEdit.getAttribute('data-purchase-receipt-edit')); }) || null);
      return;
    }
    var button = event.target.closest('[data-save-inventory-freight]');
    if (button) {
      event.preventDefault();
      saveOrder(button.getAttribute('data-save-inventory-freight') || '', button);
      return;
    }
  });

  var globalInput = document.querySelector('[data-inventory-freight-global-input]');
  if (globalInput) {
    globalInput.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      renderGlobalFreightResult(globalInput.value);
    });
  }
  root.addEventListener('change', function (event) {
    if (event.target.matches('[data-purchase-receipt-sku-check-all]')) {
      var on = !!event.target.checked;
      document.querySelectorAll('[data-purchase-receipt-sku-check]').forEach(function (input) { input.checked = on; });
      syncStandaloneSuggestChecks();
      return;
    }
    if (event.target.matches('[data-purchase-receipt-sku-check]')) syncStandaloneSuggestChecks();
  });

  var standaloneSkuInput = document.querySelector('[data-purchase-receipt-sku-search]');
  if (standaloneSkuInput) {
    standaloneSkuInput.addEventListener('input', function () {
      clearStandaloneSelectedProduct({ keepSearch: true });
      renderStandaloneSkuSuggest(standaloneSkuInput.value);
    });
    standaloneSkuInput.addEventListener('focus', function () { if (standaloneSkuInput.value) renderStandaloneSkuSuggest(standaloneSkuInput.value); });
    standaloneSkuInput.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      addStandaloneSku(standaloneSkuInput.value);
    });
  }
  ['supplier', 'platform', 'category'].forEach(function (kind) {
    var input = document.querySelector(standaloneReferenceSelectors(kind).input);
    if (!input) return;
    input.addEventListener('focus', function () {
      if (input.value) renderStandaloneReferenceSuggest(kind, input.value);
      else hideStandaloneReferenceSuggest(kind);
    });
  });

  window.LingzanzanInventoryFreightEntry = {
    payload: payloadFor,
    refresh: renderAllOrders,
    enterReceivedPrintPage: enterReceivedPrintPage,
    dismissReceivedPrintPicker: dismissReceivedPrintPicker,
    catalogStatus: function (value) {
      return {
        freightItems: state.freight.items.length,
        products: state.products.length,
        skus: state.skus.length,
        matches: standaloneSkuMatches(value || '').map(function (sku) { return text(sku.companyBarcode || sku.barcode || sku.id || sku.sku); })
      };
    }
  };

  document.addEventListener('lingzanzan:freight-loaded', function (event) {
    state.freight = normalizeFreightData(event.detail);
    mergePreorderFreightCatalog();
    pruneCoveredFreightSkus();
    fillOptions();
    applyRequestedFreightBatch();
    document.querySelectorAll('[data-inventory-freight-order]').forEach(function (host) { host.dataset.freightEntryReady = '0'; });
    renderAllOrders();
  });

  new MutationObserver(function () { renderAllOrders(); }).observe(document.querySelector('[data-admin-orders]') || page, { childList: true, subtree: true });

  resetStandaloneReceipt();
  bindStockDocsControls();

  Promise.all([
    fetchJson('./stock-inquiry-api.php?action=list&role=admin&_=' + Date.now()).then(function (payload) { state.inquiries = Array.isArray(payload.inquiries) ? payload.inquiries : []; }),
    fetchJson('./data/products.json?_=' + Date.now()).then(function (payload) { state.products = Array.isArray(payload) ? payload : []; }),
    fetchJson('./data/skus.json?_=' + Date.now()).then(function (payload) { state.skus = Array.isArray(payload) ? payload : []; }),
    fetchJson('./admin-state-api-v3.php?overview=1&_=' + Date.now()).then(function (payload) {
      var adminState = payload && payload.state || {};
      state.suppliers = Array.isArray(adminState.suppliers) ? adminState.suppliers : [];
      state.platforms = Array.isArray(adminState.purchasePlatforms) ? adminState.purchasePlatforms : [];
      state.categories = Array.isArray(adminState.categories) ? adminState.categories : [];
      state.categoryEnglishNames = adminState.categoryEnglishNames && typeof adminState.categoryEnglishNames === 'object' ? adminState.categoryEnglishNames : {};
    }),
    loadReceiptHistory(),
    fetchJson('./freight-tracking-api.php?_=' + Date.now()).then(function (payload) { state.freight = normalizeFreightData(payload); })
  ]).then(function () {
    mergePreorderFreightCatalog();
    pruneCoveredFreightSkus();
    state.ready = true;
    fillOptions();
    fillStandaloneOptions();
    if (!state.receivedPrintPage && !document.querySelector('[data-purchase-receipt-no]')?.value) resetStandaloneReceipt();
    applyRequestedFreightBatch();
    renderAllOrders();
    var pendingProductSearch = document.querySelector('[data-purchase-receipt-sku-search]');
    if (pendingProductSearch && pendingProductSearch.value) renderStandaloneSkuSuggest(pendingProductSearch.value);
  }).catch(function (error) {
    var summary = document.querySelector('[data-inventory-freight-summary]');
    if (summary) summary.textContent = '物流資料讀取失敗：' + (error.message || error);
    renderAllOrders();
  });
})();
