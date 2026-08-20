(function () {
  var PAGE = 'quotationManager';
  function $id(id) { return document.getElementById(id); }
  function adminOk() { try { return !!isAdmin; } catch (e) { return false; } }
  function canUseQuotation() {
    if (adminOk()) return true;
    try {
      if (typeof checkPagePermission === 'function') return !!checkPagePermission(PAGE);
      if (typeof data === 'object' && data && data.permissions && typeof currentLoginUser !== 'undefined') {
        var pages = data.permissions[currentLoginUser] || [];
        return Array.isArray(pages) && pages.indexOf(PAGE) !== -1;
      }
    } catch (e) {}
    return false;
  }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>'"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c]; }); }
  function jsString(v) { return String(v == null ? '' : v).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/[\r\n]+/g, ' '); }
  function num(v) {
    if (v == null) return 0;
    var text = String(v).replace(/[，,\s$＄元]/g, '').replace(/[０-９]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 65248); });
    var n = Number(text);
    return isNaN(n) ? 0 : n;
  }
  function cleanMoneyInput(el) {
    if (!el) return;
    var n = num(el.value);
    el.value = n ? String(n) : '0';
  }
  function money(v) { return num(v).toLocaleString('zh-TW') + ' 元'; }
  function today() { return new Date().toISOString().slice(0, 10); }
  function dateCode() { return today().replace(/-/g, ''); }
  function nowStamp() { return new Date().toLocaleString('zh-TW', { hour12: false }); }
  function token() { if (window.crypto && crypto.getRandomValues) { var a = new Uint32Array(4); crypto.getRandomValues(a); return Array.from(a).map(function (x) { return x.toString(36); }).join(''); } return String(Date.now()) + Math.random().toString(36).slice(2); }
  var DEFAULT_COMPANY_INFO = '寶輝科技有限公司\n公司電話：039-773280\n公司傳真：039-773669\n聯絡人：郭先生、李先生、曾小姐';
  var DEFAULT_WARRANTY = '以產品明細保固時間為主；施工/安裝屬於工資，不列入保固範圍';
  var DEFAULT_QUOTE_NOTE = [
    '保固以本估價單各品項上列保固時間為主；硬體售出後，保固內容以估價單記載為準；施工/安裝屬於工資，不列入保固範圍。',
    '簽名後雙方視同合約成立；訂金依本單設定比例計算，尾款需依約定期限給付。',
    '估價單生效與訂金規則：本估價單經客戶口頭確認、書面確認，或支付訂金／現金並經本公司確認收款後，即視同同意本估價單內容；本估價單轉為正式出貨／施工依據並生效，雙方應依本單品項、金額、付款、交付、保固及違約條款履行。若未填寫或未實際收取訂金，視同未收訂金。',
    '違約罰金原則：逾期未付尾款、無故取消、拒絕履約或其他違約情形，依本單總額乘以違約罰金比例計算。',
    '若逾期未給付尾款，本公司有權停止施工、停止交付或收回產品，並依雙方約定追究違約責任。',
    '若施工期間因不可抗力、材料供應、現場條件或其他合理因素無法如期完成，雙方應提出原因與證明後協調處理。'
  ].join('\n');
  function depositStatusLabel(status) {
    status = String(status || 'unpaid');
    if (status === 'paid') return '要收訂金（已收）';
    if (status === 'none') return '不收訂金';
    return '要收訂金（未收）';
  }
  function buildDefaultContract(depositPercent, penaltyPercent, depositStatus) {
    var dp = num(depositPercent) || 30;
    var pp = num(penaltyPercent) || 30;
    var ds = String(depositStatus || 'unpaid');
    var payLine = ds === 'none'
      ? '三、付款與生效條件：本單系統設定為不收訂金；若客戶口頭確認、書面確認，或支付訂金／現金並經本公司確認收款後，即視同同意本估價單內容，本估價單轉為正式出貨／施工依據並生效。未填寫或未實際收取訂金者，視同未收訂金；尾款或全額款項仍需依約定期限給付。逾期未依約給付時，本公司得停止施工、停止交付或收回產品。'
      : '三、付款與生效條件：本單訂金比例為產品總額 ' + dp + '%，訂金狀態為「' + depositStatusLabel(ds) + '」；若客戶口頭確認、書面確認，或支付訂金／現金並經本公司確認收款後，即視同同意本估價單內容，本估價單轉為正式出貨／施工依據並生效。尾款需依約定期限給付；尾款未依約定期限給付時，本公司得停止施工、停止交付或收回產品。';
    return [
      '一、本估價單經雙方簽名或用印後，視同雙方確認報價內容、品項規格、保固時間、交付條件與付款方式。',
      '二、產品保固依本估價單各品項上列保固時間為主；未列保固者，依實際產品或原廠保固條件辦理；施工/安裝屬於工資，不列入保固範圍。',
      payLine,
      '四、違約罰金原則：逾期未付尾款、無故取消、拒絕履約或其他違約情形，依本單總額 ' + pp + '% 計算違約賠償，作為本公司請求違約賠償之依據。',
      '五、如任一方未依約履行，應提出合理原因與證明；違約責任依雙方確認之合約內容辦理。'
    ].join('\n');
  }
  var DEFAULT_CONTRACT = buildDefaultContract(30, 30);
  function contractNeedsDefault(text) {
    text = String(text || '').trim();
    return !text || text.indexOf('依本單設定比例') >= 0 || text.indexOf('違約罰金比例') >= 0 || (text.indexOf('訂金比例為產品總額') < 0 && text.indexOf('不收訂金') < 0) || text.indexOf('違約賠償') < 0;
  }
  function contractForQuote(q) {
    var text = q && q.contract;
    if (contractNeedsDefault(text)) return buildDefaultContract(q && q.depositPercent, q && q.penaltyPercent, q && q.depositStatus);
    return text;
  }
  window.refreshQuoteContractDefault = function (force) {
    var el = $id('quoteContract');
    if (!el) return;
    if (force || contractNeedsDefault(el.value)) {
      el.value = buildDefaultContract($id('quoteDepositPercent') && $id('quoteDepositPercent').value, $id('quotePenaltyPercent') && $id('quotePenaltyPercent').value, $id('quoteDepositStatus') && $id('quoteDepositStatus').value);
    }
  };  function ensureStore() {
    if (typeof data !== 'object' || !data) return;
    if (!Array.isArray(data.quotations)) data.quotations = [];
    if (!Array.isArray(data.quoteCustomers)) data.quoteCustomers = [];
    if (!Array.isArray(data.quoteItemHistory)) data.quoteItemHistory = [];
    if (!Array.isArray(data.deliveryOrders)) data.deliveryOrders = [];
    if (!Array.isArray(data.members)) data.members = [];
    if (!data.quoteSettings || typeof data.quoteSettings !== 'object') data.quoteSettings = {};
  }
  function nextQuoteNo() {
    ensureStore();
    var prefix = 'VAL-' + dateCode() + '-';
    var max = 0;
    (data.quotations || []).forEach(function (q) {
      var no = String(q && q.no || '');
      if (no.indexOf(prefix) === 0) {
        var n = parseInt(no.slice(prefix.length), 10);
        if (!isNaN(n) && n > max) max = n;
      }
    });
    return prefix + String(max + 1).padStart(3, '0');
  }
  function quoteUrl(q) { return location.origin + location.pathname.replace(/admin\.php.*$/, '') + 'quote-view.php?token=' + encodeURIComponent(q.token || ''); }
  function nextDeliveryNo() {
    ensureStore();
    var prefix = 'VAL-' + dateCode() + '-';
    var max = 0;
    (data.deliveryOrders || []).forEach(function (o) {
      var no = String(o && o.no || '');
      if (no.indexOf(prefix) === 0) {
        var n = parseInt(no.slice(prefix.length), 10);
        if (!isNaN(n) && n > max) max = n;
      }
    });
    (data.quotations || []).forEach(function (q) {
      var no = String(q && (q.deliveryNo || q.officialNo) || '');
      if (no.indexOf(prefix) === 0) {
        var n = parseInt(no.slice(prefix.length), 10);
        if (!isNaN(n) && n > max) max = n;
      }
    });
    return prefix + String(max + 1).padStart(3, '0');
  }
  function deliveryUrl(o) { return location.origin + location.pathname.replace(/admin\.php.*$/, '') + 'delivery-view.php?token=' + encodeURIComponent(o.token || ''); }
  function syncDeliveryToOneDollar(order, quote) {
      return fetch('quote-delivery-sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ order: order || {}, quote: quote || {} })
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, message: '同步回應格式錯誤' }; });
      }).catch(function (e) {
        return { ok: false, message: e && e.message ? e.message : '同步連線失敗' };
      });
    }
    function findDeliveryByQuoteId(id) {
    ensureStore();
    return (data.deliveryOrders || []).find(function (o) { return String(o.quoteId || '') === String(id); });
  }
  function itemCalc(it) {
    var line = num(it.qty) * num(it.price);
    var mode = it.taxMode || 'none';
    var tax = 0;
    var total = line;
    if (mode === 'external') { tax = Math.round(line * 0.05); total = line + tax; }
    if (mode === 'included') { tax = Math.round(line - (line / 1.05)); total = line; }
    return { line: line, tax: tax, total: total };
  }
  function calc(q) {
    var sub = 0, tax = 0, itemTotal = 0, optionalTotal = 0;
    (q.items || []).forEach(function (it) {
      var c = itemCalc(it);
      if (isOptionalQuoteItem(it)) { optionalTotal += c.total; return; }
      sub += c.line; tax += c.tax; itemTotal += c.total;
    });
    var discount = num(q.discount);
    var shipping = num(q.shipping);
    var total = Math.max(0, itemTotal + shipping - discount);
    var depositStatus = String(q.depositStatus || 'unpaid');
    var depositPercent = depositStatus === 'none' ? 0 : num(q.depositPercent);
    var depositDue = depositPercent > 0 ? Math.round(total * depositPercent / 100) : 0;
    var enteredDeposit = num(q.depositReceived || q.depositPaidAmount || 0);
    var depositPaid = depositStatus === 'paid' ? (enteredDeposit > 0 ? Math.min(enteredDeposit, total) : depositDue) : 0;
    var depositUnpaid = depositStatus === 'none' ? 0 : Math.max(0, depositDue - depositPaid);
    var penaltyPercent = num(q.penaltyPercent || 30);
    var penalty = penaltyPercent > 0 ? Math.round(total * penaltyPercent / 100) : 0;
    return { sub: sub, tax: tax, itemTotal: itemTotal, optionalTotal: optionalTotal, grandTotal: total + optionalTotal, discount: discount, shipping: shipping, total: total, deposit: depositDue, depositDue: depositDue, depositPaid: depositPaid, depositUnpaid: depositUnpaid, depositStatus: depositStatus, balance: Math.max(0, total - depositPaid), penaltyPercent: penaltyPercent, penalty: penalty };
  }
  function customerKey(q) { return [q.customerName || q.customer || '', q.phone || '', q.email || ''].join('|').trim(); }
  function upsertQuoteMember(q, quoteCustomerId) {
    ensureStore();
    var name = String(q.customerName || q.customer || q.title || '').trim();
    var phone = String(q.phone || '').trim();
    var email = String(q.email || '').trim();
    var address = String(q.address || '').trim();
    if (!name && !phone && !email) return;
    var found = data.members.find(function (m) {
      if (phone && String(m.phone || '').trim() === phone) return true;
      if (email && String(m.email || '').trim() === email) return true;
      return name && address && String(m.name || '').trim() === name && String(m.addr || '').trim() === address;
    });
    var payload = {
      id: found && found.id || String(Date.now()),
      name: name,
      phone: phone,
      addr: address,
      email: email,
      title: q.title || '',
      contact: q.contact || '',
      fax: q.fax || '',
      source: 'quotation',
      quoteCustomerId: quoteCustomerId || '',
      depositStatus: q.depositStatus || 'unpaid',
      depositPercent: q.depositPercent || 30,
      depositReceived: q.depositReceived || q.depositPaidAmount || 0,
      penaltyPercent: q.penaltyPercent || 30,
      updatedAt: nowStamp()
    };
    if (found) Object.assign(found, payload);
    else data.members.unshift(payload);
  }
  function upsertCustomer(q) {
    ensureStore();
    var key = customerKey(q);
    if (!key) return;
    var rows = data.quoteCustomers;
    var found = rows.find(function (c) { return customerKey(c) === key || ((c.customerName || c.customer) && (c.customerName || c.customer) === (q.customerName || q.customer)); });
    var payload = {
      id: found && found.id || String(Date.now()),
      customerName: q.customerName || q.customer || '',
      title: q.title || '',
      contact: q.contact || '',
      phone: q.phone || '',
      fax: q.fax || '',
      email: q.email || '',
      address: q.address || '',
      source: 'quotation',
      depositStatus: q.depositStatus || 'unpaid',
      depositPercent: q.depositPercent || 30,
      penaltyPercent: q.penaltyPercent || 30,
      updatedAt: nowStamp()
    };
    if (found) Object.assign(found, payload); else rows.unshift(payload);
    upsertQuoteMember(q, payload.id);
  }
  function syncQuoteCustomersToMembers() {
    ensureStore();
    (data.quoteCustomers || []).forEach(function (c) {
      upsertQuoteMember({
        customerName: c.customerName || c.customer || '',
        customer: c.customerName || c.customer || '',
        title: c.title || '',
        contact: c.contact || '',
        phone: c.phone || '',
        fax: c.fax || '',
        email: c.email || '',
        address: c.address || c.addr || '',
        depositStatus: c.depositStatus || 'unpaid',
        depositPercent: c.depositPercent || 30,
        depositReceived: c.depositReceived || c.depositPaidAmount || 0,
        penaltyPercent: c.penaltyPercent || 30
      }, c.id || '');
    });
  }
  function quoteItemHistoryKey(it) {
    return [it.name || '', it.brand || '', it.spec || '', it.warranty || '', it.taxMode || 'none'].join('|').trim();
  }
  function rememberQuoteItems(items) {
    ensureStore();
    (items || []).forEach(function (it) {
      var payload = {
        id: '',
        name: it.name || '',
        brand: it.brand || '',
        spec: it.spec || '',
        price: num(it.price),
        warranty: it.warranty || '',
        taxMode: it.taxMode || 'none',
        updatedAt: nowStamp()
      };
      if (!payload.name && !payload.brand && !payload.spec) return;
      var key = quoteItemHistoryKey(payload);
      var found = data.quoteItemHistory.find(function (x) { return quoteItemHistoryKey(x) === key; });
      if (found) Object.assign(found, payload, { id: found.id || String(Date.now()) });
      else { payload.id = String(Date.now()) + Math.random().toString(36).slice(2, 7); data.quoteItemHistory.unshift(payload); }
    });
    data.quoteItemHistory = data.quoteItemHistory.slice(0, 200);
  }
  function quoteHistoryTaxLabel(mode) {
    return mode === 'external' ? '外加 5%' : (mode === 'included' ? '內含 5%' : '未稅');
  }
  function parseQuoteHistoryTaxMode(text, oldMode) {
    text = String(text || '').trim();
    if (!text) return oldMode || 'none';
    if (text === 'external' || text.indexOf('外') >= 0) return 'external';
    if (text === 'included' || text.indexOf('內') >= 0 || text.indexOf('含') >= 0) return 'included';
    return 'none';
  }
  var quoteHistoryPage = 1;
  var quoteHistoryLastKw = '';
  var QUOTE_HISTORY_PAGE_SIZE = 10;
  window.goQuoteHistoryPage = function (page) {
    quoteHistoryPage = Math.max(1, Number(page) || 1);
    renderQuoteItemHistory(true);
  };
  window.renderQuoteItemHistory = function (keepPage) {
    ensureStore();
    var box = $id('quoteItemHistoryList');
    if (!box) return;
    var kw = (($id('quoteItemHistorySearch') && $id('quoteItemHistorySearch').value) || '').trim();
    var link = window.baohuiQuoteLink;
    var rows = data.quoteItemHistory.filter(function (it) {
      if (!kw) return true;
      if (link) return link.score(kw, it) > 0;
      return [it.name, it.brand, it.spec, it.warranty, it.price].join(' ').toLowerCase().indexOf(kw.toLowerCase()) !== -1;
    });
    if (kw && link) rows = rows.sort(function (a, b) { return link.score(kw, b) - link.score(kw, a); });
    if (kw !== quoteHistoryLastKw) {
      quoteHistoryPage = 1;
      quoteHistoryLastKw = kw;
    }
    var pages = Math.max(1, Math.ceil(rows.length / QUOTE_HISTORY_PAGE_SIZE));
    quoteHistoryPage = Math.min(pages, Math.max(1, quoteHistoryPage || 1));
    var start = (quoteHistoryPage - 1) * QUOTE_HISTORY_PAGE_SIZE;
    var shown = rows.slice(start, start + QUOTE_HISTORY_PAGE_SIZE);
    if (!rows.length) {
      box.innerHTML = '<div class="text-muted small">目前沒有常用品項紀錄。儲存估價單後會自動記錄，之後可編輯預設價格、保固與稅金模式。</div>';
      return;
    }
    box.innerHTML = '<div class="quote-history-list">' + shown.map(function (it) {
      var taxText = quoteHistoryTaxLabel(it.taxMode || 'none');
      var sid = esc(jsString(it.id));
      return '<details class="quote-history-item">' +
        '<summary><b>' + esc(it.name || '-') + '</b><span>' + money(it.price || 0) + '</span></summary>' +
        '<div class="quote-history-body">' +
          '<div class="small text-muted mt-2">廠牌：' + esc(it.brand || '-') + '｜規格：' + esc(it.spec || '-') + '</div>' +
          '<div class="small text-muted">保固：' + esc(it.warranty || '-') + '｜稅金：' + esc(taxText) + '</div>' +
          '<div class="d-flex gap-2 mt-2 flex-wrap"><button type="button" class="btn btn-sm btn-primary" onclick="insertQuoteHistoryItem(\'' + sid + '\')">帶入</button>' +
          '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="editQuoteItemHistory(\'' + sid + '\')">編輯</button>' +
          '<button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteQuoteItemHistory(\'' + sid + '\')">刪除</button></div>' +
        '</div>' +
      '</details>';
    }).join('') + '</div>' +
      '<div class="quote-history-pager">' +
        '<button type="button" class="btn btn-sm btn-outline-secondary"' + (quoteHistoryPage <= 1 ? ' disabled' : '') + ' onclick="goQuoteHistoryPage(' + (quoteHistoryPage - 1) + ')">上一頁</button>' +
        '<span class="small fw-bold">第 ' + quoteHistoryPage + ' / ' + pages + ' 頁，每頁 ' + QUOTE_HISTORY_PAGE_SIZE + ' 筆；顯示 ' + shown.length + ' / ' + rows.length + ' 筆</span>' +
        '<button type="button" class="btn btn-sm btn-outline-secondary"' + (quoteHistoryPage >= pages ? ' disabled' : '') + ' onclick="goQuoteHistoryPage(' + (quoteHistoryPage + 1) + ')">下一頁</button>' +
      '</div>';
  };
  window.insertQuoteHistoryItem = function (id) {
    ensureStore();
    var it = data.quoteItemHistory.find(function (x) { return String(x.id) === String(id); });
    if (!it) return alert('找不到常用品項明細');
    addQuoteItemRow({ name: it.name || '', brand: it.brand || '', spec: it.spec || '', qty: 1, price: num(it.price), warranty: it.warranty || '', taxMode: it.taxMode || 'none' });
  };
  window.editQuoteItemHistory = function (id) {
    ensureStore();
    var it = data.quoteItemHistory.find(function (x) { return String(x.id) === String(id); });
    if (!it) return alert('找不到常用品項明細');
    var name = prompt('品項名稱：', it.name || ''); if (name === null) return;
    var brand = prompt('廠牌：', it.brand || ''); if (brand === null) return;
    var spec = prompt('規格：', it.spec || ''); if (spec === null) return;
    var price = prompt('預設價格，可留 0：', String(num(it.price))); if (price === null) return;
    var warranty = prompt('保固時間：', it.warranty || ''); if (warranty === null) return;
    var tax = prompt('稅金模式：未稅 / 外加 / 內含', quoteHistoryTaxLabel(it.taxMode || 'none')); if (tax === null) return;
    it.name = String(name || '').trim();
    it.brand = String(brand || '').trim();
    it.spec = String(spec || '').trim();
    it.price = num(price);
    it.warranty = String(warranty || '').trim();
    it.taxMode = parseQuoteHistoryTaxMode(tax, it.taxMode || 'none');
    it.updatedAt = nowStamp();
    if (!it.name && !it.brand && !it.spec) return alert('品項、廠牌、規格至少要留一項');
    if (typeof save === 'function' && !save()) return;
    renderQuoteItemHistory();
  };
  window.deleteQuoteItemHistory = function (id) {
    if (!confirm('確定刪除這筆常用品項明細？既有估價單不會被刪除。')) return;
    ensureStore();
    data.quoteItemHistory = data.quoteItemHistory.filter(function (x) { return String(x.id) !== String(id); });
    if (typeof save === 'function' && !save()) return;
    renderQuoteItemHistory();
  };
  var quoteProductCatalogItems = {};
  window.searchQuoteProductCatalog = function () {
    var input = $id('quoteProductCatalogSearch');
    var box = $id('quoteProductCatalogResults');
    if (!input || !box) return;
    var query = String(input.value || '').trim();
    box.innerHTML = '<div class="text-muted small">正在查詢產品主檔...</div>';
    fetch('api.php?action=product_catalog&q=' + encodeURIComponent(query), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { return response.json().then(function (payload) { return { ok: response.ok, payload: payload }; }); })
      .then(function (result) {
        if (!result.ok || !result.payload || !result.payload.ok) throw new Error((result.payload && result.payload.error) || '產品主檔查詢失敗');
        var items = Array.isArray(result.payload.items) ? result.payload.items : [];
        quoteProductCatalogItems = {};
        items.forEach(function (item) { quoteProductCatalogItems[String(item.id)] = item; });
        if (!items.length) {
          box.innerHTML = '<div class="text-muted small">找不到符合條件的產品。外部自有組裝主機依公司規則不會出現在結果內。</div>';
          return;
        }
        box.innerHTML = items.map(function (item) {
          var price = num(item.sale_price) || num(item.reference_price);
          var source = num(item.reference_price) ? '寶輝科技參考價' : '寶輝產品主檔';
          var checked = item.reference_checked_at ? ('｜查價：' + String(item.reference_checked_at).replace('T', ' ')) : '';
          return '<div class="col-xl-4 col-md-6"><div class="border rounded p-2 h-100 bg-white">' +
            '<div class="fw-semibold">' + esc(item.name || '-') + '</div>' +
            '<div class="small text-muted">' + esc([item.brand, item.category, item.subcategory].filter(Boolean).join(' / ')) + '</div>' +
            '<div class="small mt-1">' + esc(item.spec || '') + '</div>' +
            '<div class="small mt-1"><b>' + esc(price ? money(price) : '價格待確認') + '</b>｜可用庫存 ' + esc(item.stock || 0) + '</div>' +
            '<div class="small text-muted">' + esc(source + checked) + '</div>' +
            '<button type="button" class="btn btn-sm btn-primary mt-2" onclick="insertQuoteCatalogItem(\'' + esc(jsString(item.id)) + '\')">帶入估價單</button>' +
          '</div></div>';
        }).join('');
      })
      .catch(function (error) { box.innerHTML = '<div class="text-danger small">' + esc(error.message || '產品主檔查詢失敗') + '</div>'; });
  };
  window.insertQuoteCatalogItem = function (id) {
    var item = quoteProductCatalogItems[String(id)];
    if (!item) return alert('找不到這筆產品，請重新查詢。');
    addQuoteItemRow({
      name: item.name || '',
      brand: item.brand || '',
      spec: item.spec || [item.category, item.subcategory].filter(Boolean).join(' / '),
      qty: 1,
      price: num(item.sale_price) || num(item.reference_price),
      warranty: '依產品或原廠保固條件辦理',
      taxMode: 'none'
    });
    updateQuotePreviewTotal();
  };
  function isOptionalQuoteItem(it) {
    if (!it) return false;
    if (it.optional) return true;
    return /可加可不加/.test(String(it.spec || '') + String(it.name || ''));
  }
  var quoteComputerServicePresets = {
    hardwareYear1: {
      name: '硬體代送維護費（第一年）', brand: '寶輝科技',
      spec: '一年內非人為故障之硬體代送處理；不含人為損壞、零件費及原廠維修費', qty: 1, price: 800,
      warranty: '一年內非人為處理', taxMode: 'none'
    },
    assembly: {
      name: '電腦組裝費用', brand: '寶輝科技',
      spec: '單次整機組裝；不含作業系統授權及其他軟體安裝', qty: 1, price: 1500,
      warranty: '施工/安裝工資，不列入保固範圍', taxMode: 'none'
    },
    hardwareYear2: {
      name: '第二年硬體維護', brand: '寶輝科技',
      spec: '選購項目，可加可不加', qty: 1, price: 800, optional: true,
      warranty: '第二年硬體維護 800 元（選購）', taxMode: 'none'
    },
    hardwareYear3: {
      name: '第三年硬體維護', brand: '寶輝科技',
      spec: '選購項目，可加可不加', qty: 1, price: 500, optional: true,
      warranty: '第三年硬體維護 500 元（選購）', taxMode: 'none'
    },
    windowsHome: {
      name: 'Microsoft Windows 家用版', brand: 'Microsoft',
      spec: '合法授權版本；含基本作業系統安裝', qty: 1, price: 3500,
      warranty: '依微軟授權與版本條件', taxMode: 'none'
    },
    windowsPro: {
      name: 'Microsoft Windows 專業版', brand: 'Microsoft',
      spec: '合法授權版本；含基本作業系統安裝', qty: 1, price: 4600,
      warranty: '依微軟授權與版本條件', taxMode: 'none'
    },
    softwareInstall: {
      name: '軟體安裝工資', brand: '寶輝科技',
      spec: '單次軟體安裝服務；軟體授權費另計', qty: 1, price: 800,
      warranty: '一次性安裝服務', taxMode: 'none'
    }
  };
  window.insertQuoteComputerService = function (key) {
    var preset = quoteComputerServicePresets[String(key || '')];
    if (!preset) return alert('找不到這個服務品項。');
    addQuoteItemRow(Object.assign({}, preset));
    updateQuotePreviewTotal();
  };
  var QUOTE_BRANDS = ['華碩','技嘉','微星','華擎','映泰','Intel','AMD','NVIDIA','金士頓','威剛','十銓','美光','Samsung','WD','Seagate','Toshiba','東芝','海韻','全漢','安鈦克','酷碼','MONTECH','LIAN LI','NZXT','Corsair','EVGA','ASUS','GIGABYTE','MSI','羅技','Razer'];
  var quoteSuggestState = { input: null, items: [], index: -1, timer: null };
  var quoteCatalogSuggestCache = { __items: [] };
  function quoteLinkApi() { return window.baohuiQuoteLink || null; }
  function quoteLinkPool() {
    ensureStore();
    var history = data.quoteItemHistory || [];
    var quotes = [];
    (data.quotations || []).forEach(function (q) {
      (q.items || []).forEach(function (it) { quotes.push(it); });
    });
    var presets = Object.keys(quoteComputerServicePresets || {}).map(function (k) { return quoteComputerServicePresets[k]; });
    var rows = Array.from(document.querySelectorAll('#quoteItemRows .quote-item-row')).map(function (tr) {
      return {
        name: (tr.querySelector('.quote-item-name') || {}).value || '',
        brand: (tr.querySelector('.quote-item-brand') || {}).value || '',
        spec: (tr.querySelector('.quote-item-spec') || {}).value || ''
      };
    });
    var link = quoteLinkApi();
    var lists = [history, quotes, presets, rows, quoteCatalogSuggestCache.__items];
    return link ? link.mergePool(lists) : history.concat(quotes, presets, rows);
  }
  function quoteBrandList() {
    var brands = QUOTE_BRANDS.slice();
    quoteLinkPool().forEach(function (it) { if (it && it.brand) brands.push(it.brand); });
    return brands;
  }
  function hideQuoteSuggest() {
    document.querySelectorAll('.quote-suggest-row').forEach(function (el) { el.remove(); });
    quoteSuggestState.input = null;
    quoteSuggestState.items = [];
    quoteSuggestState.index = -1;
  }
  function highlightQuoteSuggest() {
    var box = document.querySelector('.quote-suggest');
    if (!box) return;
    box.querySelectorAll('button').forEach(function (btn, i) {
      btn.classList.toggle('is-active', i === quoteSuggestState.index);
      if (i === quoteSuggestState.index) btn.scrollIntoView({ block: 'nearest' });
    });
  }
  function applyQuoteSuggest(input, item) {
    var tr = input && input.closest && input.closest('.quote-item-row');
    if (!tr || !item) return;
    var link = quoteLinkApi();
    var current = {
      name: tr.querySelector('.quote-item-name').value,
      brand: tr.querySelector('.quote-item-brand').value,
      spec: tr.querySelector('.quote-item-spec').value,
      price: tr.querySelector('.quote-item-price').value,
      warranty: tr.querySelector('.quote-item-warranty').value,
      taxMode: tr.querySelector('.quote-item-tax').value
    };
    var filled = link ? link.linkedFill(current, item) : item;
    if (link && !filled.brand) filled.brand = link.guessBrand(filled.name, quoteBrandList());
    if (link) filled.spec = link.cleanSpec(filled.brand, filled.spec);
    tr.querySelector('.quote-item-name').value = filled.name || '';
    tr.querySelector('.quote-item-brand').value = filled.brand || '';
    tr.querySelector('.quote-item-spec').value = filled.spec || '';
    if (num(filled.price) && !num(current.price)) tr.querySelector('.quote-item-price').value = String(num(filled.price));
    hideQuoteSuggest();
    updateQuotePreviewTotal();
  }
  function renderQuoteSuggest(input, items) {
    var prevInput = quoteSuggestState.input;
    document.querySelectorAll('.quote-suggest-row').forEach(function (el) { el.remove(); });
    if (!input || !items || !items.length) {
      quoteSuggestState.input = null;
      quoteSuggestState.items = [];
      quoteSuggestState.index = -1;
      return;
    }
    var tr = input.closest('.quote-item-row');
    if (!tr || !tr.parentNode) return;
    var row = document.createElement('tr');
    row.className = 'quote-suggest-row';
    var box = document.createElement('div');
    box.className = 'quote-suggest';
    box.innerHTML = items.map(function (it, i) {
      var meta = [it.brand, it.spec].filter(Boolean).join(' ／ ');
      return '<button type="button" data-suggest-index="' + i + '"><b>' + esc(it.name || '-') + '</b>' + (meta ? '<small>' + esc(meta) + '</small>' : '') + '</button>';
    }).join('');
    var cell = document.createElement('td');
    cell.colSpan = 9;
    cell.appendChild(box);
    row.appendChild(cell);
    tr.parentNode.insertBefore(row, tr.nextSibling);
    quoteSuggestState.input = input;
    quoteSuggestState.items = items;
    quoteSuggestState.index = prevInput === input ? Math.min(quoteSuggestState.index, items.length - 1) : 0;
    if (quoteSuggestState.index < 0) quoteSuggestState.index = 0;
    box.querySelectorAll('button').forEach(function (btn) {
      btn.addEventListener('mousedown', function (e) {
        e.preventDefault();
        applyQuoteSuggest(input, items[Number(btn.getAttribute('data-suggest-index') || 0)]);
      });
    });
    highlightQuoteSuggest();
  }
  function fetchQuoteCatalogSuggest(q, cb) {
    var key = String(q || '').trim().toLowerCase();
    if (!key) return cb([]);
    if (quoteCatalogSuggestCache[key]) return cb(quoteCatalogSuggestCache[key]);
    fetch('api.php?action=product_catalog&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        var items = ((payload && payload.items) || []).map(function (item) {
          return {
            name: item.name || '',
            brand: item.brand || '',
            spec: item.spec || [item.category, item.subcategory].filter(Boolean).join(' / '),
            price: num(item.sale_price) || num(item.reference_price),
            warranty: '依產品或原廠保固條件辦理'
          };
        });
        quoteCatalogSuggestCache[key] = items;
        quoteCatalogSuggestCache.__items = (quoteCatalogSuggestCache.__items || []).concat(items);
        cb(items);
      })
      .catch(function () { cb([]); });
  }
  function showQuoteSuggest(input) {
    var link = quoteLinkApi();
    if (!link || !input) return;
    var q = String(input.value || '').trim();
    if (q.length < 1) { renderQuoteSuggest(input, []); return; }
    renderQuoteSuggest(input, link.rank(q, quoteLinkPool(), 8));
    clearTimeout(quoteSuggestState.timer);
    if (q.length < 2) return;
    quoteSuggestState.timer = setTimeout(function () {
      fetchQuoteCatalogSuggest(q, function (extra) {
        if (quoteSuggestState.input !== input && document.activeElement !== input) return;
        renderQuoteSuggest(input, link.rank(q, link.mergePool([quoteLinkPool(), extra]), 8));
      });
    }, 280);
  }
  function autoFillLinked(input) {
    var link = quoteLinkApi();
    var tr = input && input.closest && input.closest('.quote-item-row');
    if (!link || !tr) return;
    var nameEl = tr.querySelector('.quote-item-name');
    var brandEl = tr.querySelector('.quote-item-brand');
    var specEl = tr.querySelector('.quote-item-spec');
    var name = (nameEl && nameEl.value) || '';
    if (brandEl && !String(brandEl.value || '').trim()) brandEl.value = link.guessBrand(name, quoteBrandList());
    var query = name || (specEl && specEl.value) || (brandEl && brandEl.value) || '';
    var best = query ? link.rank(query, quoteLinkPool(), 1)[0] : null;
    if (best && link.score(query, best) >= 80) {
      if (brandEl && !String(brandEl.value || '').trim()) brandEl.value = best.brand || brandEl.value;
      if (specEl && (!String(specEl.value || '').trim() || specEl.value === brandEl.value)) specEl.value = link.cleanSpec(brandEl.value, best.spec || specEl.value);
    }
    if (specEl) specEl.value = link.cleanSpec(brandEl.value, specEl.value);
  }
  function bindQuoteItemLink() {
    if (document.body.dataset.quoteItemLinkBound) return;
    document.body.dataset.quoteItemLinkBound = '1';
    document.addEventListener('input', function (e) {
      var input = e.target.closest && e.target.closest('.quote-item-name, .quote-item-brand, .quote-item-spec');
      if (input) showQuoteSuggest(input);
    });
    document.addEventListener('focusin', function (e) {
      var input = e.target.closest && e.target.closest('.quote-item-name, .quote-item-brand, .quote-item-spec');
      if (input && String(input.value || '').trim()) showQuoteSuggest(input);
    });
    document.addEventListener('blur', function (e) {
      var input = e.target.closest && e.target.closest('.quote-item-name, .quote-item-brand, .quote-item-spec');
      if (!input) return;
      setTimeout(function () { autoFillLinked(input); }, 120);
    }, true);
    document.addEventListener('keydown', function (e) {
      if (!quoteSuggestState.input || !document.querySelector('.quote-suggest')) return;
      if (e.key === 'Escape') { hideQuoteSuggest(); return; }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        quoteSuggestState.index = Math.min(quoteSuggestState.items.length - 1, quoteSuggestState.index + 1);
        highlightQuoteSuggest();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        quoteSuggestState.index = Math.max(0, quoteSuggestState.index - 1);
        highlightQuoteSuggest();
      } else if (e.key === 'Enter' && quoteSuggestState.items[quoteSuggestState.index]) {
        e.preventDefault();
        applyQuoteSuggest(quoteSuggestState.input, quoteSuggestState.items[quoteSuggestState.index]);
      }
    });
    if (window.baohuiQuoteBuilder && typeof window.baohuiQuoteBuilder.loadCatalog === 'function') {
      window.baohuiQuoteBuilder.loadCatalog().then(function (catalog) {
        var items = [];
        (catalog.slots || []).concat(catalog.extra || []).forEach(function (slot) {
          (slot.items || []).forEach(function (it) {
            items.push({ name: it.name || '', brand: it.brand || '', spec: it.spec || '', price: it.price || 0 });
          });
        });
        quoteCatalogSuggestCache.__items = items.concat(quoteCatalogSuggestCache.__items || []);
      }).catch(function () {});
    }
  }
  function ensureNav() {
    var sidebar = document.querySelector('.sidebar'); if (!sidebar) return;
    var link = document.querySelector('[data-page="' + PAGE + '"]');
    if (!link) {
      link = document.createElement('a');
      link.className = 'nav-link page-hide';
      link.href = 'javascript:void(0)';
      link.setAttribute('data-page', PAGE);
      link.innerHTML = '<i class="bi bi-receipt-cutoff"></i>估價單管理';
      link.onclick = function () { if (typeof goPage === 'function') goPage(PAGE); };
      var ref = document.querySelector('[data-page="hardwareMarket"]') || document.querySelector('[data-page="salaryReports"]') || document.querySelector('[data-page="codexReport"]');
      if (ref && ref.parentNode) ref.parentNode.insertBefore(link, ref.nextSibling); else sidebar.appendChild(link);
    }
    link.classList.toggle('page-hide', !canUseQuotation());
  }
  function ensurePage() {
    if ($id('page-' + PAGE)) return;
    var main = document.querySelector('.main-content') || $id('mainSystem') || document.body;
    var section = document.createElement('div');
    section.id = 'page-' + PAGE;
    section.className = 'page-content page-hide';
    section.innerHTML = '<style>' +
      '.quote-item-table{table-layout:auto;min-width:1280px}' +
      '.quote-item-table th,.quote-item-table td{vertical-align:middle}' +
      '.quote-item-table .quote-item-qty,.quote-item-table .quote-item-price{min-width:108px;width:100%;font-size:16px;font-weight:600;padding:8px 10px;text-align:right}' +
      '.quote-item-table .quote-item-name,.quote-item-table .quote-item-brand,.quote-item-table .quote-item-spec,.quote-item-table .quote-item-warranty,.quote-item-table .quote-item-tax{font-size:15px}' +
      '.quote-item-table .quote-suggest-row td{background:#f8fafc;padding:8px 10px;border-top:0}' +
      '.quote-suggest{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;max-height:160px;overflow:auto}' +
      '.quote-suggest button{display:block;width:100%;text-align:left;border:1px solid #dbe5f2;background:#fff;border-radius:8px;padding:8px 10px;color:#0f172a}' +
      '.quote-suggest button:hover,.quote-suggest button.is-active{background:#ecfdf5;border-color:#0f766e}' +
      '.quote-suggest b{display:block;font-size:14px}' +
      '.quote-suggest small{display:block;color:#64748b;font-weight:700}' +
      '.quote-history-list{display:grid;gap:8px}' +
      '.quote-history-item{border:1px solid #dbe5f2;border-radius:10px;background:#fff;overflow:hidden}' +
      '.quote-history-item summary{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;list-style:none;padding:10px 12px;font-weight:800}' +
      '.quote-history-item summary::-webkit-details-marker{display:none}' +
      '.quote-history-item summary:after{content:"展開";color:#0f766e;font-size:13px;font-weight:800;flex:0 0 auto}' +
      '.quote-history-item[open] summary:after{content:"收合"}' +
      '.quote-history-item summary b{min-width:0;flex:1 1 auto}' +
      '.quote-history-item summary span{color:#15803d;flex:0 0 auto}' +
      '.quote-history-item .quote-history-body{padding:0 12px 12px;border-top:1px solid #eef2f7}' +
      '.quote-history-pager{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:10px}' +
      '</style>' +
      '<h4 class="mb-4">寶輝科技正式估價單</h4>' +
      '<div class="alert alert-info">估價單號自動產生 VAL-當天日期-流水號。清單按「轉現貨出貨單」後，出貨單也是 VAL-日期-流水；正規銷售出庫單是 SELL-日期-流水。客戶與品項會進銷售出庫單，可列印、篩選、修正。</div>' +
      '<datalist id="quoteCustomerList"></datalist>' +
      '<div class="form-card mb-3"><h5 class="mb-3">建立 / 編輯估價單</h5>' +
        '<input type="hidden" id="quoteId"><div class="row g-3">' +
          '<div class="col-md-3"><label>估價單號</label><input class="form-control" id="quoteNo" readonly placeholder="自動產生"></div>' +
          '<div class="col-md-3"><label>正式單號 / 公司系統出貨單號</label><input class="form-control" id="quoteOfficialNo" placeholder="例：出貨單號、正式訂單號"></div>' +
          '<div class="col-md-2"><label>開單日期</label><input type="date" class="form-control" id="quoteDate" readonly></div>' +
          '<div class="col-md-2"><label>有效天數</label><input type="number" class="form-control" id="quoteValidDays" value="7" min="1"></div>' +
          '<div class="col-md-2"><label>訂金收取</label><select class="form-select" id="quoteDepositStatus" onchange="refreshQuoteContractDefault(); updateQuotePreviewTotal();"><option value="unpaid">要收訂金（未收）</option><option value="paid">要收訂金（已收）</option><option value="none">不收訂金</option></select></div><div class="col-md-2"><label>訂金比例 %</label><input type="number" class="form-control" id="quoteDepositPercent" value="30" min="0" max="100" oninput="refreshQuoteContractDefault(); updateQuotePreviewTotal();" onchange="refreshQuoteContractDefault(); updateQuotePreviewTotal();"></div><div class="col-md-2"><label>訂金實收金額</label><input type="text" inputmode="decimal" class="form-control" id="quoteDepositReceived" value="0" oninput="updateQuotePreviewTotal()" onblur="cleanMoneyInput(this); updateQuotePreviewTotal();" placeholder="實收多少可填"></div><div class="col-md-2"><label>違約罰金 %</label><input type="number" class="form-control" id="quotePenaltyPercent" value="30" min="0" max="100" oninput="refreshQuoteContractDefault()" onchange="refreshQuoteContractDefault()"></div>' +
          '<div class="col-md-3"><label>估價單抬頭</label><input class="form-control" id="quoteHeaderTitle" value="寶輝科技正式估價單"></div>' +
          '<div class="col-md-3"><label>客戶名稱</label><input class="form-control" id="quoteCustomerName" list="quoteCustomerList" placeholder="客戶 / 公司名稱" onchange="fillQuoteCustomerFromName()" onblur="fillQuoteCustomerFromName()"></div>' +
          '<div class="col-md-3"><label>客戶抬頭</label><input class="form-control" id="quoteTitle" placeholder="發票或合約抬頭"></div>' +
          '<div class="col-md-2"><label>聯絡人</label><input class="form-control" id="quoteContact"></div>' +
          '<div class="col-md-2"><label>電話</label><input class="form-control" id="quotePhone"></div>' +
          '<div class="col-md-2"><label>傳真</label><input class="form-control" id="quoteFax"></div>' +
          '<div class="col-md-3"><label>信箱</label><input class="form-control" id="quoteEmail"></div>' +
          '<div class="col-md-5"><label>客戶地址</label><input class="form-control" id="quoteAddress"></div>' +
          '<div class="col-md-2"><label>運費（選填）</label><input type="number" class="form-control" id="quoteShipping" value="0" min="0"></div>' +
          '<div class="col-md-2"><label>折扣（選填）</label><input type="number" class="form-control" id="quoteDiscount" value="0" min="0"></div>' +
          '<div class="col-md-3"><label>預計完成日期</label><input type="date" class="form-control" id="quoteCompletionDate"></div>' +
          '<div class="col-md-3"><label>尾款期限</label><input type="date" class="form-control" id="quoteBalanceDueDate"></div>' +
          '<div class="col-md-5" data-image-paste><label>公司章電子檔（上傳後固定套用，可貼上圖片）</label><div class="input-group"><input type="file" class="form-control" id="quoteSealFile" accept="image/*"><button class="btn btn-outline-primary" type="button" onclick="uploadQuoteSealImage()">上傳並設為固定公司章</button></div><input class="form-control mt-1" id="quoteSealImage" placeholder="上傳後固定套用，也可貼圖片網址"></div>' +
          '<div class="col-md-3"><label>保固模式</label><input class="form-control" id="quoteWarranty" value="以產品明細保固時間為主；施工/安裝屬於工資，不列入保固範圍" readonly></div>' +
          '<div class="col-12"><label>報價備註（選填）</label><textarea class="form-control" id="quoteNote" rows="2" placeholder="付款方式、交期、注意事項"></textarea></div>' +
          '<div class="col-12"><label>合約內容（手動填寫，有填才會列印）</label><textarea class="form-control" id="quoteContract" rows="5" placeholder="請在這裡輸入本張估價單需要的合約條款、訂金、違約、完工或保固說明。"></textarea></div>' +
          '<div class="col-12"><label>公司聯絡資訊（固定列印尾端）</label><textarea class="form-control" id="quoteCompanyInfo" rows="4" readonly></textarea></div>' +
        '</div>' +
        '<div class="border rounded p-3 my-3 bg-light">' +
          '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">' +
            '<div><h6 class="mb-0">電腦組裝、硬體維護與軟體服務</h6><div class="small text-muted">依客戶需要帶入收費品項；第二年、第三年硬體維護為選購。</div></div>' +
            '<div class="d-flex flex-wrap gap-2">' +
              '<button class="btn btn-sm btn-outline-primary" type="button" onclick="insertQuoteComputerService(\'hardwareYear1\')">硬體代送（第一年）800</button>' +
              '<button class="btn btn-sm btn-outline-primary" type="button" onclick="insertQuoteComputerService(\'assembly\')">組裝費用 1,500</button>' +
              '<button class="btn btn-sm btn-outline-primary" type="button" onclick="insertQuoteComputerService(\'hardwareYear2\')">第二年硬體維護 800</button>' +
              '<button class="btn btn-sm btn-outline-primary" type="button" onclick="insertQuoteComputerService(\'hardwareYear3\')">第三年硬體維護 500</button>' +
              '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService(\'windowsHome\')">Windows 家用版 3,500</button>' +
              '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService(\'windowsPro\')">Windows 專業版 4,600</button>' +
              '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService(\'softwareInstall\')">軟體安裝工資 800</button>' +
            '</div>' +
          '</div>' +
          '<div class="small text-muted">第一年硬體代送費 800 元（一年內非人為處理）。組裝費用 1,500 元另計。第二年硬體維護 800 元、第三年 500 元為選購，可加可不加。</div>' +
        '</div>' +
        '<div class="border rounded p-3 my-3 bg-light">' +
          '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">' +
            '<div><h6 class="mb-0">從產品主檔帶入</h6><div class="small text-muted">搜尋產品編號、條碼、名稱、類別、廠牌或規格；實際售價優先，沒有售價時才顯示寶輝科技參考價。外部自有組裝主機依公司規則排除。</div></div>' +
            '<div class="input-group" style="max-width:420px"><input class="form-control" id="quoteProductCatalogSearch" placeholder="輸入產品關鍵字" onkeydown="if(event.key===\'Enter\'){event.preventDefault();searchQuoteProductCatalog();}"><button class="btn btn-primary" type="button" onclick="searchQuoteProductCatalog()">搜尋產品</button></div>' +
          '</div><div class="row g-2" id="quoteProductCatalogResults"><div class="text-muted small">輸入關鍵字後按搜尋，可直接帶入目前寶輝產品主檔。</div></div>' +
        '</div>' +
        '<div class="border rounded p-3 my-3 bg-light">' +
          '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">' +
            '<div><h6 class="mb-0">常用品項明細紀錄</h6><div class="small text-muted">儲存估價單後自動記錄品項、廠牌、規格、保固、稅金模式與預設價格；可再編輯。</div></div>' +
            '<input class="form-control" id="quoteItemHistorySearch" style="max-width:340px" oninput="renderQuoteItemHistory()" placeholder="搜尋品項 / 廠牌 / 規格 / 保固">' +
          '</div>' +
          '<div id="quoteItemHistoryList"></div>' +
        '</div><hr><div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">品項明細（稅金在品項內計算）</h6><button class="btn btn-sm btn-outline-primary" type="button" onclick="addQuoteItemRow()">新增品項</button></div>' +
        '<div class="table-responsive"><table class="table table-bordered align-middle quote-item-table"><thead class="table-light"><tr><th style="min-width:170px">品項</th><th style="min-width:130px">廠牌</th><th style="min-width:190px">規格</th><th style="min-width:130px;width:130px">數量</th><th style="min-width:160px;width:160px">單價</th><th style="min-width:260px">保固時間</th><th style="min-width:130px">稅金模式</th><th style="min-width:120px">小計</th><th style="width:75px">操作</th></tr></thead><tbody id="quoteItemRows"></tbody></table></div>' +
        '<div class="alert alert-secondary" id="quotePreviewTotal">總計：0 元</div>' +
        '<div class="d-flex flex-wrap gap-2 justify-content-end"><button class="btn btn-secondary" type="button" onclick="clearQuoteForm()">清空</button><button class="btn btn-outline-primary" type="button" onclick="printCurrentQuotation()">列印目前編輯這張</button><button class="btn btn-success" type="button" onclick="approveCurrentQuotation()">完成生效核准</button><button class="btn btn-primary" type="button" onclick="saveQuotation()">儲存估價單</button></div>' +
      '</div>' +
      '<div class="form-card"><div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3"><div><h5 class="mb-1">估價單清單</h5><div class="text-muted small">要印哪一張，請按該列「列印此張」。</div></div><input class="form-control" style="max-width:360px" id="quoteSearch" oninput="renderQuotationList()" placeholder="搜尋單號 / 客戶 / 電話 / 信箱"></div>' +
      '<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" id="quoteSelectAll" onchange="toggleAllQuotations(this.checked)"> 全選目前清單</label><button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteSelectedQuotations()">刪除勾選估價單</button><span class="text-muted small" id="quoteBulkHint">可勾選多張估價單後一次刪除</span></div>' +
      '<div class="table-responsive"><table class="table table-bordered align-middle"><thead class="table-primary"><tr><th style="width:54px">選</th><th>單號</th><th>正式/出貨單號</th><th>日期</th><th>客戶</th><th>電話</th><th>金額</th><th>狀態</th><th>對外連結</th><th>操作</th></tr></thead><tbody id="quotationList"></tbody></table></div></div>' +
      '<div class="form-card mt-3"><div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3"><div><h5 class="mb-1">現貨出貨單</h5><div class="text-muted small">由估價單轉現貨出貨單時，產生 VAL-日期-流水單號，客戶與品項會進銷售出庫單。</div></div><input class="form-control" style="max-width:360px" id="deliverySearch" oninput="renderDeliveryOrderList()" placeholder="搜尋出貨單 / 估價單 / 客戶 / 電話"></div>' +
      '<div class="table-responsive"><table class="table table-bordered align-middle"><thead class="table-success"><tr><th>出貨單號</th><th>來源估價單</th><th>客戶</th><th>電話</th><th>品項數</th><th>總金額</th><th>狀態</th><th>建立時間</th><th>操作</th></tr></thead><tbody id="deliveryOrderList"></tbody></table></div></div>';
    main.appendChild(section);
  }
  function refreshCustomerList() {
    ensureStore(); var dl = $id('quoteCustomerList'); if (!dl) return;
    var names = {};
    (data.quoteCustomers || []).forEach(function (c) {
      var n = String(c.customerName || c.customer || '').trim();
      if (n) names[n] = n;
    });
    (data.members || []).forEach(function (m) {
      [m.name, m.customerName, m.customer, m.organization_name].forEach(function (n) {
        n = String(n || '').trim();
        if (n) names[n] = n;
      });
    });
    dl.innerHTML = Object.keys(names).sort(function (a, b) { return a.localeCompare(b, 'zh-Hant'); }).map(function (n) {
      return '<option value="' + esc(n) + '"></option>';
    }).join('');
  }

  window.fillQuoteCustomerFromName = function () {
    ensureStore();
    var name = (($id('quoteCustomerName') && $id('quoteCustomerName').value) || '').trim();
    if (!name) return;
    var found = (data.quoteCustomers || []).find(function (c) {
      return String(c.customerName || c.customer || '').trim() === name;
    });
    if (!found) {
      found = (data.members || []).find(function (m) {
        var aliases = [m.name, m.customerName, m.customer, m.organization_name, m.title];
        return aliases.some(function (alias) { return String(alias || '').trim() === name; });
      });
    }
    if (!found) return;
    if ($id('quoteTitle')) $id('quoteTitle').value = found.title || found.organization_name || found.customerName || found.name || '';
    if ($id('quoteContact')) $id('quoteContact').value = found.contact || '';
    if ($id('quotePhone')) $id('quotePhone').value = found.phone || found.tel || found.mobile || '';
    if ($id('quoteFax')) $id('quoteFax').value = found.fax || '';
    if ($id('quoteEmail')) $id('quoteEmail').value = found.email || '';
    if ($id('quoteAddress')) $id('quoteAddress').value = found.address || found.addr || found.company_address || found.ship_address || '';
    if ($id('quoteDepositStatus')) $id('quoteDepositStatus').value = found.depositStatus || 'unpaid';
    if ($id('quoteDepositPercent')) $id('quoteDepositPercent').value = found.depositPercent || 30;
    if ($id('quoteDepositReceived')) $id('quoteDepositReceived').value = found.depositReceived || found.depositPaidAmount || 0;
    if ($id('quotePenaltyPercent')) $id('quotePenaltyPercent').value = found.penaltyPercent || 30;
    refreshQuoteContractDefault(); updateQuotePreviewTotal();
  };

  window.uploadQuoteSealImage = async function () {
    if (!canUseQuotation()) return alert('你沒有估價單管理權限，無法上傳公司章圖片');
    var input = $id('quoteSealFile');
    if (!input || !input.files || !input.files.length) return alert('請先選擇公司章圖片');
    var fd = new FormData();
    fd.append('seal', input.files[0]);
    try {
      var res = await fetch('api.php?action=quote_seal_upload', { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' });
      var payload = await res.json();
      if (!res.ok || !payload || !payload.ok) throw new Error(payload && payload.error ? payload.error : '上傳失敗');
      if ($id('quoteSealImage')) $id('quoteSealImage').value = payload.path || '';
      data.quoteSettings = data.quoteSettings || {};
      data.quoteSettings.companySealImage = payload.path || '';
      if (typeof save === 'function') save();
      alert('公司章圖片已上傳，之後估價單會固定套用這個公司章。');
    } catch (e) {
      alert('公司章圖片上傳失敗：' + (e && e.message ? e.message : e));
    }
  };

  function warrantySelectHtml(value) {
    var current = String(value || '');
    var options = [
      "依產品或原廠保固條件辦理",
      "一年內非人為處理",
      "第二年硬體維護 800 元（選購）",
      "第三年硬體維護 500 元（選購）",
      "一年保固(公司非人為不收費保固)",
      "二年保固(第一年非人為代送處理，第二年硬體維護 800 元)",
      "三年保固(第一年非人為代送處理，第二年 800 元、第三年 500 元)",
      "終身保固產品(為原廠受理，如遇到原廠拒修會提供原廠證明)",
      "施工/安裝工資，不列入保固範圍"
    ];
    var html = options.map(function (opt) {
      return '<option value="' + esc(opt) + '"' + (current === opt ? ' selected' : '') + '>' + esc(opt) + '</option>';
    }).join('');
    if (current && options.indexOf(current) === -1) {
      html = '<option value="' + esc(current) + '" selected>' + esc(current) + '</option>' + html;
    }
    return '<select class="form-select quote-item-warranty">' + html + '</select>';
  }

  function rowHtml(it) {
    it = it || {};
    var mode = it.taxMode || 'none';
    var c = itemCalc(it);
    return '<tr class="quote-item-row">' +
      '<td><div class="quote-suggest-wrap"><input class="form-control quote-item-name" autocomplete="off" value="' + esc(it.name || '') + '" placeholder="品項名稱"></div></td>' +
      '<td><div class="quote-suggest-wrap"><input class="form-control quote-item-brand" autocomplete="off" value="' + esc(it.brand || '') + '" placeholder="廠牌"></div></td>' +
      '<td><div class="quote-suggest-wrap"><input class="form-control quote-item-spec" autocomplete="off" value="' + esc(it.spec || '') + '" placeholder="規格 / 型號 / 說明"></div></td>' +
      '<td><input type="text" inputmode="decimal" class="form-control quote-item-qty" value="' + esc(it.qty || 1) + '" oninput="updateQuotePreviewTotal()" onblur="cleanMoneyInput(this); updateQuotePreviewTotal();" placeholder="數量" title="數量"></td>' +
      '<td><input type="text" inputmode="decimal" class="form-control quote-item-price" value="' + esc(it.price || 0) + '" oninput="updateQuotePreviewTotal()" onblur="cleanMoneyInput(this); updateQuotePreviewTotal();" placeholder="單價" title="單價"></td>' +
      '<td>' + warrantySelectHtml(it.warranty) + '</td>' +
      '<td><select class="form-select quote-item-tax" onchange="updateQuotePreviewTotal()"><option value="none"' + (mode === 'none' ? ' selected' : '') + '>未稅</option><option value="external"' + (mode === 'external' ? ' selected' : '') + '>外加 5%</option><option value="included"' + (mode === 'included' ? ' selected' : '') + '>內含 5%</option></select></td>' +
      '<td class="quote-row-subtotal text-end">' + money(c.total) + '</td>' +
      '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove(); updateQuotePreviewTotal();">刪除</button></td>' +
    '</tr>';
  }
  window.addQuoteItemRow = function (item) { var body = $id('quoteItemRows'); if (body) { body.insertAdjacentHTML('beforeend', rowHtml(item)); updateQuotePreviewTotal(); } };
  function readItems() { return Array.from(document.querySelectorAll('#quoteItemRows .quote-item-row')).map(function (tr) { var item = { name: tr.querySelector('.quote-item-name').value.trim(), brand: tr.querySelector('.quote-item-brand').value.trim(), spec: tr.querySelector('.quote-item-spec').value.trim(), qty: num(tr.querySelector('.quote-item-qty').value), price: num(tr.querySelector('.quote-item-price').value), warranty: tr.querySelector('.quote-item-warranty').value.trim(), taxMode: tr.querySelector('.quote-item-tax').value }; if (isOptionalQuoteItem(item)) item.optional = true; return item; }).filter(function (it) { return it.name || it.brand || it.spec || it.qty || it.price || it.warranty; }); }  window.updateQuotePreviewTotal = function () {
    var q = { items: readItems(), discount: $id('quoteDiscount') ? $id('quoteDiscount').value : 0, shipping: $id('quoteShipping') ? $id('quoteShipping').value : 0, depositStatus: $id('quoteDepositStatus') ? $id('quoteDepositStatus').value : 'unpaid', depositPercent: $id('quoteDepositPercent') ? $id('quoteDepositPercent').value : 30, depositReceived: $id('quoteDepositReceived') ? $id('quoteDepositReceived').value : 0, penaltyPercent: $id('quotePenaltyPercent') ? $id('quotePenaltyPercent').value : 30 };
    document.querySelectorAll('#quoteItemRows .quote-item-row').forEach(function (tr) { var it = { qty: tr.querySelector('.quote-item-qty').value, price: tr.querySelector('.quote-item-price').value, taxMode: tr.querySelector('.quote-item-tax').value }; var cell = tr.querySelector('.quote-row-subtotal'); if (cell) cell.textContent = money(itemCalc(it).total); });
    var c = calc(q); var box = $id('quotePreviewTotal');
    if (box) {
      box.innerHTML = '<div class="fw-bold mb-1">品項合計：' + money(c.itemTotal) + (c.optionalTotal ? '｜選購：' + money(c.optionalTotal) + '｜含選購：' + money(c.grandTotal) : '') + '｜運費：' + money(c.shipping) + '｜折扣：' + money(c.discount) + '｜總計：' + money(c.total) + '</div>' +
        '<div class="table-responsive mb-2"><table class="table table-sm table-bordered mb-0"><tbody><tr><th style="width:120px">訂金收取</th><td>' + depositStatusLabel(c.depositStatus) + '</td><th style="width:120px">訂金比例</th><td>' + (q.depositStatus === 'none' ? '不適用' : (q.depositPercent || 30) + '%') + '</td></tr><tr><th>訂金應收</th><td>' + money(c.depositDue) + '</td><th>已收 / 未收</th><td>已收 ' + money(c.depositPaid) + '｜未收 ' + money(c.depositUnpaid) + '</td></tr>' + (q.depositStatus === 'none' ? '<tr><th>訂金收取結果</th><td colspan="3">本張估價單設定為不收訂金。</td></tr>' : '<tr><th>手寫欄</th><td colspan="3">□ 已收訂金　□ 未收訂金　訂金實收：__________ 元 / 經手：__________</td></tr>') + '<tr><th>剩餘應收</th><td colspan="3" class="fw-bold">' + money(c.balance) + '</td></tr></tbody></table></div>' +
        '<div class="alert alert-warning mb-0 py-2"><b>特別提醒：</b>如有上述違約情形，會面臨之罰款金額如下：總計 ' + money(c.total) + ' × ' + (q.penaltyPercent || 30) + '% = <b>' + money(c.penalty) + '</b></div>';
    }
  };
  window.clearQuoteForm = function () {
    ['quoteId','quoteNo','quoteOfficialNo','quoteCustomerName','quoteTitle','quoteContact','quotePhone','quoteFax','quoteEmail','quoteAddress','quoteDiscount','quoteShipping','quoteNote','quoteContract','quoteCompletionDate','quoteBalanceDueDate','quoteSealImage','quoteWarranty','quotePenaltyPercent','quoteDepositStatus','quoteDepositReceived'].forEach(function (id) { var el = $id(id); if (el) el.value = ''; });
    if ($id('quoteDate')) $id('quoteDate').value = today();
    if ($id('quoteValidDays')) $id('quoteValidDays').value = 7;
    if ($id('quoteDepositStatus')) $id('quoteDepositStatus').value = 'unpaid';
    if ($id('quoteDepositPercent')) $id('quoteDepositPercent').value = 30;
    if ($id('quoteDepositReceived')) $id('quoteDepositReceived').value = 0;
    if ($id('quotePenaltyPercent')) $id('quotePenaltyPercent').value = 30;
    if ($id('quoteHeaderTitle')) $id('quoteHeaderTitle').value = '寶輝科技正式估價單';
    if ($id('quoteCompanyInfo')) $id('quoteCompanyInfo').value = DEFAULT_COMPANY_INFO;
    if ($id('quoteWarranty')) $id('quoteWarranty').value = DEFAULT_WARRANTY;
    if ($id('quoteSealImage')) $id('quoteSealImage').value = (data.quoteSettings && data.quoteSettings.companySealImage) || '';
    if ($id('quoteNote')) $id('quoteNote').value = DEFAULT_QUOTE_NOTE;
    if ($id('quoteContract')) $id('quoteContract').value = buildDefaultContract(30, 30, 'unpaid');
    if ($id('quoteItemRows')) $id('quoteItemRows').innerHTML = '';
    addQuoteItemRow(); updateQuotePreviewTotal(); refreshCustomerList(); renderQuoteItemHistory(); renderQuoteItemHistory();
  };
  window.saveQuotation = function () {
    if (!canUseQuotation()) return alert('你沒有估價單管理權限');
    ensureStore();
    var id = $id('quoteId').value || '';
    var old = id ? data.quotations.find(function (x) { return String(x.id) === String(id); }) : null;
    var items = readItems();
    var customerName = $id('quoteCustomerName').value.trim();
    if (!customerName) return alert('請輸入客戶名稱');
    if (!items.length) return alert('請至少新增一個品項，系統不會用空白品項覆蓋原估價單。');

    var noteVal = $id('quoteNote') ? $id('quoteNote').value.trim() : '';
    var contractText = $id('quoteContract') ? $id('quoteContract').value.trim() : '';
    var depositStatus = $id('quoteDepositStatus') ? $id('quoteDepositStatus').value : 'unpaid';
    var depositPercent = depositStatus === 'none' ? 0 : (num($id('quoteDepositPercent').value) || 30);
    var penaltyPercent = num($id('quotePenaltyPercent').value) || 30;

    var q = old || { id: String(Date.now()), token: token(), createdAt: nowStamp() };
    q.no = $id('quoteNo').value.trim() || q.no || nextQuoteNo();
    q.officialNo = $id('quoteOfficialNo') ? $id('quoteOfficialNo').value.trim() : (q.officialNo || '');
    q.date = today();
    q.validDays = num($id('quoteValidDays').value) || 7;
    q.headerTitle = $id('quoteHeaderTitle').value.trim() || '寶輝科技正式估價單';
    q.customerName = customerName;
    q.customer = customerName;
    q.title = $id('quoteTitle').value.trim();
    q.contact = $id('quoteContact').value.trim();
    q.phone = $id('quotePhone').value.trim();
    q.fax = $id('quoteFax').value.trim();
    q.email = $id('quoteEmail').value.trim();
    q.address = $id('quoteAddress').value.trim();
    q.discount = num($id('quoteDiscount').value);
    q.shipping = num($id('quoteShipping').value);
    q.depositStatus = depositStatus;
    q.depositPercent = depositPercent;
    q.depositReceived = depositStatus === 'paid' ? num($id('quoteDepositReceived') ? $id('quoteDepositReceived').value : 0) : 0;
    q.penaltyPercent = penaltyPercent;
    q.completionDate = $id('quoteCompletionDate').value;
    q.balanceDueDate = $id('quoteBalanceDueDate').value;
    q.sealImage = $id('quoteSealImage').value.trim() || ((data.quoteSettings && data.quoteSettings.companySealImage) || '');
    q.warranty = DEFAULT_WARRANTY;
    q.note = noteVal || q.note || DEFAULT_QUOTE_NOTE;
    q.contract = contractText
      ? (contractNeedsDefault(contractText) ? buildDefaultContract(depositPercent, penaltyPercent, depositStatus) : contractText)
      : (q.contract || buildDefaultContract(depositPercent, penaltyPercent, depositStatus));
    q.companyInfo = DEFAULT_COMPANY_INFO;
    q.items = items;
    q.status = q.status || '報價中';
    q.updatedAt = nowStamp();

    if (!old) data.quotations.unshift(q);
    rememberQuoteItems(q.items);
    upsertCustomer(q);
    syncQuoteCustomersToMembers();
    if (typeof save === 'function' && !save()) return;
    renderQuotationList();
    editQuotation(q.id);
    alert('估價單已儲存，可複製對外連結或列印。');
  };
  window.editQuotation = function (id) {
    ensureStore(); var q = data.quotations.find(function (x) { return String(x.id) === String(id); }); if (!q) return alert('找不到估價單'); ensurePage();
    $id('quoteId').value = q.id; $id('quoteNo').value = q.no || ''; if ($id('quoteOfficialNo')) $id('quoteOfficialNo').value = q.officialNo || ''; $id('quoteDate').value = q.date || today(); $id('quoteValidDays').value = q.validDays || 7; $id('quoteHeaderTitle').value = q.headerTitle || '寶輝科技正式估價單';
    $id('quoteCustomerName').value = q.customerName || q.customer || ''; $id('quoteTitle').value = q.title || ''; $id('quoteContact').value = q.contact || ''; $id('quotePhone').value = q.phone || ''; $id('quoteFax').value = q.fax || ''; $id('quoteEmail').value = q.email || ''; $id('quoteAddress').value = q.address || '';
    $id('quoteDiscount').value = q.discount || 0; $id('quoteShipping').value = q.shipping || 0; if ($id('quoteDepositStatus')) $id('quoteDepositStatus').value = q.depositStatus || 'unpaid'; $id('quoteDepositPercent').value = q.depositPercent || 30; if ($id('quoteDepositReceived')) $id('quoteDepositReceived').value = q.depositReceived || q.depositPaidAmount || 0; $id('quotePenaltyPercent').value = q.penaltyPercent || 30; $id('quoteCompletionDate').value = q.completionDate || ''; $id('quoteBalanceDueDate').value = q.balanceDueDate || ''; $id('quoteSealImage').value = q.sealImage || ((data.quoteSettings && data.quoteSettings.companySealImage) || ''); $id('quoteWarranty').value = DEFAULT_WARRANTY; $id('quoteNote').value = q.note || DEFAULT_QUOTE_NOTE; $id('quoteContract').value = contractForQuote(q); $id('quoteCompanyInfo').value = DEFAULT_COMPANY_INFO;
    $id('quoteItemRows').innerHTML = ''; (q.items || []).forEach(addQuoteItemRow); if (!(q.items || []).length) addQuoteItemRow(); refreshCustomerList(); updateQuotePreviewTotal(); $id('quoteNo').scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  function approveQuotation(id) {
    if (!adminOk()) return alert('僅管理者可核准估價單');
    ensureStore();
    var q = data.quotations.find(function (x) { return String(x.id) === String(id); });
    if (!q) return alert('找不到估價單');
    if (!confirm('確定核准此估價單完成生效？核准後會記錄核准人與時間作為證明。')) return;
    q.status = '已核准生效';
    q.approvedAt = nowStamp();
    q.approvedBy = (typeof currentLoginUser !== 'undefined' && currentLoginUser) ? currentLoginUser : 'admin';
    q.fileSavedAt = q.fileSavedAt || nowStamp();
    q.effectiveProof = '核准生效：' + q.approvedAt + '；核准人：' + q.approvedBy;
    if (typeof save === 'function' && !save()) return;
    renderQuotationList();
    editQuotation(q.id);
    alert('估價單已完成生效核准，列印版會顯示核准證明。');
  }
  window.approveCurrentQuotation = function () {
    var id = $id('quoteId') ? $id('quoteId').value : '';
    if (!id) return alert('請先儲存估價單，再核准生效。');
    approveQuotation(id);
  };
  window.approveQuotation = approveQuotation;  window.printCurrentQuotation = function () {
    ensureStore();
    var id = $id('quoteId') ? $id('quoteId').value : '';
    if (!id) return alert('請先儲存估價單，再列印目前這張單。');
    var q = data.quotations.find(function (x) { return String(x.id) === String(id); });
    if (!q) return alert('找不到目前估價單，請先儲存後再列印。');
    var w = window.open(quoteUrl(q) + '&print=1', '_blank');
    if (w) setTimeout(function () { try { w.focus(); } catch (e) {} }, 300);
  };  window.deleteQuotation = function (id) { if (!adminOk()) return; ensureStore(); var q = data.quotations.find(function (x) { return String(x.id) === String(id); }); if (!q) return; if (!confirm('確定刪除估價單 ' + (q.no || id) + '？')) return; data.quotations = data.quotations.filter(function (x) { return String(x.id) !== String(id); }); if (typeof save === 'function' && !save()) return; renderQuotationList(); };
  window.toggleAllQuotations = function (checked) {
    document.querySelectorAll('.quote-bulk-check').forEach(function (box) { box.checked = !!checked; });
    updateQuoteBulkHint();
  };
  window.updateQuoteBulkHint = function () {
    var count = document.querySelectorAll('.quote-bulk-check:checked').length;
    var hint = $id('quoteBulkHint');
    if (hint) hint.textContent = count ? ('已勾選 ' + count + ' 張估價單') : '可勾選多張估價單後一次刪除';
  };
  window.deleteSelectedQuotations = function () {
    if (!adminOk()) return alert('僅管理者可刪除估價單');
    ensureStore();
    var ids = Array.from(document.querySelectorAll('.quote-bulk-check:checked')).map(function (box) { return String(box.value); });
    if (!ids.length) return alert('請先勾選要刪除的估價單');
    var selected = data.quotations.filter(function (q) { return ids.indexOf(String(q.id)) >= 0; });
    var names = selected.slice(0, 8).map(function (q) { return q.no || q.customerName || q.id; }).join('、');
    var more = selected.length > 8 ? (' 等 ' + selected.length + ' 張') : (' 共 ' + selected.length + ' 張');
    if (!confirm('確定要刪除已勾選估價單？\\n' + names + more + '\\n此動作只刪除估價單，不會刪除客戶與常用品項紀錄。')) return;
    data.quotations = data.quotations.filter(function (q) { return ids.indexOf(String(q.id)) < 0; });
    if (typeof save === 'function' && !save()) return;
    renderQuotationList();
  };
  window.copyQuoteUrl = function (id) { ensureStore(); var q = data.quotations.find(function (x) { return String(x.id) === String(id); }); if (!q) return; var url = quoteUrl(q); if (navigator.clipboard) navigator.clipboard.writeText(url); prompt('估價單對外連結', url); };
  window.openQuoteUrl = function (id) { ensureStore(); var q = data.quotations.find(function (x) { return String(x.id) === String(id); }); if (q) window.open(quoteUrl(q), '_blank'); };
  window.printQuoteUrl = function (id) {
    ensureStore();
    var q = data.quotations.find(function (x) { return String(x.id) === String(id); });
    if (!q) return;
    var existing = findDeliveryByQuoteId(q.id);
    var url;
    if (existing && existing.token) {
      url = deliveryUrl(existing) + '&print=1';
    } else if (q.officialNo || q.deliveryNo) {
      url = location.origin + location.pathname.replace(/admin\.php.*$/, '') + 'delivery-view.php?quote_token=' + encodeURIComponent(q.token || '') + '&print=1';
    } else {
      url = quoteUrl(q) + '&print=1';
    }
    var w = window.open(url, '_blank');
    if (w) { setTimeout(function () { try { w.focus(); } catch (e) {} }, 400); }
  };
  window.renderQuotationList = function () {
    ensureStore(); syncQuoteCustomersToMembers(); ensureNav(); ensurePage(); refreshCustomerList(); renderQuoteItemHistory(); renderDeliveryOrderList(); var body = $id('quotationList'); if (!body) return;
    if (!canUseQuotation()) { body.innerHTML = '<tr><td colspan="10" class="text-muted">你沒有估價單管理權限。</td></tr>'; return; }
    var search = (($id('quoteSearch') && $id('quoteSearch').value) || '').toLowerCase();
    var rows = data.quotations.slice().filter(function (q) { return !search || [q.no,q.customerName,q.customer,q.title,q.phone,q.email,q.address].join(' ').toLowerCase().indexOf(search) >= 0; });
    if ($id('quoteSelectAll')) $id('quoteSelectAll').checked = false;
    updateQuoteBulkHint();
    if (!rows.length) { body.innerHTML = '<tr><td colspan="10" class="text-muted">目前沒有估價單。</td></tr>'; return; }
        body.innerHTML = rows.map(function (q) {
          var c = calc(q);
          var approvedInfo = q.approvedAt ? '<div class="small text-success">' + esc(q.approvedAt) + '<br>' + esc(q.approvedBy || '') + '</div>' : '';
          var approveBtn = q.status === '已核准生效' ? '' : '<button class="btn btn-sm btn-success me-1" onclick="approveQuotation(\'' + jsString(q.id) + '\')">核准生效</button>';
          var shipBtn = (q.deliveryNo || findDeliveryByQuoteId(q.id))
            ? '<button class="btn btn-sm btn-outline-success me-1" onclick="createDeliveryFromQuotation(\'' + jsString(q.id) + '\')">已轉 ' + esc(q.deliveryNo || (findDeliveryByQuoteId(q.id) || {}).no || '') + '</button>'
            : '<button class="btn btn-sm btn-warning me-1" onclick="createDeliveryFromQuotation(\'' + jsString(q.id) + '\')">轉現貨出貨單</button>';
          var printLabel = (q.deliveryNo || q.officialNo || findDeliveryByQuoteId(q.id)) ? '列印出貨單' : '列印此張';
          return '<tr><td class="text-center"><input class="form-check-input quote-bulk-check" type="checkbox" value="' + esc(q.id) + '" onchange="updateQuoteBulkHint()"></td><td class="fw-bold">' + esc(q.no || '-') + '</td><td>' + esc(q.officialNo || q.deliveryNo || '-') + '</td><td>' + esc(q.date || '-') + '</td><td>' + esc(q.customerName || q.customer || '-') + '</td><td>' + esc(q.phone || '-') + '</td><td class="text-end fw-bold">' + money(c.total) + '</td><td>' + esc(q.status || '報價中') + approvedInfo + '</td><td><button class="btn btn-sm btn-outline-success" onclick="copyQuoteUrl(\'' + jsString(q.id) + '\')">複製</button> <button class="btn btn-sm btn-outline-secondary" onclick="openQuoteUrl(\'' + jsString(q.id) + '\')">開啟</button> <button class="btn btn-sm btn-outline-primary" onclick="printQuoteUrl(\'' + jsString(q.id) + '\')">' + printLabel + '</button></td><td>' + shipBtn + approveBtn + '<button class="btn btn-sm btn-primary me-1" onclick="editQuotation(\'' + jsString(q.id) + '\')">編輯</button><button class="btn btn-sm btn-outline-danger" onclick="deleteQuotation(\'' + jsString(q.id) + '\')">刪除</button></td></tr>';
        }).join('');
  };

  window.createDeliveryFromQuotation = function (id) {
    if (!canUseQuotation()) return alert('你沒有估價單管理權限，無法轉現貨出貨單');
    ensureStore();
    var q = data.quotations.find(function (x) { return String(x.id) === String(id); });
    if (!q) return alert('找不到估價單');
    var existing = findDeliveryByQuoteId(q.id);
    if (existing) {
      if (!q.deliveryNo) q.deliveryNo = existing.no || '';
      if (!q.deliveryOrderId) q.deliveryOrderId = existing.id || '';
      if (!q.officialNo) q.officialNo = existing.no || '';
      if (typeof save === 'function' && !save()) return;
      renderQuotationList();
      renderDeliveryOrderList();
      syncDeliveryToOneDollar(existing, q).then(function (res) {
        if (res && res.ok && res.delivery_no && existing.no !== res.delivery_no) {
          existing.no = res.delivery_no;
          existing.officialNo = res.delivery_no;
          q.deliveryNo = res.delivery_no;
          q.officialNo = q.officialNo || res.delivery_no;
          if (typeof save === 'function') save();
          renderQuotationList();
          renderDeliveryOrderList();
        }
        if (!res || !res.ok) alert('這張估價單已經有現貨出貨單：' + (existing.no || '-') + '；但同步銷售出庫單失敗：' + ((res && res.message) || '請稍後重試'));
        else alert('這張估價單已經有現貨出貨單：' + (res.delivery_no || existing.no || '-'));
      });
      return;
    }
    var c = calc(q);
    var shipItems = (q.items || []).filter(function (it) { return it && !isOptionalQuoteItem(it); }).map(function (it, index) {
      return {
        lineNo: index + 1,
        name: it.name || '',
        brand: it.brand || '',
        spec: it.spec || '',
        qty: it.qty || 1,
        price: it.price || 0,
        warranty: it.warranty || '',
        taxMode: it.taxMode || 'none'
      };
    });
    if (!shipItems.length) return alert('這張估價單沒有可轉出的品項（選購項目不會轉進現貨出貨單）。');
    if (!confirm('要把估價單 ' + (q.no || '') + ' 轉成現貨出貨單嗎？\n客戶：' + (q.customerName || q.customer || '') + '\n金額：' + money(c.total) + '\n會進銷售出庫單，服務項目不扣庫存。')) return;
    var no = nextDeliveryNo();
    var order = {
      id: String(Date.now()) + Math.random().toString(36).slice(2, 7),
      token: token(),
      no: no,
      status: '待出貨',
      source: 'quotation',
      quoteId: q.id,
      quoteNo: q.no || '',
      quoteToken: q.token || '',
      officialNo: no,
      date: today(),
      createdAt: nowStamp(),
      updatedAt: nowStamp(),
      customerName: q.customerName || q.customer || '',
      title: q.title || '',
      contact: q.contact || '',
      phone: q.phone || '',
      fax: q.fax || '',
      email: q.email || '',
      address: q.address || '',
      shipping: q.shipping || 0,
      discount: q.discount || 0,
      total: c.total || 0,
      items: shipItems,
      logisticsCompany: '',
      trackingNo: '',
      shippedAt: '',
      note: q.note || '',
      stockKind: '現貨'
    };
    data.deliveryOrders.unshift(order);
    q.deliveryOrderId = order.id;
    q.deliveryNo = order.no;
    q.officialNo = q.officialNo || order.no;
    q.updatedAt = nowStamp();
    if (typeof save === 'function' && !save()) return;
    renderQuotationList();
    renderDeliveryOrderList();
    syncDeliveryToOneDollar(order, q).then(function (res) {
      if (res && res.ok) {
        if (res.delivery_no && order.no !== res.delivery_no) {
          order.no = res.delivery_no;
          order.officialNo = res.delivery_no;
          q.deliveryNo = res.delivery_no;
          q.officialNo = q.officialNo || res.delivery_no;
          if (typeof save === 'function') save();
          renderQuotationList();
          renderDeliveryOrderList();
        }
        alert('已轉現貨出貨單：' + (res.delivery_no || order.no) + '，客戶 ' + (q.customerName || '') + ' 已進銷售出庫單。');
      } else {
        alert('已建立出貨單：' + order.no + '；但同步銷售出庫單失敗：' + ((res && res.message) || '請稍後重試'));
      }
    });
  };
    window.openDeliveryOrder = function (id) {
    ensureStore();
    var o = data.deliveryOrders.find(function (x) { return String(x.id) === String(id); });
    if (o) window.open(deliveryUrl(o), '_blank');
  };
  window.printDeliveryOrder = function (id) {
    ensureStore();
    var o = data.deliveryOrders.find(function (x) { return String(x.id) === String(id); });
    if (!o) return;
    var w = window.open(deliveryUrl(o) + '&print=1', '_blank');
    if (w) setTimeout(function () { try { w.focus(); } catch (e) {} }, 300);
  };
  window.markDeliveryShipped = function (id) {
    ensureStore();
    var o = data.deliveryOrders.find(function (x) { return String(x.id) === String(id); });
    if (!o) return alert('找不到出貨單');
    o.status = '已出貨';
    o.shippedAt = nowStamp();
    o.updatedAt = nowStamp();
    if (typeof save === 'function' && !save()) return;
    renderDeliveryOrderList();
  };
  window.renderDeliveryOrderList = function () {
    ensureStore();
    var body = $id('deliveryOrderList');
    if (!body) return;
    var kw = (($id('deliverySearch') && $id('deliverySearch').value) || '').toLowerCase();
    var rows = (data.deliveryOrders || []).slice().filter(function (o) {
      return !kw || [o.no,o.quoteNo,o.customerName,o.title,o.phone,o.address,o.status].join(' ').toLowerCase().indexOf(kw) >= 0;
    });
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="9" class="text-muted">目前尚無現貨出貨單。請先在估價單清單按「轉現貨出貨單」。</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (o) {
      return '<tr><td class="fw-bold">' + esc(o.no || '-') + '</td><td>' + esc(o.quoteNo || '-') + '</td><td>' + esc(o.customerName || o.title || '-') + '</td><td>' + esc(o.phone || '-') + '</td><td class="text-end">' + ((o.items || []).length) + '</td><td class="text-end fw-bold">' + money(o.total || 0) + '</td><td>' + esc(o.status || '待出貨') + '</td><td>' + esc(o.createdAt || '-') + '</td><td><button class="btn btn-sm btn-outline-secondary me-1" onclick="openDeliveryOrder(\'' + esc(o.id) + '\')">開啟</button><button class="btn btn-sm btn-outline-primary me-1" onclick="printDeliveryOrder(\'' + esc(o.id) + '\')">列印</button><button class="btn btn-sm btn-success" onclick="markDeliveryShipped(\'' + esc(o.id) + '\')">已出貨</button></td></tr>';
    }).join('');
  };

  var oldEnsure = window.ensureDataShape; if (typeof oldEnsure === 'function') window.ensureDataShape = function () { var r = oldEnsure.apply(this, arguments); ensureStore(); return r; };
  var oldFilter = window.filterSidebarByPermission; if (typeof oldFilter === 'function') window.filterSidebarByPermission = function () { var r = oldFilter.apply(this, arguments); ensureNav(); return r; };
  var oldGo = window.goPage; if (typeof oldGo === 'function') window.goPage = function (pg) { ensureNav(); ensurePage(); bindQuoteItemLink(); var r = oldGo.apply(this, arguments); if (pg === PAGE) { if (!canUseQuotation()) return alert('你沒有估價單管理權限'); renderQuotationList(); renderQuoteItemHistory(); if (!$id('quoteItemRows').children.length) clearQuoteForm(); } return r; };
  document.addEventListener('DOMContentLoaded', function () { ensureStore(); ensureNav(); ensurePage(); refreshCustomerList(); bindQuoteItemLink(); });
  window.addEventListener('load', function () { setTimeout(function () { ensureStore(); ensureNav(); ensurePage(); refreshCustomerList(); bindQuoteItemLink(); }, 200); });
})();
