(function () {
  'use strict';

  var LANG_KEY = 'lingzanzan-cek-lang';
  var RECENT_KEY = 'lingzanzan-cek-recent';
  var COPY = {
    id: {
      title: 'Cek Kirim',
      sub: 'Tidak perlu akun. Ketik telepon atau resi.',
      label: 'Telepon / nomor resi',
      placeholder: '0812… atau nomor resi',
      submit: 'Cek sekarang',
      hint: 'Buka daftar hitam di bawah, tidak perlu telepon dulu. Atau ketik telepon / nama / resi.',
      addhome: 'Di HP: buka tautan ini → bagikan → Tambah ke Layar Utama. Nanti cukup ketuk ikon.',
      recent: 'Baru dicari',
      searching: 'Mencari…',
      need: 'Ketik minimal 3 angka, atau 2 huruf nama.',
      empty: 'Tidak ketemu. Coba telepon, nama, resi, daftar hitam, atau yang pernah return.',
      fail: 'Gagal cek. Coba lagi.',
      failFile: 'Jangan buka file. Pakai https://www.lingzanzan.com/cek.html',
      found: 'Ketemu',
      many: 'Ada beberapa hasil. Cek nama / telepon yang benar.',
      order: 'Nomor pesanan',
      carrier: 'Kurir',
      tracking: 'Nomor resi',
      updated: 'Update terakhir',
      report: 'Status logistik',
      call: 'Telepon',
      copy: 'Salin resi',
      copied: 'Tersalin',
      noPhone: 'Tidak ada telepon',
      noTracking: 'Belum ada resi',
      deadline: 'Batas ambil',
      leftover: 'Sisa ',
      days: ' hari',
      overdue: 'Lewat ',
      today: 'Hari ini terakhir ambil',
      boardTitle: 'Catatan 7 hari',
      returningTitle: 'Hampir dikembalikan',
      waitingTitle: 'Masih di toko',
      returningHint: 'Ambil hari ini. Nanti dikembalikan.',
      waitingHint: 'Sudah di toko, belum diambil.',
      boardLoading: 'Memuat daftar 7 hari…',
      boardEmpty: 'Tidak ada paket di toko 7 hari ini.',
      boardFail: 'Gagal muat daftar.',
      toko: 'Toko',
      barang: 'Barang',
      cod: 'COD toko',
      todayLimit: 'Hari ini 23:59',
      overdueShort: 'Sudah lewat',
      noDeadline: 'Batas belum ada',
      lock: 'Kunci daftar hitam',
      locked: 'Sudah dikunci',
      lockTag: 'Blacklist',
      lockConfirm: 'Kunci pelanggan ini? Order baru akan ditahan. Admin yang bisa buka.',
      lockNeedPhone: 'Tidak ada telepon, tidak bisa kunci.',
      lockOk: 'Sudah dikunci.',
      lockFail: 'Gagal kunci. Coba lagi.',
      lockReasonOverdue: 'Tidak ambil / lewat batas',
      lockReasonSales: 'Sales kunci pelanggan',
      blacklistTitle: 'Daftar hitam',
      blacklistHint: 'Sales sudah kunci. Order baru akan ditahan.',
      returnedTitle: 'Pernah dikembalikan',
      returnedHint: 'Pernah return / ditolak. Bisa dicari dari telepon atau nama.',
      returnTag: 'Pernah return',
      returnCount: 'Jumlah return',
      lastReturn: 'Return terakhir',
      reason: 'Alasan',
      riskEmpty: 'Tidak ada.',
      tabPickup: 'Ambil',
      tabBlacklist: 'Daftar hitam',
      tabReturned: 'Return',
      blacklistListHint: 'Seluruh daftar hitam. Tidak perlu cari telepon dulu. Order baru akan ditahan.',
      states: {
        pending: 'Menunggu dikirim',
        ready: 'Siap dikirim',
        shipped: 'Dalam pengiriman',
        in_transit: 'Dalam pengiriman',
        arrived_store: 'Sudah di toko, belum diambil',
        delivered: 'Sudah diterima / diambil',
        returned: 'Dikembalikan',
        blacklist: 'Daftar hitam'
      }
    },
    zh: {
      title: '查貨',
      sub: '不用登入。輸入電話或物流單號。',
      label: '電話／物流單號',
      placeholder: '電話或物流單號',
      submit: '立刻查貨',
      hint: '下面有黑名單整份清單，不用先打電話。也可以搜電話、姓名、物流單號或退貨過的客人。',
      addhome: '手機：打開這個網址 → 分享 → 加入主畫面。以後點圖示就能查。',
      recent: '最近查過',
      searching: '查詢中…',
      need: '至少打 3 碼電話，或 2 個字的姓名。',
      empty: '找不到。改打電話、姓名、物流單號、黑名單或退貨過的客人。',
      fail: '查詢失敗，再試一次。',
      failFile: '不要直接開檔案。請用 https://www.lingzanzan.com/cek.html',
      found: '找到',
      many: '找到多筆，請對一下姓名／電話。',
      order: '訂單編號',
      carrier: '物流',
      tracking: '物流單號',
      updated: '最近更新',
      report: '物流回報',
      call: '打電話',
      copy: '複製單號',
      copied: '已複製',
      noPhone: '沒有電話',
      noTracking: '還沒有物流單號',
      deadline: '取件期限',
      leftover: '還剩 ',
      days: ' 天',
      overdue: '已超過 ',
      today: '今天是最後取件日',
      boardTitle: '七天內取件',
      returningTitle: '快退回',
      waitingTitle: '還在門市',
      returningHint: '今天要取，不然會退回。',
      waitingHint: '貨已到門市，還沒取。',
      boardLoading: '載入七天清單…',
      boardEmpty: '這七天沒有待取包裹。',
      boardFail: '清單載入失敗。',
      toko: '門市',
      barang: '商品',
      cod: '物流代收金額',
      todayLimit: '今天 23:59',
      overdueShort: '已過期',
      noDeadline: '尚未標截止',
      lock: '鎖定黑名單',
      locked: '已鎖定',
      lockTag: '黑名單',
      lockConfirm: '確定把這個客人鎖進黑名單？之後打單會擋，要管理後台才能解除。',
      lockNeedPhone: '沒有電話，不能鎖定。',
      lockOk: '已鎖定黑名單。',
      lockFail: '鎖定失敗，再試一次。',
      lockReasonOverdue: '未取件／過期退回',
      lockReasonSales: '業務鎖定客戶',
      blacklistTitle: '黑名單',
      blacklistHint: '業務鎖過。之後打單會擋，要管理後台才能解除。',
      returnedTitle: '退貨過的客戶',
      returnedHint: '曾經退貨或拒收。電話、姓名都可以查。',
      returnTag: '退貨過',
      returnCount: '退貨次數',
      lastReturn: '最近退貨',
      reason: '原因',
      riskEmpty: '目前沒有。',
      tabPickup: '待取',
      tabBlacklist: '黑名單',
      tabReturned: '退貨過',
      blacklistListHint: '整份黑名單，不用先打電話。之後打單會擋，要管理後台才能解除。',
      states: {
        pending: '待出貨',
        ready: '待出貨',
        shipped: '配送中',
        in_transit: '配送中',
        arrived_store: '已到門市待取',
        delivered: '已送達／已取件',
        returned: '已退回',
        blacklist: '黑名單'
      }
    }
  };

  var $ = function (sel) { return document.querySelector(sel); };
  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }
  function lang() {
    try {
      return localStorage.getItem(LANG_KEY) === 'zh' ? 'zh' : 'id';
    } catch (error) {
      return 'id';
    }
  }
  function t() {
    return COPY[lang()];
  }
  function digits(value) {
    return String(value || '').replace(/\D+/g, '');
  }
  function moneyText(value) {
    var amount = Math.max(0, Math.round(Number(value || 0)));
    return 'NT$' + amount.toLocaleString('en-US');
  }
  function codHtml(row) {
    return '<p class="cek-cod">' + esc(t().cod) + ' <strong>' + esc(moneyText(row.codAmount)) + '</strong></p>';
  }
  function loadRecent() {
    try {
      var rows = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
      return Array.isArray(rows) ? rows.filter(Boolean).slice(0, 8) : [];
    } catch (error) {
      return [];
    }
  }
  function saveRecent(query) {
    try {
      var next = [query].concat(loadRecent().filter(function (item) { return item !== query; })).slice(0, 8);
      localStorage.setItem(RECENT_KEY, JSON.stringify(next));
    } catch (error) {}
  }
  function setStatus(message, kind) {
    var el = $('[data-cek-status]');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'cek-status' + (kind ? ' is-' + kind : '');
  }
  function apiUrl(qs) {
    return './customer-shipping-lookup-api.php?' + qs + '&_=' + Date.now();
  }
  function readApi(response) {
    return response.text().then(function (text) {
      var data = null;
      try { data = JSON.parse(text); } catch (error) { data = null; }
      if (!data || typeof data !== 'object') {
        throw new Error(location.protocol === 'file:' ? 'file' : ('http-' + (response.status || 0)));
      }
      if (!response.ok || !data.ok) throw new Error(data.error || ('http-' + response.status));
      return data;
    });
  }
  function failText(error) {
    var code = String((error && error.message) || '');
    if (code === 'file' || location.protocol === 'file:') return t().failFile;
    if (code.indexOf('http-') === 0) return t().fail + ' (' + code.slice(5) + ')';
    return t().fail;
  }
  function stateLabel(row) {
    var map = t().states;
    return map[row.state] || row.stateLabel || row.state || '-';
  }
  function deadlineHtml(row) {
    if (row.state !== 'arrived_store') return '';
    var copy = t();
    var days = row.remainingPickupDays;
    var text = copy.deadline + '：';
    if (days == null) text += String(row.pickupDeadline || '-').slice(0, 10);
    else if (days < 0) text += copy.overdue + Math.abs(days) + copy.days;
    else if (days === 0) text += copy.today;
    else text += copy.leftover + days + copy.days;
    if (row.pickupDeadline) text += '（' + String(row.pickupDeadline).slice(0, 10) + '）';
    return '<div class="cek-deadline' + ((days != null && days <= 1) ? ' is-danger' : '') + '">' + esc(text) + '</div>';
  }
  function lockButtonHtml(row, danger) {
    var copy = t();
    var phone = digits(row.phone);
    if (!phone) return '';
    if (row.blacklisted) {
      return '<button type="button" class="is-locked" disabled>' + esc(copy.locked) + '</button>';
    }
    var reason = danger ? copy.lockReasonOverdue : copy.lockReasonSales;
    return '<button type="button" class="is-lock" data-cek-lock-phone="' + esc(phone) + '" data-cek-lock-name="' + esc(row.customerName || '') + '" data-cek-lock-reason="' + esc(reason) + '">' + esc(copy.lock) + '</button>';
  }
  function blacklistTagHtml(row) {
    if (!row.blacklisted && row.kind !== 'blacklist' && row.state !== 'blacklist') return '';
    return '<em class="cek-blacklist-tag">' + esc(t().lockTag) + '</em>';
  }
  function returnTagHtml(row) {
    var n = Number(row.returnCount || 0);
    if (!row.returnedBefore && n <= 0 && row.kind !== 'returned_customer' && row.state !== 'returned') return '';
    var label = t().returnTag;
    if (n > 0) label += ' ×' + n;
    return '<em class="cek-return-tag">' + esc(label) + '</em>';
  }
  function tagsHtml(row) {
    return blacklistTagHtml(row) + returnTagHtml(row);
  }
  function resultCardHtml(row) {
    if (row.kind === 'blacklist' || row.kind === 'returned_customer') {
      return riskCardHtml(row, row.kind === 'returned_customer');
    }
    return cardHtml(row);
  }
  function cardHtml(row) {
    var copy = t();
    var phone = digits(row.phone);
    var tracking = String(row.trackingNo || '').trim();
    var cls = 'cek-card is-' + esc(row.state || 'pending') + (row.blacklisted ? ' is-blacklisted' : '');
    var customerImg = String(row.customerImage || '').trim();
    var productImgs = (Array.isArray(row.items) ? row.items : []).map(function (item) {
      return String((item && item.image) || '').trim();
    }).filter(Boolean).filter(function (url, index, list) { return list.indexOf(url) === index; }).slice(0, 4);
    var photos = '';
    if (customerImg || productImgs.length) {
      photos = '<div class="cek-photos">' +
        (customerImg ? '<img class="is-customer" src="' + esc(customerImg) + '" alt="' + esc(row.customerName || '') + '" loading="lazy">' : '') +
        productImgs.map(function (url) {
          return '<img class="is-product" src="' + esc(url) + '" alt="" loading="lazy">';
        }).join('') +
      '</div>';
    }
    return '<article class="' + cls + '">' +
      photos +
      '<header><div><h2 class="cek-name">' + esc(row.customerName || '-') + tagsHtml(row) + '</h2><p class="phone">' + esc(row.phone || copy.noPhone) + '</p>' + codHtml(row) + '</div><span class="cek-state">' + esc(stateLabel(row)) + '</span></header>' +
      '<div class="cek-grid">' +
        '<div><small>' + esc(copy.order) + '</small><b>' + esc(row.orderId || '-') + '</b></div>' +
        '<div><small>' + esc(copy.carrier) + '</small><b>' + esc(row.carrier || '-') + '</b></div>' +
        '<div><small>' + esc(copy.tracking) + '</small><b>' + esc(tracking || copy.noTracking) + '</b></div>' +
        '<div><small>' + esc(copy.updated) + '</small><b>' + esc(String(row.latestReportAt || '-').replace('T', ' ').slice(0, 19)) + '</b></div>' +
      '</div>' +
      (row.latestReport ? '<p class="cek-report">' + esc(copy.report + '：' + row.latestReport) + '</p>' : '') +
      deadlineHtml(row) +
      '<div class="cek-actions">' +
        (phone ? '<a class="is-call" href="tel:' + esc(phone) + '">' + esc(copy.call) + '</a>' : '<span></span>') +
        (tracking ? '<button type="button" data-cek-copy="' + esc(tracking) + '">' + esc(copy.copy) + '</button>' : '<span></span>') +
        lockButtonHtml(row, row.state === 'arrived_store' && Number(row.remainingPickupDays) <= 1) +
      '</div>' +
    '</article>';
  }
  var boardCache = null;
  var lastSearchRows = [];
  var COLOR_ID = {
    '紅': 'merah', '粉紅': 'pink', '粉': 'pink', '藍': 'biru', '深藍': 'biru tua',
    '白': 'putih', '黑': 'hitam', '綠': 'hijau', '咖啡': 'kopi', '紫': 'ungu',
    '黃': 'kuning', '棕': 'coklat', '灰': 'abu', '米': 'krem', '卡其': 'khaki'
  };
  function productText(row) {
    var raw = String(row.products || '').trim();
    if (!raw && Array.isArray(row.items)) {
      raw = row.items.map(function (item) {
        return String((item && item.code) || '') + ' ' + String((item && item.color) || '');
      }).join(' · ').trim();
    }
    if (lang() !== 'id' || !raw) return raw;
    return raw.replace(/粉紅|深藍|咖啡|卡其|紅|粉|藍|白|黑|綠|紫|黃|棕|灰|米/g, function (word) {
      return COLOR_ID[word] || word;
    });
  }
  function batasText(row) {
    var copy = t();
    var days = row.remainingPickupDays;
    if (days == null) return copy.noDeadline;
    if (days < 0) return copy.overdueShort;
    if (days === 0) return copy.todayLimit;
    var date = String(row.pickupDeadline || '').slice(0, 10);
    return copy.leftover + days + copy.days + (date ? ' (' + date.slice(5).replace('-', '/') + ')' : '');
  }
  function simpleCardHtml(row, danger) {
    var copy = t();
    var phone = digits(row.phone);
    var tracking = String(row.trackingNo || '').trim();
    var store = String(row.store || row.carrier || '-').trim();
    if (lang() === 'id') store = store.replace(/全家/g, 'FamilyMart');
    var products = productText(row);
    var customerImg = String(row.customerImage || '').trim();
    var productImgs = (Array.isArray(row.items) ? row.items : []).map(function (item) {
      return String((item && item.image) || '').trim();
    }).filter(Boolean).filter(function (url, index, list) { return list.indexOf(url) === index; }).slice(0, 4);
    var photos = '';
    if (customerImg || productImgs.length) {
      photos = '<div class="cek-photos">' +
        (customerImg ? '<img class="is-customer" src="' + esc(customerImg) + '" alt="' + esc(row.customerName || '') + '" loading="lazy">' : '') +
        productImgs.map(function (url) {
          return '<img class="is-product" src="' + esc(url) + '" alt="" loading="lazy">';
        }).join('') +
      '</div>';
    }
    return '<article class="cek-simple' + (danger ? ' is-danger' : '') + (row.blacklisted ? ' is-blacklisted' : '') + '">' +
      photos +
      '<b class="cek-name">' + esc(row.customerName || '-') + tagsHtml(row) + '</b>' +
      '<p>' + esc(row.phone || copy.noPhone) + '</p>' +
      codHtml(row) +
      '<p>' + esc(copy.toko) + ': ' + esc(store || '-') + '</p>' +
      '<p>' + esc(copy.tracking) + ': ' + esc(tracking || copy.noTracking) + '</p>' +
      '<p class="cek-batas">' + esc(copy.deadline) + ': ' + esc(batasText(row)) + '</p>' +
      (products ? '<p>' + esc(copy.barang) + ': ' + esc(products) + '</p>' : '') +
      '<div class="cek-actions">' +
        (phone ? '<a class="is-call" href="tel:' + esc(phone) + '">' + esc(copy.call) + '</a>' : '<span></span>') +
        (tracking ? '<button type="button" data-cek-copy="' + esc(tracking) + '">' + esc(copy.copy) + '</button>' : '<span></span>') +
        lockButtonHtml(row, danger) +
      '</div>' +
    '</article>';
  }
  function riskCardHtml(row, danger) {
    var copy = t();
    var phone = digits(row.phone);
    var tracking = String(row.trackingNo || '').trim();
    var reason = String(row.blacklistReason || '').trim();
    var products = productText(row);
    var lastReturn = String(row.lastReturnAt || '').slice(0, 10);
    var n = Number(row.returnCount || 0);
    var customerImg = String(row.customerImage || '').trim();
    var photos = customerImg
      ? '<div class="cek-photos"><img class="is-customer" src="' + esc(customerImg) + '" alt="' + esc(row.customerName || '') + '" loading="lazy"></div>'
      : '';
    var cls = 'cek-simple' + (danger ? ' is-danger' : '') + (row.blacklisted || row.kind === 'blacklist' ? ' is-blacklisted' : '') + (row.kind === 'returned_customer' ? ' is-returned-customer' : '');
    return '<article class="' + cls + '">' +
      photos +
      '<b class="cek-name">' + esc(row.customerName || '-') + tagsHtml(row) + '</b>' +
      '<p>' + esc(row.phone || copy.noPhone) + '</p>' +
      (Number(row.codAmount) > 0 ? codHtml(row) : '') +
      (reason ? '<p class="cek-reason">' + esc(copy.reason) + ': ' + esc(reason) + '</p>' : '') +
      (n > 0 ? '<p>' + esc(copy.returnCount) + ': ' + n + (lastReturn ? ' · ' + esc(copy.lastReturn) + ' ' + esc(lastReturn) : '') + '</p>' : (lastReturn ? '<p>' + esc(copy.lastReturn) + ': ' + esc(lastReturn) + '</p>' : '')) +
      (row.orderId ? '<p>' + esc(copy.order) + ': ' + esc(row.orderId) + '</p>' : '') +
      (tracking ? '<p>' + esc(copy.tracking) + ': ' + esc(tracking) + '</p>' : '') +
      (products ? '<p>' + esc(copy.barang) + ': ' + esc(products) + '</p>' : '') +
      '<div class="cek-actions">' +
        (phone ? '<a class="is-call" href="tel:' + esc(phone) + '">' + esc(copy.call) + '</a>' : '<span></span>') +
        (tracking ? '<button type="button" data-cek-copy="' + esc(tracking) + '">' + esc(copy.copy) + '</button>' : '<span></span>') +
        lockButtonHtml(row, false) +
      '</div>' +
    '</article>';
  }
  function boardBlockHtml(cls, title, hint, rows, cardFn) {
    var copy = t();
    return '<section class="cek-board-block' + (cls ? ' ' + cls : '') + '">' +
      '<h3>' + esc(title) + ' (' + rows.length + ')</h3>' +
      '<p>' + esc(hint) + '</p>' +
      (rows.length ? rows.map(cardFn).join('') : '<p class="cek-board-empty">' + esc(copy.riskEmpty) + '</p>') +
    '</section>';
  }
  var TAB_KEY = 'lingzanzan-cek-tab';
  var boardTab = (function () {
    try {
      var saved = localStorage.getItem(TAB_KEY);
      if (saved === 'blacklist' || saved === 'returned' || saved === 'pickup') return saved;
    } catch (error) {}
    return 'pickup';
  })();
  function renderTabs() {
    var box = $('[data-cek-tabs]');
    if (!box) return;
    var copy = t();
    var data = boardCache;
    var pickN = data ? ((data.returning || []).length + (data.waiting || []).length) : 0;
    var blackN = data && Array.isArray(data.blacklist) ? data.blacklist.length : 0;
    var retN = data && Array.isArray(data.returnedCustomers) ? data.returnedCustomers.length : 0;
    var tabs = [
      ['pickup', copy.tabPickup, pickN],
      ['blacklist', copy.tabBlacklist, blackN],
      ['returned', copy.tabReturned, retN]
    ];
    box.innerHTML = tabs.map(function (tab) {
      return '<button type="button" data-cek-tab="' + tab[0] + '"' + (boardTab === tab[0] ? ' class="is-active"' : '') + '>' +
        esc(tab[1]) + (tab[2] ? ' (' + tab[2] + ')' : '') + '</button>';
    }).join('');
  }
  function setBoardTab(tab, opts) {
    opts = opts || {};
    if (tab !== 'blacklist' && tab !== 'returned') tab = 'pickup';
    boardTab = tab;
    try { localStorage.setItem(TAB_KEY, tab); } catch (error) {}
    if (opts.clearSearch) {
      if (liveSearchTimer) window.clearTimeout(liveSearchTimer);
      if (liveSearchAbort && typeof liveSearchAbort.abort === 'function') {
        try { liveSearchAbort.abort(); } catch (error) {}
      }
      var input = $('[data-cek-query]');
      if (input) input.value = '';
      lastSearchRows = [];
      var list = $('[data-cek-list]');
      if (list) list.innerHTML = '';
      setStatus('');
    }
    renderTabs();
    showBoard(true);
    renderBoard(boardCache);
  }
  function renderBoard(data) {
    var box = $('[data-cek-board]');
    if (!box) return;
    var copy = t();
    renderTabs();
    if (!data || !data.ok) {
      box.innerHTML = '<h2>' + esc(copy.boardTitle) + '</h2><p class="cek-board-status">' + esc(copy.boardFail) + '</p>';
      return;
    }
    var returning = Array.isArray(data.returning) ? data.returning : [];
    var waiting = Array.isArray(data.waiting) ? data.waiting : [];
    var blacklist = Array.isArray(data.blacklist) ? data.blacklist : [];
    var returned = Array.isArray(data.returnedCustomers) ? data.returnedCustomers : [];
    if (boardTab === 'blacklist') {
      box.innerHTML = '<h2>' + esc(copy.blacklistTitle) + ' (' + blacklist.length + ')</h2>' +
        '<p class="cek-board-status">' + esc(copy.blacklistListHint) + '</p>' +
        (blacklist.length
          ? blacklist.map(function (row) { return riskCardHtml(row, true); }).join('')
          : '<p class="cek-board-empty">' + esc(copy.riskEmpty) + '</p>');
      return;
    }
    if (boardTab === 'returned') {
      box.innerHTML = '<h2>' + esc(copy.returnedTitle) + ' (' + returned.length + ')</h2>' +
        '<p class="cek-board-status">' + esc(copy.returnedHint) + '</p>' +
        (returned.length
          ? returned.map(function (row) { return riskCardHtml(row, false); }).join('')
          : '<p class="cek-board-empty">' + esc(copy.riskEmpty) + '</p>');
      return;
    }
    var hasPickup = returning.length || waiting.length;
    if (!hasPickup) {
      box.innerHTML = '<h2>' + esc(copy.boardTitle) + '</h2><p class="cek-board-status">' + esc(copy.boardEmpty) + '</p>';
      return;
    }
    box.innerHTML =
      '<h2>' + esc(copy.boardTitle) + '</h2>' +
      boardBlockHtml('is-return', copy.returningTitle, copy.returningHint, returning, function (row) { return simpleCardHtml(row, true); }) +
      boardBlockHtml('', copy.waitingTitle, copy.waitingHint, waiting, function (row) { return simpleCardHtml(row, false); });
  }
  function loadBoard() {
    var box = $('[data-cek-board]');
    if (!box) return;
    box.innerHTML = '<p class="cek-board-status">' + esc(t().boardLoading) + '</p>';
    fetch(apiUrl('board=1'), { cache: 'no-store' })
      .then(readApi)
      .then(function (data) {
        boardCache = data;
        renderBoard(data);
      })
      .catch(function () {
        boardCache = null;
        renderBoard(null);
      });
  }
  function renderRecent() {
    var box = $('[data-cek-recent]');
    if (!box) return;
    var rows = loadRecent();
    if (!rows.length) {
      box.hidden = true;
      box.innerHTML = '';
      return;
    }
    box.hidden = false;
    box.innerHTML = '<button type="button" disabled>' + esc(t().recent) + '</button>' + rows.map(function (item) {
      return '<button type="button" data-cek-recent-item="' + esc(item) + '">' + esc(item) + '</button>';
    }).join('');
  }
  function localize() {
    var copy = t();
    document.documentElement.lang = lang() === 'zh' ? 'zh-Hant' : 'id';
    var map = [
      ['[data-cek-title]', 'title'],
      ['[data-cek-sub]', 'sub'],
      ['[data-cek-label]', 'label'],
      ['[data-cek-hint]', 'hint'],
      ['[data-cek-addhome]', 'addhome']
    ];
    map.forEach(function (pair) {
      var el = $(pair[0]);
      if (el) el.textContent = copy[pair[1]];
    });
    var input = $('[data-cek-query]');
    if (input) input.placeholder = copy.placeholder;
    var submit = $('[data-cek-submit]');
    if (submit) submit.textContent = copy.submit;
    document.querySelectorAll('[data-cek-lang]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-cek-lang') === lang());
    });
    renderRecent();
    renderTabs();
    if (boardCache) renderBoard(boardCache);
    var list = $('[data-cek-list]');
    if (list && lastSearchRows.length) list.innerHTML = lastSearchRows.map(resultCardHtml).join('');
  }
  var liveSearchTimer = 0;
  var liveSearchAbort = null;
  function queryTooShort(query) {
    var raw = String(query || '').trim();
    var num = digits(raw);
    var compact = raw.replace(/[\s-]+/g, '');
    if (num && num === compact) return num.length < 3;
    return raw.length < 2;
  }
  function showBoard(show) {
    var board = $('[data-cek-board]');
    if (board) board.hidden = !show;
  }
  function search(query, opts) {
    opts = opts || {};
    query = String(query || '').trim();
    var input = $('[data-cek-query]');
    var button = $('[data-cek-submit]');
    var list = $('[data-cek-list]');
    if (input && !opts.keepTyping) input.value = query;
    if (queryTooShort(query)) {
      lastSearchRows = [];
      if (list) list.innerHTML = '';
      showBoard(true);
      if (!opts.live) {
        setStatus(t().need, 'err');
        if (input) input.focus();
      } else {
        setStatus('');
      }
      return;
    }
    showBoard(false);
    if (liveSearchAbort && typeof liveSearchAbort.abort === 'function') {
      try { liveSearchAbort.abort(); } catch (error) {}
    }
    liveSearchAbort = typeof AbortController === 'function' ? new AbortController() : null;
    if (button) button.disabled = true;
    setStatus(t().searching);
    fetch(apiUrl('q=' + encodeURIComponent(query)), {
      cache: 'no-store',
      signal: liveSearchAbort ? liveSearchAbort.signal : undefined
    })
      .then(readApi)
      .then(function (data) {
        if (!opts.live) {
          saveRecent(query);
          renderRecent();
        }
        var rows = Array.isArray(data.results) ? data.results : [];
        lastSearchRows = rows;
        if (!list) return;
        if (!rows.length) {
          list.innerHTML = '';
          setStatus(t().empty, 'warn');
          return;
        }
        list.innerHTML = rows.map(resultCardHtml).join('');
        setStatus(t().found + ' ' + rows.length + (data.ambiguous ? ' · ' + t().many : ''), data.ambiguous ? 'warn' : 'ok');
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        if (list) list.innerHTML = '';
        setStatus(failText(error), 'err');
      })
      .then(function () {
        if (button) button.disabled = false;
      });
  }
  function scheduleLiveSearch(query) {
    if (liveSearchTimer) window.clearTimeout(liveSearchTimer);
    liveSearchTimer = window.setTimeout(function () {
      liveSearchTimer = 0;
      search(query, { live: true, keepTyping: true });
    }, 180);
  }

  var form = $('[data-cek-form]');
  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (liveSearchTimer) window.clearTimeout(liveSearchTimer);
      search(($('[data-cek-query]') || {}).value);
    });
  }
  var queryInput = $('[data-cek-query]');
  if (queryInput) {
    queryInput.addEventListener('input', function () {
      scheduleLiveSearch(queryInput.value);
    });
    queryInput.addEventListener('compositionend', function () {
      scheduleLiveSearch(queryInput.value);
    });
  }
  try { localize(); } catch (error) {}
  try { loadBoard(); } catch (error) {}
  document.addEventListener('click', function (event) {
    var tabBtn = event.target.closest('[data-cek-tab]');
    if (tabBtn) {
      setBoardTab(tabBtn.getAttribute('data-cek-tab') || 'pickup', { clearSearch: true });
      return;
    }
    var langBtn = event.target.closest('[data-cek-lang]');
    if (langBtn) {
      try {
        localStorage.setItem(LANG_KEY, langBtn.getAttribute('data-cek-lang') === 'zh' ? 'zh' : 'id');
      } catch (error) {}
      localize();
      var list = $('[data-cek-list]');
      if (list && $('[data-cek-query]').value.trim()) search($('[data-cek-query]').value);
      return;
    }
    var recent = event.target.closest('[data-cek-recent-item]');
    if (recent) {
      search(recent.getAttribute('data-cek-recent-item') || '');
      return;
    }
    var copyBtn = event.target.closest('[data-cek-copy]');
    if (copyBtn) {
      var text = copyBtn.getAttribute('data-cek-copy') || '';
      var done = function () {
        copyBtn.textContent = t().copied;
        setTimeout(function () { copyBtn.textContent = t().copy; }, 1200);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () { window.prompt(t().copy, text); });
      } else {
        window.prompt(t().copy, text);
      }
      return;
    }
    var lockBtn = event.target.closest('[data-cek-lock-phone]');
    if (lockBtn) {
      var phone = digits(lockBtn.getAttribute('data-cek-lock-phone'));
      var name = String(lockBtn.getAttribute('data-cek-lock-name') || '').trim();
      var reason = String(lockBtn.getAttribute('data-cek-lock-reason') || t().lockReasonSales).trim();
      if (!phone) {
        setStatus(t().lockNeedPhone, 'err');
        return;
      }
      if (!window.confirm(t().lockConfirm + '\n' + (name || '-') + ' / ' + phone)) return;
      lockBtn.disabled = true;
      fetch('./member-risk-api-v3.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save',
          phone: phone,
          name: name,
          reason: reason,
          createdBy: '業務查貨'
        })
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.error || 'fail');
          return data;
        });
      }).then(function () {
        function stamp(list) {
          (list || []).forEach(function (row) {
            if (digits(row.phone) === phone) row.blacklisted = true;
          });
        }
        if (boardCache) {
          stamp(boardCache.returning);
          stamp(boardCache.waiting);
          stamp(boardCache.blacklist);
          stamp(boardCache.returnedCustomers);
          var already = false;
          (boardCache.blacklist || []).forEach(function (row) {
            if (digits(row.phone) === phone) already = true;
          });
          if (!already) {
            boardCache.blacklist = [{
              kind: 'blacklist',
              state: 'blacklist',
              customerName: name,
              phone: phone,
              blacklisted: true,
              blacklistReason: reason,
              returnCount: 0
            }].concat(boardCache.blacklist || []);
          }
          renderBoard(boardCache);
        }
        stamp(lastSearchRows);
        var list = $('[data-cek-list]');
        if (list && lastSearchRows.length) list.innerHTML = lastSearchRows.map(resultCardHtml).join('');
        setStatus(t().lockOk, 'ok');
      }).catch(function () {
        lockBtn.disabled = false;
        setStatus(t().lockFail, 'err');
      });
    }
  });

  try {
    var boot = new URLSearchParams(location.search).get('q');
    if (boot) search(boot);
  } catch (error) {}
})();
