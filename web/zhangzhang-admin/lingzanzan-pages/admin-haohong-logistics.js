(function () {
  'use strict';

  var LOGIN_KEY = 'lingzanzan-v1-admin-login';
  var state = { batches: [], packages: [], snapshotAt: '', hasSnapshot: false, mode: 'batches' };

  function $(selector) {
    return document.querySelector(selector);
  }

  function login() {
    try { return JSON.parse(localStorage.getItem(LOGIN_KEY) || '{}'); } catch (error) { return {}; }
  }

  function headers(base) {
    var out = Object.assign({ 'Content-Type': 'application/json' }, base || {});
    var token = login().sessionToken;
    if (token) {
      out.Authorization = 'Bearer ' + token;
      out['X-Lingzanzan-Admin-Session'] = token;
    }
    return out;
  }

  function toast(message) {
    var el = $('[data-admin-toast]');
    if (!el) return;
    el.hidden = false;
    el.textContent = message;
    clearTimeout(el.__timer);
    el.__timer = setTimeout(function () { el.hidden = true; }, 3200);
  }

  function setStatus(text) {
    var el = $('[data-haohong-status]');
    if (el) el.textContent = text;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function compareClass(label) {
    if (label === '已對上' || (label && label.indexOf('已帶入') !== -1)) return 'is-matched';
    if (label && label.indexOf('未帶入') !== -1) return 'is-missing';
    if (label && (label.indexOf('不同') !== -1 || label.indexOf('沒有') !== -1)) return 'is-diff';
    return '';
  }

  function hay(row) {
    return Object.keys(row || {}).map(function (key) { return String(row[key] == null ? '' : row[key]); }).join(' ').toLowerCase();
  }

  function filteredRows() {
    var q = String(($('[data-haohong-search]') || {}).value || '').trim().toLowerCase();
    var filter = String(($('[data-haohong-filter]') || {}).value || 'all');
    var rows = state.mode === 'packages' ? state.packages : state.batches;
    return rows.filter(function (row) {
      if (q && hay(row).indexOf(q) === -1) return false;
      var label = String(row.compare || '');
      if (filter === 'matched') return label === '已對上' || label.indexOf('已帶入') !== -1;
      if (filter === 'remote-only') return label.indexOf('豪鴻有') !== -1;
      if (filter === 'local-only') return label.indexOf('後台有') !== -1 && label.indexOf('沒有') !== -1;
      if (filter === 'diff') return label.indexOf('不同') !== -1;
      return true;
    });
  }

  function renderSummary() {
    var el = $('[data-haohong-summary]');
    if (!el) return;
    var remoteOnly = state.batches.filter(function (row) { return String(row.compare).indexOf('豪鴻有') !== -1; }).length;
    var matched = state.batches.filter(function (row) { return row.compare === '已對上'; }).length;
    el.innerHTML = [
      '<span>批次 ' + state.batches.length + '</span>',
      '<span>包裹 ' + state.packages.length + '</span>',
      '<span>已對上 ' + matched + '</span>',
      '<span>豪鴻有未帶入 ' + remoteOnly + '</span>',
      '<span>' + (state.hasSnapshot ? ('豪鴻清單 ' + (state.snapshotAt || '已抓')) : '尚未重抓豪鴻，目前只顯示後台已帶入') + '</span>'
    ].join('');
  }

  function renderTable() {
    var host = $('[data-haohong-table]');
    if (!host) return;
    var rows = filteredRows();
    if (!rows.length) {
      host.innerHTML = '<p class="haohong-logistics-empty">沒有符合的豪鴻資料。可改關鍵字，或按「從豪鴻重新抓」。</p>';
      return;
    }
    var html = '';
    if (state.mode === 'packages') {
      html = '<table class="haohong-logistics-table"><thead><tr>' +
        '<th>物流單號</th><th>批號</th><th>豪鴻品名</th><th>豪鴻狀態</th><th>倉別</th><th>計費重</th><th>後台商品</th><th>比對</th>' +
        '</tr></thead><tbody>' + rows.map(function (row) {
          return '<tr><td>' + escapeHtml(row.trackingNo) + '</td><td>' + escapeHtml(row.batchNo) + '</td><td>' + escapeHtml(row.productName) +
            '</td><td>' + escapeHtml(row.packageStatus) + '</td><td>' + escapeHtml(row.warehouse) +
            '</td><td>' + escapeHtml(row.billedWeightKg || 0) + ' kg</td><td>' + escapeHtml([row.backendCode, row.backendProduct].filter(Boolean).join(' ')) +
            '</td><td class="' + compareClass(row.compare) + '">' + escapeHtml(row.compare) + '</td></tr>';
        }).join('') + '</tbody></table>';
    } else {
      html = '<table class="haohong-logistics-table"><thead><tr>' +
        '<th>批號</th><th>豪鴻狀態</th><th>日期</th><th>轉運單</th><th>豪鴻包裹</th><th>計費重</th><th>運費</th><th>後台</th><th>後台狀態</th><th>比對</th>' +
        '</tr></thead><tbody>' + rows.map(function (row) {
          return '<tr><td><b>' + escapeHtml(row.batchNo) + '</b></td><td>' + escapeHtml(row.haohongStatus) +
            '</td><td>' + escapeHtml(String(row.orderDate || '').replace('T', ' ').slice(0, 16)) +
            '</td><td>' + escapeHtml(row.transferOrderNo) + '</td><td>' + escapeHtml(row.remotePackageCount) +
            '</td><td>' + escapeHtml(row.remoteBilledKg || 0) + ' kg</td><td>NT$' + escapeHtml(row.remoteFeeTwd || 0) +
            '</td><td>' + (row.inBackend ? escapeHtml(row.localId || '已帶入') : '未帶入') +
            '</td><td>' + escapeHtml(row.localStatus) +
            '</td><td class="' + compareClass(row.compare) + '">' + escapeHtml(row.compare) + '</td></tr>';
        }).join('') + '</tbody></table>';
    }
    host.innerHTML = html;
  }

  function paint() {
    renderSummary();
    renderTable();
  }

  function applyPayload(payload) {
    state.batches = payload.batches || [];
    state.packages = payload.packages || [];
    state.snapshotAt = payload.snapshotAt || '';
    state.hasSnapshot = !!payload.hasSnapshot;
    paint();
  }

  function loadList() {
    setStatus('正在讀取後台豪鴻批號與上次豪鴻清單…');
    return fetch('./haohong-logistics-api.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers()
    }).then(function (res) {
      return res.json().then(function (payload) {
        if (!res.ok || payload.ok === false) throw new Error(payload.error || '無法讀取豪鴻對照表');
        applyPayload(payload);
        setStatus(payload.hasSnapshot
          ? ('豪鴻清單時間：' + (payload.snapshotAt || '已抓') + '。可用搜尋比對批號／物流單。')
          : '目前先顯示後台已帶入的豪鴻批號。按「從豪鴻重新抓」才會跟豪鴻官網清單對上。');
        return payload;
      });
    }).catch(function (error) {
      setStatus(error.message || '讀取失敗');
      toast(error.message || '讀取失敗');
    });
  }

  function refreshFromHaohong() {
    var button = $('[data-haohong-refresh]');
    if (button) {
      button.disabled = true;
      button.textContent = '正在從豪鴻抓批號…';
    }
    setStatus('正在讀豪鴻「我的訂單／我的包裹」。只讀清單，不會改豪鴻網站上的批次集運。');
    fetch('./haohong-sync-api.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: headers(),
      body: JSON.stringify({ action: 'catalog', remember: true })
    }).then(function (res) {
      return res.json().then(function (payload) {
        if (!res.ok || payload.ok === false) throw new Error(payload.error || '豪鴻清單讀取失敗');
        return payload;
      });
    }).then(function (payload) {
      setStatus('豪鴻清單已抓到批次 ' + (payload.orders || []).length + '、包裹 ' + (payload.packages || []).length + '，正在跟後台比對…');
      return loadList();
    }).catch(function (error) {
      setStatus(error.message || '豪鴻清單讀取失敗');
      toast(error.message || '豪鴻清單讀取失敗');
    }).finally(function () {
      if (button) {
        button.disabled = false;
        button.textContent = '從豪鴻重新抓';
      }
    });
  }

  function bind() {
    var search = $('[data-haohong-search]');
    var filter = $('[data-haohong-filter]');
    var refresh = $('[data-haohong-refresh]');
    if (search) search.addEventListener('input', function () { paint(); });
    if (filter) filter.addEventListener('change', function () { paint(); });
    if (refresh) refresh.addEventListener('click', refreshFromHaohong);
    document.querySelectorAll('[data-haohong-mode]').forEach(function (button) {
      button.addEventListener('click', function () {
        state.mode = button.getAttribute('data-haohong-mode') || 'batches';
        document.querySelectorAll('[data-haohong-mode]').forEach(function (node) {
          node.classList.toggle('is-active', node === button);
        });
        paint();
      });
    });
    var q = new URLSearchParams(location.search).get('q');
    if (q && search) {
      search.value = q;
    }
  }

  bind();
  loadList();
})();
