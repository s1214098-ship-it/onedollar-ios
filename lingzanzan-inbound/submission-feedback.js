(function () {
  'use strict';

  if (window.__lingzanzanSubmissionFeedbackInstalled) return;
  window.__lingzanzanSubmissionFeedbackInstalled = true;

  var lastNotice = { text: '', at: 0 };
  var activeWrites = 0;
  var PHONE_INPUT_SELECTOR = [
    'input[type="tel"]',
    'input[inputmode="tel"]',
    'input[name="phone"]',
    'input[name="customerPhone"]',
    'input[data-freight-customer-phone]',
    'input[data-live-customer-phone]',
    'input[data-member-phone]',
    'input[data-sales-phone]',
    'input[data-admin-shipment-phone]',
    'input[data-manual-customer-phone]',
    'input[data-return-customer-phone]'
  ].join(',');

  function phoneDigitsOnly(value) {
    var text = String(value || '');
    try { text = text.normalize('NFKC'); } catch (error) {}
    return text.replace(/\D/g, '');
  }

  function installNumericPhoneInputs() {
    document.querySelectorAll(PHONE_INPUT_SELECTOR).forEach(function (input) {
      input.setAttribute('inputmode', 'numeric');
      input.setAttribute('pattern', '[0-9]*');
      input.setAttribute('data-phone-digits-only', '1');
    });
  }

  document.addEventListener('input', function (event) {
    var input = event.target && event.target.matches && event.target.matches(PHONE_INPUT_SELECTOR) ? event.target : null;
    if (!input || event.isComposing) return;
    var digits = phoneDigitsOnly(input.value);
    if (digits === input.value) return;
    var cursor = input.selectionStart;
    input.value = digits;
    if (typeof cursor === 'number' && input.setSelectionRange) {
      input.setSelectionRange(Math.min(cursor, digits.length), Math.min(cursor, digits.length));
    }
  }, true);
  document.addEventListener('DOMContentLoaded', installNumericPhoneInputs);
  installNumericPhoneInputs();

  function isAdminPage() {
    return /(?:^|\/)admin(?:[-.]|$)/i.test(location.pathname || '');
  }

  function isIndonesian() {
    if (isAdminPage()) return false;
    var lang = String(document.documentElement.lang || '').toLowerCase();
    if (lang.indexOf('zh') === 0) return false;
    if (lang === 'id') return true;
    return localStorage.getItem('lingzanzan-business-lang') === 'id';
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }

  function ensureStyle() {
    if (document.querySelector('[data-submission-feedback-style]')) return;
    var style = document.createElement('style');
    style.setAttribute('data-submission-feedback-style', '');
    style.textContent = [
      '.submission-feedback{position:fixed;z-index:2147483600;top:20px;left:50%;transform:translate(-50%,-18px);min-width:min(420px,calc(100vw - 28px));max-width:min(680px,calc(100vw - 28px));padding:14px 18px;border:1px solid rgba(124,231,211,.8);border-radius:12px;background:#14342f;color:#f8fff9;box-shadow:0 18px 52px rgba(0,0,0,.42);font:700 16px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;opacity:0;pointer-events:none;transition:opacity .18s ease,transform .18s ease}',
      '.submission-feedback.is-visible{opacity:1;transform:translate(-50%,0)}',
      '.submission-feedback.is-pending{border-color:#ffd363;background:#40351c}',
      '.submission-feedback.is-error{border-color:#ff8c9a;background:#4a2028}',
      '.submission-feedback strong{display:block;font-size:17px}',
      '.submission-feedback small{display:block;margin-top:2px;color:rgba(255,255,255,.88);font-weight:600;white-space:normal;word-break:break-word}',
      '@media(max-width:640px){.submission-feedback{top:10px;padding:12px 14px;border-radius:10px;font-size:14px}.submission-feedback strong{font-size:15px}}'
    ].join('');
    document.head.appendChild(style);
  }

  function showNotice(title, detail, state) {
    ensureStyle();
    var noticeState = state === true ? 'error' : (state || 'success');
    clearTimeout(showNotice.pendingTimer);
    var text = title + '|' + (detail || '') + '|' + noticeState;
    var now = Date.now();
    if (lastNotice.text === text && now - lastNotice.at < 800) return;
    lastNotice = { text: text, at: now };
    var box = document.querySelector('[data-submission-feedback]');
    if (!box) {
      box = document.createElement('div');
      box.className = 'submission-feedback';
      box.setAttribute('data-submission-feedback', '');
      box.setAttribute('role', state === 'error' ? 'alert' : 'status');
      box.setAttribute('aria-live', state === 'error' ? 'assertive' : 'polite');
      document.body.appendChild(box);
    }
    box.setAttribute('role', noticeState === 'error' ? 'alert' : 'status');
    box.setAttribute('aria-live', noticeState === 'error' ? 'assertive' : 'polite');
    box.classList.toggle('is-error', noticeState === 'error');
    box.classList.toggle('is-pending', noticeState === 'pending');
    box.innerHTML = '<strong>' + escapeHtml(title) + '</strong>' + (detail ? '<small>' + escapeHtml(detail) + '</small>' : '');
    box.classList.remove('is-visible');
    void box.offsetWidth;
    box.classList.add('is-visible');
    clearTimeout(showNotice.timer);
    if (noticeState !== 'pending') {
      showNotice.timer = setTimeout(function () { box.classList.remove('is-visible'); }, noticeState === 'error' ? 6500 : 3800);
    } else {
      showNotice.pendingTimer = setTimeout(function () {
        if (!box.classList.contains('is-pending') || !box.classList.contains('is-visible')) return;
        showNotice(
          isIndonesian() ? 'Respons server terlalu lama' : '\u5f8c\u53f0\u56de\u61c9\u904e\u4e45',
          isIndonesian()
            ? 'Belum dipastikan tersimpan. Jangan kirim ulang; muat ulang lalu periksa.'
            : '\u5c1a\u672a\u78ba\u8a8d\u5132\u5b58\u6210\u529f\uff0c\u8acb\u52ff\u91cd\u8907\u9001\u51fa\uff1b\u91cd\u65b0\u6574\u7406\u5f8c\u518d\u6838\u5c0d\u3002',
          'error'
        );
      }, 20000);
    }
  }

  function requestInfo(input, options) {
    var url = typeof input === 'string' ? input : input && (input.url || input.href) || '';
    var method = String(options && options.method || input && input.method || 'GET').toUpperCase();
    var body = options && options.body;
    var action = '';
    try {
      var parsedUrl = new URL(url, location.href);
      action = parsedUrl.searchParams.get('action') || '';
      if (parsedUrl.origin !== location.origin) return null;
      // Activity telemetry owns its status indicator; it is not an order save.
      if (action === 'control_video_activity' && /\/admin-state-api-v3\.php$/i.test(parsedUrl.pathname)) return null;
      // Login verification reads the current session; it does not write order data.
      if (parsedUrl.searchParams.get('login') === '1') return null;
      if (/address-helper-api\.php$/i.test(parsedUrl.pathname || '')) return null;
    } catch (error) {}
    if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return null;
    if (typeof body === 'string') {
      try {
        var json = JSON.parse(body);
        action = json.action || action;
      } catch (error) {
        try { action = new URLSearchParams(body).get('action') || action; } catch (ignore) {}
      }
    } else if (typeof FormData !== 'undefined' && body instanceof FormData) {
      action = body.get('action') || action;
    }
    return { url: url, method: method, action: String(action || '').toLowerCase() };
  }

  function actionLabel(action) {
    var id = isIndonesian();
    if (/delete|remove|clear|cancel/.test(action)) return id ? 'menghapus data' : '刪除資料';
    if (/submit|send|confirm|approve|publish/.test(action)) return id ? 'mengirim data' : '送出資料';
    if (/receive|stock|inventory|import/.test(action)) return id ? 'menyimpan data stok' : '寫入庫存資料';
    if (/upsert-item|freight|split/.test(action)) return id ? 'menyimpan data logistik' : '寫入物流資料';
    if (/sync|tracking|report/.test(action)) return id ? 'memperbarui data' : '更新資料';
    return id ? 'menyimpan data' : '寫入資料';
  }

  function successTitle(action) {
    var id = isIndonesian();
    if (/delete|remove|clear|cancel/.test(action)) return id ? 'Data berhasil dihapus' : '資料刪除成功';
    if (/submit|send|confirm|approve|publish/.test(action)) return id ? 'Data berhasil dikirim' : '資料送出成功';
    if (/receive|stock|inventory|import/.test(action)) return id ? 'Data stok berhasil disimpan' : '庫存資料寫入成功';
    if (/upsert-item|freight|split/.test(action)) return id ? 'Data logistik berhasil disimpan' : '物流資料寫入成功';
    if (/sync|tracking|report/.test(action)) return id ? 'Data berhasil diperbarui' : '資料更新成功';
    return id ? 'Data berhasil disimpan' : '資料寫入成功';
  }

  function responseDetail(json) {
    if (!json || typeof json !== 'object') return '';
    var value = json.message || json.notice || json.successMessage || '';
    return typeof value === 'string' && value.length <= 180 ? value : '';
  }

  function responseError(json, response, rawText) {
    var value = json && (json.error || json.message || json.reason || json.detail);
    if (typeof value === 'string' && value.trim()) return value.trim().slice(0, 240);
    if (rawText && rawText.trim() && rawText.length <= 240) return rawText.trim();
    if (response && !response.ok) return 'HTTP ' + response.status + ' ' + (response.statusText || '');
    return isIndonesian() ? 'Sistem tidak menerima data. Silakan periksa lalu coba lagi.' : '系統未接受資料，請檢查後再試。';
  }

  function readResponse(response) {
    var contentType = String(response && response.headers && response.headers.get('content-type') || '').toLowerCase();
    var contentLength = Number(response && response.headers && response.headers.get('content-length') || 0);
    var canInspect = contentType.indexOf('json') !== -1 && !(contentLength > 32768);
    if (!canInspect) return Promise.resolve({ json: null, rawText: '' });

    var bodyRead = response.clone().text().then(function (rawText) {
      var json = null;
      if (rawText) {
        try { json = JSON.parse(rawText); } catch (error) {}
      }
      return { json: json, rawText: rawText || '' };
    }).catch(function () {
      return { json: null, rawText: '' };
    });

    var timeout = new Promise(function (resolve) {
      window.setTimeout(function () {
        resolve({ json: null, rawText: '' });
      }, 1500);
    });
    return Promise.race([bodyRead, timeout]);
  }

  window.LingzanzanSubmissionFeedback = { show: showNotice };
  if (typeof window.fetch !== 'function') return;
  var originalFetch = window.fetch.bind(window);

  window.fetch = function (input, options) {
    var info = requestInfo(input, options || {});
    if (info) {
      activeWrites += 1;
      showNotice(isIndonesian() ? 'Sedang diproses...' : '資料寫入中...', actionLabel(info.action), 'pending');
    }
    return originalFetch(input, options).then(function (response) {
      if (!info) return response;
      readResponse(response).then(function (payload) {
        activeWrites = Math.max(0, activeWrites - 1);
        var json = payload.json;
        var failed = !response.ok || json && (
          json.ok === false || json.success === false || json.status === 'error' || Boolean(json.error)
        );
        if (failed) {
          var failTitle = /delete|remove|clear|cancel/.test(info.action)
            ? (isIndonesian() ? 'Data gagal dihapus' : '資料刪除失敗')
            : (isIndonesian() ? 'Data gagal disimpan' : '資料未儲存');
          showNotice(
            failTitle,
            responseError(json, response, payload.rawText),
            'error'
          );
          return;
        }
        showNotice(successTitle(info.action), responseDetail(json), 'success');
      });
      return response;
    }).catch(function (error) {
      if (info) {
        activeWrites = Math.max(0, activeWrites - 1);
        // A deliberately cancelled obsolete request is not a backend outage.
        if (!(error && error.name === 'AbortError')) {
          showNotice(
            isIndonesian() ? 'Koneksi gagal' : '後台連線失敗',
            error && error.message || (isIndonesian() ? 'Silakan coba lagi.' : '請稍後再試。'),
            'error'
          );
        } else if (activeWrites === 0) {
          var pendingBox = document.querySelector('[data-submission-feedback]');
          if (pendingBox && pendingBox.classList.contains('is-pending')) {
            clearTimeout(showNotice.pendingTimer);
            pendingBox.classList.remove('is-visible');
          }
        }
      }
      throw error;
    });
  };
}());
