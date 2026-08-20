(function () {
  'use strict';

  var LOGIN_KEY = 'lingzanzan-v1-admin-login';
  var state = { batches: [], packages: [], snapshotAt: '', hasSnapshot: false, mode: 'batches', expanded: {} };

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
    if (label === '已簽收完成') return 'is-signed-done';
    if (label === '已對上' || (label && label.indexOf('已帶入') !== -1)) return 'is-matched';
    if (label && (label.indexOf('未帶入') !== -1 || label.indexOf('未建檔') !== -1)) return 'is-missing';
    if (label && (label.indexOf('不同') !== -1 || label.indexOf('沒有') !== -1)) return 'is-diff';
    return '';
  }

  function isSignedRow(row) {
    if (row && row.signed === true) return true;
    var blob = [row && row.haohongStatus, row && row.packageStatus, row && row.localStatus, row && row.compare].map(function (value) {
      return String(value || '');
    }).join(' ');
    if (/待[簽签]收|未[簽签]收/.test(blob)) return false;
    return /已[簽签]收/.test(blob) || blob.indexOf('已簽收完成') !== -1;
  }

  function statusFilterValue() {
    var el = $('[data-haohong-status-filter]');
    return String((el && el.value) || 'unsigned');
  }

  function hay(row) {
    return Object.keys(row || {}).map(function (key) {
      var value = row[key];
      if (Array.isArray(value)) {
        return value.map(function (item) { return typeof item === 'object' ? hay(item) : String(item == null ? '' : item); }).join(' ');
      }
      if (value && typeof value === 'object') return '';
      return String(value == null ? '' : value);
    }).join(' ').toLowerCase();
  }

  function digitHit(haystack, q) {
    var compact = String(q || '').trim().replace(/\s+/g, '');
    var digits = compact.replace(/\D/g, '');
    if (!/^\d+$/.test(compact) || digits.length < 4) return false;
    var blob = String(haystack || '').replace(/\D/g, '');
    var keys = [digits];
    if (digits.length > 4) {
      keys.push(digits.slice(-4), digits.slice(0, 4), digits.slice(0, -1));
    }
    return keys.some(function (key) { return key.length >= 4 && blob.indexOf(key) !== -1; });
  }

  function searchQuery() {
    return String(($('[data-haohong-search]') || {}).value || '').trim().toLowerCase();
  }

  function batchId(row) {
    return String((row && (row.localId || row.haohongOrderId || row.batchNo)) || '');
  }

  function packagesForBatch(row) {
    if (Array.isArray(row && row.packages) && row.packages.length) return row.packages;
    var ids = [row && row.batchNo, row && row.haohongOrderId, row && row.localId].filter(Boolean).map(String);
    return (state.packages || []).filter(function (pkg) {
      return ids.indexOf(String(pkg.batchNo || '')) !== -1;
    });
  }

  function queryHits(haystack, q) {
    if (!q) return true;
    return String(haystack || '').indexOf(q) !== -1 || digitHit(haystack, q);
  }

  function queryHitsPackage(pkg, q) {
    return !!q && queryHits(hay(pkg), q);
  }

  function batchHay(row) {
    return hay(row) + ' ' + packagesForBatch(row).map(hay).join(' ');
  }

  function isBatchOpen(row, q) {
    var id = batchId(row);
    if (Object.prototype.hasOwnProperty.call(state.expanded, id)) return !!state.expanded[id];
    return !!(q && packagesForBatch(row).some(function (pkg) { return queryHitsPackage(pkg, q); }));
  }

  function filteredRows() {
    var q = searchQuery();
    var filter = String(($('[data-haohong-filter]') || {}).value || 'all');
    var statusFilter = statusFilterValue();
    var rows = state.mode === 'packages' ? state.packages : state.batches;
    return rows.filter(function (row) {
      var haystack = state.mode === 'batches' ? batchHay(row) : hay(row);
      if (q && !queryHits(haystack, q)) return false;
      var signed = isSignedRow(row);
      if (!q) {
        if (statusFilter === 'unsigned' && signed) return false;
        if (statusFilter === 'signed' && !signed) return false;
      } else if (statusFilter === 'unsigned' && signed) {
        return false;
      } else if (statusFilter === 'signed' && !signed) {
        return false;
      }
      var label = String(row.compare || '');
      if (filter === 'matched') return label === '已對上' || label.indexOf('已帶入') !== -1 || label === '已簽收完成';
      if (filter === 'remote-only') return !signed && label.indexOf('豪鴻有') !== -1;
      if (filter === 'local-only') return !signed && label.indexOf('後台有') !== -1 && label.indexOf('沒有') !== -1;
      if (filter === 'diff') return !signed && label.indexOf('不同') !== -1;
      return true;
    });
  }

  function renderBatchPackages(row, q) {
    var pkgs = packagesForBatch(row);
    var signed = isSignedRow(row);
    if (!pkgs.length) {
      return signed
        ? '<p class="haohong-batch-empty">已簽收完成。豪鴻已簽收，寶輝已驗收，不必再核對。</p>'
        : '<p class="haohong-batch-empty">這個批次目前沒有物流單可展開。可改看「包裹表」，或按「從豪鴻重新抓」。</p>';
    }
    if (signed) {
      return '<div class="haohong-batch-detail-head"><strong>已簽收完成</strong><span>豪鴻已簽收，寶輝已驗收，不必再核對品名或建檔。本批 ' + pkgs.length + ' 筆物流。</span></div>' +
        '<table class="haohong-batch-detail-table"><thead><tr>' +
        '<th>物流單號</th><th>豪鴻品名</th><th>豪鴻狀態</th><th>後台商品</th><th>比對</th>' +
        '</tr></thead><tbody>' + pkgs.map(function (pkg) {
          var hit = queryHitsPackage(pkg, q);
          return '<tr><td class="' + (hit ? 'haohong-hit-tracking' : '') + '">' + escapeHtml(pkg.trackingNo || '') +
            '</td><td>' + escapeHtml(pkg.productName || '') +
            '</td><td>' + escapeHtml(pkg.packageStatus || '已簽收完成') +
            '</td><td>' + escapeHtml([pkg.backendCode, pkg.backendProduct].filter(Boolean).join(' ')) +
            '</td><td class="is-signed-done">已簽收完成</td></tr>';
        }).join('') + '</tbody></table>';
    }
    var missing = pkgs.filter(function (pkg) { return String(pkg.compare || '').indexOf('未建檔') !== -1; }).length;
    var filed = pkgs.length - missing;
    return '<div class="haohong-batch-detail-head"><strong>本批物流 ' + pkgs.length + ' 筆</strong><span>後台商品 ' + filed + ' 筆' + (missing ? '／豪鴻有單未建檔 ' + missing + ' 筆' : '') + '。再點批號可收合</span></div>' +
      '<table class="haohong-batch-detail-table"><thead><tr>' +
      '<th>物流單號</th><th>豪鴻品名</th><th>豪鴻狀態</th><th>後台商品</th><th>比對</th>' +
      '</tr></thead><tbody>' + pkgs.map(function (pkg) {
        var hit = queryHitsPackage(pkg, q);
        return '<tr><td class="' + (hit ? 'haohong-hit-tracking' : '') + '">' + escapeHtml(pkg.trackingNo || '') +
          '</td><td>' + escapeHtml(pkg.productName || '') +
          '</td><td>' + escapeHtml(pkg.packageStatus || '') +
          '</td><td>' + escapeHtml([pkg.backendCode, pkg.backendProduct].filter(Boolean).join(' ')) +
          '</td><td class="' + compareClass(pkg.compare) + '">' + escapeHtml(pkg.compare || '') + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  function renderSummary() {
    var el = $('[data-haohong-summary]');
    if (!el) return;
    var unsigned = state.batches.filter(function (row) { return !isSignedRow(row); }).length;
    var signedDone = state.batches.filter(isSignedRow).length;
    var remoteOnly = state.batches.filter(function (row) { return !isSignedRow(row) && String(row.compare).indexOf('豪鴻有') !== -1; }).length;
    var matched = state.batches.filter(function (row) { return !isSignedRow(row) && row.compare === '已對上'; }).length;
    el.innerHTML = [
      '<span>未簽收 ' + unsigned + '</span>',
      '<span>已簽收完成 ' + signedDone + '</span>',
      '<span>未簽收已對上 ' + matched + '</span>',
      '<span>未簽收未帶入 ' + remoteOnly + '</span>',
      '<span>包裹 ' + state.packages.length + '</span>',
      '<span>' + (state.hasSnapshot ? ('豪鴻清單 ' + (state.snapshotAt || '已抓')) : '尚未重抓豪鴻，目前只顯示後台已帶入') + '</span>',
      state.mode === 'batches' ? '<button type="button" data-haohong-expand-all>全部展開</button><button type="button" data-haohong-collapse-all>全部收合</button>' : ''
    ].join('');
  }

  function renderTable() {
    var host = $('[data-haohong-table]');
    if (!host) return;
    var q = searchQuery();
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
          return '<tr><td class="' + (queryHitsPackage(row, q) ? 'haohong-hit-tracking' : '') + '">' + escapeHtml(row.trackingNo) + '</td><td>' + escapeHtml(row.batchNo) + '</td><td>' + escapeHtml(row.productName) +
            '</td><td>' + escapeHtml(row.packageStatus) + '</td><td>' + escapeHtml(row.warehouse) +
            '</td><td>' + escapeHtml(row.billedWeightKg || 0) + ' kg</td><td>' + escapeHtml([row.backendCode, row.backendProduct].filter(Boolean).join(' ')) +
            '</td><td class="' + compareClass(row.compare) + '">' + escapeHtml(row.compare) + '</td></tr>';
        }).join('') + '</tbody></table>';
    } else {
      html = '<table class="haohong-logistics-table"><thead><tr>' +
        '<th>批號</th><th>豪鴻狀態</th><th>日期</th><th>轉運單</th><th>豪鴻包裹</th><th>計費重</th><th>運費</th><th>後台</th><th>後台狀態</th><th>比對</th>' +
        '</tr></thead><tbody>' + rows.map(function (row) {
          var id = batchId(row);
          var open = isBatchOpen(row, q);
          var count = packagesForBatch(row).length;
          var main = '<tr class="haohong-batch-row' + (open ? ' is-open' : '') + '">' +
            '<td><button type="button" class="haohong-batch-toggle" data-haohong-expand="' + escapeHtml(id) + '" aria-expanded="' + (open ? 'true' : 'false') + '"><span class="haohong-batch-chevron"></span><b>' + escapeHtml(row.batchNo) + '</b><small>' + count + ' 筆物流</small></button></td>' +
            '<td>' + escapeHtml(row.haohongStatus) +
            '</td><td>' + escapeHtml(String(row.orderDate || '').replace('T', ' ').slice(0, 16)) +
            '</td><td>' + escapeHtml(row.transferOrderNo) + '</td><td>' + escapeHtml(row.remotePackageCount) +
            '</td><td>' + escapeHtml(row.remoteBilledKg || 0) + ' kg</td><td>NT$' + escapeHtml(row.remoteFeeTwd || 0) +
            '</td><td>' + (row.inBackend ? escapeHtml(row.localId || '已帶入') : '未帶入') +
            '</td><td>' + escapeHtml(row.localStatus) +
            '</td><td class="' + compareClass(row.compare) + '">' + escapeHtml(row.compare) + '</td></tr>';
          var detail = open
            ? '<tr class="haohong-batch-detail-row"><td colspan="10"><div class="haohong-batch-detail">' + renderBatchPackages(row, q) + '</div></td></tr>'
            : '';
          return main + detail;
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

  function setAllExpanded(open) {
    state.expanded = {};
    state.batches.forEach(function (row) {
      var id = batchId(row);
      if (id) state.expanded[id] = !!open;
    });
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
          ? ('預設只看未簽收。豪鴻清單時間：' + (payload.snapshotAt || '已抓') + '。已簽收的當已驗收完成。')
          : '預設只看未簽收。按「從豪鴻重新抓」才會跟豪鴻官網清單對上。');
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

  function ensureStatusFilter() {
    if ($('[data-haohong-status-filter]')) return;
    var tools = document.querySelector('.haohong-logistics-tools');
    var compare = $('[data-haohong-filter]');
    var compareLabel = compare && compare.closest('label');
    if (!tools || !compareLabel) return;
    var label = document.createElement('label');
    label.appendChild(document.createTextNode('狀態'));
    var sel = document.createElement('select');
    sel.setAttribute('data-haohong-status-filter', '');
    [['unsigned', '未簽收（要看）'], ['signed', '已簽收完成'], ['all', '全部']].forEach(function (opt) {
      var option = document.createElement('option');
      option.value = opt[0];
      option.textContent = opt[1];
      if (opt[0] === 'unsigned') option.selected = true;
      sel.appendChild(option);
    });
    label.appendChild(sel);
    tools.insertBefore(label, compareLabel);
  }

  function bind() {
    ensureStatusFilter();
    var search = $('[data-haohong-search]');
    var filter = $('[data-haohong-filter]');
    var statusFilter = $('[data-haohong-status-filter]');
    var refresh = $('[data-haohong-refresh]');
    if (search) search.addEventListener('input', function () { paint(); });
    if (filter) filter.addEventListener('change', function () { paint(); });
    if (statusFilter) statusFilter.addEventListener('change', function () { paint(); });
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
    document.addEventListener('click', function (event) {
      var expand = event.target.closest('[data-haohong-expand]');
      if (expand) {
        var id = String(expand.getAttribute('data-haohong-expand') || '');
        if (!id) return;
        var row = state.batches.filter(function (item) { return batchId(item) === id; })[0];
        state.expanded[id] = !(row && isBatchOpen(row, searchQuery()));
        paint();
        return;
      }
      if (event.target.closest('[data-haohong-expand-all]')) {
        setAllExpanded(true);
        return;
      }
      if (event.target.closest('[data-haohong-collapse-all]')) {
        setAllExpanded(false);
      }
    });
    var q = new URLSearchParams(location.search).get('q');
    if (q && search) {
      search.value = q;
    }
  }

  bind();
  loadList();
})();
