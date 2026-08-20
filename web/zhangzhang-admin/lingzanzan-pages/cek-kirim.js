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
      hint: 'Ketik nomor telepon pelanggan, 4 angka terakhir, atau nomor resi. Lalu tekan tombol besar.',
      addhome: 'Di HP: buka tautan ini → bagikan → Tambah ke Layar Utama. Nanti cukup ketuk ikon.',
      recent: 'Baru dicari',
      searching: 'Mencari…',
      need: 'Ketik minimal 3 angka telepon atau nomor resi.',
      empty: 'Tidak ketemu. Coba telepon lengkap, 4 angka terakhir, atau nomor resi.',
      fail: 'Gagal cek. Coba lagi.',
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
      states: {
        pending: 'Menunggu dikirim',
        ready: 'Siap dikirim',
        shipped: 'Dalam pengiriman',
        in_transit: 'Dalam pengiriman',
        arrived_store: 'Sudah di toko, belum diambil',
        delivered: 'Sudah diterima / diambil',
        returned: 'Dikembalikan'
      }
    },
    zh: {
      title: '查貨',
      sub: '不用登入。輸入電話或物流單號。',
      label: '電話／物流單號',
      placeholder: '電話或物流單號',
      submit: '立刻查貨',
      hint: '輸入客人電話、電話後四碼，或物流單號，再按金色大按鈕。',
      addhome: '手機：打開這個網址 → 分享 → 加入主畫面。以後點圖示就能查。',
      recent: '最近查過',
      searching: '查詢中…',
      need: '至少輸入 3 碼電話或物流單號。',
      empty: '找不到。改打完整電話、後四碼或物流單號。',
      fail: '查詢失敗，再試一次。',
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
      states: {
        pending: '待出貨',
        ready: '待出貨',
        shipped: '配送中',
        in_transit: '配送中',
        arrived_store: '已到門市待取',
        delivered: '已送達／已取件',
        returned: '已退回'
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
    return localStorage.getItem(LANG_KEY) === 'zh' ? 'zh' : 'id';
  }
  function t() {
    return COPY[lang()];
  }
  function digits(value) {
    return String(value || '').replace(/\D+/g, '');
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
    var next = [query].concat(loadRecent().filter(function (item) { return item !== query; })).slice(0, 8);
    localStorage.setItem(RECENT_KEY, JSON.stringify(next));
  }
  function setStatus(message, kind) {
    var el = $('[data-cek-status]');
    el.textContent = message || '';
    el.className = 'cek-status' + (kind ? ' is-' + kind : '');
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
  function cardHtml(row) {
    var copy = t();
    var phone = digits(row.phone);
    var tracking = String(row.trackingNo || '').trim();
    var cls = 'cek-card is-' + esc(row.state || 'pending');
    return '<article class="' + cls + '">' +
      '<header><div><h2>' + esc(row.customerName || '-') + '</h2><p class="phone">' + esc(row.phone || copy.noPhone) + '</p></div><span class="cek-state">' + esc(stateLabel(row)) + '</span></header>' +
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
      '</div>' +
    '</article>';
  }
  function renderRecent() {
    var box = $('[data-cek-recent]');
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
    $('[data-cek-title]').textContent = copy.title;
    $('[data-cek-sub]').textContent = copy.sub;
    $('[data-cek-label]').textContent = copy.label;
    $('[data-cek-query]').placeholder = copy.placeholder;
    $('[data-cek-submit]').textContent = copy.submit;
    $('[data-cek-hint]').textContent = copy.hint;
    $('[data-cek-addhome]').textContent = copy.addhome;
    document.querySelectorAll('[data-cek-lang]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.getAttribute('data-cek-lang') === lang());
    });
    renderRecent();
  }
  function search(query) {
    query = String(query || '').trim();
    var input = $('[data-cek-query]');
    var button = $('[data-cek-submit]');
    var list = $('[data-cek-list]');
    input.value = query;
    if (query.length < 2) {
      setStatus(t().need, 'err');
      input.focus();
      return;
    }
    button.disabled = true;
    setStatus(t().searching);
    fetch('./customer-shipping-lookup-api.php?q=' + encodeURIComponent(query), { cache: 'no-store' })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.error || 'fail');
          return data;
        });
      })
      .then(function (data) {
        saveRecent(query);
        renderRecent();
        var rows = Array.isArray(data.results) ? data.results : [];
        if (!rows.length) {
          list.innerHTML = '';
          setStatus(t().empty, 'warn');
          return;
        }
        list.innerHTML = rows.map(cardHtml).join('');
        setStatus(t().found + ' ' + rows.length + (data.ambiguous ? ' · ' + t().many : ''), data.ambiguous ? 'warn' : 'ok');
      })
      .catch(function () {
        list.innerHTML = '';
        setStatus(t().fail, 'err');
      })
      .finally(function () {
        button.disabled = false;
      });
  }

  localize();
  $('[data-cek-form]').addEventListener('submit', function (event) {
    event.preventDefault();
    search($('[data-cek-query]').value);
  });
  document.addEventListener('click', function (event) {
    var langBtn = event.target.closest('[data-cek-lang]');
    if (langBtn) {
      localStorage.setItem(LANG_KEY, langBtn.getAttribute('data-cek-lang') === 'zh' ? 'zh' : 'id');
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
    }
  });

  try {
    var boot = new URLSearchParams(location.search).get('q');
    if (boot) search(boot);
  } catch (error) {}
})();
