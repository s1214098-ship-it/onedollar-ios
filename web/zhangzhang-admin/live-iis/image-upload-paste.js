/**
 * 所有圖片上傳欄：可貼上（Ctrl+V / 按鈕）與拖放。
 * 既有「貼上圖片」區塊不會重複加按鈕；仍可用 Ctrl+V。
 */
(function (global) {
  if (global.__lingzanzanImageUploadPasteBound) return;
  global.__lingzanzanImageUploadPasteBound = true;

  var lastInput = null;
  var lastInputAt = 0;
  var overlay = null;

  function notify(message) {
    if (typeof global.toast === 'function') global.toast(message);
    else if (typeof global.showToast === 'function') global.showToast(message);
  }

  function isImageFileInput(input) {
    if (!input || input.tagName !== 'INPUT') return false;
    if (String(input.type || '').toLowerCase() !== 'file') return false;
    if (input.hasAttribute('data-clipboard-paste-file')) return false;
    if (input.closest('[data-clipboard-paste-dialog]')) return false;
    var accept = String(input.accept || '').toLowerCase();
    if (accept.indexOf('video/') !== -1 && accept.indexOf('image') === -1) return false;
    if (!accept || accept === '*/*') {
      var hint = [
        input.name || '',
        input.id || '',
        input.className || '',
        input.getAttribute('data-admin-image-search-file') != null ? 'image' : '',
        Array.prototype.map.call(input.attributes || [], function (attr) { return attr.name + ' ' + attr.value; }).join(' ')
      ].join(' ').toLowerCase();
      return /image|photo|screenshot|picture|截圖|圖片|主圖|縮圖/.test(hint);
    }
    return /image|png|jpe?g|gif|webp|bmp/.test(accept);
  }

  function asFile(blob, index) {
    if (blob instanceof File) return blob;
    var type = String(blob && blob.type || 'image/png');
    var ext = (type.split('/')[1] || 'png').split(';')[0].split('+')[0] || 'png';
    return new File([blob], 'paste-' + Date.now() + '-' + index + '.' + ext, { type: type });
  }

  function filesFromList(list) {
    return Array.prototype.slice.call(list || []).filter(function (file) {
      return file && String(file.type || '').indexOf('image/') === 0;
    }).map(asFile);
  }

  function filesFromClipboardEvent(event) {
    var data = event && event.clipboardData;
    if (!data) return [];
    var files = filesFromList(data.files);
    if (files.length) return files;
    Array.prototype.forEach.call(data.items || [], function (item, index) {
      if (item && item.kind === 'file' && String(item.type || '').indexOf('image/') === 0) {
        var file = item.getAsFile();
        if (file) files.push(asFile(file, index));
      }
    });
    return files;
  }

  function readClipboardFiles() {
    if (!navigator.clipboard || !navigator.clipboard.read) return Promise.resolve([]);
    return navigator.clipboard.read().then(function (items) {
      var jobs = [];
      Array.prototype.forEach.call(items || [], function (item, itemIndex) {
        Array.prototype.forEach.call(item.types || [], function (type) {
          if (String(type || '').indexOf('image/') === 0) {
            jobs.push(item.getType(type).then(function (blob) { return asFile(blob, itemIndex); }));
          }
        });
      });
      return Promise.all(jobs);
    }).catch(function () { return []; });
  }

  function assignFiles(input, files) {
    files = (files || []).filter(Boolean);
    if (!input || !files.length) return false;
    if (!input.multiple) files = files.slice(0, 1);
    try {
      var transfer = new DataTransfer();
      files.forEach(function (file) { transfer.items.add(file); });
      input.files = transfer.files;
    } catch (error) {
      notify('這個瀏覽器無法直接貼到上傳欄，請改用選擇檔案');
      return false;
    }
    remember(input);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    notify(files.length > 1 ? '已貼上 ' + files.length + ' 張圖片' : '已貼上圖片');
    return true;
  }

  function remember(input) {
    if (!isImageFileInput(input)) return;
    lastInput = input;
    lastInputAt = Date.now();
  }

  function dedicatedPasteZone(node) {
    if (!node || !node.closest) return null;
    return node.closest([
      '[data-clipboard-paste-dialog]',
      '[data-freight-fifo-customer-image-paste-zone]',
      '[data-freight-fifo-payment-proof]',
      '[data-admin-live-customer-dropzone]',
      '[data-admin-shipment-customer-dropzone]',
      '[data-manual-customer-photo-dropzone]'
    ].join(','));
  }

  function isTypingField(node) {
    if (!node || !node.closest) return false;
    if (dedicatedPasteZone(node)) return false;
    if (node.closest('[data-image-upload-paste-zone]')) return false;
    var tag = String(node.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'select') return true;
    if (tag === 'input') {
      var type = String(node.type || 'text').toLowerCase();
      return type !== 'file' && type !== 'button' && type !== 'submit' && type !== 'checkbox' && type !== 'radio';
    }
    return !!node.isContentEditable;
  }

  function shouldAddButton(input) {
    var node = input.parentElement;
    for (var depth = 0; depth < 3 && node; depth += 1, node = node.parentElement) {
      var buttons = node.querySelectorAll('button');
      for (var i = 0; i < buttons.length; i += 1) {
        var text = String(buttons[i].textContent || '').replace(/\s+/g, '');
        if (buttons[i].hasAttribute('data-image-upload-paste') || /貼上(圖片|截圖)/.test(text)) {
          var files = node.querySelectorAll('input[type="file"]');
          var imageFiles = 0;
          Array.prototype.forEach.call(files, function (fileInput) {
            if (isImageFileInput(fileInput)) imageFiles += 1;
          });
          if (imageFiles <= 1) return false;
        }
      }
    }
    return true;
  }

  function ensureStyles() {
    if (document.getElementById('image-upload-paste-style')) return;
    var style = document.createElement('style');
    style.id = 'image-upload-paste-style';
    style.textContent = [
      '.image-upload-paste-button{margin-left:.4rem;white-space:nowrap;}',
      'label .image-upload-paste-button{margin-top:.35rem;}',
      '[data-image-upload-paste-zone].is-image-paste-hot{outline:2px dashed #2e7d32;outline-offset:2px;background:rgba(46,125,50,.06);}',
      '.image-upload-paste-overlay{position:fixed;inset:0;z-index:99999;background:rgba(8,18,12,.55);display:flex;align-items:center;justify-content:center;padding:1.5rem;}',
      '.image-upload-paste-overlay[hidden]{display:none;}',
      '.image-upload-paste-overlay section{background:#102018;color:#e8f5e9;border-radius:16px;max-width:28rem;width:100%;padding:1.25rem 1.4rem;box-shadow:0 16px 48px rgba(0,0,0,.35);}',
      '.image-upload-paste-overlay h3{margin:0 0 .35rem;font-size:1.15rem;}',
      '.image-upload-paste-overlay p{margin:0 0 1rem;line-height:1.5;}',
      '.image-upload-paste-target{min-height:7rem;border:2px dashed #81c784;border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.35rem;padding:1rem;cursor:text;}',
      '.image-upload-paste-target:focus{outline:none;background:rgba(129,199,132,.12);}'
    ].join('');
    document.head.appendChild(style);
  }

  function hideOverlay() {
    if (overlay) overlay.hidden = true;
  }

  function showOverlay(input) {
    ensureStyles();
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'image-upload-paste-overlay';
      overlay.innerHTML = '<section><header style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start"><div><h3>貼上圖片</h3><p>請在方框內按 Ctrl+V，Windows 截圖也可以。</p></div><button type="button" class="ghost-button" data-image-paste-overlay-close>關閉</button></header><div class="image-upload-paste-target" tabindex="0" contenteditable="true" data-image-paste-overlay-target>點這裡，按 Ctrl+V</div></section>';
      overlay.addEventListener('click', function (event) {
        if (event.target === overlay || event.target.closest('[data-image-paste-overlay-close]')) hideOverlay();
      });
      overlay.addEventListener('paste', function (event) {
        var files = filesFromClipboardEvent(event);
        if (!files.length) return;
        event.preventDefault();
        var targetInput = overlay._imagePasteInput;
        hideOverlay();
        if (targetInput) assignFiles(targetInput, files);
      });
      document.body.appendChild(overlay);
    }
    overlay._imagePasteInput = input;
    overlay.hidden = false;
    var target = overlay.querySelector('[data-image-paste-overlay-target]');
    if (target) setTimeout(function () { target.focus(); }, 0);
  }

  function pasteIntoInput(input, button) {
    if (!input) return;
    remember(input);
    if (button) {
      button.disabled = true;
      button.textContent = '等待貼上…';
    }
    readClipboardFiles().then(function (files) {
      if (files.length) {
        assignFiles(input, files);
        return;
      }
      showOverlay(input);
    }).finally(function () {
      if (!button) return;
      button.disabled = false;
      button.textContent = '貼上圖片';
    });
  }

  function enhanceInput(input) {
    if (!isImageFileInput(input) || input.getAttribute('data-image-paste-bound') === '1') return;
    input.setAttribute('data-image-paste-bound', '1');
    ensureStyles();
    var host = input.closest('label') || input.parentElement || input;
    if (host && host.getAttribute) {
      host.setAttribute('data-image-upload-paste-zone', '');
      if (!host.hasAttribute('tabindex')) host.setAttribute('tabindex', '0');
    }
    input.addEventListener('focus', function () { remember(input); });
    input.addEventListener('click', function () { remember(input); });
    if (host && host.addEventListener) {
      host.addEventListener('pointerdown', function () { remember(input); });
      host.addEventListener('dragover', function (event) {
        if (!event.dataTransfer) return;
        event.preventDefault();
        host.classList.add('is-image-paste-hot');
      });
      host.addEventListener('dragleave', function () { host.classList.remove('is-image-paste-hot'); });
      host.addEventListener('drop', function (event) {
        host.classList.remove('is-image-paste-hot');
        var files = filesFromList(event.dataTransfer && event.dataTransfer.files);
        if (!files.length) return;
        event.preventDefault();
        event.stopPropagation();
        assignFiles(input, files);
      });
      host.addEventListener('paste', function (event) {
        if (dedicatedPasteZone(event.target)) return;
        var files = filesFromClipboardEvent(event);
        if (!files.length) return;
        event.preventDefault();
        event.stopPropagation();
        assignFiles(input, files);
      });
    }
    if (!shouldAddButton(input)) return;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = (document.querySelector('.ghost-button') ? 'ghost-button ' : '') + 'image-upload-paste-button';
    button.setAttribute('data-image-upload-paste', '');
    button.textContent = '貼上圖片';
    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      pasteIntoInput(input, button);
    });
    if (input.parentNode) {
      if (input.nextSibling) input.parentNode.insertBefore(button, input.nextSibling);
      else input.parentNode.appendChild(button);
    }
  }

  function scan(root) {
    root = root && root.querySelectorAll ? root : document;
    Array.prototype.forEach.call(root.querySelectorAll('input[type="file"]'), enhanceInput);
    if (root !== document && isImageFileInput(root)) enhanceInput(root);
  }

  document.addEventListener('paste', function (event) {
    if (event.defaultPrevented) return;
    if (dedicatedPasteZone(event.target)) return;
    if (overlay && !overlay.hidden && overlay.contains(event.target)) return;
    var files = filesFromClipboardEvent(event);
    if (!files.length) return;
    var zone = event.target && event.target.closest ? event.target.closest('[data-image-upload-paste-zone]') : null;
    var input = zone ? zone.querySelector('input[type="file"]') : null;
    if (!isImageFileInput(input) && lastInput && document.contains(lastInput) && (Date.now() - lastInputAt) < 20000 && !isTypingField(event.target)) {
      input = lastInput;
    }
    if (!isImageFileInput(input)) return;
    event.preventDefault();
    assignFiles(input, files);
  }, true);

  document.addEventListener('pointerdown', function (event) {
    var zone = event.target && event.target.closest ? event.target.closest('[data-image-upload-paste-zone]') : null;
    var input = zone && zone.querySelector('input[type="file"]');
    if (isImageFileInput(input)) remember(input);
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(document); });
  } else {
    scan(document);
  }

  if (typeof MutationObserver === 'function') {
    new MutationObserver(function (records) {
      records.forEach(function (record) {
        Array.prototype.forEach.call(record.addedNodes || [], function (node) {
          if (!node || node.nodeType !== 1) return;
          scan(node);
        });
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  global.LingzanzanImagePaste = { scan: scan, assignFiles: assignFiles };
})(window);
