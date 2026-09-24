(function () {
  'use strict';

  var input = document.querySelector('[data-scan-input]');
  var manualInput = document.querySelector('[data-manual-scan-input]');
  var tabToggle = document.querySelector('[data-scan-tab-toggle]');
  var currentTabLabel = document.querySelector('[data-current-tab-label]');
  var screenTitle = document.querySelector('[data-scanner-screen-title]');
  var scanTabs = document.querySelector('[data-scan-tabs]');
  var message = document.querySelector('[data-scan-message]');
  var lastScanCode = document.querySelector('[data-last-scan-code]');
  var lastScanStatus = document.querySelector('[data-last-scan-status]');
  var matchPanel = document.querySelector('[data-match-panel]');
  var matchGrid = document.querySelector('[data-match-grid]');
  var operationPanel = document.querySelector('[data-operation-panel]');
  var warehouse = document.querySelector('[data-warehouse]');
  var lookupWarehouse = document.querySelector('[data-lookup-warehouse]');
  var sourceWarehouse = document.querySelector('[data-source-warehouse]');
  var targetWarehouse = document.querySelector('[data-target-warehouse]');
  var transferDepartmentMode = document.querySelector('[data-transfer-department-mode]');
  var arrivalWarehouse = document.querySelector('[data-arrival-warehouse]');
  var pendingPanel = document.querySelector('[data-pending-panel]');
  var pendingPurpose = 'stockin';
  var selected = null;
  var allMatches = [];
  var currentTab = 'stocktake';
  var requestedTab = (new URLSearchParams(window.location.search || '')).get('tab') || '';
  var draftKey = 'lingzanzan-scanner-draft-v3';
  var transferDraftKey = 'lingzanzan-scanner-transfer-draft-v1';
  var stockinDraftKey = 'lingzanzan-scanner-stockin-draft-v1';
  var stockinRequestKey = 'lingzanzan-scanner-stockin-request-v1';
  var stockinRecentCategoryKey = 'lingzanzan-scanner-stockin-recent-category-v1';
  var sessionKey = 'lingzanzan-scanner-session-v3';
  var loginKey = 'lingzanzan-v1-admin-login';
  var authVersion = 'password-login-v2';
  var loginTtl = 24 * 60 * 60 * 1000;
  // 工作紀錄只存在目前頁面的記憶體中；重開或重新整理後不保留。
  // 同時清掉舊版曾寫入瀏覽器的掃描顯示紀錄。
  try { localStorage.removeItem(draftKey); } catch (error) {}
  var draft = [];
  var transferDraft = readJson(transferDraftKey, []);
  if (!Array.isArray(transferDraft)) transferDraft = [];
  var computerTransferCategories = [];
  var computerTransferCategoriesLoading = false;
  var scannerOpenedAt = Date.now();
  // Android/YGF opens file pickers on top of the WebView.  The scanner's
  // periodic focus keeper must pause while that native window is open or it
  // steals focus back to the barcode field and closes the photo picker.
  var scannerMediaPickerOpen = false;
  var scannerMediaPickerTimer = 0;
  // The native APK must never fall back to Android's full-resolution camera
  // file chooser. Older handhelds can crash before JavaScript gets a chance to
  // resize the photo. APK photos use the low-resolution WebRTC path only.
  var scannerNativeApk = /LINGZANZANInventory\//i.test(navigator.userAgent || '')
    || /(?:^|[?&])apk=1(?:&|$)/.test(location.search || '');
  // YGF/Android native <select> does not keep document.activeElement on the
  // control. The barcode focus keeper must pause or the picker closes instantly.
  var scannerUiPickerOpen = false;
  var scannerUiPickerTimer = 0;
  var stockinDraft = readJson(stockinDraftKey, []);
  if (!Array.isArray(stockinDraft)) stockinDraft = [];
  stockinDraft.forEach(function (line) { if (line) line.qty = Math.max(1, Number(line.qty || 1)); });
  var stockinCategories = [];
  var stockinColors = [];
  var stockinCategoryPrefixes = {};
  var initialStockMode = { enabled: true, mode: 'initial_inventory_setup', stayOpen: true };
  var warehouses = [];
  var stockinShelves = [];
  var stockinLayers = [];
  var session = readJson(sessionKey, null);
  var operator = currentOperator();
  var stream = null;
  var scanning = false;
  var cameraScanner = null;
  var cameraLibraryPromise = null;
  var cameraSessionId = 0;
  var installPrompt = null;
  var inputLookupTimer = null;
  var scanInputMode = 'hardware';
  var hardwareScanBuffer = '';
  var hardwareScanTimer = null;
  var hardwareTerminatorTimer = null;
  var hardwareTerminatorDeadline = 0;
  var hardwareLastKeyAt = 0;
  var hardwareLastKeyDownAt = 0;
  // Older YGF units may commit a barcode in several slow IME chunks. Wait for
  // the full value to settle; Enter/Tab still flushes immediately.
  var hardwareScanDelayMs = 700;
  var lastLookupCode = '';
  var lastLookupAt = 0;
  var scanTabsCollapsed = false;
  var pendingStockinLinePhotoIndex = -1;
  var stocktakeFlowLock = { barcode: '', skuId: '', shelf: '', layer: '' };
  var stocktakeSharedLocation = session && session.stocktakeActiveLocation ? session.stocktakeActiveLocation : { warehouse: '', shelf: '', layer: '' };
  var stocktakeChangingLocation = false;
  var stocktakeAutoSubmitting = false;
  var stocktakeLiveCounts = {};
  var currentStocktakeScanCode = '';
  var lookupInFlight = {};
  var crossWarehouseContext = null;
  var queuedLookupCounts = {};
  var lastAcceptedHardwareCode = '';
  var lastAcceptedHardwareAt = 0;
  var recentExternalScanCodes = {};
  var ownSessionRows = [];
  var latestMissingStocktakeScan = null;

  function stocktakeFlowStatus(text) {
    var node = document.querySelector('[data-stocktake-flow-status]');
    if (node) node.textContent = text;
  }

  function selectedStocktakeLocation() {
    var locked = selected && stocktakeFlowLock.skuId === String(selected.skuId || '') && stocktakeFlowLock.shelf && stocktakeFlowLock.layer;
    return {
      shelf: String((locked ? stocktakeFlowLock.shelf : '') || ((document.querySelector('[data-match-shelf]') || {}).value) || (selected && selected.shelf) || '').trim(),
      layer: String((locked ? stocktakeFlowLock.layer : '') || ((document.querySelector('[data-match-layer]') || {}).value) || (selected && selected.layer) || '').trim()
    };
  }

  function canReuseStocktakeSharedLocation() {
    if (stocktakeChangingLocation || !stocktakeSharedLocation) return false;
    var shelf = String(stocktakeSharedLocation.shelf || '').trim();
    var layer = String(stocktakeSharedLocation.layer || '').trim();
    if (!shelf || !layer) return false;
    var sharedWarehouse = warehouseCodeOf(stocktakeSharedLocation.warehouse || '');
    var currentWarehouse = warehouseCodeOf(warehouse && warehouse.value || '');
    // 舊盤點單可能保存「台灣倉」，新選單則保存 TW；兩者應視為同一倉。
    // 若舊資料沒有倉庫代碼，只要同一張盤點單已有倉架與層架，也繼續沿用。
    return !sharedWarehouse || sharedWarehouse === 'all' || !currentWarehouse || currentWarehouse === 'all' || sharedWarehouse === currentWarehouse;
  }

  function revealMissingStocktakePhoto(row) {
    if (currentTab !== 'stocktake' || !row || String(row.image || '').trim()) return;
    var toggle = document.querySelector('[data-stocktake-photo-toggle]');
    if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.textContent = '此商品缺圖｜點此拍照或貼圖'; }
    stocktakeFlowStatus('此商品沒有圖片；需要時請點「此商品缺圖」補照片，不會自動遮住掃描畫面。');
  }

  function installStocktakePhotoBox() {
    var fields = document.querySelector('[data-stocktake-fields]');
    var confirmButton = document.querySelector('[data-confirm-stocktake]');
    if (!fields || !confirmButton || fields.querySelector('[data-stocktake-photo-box]')) return;
    var box = document.createElement('div');
    box.className = 'stocktake-photo-box';
    box.setAttribute('data-stocktake-photo-box', '');
    box.setAttribute('tabindex', '0');
    box.innerHTML = '<div><b>本筆商品照片</b><span data-stocktake-photo-status>手機／YGF 可直接拍照，電腦可選照片或貼上。</span></div><label>拍照／選照片<input data-stocktake-photo type="file" accept="image/*" capture="environment"></label><button type="button" data-stocktake-photo-clear>清除照片</button><img data-stocktake-photo-preview alt="盤點商品照片預覽" hidden>';
    fields.insertBefore(box, confirmButton);
    box.hidden = true;
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'stocktake-photo-toggle';
    toggle.setAttribute('data-stocktake-photo-toggle', '');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.textContent = '＋ 展開照片功能';
    fields.insertBefore(toggle, box);
    confirmButton.textContent = '加入本次盤點單並送出照片';
  }
  installStocktakePhotoBox();
  function enhanceScannerPhotoActions() {
    [
      {box: '[data-stocktake-photo-box]', upload: '[data-stocktake-photo]', camera: 'data-stocktake-camera-photo', paste: 'data-stocktake-photo-paste'},
      {box: '[data-stockin-photo-box]', upload: '[data-stockin-photo]', camera: 'data-stockin-camera-photo', paste: 'data-stockin-photo-paste'}
    ].forEach(function (config) {
      var box = document.querySelector(config.box);
      var upload = box && box.querySelector(config.upload);
      if (!box || !upload || box.querySelector('[' + config.camera + ']')) return;
      upload.removeAttribute('capture');
      var uploadLabel = upload.closest('label');
      if (uploadLabel) uploadLabel.firstChild.textContent = '選擇照片';
      var cameraLabel = null;
      if (!scannerNativeApk) {
        cameraLabel = document.createElement('label');
        cameraLabel.className = 'scanner-camera-photo-action';
        cameraLabel.innerHTML = '手機拍照上傳<input type="file" accept="image/*" capture="environment" ' + config.camera + '>';
      }
      var pasteButton = document.createElement('button');
      pasteButton.type = 'button';
      pasteButton.setAttribute(config.paste, '');
      pasteButton.textContent = '貼上複製圖片';
      if (cameraLabel) box.insertBefore(cameraLabel, box.querySelector('img'));
      box.insertBefore(pasteButton, box.querySelector('img'));
      if (!box.querySelector('[data-live-camera-photo]')) {
        var liveButton = document.createElement('button');
        liveButton.type = 'button';
        liveButton.className = 'scanner-live-camera-action';
        liveButton.setAttribute('data-live-camera-photo', config.box.indexOf('stockin') >= 0 ? 'stockin' : 'stocktake');
        liveButton.textContent = '開啟相機拍照';
        box.insertBefore(liveButton, box.querySelector('img'));
      }
      var role = document.createElement('div');
      role.className = 'scanner-photo-role';
      role.setAttribute('data-photo-role-group', config.box.indexOf('stockin') >= 0 ? 'stockin' : 'stocktake');
      role.innerHTML = '<b>照片用途</b><button type="button" data-photo-role="main">商品主圖</button><button type="button" class="is-active" data-photo-role="color">顏色圖</button>';
      box._photoRole = 'color';
      box.insertBefore(role, box.querySelector('img'));
    });
    var pendingInput = document.querySelector('[data-pending-photo]');
    var pendingLabel = pendingInput && pendingInput.closest('label');
    if (pendingInput && pendingLabel && !document.querySelector('[data-pending-camera-photo]')) {
      pendingInput.removeAttribute('capture');
      pendingLabel.firstChild.textContent = '選擇／上傳照片';
      var pendingCamera = null;
      if (!scannerNativeApk) {
        pendingCamera = document.createElement('label');
        pendingCamera.className = 'pending-camera-photo-action';
        pendingCamera.innerHTML = '手機／YGF 拍照上傳<input type="file" accept="image/*" capture="environment" data-pending-camera-photo>';
      }
      var pendingPaste = document.createElement('button');
      pendingPaste.type = 'button';
      pendingPaste.className = 'pending-paste-photo-action';
      pendingPaste.setAttribute('data-pending-photo-paste-button', '');
      pendingPaste.textContent = '貼上複製圖片';
      pendingLabel.insertAdjacentElement('afterend', pendingPaste);
      if (pendingCamera) pendingLabel.insertAdjacentElement('afterend', pendingCamera);
      var pendingLive = document.createElement('button');
      pendingLive.type = 'button';
      pendingLive.className = 'scanner-live-camera-action';
      pendingLive.setAttribute('data-live-camera-photo', 'pending');
      pendingLive.textContent = '開啟相機拍照';
      pendingPaste.insertAdjacentElement('afterend', pendingLive);
      var pendingRole = document.createElement('div');
      pendingRole.className = 'scanner-photo-role';
      pendingRole.setAttribute('data-photo-role-group', 'pending');
      pendingRole.innerHTML = '<b>照片用途</b><button type="button" data-photo-role="main">商品主圖</button><button type="button" class="is-active" data-photo-role="color">顏色圖</button>';
      pendingRole._photoRole = 'color';
      pendingLive.insertAdjacentElement('afterend', pendingRole);
    }
  }
  enhanceScannerPhotoActions();

  function applyCapturedPhoto(kind, dataUrl, label) {
    kind = resolveLivePhotoKind(kind);
    if (kind === 'stockin') setStockinBatchPhoto(dataUrl, label || '相機拍照');
    else if (kind === 'pending') {
      setPendingPastedPhoto(dataUrl, label || '相機拍照');
      savePending({ autoFromPhoto: true });
    } else if (String(kind || '').indexOf('stockin-line:') === 0) {
      setStockinLinePhoto(Number(kind.split(':')[1]), dataUrl, label || '相機拍照');
    } else {
      setStocktakePhoto(dataUrl, label || '相機拍照');
      if (selected) selected.image = dataUrl;
      renderStocktakeFastRows();
    }
  }

  function resolveLivePhotoKind(kind) {
    kind = String(kind || '');
    if (kind && kind !== 'current' && kind !== 'auto') return kind;
    if (pendingPanel && !pendingPanel.hidden) return 'pending';
    if (currentTab === 'stockin') return 'stockin';
    return 'stocktake';
  }

  function closeLivePhotoCamera() {
    if (livePhotoStream) {
      livePhotoStream.getTracks().forEach(function (track) { try { track.stop(); } catch (error) {} });
      livePhotoStream = null;
    }
    var overlay = document.querySelector('[data-live-camera-overlay]');
    if (overlay) overlay.hidden = true;
    livePhotoTarget = '';
    resumeScannerAfterMediaPicker(400);
  }

  function ensureLiveCameraOverlay() {
    var overlay = document.querySelector('[data-live-camera-overlay]');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'scanner-live-camera-overlay';
    overlay.setAttribute('data-live-camera-overlay', '');
    overlay.hidden = true;
    overlay.innerHTML = '<video data-live-camera-video autoplay playsinline muted></video><p data-live-camera-status>正在開啟相機…</p><div class="scanner-live-camera-bar"><button type="button" class="primary" data-live-camera-shutter>拍照</button><button type="button" data-live-camera-close>關閉</button></div>';
    document.body.appendChild(overlay);
    overlay.querySelector('[data-live-camera-close]').addEventListener('click', closeLivePhotoCamera);
    overlay.querySelector('[data-live-camera-shutter]').addEventListener('click', function () {
      var video = overlay.querySelector('[data-live-camera-video]');
      var status = overlay.querySelector('[data-live-camera-status]');
      if (!video || !video.videoWidth) {
        if (status) status.textContent = '相機尚未就緒，請再等一秒';
        return;
      }
      try {
        scannerCanvasDataUrl(video, video.videoWidth, video.videoHeight).then(function (dataUrl) {
          var kind = livePhotoTarget;
          closeLivePhotoCamera();
          applyCapturedPhoto(kind, dataUrl, '相機拍照');
        }).catch(function (error) {
          if (status) status.textContent = error.message || '拍照失敗';
          setMessage(error.message || '拍照失敗', 'error');
        });
      } catch (error) {
        setMessage(error.message || '拍照失敗', 'error');
      }
    });
    return overlay;
  }

  function fallbackFileCameraInput(kind) {
    kind = resolveLivePhotoKind(kind);
    if (String(kind).indexOf('stockin-line:') === 0) {
      var lineIndex = Number(kind.split(':')[1]);
      return document.querySelector('[data-stockin-line-photo="' + lineIndex + '"]');
    }
    if (kind === 'stockin') return document.querySelector('[data-stockin-camera-photo]') || document.querySelector('[data-stockin-photo]');
    if (kind === 'pending') return document.querySelector('[data-pending-camera-photo]') || document.querySelector('[data-pending-photo]');
    return document.querySelector('[data-stocktake-camera-photo]') || document.querySelector('[data-fast-stocktake-photo]') || document.querySelector('[data-stocktake-photo]');
  }

  function openLivePhotoCamera(kind) {
    livePhotoTarget = resolveLivePhotoKind(kind);
    scannerMediaPickerOpen = true;
    closeCamera();
    var overlay = ensureLiveCameraOverlay();
    var video = overlay.querySelector('[data-live-camera-video]');
    var status = overlay.querySelector('[data-live-camera-status]');
    overlay.hidden = false;
    if (status) status.textContent = '正在開啟相機…';
    function useFileCameraFallback(reason) {
      if (status) status.textContent = reason || '改用檔案拍照';
      if (scannerNativeApk) {
        closeLivePhotoCamera();
        setMessage((reason || '無法開啟相機') + '。請到系統設定允許「LINGZANZAN盤點機新版」使用相機，再重新開啟。', 'error');
        return;
      }
      var fallbackTarget = livePhotoTarget;
      closeLivePhotoCamera();
      var fileInput = fallbackFileCameraInput(fallbackTarget);
      if (fileInput) {
        try { fileInput.click(); } catch (error) {}
        setMessage('這台盤點機改用系統相機拍照；拍完會自動縮小，避免當機。', 'ok');
        return;
      }
      setMessage(reason || '這台盤點機尚未開放相機。請確認 APK／瀏覽器允許相機後再試。', 'error');
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      useFileCameraFallback('這台盤點機瀏覽器沒有即時相機，改用系統拍照。');
      return;
    }
    var constraints = scannerIsHandheld()
      ? { video: { facingMode: { ideal: 'environment' }, width: { ideal: 640, max: 640 }, height: { ideal: 480, max: 480 }, frameRate: { ideal: 15, max: 20 } }, audio: false }
      : { video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false };
    navigator.mediaDevices.getUserMedia(constraints).catch(function () {
      if (scannerNativeApk) {
        return navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 480, max: 640 }, height: { ideal: 360, max: 480 } }, audio: false });
      }
      return navigator.mediaDevices.getUserMedia({ video: true, audio: false });
    }).then(function (mediaStream) {
      livePhotoStream = mediaStream;
      if (video) {
        video.srcObject = mediaStream;
        var play = video.play();
        if (play && play.catch) play.catch(function () {});
      }
      if (status) status.textContent = '對準商品後按「拍照」；完成後會自動縮小，不會當機。';
    }).catch(function (error) {
      useFileCameraFallback(cameraErrorText(error));
    });
  }

  // Handheld scanners have much less memory than a desktop browser. Reading a
  // 12–30 MP camera photo directly as Base64 keeps the compressed file, the
  // Base64 copy and the decoded bitmap alive at the same time and can make the
  // Android WebView process crash. Read only the image header first, ask
  // createImageBitmap to resize during decode, then Base64-encode the small
  // result that is actually needed for product verification.
  function scannerIsHandheld() {
    return /Android|Linux armv|; wv\)|YGF|Urovo|Seuic|Newland|iMin/i.test(navigator.userAgent || '')
      || (window.innerWidth <= 820 && 'ontouchstart' in window);
  }
  var scannerPhotoMaxEdge = scannerIsHandheld() ? 720 : 1280;
  var scannerPhotoJpegQuality = scannerIsHandheld() ? 0.68 : 0.78;
  var scannerPhotoMaxInputBytes = scannerIsHandheld() ? 4 * 1024 * 1024 : 32 * 1024 * 1024;
  var livePhotoStream = null;
  var livePhotoTarget = '';

  function scannerReadBlobBuffer(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(new Error('照片檔案讀取失敗')); };
      reader.readAsArrayBuffer(blob);
    });
  }

  function scannerImageHeaderSize(file) {
    return scannerReadBlobBuffer(file.slice(0, Math.min(file.size || 0, 1024 * 1024))).then(function (buffer) {
      var bytes = new Uint8Array(buffer || new ArrayBuffer(0));
      var view = new DataView(buffer || new ArrayBuffer(0));
      if (bytes.length >= 24 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
        return { width: view.getUint32(16, false), height: view.getUint32(20, false) };
      }
      if (bytes.length >= 10 && bytes[0] === 0xff && bytes[1] === 0xd8) {
        var offset = 2;
        while (offset + 9 < bytes.length) {
          if (bytes[offset] !== 0xff) { offset += 1; continue; }
          var marker = bytes[offset + 1];
          if (marker === 0xd8 || marker === 0xd9 || marker === 0x01) { offset += 2; continue; }
          var length = (bytes[offset + 2] << 8) + bytes[offset + 3];
          if (length < 2 || offset + 2 + length > bytes.length) break;
          if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].indexOf(marker) !== -1) {
            return {
              width: (bytes[offset + 7] << 8) + bytes[offset + 8],
              height: (bytes[offset + 5] << 8) + bytes[offset + 6]
            };
          }
          offset += 2 + length;
        }
      }
      return { width: 0, height: 0 };
    });
  }

  function scannerPhotoTargetSize(width, height) {
    width = Math.max(1, Number(width || 1));
    height = Math.max(1, Number(height || 1));
    var scale = Math.min(1, scannerPhotoMaxEdge / Math.max(width, height));
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
  }

  function scannerCanvasDataUrl(source, width, height) {
    var target = scannerPhotoTargetSize(width, height);
    var canvas = document.createElement('canvas');
    canvas.width = target.width;
    canvas.height = target.height;
    var context = canvas.getContext('2d');
    if (!context) return Promise.reject(new Error('盤點機無法建立照片縮圖'));
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, target.width, target.height);
    context.drawImage(source, 0, 0, target.width, target.height);
    if (source && typeof source.close === 'function') source.close();
    return new Promise(function (resolve, reject) {
      function finish(blob) {
        canvas.width = 1;
        canvas.height = 1;
        if (!blob) { reject(new Error('照片壓縮失敗')); return; }
        var reader = new FileReader();
        reader.onload = function () { resolve(String(reader.result || '')); };
        reader.onerror = function () { reject(new Error('縮圖讀取失敗')); };
        reader.readAsDataURL(blob);
      }
      if (typeof canvas.toBlob === 'function') canvas.toBlob(finish, 'image/jpeg', scannerPhotoJpegQuality);
      else {
        try {
          var dataUrl = canvas.toDataURL('image/jpeg', scannerPhotoJpegQuality);
          canvas.width = 1;
          canvas.height = 1;
          resolve(dataUrl);
        } catch (error) { reject(error); }
      }
    });
  }

  function scannerImageWithObjectUrl(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var image = new Image();
      image.onload = function () {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error('不支援這張照片格式，請改用 JPG 或 PNG'));
      };
      image.src = url;
    });
  }

  function scannerImageFileToDataUrl(file) {
    if (!file) return Promise.reject(new Error('請選擇圖片檔案'));
    var typeHint = String(file.type || file.name || '');
    if (/heic|heif/i.test(typeHint)) return Promise.reject(new Error('盤點機不支援 HEIC，請改拍 JPG'));
    if (file.type && !/^image\//i.test(file.type)) return Promise.reject(new Error('請選擇圖片檔案'));
    if (Number(file.size || 0) > scannerPhotoMaxInputBytes) {
      return Promise.reject(new Error('照片太大，請用「開啟相機拍照」或降低畫質後再放圖'));
    }
    setMessage('正在縮小照片，完成前請勿關閉盤點機。', 'ok');
    var handheld = scannerIsHandheld();
    return scannerImageHeaderSize(file).catch(function () { return { width: 0, height: 0 }; }).then(function (header) {
      var target = header.width > 0 && header.height > 0
        ? scannerPhotoTargetSize(header.width, header.height)
        : { width: scannerPhotoMaxEdge, height: scannerPhotoMaxEdge };
      var chromeMatch = String(navigator.userAgent || '').match(/(?:Chrome|Chromium)\/(\d+)/i);
      var bitmapResizeSupported = !handheld || (chromeMatch && Number(chromeMatch[1]) >= 80);
      if (handheld && !bitmapResizeSupported && Math.max(header.width || 0, header.height || 0) > 2048) {
        throw new Error('這張照片解析度太高，請改用「開啟相機拍照」。');
      }
      if (typeof createImageBitmap === 'function' && bitmapResizeSupported) {
        var options = { imageOrientation: 'from-image', resizeWidth: target.width, resizeQuality: 'low' };
        if (header.width > 0 && header.height > 0) options.resizeHeight = target.height;
        return createImageBitmap(file, options).then(function (bitmap) {
          return scannerCanvasDataUrl(bitmap, bitmap.width || target.width, bitmap.height || target.height);
        }).catch(function () {
          // Handheld WebViews crash if Image() decodes a full-resolution JPEG.
          if (handheld) throw new Error('這張照片無法在盤點機縮小。請改按「開啟相機拍照」。');
          return scannerImageWithObjectUrl(file).then(function (image) {
            return scannerCanvasDataUrl(image, image.naturalWidth || image.width, image.naturalHeight || image.height);
          });
        });
      }
      if (handheld) throw new Error('這台盤點機無法縮小照片。請改按「開啟相機拍照」。');
      return scannerImageWithObjectUrl(file).then(function (image) {
        return scannerCanvasDataUrl(image, image.naturalWidth || image.width, image.naturalHeight || image.height);
      });
    }).then(function (dataUrl) {
      if (!dataUrl) throw new Error('照片壓縮後沒有資料');
      return dataUrl;
    });
  }

  function installStocktakeFastBoard() {
    if (!matchPanel || document.querySelector('[data-stocktake-fast-board]')) return;
    var board = document.createElement('section');
    board.className = 'stocktake-fast-board';
    board.setAttribute('data-stocktake-fast-board', '');
    board.hidden = true;
    board.innerHTML = '<header><b>本次盤點明細</b><small>每個商品／位置固定一橫排；同條碼連刷只增加數量。</small><button type="button" data-clear-current-stocktake>清除全部本次盤點</button></header><div data-stocktake-fast-rows></div>';
    if (operationPanel) operationPanel.insertAdjacentElement('afterend', board);
    else matchPanel.insertAdjacentElement('afterend', board);
    var fastRowsHost = board.querySelector('[data-stocktake-fast-rows]');
    fastRowsHost.style.maxHeight = '55vh';
    fastRowsHost.style.overflowY = 'auto';
    var title = matchPanel.querySelector('h2');
    if (title) title.textContent = '辨識不到唯一規格｜請選產品';
  }
  installStocktakeFastBoard();

  function installMissingStocktakeRecovery() {
    var actions = document.querySelector('[data-session-actions]');
    if (!actions || document.querySelector('[data-missing-stocktake-recovery]')) return;
    var recovery = document.createElement('section');
    recovery.setAttribute('data-missing-stocktake-recovery', '');
    recovery.hidden = true;
    recovery.style.cssText = 'margin:10px 0;padding:10px;border:1px solid #d58a00;border-radius:10px;background:#fff8e6';
    recovery.innerHTML = '<b>找回當掉前未完成的建檔</b><small style="display:block;margin:4px 0 8px">只恢復條碼與建檔畫面，不會送審或修改庫存。</small><button type="button" class="primary" data-restore-missing-stocktake>恢復最後一筆</button>';
    actions.insertAdjacentElement('beforebegin', recovery);
  }
  installMissingStocktakeRecovery();

  function missingStocktakeDisplayCode(value) {
    var raw = String(value == null ? '' : value).trim();
    var normalized = normalizeScanCode(raw);
    if (!normalized) return '未辨識條碼';
    if (normalized.length <= 28) return normalized;
    return normalized.slice(0, 12) + '…' + normalized.slice(-8);
  }

  function updateMissingStocktakeRecoveryButton(button, entry) {
    if (!button || !entry) return;
    var displayCode = missingStocktakeDisplayCode(entry.scanCode);
    button.textContent = '';
    button.classList.add('missing-stocktake-restore-button');
    var label = document.createElement('span');
    label.textContent = '找回最後一筆建檔草稿';
    var code = document.createElement('small');
    code.textContent = displayCode;
    button.appendChild(label);
    button.appendChild(code);
    button.setAttribute('aria-label', '找回最後一筆建檔草稿，條碼 ' + displayCode);
  }

  function loadLatestMissingStocktakeScan() {
    var account = operator.account || operator.name || '';
    if (!account || operator.role === 'unknown') return;
    fetch('./scanner-api.php?mode=last_missing_stocktake&operator=' + encodeURIComponent(account) + '&warehouse=' + encodeURIComponent(warehouse.value || '') + '&_=' + Date.now(), {cache:'no-store'})
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        latestMissingStocktakeScan = payload && payload.entry ? payload.entry : null;
        var recovery = document.querySelector('[data-missing-stocktake-recovery]');
        var button = document.querySelector('[data-restore-missing-stocktake]');
        if (!recovery || !button) return;
        recovery.hidden = !latestMissingStocktakeScan;
        if (latestMissingStocktakeScan) updateMissingStocktakeRecoveryButton(button, latestMissingStocktakeScan);
      })
      .catch(function () {});
  }

  function stocktakeFastRowKey(row, shelf, layer) {
    return [row && (row.skuId || row.barcode || row.productCode) || '', shelf || '', layer || ''].join('|');
  }

  function renderStocktakeFastRows() {
    var board = document.querySelector('[data-stocktake-fast-board]');
    var host = document.querySelector('[data-stocktake-fast-rows]');
    if (!board || !host) return;
    var rows = [], seen = {};
    draft.map(function (line, draftIndex) { return {line:line,index:draftIndex}; }).sort(function (a,b) {
      return (Date.parse(b.line.at)||0)-(Date.parse(a.line.at)||0) || a.index-b.index;
    }).forEach(function (indexed) {
      var line=indexed.line, draftIndex=indexed.index;
      if (!(line && line.action === 'stocktake' && line.at && new Date(line.at).getTime() >= scannerOpenedAt)) return;
      var key = stocktakeFastRowKey(line, line.shelf, line.layer);
      if (seen[key]) return;
      seen[key] = true;
      rows.push({ row: line, qty: Number(line.qty || 0), shelf: line.shelf || '', layer: line.layer || '', complete: true, draftIndex: draftIndex });
    });
    if (selected) {
      var loc = selectedStocktakeLocation();
      var currentKey = stocktakeFastRowKey(selected, loc.shelf, loc.layer);
      rows = rows.filter(function (entry) { return stocktakeFastRowKey(entry.row, entry.shelf, entry.layer) !== currentKey; });
      rows.unshift({ row: selected, qty: Number((document.querySelector('[data-qty]') || {}).value || 1), shelf: loc.shelf, layer: loc.layer, complete: false });
    }
    board.hidden = currentTab !== 'stocktake' || !rows.length;
    host.scrollTop = 0;
    host.innerHTML = rows.map(function (entry) {
      var row = entry.row || {};
      var waitingForSetup = !entry.complete && currentTab === 'stocktake' && currentStocktakeScanCode && !(stocktakeFlowLock.barcode === currentStocktakeScanCode && stocktakeFlowLock.skuId === String(row.skuId || '') && stocktakeFlowLock.shelf && stocktakeFlowLock.layer);
      var colorMigrationWarning = !entry.complete && row.colorConfirmationRequired && !row.colorConfirmed ? '<strong class="warning">舊顏色「' + escapeHtml(row.legacyColor || '-') + '」已清除；請按「更換顏色／尺寸」並由行政確認實品顏色後才能完成。</strong>' : '';
      var editTools = entry.complete ? '' : '<div class="stocktake-fast-edit-tools"><span>實品顏色：<b>' + escapeHtml(row.color || '未設定') + '</b></span>' + colorMigrationWarning + '<button type="button" class="scanner-live-camera-action" data-live-camera-photo="stocktake">開啟相機拍照</button><label>更換照片<input type="file" accept="image/*" capture="environment" data-no-auto-paste data-fast-stocktake-photo></label><button type="button" data-fast-change-variant>更換顏色／尺寸</button><small>新照片會設為目前顏色的照片，送主管核准後更新。盤點機請用「開啟相機拍照」，不要放原圖。</small></div>';
      return '<article class="stocktake-fast-row' + (entry.complete ? ' is-complete' : ' is-current') + '"><img src="' + escapeHtml(resolveImage(row.image || './assets/brand-icon-192.png')) + '" onerror="this.onerror=null;this.src=\'./assets/brand-icon-192.png\'" alt="商品"><span class="identity"><small>條碼</small><b>' + escapeHtml(row.labelBarcode || row.scannedBarcode || row.barcode || row.skuId || row.productCode || '-') + '</b></span><span><small>顏色／尺寸</small><b>' + escapeHtml((row.color || '-') + '／' + stockinCanonicalSize(row.size)) + '</b>' + (row.crossWarehouseReference ? '<small class="cross-wh-note">資料來自' + escapeHtml(row.referenceWarehouse || '其他倉') + '，目前倉帳面 0；列在本次盤點，核准前不改庫存</small>' : '') + '</span><label class="fast-qty"><small>數量</small><span><button type="button" data-fast-qty-minus ' + (entry.complete ? 'disabled' : '') + '>−</button><input type="number" min="0" step="1" value="' + entry.qty + '" ' + (entry.complete ? 'readonly' : 'data-fast-stocktake-qty') + '><button type="button" data-fast-qty-plus ' + (entry.complete ? 'disabled' : '') + '>＋</button></span></label><span><small>倉架位置</small><b>' + escapeHtml(waitingForSetup ? '確認商品後選擇' : (entry.shelf && entry.layer ? entry.shelf + '／' + entry.layer : '尚未選擇')) + '</b></span>' + (entry.complete ? '<strong class="done">✓ 已盤點</strong><button type="button" data-remove-stocktake-entry="' + entry.draftIndex + '">刪除這筆</button>' : '<button type="button" data-fast-change-location>' + (waitingForSetup ? '確認產品' : (entry.shelf && entry.layer ? '更正本筆位置' : '選倉位／層架')) + '</button>' + editTools + '<button type="button" data-fast-split-location>同產品另放一處（新增）</button><button type="button" class="primary" data-fast-confirm-stocktake>完成這一件</button><button type="button" data-cancel-current-stocktake>清除這個產品</button>') + '<button type="button" class="stocktake-print-label" data-print-stocktake-label="' + (entry.complete ? entry.draftIndex : 'current') + '">列印條碼標籤</button>' + '</article>';
    }).join('');
  }

  function openStocktakeVariantEditor() {
    if (!selected) { setMessage('請先掃描商品，再更換顏色或尺寸。', 'error'); return; }
    var originalScanCode = String(currentStocktakeScanCode || selected.scannedBarcode || selected.barcode || selected.skuId || '').trim();
    var productCode = String(selected.productCode || '').trim();
    if (!productCode) { setMessage('找不到商品系列碼，請重新掃描。', 'error'); return; }
    setMessage('正在讀取 ' + productCode + ' 的顏色與尺寸…');
    fetchWithTimeout('./scanner-api.php?q=' + encodeURIComponent(productCode) + '&warehouse=' + encodeURIComponent(activeWarehouse()) + '&_=' + Date.now(), {cache:'no-store'}, 20000)
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        var rows = payload && Array.isArray(payload.rows) ? payload.rows : [];
        rows = rows.filter(function (row) { return String(row.productCode || '') === productCode; });
        if (!rows.length) throw new Error('找不到可切換的顏色或尺寸');
        rows.forEach(function (row) { row.scannedBarcode = originalScanCode; });
        renderMatches(rows);
        var currentVariantIndex = allMatches.findIndex(function (row) { return String(row.skuId || '') === String(selected.skuId || ''); });
        var colorSelect = document.querySelector('[data-match-color-select]');
        if (colorSelect && currentVariantIndex >= 0) colorSelect.value = String(currentVariantIndex);
        if (matchPanel) matchPanel.hidden = false;
        if (matchGrid) matchGrid.hidden = false;
        var locationEditor = document.querySelector('[data-match-first-location]');
        if (locationEditor) locationEditor.hidden = true;
        var title = matchPanel && matchPanel.querySelector('h2');
        if (title) title.textContent = '更換顏色／尺寸｜請點正確實品';
        matchPanel.scrollIntoView({block:'nearest'});
        setMessage('請依實品選擇正確顏色與尺寸；原掃描條碼仍會保留。', 'ok');
      })
      .catch(function (error) { setMessage(error.message || '讀取顏色與尺寸失敗', 'error'); });
  }

  function resetCurrentStocktakeSelection() {
    selected = null;
    allMatches = [];
    currentStocktakeScanCode = '';
    stocktakeLiveCounts = {};
    stocktakeFlowLock = {barcode:'',skuId:'',shelf:'',layer:''};
    stocktakeChangingLocation = false;
    stocktakeAutoSubmitting = false;
    if (matchPanel) matchPanel.hidden = true;
    if (operationPanel) operationPanel.hidden = true;
    resetProductSelection();
    clearScanInput();
    renderStocktakeFastRows();
  }

  function removeStocktakeEntry(draftIndex) {
    var line = draft[draftIndex];
    if (!line || line.action !== 'stocktake' || !session || !session.id) return Promise.reject(new Error('找不到要刪除的盤點商品'));
    return post({action:'stocktake_remove_entry',sessionId:session.id,skuId:line.skuId || '',barcode:line.barcode || '',warehouse:line.to || line.from || warehouse.value,shelf:line.shelf || '',layer:line.layer || '',operator:operator}).then(function () {
      draft.splice(draftIndex, 1);
      saveDraft();
      renderStocktakeFastRows();
      loadOwnSessions();
      setMessage('已刪除這筆盤點商品；重新整理後也不會再出現。','ok');
    });
  }

  function clearCurrentStocktake() {
    if (!session || !session.id) {
      draft = draft.filter(function (line) { return line.action !== 'stocktake'; });
      resetCurrentStocktakeSelection();
      saveDraft();
      return Promise.resolve();
    }
    return post({action:'stocktake_clear_entries',sessionId:session.id,operator:operator}).then(function () {
      draft = draft.filter(function (line) { return line.action !== 'stocktake'; });
      resetCurrentStocktakeSelection();
      saveDraft();
      loadOwnSessions();
      setMessage('本次盤點商品已全部清除；盤點單保留為空白，可重新開始掃描。','ok');
    });
  }

  function pastePhotoFromClipboard(kind) {
    var box = document.querySelector(kind === 'stockin' ? '[data-stockin-photo-box]' : kind === 'pending' ? '[data-pending-photo-paste]' : '[data-stocktake-photo-box]');
    if (box) box.focus();
    if (!navigator.clipboard || typeof navigator.clipboard.read !== 'function') {
      setMessage('此瀏覽器請先複製圖片，再點照片區按 Ctrl+V；手機可直接使用「手機拍照」。', 'error');
      return;
    }
    navigator.clipboard.read().then(function (items) {
      for (var i = 0; i < items.length; i += 1) {
        var imageType = items[i].types.find(function (type) { return /^image\//i.test(type); });
        if (!imageType) continue;
        return items[i].getType(imageType).then(function (blob) {
          return scannerImageFileToDataUrl(blob);
        }).then(function (dataUrl) {
          if (kind === 'stockin') setStockinBatchPhoto(dataUrl, '複製圖片');
          else if (kind === 'pending') { setPendingPastedPhoto(dataUrl, '複製圖片'); savePending({autoFromPhoto:true}); }
          else setStocktakePhoto(dataUrl, '複製圖片');
        });
      }
      throw new Error('剪貼簿裡目前沒有圖片');
    }).catch(function (error) { setMessage((error && error.message) || '無法讀取複製圖片，請允許剪貼簿權限。', 'error'); });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c];
    });
  }

  function stockinCode128Pattern(value) {
    var patterns=['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
    var text=String(value||'').trim(); if(!text)return '';
    var startsNumeric=/^\d{4,}$/.test(text)&&text.length%2===0;
    var codes=[startsNumeric?105:104],mode=startsNumeric?'C':'B',i=0;
    while(i<text.length){
      if(mode==='C'){if(/^\d{2}$/.test(text.slice(i,i+2))){codes.push(Number(text.slice(i,i+2)));i+=2;continue;}codes.push(100);mode='B';continue;}
      var run=(text.slice(i).match(/^\d+/)||[''])[0];
      if(run.length>=4){if(run.length%2===1){codes.push(text.charCodeAt(i)-32);i+=1;}codes.push(99);mode='C';continue;}
      var code=text.charCodeAt(i);if(code<32||code>126)return '';codes.push(code-32);i+=1;
    }
    var sum=codes[0];for(var weight=1;weight<codes.length;weight+=1)sum+=codes[weight]*weight;codes.push(sum%103);codes.push(106);
    return codes.map(function(code){return patterns[code]||'';}).join('');
  }
  
  function normalizeScanCodeLocal(value) {
    return String(value == null ? '' : value).trim();
  }
  function compactNumericBarcodeScanKey(value) { // legacy 8-digit labels only
    var text = (typeof normalizeScanCode === 'function' ? normalizeScanCode(value) : normalizeScanCodeLocal(value)).toUpperCase().replace(/[^0-9A-Z]/g,'');
    if (!text) return '';
    var hash = 2166136261;
    for (var i = 0; i < text.length; i += 1) {
      hash = Math.imul(hash ^ text.charCodeAt(i), 16777619) >>> 0;
    }
    return '8' + String(hash % 10000000).padStart(7, '0');
  }
function stockinCode128Svg(value) {
    var pattern=stockinCode128Pattern(value);if(!pattern)return '';
    var quietModules=10,x=quietModules,bars=[];for(var i=0;i<pattern.length;i+=1){var width=Number(pattern[i]);if(i%2===0)bars.push('<rect x="'+x+'" y="4" width="'+width+'" height="72"/>');x+=width;}
    var total=x+quietModules;var physicalWidth=(total*.25).toFixed(3);return '<svg class="barcode-svg" xmlns="http://www.w3.org/2000/svg" style="width:'+physicalWidth+'mm;height:100%" viewBox="0 0 '+total+' 80" preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges"><rect width="'+total+'" height="80" fill="#fff"/><g fill="#000">'+bars.join('')+'</g></svg>';
  }
  function scannerNumericBarcodeScanKey(value) {
    var text=normalizeScanCode(value);if(!text)return '';
    var hash=2166136261;
    for(var i=0;i<text.length;i+=1)hash=Math.imul(hash^text.charCodeAt(i),16777619)>>>0;
    // Ten numeric digits still fit a 40x30mm Code128-C label, while avoiding
    // the real collisions found in the former eight-digit key space.
    return '8'+String(hash%1000000000).padStart(9,'0');
  }
  function scannerQrCodeSvg(value) {
    value=String(value||'').trim();
    if(!value||value.length>32||/[^\x20-\x7e]/.test(value)||typeof window.qrcode!=='function')return '';
    var qr;
    try{qr=window.qrcode(value.length<=14?1:2,'M');qr.addData(value,'Byte');qr.make();}
    catch(error){qr=window.qrcode(value.length<=17?1:2,'L');qr.addData(value,'Byte');qr.make();}
    var size=qr.getModuleCount(),quiet=4,canvas=size+quiet*2,rects=[];
    for(var row=0;row<size;row+=1)for(var col=0;col<size;col+=1)if(qr.isDark(row,col))rects.push('<rect x="'+(col+quiet)+'" y="'+(row+quiet)+'" width="1" height="1"/>');
    return '<svg class="qr-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+canvas+' '+canvas+'" shape-rendering="crispEdges"><rect width="'+canvas+'" height="'+canvas+'" fill="#fff"/><g fill="#000">'+rects.join('')+'</g></svg>';
  }
  function scannerLabelSerial(line, barcode) {
    var source=String(barcode||line&&(
      line.labelBarcode||line.barcode||line.rawCode||line.skuId
    )||'').replace(/\s+/g,'').trim();
    return source.slice(-6).toUpperCase().padStart(6,'0');
  }
  function scannerLabelWarehouseText(line) {
    line=line||{};
    var raw=String(line.warehouse||line.warehouseName||line.warehouseCode||line.destinationWarehouse||(warehouse&&warehouse.value)||'').trim();
    var code=warehouseCodeOf(raw);
    if(code==='CN')return '中國倉';
    if(code==='TW')return '台灣倉';
    if(code==='ID')return '印尼倉';
    if(code==='PREORDER'||/預購|预购/i.test(raw))return '預購倉';
    return raw||'未設定倉';
  }
  function scannerLabelColorText(color, indonesian) {
    var zh=String(color||'未設定').trim();
    var id=String(indonesian||'').trim();
    if(!id){
      var rules=[[/粉紅|粉色/,'MERAH MUDA'],[/深藍|藏青/,'BIRU TUA'],[/淺藍|天藍/,'BIRU MUDA'],[/淺綠/,'HIJAU MUDA'],[/米白|象牙/,'PUTIH GADING'],[/黑/,'HITAM'],[/白/,'PUTIH'],[/紅/,'MERAH'],[/黃/,'KUNING'],[/綠/,'HIJAU'],[/藍/,'BIRU'],[/銀/,'PERAK'],[/灰/,'ABU-ABU'],[/紫/,'UNGU'],[/咖|棕/,'COKELAT'],[/金/,'EMAS'],[/橘/,'ORANYE'],[/米/,'KREM'],[/透明/,'TRANSPARAN'],[/圖片|彩|混色/,'WARNA-WARNI']];
      for(var i=0;i<rules.length;i+=1)if(rules[i][0].test(zh)){id=rules[i][1];break;}
    }
    return zh+' / '+(id||'WARNA LAIN');
  }
  function printStockinDraftBarcode(index) {
    var line=stockinDraft[index];if(!line)return;
    printScannerLineBarcode(line, 1);
  }
  function printScannerLineBarcode(line, defaultCopies) {
    if(!line)return;
    var barcode=String(line.labelBarcode||line.barcode||line.rawCode||line.skuId||'').trim();
    if(!barcode){setMessage('這個品項還沒有可列印的條碼。','error');return;}
    var copies=Number(defaultCopies||1);
    if(!Number.isInteger(copies)||copies<1||copies>99){setMessage('每次請列印 1 至 99 張。','error');return;}
    var popup=window.open('','_blank','width=520,height=620');if(!popup){setMessage('瀏覽器擋住列印視窗，請允許彈出視窗。','error');return;}
    // LZ_LABEL_P_FIT_20260924: human text keeps full 編號C色S尺P成本; never clip P.
    // Long product codes (14–16 chars) are too dense for 40×30mm Code128.
    // Keep QR + human text as the FULL barcode; linear bars use compact 8xxxxxxxx
    // key matching PHP scanner_numeric_barcode_key / scanner_barcode_key_matches.
    var linearScanKey = /^8\d{9}$/.test(String(barcode||'').replace(/[^0-9A-Za-z]/g,'').toUpperCase())
      ? String(barcode||'').replace(/[^0-9A-Za-z]/g,'').toUpperCase()
      : (scannerNumericBarcodeScanKey(barcode) || barcode);
    var barcodeSvg=stockinCode128Svg(linearScanKey),qrSvg=scannerQrCodeSvg(barcode);if(!barcodeSvg||!qrSvg){popup.close();setMessage('條碼含有無法列印的字元，或二維碼元件尚未載入。請重新整理後再試。','error');return;}
    var serial='SN:'+scannerLabelSerial(line,barcode);
    var warehouseText=scannerLabelWarehouseText(line);
    var variant=scannerLabelColorText(line.color,line.colorIndonesian)+' · '+stockinCanonicalSize(line.size);
    var barcodeLen=String(barcode||'').replace(/\s+/g,'').length;
    var barcodePt=barcodeLen<=12?7.0:barcodeLen<=14?6.1:barcodeLen<=16?5.3:barcodeLen<=18?4.7:4.2;
    var barcodeMatch=String(barcode||'').toUpperCase().match(/^(.*?)(P\d+)$/);
    var barcodeHtml=barcodeMatch
      ? '<span class="code-body">'+escapeHtml(barcodeMatch[1])+'</span><span class="code-cost">'+escapeHtml(barcodeMatch[2])+'</span>'
      : escapeHtml(barcode);
    var label='<article class="label"><div class="info-line barcode-line"><b>商品條碼</b><span style="font-size:'+barcodePt+'pt">'+barcodeHtml+'</span></div><div class="info-line serial-line"><b>序號</b><span>'+escapeHtml(serial)+'</span><em class="warehouse">倉庫 '+escapeHtml(warehouseText)+'</em></div><div class="info-line variant-line"><b>顏色＋尺寸</b><span>'+escapeHtml(variant)+'</span></div><div class="code-row"><div class="qr-area">'+qrSvg+'</div><div class="barcode-area" aria-label="一維掃描碼 '+escapeHtml(linearScanKey)+'">'+barcodeSvg+'</div></div></article>';
    popup.document.open();popup.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Print '+escapeHtml(barcode)+'</title><style>@page{size:40mm 30mm;margin:0!important}*{box-sizing:border-box}html,body{width:40mm;min-width:40mm;max-width:40mm;margin:0;padding:0;background:#fff;color:#000;font-family:Arial,"Microsoft JhengHei",sans-serif}.label{width:40mm;height:30mm;margin:0;padding:.55mm .65mm;display:grid;grid-template-rows:3.8mm 3.8mm 4.2mm 15.75mm;gap:.32mm;overflow:hidden;break-after:page;page-break-after:always}.label:last-child{break-after:auto;page-break-after:auto}.info-line{min-width:0;display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:.65mm;border-bottom:.1mm solid #777;white-space:nowrap;overflow:hidden}.info-line b{font-size:4.4pt;font-weight:900;line-height:1}.info-line span{min-width:0;overflow:visible;white-space:nowrap;font-weight:900;line-height:1}.barcode-line{overflow:visible}.barcode-line span{font-family:Arial,"Arial Narrow",sans-serif;font-size:5.4pt;letter-spacing:-.05em;display:flex;align-items:baseline;gap:.35mm;min-width:0;overflow:visible;white-space:nowrap}.barcode-line .code-cost{font-size:1.12em;letter-spacing:.02em;font-weight:950}.serial-line{grid-template-columns:auto minmax(0,1fr) auto}.serial-line span{font-size:7.2pt;letter-spacing:.035em}.serial-line .warehouse{font-style:normal;font-size:5.7pt;font-weight:900;line-height:1;padding-left:.65mm;border-left:.1mm solid #777}.variant-line span{font-size:5.35pt;letter-spacing:-.02em}.code-row{min-width:0;display:grid;grid-template-columns:14.2mm minmax(0,1fr);gap:.75mm;align-items:center;overflow:hidden}.qr-area{width:14.2mm;height:14.2mm;padding:.25mm;background:#fff;overflow:hidden}.qr-svg{width:100%;height:100%;display:block;shape-rendering:crispEdges}.barcode-area{width:23.5mm;height:14.2mm;display:flex;align-items:center;justify-content:center;overflow:visible;background:#fff;padding:0 1.5mm;box-sizing:border-box;}.barcode-svg{width:23.5mm!important;min-width:23.5mm;max-width:23.5mm;height:13.7mm!important;display:block;flex:0 0 23.5mm;shape-rendering:crispEdges}@media print{html,body{width:40mm!important;min-width:40mm!important;max-width:40mm!important}.label{width:40mm!important;height:30mm!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}svg{image-rendering:pixelated;shape-rendering:crispEdges}}</style></head><body>'+Array.from({length:copies}).map(function(){return label;}).join('')+'<script>window.onload=function(){var run=function(){setTimeout(function(){window.focus();window.print();},500)};if(document.fonts&&document.fonts.ready){document.fonts.ready.then(run,run)}else{run()}};<\/script></body></html>');popup.document.close();
  }
  function readJson(key, fallback) {
    try {
      var value = JSON.parse(localStorage.getItem(key) || 'null');
      return value == null ? fallback : value;
    } catch (error) {
      return fallback;
    }
  }
  function fetchWithTimeout(url, options, timeoutMs) {
    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (controller) controller.abort(); }, Math.max(3000, Number(timeoutMs || 8000)));
    options = options || {};
    if (controller) options.signal = controller.signal;
    return fetch(url, options).then(function (response) {
      clearTimeout(timer);
      return response;
    }, function (error) {
      clearTimeout(timer);
      if (error && error.name === 'AbortError') throw new Error('伺服器回應逾時，請確認網路後重試');
      throw error;
    });
  }
  function currentOperator() {
    var login = readJson(loginKey, {});
    if (login && login.until && Date.now() < Number(login.until)) {
      return {name: login.name || login.account || '管理者', account: login.account || '', role: login.role || 'admin', permissions: Array.isArray(login.permissions) ? login.permissions : []};
    }
    return {name: '未登入人員', account: '', role: 'unknown', permissions: []};
  }

  function normalizeScanCode(value) {
    var text = String(value == null ? '' : value);
    try { text = text.normalize('NFKC'); } catch (error) {}
    text = text.replace(/(?:UNIDENTIFIED|UNIDENT|IUNDIENT|IUNIDENT|PROCESS|DEAD|COMPOSE|UNKNOWN|NONE)+/gi, '');
    return text.replace(/[^0-9A-Za-z]/g, '').toUpperCase();
  }
  function sanitizeScanField(field) {
    if (!field) return '';
    var clean = normalizeScanCode(field.value);
    if (field.value !== clean) field.value = clean;
    return clean;
  }
  function isAllowedScanText(value) {
    return normalizeScanCode(value) === String(value == null ? '' : value).toUpperCase();
  }
  function activeScanInput() {
    return scanInputMode === 'manual' && manualInput ? manualInput : input;
  }
  function activeScanCode() {
    return sanitizeScanField(activeScanInput());
  }
  function setBarcodeValue(value) {
    var clean = normalizeScanCode(value);
    if (input) input.value = clean;
    updateScanEcho(clean, clean ? '已接收掃描字串' : '等待掃描槍');
    return clean;
  }
  function updateScanEcho(code, status, type) {
    code = normalizeScanCode(code);
    if (lastScanCode) lastScanCode.textContent = code || '尚未掃描';
    if (lastScanStatus) {
      lastScanStatus.textContent = status || (code ? '已接收' : '等待掃描槍');
      lastScanStatus.className = type ? 'is-' + type : '';
    }
  }
  function clearScanInput(messageText) {
    clearTimeout(inputLookupTimer);
    clearTimeout(hardwareScanTimer);
    clearTimeout(hardwareTerminatorTimer);
    hardwareTerminatorTimer = null;
    hardwareTerminatorDeadline = 0;
    hardwareScanBuffer = '';
    lastLookupCode = '';
    lastLookupAt = 0;
    if (input) input.value = '';
    if (manualInput) manualInput.value = '';
    updateScanEcho('', '已清除掃描欄', 'ok');
    if (messageText) setMessage(messageText, 'ok');
    focusActiveScanInput();
  }
  function scanArchiveStatusText(status) {
    return status === 'matched' ? '已對到商品並歸檔'
      : status === 'not_found' ? '找不到商品，已歸檔'
      : status === 'wrong_warehouse' ? '商品存在但倉庫不符，已歸檔'
      : status === 'error' ? '查詢失敗，已留本機紀錄'
      : '已歸檔';
  }
  function archiveScanResult(code, status, row, matchCount, note) {
    code = normalizeScanCode(code);
    if (!code) return;
    row = row || {};
    var entry = {
      at: new Date().toISOString(),
      action: status === 'matched' ? 'scan_match' : 'scan_issue',
      scanCode: code,
      scanStatus: status,
      productCode: row.productCode || code,
      title: row.title || '',
      color: row.color || '',
      size: row.size || '',
      qty: 0,
      previousStock: row && row.stock != null ? Number(row.stock || 0) : 0,
      variance: 0,
      from: activeWarehouse(),
      to: activeWarehouse(),
      warehouse: row.warehouse || activeWarehouse(),
      image: row.image || '',
      operator: operator.name,
      skuId: row.skuId || '',
      matchCount: Number(matchCount || 0),
      note: note || scanArchiveStatusText(status)
    };
    draft.unshift(entry);
    draft = draft.slice(0, 200);
    saveDraft();
    updateScanEcho(code, entry.note, status === 'matched' || status === 'series_found' ? 'ok' : 'error');
    if (operator.role === 'unknown') return;
    post({
      action: 'scan_archive',
      scanCode: code,
      scanStatus: status,
      matchCount: entry.matchCount,
      note: entry.note,
      warehouse: activeWarehouse(),
      operator: operator,
      match: {
        skuId: entry.skuId,
        productCode: entry.productCode,
        title: entry.title,
        color: entry.color,
        size: entry.size,
        warehouse: entry.warehouse,
        stock: entry.previousStock
      }
    }).catch(function () {});
  }
  function isScannerUiControl(node) {
    if (!node || node === document.body || node === document.documentElement) return false;
    var tag = String(node.tagName || '').toLowerCase();
    if (tag === 'select' || tag === 'textarea' || tag === 'option' || tag === 'button') {
      if (tag === 'button' && node.getAttribute && (node.getAttribute('data-scan-submit') != null || node.getAttribute('data-scan-input-mode') != null || node.getAttribute('data-tab') != null)) return false;
      return tag !== 'button';
    }
    if (tag === 'input' && node !== input && node !== manualInput && String(node.type || '') !== 'hidden') return true;
    if (node.closest && node.closest('select, textarea, .warehouse-context, [data-stockin-fields], [data-stockin-draft-panel], [data-match-first-location], [data-pending-panel], [data-match-color-picker]')) return true;
    return false;
  }
  function suspendScannerForUiPicker() {
    scannerUiPickerOpen = true;
    clearTimeout(scannerUiPickerTimer);
    scannerUiPickerTimer = setTimeout(function () { scannerUiPickerOpen = false; }, 10000);
  }
  function resumeScannerAfterUiPicker(delay) {
    clearTimeout(scannerUiPickerTimer);
    scannerUiPickerTimer = setTimeout(function () { scannerUiPickerOpen = false; }, Math.max(400, Number(delay || 0)));
  }
  function shouldKeepScanInputFocus() {
    if (document.body.classList.contains('is-scanner-locked')) return false;
    if (scannerMediaPickerOpen || scannerUiPickerOpen) return false;
    if (isScannerUiControl(document.activeElement)) return false;
    if (document.querySelector('[data-match-first-location]:not([hidden])')) return false;
    return true;
  }
  function focusActiveScanInput() {
    var field = activeScanInput();
    if (!field) return;
    setTimeout(function () {
      // 登入畫面與一般表單輸入期間不可讓掃描欄搶走游標。
      if (document.body.classList.contains('is-scanner-locked')) return;
      if (scannerMediaPickerOpen || scannerUiPickerOpen) return;
      if (isScannerUiControl(document.activeElement)) return;
      if (currentTab === 'stocktake' && matchPanel && !matchPanel.hidden) {
        field.blur();
        return;
      }
      // YGF 舊機需要 text input connection 才會把掃描結果送入網頁。
      // 先以唯讀欄位取得焦點來阻止軟鍵盤，焦點建立後再解除唯讀；
      // 全程保留 inputmode=text，避免關掉掃描頭所依賴的輸入通道。
      var isHardwareField = field === input && (scanInputMode === 'hardware' || scanInputMode === 'ccd');
      if (isHardwareField) {
        field.setAttribute('inputmode', 'text');
        field.readOnly = true;
        field.setAttribute('aria-readonly', 'true');
      }
      try { field.focus({preventScroll:true}); } catch (error) { field.focus(); }
      if (isHardwareField) {
        setTimeout(function () {
          if ((scanInputMode === 'hardware' || scanInputMode === 'ccd') && document.activeElement === input) {
            input.readOnly = false;
            input.setAttribute('aria-readonly', 'false');
          }
        }, 180);
      }
    }, 0);
  }
  function setInputMode(mode) {
    scanInputMode = mode === 'manual' ? 'manual' : (mode === 'camera' ? 'camera' : 'hardware');
    hardwareScanBuffer = '';
    clearTimeout(hardwareScanTimer);
    clearTimeout(hardwareTerminatorTimer);
    hardwareTerminatorTimer = null;
    hardwareTerminatorDeadline = 0;
    // 舊版 YGF 會把整串條碼直接寫進目前欄位，而不是逐字送 keydown。
    // 掃描欄位必須保留 text input connection；focusActiveScanInput 會在
    // 聚焦當下短暫設為唯讀，阻止 Android 軟鍵盤自行彈出。
    if (input) {
      input.setAttribute('inputmode', 'text');
      input.setAttribute('virtualkeyboardpolicy', 'manual');
      input.readOnly = false;
      input.setAttribute('aria-readonly', 'false');
    }
    if (manualInput) manualInput.setAttribute('inputmode', 'latin');
    document.body.classList.toggle('is-manual-input', scanInputMode === 'manual');
    document.body.classList.toggle('is-barcode-input', scanInputMode !== 'manual');
    document.querySelectorAll('[data-scan-input-mode]').forEach(function (item) {
      item.classList.toggle('is-active', item.getAttribute('data-scan-input-mode') === scanInputMode);
    });
    if (scanInputMode === 'manual' && manualInput) manualInput.placeholder = '英文數字搜尋';
    if (scanInputMode !== 'manual' && input) input.placeholder = '等待掃描槍輸入（不用手打）';
    focusActiveScanInput();
  }
  function updateCurrentTabLabel(tab) {
    var active = document.querySelector('[data-tab="' + tab + '"]');
    var label = active ? active.textContent.trim() : tab;
    if (currentTabLabel) currentTabLabel.textContent = label;
    if (screenTitle) screenTitle.textContent = tab === 'stocktake' ? '商品盤點' : (tab === 'lookup' ? '庫存查詢' : label);
  }
  function applyInitialStockCopy() {
    var summary = document.querySelector('[data-stockin-summary]');
    if (summary) summary.textContent = '先選入庫倉與本批貨架／層位；掃碼會加入下方清單，選定會寫入本批，送審前仍可逐筆修改。';
    var stockinFields = document.querySelector('[data-stockin-fields]');
    if (!stockinFields) return;
    var warningTitle = stockinFields.querySelector('.stockin-warning b');
    if (warningTitle) warningTitle.textContent = '掃描後加入建檔清單';
    var shelfSelect = stockinFields.querySelector('[data-stockin-shelf]');
    var layerSelect = stockinFields.querySelector('[data-stockin-layer]');
    if (shelfSelect && shelfSelect.closest('label') && shelfSelect.closest('label').firstChild) shelfSelect.closest('label').firstChild.nodeValue = '貨架（可稍後補填）';
    if (layerSelect && layerSelect.closest('label') && layerSelect.closest('label').firstChild) layerSelect.closest('label').firstChild.nodeValue = '層位（可稍後補填）';
    var photoTitle = stockinFields.querySelector('.stockin-photo-box b');
    if (photoTitle) photoTitle.textContent = '本批建檔照片';
  }
  function setTabsCollapsed(collapsed) {
    scanTabsCollapsed = !!collapsed;
    document.body.classList.toggle('scanner-tabs-collapsed', scanTabsCollapsed);
    if (tabToggle) tabToggle.setAttribute('aria-expanded', scanTabsCollapsed ? 'false' : 'true');
  }
  function scannerSetLoginStatus(text, type) {
    var status = document.querySelector('[data-scanner-login-status]');
    if (!status) return;
    status.textContent = text || '';
    status.classList.toggle('is-error', type === 'error');
    status.classList.toggle('is-ok', type === 'ok');
  }
  function scannerVerifyLogin(account, password) {
    return fetchWithTimeout('./admin-state-api-v3.php?login=1&_=' + Date.now(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ account: String(account || '').trim(), password: String(password || ''), roleHint: 'staff' })
    }, 60000).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (payload) {
        if (!res.ok || !payload || !payload.user) throw new Error((payload && payload.error) || '帳號或密碼錯誤');
        var user = payload.user || {};
        user.serverSession = payload.sessionReady === true;
        user.sessionExpiresAt = Number(payload.sessionExpiresAt || 0);
        if (!user.serverSession || user.sessionExpiresAt * 1000 <= Date.now()) throw new Error('登入工作階段建立失敗，請重新登入');
        return user;
      });
    }).catch(function (error) {
      var msg = error && error.message ? String(error.message) : '登入失敗';
      if (/逾時|AbortError|Failed to fetch|NetworkError|Load failed/i.test(msg)) {
        throw new Error('登入逾時或網路中斷。伺服器帳號檔較大，請再試一次；若連續失敗請通知管理者。');
      }
      throw error;
    });
  }
  function scannerCompleteLogin(user) {
    var role = String(user && user.role || '');
    if (['admin', 'staff'].indexOf(role) === -1) throw new Error('此帳號不是管理者或員工帳號，不能登入盤點機');
    var permissions = Array.isArray(user.permissions) ? user.permissions : [];
    var allowed = role === 'admin' || ['盤點機', '盤點', '調撥', '快速入庫', '查庫存', '貨倉管理', '庫存管理'].some(function (name) { return permissions.indexOf(name) !== -1; });
    if (!allowed) throw new Error('此帳號尚未開啟盤點機權限，請管理者到員工管理勾選');
    var remoteUntil = Number(user.sessionExpiresAt || 0) * 1000;
    localStorage.setItem(loginKey, JSON.stringify({
      version: authVersion,
      until: remoteUntil > Date.now() ? Math.min(Date.now() + loginTtl, remoteUntil) : Date.now() + loginTtl,
      role: role,
      name: user.name || (role === 'admin' ? '管理者' : ''),
      account: user.account || '',
      permissions: permissions,
      serverSession: true
    }));
    operator = currentOperator();
    session = null;
    scannerSetLoginStatus('登入成功，已進入盤點機功能。', 'ok');
    ensureSession();
    applyPermissionUi();
    renderSession();
    loadOwnSessions();
    setTimeout(function () { if (input) input.focus(); }, 120);
  }
  function bindScannerLogin() {
    var form = document.querySelector('[data-scanner-login-form]');
    if (form && !form._scannerLoginBound) {
      form._scannerLoginBound = true;
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var account = document.querySelector('[data-scanner-login-account]');
        var password = document.querySelector('[data-scanner-login-password]');
        var button = form.querySelector('button[type="submit"]');
        var accountValue = String(account && account.value || '').trim();
        var passwordValue = String(password && password.value || '');
        if (!accountValue || !passwordValue) {
          scannerSetLoginStatus('請輸入帳號與密碼。', 'error');
          if (!accountValue && account) account.focus();
          else if (password) password.focus();
          return;
        }
        if (button) { button.disabled = true; button.textContent = '登入中…'; }
        scannerSetLoginStatus('正在驗證帳號，請稍候。', '');
        scannerVerifyLogin(accountValue, passwordValue).then(function (user) {
          if (password) password.value = '';
          scannerCompleteLogin(user);
        }).catch(function (error) {
          scannerSetLoginStatus(error && error.message ? error.message : '帳號或密碼錯誤。', 'error');
          if (password) { password.value = ''; password.focus(); }
        }).then(function () {
          if (button) { button.disabled = false; button.textContent = '登入盤點機'; }
        });
      });
    }
    var logout = document.querySelector('[data-scanner-logout]');
    if (logout && !logout._scannerLogoutBound) {
      logout._scannerLogoutBound = true;
      logout.addEventListener('click', function () {
        localStorage.removeItem(loginKey);
        operator = currentOperator();
        session = null;
        renderSession();
        applyPermissionUi();
        scannerSetLoginStatus('已登出，請重新登入盤點機。', '');
      });
    }
    document.querySelectorAll('[data-scanner-login-focus]').forEach(function (button) {
      if (button._scannerLoginFocusBound) return;
      button._scannerLoginFocusBound = true;
      button.addEventListener('click', function () {
        var account = document.querySelector('[data-scanner-login-account]');
        if (account) account.focus();
        var gate = document.querySelector('[data-scanner-login-gate]');
        if (gate && gate.scrollIntoView) gate.scrollIntoView({ block: 'center' });
      });
    });
  }
  function hasPermission(names) {
    if (operator.role === 'admin') return true;
    var permissions = Array.isArray(operator.permissions) ? operator.permissions : [];
    return names.some(function (name) { return permissions.indexOf(name) !== -1; });
  }
  function canUseTab(tab) {
    if (operator.role === 'sales') return tab === 'lookup';
    if (tab === 'stocktake') return hasPermission(['盤點機', '盤點', '貨倉管理', '庫存管理']);
    if (tab === 'transfer') return hasPermission(['盤點機', '調撥', '貨倉管理', '庫存管理']);
    if (tab === 'arrival') return hasPermission(['盤點機', '調撥', '出貨', '訂單管理', '貨倉管理', '庫存管理']);
    if (tab === 'stockin') return hasPermission(['盤點機', '快速入庫', '貨倉管理', '庫存管理', '公司庫存']);
    return hasPermission(['盤點機', '查庫存', '貨倉管理', '庫存管理', '公司庫存']);
  }
  function applyPermissionUi() {
    var firstAllowed = null;
    document.querySelectorAll('[data-tab]').forEach(function (button) {
      var allowed = canUseTab(button.dataset.tab);
      button.hidden = !allowed;
      if (!firstAllowed && allowed) firstAllowed = button;
    });
    var gate = document.querySelector('[data-scanner-login-gate]');
    document.body.classList.toggle('is-scanner-locked', !firstAllowed);
    if (gate) gate.hidden = !!firstAllowed;
    var logoutButton = document.querySelector('[data-scanner-logout]');
    if (logoutButton) logoutButton.hidden = !firstAllowed;
    var closeInitialButton = document.querySelector('[data-close-initial-stock-mode]');
    if (closeInitialButton) closeInitialButton.hidden = true;
    if (!firstAllowed) {
      input.disabled = true;
      var cameraButton = document.querySelector('[data-camera-open]');
      if (cameraButton) cameraButton.hidden = true;
      setMessage(operator.role === 'unknown' ? '請先登入盤點機帳號。' : '此帳號尚未開啟盤點機權限，請管理者到員工管理勾選盤點、調撥或查庫存。', 'error');
      return;
    }
    input.disabled = false;
    if (!canUseTab(currentTab)) switchTab(firstAllowed.dataset.tab, firstAllowed);
  }
  function newSession() {
    return {
      id: 'STK-' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '-' + String(Date.now()).slice(-6),
      startedAt: new Date().toISOString(),
      warehouse: warehouse.value || '台灣倉',
      operator: operator
    };
  }
  function ensureSession() {
    if (!session || session.status === 'submitted') {
      session = newSession();
      localStorage.setItem(sessionKey, JSON.stringify(session));
    }
    renderSession();
  }
  function renderSession() {
    document.querySelector('[data-operator]').textContent = operator.name + (operator.role === 'staff' ? '（員工）' : operator.role === 'admin' ? '（管理者）' : '');
    document.querySelector('[data-started-at]').textContent = session ? new Date(session.startedAt).toLocaleString('zh-TW') : '-';
    document.querySelector('[data-session-id]').textContent = session ? session.id : '-';
  }
  function saveDraft() {
    renderDraft();
  }
  function transferDraftTotals() {
    return transferDraft.reduce(function (totals, line) {
      totals.items += 1;
      totals.qty += Math.max(1, Number(line.qty || 1));
      return totals;
    }, {items: 0, qty: 0});
  }
  function transferLineKey(row, from, to) {
    return [String(row && row.skuId || ''), String(from || ''), String(to || ''), transferMode()].join('|');
  }
  function transferMode() {
    return transferDepartmentMode && transferDepartmentMode.value === 'computer' ? 'computer' : 'clothing';
  }
  function computerCategoryOptions(selected) {
    var current = String(selected || '').trim();
    var list = computerTransferCategories.slice();
    if (current && list.indexOf(current) === -1) list.unshift(current);
    return '<option value="">請選寶輝產品分類（必選）</option>' + list.map(function (name) {
      return '<option value="' + escapeHtml(name) + '"' + (name === current ? ' selected' : '') + '>' + escapeHtml(name) + '</option>';
    }).join('');
  }
  function loadComputerTransferCategories() {
    if (computerTransferCategories.length || computerTransferCategoriesLoading) return Promise.resolve(computerTransferCategories);
    computerTransferCategoriesLoading = true;
    return fetchWithTimeout('./computer-products-api.php?scannerTransfer=1&_=' + Date.now(), {cache: 'no-store'}, 12000)
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        if (!payload.ok) throw new Error(payload.error || '讀取寶輝分類失敗');
        computerTransferCategories = (Array.isArray(payload.categories) ? payload.categories : []).map(function (name) { return String(name || '').trim(); }).filter(Boolean);
        renderTransferDraft();
        return computerTransferCategories;
      }).catch(function (error) {
        setMessage('寶輝分類讀取失敗：' + (error.message || '') + '。尚未送出任何庫存。', 'error');
        return [];
      }).finally(function () { computerTransferCategoriesLoading = false; });
  }
  function applyTransferMode() {
    var computerMode = transferMode() === 'computer';
    var notice = document.querySelector('[data-transfer-computer-notice]');
    var help = document.querySelector('[data-transfer-save-help]');
    if (notice) notice.hidden = !computerMode;
    Array.prototype.forEach.call(sourceWarehouse.options || [], function (option) {
      option.disabled = computerMode && option.value === '預購倉';
    });
    if (computerMode && sourceWarehouse.value === '預購倉') sourceWarehouse.value = warehouses.indexOf('台灣倉') !== -1 ? '台灣倉' : (warehouses[0] || '');
    if (computerMode) {
      targetWarehouse.innerHTML = '<option value="寶輝電腦倉">寶輝後台／電腦倉</option>';
      targetWarehouse.disabled = true;
      if (help) help.textContent = '每項先選寶輝產品分類。儲存後會同一筆交易扣除服裝來源倉、加入寶輝電腦倉；顏色、尺寸、照片、成本與數量維持。';
      loadComputerTransferCategories();
    } else {
      var options = warehouses.map(function (item) { return '<option value="' + escapeHtml(item) + '">' + escapeHtml(item) + '</option>'; }).join('');
      fillWarehouseSelect(targetWarehouse, options, false);
      targetWarehouse.disabled = false;
      if (targetWarehouse.options.length > 1 && targetWarehouse.value === sourceWarehouse.value) targetWarehouse.selectedIndex = 1;
      if (help) help.textContent = '中國→台灣：儲存後只建立在途調撥單，台灣倉不加庫。其他倉對倉仍是來源減、目的加。';
    }
    renderTransferDraft();
  }
  function saveTransferDraft() {
    localStorage.setItem(transferDraftKey, JSON.stringify(transferDraft));
    renderTransferDraft();
  }
  function renderTransferDraft() {
    var panel = document.querySelector('[data-transfer-draft-panel]');
    var list = document.querySelector('[data-transfer-draft-list]');
    var total = document.querySelector('[data-transfer-draft-total]');
    var saveButton = document.querySelector('[data-save-transfer-draft]');
    if (!panel || !list || !total || !saveButton) return;
    panel.hidden = currentTab !== 'transfer';
    var totals = transferDraftTotals();
    total.textContent = totals.items + ' 項／' + totals.qty + ' 件';
    var computerMode = transferMode() === 'computer';
    var missingCategory = computerMode && transferDraft.some(function (line) { return !String(line.computerCategory || '').trim(); });
    saveButton.disabled = !transferDraft.length || missingCategory;
    saveButton.textContent = missingCategory ? '請先選完寶輝產品分類' : (computerMode ? '確認轉入寶輝電腦倉（' : '儲存整張調撥單（') + totals.qty + ' 件）';
    if (!transferDraft.length) {
      list.innerHTML = '<p class="empty">尚未掃描調撥產品</p>';
      return;
    }
    var hasPreviousDraft = transferDraft.some(function (line) {
      var at = Date.parse(line.lastScannedAt || line.scannedAt || '');
      return !at || at < scannerOpenedAt;
    });
    list.innerHTML = (hasPreviousDraft ? '<p class="empty">注意：下列含上次未儲存草稿，不是本次空白掃描產生；不需要請按「清空本次調撥單」。</p>' : '') + transferDraft.map(function (line, index) {
      var at = Date.parse(line.lastScannedAt || line.scannedAt || '');
      var scanLabel = (!at || at < scannerOpenedAt) ? '上次未儲存草稿' : '本次掃描';
      return '<article class="transfer-draft-line">' +
        '<img src="' + escapeHtml(resolveImage(line.image)) + '" alt="商品圖片">' +
        '<div><b>' + escapeHtml(line.productCode) + '｜' + escapeHtml(line.title || '') + '</b><span>' + escapeHtml(line.color) + '／' + escapeHtml(line.size) + '</span><small>' + escapeHtml(scanLabel) + '｜' + escapeHtml(line.from) + ' → ' + escapeHtml(computerMode ? '寶輝電腦倉' : line.to) + '｜來源可用 ' + Number(line.availableStock || 0) + ' 件</small></div>' +
        '<label>調撥數量<input type="number" min="1" max="' + Number(line.availableStock || 1) + '" step="1" value="' + Number(line.qty || 1) + '" data-transfer-line-qty="' + index + '"></label>' +
        '<button type="button" data-remove-transfer-line="' + index + '">移除</button>' +
        (computerMode ? '<label class="transfer-category-field' + (line.computerCategory ? '' : ' is-missing') + '">寶輝產品分類<select data-transfer-line-category="' + index + '">' + computerCategoryOptions(line.computerCategory) + '</select>' + (line.computerCategory ? '' : '<em>此欄必選；顏色與尺寸不需重選</em>') + '</label>' : '') +
      '</article>';
    }).join('');
  }
  function queueTransfer(row, code) {
    var from = sourceWarehouse.value;
    var to = transferMode() === 'computer' ? '寶輝電腦倉' : targetWarehouse.value;
    var scanQty = Math.max(1, Number(document.querySelector('[data-transfer-qty]').value || 1));
    if (!row || !row.skuId || !from || !to || from === to) {
      beep(3);
      setMessage('三聲：來源倉庫與目的倉庫設定不正確，尚未加入。', 'error');
      return;
    }
    var available = stockOf(row);
    if (!sameWarehouse(row.warehouse, from)) {
      beep(3); setMessage('所選商品在「' + row.warehouse + '」，但調撥來源是「' + from + '」。請先選正確來源倉再查詢；尚未加入或扣庫存。', 'error'); return;
    }
    if (available <= 0) {
      beep(3);
      setMessage('三聲：來源倉沒有庫存，尚未加入調撥單。', 'error');
      return;
    }
    var key = transferLineKey(row, from, to);
    var existingIndex = transferDraft.findIndex(function (line) { return line.key === key; });
    if (existingIndex !== -1) {
      var nextQty = Number(transferDraft[existingIndex].qty || 0) + scanQty;
      beep(2);
      if (nextQty > available) {
        setMessage('兩聲：疑似重複掃描，累加後會超過來源庫存 ' + available + ' 件，本次未增加。', 'error');
        return;
      }
      transferDraft[existingIndex].qty = nextQty;
      transferDraft[existingIndex].lastScannedAt = new Date().toISOString();
      saveTransferDraft();
      setMessage('兩聲：偵測到重複產品，已累加為 ' + nextQty + ' 件；尚未正式扣庫存。', 'error');
    } else {
      if (scanQty > available) {
        beep(3);
        setMessage('三聲：單次數量超過來源庫存 ' + available + ' 件，尚未加入。', 'error');
        return;
      }
      transferDraft.push({
        key: key,
        skuId: row.skuId,
        barcode: row.barcode || code || '',
        productCode: row.productCode || '',
        title: row.title || '',
        color: row.color || '',
        size: row.size || '',
        image: row.image || '',
        from: from,
        to: to,
        qty: scanQty,
        availableStock: available,
        transferMode: transferMode(),
        computerCategory: '',
        scannedAt: new Date().toISOString()
      });
      saveTransferDraft();
      beep(1);
      setMessage('一聲：已直接加入本次調撥單 ' + scanQty + ' 件；掃完請按「儲存整張調撥單」。', 'ok');
    }
    resetProductSelection();
    readyForNextScan();
  }
  function saveTransferSlip() {
    if (!transferDraft.length) {
      beep(3);
      setMessage('三聲：本次調撥單沒有產品，請先掃描。', 'error');
      return;
    }
    if (operator.role === 'unknown') {
      beep(3);
      setMessage('請先登入員工或管理者帳號，再儲存調撥單。', 'error');
      return;
    }
    var computerMode = transferMode() === 'computer';
    if (computerMode) {
      var missingIndex = transferDraft.findIndex(function (line) { return !String(line.computerCategory || '').trim(); });
      if (missingIndex !== -1) {
        beep(3);
        setMessage('請先替第 ' + (missingIndex + 1) + ' 項選擇寶輝產品分類；顏色與尺寸會自動維持。', 'error');
        renderTransferDraft();
        return;
      }
    }
    var saveButton = document.querySelector('[data-save-transfer-draft]');
    saveButton.disabled = true;
    saveButton.textContent = '正在儲存整張調撥單…';
    var request = computerMode ? fetchWithTimeout('./computer-products-api.php', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        action: 'scanner-cross-system-transfer',
        idempotencyKey: 'SCANNER-COMPUTER-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9),
        sourceWarehouse: sourceWarehouse.value,
        lines: transferDraft.map(function (line) { return {skuId: line.skuId, barcode: line.barcode, qty: Number(line.qty || 1), category: line.computerCategory}; })
      })
    }, 30000).then(function (res) { return res.json().then(function (payload) { if (!payload.ok) throw new Error(payload.error || '跨系統調撥失敗'); return payload; }); }) : post({
      action: 'transfer_batch',
      operator: operator,
      lines: transferDraft.map(function (line) {
        return {
          skuId: line.skuId,
          barcode: line.barcode,
          qty: Number(line.qty || 1),
          sourceWarehouse: line.from,
          targetWarehouse: line.to
        };
      })
    });
    request.then(function (payload) {
      var operations = computerMode ? ((payload.transfer && Array.isArray(payload.transfer.lines)) ? payload.transfer.lines.map(function (line) {
        return {skuId: '', barcode: line.barcode, qty: line.qty, sourceWarehouse: sourceWarehouse.value, targetWarehouse: '寶輝電腦倉', previousStock: line.sourceBefore, remainingStock: line.sourceAfter};
      }) : []) : (Array.isArray(payload.operations) ? payload.operations : []);
      operations.forEach(function (op) {
        var sourceLine = transferDraft.find(function (line) {
          return line.skuId === op.skuId && line.from === op.sourceWarehouse && line.to === op.targetWarehouse;
        }) || {};
        draft.unshift({
          at: op.createdAt || new Date().toISOString(),
          action: 'transfer',
          transferId: payload.transferId || op.transferId || '',
          productCode: sourceLine.productCode || op.productCode || '',
          color: sourceLine.color || op.color || '',
          size: sourceLine.size || op.size || '',
          qty: Number(op.qty || sourceLine.qty || 0),
          previousStock: Number(op.previousStock || 0),
          variance: 0,
          from: op.sourceWarehouse || sourceLine.from || '',
          to: op.targetWarehouse || sourceLine.to || '',
          image: sourceLine.image || '',
          operator: operator.name,
          transferredUnitCostTwd: Number(op.transferredUnitCostTwd || 0),
          costSource: op.costSource || ''
        });
      });
      draft = draft.slice(0, 200);
      saveDraft();
      transferDraft = [];
      saveTransferDraft();
      beep(1);
      var completedId = computerMode ? String(payload.transfer && payload.transfer.transferId || '') : String(payload.transferId || '');
      var completedQty = computerMode ? totals.qty : Number(payload.totalQty || 0);
      var transitNote = payload && payload.inTransit
        ? ('中國→台灣改為在途：台灣倉尚未入帳。' + (Array.isArray(payload.boundOrderIds) && payload.boundOrderIds.length ? '已綁客戶單 ' + payload.boundOrderIds.join('、') + '。' : '無客戶單時，之後整批驗收當台灣現貨。'))
        : (computerMode ? '服裝來源倉與寶輝電腦倉已同步更新。' : '庫存已正式更新。');
      setMessage('一聲：調撥單 ' + completedId + ' 已儲存成功，共 ' + completedQty + ' 件，' + transitNote, 'ok');
      readyForNextScan();
    }).catch(function (error) {
      saveButton.disabled = false;
      renderTransferDraft();
      beep(3);
      setMessage('三聲：調撥單儲存失敗，庫存未變更。' + (error.message || ''), 'error');
    });
  }
  function stockinDraftTotals() {
    return stockinDraft.reduce(function (totals, line) {
      var qty = Math.max(1, Number(line.qty || 1));
      var cost = Math.max(0, Number(line.unitCostTwd || 0));
      totals.items += 1;
      totals.qty += qty;
      totals.cost += qty * cost;
      if (!String(line.category || '').trim() || String(line.category || '').trim() === '快速入庫待補') totals.pendingCategory += 1;
      if (!String(line.color || '').trim() || String(line.color || '').trim() === '待補顏色') totals.pendingColor += 1;
      if (cost <= 0) totals.pendingCost += 1;
      if (!String(line.shelf || '').trim() || !String(line.layer || '').trim()) totals.pendingLocation += 1;
      return totals;
    }, {items: 0, qty: 0, cost: 0, pendingCategory: 0, pendingColor: 0, pendingCost: 0, pendingLocation: 0});
  }
  function stockinMoney(value) {
    return 'NT$' + Math.round(Number(value || 0) * 100) / 100;
  }
  function normalizeStockinCategoryList(values) {
    var seen = {};
    return (values || []).map(function (value) {
      return String(value == null ? '' : value).trim();
    }).filter(function (value) {
      if (!value || value === '快速入庫待補' || value === '請先選分類' || seen[value]) return false;
      seen[value] = true;
      return true;
    });
  }
  function setStockinCategories(values) {
    stockinCategories = normalizeStockinCategoryList(values);
  }
  function stockinCategoryOptions(selected) {
    selected = String(selected || '').trim();
    var list = normalizeStockinCategoryList(['電腦', '服裝'].concat(stockinCategories));
    if (selected && selected !== '快速入庫待補' && list.indexOf(selected) === -1) list.unshift(selected);
    return '<option value="">請選分類</option>' + list.map(function (item) {
      return '<option value="' + escapeHtml(item) + '"' + (item === selected ? ' selected' : '') + '>' + escapeHtml(item) + '</option>';
    }).join('') + '<option value="快速入庫待補"' + (selected === '快速入庫待補' ? ' selected' : '') + '>稍後補分類</option>';
  }
  function stockinUniqueValues(values) {
    var seen = {};
    return values.map(function (value) { return String(value == null ? '' : value).trim(); }).filter(function (value) {
      if (!value || seen[value]) return false;
      seen[value] = true;
      return true;
    });
  }
  function stockinSelectOptions(values, selected) {
    selected = String(selected == null ? '' : selected).trim();
    var list = stockinUniqueValues((selected ? [selected] : []).concat(values || []));
    return list.map(function (value) {
      return '<option value="' + escapeHtml(value) + '"' + (value === selected ? ' selected' : '') + '>' + escapeHtml(value) + '</option>';
    }).join('');
  }
  function stockinColorOptions(selected, extraValues) {
    return stockinSelectOptions((extraValues || []).concat(stockinColors), selected);
  }
  function stockinSizeOptions(selected, extraValues) {
    var sizes = ['NO SIZE', 'XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'];
    for (var shoe = 35; shoe <= 45; shoe += 1) sizes.push(String(shoe));
    return stockinSelectOptions((extraValues || []).concat(sizes), stockinCanonicalSize(selected));
  }
  function stockinQtyOptions(selected) {
    var qty = Math.max(1, Number(selected || 1));
    var values = [];
    for (var value = 1; value <= 100; value += 1) values.push(String(value));
    if (qty > 100) values.unshift(String(qty));
    return stockinSelectOptions(values, String(qty));
  }
  function initStockinSelectControls() {
    var scanQty = document.querySelector('[data-stockin-qty]');
    var pendingQty = document.querySelector('[data-pending-qty]');
    var defaultColor = document.querySelector('[data-stockin-default-color]');
    var pendingColor = document.querySelector('[data-pending-color]');
    var pendingSize = document.querySelector('[data-pending-size]');
    if (scanQty) scanQty.innerHTML = stockinQtyOptions(1);
    if (pendingQty) pendingQty.innerHTML = stockinQtyOptions(1);
    if (defaultColor) defaultColor.innerHTML = '<option value="">沿用掃描產品顏色</option>' + stockinColorOptions('', []);
    if (pendingColor) pendingColor.innerHTML = '<option value="">請選顏色</option>' + stockinColorOptions('', []);
    if (pendingSize) pendingSize.innerHTML = stockinSizeOptions('NO SIZE', []);
  }
  function stockinCostFromBarcode(code) {
    var value = String(code || '').toUpperCase().replace(/[^0-9A-Z]/g,'');
    var v3 = value.match(/C[A-Z0-9]+S[A-Z0-9]+P(\d+)$/);
    if (v3) return Number(v3[1]) || 0;
    var v2 = value.match(/P(\d+)C[A-Z0-9]+S[A-Z0-9]+$/);
    if (v2) return Number(v2[1]) || 0;
    var pend = value.match(/P(\d{1,4})$/);
    if (pend && value.indexOf('P') > 0 && !/P\d+C[A-Z0-9]+S/.test(value)) {
      return Number(pend[1]) || 0;
    }
    var pIndex = value.indexOf('P');
    if (pIndex < 1) return 0;
    var tail = value.slice(pIndex + 1);
    var separator = tail.lastIndexOf('9');
    if (separator < 1) return 0;
    var raw = tail.slice(0, separator);
    if (!/^\d+(?:\.\d+)?$/.test(raw)) return 0;
    var cost = Number(raw);
    return Number.isFinite(cost) && cost > 0 ? cost : 0;
  }
  function stockinCategoryFromCode(code) {
    var match = String(code || '').toUpperCase().match(/^[A-Z]+/);
    if (!match) return '';
    var letters = match[0];
    var prefixes = Object.keys(stockinCategoryPrefixes).sort(function (a, b) { return b.length - a.length; });
    for (var i = 0; i < prefixes.length; i += 1) {
      if (letters.indexOf(prefixes[i]) !== 0) continue;
      var meta = stockinCategoryPrefixes[prefixes[i]] || {};
      if (Number(meta.count || 0) < 1 || Number(meta.confidence || 0) < 0.6) return '';
      return String(meta.category || '');
    }
    return '';
  }
  function stockinProductCodeFromBarcode(code) {
    var normalized = String(code || '').trim().toUpperCase().replace(/\s+/g, '');
    var v3 = normalized.match(/^([A-Z]+\d+)C[A-Z0-9]+S[A-Z0-9]+P\d+$/);
    if (v3) return v3[1];
    var v2 = normalized.match(/^([A-Z]+\d+)P\d+C[A-Z0-9]+S[A-Z0-9]+$/);
    if (v2) return v2[1];
    var barcodeMatch = normalized.match(/^([A-Z]+\d+)P\d/);
    if (barcodeMatch) return barcodeMatch[1];
    var productMatch = normalized.match(/^[A-Z]+\d+/);
    return productMatch ? productMatch[0] : normalized;
  }
  function scannedCodeMode(code) {
    var normalized = String(code || '').trim().toUpperCase().replace(/\s+/g, '');
    if (!normalized) return 'series';
    if (/^[A-Z]+\d+C[A-Z0-9]+S[A-Z0-9]+P\d+$/.test(normalized)) return 'full';
    if (/^[A-Z]+\d+P\d+C[A-Z0-9]+S[A-Z0-9]+$/.test(normalized)) return 'full';
    /* LZ_SCAN_LOOKUP_20260924: {編號}{色碼}{尺碼}P{成本} has extra digits before P. */
    if (/^[A-Z]+\d{4,}P\d+$/.test(normalized)) return 'full';
    var productCode = stockinProductCodeFromBarcode(normalized);
    return productCode && productCode !== normalized && normalized.indexOf(productCode + 'P') === 0 ? 'full' : 'series';
  }
  function stockinCanonicalSize(value) {
    var normalized = String(value || '').normalize('NFKC').replace(/\s+/g, ' ').trim().toUpperCase();
    if (!normalized || /^(未填|未設定尺寸|未設定|未選尺寸|未帶尺寸|未帶|沒尺寸|無尺寸|没有尺寸|均碼|均一尺寸|單一尺寸|NO\s*SIZE|NO[-_]?SIZE|FREE(?:\s*SIZE)?|FREESIZE|NONE|UNKNOWN)$/i.test(normalized)) return 'NO SIZE';
    return normalized;
  }
  function stockinLineKey(line) {
    return [String(line.skuId || line.rawCode || ''), String(line.warehouse || ''), String(line.shelf || ''), String(line.layer || ''), String(line.color || ''), stockinCanonicalSize(line.size), String(line.category || '')].join('|');
  }
  function stockinWarehouseOptions(selected) {
    var values = Array.isArray(warehouses) ? warehouses.filter(Boolean) : [];
    if (selected && values.indexOf(selected) === -1) values.unshift(selected);
    return values.map(function (value) {
      return '<option value="' + escapeHtml(value) + '"' + (String(selected || '') === String(value) ? ' selected' : '') + '>' + escapeHtml(value) + '</option>';
    }).join('');
  }
  function stockinLocationOptions(list, selected, emptyLabel) {
    var values = Array.isArray(list) ? list.filter(Boolean) : [];
    if (selected && values.indexOf(selected) === -1) values.unshift(selected);
    return '<option value="">' + escapeHtml(emptyLabel || '可空白') + '</option>' + values.map(function (value) {
      return '<option value="' + escapeHtml(value) + '"' + (String(selected || '') === String(value) ? ' selected' : '') + '>' + escapeHtml(value) + '</option>';
    }).join('');
  }
  function fillLocationSelect(select, list, selected, emptyLabel) {
    if (!select) return;
    select.innerHTML = stockinLocationOptions(list, selected || '', emptyLabel || '可空白');
  }
  function refreshLocationSelects() {
    var stockinShelf = document.querySelector('[data-stockin-shelf]');
    var stockinLayer = document.querySelector('[data-stockin-layer]');
    var pendingShelf = document.querySelector('[data-pending-shelf]');
    var pendingLayer = document.querySelector('[data-pending-layer]');
    fillLocationSelect(stockinShelf, stockinShelves, stockinShelf && stockinShelf.value, '不指定貨架');
    fillLocationSelect(stockinLayer, stockinLayers, stockinLayer && stockinLayer.value, '不指定層位');
    fillLocationSelect(pendingShelf, stockinShelves, pendingShelf && pendingShelf.value, '不指定貨架');
    fillLocationSelect(pendingLayer, stockinLayers, pendingLayer && pendingLayer.value, '不指定層位');
    syncNoSpaceLocations();
  }
  function syncNoSpaceLocations() {
    ['stockin', 'pending', 'match'].forEach(function (prefix) {
      var shelf = document.querySelector('[data-' + prefix + '-shelf]');
      var layer = document.querySelector('[data-' + prefix + '-layer]');
      if (!shelf || !layer) return;
      if (!Array.prototype.some.call(shelf.options, function (option) { return option.value === '無空間擺放'; })) shelf.add(new Option('無空間擺放', '無空間擺放'));
      if (shelf.value === '無空間擺放') {
        if (!Array.prototype.some.call(layer.options, function (option) { return option.value === '不適用'; })) layer.add(new Option('不需選層位', '不適用'));
        layer.value = '不適用';
        layer.disabled = true;
      } else if (layer.disabled) {
        layer.disabled = false;
        if (layer.value === '不適用') layer.value = '';
      }
    });
  }
  function isInvalidStockinShelfName(value) {
    var text = String(value || '').trim();
    return text === 'D倉-R01' || /[區区]/.test(text);
  }
  function isInvalidStockinLayerName(value) {
    var text = String(value || '').trim();
    return (text.match(/第[^層层]+[層层]/g) || []).length > 1;
  }
  function applyStockinNewLocation() {
    var shelfInput = document.querySelector('[data-stockin-new-shelf]');
    var layerInput = document.querySelector('[data-stockin-new-layer]');
    var shelf = String(shelfInput && shelfInput.value || '').trim();
    var layer = String(layerInput && layerInput.value || '').trim();
    if (!shelf && !layer) {
      setMessage('請輸入要新增的貨架或層位。', 'error');
      return;
    }
    if (shelf && isInvalidStockinShelfName(shelf)) {
      setMessage('貨架名稱不能包含「區」，請輸入實際的「架」名稱。', 'error');
      return;
    }
    if (layer && isInvalidStockinLayerName(layer)) {
      setMessage('層位必須分開建立，不能把兩個層位放在同一項。', 'error');
      return;
    }
    post({
      action: 'location_settings',
      shelves: shelf ? [shelf] : [],
      layers: layer ? [layer] : [],
      operator: operator
    }).then(function (payload) {
      stockinShelves = Array.isArray(payload.shelves) ? payload.shelves.filter(Boolean) : stockinShelves;
      stockinLayers = Array.isArray(payload.layers) ? payload.layers.filter(Boolean) : stockinLayers;
      if (shelfInput) shelfInput.value = '';
      if (layerInput) layerInput.value = '';
      refreshLocationSelects();
      var shelfSelect = document.querySelector('[data-stockin-shelf]');
      var layerSelect = document.querySelector('[data-stockin-layer]');
      if (shelf && shelfSelect) shelfSelect.value = shelf;
      if (layer && layerSelect) layerSelect.value = layer;
      var filled = applyStockinDefaultLocation({ emptyOnly: true, skipRender: false, allowEmpty: true, quietIfEmpty: true });
      setMessage(filled ? '已新增並套用上架位置，同時補上 ' + filled + ' 筆尚未指定倉架的品項。' : '已新增並套用上架位置；後續掃描會帶入此倉架。', 'ok');
    }).catch(function (error) {
      setMessage(error.message || '新增上架位置失敗。', 'error');
    });
  }
  function signedDiff(value) {
    value = Number(value || 0);
    return (value > 0 ? '+' : '') + value + ' 件';
  }
  function refreshStocktakeCompare() {
    var qtyInput = document.querySelector('[data-qty]');
    var actual = Math.max(0, Math.floor(Number(qtyInput && qtyInput.value || 0)));
    var beforeEl = document.querySelector('[data-before-stock]');
    var book = Math.max(0, Math.floor(Number(beforeEl && beforeEl.getAttribute('data-book-stock') || 0)));
    var lastRaw = selected && selected.lastCountedStock != null ? Number(selected.lastCountedStock) : null;
    var lastEl = document.querySelector('[data-last-counted-stock]');
    var lastDiffEl = document.querySelector('[data-stocktake-last-diff]');
    var bookDiffEl = document.querySelector('[data-stocktake-book-diff]');
    if (lastEl) lastEl.textContent = lastRaw == null ? '無紀錄' : lastRaw + ' 件';
    if (lastDiffEl) {
      lastDiffEl.textContent = lastRaw == null ? '第一次盤點' : signedDiff(actual - lastRaw);
      lastDiffEl.setAttribute('data-state', lastRaw == null ? 'neutral' : (actual - lastRaw === 0 ? 'same' : 'diff'));
    }
    if (bookDiffEl) {
      bookDiffEl.textContent = signedDiff(actual - book);
      bookDiffEl.setAttribute('data-state', actual - book === 0 ? 'same' : 'diff');
    }
  }
  function setPendingPastedPhoto(dataUrl, label) {
    if (!pendingPanel || !dataUrl) return;
    pendingPanel._pastedPhotoData = dataUrl;
    var preview = document.querySelector('[data-pending-photo-preview]');
    if (preview) { preview.src = dataUrl; preview.hidden = false; }
    var box = document.querySelector('[data-pending-photo-paste]');
    if (box) box.classList.add('has-photo');
    setMessage((label || '照片') + ' 已貼上；補齊欄位後可手動加入批次。', 'ok');
  }
  function setStockinBatchPhoto(dataUrl, label) {
    var box = document.querySelector('[data-stockin-photo-box]');
    var preview = document.querySelector('[data-stockin-photo-preview]');
    var status = document.querySelector('[data-stockin-photo-status]');
    if (!box || !dataUrl) return;
    box._stockinPhotoData = dataUrl;
    box.classList.add('has-photo');
    if (preview) { preview.src = dataUrl; preview.hidden = false; }
    if (status) status.textContent = (label || '照片') + ' 已帶入；下一筆掃到的快速入庫商品會自動附上。';
    setMessage((label || '照片') + ' 已帶入快速入庫批次。', 'ok');
  }
  function clearStockinBatchPhoto(messageText) {
    var box = document.querySelector('[data-stockin-photo-box]');
    var preview = document.querySelector('[data-stockin-photo-preview]');
    var status = document.querySelector('[data-stockin-photo-status]');
    var fileInput = document.querySelector('[data-stockin-photo]');
    if (box) { box._stockinPhotoData = ''; box.classList.remove('has-photo'); }
    if (preview) { preview.src = ''; preview.hidden = true; }
    if (fileInput) fileInput.value = '';
    if (status) status.textContent = '可先拍照或貼圖，下一筆掃到的商品會自動帶入這張照片。';
    if (messageText) setMessage(messageText, 'ok');
  }
  function stockinBatchPhotoData() {
    var box = document.querySelector('[data-stockin-photo-box]');
    return String(box && box._stockinPhotoData || '');
  }
  function scannerPhotoRole(kind) {
    var target = kind === 'stockin' ? document.querySelector('[data-stockin-photo-box]') : kind === 'pending' ? document.querySelector('[data-photo-role-group="pending"]') : document.querySelector('[data-stocktake-photo-box]');
    return String(target && target._photoRole || 'color');
  }
  function setStocktakePhoto(dataUrl, label) {
    var box = document.querySelector('[data-stocktake-photo-box]');
    var preview = document.querySelector('[data-stocktake-photo-preview]');
    var status = document.querySelector('[data-stocktake-photo-status]');
    if (!box || !dataUrl) return;
    box._stocktakePhotoData = dataUrl;
    box.hidden = false;
    box.classList.add('has-photo');
    var toggle = document.querySelector('[data-stocktake-photo-toggle]');
    if (toggle) { toggle.setAttribute('aria-expanded', 'true'); toggle.textContent = '－ 收合照片功能'; }
    if (preview) { preview.src = dataUrl; preview.hidden = false; }
    if (status) status.textContent = (label || '照片') + ' 已附加，會跟著這筆 SKU 送主管確認。';
    setMessage((label || '照片') + ' 已附加到本筆盤點。', 'ok');
  }
  function clearStocktakePhoto(messageText) {
    var box = document.querySelector('[data-stocktake-photo-box]');
    var preview = document.querySelector('[data-stocktake-photo-preview]');
    var status = document.querySelector('[data-stocktake-photo-status]');
    var fileInput = document.querySelector('[data-stocktake-photo]');
    if (box) { box._stocktakePhotoData = ''; box.classList.remove('has-photo'); }
    if (preview) { preview.src = ''; preview.hidden = true; }
    if (fileInput) fileInput.value = '';
    if (status) status.textContent = '手機／YGF 可直接拍照，電腦可選照片或貼上。';
    if (messageText) setMessage(messageText, 'ok');
  }
  function stocktakePhotoData() {
    var box = document.querySelector('[data-stocktake-photo-box]');
    return String(box && box._stocktakePhotoData || '');
  }
  function setStockinLinePhoto(index, dataUrl, label) {
    index = Number(index);
    if (!dataUrl || !stockinDraft[index]) return false;
    stockinDraft[index].imageData = dataUrl;
    saveStockinDraft();
    setMessage((label || '照片') + ' 已更新到第 ' + (index + 1) + ' 筆快速入庫。', 'ok');
    return true;
  }
  function clipboardImageData(event) {
    var items = event && event.clipboardData && event.clipboardData.items ? Array.from(event.clipboardData.items) : [];
    var imageItem = items.find(function (item) { return item && item.type && item.type.indexOf('image/') === 0; });
    if (!imageItem) return Promise.resolve('');
    var file = imageItem.getAsFile && imageItem.getAsFile();
    if (!file && pendingPanel && pendingPanel._pastedPhotoData) return Promise.resolve(pendingPanel._pastedPhotoData);
    if (!file) return Promise.resolve('');
    return scannerImageFileToDataUrl(file);
  }
  function persistStockinDraft(options) {
    try {
      localStorage.setItem(stockinDraftKey, JSON.stringify(stockinDraft));
    } catch (error) {
      try {
        var slim = stockinDraft.map(function (line, index) {
          var copy = Object.assign({}, line);
          if (index < stockinDraft.length - 2) copy.imageData = '';
          return copy;
        });
        localStorage.setItem(stockinDraftKey, JSON.stringify(slim));
      } catch (quotaError) {
        setMessage('照片已縮小顯示，但這台盤點機暫存空間不足；請先確認入庫，勿重新整理。', 'error');
      }
    }
    if (options && options.skipRender) {
      refreshStockinDraftChrome();
      return;
    }
    renderStockinDraft();
  }
  function saveStockinDraft() {
    persistStockinDraft();
  }
  function refreshStockinDraftChrome() {
    var totals = stockinDraftTotals();
    var total = document.querySelector('[data-stockin-draft-total]');
    var saveButton = document.querySelector('[data-save-stockin-draft]');
    if (total) total.textContent = totals.items + ' 項／' + totals.qty + ' 件／' + stockinMoney(totals.cost);
    if (saveButton) {
      saveButton.disabled = !stockinDraft.length;
      saveButton.textContent = '確認臨時建檔入庫（' + totals.qty + ' 件／' + stockinMoney(totals.cost) + '）';
    }
    stockinDraft.forEach(function (line, index) {
      var missingLocation = !String(line.shelf || '').trim() || !String(line.layer || '').trim();
      var article = document.querySelector('[data-stockin-draft-line="' + index + '"]');
      if (!article) return;
      article.classList.toggle('needs-location-adjust', missingLocation);
      var locationText = article.querySelector('[data-stockin-line-loc-text]');
      if (locationText) locationText.textContent = '位置：' + [line.shelf || '未指定貨架', line.layer || '未指定層位'].join('／');
      var warehouseText = article.querySelector('[data-stockin-line-wh-text]');
      if (warehouseText) warehouseText.textContent = (line.warehouse || '') + '｜入庫前 ' + Number(line.previousStock || 0) + ' 件';
      var warehouseSelect = article.querySelector('[data-stockin-line-warehouse]');
      var shelfSelect = article.querySelector('[data-stockin-line-shelf]');
      var layerSelect = article.querySelector('[data-stockin-line-layer]');
      if (warehouseSelect && String(warehouseSelect.value || '') !== String(line.warehouse || '')) warehouseSelect.value = line.warehouse || '';
      if (shelfSelect && String(shelfSelect.value || '') !== String(line.shelf || '')) {
        if (line.shelf && !Array.prototype.some.call(shelfSelect.options, function (option) { return option.value === line.shelf; })) shelfSelect.add(new Option(line.shelf, line.shelf));
        shelfSelect.value = line.shelf || '';
      }
      if (layerSelect && String(layerSelect.value || '') !== String(line.layer || '')) {
        if (line.layer && !Array.prototype.some.call(layerSelect.options, function (option) { return option.value === line.layer; })) layerSelect.add(new Option(line.layer, line.layer));
        layerSelect.value = line.layer || '';
      }
    });
  }
  function renderStockinDraft() {
    var panel = document.querySelector('[data-stockin-draft-panel]');
    var list = document.querySelector('[data-stockin-draft-list]');
    var total = document.querySelector('[data-stockin-draft-total]');
    var saveButton = document.querySelector('[data-save-stockin-draft]');
    if (!panel || !list || !total || !saveButton) return;
    panel.hidden = currentTab !== 'stockin';
    var totals = stockinDraftTotals();
    total.textContent = totals.items + ' 項／' + totals.qty + ' 件／' + stockinMoney(totals.cost);
    saveButton.disabled = !stockinDraft.length;
    saveButton.textContent = '確認臨時建檔入庫（' + totals.qty + ' 件／' + stockinMoney(totals.cost) + '）';
    if (!stockinDraft.length) {
      list.innerHTML = '<p class="empty">尚未掃描臨時建檔商品</p>';
      return;
    }
    list.innerHTML = stockinDraft.map(function (line, index) {
      var pending = !String(line.category || '').trim() || String(line.category || '') === '快速入庫待補' || !String(line.color || '').trim() || String(line.color || '') === '待補顏色' || Number(line.unitCostTwd || 0) <= 0;
      var missingLocation = !String(line.shelf || '').trim() || !String(line.layer || '').trim();
      var otherWarehouseNotice = Array.isArray(line.otherWarehouseOptions) && line.otherWarehouseOptions.length
        ? '<div class="stockin-other-warehouse"><b>⚠ 其他倉已有同規格庫存</b><span>' + line.otherWarehouseOptions.map(function (item) { return escapeHtml(item.warehouse + '：' + Number(item.stock || 0) + ' 件'); }).join('／') + '</span><small>可能是先前忘記調撥。請明確選擇，不會自動重複增加台灣庫存。</small><label>這次如何處理<select data-stockin-line-action="' + index + '"><option value="review"' + (line.inventoryAction === 'review' ? ' selected' : '') + '>請選擇處理方式</option><option value="stockin"' + (line.inventoryAction === 'stockin' ? ' selected' : '') + '>確定是新貨：快速入庫台灣倉</option>' + line.otherWarehouseOptions.map(function (item) { return '<option value="transfer:' + escapeHtml(item.skuId) + '"' + (line.inventoryAction === 'transfer' && line.sourceSkuId === item.skuId ? ' selected' : '') + '>從 ' + escapeHtml(item.warehouse) + ' 調回台灣（現有 ' + Number(item.stock || 0) + ' 件）</option>'; }).join('') + '</select></label></div>'
        : '';
      if (otherWarehouseNotice) {
        otherWarehouseNotice = '<div class="stockin-other-warehouse"><b>其他倉庫有此顏色／尺寸，請選擇帶入模式</b>' + line.otherWarehouseOptions.map(function (item) {
          return '<button type="button" data-stockin-mode-button="' + index + '" data-mode-value="transfer:' + escapeHtml(item.skuId) + '" aria-pressed="' + (line.inventoryAction === 'transfer' && line.sourceSkuId === item.skuId) + '">從' + escapeHtml(item.warehouse) + '調入' + escapeHtml(line.warehouse) + '（來源有 ' + Number(item.stock || 0) + ' 件）</button>';
        }).join('') + '<button type="button" data-stockin-mode-button="' + index + '" data-mode-value="stockin" aria-pressed="' + (line.inventoryAction === 'stockin') + '">直接帶入' + escapeHtml(line.warehouse) + '庫存（不扣其他倉）</button><p>調入：來源倉減少、目的倉增加。直接帶入：恢復既有實物庫存，不新增進貨支出。兩種都在最後確認入庫才寫入。</p><strong>目前選擇：' + escapeHtml(line.inventoryAction === 'review' ? '尚未選擇，不能送出' : line.inventoryAction === 'transfer' ? '從' + line.sourceWarehouse + '調入' + line.warehouse : '直接恢復' + line.warehouse + '庫存') + '</strong><select hidden data-stockin-line-action="' + index + '"><option value="review">請選擇</option><option value="stockin">直接帶入</option>' + line.otherWarehouseOptions.map(function (item) { return '<option value="transfer:' + escapeHtml(item.skuId) + '">調入</option>'; }).join('') + '</select></div>';
      }
      return '<article class="stockin-draft-line' + (pending ? ' is-pending' : '') + (missingLocation ? ' needs-location-adjust is-adjusting-location' : '') + '" data-stockin-draft-line="' + index + '">' +
        '<img src="' + escapeHtml(resolveImage(line.image || line.imageData)) + '" alt="商品圖片">' +
        '<div class="stockin-draft-identity"><b>完整條碼：' + escapeHtml(line.labelBarcode || line.rawCode || line.barcode || '待補條碼') + '</b><button type="button" class="stockin-print-barcode" data-print-stockin-barcode="' + index + '">列印完整條碼</button><span>商品系列碼：' + escapeHtml(line.catalogProductCode || stockinProductCodeFromBarcode(line.labelBarcode || line.rawCode || line.productCode || '')) + '</span><span data-stockin-line-wh-text="' + index + '">' + escapeHtml(line.warehouse || '') + '｜入庫前 ' + Number(line.previousStock || 0) + ' 件</span><small class="is-location" data-stockin-line-loc-text="' + index + '">位置：' + escapeHtml([line.shelf || '未指定貨架', line.layer || '未指定層位'].join('／')) + '</small><small>' + (line.provisional ? '兩種模式共存：完整條碼精確掃描，系列碼用於分類與同系列搜尋' : '既有產品：儲存後正式增加指定倉庫庫存') + '</small></div>' +
        otherWarehouseNotice + '<div class="stockin-draft-edit-grid">' +
          '<label>分類<select data-stockin-line-category="' + index + '">' + stockinCategoryOptions(line.category || '') + '</select></label>' +
          '<label>顏色<select data-stockin-line-color="' + index + '">' + stockinColorOptions(line.color, line.colorOptions || []) + '</select></label>' +
          '<label>尺寸<select data-stockin-line-size="' + index + '">' + stockinSizeOptions(line.size, line.sizeOptions || []) + '</select></label>' +
          '<label class="stockin-line-location-field">倉庫<select data-stockin-line-warehouse="' + index + '">' + stockinWarehouseOptions(line.warehouse || '') + '</select></label>' +
          '<label class="stockin-line-location-field">貨架<select data-stockin-line-shelf="' + index + '">' + stockinLocationOptions(stockinShelves, line.shelf || '', '請選貨架') + '</select></label>' +
          '<label class="stockin-line-location-field">層位<select data-stockin-line-layer="' + index + '">' + stockinLocationOptions(stockinLayers, line.layer || '', '請選層位') + '</select></label>' +
          '<label>數量<select data-stockin-line-qty="' + index + '">' + stockinQtyOptions(line.qty) + '</select></label>' +
          '<label>單件成本 NT$<input type="number" min="0" step="0.01" value="' + (Number(line.unitCostTwd || 0) || '') + '" data-stockin-line-cost="' + index + '" placeholder="必填"></label>' +
        '</div>' +
        '<div class="stockin-line-photo-actions"><button type="button" data-stockin-toggle-location="' + index + '">' + (missingLocation ? '請選正確貨架位置' : '調整貨架位置') + '</button><button type="button" class="scanner-live-camera-action" data-live-camera-photo="stockin-line:' + index + '">相機拍照</button><label>補照片<input type="file" accept="image/*" capture="environment" data-stockin-line-photo="' + index + '"></label><button type="button" data-stockin-line-paste-photo="' + index + '">貼上此列照片</button></div>' +
        '<button type="button" data-remove-stockin-line="' + index + '">移除</button>' +
      '</article>';
    }).join('');
  }
  function queueStockinRow(row, code) {
    var qty = Math.max(1, Number(document.querySelector('[data-stockin-qty]').value || 1));
    var defaultCategory = String(document.querySelector('[data-stockin-default-category]').value || '').trim();
    var defaultColor = String(document.querySelector('[data-stockin-default-color]').value || '').trim();
    var defaultCost = Math.max(0, Number(document.querySelector('[data-stockin-default-cost]').value || 0));
    var sameProductRows = allMatches.filter(function (candidate) {
      return String(candidate.productId || candidate.productCode || '') === String(row.productId || row.productCode || '');
    });
    var productCategory = String(row.category || '').trim();
    if (!productCategory || productCategory === '快速入庫待補' || productCategory === '未設定分類') productCategory = '';
    var targetWarehouse = String(warehouse.value || '').trim();
    var otherWarehouseRows = sameProductRows.filter(function (candidate) {
      return String(candidate.warehouse || '').trim() !== targetWarehouse
        && String(candidate.color || '') === String(row.color || '')
        && stockinCanonicalSize(candidate.size) === stockinCanonicalSize(row.size)
        && stockOf(candidate) > 0;
    }).sort(function (a, b) { return stockOf(b) - stockOf(a); });
    var sourceReference = otherWarehouseRows[0] || null;
    var line = {
      skuId: row.skuId,
      barcode: row.barcode || code || '',
      rawCode: code || row.barcode || '',
      labelBarcode: row.labelBarcode || '',
      catalogProductCode: row.productCode || stockinProductCodeFromBarcode(row.labelBarcode || code || row.barcode || ''),
      productCode: row.productCode || '',
      title: row.title || '',
      category: defaultCategory || productCategory || '快速入庫待補',
      color: defaultColor || row.color || '待補顏色',
      size: stockinCanonicalSize(row.size),
      colorOptions: stockinUniqueValues(sameProductRows.map(function (candidate) { return candidate.color; })),
      sizeOptions: stockinUniqueValues(sameProductRows.map(function (candidate) { return stockinCanonicalSize(candidate.size); })),
      warehouse: targetWarehouse,
      inventoryAction: sourceReference ? 'review' : 'stockin',
      sourceWarehouse: sourceReference ? String(sourceReference.warehouse || '').trim() : '',
      sourceSkuId: sourceReference ? String(sourceReference.skuId || '').trim() : '',
      sourceWarehouseStock: sourceReference ? stockOf(sourceReference) : 0,
      otherWarehouseOptions: otherWarehouseRows.map(function (candidate) {
        return { warehouse: String(candidate.warehouse || '').trim(), skuId: String(candidate.skuId || '').trim(), stock: stockOf(candidate) };
      }),
      qty: qty,
      previousStock: row.warehouse === targetWarehouse ? stockOf(row) : 0,
      unitCostTwd: defaultCost || Math.max(0, Number(row.currentCostTwd || 0)),
      shelf: String(document.querySelector('[data-stockin-shelf]').value || row.shelf || '').trim(),
      layer: String(document.querySelector('[data-stockin-layer]').value || row.layer || '').trim(),
      image: row.image || '',
      imageData: stockinBatchPhotoData(),
      imageRole: scannerPhotoRole('stockin'),
      provisional: false,
      scannedAt: new Date().toISOString()
    };
    line.key = stockinLineKey(line);
    var existingIndex = stockinDraft.findIndex(function (old) { return old.key === line.key; });
    if (existingIndex >= 0) {
      stockinDraft[existingIndex].qty = Number(stockinDraft[existingIndex].qty || 0) + qty;
      if (defaultCategory || productCategory) stockinDraft[existingIndex].category = defaultCategory || productCategory;
      if (defaultColor) stockinDraft[existingIndex].color = defaultColor;
      if (defaultCost > 0) stockinDraft[existingIndex].unitCostTwd = defaultCost;
      if (line.imageData) stockinDraft[existingIndex].imageData = line.imageData;
      beep(2);
      setMessage('兩聲：同一商品重複掃描，已累加為 ' + stockinDraft[existingIndex].qty + ' 件；尚未正式入庫。', 'error');
    } else {
      stockinDraft.push(line);
      beep(1);
      setMessage(sourceReference
        ? '一聲：台灣倉目前沒有此規格，但「' + sourceReference.warehouse + '」有 ' + stockOf(sourceReference) + ' 件。請選擇是新貨入庫，或從其他倉調回台灣。'
        : '一聲：已加入本次臨時建檔清單；掃完仍可補分類、顏色與成本。', sourceReference ? 'error' : 'ok');
    }
    saveStockinDraft();
    resetProductSelection();
    readyForNextScan();
  }
  function applyStockinDefault(field) {
    var selector = field === 'category' ? '[data-stockin-default-category]' : '[data-stockin-default-cost]';
    var raw = String(document.querySelector(selector).value || '').trim();
    if (!raw) { setMessage(field === 'category' ? '請先選擇本批預設分類。' : '請先輸入本批預設成本。', 'error'); return; }
    stockinDraft.forEach(function (line) { line[field === 'category' ? 'category' : 'unitCostTwd'] = field === 'category' ? raw : Math.max(0, Number(raw || 0)); });
    if (field === 'category') try { localStorage.setItem(stockinRecentCategoryKey, raw); } catch (error) {}
    saveStockinDraft();
    setMessage('已將相同' + (field === 'category' ? '分類' : '成本') + '套用本批 ' + stockinDraft.length + ' 個品項。', 'ok');
  }
  function currentStockinDefaultLocation() {
    var shelfSelect = document.querySelector('[data-stockin-shelf]');
    var layerSelect = document.querySelector('[data-stockin-layer]');
    return {
      warehouse: String(warehouse && warehouse.value || '').trim(),
      shelf: String(shelfSelect && shelfSelect.value || '').trim(),
      layer: String(layerSelect && layerSelect.value || '').trim()
    };
  }
  function applyStockinDefaultLocation(options) {
    options = options || {};
    if (!stockinDraft.length) {
      if (options.quietIfEmpty) return 0;
      setMessage('還沒有入庫品項。先選入庫倉與貨架／層位，掃碼後會自動帶入。', 'error');
      return 0;
    }
    var defaults = currentStockinDefaultLocation();
    if (!defaults.warehouse) { setMessage('請先選擇入庫倉。', 'error'); return 0; }
    if (!options.allowEmpty && !defaults.shelf && !defaults.layer) {
      setMessage('請先選擇本批貨架或層位。', 'error');
      return 0;
    }
    var changed = 0;
    stockinDraft.forEach(function (line) {
      var nextWarehouse = defaults.warehouse || line.warehouse;
      var nextShelf = options.emptyOnly && String(line.shelf || '').trim() ? line.shelf : (defaults.shelf || line.shelf);
      var nextLayer = options.emptyOnly && String(line.layer || '').trim() ? line.layer : (defaults.layer || line.layer);
      if (options.emptyOnly) {
        if (!String(line.shelf || '').trim() && defaults.shelf) nextShelf = defaults.shelf;
        if (!String(line.layer || '').trim() && defaults.layer) nextLayer = defaults.layer;
        if (!options.forceWarehouse) nextWarehouse = line.warehouse;
      }
      if (String(line.warehouse || '') === String(nextWarehouse || '') && String(line.shelf || '') === String(nextShelf || '') && String(line.layer || '') === String(nextLayer || '')) return;
      line.warehouse = nextWarehouse;
      line.shelf = nextShelf;
      line.layer = nextLayer;
      line.key = stockinLineKey(line);
      changed += 1;
    });
    persistStockinDraft({ skipRender: options.skipRender !== false });
    return changed;
  }
  function stockinSuccessSummary(lines, batchId, totalQty, allocations) {
    var locations = {};
    (lines || []).forEach(function (line) {
      var warehouseName = String(line.warehouse || warehouse.value || '未指定倉別').trim();
      var shelfName = String(line.shelf || '').trim();
      var layerName = String(line.layer || '').trim();
      var key = [warehouseName, shelfName, layerName].join('|');
      if (!locations[key]) locations[key] = { warehouse: warehouseName, shelf: shelfName, layer: layerName, qty: 0 };
      locations[key].qty += Math.max(1, Number(line.qty || 1));
    });
    var detail = Object.keys(locations).map(function (key) {
      var item = locations[key];
      var location = [item.shelf, item.layer].filter(Boolean).join('／');
      return '已轉入' + item.warehouse + (location ? '（' + location + '）' : '') + '：' + item.qty + ' 件';
    });
    var allocatedRows = Array.isArray(allocations) ? allocations : [];
    var allocatedQty = allocatedRows.reduce(function (sum, row) { return sum + Math.max(0, Number(row && row.qty || 0)); }, 0);
    var allocatedOrders = Array.from(new Set(allocatedRows.map(function (row) { return String(row && row.orderId || ''); }).filter(Boolean)));
    var allocationText = allocatedQty > 0
      ? '\n\n自動配貨：' + allocatedQty + ' 件，已配給 ' + allocatedOrders.length + ' 張排程／寄庫單；這些數量不會留在公司可用現貨。'
      : '\n\n自動配貨：本批沒有符合「同商品＋同顏色＋同尺寸」的待配客戶，數量保留為公司現貨。';
    return '臨時建檔已完成' + (batchId ? '｜' + batchId : '') + '\n\n' + detail.join('\n') + '\n\n合計 ' + Number(totalQty || 0) + ' 件，已寫入正式庫存。' + allocationText;
  }
  function stockinSaveRequestId(lines) {
    var fingerprintText = JSON.stringify((lines || []).map(function (line) {
      return [line.skuId || '', line.rawCode || line.barcode || '', line.productCode || '', line.color || '', line.size || '', line.warehouse || '', Number(line.qty || 0), Number(line.unitCostTwd || 0), line.shelf || '', line.layer || '', line.inventoryAction || '', line.sourceSkuId || '', String(line.imageData || '').length];
    }));
    var hash = 2166136261;
    for (var i = 0; i < fingerprintText.length; i += 1) hash = Math.imul(hash ^ fingerprintText.charCodeAt(i), 16777619) >>> 0;
    var fingerprint = String(hash >>> 0) + '-' + fingerprintText.length;
    var saved = readJson(stockinRequestKey, null);
    if (saved && saved.fingerprint === fingerprint && saved.id) return String(saved.id);
    var randomPart = '';
    try { randomPart = crypto.randomUUID ? crypto.randomUUID() : ''; } catch (error) {}
    if (!randomPart) randomPart = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    var request = { fingerprint: fingerprint, id: 'STOCKIN-' + randomPart.toUpperCase(), createdAt: new Date().toISOString() };
    try { localStorage.setItem(stockinRequestKey, JSON.stringify(request)); } catch (error) {}
    return request.id;
  }
  function saveStockinBatch() {
    if (!stockinDraft.length) { beep(3); setMessage('三聲：本次臨時建檔清單沒有產品。', 'error'); return; }
    if (operator.role === 'unknown') { beep(3); setMessage('請先登入員工或管理者帳號。', 'error'); return; }
    var totals = stockinDraftTotals();
    if (totals.pendingCost > 0) { beep(3); setMessage('三聲：還有 ' + totals.pendingCost + ' 個品項未填單件成本，請補齊後再儲存。', 'error'); return; }
    // Initial inventory setup may be saved before a shelf/layer is assigned.
    var undecided = stockinDraft.filter(function (line) { return line.inventoryAction === 'review'; }).length;
    if (undecided) { beep(3); setMessage('三聲：有 ' + undecided + ' 個品項在其他倉已有庫存。請先選擇「新貨入庫」或「從其他倉調回」。', 'error'); return; }
    var button = document.querySelector('[data-save-stockin-draft]');
    button.disabled = true;
    button.textContent = '正在寫入正式庫存…';
    var clientRequestId = stockinSaveRequestId(stockinDraft);
    post({
      action: 'quick_stockin_batch',
      entryType: 'initial_inventory_setup',
      clientRequestId: clientRequestId,
      operator: operator,
      warehouse: warehouse.value,
      lines: stockinDraft.map(function (line) {
        return {
          skuId: line.skuId || '',
          rawCode: line.rawCode || '',
          barcode: line.barcode || line.rawCode || '',
          productCode: line.productCode || '',
          catalogProductCode: line.catalogProductCode || stockinProductCodeFromBarcode(line.rawCode || line.productCode || ''),
          title: line.title || '',
          category: String(line.category || '').trim() || '快速入庫待補',
          color: String(line.color || '').trim() || '待補顏色',
          size: stockinCanonicalSize(line.size),
          warehouse: line.warehouse || warehouse.value,
          qty: Math.max(1, Number(line.qty || 1)),
          unitCostTwd: Math.max(0, Number(line.unitCostTwd || 0)),
          shelf: line.shelf || '',
          layer: line.layer || '',
          imageData: line.imageData || '',
          imageRole: line.imageRole || 'color',
          provisional: !!line.provisional,
          inventoryAction: line.inventoryAction || 'stockin',
          sourceWarehouse: line.sourceWarehouse || '',
          sourceSkuId: line.sourceSkuId || ''
        };
      })
    }).then(function (payload) {
      if (!payload.batchId) throw new Error('未取得入庫單號，請先核對入庫紀錄');
      var original = stockinDraft.slice();
      (payload.operations || []).forEach(function (op, index) {
        var source = original[index] || {};
        draft.unshift({
          at: op.createdAt || new Date().toISOString(),
          action: 'stockin',
          stockinBatchId: payload.batchId || '',
          operationId: op.id || '',
          targetSkuId: op.targetSkuId || '',
          productCode: op.productCode || source.productCode || source.rawCode || '',
          color: op.color || source.color || '',
          size: op.size || source.size || '',
          category: op.category || source.category || '',
          qty: Number(op.qty || source.qty || 0),
          previousStock: Number(op.previousStock || 0),
          variance: Number(op.qty || source.qty || 0),
          from: op.warehouse || source.warehouse || '',
          to: op.warehouse || source.warehouse || '',
          image: op.image || source.image || '',
          operator: operator.name,
          unitCostTwd: Number(op.unitCostTwd || 0),
          totalCostTwd: Number(op.totalCostTwd || 0),
          pendingMetadata: !!op.pendingMetadata
        });
      });
      draft = draft.slice(0, 200);
      saveDraft();
      stockinDraft = [];
      saveStockinDraft();
      try { localStorage.removeItem(stockinRequestKey); } catch (error) {}
      beep(1);
      var successText = stockinSuccessSummary(original, payload.batchId || '', payload.totalQty || totals.qty, payload.customerAllocations || []);
      setMessage(successText.replace(/\n+/g, '｜'), 'ok');
      button.disabled = true;
      button.textContent = '✓ 儲存成功｜' + (payload.batchId || '庫存已確認');
      var successOverlay = document.createElement('div');
      successOverlay.setAttribute('data-stockin-commit-success', '');
      successOverlay.setAttribute('role', 'status');
      successOverlay.style.cssText = 'position:fixed;z-index:99999;inset:0;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:20px;color:#fff;text-align:center;font-size:24px;font-weight:900;white-space:pre-line';
      successOverlay.textContent = '✓ 庫存完整儲存成功\n' + (payload.batchId || '') + '\n3 秒後才開放下一筆';
      document.body.appendChild(successOverlay);
      setTimeout(function () {
        if (successOverlay.parentNode) successOverlay.remove();
        button.disabled = false;
        renderStockinDraft();
        readyForNextScan();
      }, 3000);
    }).catch(function (error) {
      button.disabled = false;
      renderStockinDraft();
      beep(3);
      var failureText = '臨時建檔未取得成功回覆：' + (error.message || '未知錯誤') + '。請先核對入庫紀錄，勿連續重送。';
      setMessage(failureText, 'error');
      window.alert(failureText);
    });
  }
  function setMessage(text, type) {
    message.textContent = text;
    message.className = type ? 'is-' + type : '';
    var saveStockinButton = document.querySelector('[data-save-stockin-draft]');
    if (saveStockinButton && type === 'error' && currentTab === 'stockin') {
      var inlineError = document.querySelector('[data-stockin-submit-error]');
      if (!inlineError) {
        inlineError = document.createElement('p');
        inlineError.setAttribute('data-stockin-submit-error', '');
        inlineError.setAttribute('role', 'alert');
        inlineError.style.cssText = 'color:#a40019;background:#fff0f0;padding:12px;white-space:normal;';
        saveStockinButton.parentNode.insertBefore(inlineError, saveStockinButton.nextSibling);
      }
      inlineError.textContent = text;
      inlineError.scrollIntoView({block:'nearest'});
    }
  }
  function resetProductSelection() {
    selected = null;
    allMatches = [];
    matchPanel.hidden = true;
    matchGrid.innerHTML = '';
    var crossWarehouseChoice = document.querySelector('[data-cross-warehouse-choice]');
    if (crossWarehouseChoice) crossWarehouseChoice.hidden = true;
    crossWarehouseContext = null;
    operationPanel.hidden = currentTab !== 'stockin';
    var beforeStock = document.querySelector('[data-before-stock]');
    var stocktakeQty = document.querySelector('[data-qty]');
    var transferQty = document.querySelector('[data-transfer-qty]');
    var stockinQty = document.querySelector('[data-stockin-qty]');
    var stockinSummary = document.querySelector('[data-stockin-summary]');
    if (beforeStock) { beforeStock.textContent = '—'; beforeStock.setAttribute('data-book-stock', '0'); }
    var lastCounted = document.querySelector('[data-last-counted-stock]');
    var lastDiff = document.querySelector('[data-stocktake-last-diff]');
    var bookDiff = document.querySelector('[data-stocktake-book-diff]');
    if (lastCounted) lastCounted.textContent = '—';
    if (lastDiff) lastDiff.textContent = '—';
    if (bookDiff) bookDiff.textContent = '—';
    if (stocktakeQty) stocktakeQty.value = '';
    if (transferQty) transferQty.value = '1';
    if (stockinQty) stockinQty.value = '1';
    if (stockinSummary) stockinSummary.textContent = '先選建檔倉與本批預設值；掃碼會加入下方清單，送審前仍可逐筆修改。';
  }
  function readyForNextScan() {
    var hardwareScanIsBeingTyped = normalizeScanCode(hardwareScanBuffer || '').length > 0;
    clearTimeout(inputLookupTimer);
    if (!hardwareScanIsBeingTyped) {
      clearTimeout(hardwareScanTimer);
      hardwareScanBuffer = '';
    }
    lastLookupCode = '';
    lastLookupAt = 0;
    if (input && !hardwareScanIsBeingTyped) input.value = '';
    if (manualInput) manualInput.value = '';
    focusActiveScanInput();
  }
  function resetTransientScannerUi() {
    resetProductSelection();
    currentStocktakeScanCode = '';
    stocktakeLiveCounts = {};
    stocktakeFlowLock = {barcode:'',skuId:'',shelf:'',layer:''};
    stocktakeChangingLocation = false;
    hardwareScanBuffer = '';
    lastAcceptedHardwareCode = '';
    lastAcceptedHardwareAt = 0;
    if (input) input.value = '';
    updateScanEcho('', '新畫面已就緒，等待掃描槍');
    renderStocktakeFastRows();
    readyForNextScan();
  }
  function stopForMissingStock(text) {
    resetProductSelection();
    hidePending();
    readyForNextScan();
    beep(3);
    setMessage('三聲：' + text + ' 已清空，請掃描下一筆。', 'error');
  }
  function beep(count) {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = window.__scannerAudio || (window.__scannerAudio = new Ctx());
      if (ctx.state === 'suspended') ctx.resume();
      for (var i = 0; i < count; i += 1) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        var at = ctx.currentTime + i * 0.16;
        osc.frequency.value = count === 1 ? 920 : count === 2 ? 620 : 420;
        gain.gain.setValueAtTime(0.26, at);
        gain.gain.exponentialRampToValueAtTime(0.001, at + 0.1);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(at);
        osc.stop(at + 0.11);
      }
    } catch (error) {}
  }
  function resolveImage(src) {
    return src || './assets/brand-logo.png';
  }
  function costMoney(value) {
    var amount = Number(value || 0);
    return 'NT$' + (Number.isFinite(amount) ? amount : 0).toLocaleString('zh-TW', {maximumFractionDigits: 2});
  }
  function costSourceLabel(source) {
    var value = String(source || '');
    if (value.indexOf('freight_receipt:') === 0) return '進貨成本（' + value.slice('freight_receipt:'.length) + '）';
    if (value === 'freight_receipt') return '進貨成本';
    if (/manual|override|table/i.test(value)) return '表格手動成本';
    if (value === 'product_current_cost') return '產品目前成本';
    if (value === 'product_final_cost') return '產品最終進貨成本';
    if (value === 'product_master_cost') return '產品主檔成本';
    if (value === 'product_purchase_cost') return '產品採購成本';
    if (value === 'legacy_barcode_cost') return '舊 P 條碼備援';
    if (value === 'sku_cost_fallback') return 'SKU 成本備援';
    if (value === 'cost_unavailable') return '成本待補';
    if (value === 'current_cost') return '目前成本';
    return value || '成本待補';
  }
  function rowCostText(row) {
    if (!row || row.costSource === 'cost_unavailable') return '目前成本待補';
    return '目前成本 ' + costMoney(row.currentCostTwd) + '／來源 ' + costSourceLabel(row.costSource);
  }
  function stockPurposeText(row) {
    var purpose = String(row && row.stockPurpose || '');
    if (purpose === 'sample' || row && row.sampleStock) return '庫存用途：現貨樣品';
    if (purpose === 'live_ready') return '庫存用途：直播現貨';
    if (purpose === 'reserved') return '庫存用途：已保留';
    if (purpose) return '庫存用途：' + purpose;
    return row && row.productMode === 'china_sample' ? '庫存用途：中國倉舊資料（未標示）' : '庫存用途：一般庫存';
  }
  function activeWarehouse() {
    if (currentTab === 'stocktake' || currentTab === 'stockin') return warehouse.value;
    if (currentTab === 'transfer') return sourceWarehouse.value;
    if (currentTab === 'arrival') return 'all';
    return lookupWarehouse.value;
  }
  function stockOf(row) {
    return Number(row && row.stock || 0);
  }
  function warehouseCodeOf(name) {
    var text = String(name || '').trim().toLowerCase();
    if (!text || text === 'all') return 'all';
    if (text === 'cn' || text === 'cn_dongguan' || /中國|中国|東莞|东莞|china/.test(text)) return 'CN';
    if (text === 'id' || text === 'id_direct' || /印尼|indonesia/.test(text)) return 'ID';
    if (text === 'tw' || text === 'tw_baohui' || /台灣|台湾|寶輝|宝辉|taiwan/.test(text)) return 'TW';
    return text;
  }
  function sameWarehouse(rowWarehouse, selectedWarehouse) {
    var wanted = warehouseCodeOf(selectedWarehouse);
    var got = warehouseCodeOf(rowWarehouse);
    if (wanted === 'all' || got === 'all') return true;
    return wanted === got;
  }
  function sameWarehouseRows(rows, wh) {
    return rows.filter(function (row) { return sameWarehouse(row.warehouse, wh); });
  }
  function cloneRowsForCurrentStocktake(rows, targetWarehouse) {
    var seen = {};
    var clones = [];
    (rows || []).forEach(function (row) {
      if (!row) return;
      var key = [String(row.productCode || ''), String(row.color || '').toUpperCase().replace(/\s+/g, ''), stockinCanonicalSize(row.size)].join('|');
      if (seen[key]) {
        if (!seen[key].image && row.image) seen[key].image = row.image;
        return;
      }
      var clone = Object.assign({}, row);
      clone.warehouse = targetWarehouse;
      clone.stock = 0;
      clone.shelf = '';
      clone.layer = '';
      clone.crossWarehouseReference = true;
      clone.referenceWarehouse = row.warehouse;
      clone.referenceSkuId = row.skuId;
      seen[key] = clone;
      clones.push(clone);
    });
    return clones;
  }
  function showCrossWarehouseChoice(code, rows, targetWarehouse) {
    var panel = document.querySelector('[data-cross-warehouse-choice]');
    if (!panel) return false;
    var targetRows = sameWarehouseRows(rows, targetWarehouse);
    var stockedRows = rows.filter(function (row) { return !sameWarehouse(row.warehouse, targetWarehouse) && stockOf(row) > 0; });
    var sourceRows = stockedRows.length ? stockedRows : rows;
    var sources = {};
    sourceRows.forEach(function (row) {
      var name = String(row.warehouse || '其他倉');
      if (!sources[name]) sources[name] = {warehouse:name, stock:0, rows:[]};
      sources[name].stock += Math.max(0, stockOf(row));
      sources[name].rows.push(row);
    });
    var sourceList = Object.keys(sources).map(function (key) { return sources[key]; }).sort(function (a,b) { return b.stock-a.stock; });
    var sourceImage = String(((sourceRows.find(function (row) { return row.image; }) || {}).image) || '');
    var referenceRows = targetRows.map(function (row) {
      if (!row.image && sourceImage) row.image = sourceImage;
      return row;
    });
    crossWarehouseContext = {code:code, rows:(referenceRows.length ? referenceRows : sourceRows).slice(), targetRows:referenceRows.slice(), sourceRows:sourceRows.slice(), targetWarehouse:targetWarehouse, sources:sourceList};
    panel._crossWarehouseContext = crossWarehouseContext;
    var codeOutput = panel.querySelector('[data-cross-warehouse-code]');
    var summary = panel.querySelector('[data-cross-warehouse-summary]');
    var stockBox = panel.querySelector('[data-cross-warehouse-stock]');
    var transferButton = panel.querySelector('[data-cross-warehouse-transfer]');
    if (codeOutput) codeOutput.textContent = code;
    if (summary) summary.textContent = '你正在盤點「' + targetWarehouse + '」，這裡還沒有這個規格的庫存列。其他倉已找到同編號／顏色。請把資料帶入「本次盤點明細」，或改做調撥；帶入不會立刻改庫存。';
    if (stockBox) stockBox.innerHTML = sourceList.map(function (source) { return '<article><b>' + escapeHtml(source.warehouse) + '</b><strong>' + source.stock + ' 件</strong><small>' + escapeHtml(source.rows.map(function (row) { return [row.productCode,row.color,row.size].filter(Boolean).join('／'); }).join('、')) + '</small></article>'; }).join('');
    if (transferButton) {
      transferButton.disabled = !sourceList.length || sourceList.every(function (source) { return source.stock <= 0; });
      transferButton.textContent = sourceList.length ? '從 ' + sourceList[0].warehouse + ' 調撥到 ' + targetWarehouse : '其他倉沒有可調撥庫存';
    }
    panel.hidden = false;
    matchPanel.hidden = true;
    setMessage('注意：目前倉沒有此規格，但其他倉找到同編號。請選擇帶入本次盤點，或改做調撥。帶入不會立刻改庫存。', 'error');
    return true;
  }
  function prioritizedRows(rows) {
    var wh = activeWarehouse();
    if (!wh || wh === 'all') return rows;
    return rows.slice().sort(function (a, b) {
      return (sameWarehouse(b.warehouse, wh) ? 1 : 0) - (sameWarehouse(a.warehouse, wh) ? 1 : 0) || stockOf(b) - stockOf(a);
    });
  }
  function renderMatches(rows) {
    var wh = activeWarehouse();
    var visible = currentTab === 'lookup' && wh !== 'all' ? sameWarehouseRows(rows, wh) : prioritizedRows(rows);
    if (!visible.length && rows.length) visible = prioritizedRows(rows);
    // Display-only duplicate suppression: retain every stocked warehouse and one zero-stock fallback per size.
    var displayGroups = {};
    visible.forEach(function(row) {
      var color=String(row.color||'').toUpperCase().replace(/\s+/g,''), bilingual=color.match(/[（(]([^）)]+)[）)]/);
      if(bilingual)color=bilingual[1];
      color=({'黑色':'BLACK','HITEM':'BLACK','HITAM':'BLACK','白色':'WHITE','PUTI':'WHITE','PUTIH':'WHITE','咖色':'BROWN','咖啡色':'BROWN','COKELAT':'BROWN','紅色':'RED','MERAL':'RED','MERAH':'RED','藍色':'BLUE','BIRU':'BLUE','綠色':'GREEN','HIGAU':'GREEN','HIJAU':'GREEN'}[color]||color);
      var key=(row.productId||row.productCode)+'|'+color+'|'+stockinCanonicalSize(row.size);
      if(!displayGroups[key])displayGroups[key]=[];displayGroups[key].push(row);
    });
    if(currentTab==='stocktake'||currentTab==='lookup')visible=Object.values(displayGroups).reduce(function(out,group){var stocked=group.filter(function(row){return stockOf(row)>0;});return out.concat(stocked.length?stocked:group.slice(0,1));},[]);
    allMatches = visible;
    var colorPicker = document.querySelector('[data-match-color-picker]');
    var colorSelect = document.querySelector('[data-match-color-select]');
    if (colorPicker) colorPicker.hidden = visible.length <= 1;
    if (colorSelect) colorSelect.innerHTML = visible.map(function (row,index) { return '<option value="' + index + '">' + escapeHtml((row.color || '未設定顏色') + '／' + (row.size || 'NO SIZE') + '｜' + (row.productCode || '')) + '</option>'; }).join('');
    matchPanel.hidden = !visible.length;
    matchGrid.innerHTML = visible.map(function (row, index) {
      var targetStock = sameWarehouse(row.warehouse, wh) ? stockOf(row) : 0;
      var last = row.lastCountedStock == null ? '無上次盤點' : '上次實盤 ' + Number(row.lastCountedStock) + ' 件';
      var barcodeCheck = row.exact ? (row.matchedBy === 'historical_variant_barcode' ? '條碼核對：歷史標籤已正確對應' : '條碼核對：正式條碼相符') : '條碼核對：相近結果，請看圖片確認';
      return '<button type="button" class="match-card' + (selected === row ? ' is-selected' : '') + '" data-match-index="' + index + '"><img src="' + escapeHtml(resolveImage(row.image)) + '" alt="商品對照圖"><span><b>' + escapeHtml(row.productCode) + '</b><em>' + escapeHtml(row.title) + '</em><strong>' + escapeHtml(row.color) + ' · ' + escapeHtml(row.size) + '</strong><small>分類：' + escapeHtml(row.category || '未設定分類') + '</small><small>' + escapeHtml(row.warehouse) + '／庫存 ' + stockOf(row) + ' 件</small><small>倉位：' + escapeHtml([row.shelf || '未設定貨架',row.layer || '未設定層位'].join('／')) + '</small><small>' + escapeHtml(stockPurposeText(row)) + '</small><small>' + escapeHtml(barcodeCheck) + '</small><small>' + escapeHtml(rowCostText(row)) + '</small><small>' + escapeHtml(last) + '</small><small class="target-stock">本次 ' + escapeHtml(wh === 'all' ? '全部倉庫' : wh) + ' 盤點前：' + targetStock + ' 件</small><code>' + escapeHtml(row.skuId) + '</code></span></button>';
    }).join('');
  }
  function choose(row, index, manualColorConfirmation) {
    var sameSelection = selected && String(selected.skuId) === String(row.skuId);
    var transferQtyBeforeChoose = Number((document.querySelector('[data-transfer-qty]') || {}).value || 1);
    selected = row;
    if (manualColorConfirmation === true) selected.colorConfirmed = true;
    else if (selected.colorConfirmationRequired) selected.colorConfirmed = false;
    var firstLocation = document.querySelector('[data-match-first-location]');
    var locationStatus = document.querySelector('[data-match-location-status]');
    var currentLocation = document.querySelector('[data-match-current-location]');
    var locationCompare = document.querySelector('[data-match-location-compare]');
    var editorTitle = document.querySelector('[data-match-location-editor-title]');
    var editorHelp = document.querySelector('[data-match-location-editor-help]');
    var matchShelf = document.querySelector('[data-match-shelf]');
    var matchLayer = document.querySelector('[data-match-layer]');
    var matchLocationWarehouse = document.querySelector('[data-match-location-warehouse]');
    var hasLocation = !!(String(row.shelf || '').trim() && String(row.layer || '').trim());
    if (locationStatus) locationStatus.hidden = !hasLocation;
    if (currentLocation) currentLocation.textContent = hasLocation ? [row.shelf, row.layer].join('／') : '尚未設定';
    if (locationCompare) locationCompare.textContent = hasLocation ? '已帶入原本擺放位置；若現場不同請按調整。' : '尚無位置資料，快速入庫前必須設定。';
    if (editorTitle) editorTitle.textContent = hasLocation ? '調整貨架位置' : '第一次倉位紀錄';
    if (editorHelp) editorHelp.textContent = hasLocation ? '請依現場實際位置重新選擇，儲存後同步後台。' : '未換倉位前會沿用這次設定。';
    if (firstLocation) firstLocation.hidden = currentTab !== 'stocktake' || hasLocation;
    fillLocationSelect(matchShelf, stockinShelves, row.shelf || '', '請選貨架');
    fillLocationSelect(matchLayer, stockinLayers, row.layer || '', '請選層位');
    syncNoSpaceLocations();
    if (matchLocationWarehouse && warehouse) { matchLocationWarehouse.innerHTML = warehouse.innerHTML; matchLocationWarehouse.value = warehouse.value; }
    operationPanel.hidden = currentTab === 'lookup' || currentTab === 'stocktake';
    document.querySelectorAll('[data-match-index]').forEach(function (card) {
      card.classList.toggle('is-selected', Number(card.dataset.matchIndex) === index);
    });
    var activeColorSelect = document.querySelector('[data-match-color-select]');
    if (activeColorSelect && index >= 0) activeColorSelect.value = String(index);
    var targetStock = sameWarehouse(row.warehouse, activeWarehouse()) ? stockOf(row) : 0;
    document.querySelector('[data-before-stock]').textContent = targetStock + ' 件';
    if (currentTab === 'stocktake') {
      currentStocktakeScanCode = String(currentStocktakeScanCode || row.scannedBarcode || row.barcode || row.skuId || '');
      if (!Object.prototype.hasOwnProperty.call(stocktakeLiveCounts, currentStocktakeScanCode)) stocktakeLiveCounts[currentStocktakeScanCode] = 1;
      document.querySelector('[data-qty]').value = String(stocktakeLiveCounts[currentStocktakeScanCode]);
    } else document.querySelector('[data-qty]').value = String(targetStock);
    document.querySelector('[data-transfer-qty]').value = String(sameSelection ? Math.max(1, transferQtyBeforeChoose) : 1);
    var arrivalQty = document.querySelector('[data-arrival-qty]');
    if (arrivalQty) arrivalQty.value = '1';
    document.querySelector('[data-stockin-qty]').value = '1';
    var stockinSummary = document.querySelector('[data-stockin-summary]');
    if (stockinSummary) stockinSummary.textContent = row.productCode + '／' + row.title + '／分類：' + (row.category || '未設定分類') + '／' + row.color + '／' + row.size;
    setMessage('已選 ' + row.productCode + '／' + row.color + '／' + row.size + '；' + rowCostText(row) + '。請看圖片確認後輸入數量。', 'ok');
    revealMissingStocktakePhoto(row);
    if (currentTab === 'stocktake') stocktakeFlowStatus('已選 ' + row.productCode + '／' + row.color + '／' + row.size + '；請確認數量與 ' + (hasLocation ? [row.shelf, row.layer].join('／') : '首次倉位／層架') + '。');
    if (currentTab === 'stocktake') {
      matchPanel.hidden = hasLocation;
      if (matchGrid) matchGrid.hidden = true;
      renderStocktakeFastRows();
    } else if (matchGrid) matchGrid.hidden = false;
  }
  function setExactStocktakeScanQuantity(row, scannedCode) {
    if (currentTab !== 'stocktake' || !row) return;
    var key = String(scannedCode || row.barcode || row.skuId || '').trim();
    stocktakeLiveCounts[key] = Math.max(0, Number(stocktakeLiveCounts[key] || 0)) + 1;
    var qtyInput = document.querySelector('[data-qty]');
    if (!qtyInput) return;
    qtyInput.value = String(stocktakeLiveCounts[key]);
    refreshStocktakeCompare();
    renderStocktakeFastRows();
  }
  function hidePending() {
    pendingPanel.hidden = true;
  }
  function showPending(code, purpose) {
    pendingPurpose = purpose || 'stockin';
    resetProductSelection();
    var pendingPhoto = document.querySelector('[data-pending-photo]');
    if (pendingPhoto) pendingPhoto.value = '';
    pendingPanel._pastedPhotoData = '';
    var pastedPreview = document.querySelector('[data-pending-photo-preview]');
    if (pastedPreview) { pastedPreview.src = ''; pastedPreview.hidden = true; }
    var pasteBox = document.querySelector('[data-pending-photo-paste]');
    if (pasteBox) pasteBox.classList.remove('has-photo');
    document.querySelector('[data-pending-code]').value = code;
    document.querySelector('[data-pending-title]').value = code;
    document.querySelector('[data-pending-warehouse]').value = currentTab === 'transfer' ? sourceWarehouse.value : warehouse.value;
    document.querySelector('[data-pending-qty]').value = currentTab === 'transfer' ? (document.querySelector('[data-transfer-qty]').value || 1) : 1;
    var inferredCategory = stockinCategoryFromCode(code);
    var inferredCost = stockinCostFromBarcode(code);
    document.querySelector('[data-pending-category]').value = String(document.querySelector('[data-stockin-default-category]').value || inferredCategory || localStorage.getItem(stockinRecentCategoryKey) || '');
    document.querySelector('[data-pending-color]').value = String(document.querySelector('[data-stockin-default-color]').value || '');
    document.querySelector('[data-pending-size]').value = 'NO SIZE';
    document.querySelector('[data-pending-cost]').value = String(document.querySelector('[data-stockin-default-cost]').value || inferredCost || '');
    var pendingHeading = pendingPanel.querySelector('h2');
    var pendingSave = pendingPanel.querySelector('[data-save-pending]');
    if (pendingHeading) pendingHeading.textContent = pendingPurpose === 'stocktake_issue' ? '公司倉庫無產品｜拍照列入待人工建檔' : '臨時建檔｜完整條碼與商品系列碼共存';
    if (pendingSave) pendingSave.textContent = pendingPurpose === 'stocktake_issue' ? '儲存到後台待人工建檔' : '未上傳照片時手動加入批次';
    document.querySelector('[data-pending-summary]').textContent = pendingPurpose === 'stocktake_issue'
      ? '此條碼在公司倉庫沒有產品資料。請選擇本次盤點倉庫、首次貨架／層位並拍照；只會存到後台待人工建檔，不會修改庫存。'
      : '臨時建檔採兩種模式共存：完整條碼「' + code + '」用於精確掃描與列印；商品系列碼「' + stockinProductCodeFromBarcode(code) + '」用於分類、搜尋及同系列管理。' + (inferredCategory ? '已依字首推測分類「' + inferredCategory + '」；' : '') + (inferredCost ? '已從 P…9 解析成本 NT$' + inferredCost + '；' : '') + '確認資料並上傳照片後加入本批。';
    pendingPanel.hidden = false;
  }
  function queueMissingStocktake(code) {
    return post({
      action: 'pending_stockin',
      rawCode: code,
      barcode: code,
      warehouse: activeWarehouse(),
      qty: 1,
      operator: operator,
      note: '本條碼不在盤點清單庫存比對內，已轉到快速入庫待管理者手動對應。'
    });
  }
  function classifyLookup(rows, code) {
    // Keep the lookup identity on its results: the scanner input is cleared before selection.
    rows.forEach(function (row) { row.scannedBarcode = code; });
    var previousChoice = document.querySelector('[data-cross-warehouse-choice]');
    if (previousChoice) {
      previousChoice.hidden = true;
      previousChoice._crossWarehouseContext = null;
    }
    crossWarehouseContext = null;
    var wh = activeWarehouse();
    if (!rows.length) {
      archiveScanResult(code, 'not_found', null, 0, '查無產品或庫存資料');
      if (currentTab === 'stockin') {
        beep(3);
        showPending(code);
        readyForNextScan();
        setMessage('三聲：找不到產品。已清空掃描欄；如要入庫，請使用下方待判別流程補照片。', 'error');
      } else if (currentTab === 'stocktake') {
        beep(3);
        showPending(code, 'stocktake_issue');
        stocktakeFlowStatus('無法從 P 前產品碼／P 後色碼辨識：請選類別、顏色、尺寸、數量、倉位與層架，再拍照或貼圖送出。');
        setMessage('三聲：辨識不到完整產品。請在下方選類別、顏色、尺寸、數量與倉架；缺圖可直接用 YGF 拍照或貼圖。', 'error');
      } else {
        stopForMissingStock('查無產品或庫存資料：' + code);
      }
      return;
    }
    var warehouseRows = wh === 'all' ? rows : sameWarehouseRows(rows, wh);
    var usingOtherWarehouseReference = false;
    var otherWarehouseStockRows = wh === 'all' ? [] : rows.filter(function (row) {
      return !sameWarehouse(row.warehouse, wh) && stockOf(row) > 0;
    });
    // Stocktake must keep a SKU that already belongs to the selected warehouse,
    // even when its book stock is zero. Zero stock is exactly what stocktake may
    // correct; only offer cross-warehouse transfer when no local SKU row exists.
    if (currentTab === 'stocktake' && !warehouseRows.length && otherWarehouseStockRows.length) {
      warehouseRows = otherWarehouseStockRows;
      usingOtherWarehouseReference = true;
    }
    if ((currentTab === 'stocktake' || currentTab === 'lookup') && !warehouseRows.length && rows.length) {
      warehouseRows = rows;
      usingOtherWarehouseReference = true;
    }
    if (currentTab !== 'stockin' && !warehouseRows.length) {
      archiveScanResult(code, 'wrong_warehouse', rows[0] || null, rows.length, '產品存在，但目前倉庫沒有這個規格');
      stopForMissingStock('產品存在，但「' + wh + '」沒有庫存資料：' + code);
      return;
    }
    if (currentTab === 'transfer' && warehouseRows.every(function (row) { return stockOf(row) <= 0; })) {
      archiveScanResult(code, 'wrong_warehouse', warehouseRows[0] || null, warehouseRows.length, '來源倉庫庫存為 0，不能調撥');
      stopForMissingStock('「' + wh + '」目前庫存為 0，不能調撥：' + code);
      return;
    }
    hidePending();
    selected = null;
    operationPanel.hidden = true;
    renderMatches(currentTab === 'stockin' ? rows : warehouseRows);
    if (usingOtherWarehouseReference && currentTab === 'stocktake') {
      showCrossWarehouseChoice(code, rows, wh);
      archiveScanResult(code, 'other_warehouse_found', warehouseRows[0] || null, warehouseRows.length, '目前倉無資料，其他倉找到同產品；等待選擇帶入或調撥');
      beep(2);
      return;
    }
    var exactRows = allMatches.filter(function (row) { return row.exact; });
    // Some valid product codes contain an S segment before P (for example
    // LEN180S2P350C91S6).  The old text-only parser treated those as a series
    // code even when the API had one exact SKU.  A unique exact API match is
    // authoritative and should open the product directly.
    var fullBarcodeScan = scannedCodeMode(code) === 'full' || exactRows.length === 1;
    if (!fullBarcodeScan) {
      if (currentTab === 'stocktake') renderStocktakeFastRows();
      if (matchGrid) matchGrid.hidden = false;
      archiveScanResult(code, 'series_found', null, allMatches.length, '商品系列碼已找到，等待選擇顏色與尺寸');
      beep(1);
      setMessage('一聲：已找到商品系列「' + stockinProductCodeFromBarcode(code) + '」。這是 P 前的商品編號，請先點選正確的顏色與尺寸；選定後數量會預設為 1。', 'ok');
      return;
    }
    if (exactRows.length !== 1) {
      if (matchGrid) matchGrid.hidden = false;
      archiveScanResult(code, exactRows.length ? 'ambiguous' : 'similar_only', null, allMatches.length, exactRows.length ? '完整條碼對到多筆 SKU' : '完整條碼未精準對到 SKU');
      beep(exactRows.length ? 2 : 3);
      setMessage(exactRows.length ? '兩聲：完整條碼對到多筆規格，資料有重複；請點選正確的顏色與尺寸。' : '三聲：沒有找到與完整條碼完全相符的 SKU；已列出相近商品，請人工核對後點選。', 'error');
      return;
    }
    var autoRow = exactRows[0];
    if (currentTab === 'stocktake') {
      currentStocktakeScanCode = code;
      autoRow.scannedBarcode = code;
    }
    choose(autoRow, allMatches.indexOf(autoRow));
    if (currentTab === 'stocktake' && autoRow.colorConfirmationRequired && !autoRow.colorConfirmed) {
      beep(2);
      setMessage('兩聲：這個舊條碼的雙語顏色已清除。請由行政點選正確實品顏色／尺寸後再帶入庫存。', 'error');
      openStocktakeVariantEditor();
      return;
    }
    if (currentTab === 'stocktake') {
      var alreadyCounted = Math.max(0, Number(stocktakeLiveCounts[code] || 0));
      if (alreadyCounted < 1 && session && Array.isArray(session.lines)) {
        session.lines.forEach(function (line) {
          var sameSku = String(line.sourceSkuId || line.skuId || '') === String(autoRow.skuId || '');
          var sameCode = String(line.barcode || '').toUpperCase() === String(code || '').toUpperCase();
          if (!sameSku && !sameCode) return;
          alreadyCounted = Math.max(alreadyCounted, Number(line.countedQty || line.qty || 0));
        });
      }
      stocktakeLiveCounts[code] = alreadyCounted > 0 ? alreadyCounted : 1;
      var setupQty = document.querySelector('[data-qty]');
      if (setupQty) setupQty.value = String(stocktakeLiveCounts[code]);
      matchPanel.hidden = true;
      var canReuseLocation = canReuseStocktakeSharedLocation();
      if (canReuseLocation) {
        autoRow.shelf = stocktakeSharedLocation.shelf;
        autoRow.layer = stocktakeSharedLocation.layer;
        stocktakeFlowLock = {barcode:code,skuId:String(autoRow.skuId || ''),shelf:stocktakeSharedLocation.shelf,layer:stocktakeSharedLocation.layer};
      }
      renderStocktakeFastRows();
      archiveScanResult(code, 'matched', autoRow, allMatches.length, canReuseLocation ? '第一刷已計 1 件並沿用目前工作倉架' : '第一刷已計 1 件，等待確認商品與層架');
      beep(1);
      stocktakeFlowStatus(canReuseLocation
        ? '不同產品已直接沿用 ' + stocktakeSharedLocation.shelf + '／' + stocktakeSharedLocation.layer + '，第一刷計 1 件；可繼續掃下一個產品。若同產品另放別處，按「同產品另放一處（新增）」。'
        : '第一刷已計 1 件。請核對縮圖、編號、顏色與尺寸；正確請按「確認產品」，再選擇層架。第二刷才會變 2。');
      setMessage(canReuseLocation
        ? '已辨識商品並沿用目前倉架 ' + stocktakeSharedLocation.shelf + '／' + stocktakeSharedLocation.layer + '；可直接繼續掃。'
        : '商品已辨識並計入第 1 件；請確認商品與層架，不必重新刷這一件。', 'ok');
      readyForNextScan();
      return;
    }
    setExactStocktakeScanQuantity(autoRow, code);
    archiveScanResult(code, 'matched', autoRow, allMatches.length, '完整條碼已對到 ' + (autoRow.productCode || '') + '／' + (autoRow.color || '') + '／' + (autoRow.size || ''));
    if (currentTab === 'transfer') {
      var sourceRows = sameWarehouseRows(warehouseRows, sourceWarehouse.value);
      var exactRow = sourceRows.find(function (row) { return row === autoRow; });
      if (isIncompleteOlanBarcode(code)) {
        beep(3);
        setMessage('三聲：目前只有產品碼，缺少 P成本＋色碼；已列出相近商品供核對，但不會自動加入調撥單。請掃完整條碼或選擇正確顏色。', 'error');
        return;
      }
      if (exactRow) {
        queueTransfer(exactRow, code);
      } else {
        setMessage('找到多個相近產品，請點選正確圖片；點選後會直接加入調撥單。', 'error');
      }
      return;
    }
    if (currentTab === 'arrival') {
      receiveCrossWarehouse(autoRow, code);
      return;
    }
    if (currentTab === 'lookup') {
      var exactLookupRows = allMatches.filter(function (row) { return row.exact; });
      beep(1);
      if (usingOtherWarehouseReference) {
        setMessage('一聲：已找到產品；目前貨在其他倉庫。畫面保留商品、圖片與各倉數量供核對。', 'ok');
      } else if (exactLookupRows.length === 1) {
        var exactLookup = exactLookupRows[0];
        setMessage('一聲：條碼核對成功，已對應 ' + exactLookup.productCode + '／' + exactLookup.color + '／' + exactLookup.size + '；請再看圖片確認。', 'ok');
      } else if (exactLookupRows.length > 1) {
        setMessage('一聲：產品條碼核對成功，但此產品有多個顏色或尺寸，請看圖片選正確規格。', 'ok');
      } else {
        setMessage('找到相近產品，尚未核對到唯一條碼；請看圖片、顏色與尺寸確認。', 'error');
      }
      return;
    }
    if (currentTab === 'stockin') {
      queueStockinRow(autoRow, code);
      return;
    }
    if (currentTab === 'stocktake' && stocktakeFlowLock.barcode === code && stocktakeFlowLock.skuId === String(autoRow.skuId || '')) {
      autoRow.shelf = stocktakeFlowLock.shelf || autoRow.shelf;
      autoRow.layer = stocktakeFlowLock.layer || autoRow.layer;
      stocktakeFlowStatus('同條碼連續掃描：目前 ' + Number(stocktakeLiveCounts[code] || 1) + ' 件；沿用 ' + [autoRow.shelf, autoRow.layer].filter(Boolean).join('／') + '。');
      renderStocktakeFastRows();
      readyForNextScan();
      return;
    }
    beep(1);
    setMessage(exactRows.length === 1
      ? '一聲：完整條碼已自動選定商品，本次實盤數量已帶入 ' + Number((document.querySelector('[data-qty]') || {}).value || 0) + ' 件；可拍照後加入盤點單。'
      : usingOtherWarehouseReference
        ? '一聲：已找到產品；「' + wh + '」目前沒有這個規格，請選正確圖片後輸入實盤數量。'
        : '一聲：此產品有多個顏色或尺寸，請先點選正確圖片，再輸入實盤數量。', exactRows.length === 1 ? 'ok' : 'error');
  }
  function lookup(code, options) {
    options = options || {};
    code = normalizeScanCode(code);
    if (!code) return;
    if (currentTab === 'stocktake' && stocktakeAutoSubmitting) {
      updateScanEcho(code, '上一件正在安全儲存，本次未重複計數', 'error');
      setMessage('上一件盤點資料正在儲存，請等畫面顯示完成後再掃下一件；本次沒有增加數量。', 'error');
      focusActiveScanInput();
      return;
    }
    if (currentTab === 'stocktake' && selected && currentStocktakeScanCode && code !== currentStocktakeScanCode) {
      var currentSwitchQty = Number((document.querySelector('[data-qty]') || {}).value || 0);
      if (stocktakeFlowLock.shelf && stocktakeFlowLock.layer && currentSwitchQty > 0) {
        submit('stocktake', {switchToCode:code});
        return;
      }
      beep(2);
      updateScanEcho(code, '請先完成上一件商品的位置', 'error');
      setMessage('請先替上一個條碼選好倉庫、貨架與層位；完成後再掃下一個條碼。','error');
      return;
    }
    if (currentTab === 'stocktake' && selected && currentStocktakeScanCode === code && stocktakeFlowLock.barcode === code && stocktakeFlowLock.skuId === String(selected.skuId || '') && stocktakeFlowLock.shelf && stocktakeFlowLock.layer) {
      selected.scannedBarcode = code;
      setExactStocktakeScanQuantity(selected, code);
      stocktakeFlowStatus('同條碼快速累加：目前 ' + Number(stocktakeLiveCounts[code] || 1) + ' 件；沿用 ' + stocktakeFlowLock.shelf + '／' + stocktakeFlowLock.layer + '。');
      updateScanEcho(code, '同條碼已直接 +1', 'ok');
      readyForNextScan();
      return;
    }
    if (lookupInFlight[code]) {
      // YGF may emit input/change/Enter and native scan callbacks for one
      // physical scan.  Only another accepted physical scan may queue one
      // extra count; passive input events must not replay the same lookup.
      if (options.physicalScan) queuedLookupCounts[code] = Math.max(0, Number(queuedLookupCounts[code] || 0)) + 1;
      return;
    }
    lookupInFlight[code] = true;
    lastLookupCode = code;
    lastLookupAt = Date.now();
    autoStagePendingBeforeNextScan(code).then(function () {
      setMessage('正在比對條碼與商品圖片…');
      var lookupUrl = './scanner-api.php?q=' + encodeURIComponent(code) + '&warehouse=' + encodeURIComponent(activeWarehouse()) + '&_=' + Date.now();
      return fetchWithTimeout(lookupUrl, {cache: 'no-store'}, 20000).catch(function () {
        return fetchWithTimeout(lookupUrl + '&retry=' + Date.now(), {cache: 'no-store'}, 20000);
      });
    })
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        var rows = payload && Array.isArray(payload.rows) ? payload.rows : [];
        if (payload && payload.ambiguousBarcode) {
          queuedLookupCounts[code] = 0;
          resetProductSelection();
          renderMatches(rows);
          document.querySelectorAll('[data-match-index]').forEach(function (card) { card.disabled = true; });
          if (matchPanel) matchPanel.hidden = false;
          archiveScanResult(code, 'ambiguous_blocked', null, rows.length, '掃描碼同時屬於不同產品或規格，已禁止選用');
          beep(3);
          setMessage('三聲：這個舊掃描碼同時屬於不同產品，系統已完全停止，不會選商品、入庫或扣庫存。請改掃 QR 完整條碼，或請管理者先修正條碼。', 'error');
          readyForNextScan();
          return;
        }
        classifyLookup(rows, code);
        readyForNextScan();
      })
      .catch(function (error) {
        if (window.console && typeof window.console.error === 'function') console.error('Scanner lookup workflow failed', error);
        if (error && error.stockinPendingBlocked) {
          lastLookupCode = '';
          lastLookupAt = 0;
          setBarcodeValue('');
          beep(3);
          setMessage(error.message, 'error');
          return;
        }
        resetProductSelection();
        readyForNextScan();
        beep(3);
        archiveScanResult(code, 'error', null, 0, '後台查詢失敗');
        setMessage('網路查詢暫時失敗，本筆未計數；請確認網路後再掃一次。', 'error');
      })
      .then(function () { finishQueuedLookup(code); }, function () { finishQueuedLookup(code); });
  }
  function finishQueuedLookup(code) {
    delete lookupInFlight[code];
    var pending = Math.max(0, Number(queuedLookupCounts[code] || 0));
    queuedLookupCounts[code] = 0;
    if (!pending) return;
    if (currentTab === 'stocktake') {
      var row = selected && currentStocktakeScanCode === code ? selected : null;
      var extra = 0;
      for (extra = 0; extra < pending; extra += 1) {
        if (row) setExactStocktakeScanQuantity(row, code);
        else stocktakeLiveCounts[code] = Math.max(0, Number(stocktakeLiveCounts[code] || 0)) + 1;
      }
      if (!row) {
        var queuedQty = document.querySelector('[data-qty]');
        if (queuedQty) queuedQty.value = String(Math.max(1, Number(stocktakeLiveCounts[code] || 1)));
      }
      stocktakeFlowStatus('同條碼連刷已累加：目前 ' + Number(stocktakeLiveCounts[code] || 1) + ' 件。');
      renderStocktakeFastRows();
      readyForNextScan();
      return;
    }
    setTimeout(function () { lookup(code, {physicalScan:true}); }, 0);
  }

  function receiveCrossWarehouse(row, code) {
    if (!row || !row.skuId) {
      beep(3);
      setMessage('請先選擇正確的到貨商品圖片、顏色與尺寸。', 'error');
      return;
    }
    var qty = Math.max(1, Number((document.querySelector('[data-arrival-qty]') || {}).value || 1));
    var button = document.querySelector('[data-confirm-arrival]');
    if (button) {
      button.disabled = true;
      button.textContent = '正在找客戶並建立到貨調撥…';
    }
    post({
      action: 'cross_warehouse_receive',
      skuId: row.skuId,
      barcode: String(code || row.barcode || ''),
      qty: qty,
      destinationWarehouse: arrivalWarehouse.value,
      operator: operator
    }).then(function (payload) {
      var allocations = Array.isArray(payload.allocations) ? payload.allocations : [];
      var result = document.querySelector('[data-arrival-result]');
      if (result) {
        result.innerHTML = '<h3>已找到客戶並進入出貨準備</h3>' + allocations.map(function (item) {
          return '<p><b>' + escapeHtml(item.orderId || '') + '｜' + escapeHtml(item.customer || '') + '</b><span>' + escapeHtml(item.phone || '') + '｜' + Number(item.qty || 0) + ' 件｜' + escapeHtml(item.color || '') + '／' + escapeHtml(item.size || '') + '</span></p>';
        }).join('');
      }
      draft.unshift({
        at: new Date().toISOString(),
        action: 'cross_warehouse_arrival_allocation',
        transferId: payload.receiveId || '',
        productCode: row.productCode || '',
        color: row.color || '',
        size: row.size || '',
        qty: qty,
        from: '業務打單來源倉（已扣）',
        to: arrivalWarehouse.value,
        image: row.image || '',
        operator: operator.name
      });
      draft = draft.slice(0, 200);
      saveDraft();
      beep(1);
      setMessage('一聲：到貨已配給 ' + allocations.length + ' 張客戶訂單，已建立調撥到貨紀錄並進入出貨準備。', 'ok');
      resetProductSelection();
      readyForNextScan();
    }).catch(function (error) {
      beep(3);
      setMessage('三聲：到貨配客失敗，未變更訂單。' + (error.message || ''), 'error');
    }).then(function () {
      if (button) {
        button.disabled = false;
        button.textContent = '確認到貨並配客';
      }
    });
  }
  function scheduleInputLookup() {
    clearTimeout(inputLookupTimer);
    var code = activeScanCode();
    if (code.length < 2) return;
    inputLookupTimer = setTimeout(function () { lookup(activeScanCode()); }, scanInputMode === 'manual' ? 650 : hardwareScanDelayMs);
  }
  function isHardwareTerminator(event) {
    return event.key === 'Enter' || event.key === 'Tab' ||
      event.code === 'Enter' || event.code === 'NumpadEnter' || event.code === 'Tab' ||
      event.keyCode === 13 || event.keyCode === 9 || event.which === 13 || event.which === 9;
  }
  function isScannerExitKey(event) {
    var key = String(event && event.key || '');
    var code = String(event && event.code || '');
    var number = Number(event && (event.which || event.keyCode) || 0);
    return key === 'Escape' || key === 'BrowserBack' || key === 'GoBack' ||
      code === 'Escape' || code === 'BrowserBack' || code === 'GoBack' ||
      number === 4 || number === 27 || number === 166;
  }
  function hardwareEventCharacter(event) {
    var key = String(event && event.key || '');
    if (/^(?:Unidentified|Unident|Iundient|Iunident|Process|Dead|Compose|Unknown|None)$/i.test(key)) key = '';
    // Some YGF Android firmware emits the whole barcode as one key event.
    if (key.length > 1 && /^[A-Za-z0-9]+$/.test(key) && /[0-9]/.test(key)) return key;
    if (key.length === 1) return key;
    var physicalCode = String(event && event.code || '');
    if (/^Key[A-Z]$/i.test(physicalCode)) return physicalCode.slice(3).toUpperCase();
    if (/^Digit[0-9]$/i.test(physicalCode)) return physicalCode.slice(5);
    if (/^Numpad[0-9]$/i.test(physicalCode)) return physicalCode.slice(6);
    var code = Number(event && (event.which || event.keyCode) || 0);
    if ((code >= 48 && code <= 90)) return String.fromCharCode(code);
    if (code >= 96 && code <= 105) return String(code - 96);
    return '';
  }
  function captureHardwareCommittedText(event) {
    if (scanInputMode !== 'hardware' && scanInputMode !== 'ccd') return;
    var incoming = String(event && event.data || '');
    var clean = normalizeScanCode(incoming);
    if (!clean) return;
    // Once editable, allow the browser to commit every chunk normally; the
    // input listener will read the accumulated full value. Only retain text
    // ourselves during the short readonly focus window used to hide the IME.
    if (input && !input.readOnly) return;
    hardwareScanBuffer += clean;
    updateScanEcho('', '正在接收 YGF 條碼');
    if (event && event.cancelable) event.preventDefault();
    clearTimeout(hardwareScanTimer);
    hardwareScanTimer = setTimeout(flushHardwareScan, hardwareScanDelayMs);
  }
  function externalScannerCode(payload) {
    if (payload && typeof payload === 'object') {
      payload = payload.barcode || payload.code || payload.data || payload.scanCode || payload.scanResult || payload.value || '';
    }
    return normalizeScanCode(String(payload || ''));
  }
  function acceptExternalScannerResult(payload) {
    var clean = externalScannerCode(payload);
    if (clean.length < 2) return false;
    var externalAcceptedAt = Date.now();
    if (recentExternalScanCodes[clean] && externalAcceptedAt - recentExternalScanCodes[clean] < 600) {
      updateScanEcho(clean, '已忽略同一次掃描的重複訊號', 'ok');
      return true;
    }
    recentExternalScanCodes[clean] = externalAcceptedAt;
    Object.keys(recentExternalScanCodes).forEach(function (savedCode) {
      if (externalAcceptedAt - recentExternalScanCodes[savedCode] > 5000) delete recentExternalScanCodes[savedCode];
    });
    setInputMode('hardware');
    hardwareScanBuffer = clean;
    setBarcodeValue(clean);
    updateScanEcho(clean, '已接收 YGF／PDA 掃描結果，正在查詢');
    setMessage('已收到條碼 ' + clean + '，正在查詢…');
    clearTimeout(hardwareScanTimer);
    hardwareScanTimer = setTimeout(flushHardwareScan, 20);
    return true;
  }
  // Compatibility callbacks used by common Android PDA/YGF WebView wrappers.
  window.onBarcode = acceptExternalScannerResult;
  window.onBarcodeScanned = acceptExternalScannerResult;
  window.onScan = acceptExternalScannerResult;
  window.onScanResult = acceptExternalScannerResult;
  window.scannerCallback = acceptExternalScannerResult;
  ['scan', 'barcode', 'barcodescan', 'scanresult', 'scannerdata'].forEach(function (eventName) {
    document.addEventListener(eventName, function (event) {
      acceptExternalScannerResult(event && (event.detail || event.data));
    }, true);
  });
  window.addEventListener('message', function (event) {
    var data = event && event.data;
    if (!data) return;
    if (typeof data === 'object' && data.type && !/scan|barcode/i.test(String(data.type))) return;
    acceptExternalScannerResult(data);
  });
  function isIncompleteOlanBarcode(code) {
    /* LZ_SCAN_LOOKUP_20260924: only reject bare series / P+cost without color.
       New stickers OLAN939024P338 and old OLAN71-93-NO-SIZE / OLAN66P34098 must look up. */
    code = normalizeScanCode(code);
    if (!/^OLAN/i.test(code)) return false;
    if (/^OLAN\d+$/i.test(code)) return true;
    if (/^OLAN\d{1,4}P\d{1,3}$/i.test(code)) return true;
    return false;
  }
  function rejectIncompleteScannedBarcode(code) {
    if (!isIncompleteOlanBarcode(code)) return false;
    beep(3);
    updateScanEcho(code, '條碼不完整：缺少 P成本＋色碼，未加入任何單據', 'error');
    setMessage('三聲：OLAN 條碼必須包含「P＋3位成本＋色碼」，例如 OLAN72P35096。本次未加入盤點或調撥單。', 'error');
    hardwareScanBuffer = '';
    if (input) input.value = '';
    return true;
  }
  function flushHardwareScan() {
    clearTimeout(inputLookupTimer);
    clearTimeout(hardwareScanTimer);
    clearTimeout(hardwareTerminatorTimer);
    hardwareTerminatorTimer = null;
    hardwareTerminatorDeadline = 0;
    var code = normalizeScanCode(hardwareScanBuffer || input.value || '');
    hardwareScanBuffer = '';
    if (code.length < 2) {
      if (lastAcceptedHardwareCode && Date.now() - lastAcceptedHardwareAt < 2500) {
        updateScanEcho(lastAcceptedHardwareCode, '掃描完成', 'ok');
        focusActiveScanInput();
        return;
      }
      updateScanEcho('', '有收到掃描結束鍵，但沒有收到條碼內容', 'error');
      setMessage('掃描槍有送出結束鍵，但網站沒有收到條碼文字；請確認掃描槍輸出模式是鍵盤輸入 HID。', 'error');
      return;
    }
    var acceptedAt = Date.now();
    if (code === lastAcceptedHardwareCode && acceptedAt - lastAcceptedHardwareAt < 800) {
      if (input) input.value = '';
      focusActiveScanInput();
      return;
    }
    lastAcceptedHardwareCode = code;
    lastAcceptedHardwareAt = acceptedAt;
    if (rejectIncompleteScannedBarcode(code)) return;
    setBarcodeValue(code);
    setMessage('已收到條碼 ' + code + '，正在查詢…');
    lookup(code, {physicalScan:true});
  }
  function finishHardwareScanAfterInput() {
    clearTimeout(hardwareTerminatorTimer);
    if (!normalizeScanCode(hardwareScanBuffer || String(input && input.value || '')) && lastAcceptedHardwareCode && Date.now() - lastAcceptedHardwareAt < 2500) {
      updateScanEcho(lastAcceptedHardwareCode, '掃描完成', 'ok');
      focusActiveScanInput();
      return;
    }
    // Some Android/YGF WebViews dispatch Enter before committing HID text to the
    // focused field. Poll until the field really contains data instead of assuming
    // that one animation frame is enough.
    hardwareTerminatorDeadline = Date.now() + 5000;
    function waitForCommittedBarcode() {
      // YGF/Android may commit the HID text after Enter and may briefly move
      // focus away from the field. Keep the hardware input focused while we
      // wait so the delayed full barcode is not discarded by the WebView.
      if (input && document.activeElement !== input) {
        try { input.focus({preventScroll: true}); } catch (error) { input.focus(); }
      }
      var committed = normalizeScanCode(hardwareScanBuffer || String(input && input.value || ''));
      if (committed.length >= 2) {
        flushHardwareScan();
        return;
      }
      if (Date.now() < hardwareTerminatorDeadline) {
        hardwareTerminatorTimer = setTimeout(waitForCommittedBarcode, 60);
        return;
      }
      hardwareTerminatorTimer = null;
      hardwareTerminatorDeadline = 0;
      updateScanEcho('', '有收到掃描結束鍵，但沒有收到條碼內容', 'error');
      setMessage('掃描槍有送出結束鍵，但網站在 5 秒內沒有收到條碼文字；請確認掃描槍輸出模式是鍵盤輸入 HID。', 'error');
    }
    hardwareTerminatorTimer = setTimeout(waitForCommittedBarcode, 30);
  }
  function captureHardwareScan(event) {
    if (scanInputMode !== 'hardware' && scanInputMode !== 'ccd') return;
    if (scannerMediaPickerOpen || scannerUiPickerOpen) return;
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (isScannerExitKey(event)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      updateScanEcho(lastAcceptedHardwareCode, '已攔截掃描槍返回鍵，盤點頁保持開啟', 'error');
      setMessage('掃描槍送出了返回／離開按鍵，系統已攔截；盤點頁與目前數量都保留。', 'error');
      focusActiveScanInput();
      return;
    }
    var target = event.target;
    var tag = String(target && target.tagName || '').toLowerCase();
    if (tag === 'textarea' || tag === 'select' || (tag === 'input' && target !== input)) return;
    if (isHardwareTerminator(event)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      finishHardwareScanAfterInput();
      return;
    }
    // 可編輯時由 input 事件讀取；唯讀掃描模式則由 document keydown
    // 接收 HID 掃描槍字元，避免 Android 軟鍵盤被喚起。
    if (target === input && !input.readOnly && event.key && event.key.length === 1) {
      // The editable field's native input event owns this character. Mark the
      // keydown so the compatibility keyup path cannot append it a second time.
      hardwareLastKeyDownAt = Date.now();
      return;
    }
    hardwareLastKeyDownAt = Date.now();
    var eventCharacter = hardwareEventCharacter(event);
    if (!eventCharacter) return;
    var now = Date.now();
    if (now - hardwareLastKeyAt > hardwareScanDelayMs) hardwareScanBuffer = '';
    hardwareLastKeyAt = now;
    var cleanKey = normalizeScanCode(eventCharacter);
    if (!cleanKey) return;
    hardwareScanBuffer += cleanKey;
    updateScanEcho('', '正在接收掃描槍條碼');
    event.preventDefault();
    clearTimeout(hardwareScanTimer);
    hardwareScanTimer = setTimeout(flushHardwareScan, hardwareScanDelayMs);
  }
  function captureHardwareKeyup(event) {
    if (scanInputMode !== 'hardware' && scanInputMode !== 'ccd') return;
    if (scannerMediaPickerOpen || scannerUiPickerOpen) return;
    if (isScannerExitKey(event)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (Date.now() - hardwareLastKeyDownAt < 70) return;
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (isHardwareTerminator(event)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      finishHardwareScanAfterInput();
      return;
    }
    // When the editable scan field is focused, the browser already committed
    // the character and fired input. Never duplicate it from keyup.
    if (input && document.activeElement === input && !input.readOnly) return;
    var eventCharacter = hardwareEventCharacter(event);
    if (!eventCharacter) return;
    var now = Date.now();
    if (now - hardwareLastKeyAt > hardwareScanDelayMs) hardwareScanBuffer = '';
    hardwareLastKeyAt = now;
    var cleanKey = normalizeScanCode(eventCharacter);
    if (!cleanKey) return;
    hardwareScanBuffer += cleanKey;
    updateScanEcho('', '正在接收掃描槍條碼');
    clearTimeout(hardwareScanTimer);
    hardwareScanTimer = setTimeout(flushHardwareScan, hardwareScanDelayMs);
  }
  function fillWarehouseSelect(select, options, prefixAll) {
    select.innerHTML = (prefixAll ? '<option value="all">全部倉庫</option>' : '') + options;
  }
  function loadWarehouses() {
    document.querySelector('[data-online]').textContent = '讀取倉庫中…';
    fetchWithTimeout('./scanner-api.php?_=' + Date.now(), {cache: 'no-store'}, 10000)
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        initialStockMode = payload.initialStockMode && typeof payload.initialStockMode === 'object' ? payload.initialStockMode : { enabled: true, mode: 'initial_inventory_setup' };
        var requiredWarehouses = ['中國倉', '台灣倉', '印尼倉', '預購倉'];
        var list = Array.isArray(payload.warehouses) && payload.warehouses.length ? payload.warehouses : requiredWarehouses.slice();
        stockinShelves = Array.isArray(payload.shelves) ? payload.shelves.filter(Boolean) : [];
        stockinLayers = Array.isArray(payload.layers) ? payload.layers.filter(Boolean) : [];
        list = requiredWarehouses.filter(function (name) { return list.indexOf(name) !== -1; });
        requiredWarehouses.forEach(function (name) { if (list.indexOf(name) === -1) list.push(name); });
        warehouses = list.slice();
        var options = list.map(function (item) { return '<option value="' + escapeHtml(item) + '">' + escapeHtml(item) + '</option>'; }).join('');
        fillWarehouseSelect(warehouse, options, false);
        fillWarehouseSelect(sourceWarehouse, options, false);
        fillWarehouseSelect(targetWarehouse, options, false);
        fillWarehouseSelect(arrivalWarehouse, options, false);
        if (list.indexOf('台灣倉') !== -1) arrivalWarehouse.value = '台灣倉';
        if (targetWarehouse.options.length > 1 && targetWarehouse.value === sourceWarehouse.value) targetWarehouse.selectedIndex = 1;
        applyTransferMode();
        fillWarehouseSelect(lookupWarehouse, options, true);
        fillWarehouseSelect(document.querySelector('[data-pending-warehouse]'), options, false);
        refreshLocationSelects();
        setStockinCategories(Array.isArray(payload.categories) ? payload.categories : []);
        stockinColors = Array.isArray(payload.colors) ? payload.colors.map(function (name) { return String(name || '').trim(); }).filter(Boolean) : [];
        initStockinSelectControls();
        stockinCategoryPrefixes = payload.categoryPrefixes && typeof payload.categoryPrefixes === 'object' ? payload.categoryPrefixes : {};
        var recentCategory = String(localStorage.getItem(stockinRecentCategoryKey) || '');
        document.querySelector('[data-stockin-default-category]').innerHTML = stockinCategoryOptions(recentCategory);
        document.querySelector('[data-pending-category]').innerHTML = stockinCategoryOptions('');
        renderStockinDraft();
        applyPermissionUi();
        if (requestedTab && canUseTab(requestedTab)) {
          var requestedButton = document.querySelector('[data-tab="' + requestedTab + '"]');
          if (requestedButton && !requestedButton.hidden) {
            switchTab(requestedTab, requestedButton);
            setTabsCollapsed(true);
          }
        }
        requestedTab = '';
        if (session && list.indexOf(session.warehouse) !== -1) warehouse.value = session.warehouse;
        document.querySelector('[data-online]').textContent = '系統已連線';
        document.querySelector('[data-online]').className = 'is-online';
        ensureSession();
      })
      .catch(function (error) {
        document.querySelector('[data-online]').textContent = '系統離線';
        setMessage((error && error.message ? error.message : '無法讀取倉庫資料') + '；登入狀態仍保留，請重新載入後再試。', 'error');
      });
  }
  function post(body) {
    return fetchWithTimeout('./scanner-api.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}, 12000)
      .then(function (res) { return res.json().then(function (payload) { if (!payload.ok) throw new Error(payload.error || '操作失敗'); return payload; }); });
  }
  function startStocktake(force) {
    if (operator.role === 'unknown') {
      setMessage('請先登入員工或管理者帳號，再開始盤點。', 'error');
      return Promise.reject(new Error('尚未登入'));
    }
    if (force) {
      session = null;
      ensureSession();
    }
    ensureSession();
    session.warehouse = warehouse.value;
    localStorage.setItem(sessionKey, JSON.stringify(session));
    return post({action: 'stocktake_start', sessionId: session.id, warehouse: warehouse.value, operator: operator, startedAt: session.startedAt})
      .then(function (payload) {
        session.nasStarted = true;
        localStorage.setItem(sessionKey, JSON.stringify(session));
        renderSession();
        loadOwnSessions();
        setMessage('盤點單已建立，管理者現在可看到進度。', 'ok');
        return payload;
      })
      .catch(function (error) {
        setMessage(error.message || '建立盤點單失敗', 'error');
        throw error;
      });
  }
  function restoreStocktakeDraft(item, options) {
    options = options || {};
    if (!item || item.status !== 'draft' || !item.id) {
      setMessage('找不到可繼續編輯的盤點草稿。', 'error');
      return false;
    }
    var lines = Array.isArray(item.lines) ? item.lines : [];
    if (!lines.length) {
      setMessage('這張盤點草稿目前沒有商品。', 'error');
      return false;
    }
    var restoredAt = Date.now();
    var warehouseName = String(item.warehouse || warehouse.value || '台灣倉');
    var latestLocatedLine = lines.slice().reverse().find(function (line) { return line && line.shelf && line.layer; }) || {};
    var savedLocation = item.lastLocation || {};
    var activeLocation = {
      warehouse: warehouseName,
      shelf: String(savedLocation.shelf || latestLocatedLine.shelf || ''),
      layer: String(savedLocation.layer || latestLocatedLine.layer || '')
    };
    resetCurrentStocktakeSelection();
    scannerOpenedAt = restoredAt - 1000;
    session = {
      id: String(item.id),
      startedAt: item.startedAt || new Date().toISOString(),
      warehouse: warehouseName,
      operator: item.operator || operator,
      nasStarted: true,
      status: 'draft',
      stocktakeActiveLocation: activeLocation
    };
    stocktakeSharedLocation = activeLocation;
    if (warehouse) warehouse.value = warehouseName;
    draft = draft.filter(function (line) { return !line || line.action !== 'stocktake'; });
    stocktakeLiveCounts = {};
    lines.forEach(function (line, index) {
      line = line || {};
      var barcode = String(line.barcode || line.sourceSkuId || '');
      var countedQty = Math.max(0, Number(line.countedQty || 0));
      var previousStock = Number(line.previousStock || 0);
      draft.push({
        at: new Date(restoredAt + index).toISOString(),
        serverScannedAt: line.scannedAt || '',
        action: 'stocktake',
        skuId: String(line.sourceSkuId || line.skuId || ''),
        barcode: barcode,
        scannedBarcode: barcode,
        productCode: String(line.displayProductCode || line.productCode || line.productId || line.sourceSkuId || barcode),
        color: String(line.color || ''),
        size: String(line.size || 'NO SIZE'),
        qty: countedQty,
        shelf: String(line.shelf || ''),
        layer: String(line.layer || ''),
        previousStock: previousStock,
        variance: Number(line.variance == null ? countedQty - previousStock : line.variance),
        from: String(line.warehouse || warehouseName),
        to: String(line.warehouse || warehouseName),
        image: String(line.image || line.displayImage || ''),
        operator: ((item.operator || {}).name || (item.operator || {}).account || operator.name || ''),
        restoredFromServer: true
      });
      if (barcode) stocktakeLiveCounts[barcode] = countedQty;
    });
    try { localStorage.setItem(sessionKey, JSON.stringify(session)); } catch (error) {}
    renderSession();
    saveDraft();
    renderStocktakeFastRows();
    if (!options.silent) {
      var stocktakeTab = document.querySelector('[data-tab="stocktake"]');
      if (stocktakeTab && !stocktakeTab.hidden) switchTab('stocktake', stocktakeTab);
      setMessage('已恢復草稿 ' + item.id + '，共 ' + lines.length + ' 筆；可從原清單繼續掃描，尚未送審且不影響正式庫存。', 'ok');
      var board = document.querySelector('[data-stocktake-fast-board]');
      if (board) board.scrollIntoView({block:'nearest'});
    }
    return true;
  }
  function ownSessionLineHtml(sessionIndex, line, lineIndex) {
    line = line || {};
    var src = String(line.displayImage || line.image || '').trim();
    var hasImage = !!(src && /^(https?:\/\/|\.?\.?\/|uploads\/|assets\/|data:image\/)/i.test(src));
    var thumb = hasImage
      ? '<img class="own-session-thumb" loading="lazy" alt="" src="' + escapeHtml(src) + '">'
      : '<span class="own-session-thumb is-missing">無圖</span>';
    var code = String(line.displayProductCode || line.productCode || '').trim();
    var name = String(line.displayProductName || line.productName || '').trim();
    var barcode = String(line.barcode || line.sourceSkuId || '-').trim() || '-';
    var identity = code || barcode;
    return '<div class="own-session-line">' +
      '<label class="own-session-pick"><input type="checkbox" data-own-stocktake-select="' + sessionIndex + ':' + lineIndex + '" aria-label="選取 ' + escapeHtml(identity) + '"></label>' +
      thumb +
      '<div class="own-session-identity"><b>' + escapeHtml(identity) + '</b>' +
        (name && name !== identity ? '<small class="own-session-name">' + escapeHtml(name) + '</small>' : '') +
        '<code>' + escapeHtml(barcode) + '</code>' +
        '<em>' + escapeHtml((line.color || '-') + '／' + (line.size || 'NO SIZE')) + '</em>' +
        '<span>' + escapeHtml((line.shelf || '-') + '／' + (line.layer || '-')) + '</span></div>' +
      '<label class="own-session-qty">數量<input type="number" min="0" step="1" value="' + Number(line.countedQty || 0) + '" data-own-stocktake-qty="' + sessionIndex + ':' + lineIndex + '"></label>' +
      '<div class="own-session-actions"><button type="button" data-own-stocktake-save-qty="' + sessionIndex + ':' + lineIndex + '">改數量</button><button type="button" data-own-stocktake-remove="' + sessionIndex + ':' + lineIndex + '">刪除</button></div>' +
      '</div>';
  }
  function loadOwnSessions() {
    var account = operator.account || operator.name;
    fetch('./scanner-api.php?mode=sessions&operator=' + encodeURIComponent(account) + '&_=' + Date.now(), {cache: 'no-store'})
      .then(function (res) { return res.json(); })
      .then(function (payload) {
        var rows = (payload.sessions || []).filter(function (item) {
          // This block is the active work queue. Approved sessions remain in the
          // server history, but no longer occupy the draft/review list.
          if (item.status === 'approved') return false;
          var op = item.operator || {};
          return operator.role === 'admin' || (account && op.account === account) || op.name === operator.name;
        }).sort(function (a, b) {
          return (new Date(b.startedAt || b.submittedAt || 0).getTime() || 0) - (new Date(a.startedAt || a.submittedAt || 0).getTime() || 0);
        });
        var sessionHeading = document.querySelector('.own-sessions summary b');
        if (sessionHeading) sessionHeading.textContent = operator.role === 'admin' ? '全部人員盤點單' : '我的盤點單';
        ownSessionRows = rows;
        var activeServerDraft = session && rows.find(function (item) { return item && item.id === session.id && item.status === 'draft' && (item.lines || []).length; });
        var hasVisibleStocktakeDraft = draft.some(function (line) { return line && line.action === 'stocktake'; });
        if (activeServerDraft && !hasVisibleStocktakeDraft) {
          restoreStocktakeDraft(activeServerDraft, {silent:true});
          setMessage('已自動找回當掉前的盤點草稿 ' + activeServerDraft.id + '，共 ' + (activeServerDraft.lines || []).length + ' 筆；可直接繼續掃描。', 'ok');
        }
        var box = document.querySelector('[data-own-sessions]');
        if (!rows.length) {
          box.innerHTML = '<p class="empty">' + (operator.role === 'admin' ? '目前沒有待處理盤點單' : '目前沒有待處理的個人盤點單') + '</p>';
          return;
        }
        var visibleRows = rows.slice(0, 20);
        var bulkDeleteCount = operator.role === 'admin' ? visibleRows.filter(function (item) {
          return ['draft','pending_review','rejected','superseded'].indexOf(item.status || 'draft') !== -1;
        }).length : 0;
        var bulkDeleteToolbar = bulkDeleteCount ? '<div class="own-session-bulk-delete"><label><input type="checkbox" data-select-all-old-sessions><span>全選可刪除盤點單</span></label><strong data-selected-old-session-count>已選 0 張</strong><button type="button" data-delete-selected-old-sessions disabled>刪除已選</button></div>' : '';
        box.innerHTML = bulkDeleteToolbar + visibleRows.map(function (item, sessionIndex) {
          var diff = (item.lines || []).reduce(function (sum, line) { return sum + Number(line.variance || 0); }, 0);
          var status = {draft:'盤點中', pending_review:'待主管核准', approved:'已核准入帳', rejected:'已退回'}[item.status] || item.status;
          var recordOperator = item.operator || {};
          var thumbnail = function(line) {
            var src = String(line.displayImage || line.image || '').trim();
            if (!src || !/^(https?:\/\/|\.?\.?\/|uploads\/|assets\/|data:image\/)/i.test(src)) return '<span style="display:inline-block;padding:10px;color:#777">無圖片</span>';
            return '<img loading="lazy" alt="' + escapeHtml((line.color || '') + ' ' + (line.size || '') + ' 商品縮圖') + '" src="' + escapeHtml(src) + '" style="width:52px;height:52px;object-fit:contain;border:1px solid #ddd;border-radius:6px;background:white">';
          };
          var approval = operator.role === 'admin' && ['draft','pending_review'].indexOf(item.status) !== -1 && (item.lines || []).length > 0
            ? '<button type="button" style="align-self:flex-start;flex:none;min-height:36px;padding:6px 14px;border:1px solid #176b56;border-radius:8px;background:#176b56;color:white;font-weight:bold;cursor:pointer" data-own-session-approve="' + escapeHtml(item.id) + '">核准</button><details style="flex-basis:100%;width:100%"><summary style="cursor:pointer">查看盤點明細</summary><p>' + (item.excludedFromLossSurplus ? '2026/09/18 特別基準重建批次：即使跨日核准，也只帶入實盤數量，不建立報損或報溢。' : '核准會將本單商品庫存調整為實盤數量，不是再加一次。請先確認倉庫與每筆數量。') + '</p><div style="overflow:auto"><table><thead><tr><th>條碼／顏色／尺寸</th><th>目前庫存</th><th>實盤</th></tr></thead><tbody>' + (item.lines || []).map(function(line) {
              var before = Number(line.previousStock || 0), current = Number(line.currentStock == null ? before : line.currentStock), counted = Number(line.countedQty || 0);
              var variance = counted - before, nowVariance = counted - current;
              return '<tr><td>' + thumbnail(line) + '</td><td>' + escapeHtml(line.barcode || line.sourceSkuId || '') + '<br><b>' + escapeHtml(line.displayProductName || line.productName || '未找到產品名稱') + '</b></td><td>' + escapeHtml(line.color || '-') + '</td><td>' + escapeHtml(line.size || 'NO SIZE') + '</td><td>' + before + '</td><td><b>' + counted + '</b></td><td>' + (variance > 0 ? '+' : '') + variance + '</td><td>' + current + '</td><td>' + (nowVariance > 0 ? '+' : '') + nowVariance + '</td></tr>';
            }).join('') + '</tbody></table></div></details>' : '';
          approval = approval.replace('<table><thead><tr><th>條碼／顏色／尺寸</th><th>目前庫存</th><th>實盤</th></tr></thead>', '<table style="min-width:780px;width:100%;border-collapse:collapse" class="stocktake-comparison"><thead><tr><th>縮圖</th><th>條碼／產品名稱</th><th>顏色</th><th>尺碼</th><th>盤點時原庫存</th><th>盤點數量</th><th>盤點差異</th><th>目前庫存</th><th>目前差異</th></tr></thead>');
          var editable = item.status === 'draft' && (operator.role === 'admin' || (account && recordOperator.account === account) || recordOperator.name === operator.name);
          var canCorrectQty = (item.status === 'draft' || item.status === 'pending_review') && (operator.role === 'admin' || (account && recordOperator.account === account) || recordOperator.name === operator.name);
          var resumeButton = editable && (item.lines || []).length ? '<div style="flex-basis:100%;width:100%"><button type="button" class="primary" style="width:100%;min-height:40px" data-resume-stocktake-session="' + sessionIndex + '">繼續此草稿（' + (item.lines || []).length + ' 筆）</button></div>' : '';
          var oldDate = new Date(item.startedAt || '').getTime();
          var canDeleteSession = operator.role === 'admin' && ['draft','pending_review','rejected','superseded'].indexOf(item.status || 'draft') !== -1;
          var sessionSelect = canDeleteSession ? '<label class="own-session-delete-select"><input type="checkbox" data-select-old-session="' + sessionIndex + '" aria-label="選取盤點單 ' + escapeHtml(item.id) + '"><span>選取</span></label>' : '';
          var deleteOldButton = canDeleteSession ? '<div style="flex-basis:100%;width:100%;text-align:right"><button type="button" style="padding:5px 12px;border:1px solid #b42318;border-radius:7px;color:#b42318;background:white;cursor:pointer" data-delete-old-session="' + sessionIndex + '">刪除</button></div>' : '';
          deleteOldButton = approval + deleteOldButton;
          var preview = (!canCorrectQty && (item.lines || []).length) ? '<div class="own-session-preview">' + item.lines.slice(0,4).map(thumbnail).join('') + (item.lines.length > 4 ? '<small>另 ' + (item.lines.length-4) + ' 筆商品，請展開明細</small>' : '') + '</div>' : '';
          var details = canCorrectQty && (item.lines || []).length ? '<div class="own-session-lines"><div class="own-session-selection"><button type="button" data-own-stocktake-select-all="' + sessionIndex + '">全選</button><span data-own-stocktake-selected-count="' + sessionIndex + '">已選 0 筆</span><button type="button" class="remove-selected-own-session" data-own-stocktake-remove-selected="' + sessionIndex + '" disabled>刪除已選</button></div>' + item.lines.map(function (line, lineIndex) { return ownSessionLineHtml(sessionIndex, line, lineIndex); }).join('') + '<button type="button" class="clear-own-session" data-own-stocktake-clear="' + sessionIndex + '">清除這張盤點單全部商品</button></div>' : '';
          return '<article class="own-session-card">' + sessionSelect + '<header class="own-session-head"><div><b>' + escapeHtml(item.id) + '</b><span>盤點人：' + escapeHtml(typeof item.operator === 'string' ? item.operator : ((item.operator || {}).name || (item.operator || {}).account || '未記錄')) + '</span><span>' + escapeHtml(item.warehouse || '') + ' · ' + escapeHtml(status) + '</span><small>' + new Date(item.startedAt || item.submittedAt).toLocaleString('zh-TW') + '</small></div><strong>' + (item.lines || []).length + ' SKU<br>' + (item.excludedFromLossSurplus ? '基準帶入（不報損溢）' : ('差數 ' + (diff > 0 ? '+' : '') + diff)) + '</strong></header>' + resumeButton + details + preview + deleteOldButton + '</article>';
        }).join('');
        box.querySelectorAll('[data-own-session-approve], [data-delete-old-session]').forEach(function(button) {
          var card = button.closest('article');
          card.style.flexWrap = 'wrap';
          var cardCopy = card.querySelector(':scope > div');
          if (cardCopy) {
            cardCopy.style.flex = '1';
            cardCopy.style.minWidth = '120px';
          }
        });
      })
      .catch(function () {
        document.querySelector('[data-own-sessions]').innerHTML = '<p class="empty">盤點單讀取失敗</p>';
      });
  }
  function updateOwnStocktakeSelection(sessionIndex) {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-own-stocktake-select^="' + sessionIndex + ':"]'));
    var checked = boxes.filter(function (box) { return box.checked; });
    var count = document.querySelector('[data-own-stocktake-selected-count="' + sessionIndex + '"]');
    var removeButton = document.querySelector('[data-own-stocktake-remove-selected="' + sessionIndex + '"]');
    var selectAllButton = document.querySelector('[data-own-stocktake-select-all="' + sessionIndex + '"]');
    if (count) count.textContent = '已選 ' + checked.length + ' 筆';
    if (removeButton) {
      removeButton.disabled = checked.length === 0;
      removeButton.textContent = checked.length ? '刪除已選（' + checked.length + ' 筆）' : '刪除已選';
    }
    if (selectAllButton) selectAllButton.textContent = boxes.length && checked.length === boxes.length ? '取消全選' : '全選';
  }
  function updateOldSessionBulkSelection() {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-select-old-session]'));
    var checked = boxes.filter(function (box) { return box.checked; });
    var count = document.querySelector('[data-selected-old-session-count]');
    var removeButton = document.querySelector('[data-delete-selected-old-sessions]');
    var selectAll = document.querySelector('[data-select-all-old-sessions]');
    if (count) count.textContent = '已選 ' + checked.length + ' 張';
    if (removeButton) {
      removeButton.disabled = checked.length === 0;
      removeButton.textContent = checked.length ? '刪除已選（' + checked.length + ' 張）' : '刪除已選';
    }
    if (selectAll) {
      selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
      selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
  }
  function ownStocktakeEntryPayload(line, item) {
    return {skuId:line.sourceSkuId || '',barcode:line.barcode || '',warehouse:line.warehouse || item.warehouse || '',shelf:line.shelf || '',layer:line.layer || ''};
  }
  function draftLineMatchesOwnEntry(line, entry) {
    var skuMatches = entry.skuId && (line.skuId || line.sourceSkuId || '') === entry.skuId;
    var barcodeMatches = entry.barcode && String(line.barcode || '').toUpperCase() === String(entry.barcode).toUpperCase();
    return (skuMatches || barcodeMatches) && (!entry.warehouse || (line.warehouse || line.to || line.from || '') === entry.warehouse) && (!entry.shelf || (line.shelf || '') === entry.shelf) && (!entry.layer || (line.layer || '') === entry.layer);
  }
  function submit(action, options) {
    options = options || {};
    if (!selected) {
      setMessage('請先掃描並選擇商品。', 'error');
      return;
    }
    if (operator.role === 'unknown') {
      setMessage('請先從管理者後台登入員工帳號，再開啟盤點機。', 'error');
      return;
    }
    if (action === 'stocktake') ensureSession();
    var qty = action === 'stocktake' ? Number(document.querySelector('[data-qty]').value || 0) : action === 'stockin' ? Number(document.querySelector('[data-stockin-qty]').value || 0) : Number(document.querySelector('[data-transfer-qty]').value || 0);
    var sourceRows = sameWarehouseRows(allMatches, sourceWarehouse.value);
    if (action === 'transfer') {
      if (!sourceRows.length || sourceRows.every(function (row) { return stockOf(row) <= 0; })) {
        beep(3);
        setMessage('三聲：此倉沒有庫存，需補數量入庫。', 'error');
        return;
      }
      if (sourceRows[0] && qty > stockOf(sourceRows[0])) {
        beep(2);
        setMessage('兩聲：庫存不足，目前只有 ' + stockOf(sourceRows[0]) + ' 件。', 'error');
        return;
      }
    }
    var body = {
      action: action === 'stocktake' ? 'stocktake_entry' : action === 'stockin' ? 'quick_stockin' : 'transfer',
      skuId: selected.skuId,
      barcode: selected.barcode,
      qty: qty,
      warehouse: action === 'stocktake' || action === 'stockin' ? warehouse.value : sourceWarehouse.value,
      operator: operator,
      sessionId: session && session.id,
      startedAt: session && session.startedAt
    };
    if (action === 'stocktake') {
      body.imageData = stocktakePhotoData();
      body.imageRole = scannerPhotoRole('stocktake');
      var stocktakeLocation = selectedStocktakeLocation();
      if (!stocktakeLocation.shelf || !stocktakeLocation.layer) {
        stocktakeAutoSubmitting = false;
        beep(2);
        setMessage('兩聲：請先選擇這件商品的倉位與層架；同條碼後續連刷會自動沿用。', 'error');
        var stocktakeLocationEditor = document.querySelector('[data-match-first-location]');
        if (stocktakeLocationEditor) stocktakeLocationEditor.hidden = false;
        return;
      }
      body.shelf = stocktakeLocation.shelf;
      body.layer = stocktakeLocation.layer;
      if (stocktakeAutoSubmitting) {
        setMessage('這件盤點資料正在儲存，請勿重複送出。', 'error');
        return;
      }
      stocktakeAutoSubmitting = true;
    }
    if (action === 'transfer') body.targetWarehouse = targetWarehouse.value;
    if (action === 'stockin') {
      body.shelf = String(document.querySelector('[data-stockin-shelf]').value || '').trim();
      body.layer = String(document.querySelector('[data-stockin-layer]').value || '').trim();
      var category = selected.category || '未設定分類';
      if (!confirm('請核對後再入庫：\n\n產品：' + selected.productCode + '／' + selected.title + '\n分類：' + category + '\n規格：' + selected.color + '／' + selected.size + '\n倉庫：' + warehouse.value + '\n數量：+' + qty + '\n\n以上產品、分類與數量都正確嗎？')) return;
    }
    var ready = action === 'stocktake' && !session.nasStarted ? startStocktake(false) : Promise.resolve();
    ready.then(function () { return post(body); }).then(function (payload) {
      var op = payload.operation || {};
      var previous = Number(op.previousStock || 0);
      var variance = Number(op.variance || (qty - previous));
      var beepCount = action === 'stocktake' ? (variance === 0 ? 1 : 2) : 1;
      draft.unshift({at: op.createdAt || new Date().toISOString(), action: action, skuId: selected.skuId || '', barcode: selected.barcode || body.barcode || '', labelBarcode: selected.labelBarcode || '', productCode: selected.productCode, productSerial: selected.productSerial || '', dedicatedSerialNumber: selected.dedicatedSerialNumber || '', colorIndonesian: selected.colorIndonesian || '', colorConfirmationRequired: !!selected.colorConfirmationRequired, colorConfirmed: !!selected.colorConfirmed, color: selected.color, size: stockinCanonicalSize(selected.size), qty: qty, shelf: body.shelf || selected.shelf || '', layer: body.layer || selected.layer || '', previousStock: previous, variance: variance, from: body.warehouse, to: body.targetWarehouse || body.warehouse, image: op.image || body.imageData || selected.image, operator: operator.name, transferredUnitCostTwd: Number(op.transferredUnitCostTwd || 0), costSource: op.costSource || ''});
      draft = draft.slice(0, 200);
      saveDraft();
      beep(beepCount);
      setMessage(action === 'stocktake'
        ? ((options.auto ? '同條碼連刷完成；' : '本件盤點完成；') + (variance === 0 ? '同倉庫數量相同，已加入盤點單。' : '同倉庫數量不同，已加入盤點單等待主管比對。'))
        : action === 'stockin'
          ? '一聲：快速入庫已寫入正式庫存；' + Number(op.previousStock || 0) + ' → ' + Number(op.remainingStock || 0) + ' 件。'
          : '一聲：有庫存，調撥已寫入後台；本次成本 ' + costMoney(op.transferredUnitCostTwd) + '（' + costSourceLabel(op.costSource) + '）。', variance === 0 || action === 'transfer' || action === 'stockin' ? 'ok' : 'error');
      if (action === 'stocktake') {
        stocktakeFlowLock = { barcode: String(body.barcode || activeScanCode() || ''), skuId: String(selected.skuId || ''), shelf: String(body.shelf || ''), layer: String(body.layer || '') };
        stocktakeFlowStatus('✓ 本件完成：' + selected.productCode + '／' + selected.color + '／' + selected.size + '，累計 ' + qty + ' 件，位置 ' + body.shelf + '／' + body.layer + '。同條碼可繼續刷；按「調整貨架位置」即可換位。');
        clearStocktakePhoto();
        stocktakeLiveCounts[String(body.barcode || '')] = qty;
        if (payload.session) session = payload.session;
        stocktakeAutoSubmitting = false;
      }
      if (action === 'stocktake' && options.continueAtNewLocation) {
        var continuingRow = selected;
        var continuingCode = String(body.barcode || currentStocktakeScanCode || activeScanCode() || '');
        selected = continuingRow;
        currentStocktakeScanCode = continuingCode;
        stocktakeLiveCounts[continuingCode] = 0;
        stocktakeFlowLock = {barcode:continuingCode,skuId:String(continuingRow.skuId || ''),shelf:'',layer:''};
        stocktakeChangingLocation = true;
        var continueQty = document.querySelector('[data-qty]'); if (continueQty) continueQty.value = '0';
        var continueLocationQty = document.querySelector('[data-match-location-qty]'); if (continueLocationQty) continueLocationQty.value = '0';
        var continueEditor = document.querySelector('[data-match-first-location]'); if (continueEditor) continueEditor.hidden = false;
        if (matchPanel) matchPanel.hidden = false;
        stocktakeFlowStatus('第一組位置已保留；請選第二組貨架與層位，下一次掃同條碼會從 1 開始累加。');
        setMessage('第一組 ' + body.shelf + '／' + body.layer + ' 共 ' + qty + ' 件已記錄；現在選擇第二組位置。','ok');
        renderStocktakeFastRows();
        readyForNextScan();
        return;
      }
      if (action === 'stocktake' && options.switchToCode) {
        var nextProductCode = String(options.switchToCode || '');
        selected = null;
        currentStocktakeScanCode = '';
        stocktakeFlowLock = {barcode:'',skuId:'',shelf:'',layer:''};
        stocktakeChangingLocation = false;
        matchPanel.hidden = true;
        operationPanel.hidden = true;
        resetProductSelection();
        renderStocktakeFastRows();
        readyForNextScan();
        setMessage('上一個商品已保存；正在開啟下一個條碼 ' + nextProductCode + '。','ok');
        setTimeout(function () { lookup(nextProductCode); }, 30);
        return;
      }
      selected = null;
      matchPanel.hidden = true;
      operationPanel.hidden = true;
      resetProductSelection();
      renderStocktakeFastRows();
      readyForNextScan();
    }).catch(function (error) {
      stocktakeAutoSubmitting = false;
      var text = error.message || '操作失敗';
      if (/不足|超過|瓒呴亷/.test(text)) beep(2);
      else beep(3);
      setMessage(text, 'error');
    });
  }
  function readPendingPhoto() {
    var fileInput = document.querySelector('[data-pending-photo]');
    var file = fileInput && fileInput.files ? fileInput.files[0] : null;
    if (!file && pendingPanel && pendingPanel._pastedPhotoData) return Promise.resolve(pendingPanel._pastedPhotoData);
    if (!file) return Promise.resolve('');
    return scannerImageFileToDataUrl(file).then(function (dataUrl) {
      fileInput.value = '';
      return dataUrl;
    }, function (error) {
      fileInput.value = '';
      throw error;
    });
  }
  function pendingStockinLine(imageData) {
    var rawCode = String(document.querySelector('[data-pending-code]').value || '').trim();
    var productCode = stockinProductCodeFromBarcode(rawCode);
    return {
      rawCode: rawCode,
      barcode: rawCode,
      productCode: rawCode,
      catalogProductCode: productCode,
      title: rawCode,
      category: String(document.querySelector('[data-pending-category]').value || '').trim() || '快速入庫待補',
      color: String(document.querySelector('[data-pending-color]').value || '').trim() || '待補顏色',
      size: stockinCanonicalSize(document.querySelector('[data-pending-size]').value),
      warehouse: document.querySelector('[data-pending-warehouse]').value || warehouse.value || '',
      qty: Math.max(1, Number(document.querySelector('[data-pending-qty]').value || 1)),
      imageData: imageData || '',
      imageRole: scannerPhotoRole('pending'),
      unitCostTwd: Math.max(0, Number(document.querySelector('[data-pending-cost]').value || 0)),
      shelf: String(document.querySelector('[data-pending-shelf]').value || '').trim(),
      layer: String(document.querySelector('[data-pending-layer]').value || '').trim(),
      previousStock: 0,
      provisional: true,
      scannedAt: new Date().toISOString()
    };
  }
  function queuePendingStockinLine(line) {
    line.key = stockinLineKey(line);
    var existingIndex = stockinDraft.findIndex(function (old) { return old.key === line.key; });
    if (existingIndex >= 0) stockinDraft[existingIndex].qty = Number(stockinDraft[existingIndex].qty || 0) + Number(line.qty || 1);
    else stockinDraft.push(line);
    if (line.category !== '快速入庫待補') try { localStorage.setItem(stockinRecentCategoryKey, line.category); } catch (error) {}
    saveStockinDraft();
    return existingIndex;
  }
  function autoStagePendingBeforeNextScan(nextCode) {
    if (currentTab !== 'stockin' || pendingPanel.hidden) return Promise.resolve(false);
    var previousCode = String(document.querySelector('[data-pending-code]').value || '').trim();
    if (!previousCode || previousCode === String(nextCode || '').trim()) return Promise.resolve(false);
    var preview = pendingStockinLine('');
    if (preview.unitCostTwd <= 0) {
      var blocked = new Error('上一筆 ' + previousCode + ' 尚未加入：請先補單件成本，再掃下一筆。已保留分類、顏色與數量。');
      blocked.stockinPendingBlocked = true;
      return Promise.reject(blocked);
    }
    return readPendingPhoto().then(function (imageData) {
      var line = pendingStockinLine(imageData);
      var existingIndex = queuePendingStockinLine(line);
      pendingPanel.hidden = true;
      beep(existingIndex >= 0 ? 2 : 1);
      setMessage('上一筆 ' + line.productCode + ' 已自動保留在本次批次，正在加入下一筆。', 'ok');
      return true;
    });
  }
  function pendingAutoBatchMissingFields() {
    var missing = [];
    if (!String(document.querySelector('[data-pending-warehouse]').value || '').trim()) missing.push('入庫倉庫');
    if (!String(document.querySelector('[data-pending-category]').value || '').trim()) missing.push('分類');
    if (!String(document.querySelector('[data-pending-color]').value || '').trim()) missing.push('顏色');
    if (Math.max(0, Number(document.querySelector('[data-pending-cost]').value || 0)) <= 0) missing.push('單件成本');
    return missing;
  }
  function savePending(options) {
    options = options || {};
    var autoFromPhoto = options.autoFromPhoto === true;
    if (autoFromPhoto) {
      var missing = pendingAutoBatchMissingFields();
      if (missing.length) {
        beep(3);
        setMessage('照片已選取，但尚缺「' + missing.join('、') + '」，補齊後請重新選照片，或按手動加入批次。', 'error');
        return;
      }
    }
    var preview = pendingStockinLine('');
    if (preview.unitCostTwd <= 0) {
      beep(3);
      setMessage('三聲：請先填單件成本，才能加入快速入庫批次。', 'error');
      return;
    }
    readPendingPhoto().then(function (imageData) {
      var line = pendingStockinLine(imageData);
      if (pendingPurpose === 'stocktake_issue') {
        post({
          action: 'scan_archive',
          scanCode: line.rawCode,
          scanStatus: 'not_found_photo',
          matchCount: 0,
          note: '公司倉庫無產品，盤點機拍照待人工建檔',
          warehouse: line.warehouse,
          shelf: line.shelf,
          layer: line.layer,
          photoData: imageData,
          operator: operator
        }).then(function () {
          draft.unshift({at:new Date().toISOString(),action:'scan_issue',scanCode:line.rawCode,scanStatus:'not_found_photo',productCode:line.productCode,title:line.title,color:line.color,size:line.size,qty:0,previousStock:0,variance:0,from:line.warehouse,to:line.warehouse,warehouse:line.warehouse,image:imageData,operator:operator.name,note:'已拍照送後台待人工建檔'});
          draft = draft.slice(0, 200);
          saveDraft();
          beep(3);
          setMessage('三聲：照片與首次倉位已存到後台待人工建檔，正式庫存沒有變更。', 'error');
          pendingPanel.hidden = true;
          pendingPurpose = 'stockin';
          readyForNextScan();
        }).catch(function (error) {
          beep(3);
          setMessage(error.message || '待人工建檔儲存失敗', 'error');
        });
        return;
      }
      var existingIndex = queuePendingStockinLine(line);
      beep(existingIndex >= 0 ? 2 : 1);
      setMessage((existingIndex >= 0 ? '兩聲：同一待建檔商品已累加；' : (autoFromPhoto ? '一聲：照片上傳完成，已自動加入批次；' : '一聲：待建檔商品已加入批次；')) + '正式庫存尚未變更，請掃完後儲存整批。', existingIndex >= 0 ? 'error' : 'ok');
      var fileInput = document.querySelector('[data-pending-photo]');
      if (fileInput) fileInput.value = '';
      pendingPanel.hidden = true;
      readyForNextScan();
    }).catch(function (error) {
      beep(3);
      setMessage(error.message || '照片讀取失敗', 'error');
    });
  }
  function finishStocktake() {
    ensureSession();
    if (!confirm('完成本次盤點並送到管理者後台比對？送出後需由主管核准才會修改庫存。')) return;
    post({action: 'stocktake_finalize', sessionId: session.id, warehouse: warehouse.value, operator: operator, startedAt: session.startedAt})
      .then(function () {
        session.status = 'submitted';
        localStorage.setItem(sessionKey, JSON.stringify(session));
        beep(1);
        setMessage('盤點單已送到後台，等待主管在貨倉管理核准。', 'ok');
        session = null;
        ensureSession();
        loadOwnSessions();
      })
      .catch(function (error) {
        beep(3);
        setMessage(error.message || '送出失敗', 'error');
      });
  }
  function renderDraft() {
    var box = document.querySelector('[data-draft-list]');
    if (!draft.length) {
      box.innerHTML = '<p class="empty">尚未掃描</p>';
      return;
    }
    box.innerHTML = draft.slice().sort(function (a,b) { return (Date.parse(b.at)||0)-(Date.parse(a.at)||0); }).map(function (item) {
      var detail = item.action === 'transfer'
        ? '調撥 ' + escapeHtml(item.from) + ' → ' + escapeHtml(item.to) + '，成本 ' + escapeHtml(costMoney(item.transferredUnitCostTwd)) + '（' + escapeHtml(costSourceLabel(item.costSource)) + '）'
        : item.action === 'stockin'
          ? '快速入庫 ' + escapeHtml(item.to) + '，+' + Number(item.qty || 0) + ' 件，單件 ' + escapeHtml(stockinMoney(item.unitCostTwd)) + '／小計 ' + escapeHtml(stockinMoney(item.totalCostTwd)) + (item.stockinBatchId ? '／批號 ' + escapeHtml(item.stockinBatchId) : '') + (item.pendingMetadata ? '／資料待補' : '')
        : item.action === 'pending-stockin'
          ? '待判別入庫 ' + escapeHtml(item.to) + '，數量 ' + Number(item.qty || 0)
        : item.action === 'scan_match'
          ? '掃描歸檔：' + escapeHtml(item.scanCode || '') + '，對到 ' + escapeHtml(item.warehouse || item.to || '') + ' 庫存 ' + Number(item.previousStock || 0) + ' 件'
        : item.action === 'scan_issue'
          ? '掃描異常：' + escapeHtml(item.scanCode || '') + '，' + escapeHtml(item.note || scanArchiveStatusText(item.scanStatus))
        : '盤點 ' + escapeHtml(item.to) + '，帳面 ' + Number(item.previousStock || 0) + ' → 實盤 ' + item.qty + '，差異 ' + Number(item.variance || 0);
      return '<article><img src="' + escapeHtml(resolveImage(item.image)) + '" alt=""><div><b>' + escapeHtml(item.productCode) + '</b><span>' + escapeHtml(item.color) + '／' + escapeHtml(item.size) + '</span><small>' + detail + '</small><small>' + escapeHtml(item.operator || '') + ' · ' + new Date(item.at).toLocaleString('zh-TW') + '</small></div></article>';
    }).join('');
  }
  function closeCamera() {
    cameraSessionId += 1;
    scanning = false;
    if (stream) stream.getTracks().forEach(function (track) { track.stop(); });
    stream = null;
    var activeScanner = cameraScanner;
    cameraScanner = null;
    if (activeScanner) {
      Promise.resolve(activeScanner.stop ? activeScanner.stop() : null).catch(function () {}).then(function () {
        return activeScanner.clear ? activeScanner.clear() : null;
      }).catch(function () {});
    }
    var box = document.querySelector('[data-camera-box]');
    var video = document.querySelector('[data-camera-video]');
    var reader = document.querySelector('[data-camera-reader]');
    if (video) { video.srcObject = null; video.hidden = false; }
    if (reader) reader.hidden = true;
    if (box) box.hidden = true;
    document.body.setAttribute('data-scanner-camera-state', 'closed');
  }

  function loadCameraLibrary() {
    if (window.Html5Qrcode) return Promise.resolve(window.Html5Qrcode);
    if (cameraLibraryPromise) return cameraLibraryPromise;
    cameraLibraryPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = './assets/vendor/html5-qrcode.min.js?v=2.3.8';
      script.async = true;
      script.onload = function () { window.Html5Qrcode ? resolve(window.Html5Qrcode) : reject(new Error('掃碼元件載入失敗')); };
      script.onerror = function () { reject(new Error('掃碼元件載入失敗')); };
      document.head.appendChild(script);
    });
    return cameraLibraryPromise;
  }

  function cameraDecoded(value) {
    var code = String(value || '').trim();
    if (!code) return;
    closeCamera();
    document.body.setAttribute('data-scanner-camera-state', 'decoded');
    var cameraCode = setBarcodeValue(code);
    if (rejectIncompleteScannedBarcode(cameraCode)) return;
    input.dispatchEvent(new Event('input', {bubbles: true}));
    clearTimeout(inputLookupTimer);
    lookup(cameraCode);
  }

  function cameraErrorText(error) {
    var name = String(error && error.name || '');
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') return '相機權限被拒絕。請到瀏覽器網站設定允許相機後，再按一次「手機相機掃碼」';
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') return '找不到手機相機。請確認不是在不支援相機的內嵌瀏覽器中開啟';
    if (name === 'NotReadableError' || name === 'TrackStartError') return '相機正被其他 APP 使用。請關閉其他相機或視訊 APP 後再試';
    if (!window.isSecureContext) return '相機只能在 HTTPS 安全網址開啟，請使用正式網站網址';
    return String(error && error.message || error || '無法開啟相機');
  }

  function showCameraBox(message) {
    var box = document.querySelector('[data-camera-box]');
    var status = document.querySelector('[data-camera-status]');
    if (box) {
      box.hidden = false;
      requestAnimationFrame(function () {
        if (box.scrollIntoView) box.scrollIntoView({behavior: 'smooth', block: 'center'});
      });
    }
    if (status) status.textContent = message || '正在開啟手機相機…';
  }

  function openCameraFallback(reason, sessionId) {
    var video = document.querySelector('[data-camera-video]');
    var reader = document.querySelector('[data-camera-reader]');
    var status = document.querySelector('[data-camera-status]');
    if (video) video.hidden = true;
    if (reader) reader.hidden = false;
    if (status) status.textContent = reason ? (cameraErrorText(reason) + '；正在切換相容掃碼模式…') : '正在開啟手機相機…';
    document.body.setAttribute('data-scanner-camera-state', 'opening');
    return loadCameraLibrary().then(function () {
      if (sessionId !== cameraSessionId) return;
      var formats = window.Html5QrcodeSupportedFormats ? [
        window.Html5QrcodeSupportedFormats.QR_CODE,
        window.Html5QrcodeSupportedFormats.CODE_128,
        window.Html5QrcodeSupportedFormats.CODE_39,
        window.Html5QrcodeSupportedFormats.EAN_13,
        window.Html5QrcodeSupportedFormats.EAN_8,
        window.Html5QrcodeSupportedFormats.UPC_A,
        window.Html5QrcodeSupportedFormats.UPC_E,
        window.Html5QrcodeSupportedFormats.ITF
      ].filter(function (item) { return item != null; }) : [];
      cameraScanner = new window.Html5Qrcode(reader.id || (reader.id = 'scanner-camera-reader'), formats.length ? {formatsToSupport: formats, verbose: false} : {verbose: false});
      return cameraScanner.start(
        {facingMode: 'environment'},
        {fps: 12, aspectRatio: 1.7778, qrbox: function (w, h) { return {width: Math.floor(w * .82), height: Math.max(90, Math.floor(h * .38))}; }},
        cameraDecoded,
        function () {}
      ).then(function () {
        if (sessionId !== cameraSessionId) return;
        document.body.setAttribute('data-scanner-camera-state', 'ready');
        if (status) status.textContent = '相機已開啟。請把一維條碼橫向放進框內，保持清楚與光線充足。';
      });
    }).catch(function (error) {
      if (sessionId !== cameraSessionId) return;
      document.body.setAttribute('data-scanner-camera-state', 'error');
      if (status) status.textContent = cameraErrorText(error) + '。也可以使用下方「拍照辨識」。';
      setMessage(cameraErrorText(error), 'error');
    });
  }

  function scanCameraFile(file) {
    if (!file) return;
    var status = document.querySelector('[data-camera-status]');
    if (status) status.textContent = '照片辨識中…';
    loadCameraLibrary().then(function () {
      var previous = cameraScanner;
      cameraScanner = null;
      return Promise.resolve(previous && previous.stop ? previous.stop() : null).catch(function () {}).then(function () {
        return previous && previous.clear ? previous.clear() : null;
      }).catch(function () {}).then(function () {
        var reader = document.querySelector('[data-camera-reader]');
        var readerId = reader && (reader.id || (reader.id = 'scanner-camera-reader'));
        var scanner = new window.Html5Qrcode(readerId || 'scanner-camera-reader');
        cameraScanner = scanner;
        return scanner.scanFile(file, true);
      });
    }).then(cameraDecoded).catch(function (error) {
      if (status) status.textContent = '照片內找不到條碼，請靠近一點、保持清楚再拍一次。';
      setMessage(error.message || '照片辨識失敗', 'error');
    });
  }

  function openCamera() {
    closeCamera();
    cameraSessionId += 1;
    var sessionId = cameraSessionId;
    document.querySelectorAll('[data-scan-input-mode]').forEach(function (item) {
      item.classList.toggle('is-active', item.getAttribute('data-scan-input-mode') === 'camera');
    });
    setInputMode('camera');
    input.setAttribute('inputmode', 'none');
    input.placeholder = '相機掃碼完成後會自動帶入條碼';
    showCameraBox('正在請求手機相機權限…');
    openCameraFallback(null, sessionId);
  }
  function applyScannerTabChrome(tab, button) {
    currentTab = tab;
    document.body.setAttribute('data-current-scanner-tab', tab);
    document.querySelectorAll('[data-tab]').forEach(function (btn) {
      btn.classList.toggle('is-active', button ? btn === button : btn.getAttribute('data-tab') === tab);
    });
    var stocktakeFields = document.querySelector('[data-stocktake-fields]');
    var transferFields = document.querySelector('[data-transfer-fields]');
    var arrivalFields = document.querySelector('[data-arrival-fields]');
    var stockinFields = document.querySelector('[data-stockin-fields]');
    var transferWarehouseFields = document.querySelector('[data-transfer-warehouse-fields]');
    var arrivalWarehouseLabel = document.querySelector('[data-arrival-warehouse-label]');
    var lookupWarehouseLabel = document.querySelector('[data-lookup-warehouse-label]');
    var sessionActions = document.querySelector('[data-session-actions]');
    if (stocktakeFields) stocktakeFields.hidden = tab !== 'stocktake';
    if (transferFields) transferFields.hidden = tab !== 'transfer';
    if (arrivalFields) arrivalFields.hidden = tab !== 'arrival';
    if (stockinFields) stockinFields.hidden = tab !== 'stockin';
    if (transferWarehouseFields) transferWarehouseFields.hidden = tab !== 'transfer';
    if (arrivalWarehouseLabel) arrivalWarehouseLabel.hidden = tab !== 'arrival';
    var warehouseLabel = document.querySelector('[data-stocktake-warehouse-label]');
    if (warehouseLabel) {
      warehouseLabel.hidden = tab !== 'stocktake' && tab !== 'stockin';
      if (warehouseLabel.firstChild && warehouseLabel.firstChild.nodeType === 3) warehouseLabel.firstChild.nodeValue = tab === 'stockin' ? '入庫倉' : '盤點倉庫';
    }
    if (lookupWarehouseLabel) lookupWarehouseLabel.hidden = tab !== 'lookup';
    if (sessionActions) sessionActions.hidden = tab !== 'stocktake';
    document.querySelectorAll('[data-stocktake-session-context]').forEach(function (item) { item.hidden = tab !== 'stocktake'; });
    if (operationPanel) operationPanel.hidden = tab !== 'stockin' && tab !== 'arrival';
    hidePending();
    renderTransferDraft();
    renderStockinDraft();
    updateCurrentTabLabel(tab);
  }
  function switchTab(tab, button) {
    if (!canUseTab(tab)) {
      setMessage('此帳號沒有這項功能權限。', 'error');
      return;
    }
    resetProductSelection();
    readyForNextScan();
    applyScannerTabChrome(tab, button);
    setMessage(tab === 'lookup' ? '選擇倉庫後掃碼，可查看圖片與該倉庫存。' : tab === 'transfer' ? '先選來源倉與目的倉；掃到有效產品會直接加入調撥單，最後按「儲存整張調撥單」。' : tab === 'arrival' ? '先選實際收到貨物的目的倉，再掃商品；系統會找跨倉客戶訂單、寫入到貨調撥並送進出貨準備。' : tab === 'stockin' ? '先選上方入庫倉與下方貨架／層位，掃碼加入清單；選定會寫入本批，也可按「同倉架套用本批」。' : '選擇盤點倉庫後開始掃碼。');
    if (activeScanCode()) scheduleInputLookup();
  }

  document.addEventListener('click', function (event) {
    var modeButton = event.target.closest('[data-stockin-mode-button]');
    if (modeButton) {
      var modeSelect = modeButton.parentElement.querySelector('[data-stockin-line-action]');
      modeSelect.value = modeButton.getAttribute('data-mode-value');
      modeSelect.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    var target = event.target.closest && event.target.closest('button');
    if (!target) return;
    if (target.matches('[data-restore-missing-stocktake]')) {
      if (!latestMissingStocktakeScan || !latestMissingStocktakeScan.scanCode) {
        setMessage('目前沒有可恢復的未建檔商品。', 'error');
        return;
      }
      if (warehouse && latestMissingStocktakeScan.warehouse) warehouse.value = latestMissingStocktakeScan.warehouse;
      showPending(latestMissingStocktakeScan.scanCode, 'stocktake_issue');
      setMessage('已找回建檔草稿（' + missingStocktakeDisplayCode(latestMissingStocktakeScan.scanCode) + '）；請補上商品資料與照片後送到後台。', 'ok');
      pendingPanel.scrollIntoView({block:'nearest'});
      return;
    }
    if (target.matches('[data-resume-stocktake-session]')) {
      var resumeSession = ownSessionRows[Number(target.getAttribute('data-resume-stocktake-session'))];
      restoreStocktakeDraft(resumeSession);
      return;
    }
    if (target.matches('[data-delete-old-session]')) {
      var oldSession = ownSessionRows[Number(target.getAttribute('data-delete-old-session'))];
      if (!oldSession || !confirm('刪除盤點單 ' + oldSession.id + '（' + (oldSession.lines || []).length + ' 個 SKU）？此單將封存並停止編輯，保留可復原備份，不改動正式庫存。')) return;
      target.disabled = true;
      post({action:'stocktake_delete_old',sessionId:oldSession.id}).then(function () { loadOwnSessions();setMessage('盤點單已從清單移除，保留可復原備份；正式庫存未變更。','ok'); }).catch(function (error) {target.disabled=false;alert(error.message || '刪除失敗');});
      return;
    }
    if (target.matches('[data-cross-warehouse-use-reference]')) {
      var referencePanel = document.querySelector('[data-cross-warehouse-choice]');
      var referenceContext = crossWarehouseContext || (referencePanel && referencePanel._crossWarehouseContext);
      if (!referenceContext) { setMessage('跨倉產品資料已逾時，請重新掃描一次。', 'error'); return; }
      var referenceCode = referenceContext.code;
      var referenceTarget = referenceContext.targetWarehouse;
      var sourceForClone = (referenceContext.sourceRows && referenceContext.sourceRows.length)
        ? referenceContext.sourceRows
        : (referenceContext.rows || []);
      if (referencePanel) referencePanel.hidden = true;
      if (warehouse) warehouse.value = referenceTarget;
      ensureSession();
      applyScannerTabChrome('stocktake', document.querySelector('[data-tab="stocktake"]'));
      var clonedRows = cloneRowsForCurrentStocktake(sourceForClone, referenceTarget);
      clonedRows.forEach(function (row) { row.scannedBarcode = referenceCode; });
      if (!clonedRows.length) {
        setMessage('沒有可帶入的產品規格，請重新掃描。', 'error');
        return;
      }
      allMatches = clonedRows.slice();
      renderMatches(clonedRows);
      currentStocktakeScanCode = referenceCode;
      stocktakeLiveCounts[referenceCode] = Math.max(1, Number(stocktakeLiveCounts[referenceCode] || 1));
      var qtyField = document.querySelector('[data-qty]');
      if (qtyField) qtyField.value = String(stocktakeLiveCounts[referenceCode]);
      if (clonedRows.length === 1) {
        choose(clonedRows[0], 0);
        if (canReuseStocktakeSharedLocation()) {
          clonedRows[0].shelf = stocktakeSharedLocation.shelf;
          clonedRows[0].layer = stocktakeSharedLocation.layer;
          stocktakeFlowLock = {
            barcode: referenceCode,
            skuId: String(clonedRows[0].skuId || ''),
            shelf: stocktakeSharedLocation.shelf,
            layer: stocktakeSharedLocation.layer
          };
          if (matchPanel) matchPanel.hidden = true;
        } else {
          var crossQty = document.querySelector('[data-match-location-qty]');
          if (crossQty) crossQty.value = String(stocktakeLiveCounts[referenceCode]);
          var crossEditor = document.querySelector('[data-match-first-location]');
          if (crossEditor) crossEditor.hidden = false;
          if (matchPanel) matchPanel.hidden = false;
        }
      } else {
        selected = null;
        if (matchGrid) matchGrid.hidden = false;
        if (matchPanel) {
          matchPanel.hidden = false;
          var matchTitle = matchPanel.querySelector('h2');
          if (matchTitle) matchTitle.textContent = '帶入本次盤點｜請選顏色／尺寸';
        }
      }
      renderStocktakeFastRows();
      var board = document.querySelector('[data-stocktake-fast-board]');
      if (board) {
        board.hidden = false;
        try { board.scrollIntoView({ block: 'nearest' }); } catch (error) {}
      }
      stocktakeFlowStatus('已帶入其他倉的編號／條碼／顏色到本次盤點明細；帳面 0，核准前不改庫存。');
      setMessage('已列在「本次盤點明細」：' + referenceCode + '／' + (clonedRows[0].color || '') + '。這不是入庫，不會立刻改庫存。請確認規格與層架後完成這一件。', 'ok');
      archiveScanResult(referenceCode, 'other_warehouse_reference', clonedRows[0] || null, clonedRows.length, '帶入本次盤點明細，不直接改庫存');
      return;
    }
    if (target.matches('[data-cross-warehouse-transfer]')) {
      var transferPanel = document.querySelector('[data-cross-warehouse-choice]');
      var transferContext = crossWarehouseContext || (transferPanel && transferPanel._crossWarehouseContext);
      if (!transferContext || !transferContext.sources.length) { setMessage('跨倉產品資料已逾時，請重新掃描一次。', 'error'); return; }
      var source = transferContext.sources[0];
      var transferTabButton = document.querySelector('[data-tab="transfer"]');
      switchTab('transfer', transferTabButton);
      sourceWarehouse.value = source.warehouse;
      targetWarehouse.value = transferContext.targetWarehouse;
      setMessage('已切換調撥：' + source.warehouse + ' → ' + transferContext.targetWarehouse + '。請選擇正確顏色／尺寸，加入調撥單後再儲存整張調撥單。', 'ok');
      lookup(transferContext.code);
      return;
    }
    if (target.matches('[data-cross-warehouse-cancel]')) {
      resetProductSelection();
      readyForNextScan();
      setMessage('已取消這筆跨倉選擇，庫存沒有變動。', 'ok');
      return;
    }
    if (target.dataset.tab) { switchTab(target.dataset.tab, target); setTabsCollapsed(true); }
    if (target.matches('[data-match-index]')) {
      var matchIndex = Number(target.dataset.matchIndex);
      var matchRow = allMatches[matchIndex];
      if (currentTab === 'stocktake') {
        currentStocktakeScanCode = String(matchRow.scannedBarcode || matchRow.barcode || matchRow.skuId || '').trim();
        stocktakeLiveCounts[currentStocktakeScanCode] = Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 0));
      }
      choose(matchRow, matchIndex, true);
      if (currentTab === 'stocktake') {
        if (input) input.blur();
        if (manualInput) manualInput.blur();
        setTimeout(function () {
          var destination = matchPanel && !matchPanel.hidden ? document.querySelector('[data-match-first-location]') : document.querySelector('[data-stocktake-fast-board]');
          if (destination) destination.scrollIntoView({block:'center'});
        }, 150);
        var canReuseSharedLocation = canReuseStocktakeSharedLocation();
        if (canReuseSharedLocation) {
          matchRow.shelf = stocktakeSharedLocation.shelf;
          matchRow.layer = stocktakeSharedLocation.layer;
          stocktakeFlowLock = {
            barcode: currentStocktakeScanCode,
            skuId: String(matchRow.skuId || ''),
            shelf: stocktakeSharedLocation.shelf,
            layer: stocktakeSharedLocation.layer
          };
          var reusedQty = document.querySelector('[data-qty]');
          if (reusedQty) reusedQty.value = String(Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 1)));
          if (matchPanel) matchPanel.hidden = true;
          renderStocktakeFastRows();
          stocktakeFlowStatus('已選定 ' + (matchRow.productCode || '') + '／' + (matchRow.color || '') + '／' + (matchRow.size || 'NO SIZE') + '，並沿用 ' + stocktakeSharedLocation.shelf + '／' + stocktakeSharedLocation.layer + '；可直接掃下一個產品。');
          setMessage('產品已確認，位置沿用 ' + stocktakeSharedLocation.shelf + '／' + stocktakeSharedLocation.layer + '；不必重新選倉架。要換位置時再按「同產品另放一處（新增）」。', 'ok');
          readyForNextScan();
        } else {
        var selectedLocationEditor = document.querySelector('[data-match-first-location]');
        if (selectedLocationEditor) selectedLocationEditor.hidden = false;
        var selectedLocationQty = document.querySelector('[data-match-location-qty]');
        if (selectedLocationQty) selectedLocationQty.value = String(Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 1)));
        if (matchPanel) matchPanel.hidden = false;
        var selectedMatchTitle = matchPanel ? matchPanel.querySelector('h2') : null;
        if (selectedMatchTitle) selectedMatchTitle.textContent = '商品已確認｜選擇貨架與層位';
        stocktakeFlowStatus('已選定 ' + (matchRow.productCode || '') + '／' + (matchRow.color || '') + '／' + (matchRow.size || 'NO SIZE') + '，第一件已計入；請選貨架與層位後按確定。');
        }
      }
      var matchedCode = String(matchRow.scannedBarcode || matchRow.barcode || matchRow.skuId || '').trim();
      if (currentTab === 'transfer') queueTransfer(matchRow, matchedCode);
      if (currentTab === 'arrival') receiveCrossWarehouse(matchRow, matchedCode);
      if (currentTab === 'stockin') queueStockinRow(matchRow, matchedCode);
    }
    if (target.matches('[data-save-match-location]')) {
      if (currentTab !== 'stocktake') { setMessage('此按鈕只用於盤點。調撥請設定來源倉、目的倉與調撥數量後加入調撥單。','error'); return; }
      // A unique displayed match can be confirmed directly from the location form.
      if (!selected && allMatches.length === 1) {
        selected = allMatches[0];
        currentStocktakeScanCode = String(selected.scannedBarcode || selected.barcode || selected.skuId || '');
      }
      if (!selected || !selected.skuId) { setMessage('請先選擇正確產品與顏色。','error'); return; }
      var matchShelf = String((document.querySelector('[data-match-shelf]') || {}).value || '').trim();
      var matchLayer = String((document.querySelector('[data-match-layer]') || {}).value || '').trim();
      var matchWarehouse = String((document.querySelector('[data-match-location-warehouse]') || {}).value || '').trim();
      var matchLocationQty = Math.max(0, Number((document.querySelector('[data-match-location-qty]') || {}).value || 0));
      if (!matchWarehouse) { beep(2); setMessage('兩聲：請先選擇盤點倉庫。','error'); return; }
      if (!matchShelf || !matchLayer) { beep(2); setMessage('兩聲：第一次紀錄請同時選擇貨架與層位。','error'); return; }
      if (currentTab === 'stocktake' && matchLocationQty < 1) { beep(2); setMessage('兩聲：請輸入這個倉架目前至少 1 件；若尚未開始放貨，請先掃第一件。','error'); return; }
      if (warehouse && warehouse.value !== matchWarehouse) { warehouse.value = matchWarehouse; warehouse.dispatchEvent(new Event('change')); }
      if (warehouse.value !== matchWarehouse) { setMessage('已取消切換倉庫，本筆尚未確認。','error'); return; }
      currentStocktakeScanCode = String(currentStocktakeScanCode || selected.scannedBarcode || selected.barcode || selected.skuId || '');
      var saveLocationButton = target;
      var saveLocationButtonText = saveLocationButton.textContent;
      saveLocationButton.disabled = true;
      saveLocationButton.textContent = '正在儲存位置…';
      setMessage('正在確認 ' + matchWarehouse + '／' + matchShelf + '／' + matchLayer + '，請稍候…');
      // Stocktake locations belong to the draft; do not update the master SKU here.
      var saveLocationRequest = currentTab === 'stocktake' ? Promise.resolve({localLocationSegment:true}) : post({action:'bind_sku_location',skuId:selected.skuId,warehouse:matchWarehouse,shelf:matchShelf,layer:matchLayer,operator:operator});
      saveLocationRequest.then(function () {
        saveLocationButton.disabled = false;
        saveLocationButton.textContent = saveLocationButtonText;
        if (!stocktakeChangingLocation) { selected.shelf = matchShelf; selected.layer = matchLayer; }
        if (currentTab === 'stocktake') stocktakeFlowLock = { barcode: String(currentStocktakeScanCode || activeScanCode() || selected.barcode || ''), skuId: String(selected.skuId || ''), shelf: matchShelf, layer: matchLayer };
        if (currentTab === 'stocktake') {
          stocktakeSharedLocation = {warehouse:matchWarehouse,shelf:matchShelf,layer:matchLayer};
          if (session) { session.stocktakeActiveLocation = stocktakeSharedLocation; localStorage.setItem(sessionKey, JSON.stringify(session)); }
        }
        if (currentTab === 'stocktake') {
          stocktakeLiveCounts[String(currentStocktakeScanCode || activeScanCode() || selected.barcode || '')] = matchLocationQty;
          var mainQtyInput = document.querySelector('[data-qty]');
          if (mainQtyInput) mainQtyInput.value = String(matchLocationQty);
        }
        else if (stocktakeFlowLock.skuId === String(selected.skuId || '')) { stocktakeFlowLock.shelf = matchShelf; stocktakeFlowLock.layer = matchLayer; }
        var box = document.querySelector('[data-match-first-location]'); if (box) box.hidden = true;
        beep(1); setMessage('一聲：產品與位置已確認為 ' + matchShelf + '／' + matchLayer + '，目前 ' + matchLocationQty + ' 件；下一刷同條碼會變 ' + (matchLocationQty + 1) + '。','ok');
        stocktakeFlowStatus('產品與位置已確認：' + matchShelf + '／' + matchLayer + '，目前 ' + matchLocationQty + ' 件。同編號若還放在別處，按「同產品另放一處（新增）」會另開第二筆，不覆蓋本筆。');
        renderMatches(allMatches);
        choose(selected, Math.max(0, allMatches.indexOf(selected)), selected.colorConfirmed === true);
        stocktakeLiveCounts[currentStocktakeScanCode] = matchLocationQty;
        var confirmedQtyInput = document.querySelector('[data-qty]');
        if (confirmedQtyInput) confirmedQtyInput.value = String(matchLocationQty);
        if (currentTab === 'stocktake') { matchPanel.hidden = true; renderStocktakeFastRows(); readyForNextScan(); }
        stocktakeChangingLocation = false;
      }).catch(function (error) { saveLocationButton.disabled = false; saveLocationButton.textContent = saveLocationButtonText; beep(3); setMessage(error.message || '首次倉位儲存失敗','error'); });
    }
    if (target.matches('[data-toggle-match-location-adjust]')) {
      var locationEditor = document.querySelector('[data-match-first-location]');
      if (locationEditor) locationEditor.hidden = !locationEditor.hidden;
    }
    if (target.matches('[data-fast-change-location],[data-fast-split-location]')) {
      var currentSegmentQty = Number((document.querySelector('[data-fast-stocktake-qty]') || document.querySelector('[data-qty]') || {}).value || 0);
      if (target.matches('[data-fast-split-location]') && currentTab === 'stocktake' && selected && stocktakeFlowLock.shelf && stocktakeFlowLock.layer && currentSegmentQty > 0) {
        stocktakeChangingLocation = true;
        submit('stocktake', {continueAtNewLocation:true});
        return;
      }
      var fastLocationEditor = document.querySelector('[data-match-first-location]');
      if (fastLocationEditor) fastLocationEditor.hidden = false;
      var fastLocationQty = document.querySelector('[data-match-location-qty]');
      if (fastLocationQty) fastLocationQty.value = String(Math.max(0, currentSegmentQty));
      if (matchPanel) matchPanel.hidden = false;
      var matchTitle = matchPanel ? matchPanel.querySelector('h2') : null;
      if (matchTitle) matchTitle.textContent = '確認層架位置';
      stocktakeFlowLock.shelf = '';
      stocktakeFlowLock.layer = '';
      stocktakeChangingLocation = true;
      stocktakeFlowStatus('更正本筆位置：數量保留，舊位置不另存一筆。請選正確倉架／層架並確認。');
    }
    if (target.matches('[data-fast-qty-minus],[data-fast-qty-plus]')) {
      var quickQty = document.querySelector('[data-fast-stocktake-qty]');
      var mainQty = document.querySelector('[data-qty]');
      if (quickQty) {
        var nextQuickQty = Math.max(0, Number(quickQty.value || 0) + (target.matches('[data-fast-qty-plus]') ? 1 : -1));
        quickQty.value = String(nextQuickQty);
        if (mainQty) mainQty.value = String(nextQuickQty);
        if (currentStocktakeScanCode) stocktakeLiveCounts[currentStocktakeScanCode] = nextQuickQty;
      }
    }
    if (target.matches('[data-fast-confirm-stocktake]')) {
      if (selected && selected.colorConfirmationRequired && !selected.colorConfirmed) { beep(2); setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。','error'); return; }
      var fastQty = document.querySelector('[data-fast-stocktake-qty]');
      var qtyField = document.querySelector('[data-qty]');
      if (fastQty && qtyField) qtyField.value = fastQty.value;
      submit('stocktake');
    }
    if (target.matches('[data-print-stocktake-label]')) {
      var printIndex = target.getAttribute('data-print-stocktake-label');
      var printLine = printIndex === 'current' ? selected : draft[Number(printIndex)];
      if (!printLine) return;
      if (printLine.colorConfirmationRequired && !printLine.colorConfirmed) {
        setMessage('請先更換顏色／尺寸並確認實品顏色，再列印標籤。','error'); return;
      }
      var printQty = printIndex === 'current' ? Number((document.querySelector('[data-fast-stocktake-qty]') || document.querySelector('[data-qty]') || {}).value) : Number(printLine.qty);
      if (!Number.isInteger(printQty) || printQty < 1) { setMessage('請先填寫大於 0 的盤點數量。','error'); return; }
      printScannerLineBarcode(printLine, printQty);
      return;
    }
    if (target.matches('[data-close-required-photo]')) {
      var requiredPhoto = document.querySelector('[data-stocktake-photo-box]');
      if (requiredPhoto) { requiredPhoto.hidden = true; requiredPhoto.classList.remove('is-required-photo-popup'); }
    }
    if (target.matches('[data-start-stocktake]')) startStocktake(true);
    if (target.matches('[data-refresh-sessions]')) loadOwnSessions();
    if (target.matches('[data-own-session-approve]')) {
      if (operator.role !== 'admin' || target.disabled) return;
      var approvalId = target.getAttribute('data-own-session-approve');
      if (!confirm('確定核准 ' + approvalId + '？若仍在盤點中，將結束這張草稿，盤點人不能繼續編輯。本單商品將以伺服器已儲存的實盤數量更新正式庫存，不是額外加庫存。請確認盤點人已完成上傳。')) return;
      target.disabled = true;
      target.textContent = '核准處理中，請勿重複操作…';
      post({action:'stocktake_approve',sessionId:approvalId,operator:operator,approveDraft:true}).then(function () {
        loadOwnSessions();
        setMessage('盤點單 ' + approvalId + ' 已核准，正式庫存已更新。','ok');
      }).catch(function(error) {
        loadOwnSessions();
        alert((error.message || '核准結果未確認') + '。請先重新整理確認此單狀態，勿重複核准。');
      });
      return;
    }
    if (target.matches('[data-confirm-stocktake]')) {
      if (selected && selected.colorConfirmationRequired && !selected.colorConfirmed) { beep(2); setMessage('兩聲：請先由行政重新選取正確顏色／尺寸。','error'); return; }
      submit('stocktake');
    }
    if (target.matches('[data-confirm-transfer]')) submit('transfer');
    if (target.matches('[data-confirm-arrival]')) receiveCrossWarehouse(selected, activeScanCode());
    if (target.matches('[data-save-transfer-draft]')) saveTransferSlip();
    if (target.matches('[data-stockin-add-location]')) applyStockinNewLocation();
    if (target.matches('[data-stockin-photo-clear]')) clearStockinBatchPhoto('快速入庫照片已清除。');
    if (target.matches('[data-remove-transfer-line]')) {
      transferDraft.splice(Number(target.dataset.removeTransferLine), 1);
      saveTransferDraft();
      setMessage('已從本次調撥單移除，庫存尚未變更。');
    }
    if (target.matches('[data-clear-transfer-draft]')) {
      transferDraft = [];
      saveTransferDraft();
      setMessage('本次調撥單已清空，正式庫存沒有變更。');
    }
    if (target.matches('[data-save-stockin-draft]')) saveStockinBatch();
    if (target.matches('[data-print-stockin-barcode]')) printStockinDraftBarcode(Number(target.dataset.printStockinBarcode));
    if (target.matches('[data-close-initial-stock-mode]')) {
      setMessage('臨時入庫是日常入口，已依峰志指示保持開放，不能關閉。', 'ok');
      return;
    }
    if (target.matches('[data-stockin-toggle-location]')) {
      var stockinLocationLine = target.closest('[data-stockin-draft-line]');
      if (stockinLocationLine) {
        stockinLocationLine.classList.toggle('is-adjusting-location');
        target.textContent = stockinLocationLine.classList.contains('is-adjusting-location') ? '收合貨架調整' : '調整貨架位置';
      }
    }
    if (target.matches('[data-remove-stockin-line]')) {
      stockinDraft.splice(Number(target.dataset.removeStockinLine), 1);
      saveStockinDraft();
      setMessage('已從本次臨時建檔清單移除；正式庫存尚未變更。');
    }
    if (target.matches('[data-clear-stockin-draft]')) {
      stockinDraft = [];
      saveStockinDraft();
      setMessage('本次臨時建檔清單已清空；正式庫存沒有變更。');
    }
    if (target.matches('[data-stockin-apply-category]')) applyStockinDefault('category');
    if (target.matches('[data-stockin-apply-cost]')) applyStockinDefault('cost');
    if (target.matches('[data-stockin-apply-location]')) {
      var applied = applyStockinDefaultLocation({ skipRender: false, forceWarehouse: true });
      if (applied) setMessage('已將入庫倉／貨架／層位套用本批 ' + applied + ' 個品項。', 'ok');
      else if (stockinDraft.length) setMessage('本批倉架沒有變更。', 'ok');
    }
    if (target.matches('[data-stockin-line-paste-photo]')) {
      pendingStockinLinePhotoIndex = Number(target.getAttribute('data-stockin-line-paste-photo'));
      setMessage('已選第 ' + (pendingStockinLinePhotoIndex + 1) + ' 筆，請按 Ctrl+V 貼上照片。', 'ok');
    }
    if (target.matches('[data-finish-stocktake]')) finishStocktake();
    if (target.matches('[data-camera-open]')) openCamera();
    if (target.matches('[data-scan-submit]')) {
      var submittedCode = activeScanCode();
      clearTimeout(inputLookupTimer);
      clearTimeout(hardwareScanTimer);
      hardwareScanBuffer = '';
      lookup(submittedCode);
    }
    if (target.matches('[data-manual-scan-submit]')) { clearTimeout(inputLookupTimer); setInputMode('manual'); lookup(activeScanCode()); }
    if (target.matches('[data-clear-scan-input]')) clearScanInput('掃描欄已清除，請重新掃描。');
    if (target.matches('[data-scan-tab-toggle]')) setTabsCollapsed(!scanTabsCollapsed);
    if (target.matches('[data-camera-close]')) closeCamera();
    if (target.matches('[data-save-pending]')) savePending();
    if (target.matches('[data-cancel-pending]')) hidePending();
    if (target.matches('[data-install-app]')) {
      if (installPrompt) {
        var prompt = installPrompt;
        installPrompt = null;
        target.disabled = true;
        target.textContent = '正在開啟安裝…';
        setMessage('正在開啟 Android 安裝視窗，請按「安裝」。', 'ok');
        Promise.resolve(prompt.prompt()).then(function () {
          return prompt.userChoice;
        }).then(function (choice) {
          if (choice && choice.outcome === 'accepted') {
            target.hidden = true;
            setMessage('LINGZANZAN盤點系統已安裝，可從桌面開啟。', 'ok');
          } else {
            target.disabled = false;
            target.textContent = '安裝 LINGZANZAN';
            setMessage('尚未安裝。請再按一次，或用 Chrome 右上角選單選「安裝應用程式」。', 'error');
          }
        }).catch(function () {
          target.disabled = false;
          target.textContent = '請用 Chrome 安裝';
          setMessage('目前瀏覽器無法直接安裝，請用 Chrome 開啟後選「安裝應用程式」。', 'error');
        });
      } else {
        target.textContent = '請用 Chrome 安裝';
        setMessage('尚未取得 Android 安裝權限。請確認使用 Chrome，然後按右上角「⋮」→「安裝應用程式」。', 'error');
        setMessage('請使用 Chrome：右上角「⋮」→「安裝應用程式」。', 'error');
      }
    }
    if (target.matches('[data-clear-draft]') && confirm('只清除這台裝置的顯示紀錄？正式紀錄不會刪除。')) {
      draft = [];
      saveDraft();
    }
  });
  var matchColorSelect = document.querySelector('[data-match-color-select]');
  if (matchColorSelect) matchColorSelect.addEventListener('change', function () {
    var index = Number(this.value || 0);
    if (!allMatches[index]) return;
    choose(allMatches[index], index, true);
    beep(2);
    setMessage('兩聲：條碼只對到 P 前產品，已切換為 ' + (allMatches[index].color || '未設定顏色') + '／' + (allMatches[index].size || 'NO SIZE') + '，請看圖片確認。','error');
  });
  document.addEventListener('input', function (event) {
    if (!event.target || !event.target.matches('[data-fast-stocktake-qty]')) return;
    var qtyField = document.querySelector('[data-qty]');
    if (qtyField) qtyField.value = event.target.value;
    refreshStocktakeCompare();
  });
  var cameraFile = document.querySelector('[data-camera-file]');
  if (cameraFile) cameraFile.addEventListener('change', function () {
    scanCameraFile(cameraFile.files && cameraFile.files[0]);
    cameraFile.value = '';
  });
  function wireScannerTextInput(field, mode) {
    if (!field) return;
    field.addEventListener('beforeinput', function (event) {
      if (event.inputType && event.inputType.indexOf('delete') === 0) return;
      if (event.inputType === 'insertLineBreak') return;
      var incoming = event.data == null ? '' : String(event.data);
      if (incoming && !isAllowedScanText(incoming)) {
        event.preventDefault();
        var clean = normalizeScanCode(incoming);
        if (clean && typeof field.setRangeText === 'function') {
          field.setRangeText(clean, field.selectionStart || field.value.length, field.selectionEnd || field.value.length, 'end');
          field.dispatchEvent(new Event('input', {bubbles: true}));
        }
      }
    });
    field.addEventListener('keydown', function (event) {
      if (event.key === ' ' || event.code === 'Space' || event.keyCode === 32 || event.which === 32) {
        event.preventDefault();
        sanitizeScanField(field);
        return;
      }
      if (isHardwareTerminator(event)) {
        event.preventDefault();
        clearTimeout(inputLookupTimer);
        if (mode === 'manual') setInputMode('manual');
        if (mode === 'hardware') flushHardwareScan();
        else lookup(activeScanCode());
      }
    });
    field.addEventListener('input', function () {
      if (mode === 'manual') scanInputMode = 'manual';
      var clean = sanitizeScanField(field);
      if (mode === 'manual') updateScanEcho(clean, clean ? '正在輸入／等待查詢' : '等待掃描槍');
      else updateScanEcho('', clean ? '正在接收掃描槍條碼' : '等待掃描槍');
      if (mode === 'hardware' && hardwareTerminatorDeadline && clean.length >= 2) {
        flushHardwareScan();
        return;
      }
      if (mode === 'hardware' && clean.length >= 2) {
        hardwareScanBuffer = clean;
        clearTimeout(hardwareScanTimer);
        hardwareScanTimer = setTimeout(flushHardwareScan, hardwareScanDelayMs);
        return;
      }
      if (mode === 'manual') scheduleInputLookup();
    });
    field.addEventListener('change', function () {
      var clean = sanitizeScanField(field);
      updateScanEcho(clean, clean ? '已接收掃描字串' : '等待掃描槍');
      if (mode === 'hardware' && String(input.value || '').trim()) flushHardwareScan();
      else if (mode === 'manual' && activeScanCode()) scheduleInputLookup();
    });
    field.addEventListener('compositionend', function () {
      var clean = sanitizeScanField(field);
      updateScanEcho(clean, clean ? '已清除中文輸入，只保留英文數字' : '等待掃描槍');
      if (mode === 'manual') scheduleInputLookup();
    });
    field.addEventListener('paste', function () {
      setTimeout(function () {
        var clean = sanitizeScanField(field);
        updateScanEcho(clean, clean ? '已貼上並清理成英文數字' : '貼上內容已清除');
        if (mode === 'manual') scheduleInputLookup();
      }, 0);
    });
  }
  wireScannerTextInput(input, 'hardware');
  wireScannerTextInput(manualInput, 'manual');
  function prepareHardwareFieldTap() {
    if (!input || (scanInputMode !== 'hardware' && scanInputMode !== 'ccd')) return;
    // 使用者碰到欄位時短暫設為唯讀，避免 Android 叫出軟鍵盤；
    // 不切換 inputmode，讓 YGF 的文字輸入通道維持可用。
    input.setAttribute('inputmode', 'text');
    input.readOnly = true;
    input.setAttribute('aria-readonly', 'true');
    setTimeout(function () {
      if ((scanInputMode === 'hardware' || scanInputMode === 'ccd') && document.activeElement === input) {
        input.readOnly = false;
        input.setAttribute('aria-readonly', 'false');
      }
    }, 220);
  }
  if (input) {
    input.addEventListener('touchstart', prepareHardwareFieldTap, {passive: true});
    input.addEventListener('mousedown', prepareHardwareFieldTap, true);
    input.addEventListener('focus', prepareHardwareFieldTap);
  }
  function suspendScannerForMediaPicker() {
    scannerMediaPickerOpen = true;
    clearTimeout(scannerMediaPickerTimer);
    scannerMediaPickerTimer = setTimeout(function () {
      scannerMediaPickerOpen = false;
    }, 120000);
  }
  function resumeScannerAfterMediaPicker(delay) {
    clearTimeout(scannerMediaPickerTimer);
    scannerMediaPickerTimer = setTimeout(function () {
      scannerMediaPickerOpen = false;
      focusActiveScanInput();
    }, Math.max(500, Number(delay || 0)));
  }
  document.addEventListener('click', function (event) {
    var mediaInput = event.target && event.target.closest && event.target.closest('input[type="file"]');
    if (mediaInput) suspendScannerForMediaPicker();
  }, true);
  window.addEventListener('blur', function () {
    if (scannerMediaPickerOpen) clearTimeout(scannerMediaPickerTimer);
  });
  window.addEventListener('focus', function () {
    if (scannerMediaPickerOpen) resumeScannerAfterMediaPicker(1200);
  });
  setInterval(function () {
    if (scanInputMode !== 'hardware' && scanInputMode !== 'ccd') return;
    var polledCode = normalizeScanCode(String(input && input.value || ''));
    if (polledCode.length < 2) return;
    // Polling is only a fallback for WebViews that change .value without an
    // input event. Do not repeatedly schedule a 40 ms partial lookup while
    // the scanner is still typing the rest of the barcode.
    if (polledCode === hardwareScanBuffer) return;
    hardwareScanBuffer = polledCode;
    clearTimeout(hardwareScanTimer);
    hardwareScanTimer = setTimeout(flushHardwareScan, hardwareScanDelayMs);
  }, 120);
  setInterval(function () {
    if (scanInputMode !== 'hardware' && scanInputMode !== 'ccd') return;
    if (!shouldKeepScanInputFocus()) return;
    if (document.body.classList.contains('is-scanner-locked')) return;
    var openLocation = document.querySelector('[data-match-first-location]:not([hidden])');
    var openMatches = document.querySelector('[data-match-grid]:not([hidden])');
    if (openLocation || openMatches) return;
    var active = document.activeElement;
    var tag = String(active && active.tagName || '').toLowerCase();
    if (active === manualInput || (active && active !== input && active.matches && active.matches('input,[contenteditable="true"]')) || tag === 'select' || tag === 'textarea') return;
    if (active !== input) focusActiveScanInput();
  }, 350);
  document.addEventListener('pointerdown', function (event) {
    var target = event.target && event.target.closest && event.target.closest('select, textarea, option, [data-warehouse], [data-lookup-warehouse], [data-source-warehouse], [data-target-warehouse], [data-arrival-warehouse], [data-stockin-shelf], [data-stockin-layer], [data-pending-shelf], [data-pending-layer], [data-pending-warehouse], [data-match-shelf], [data-match-layer], [data-match-location-warehouse], [data-match-color-select], [data-stockin-line-warehouse], [data-stockin-line-shelf], [data-stockin-line-layer], [data-stockin-default-category], [data-stockin-default-color], [data-stockin-qty], [data-qty], [data-fast-stocktake-qty], [data-match-location-qty], [data-own-stocktake-qty]');
    if (target) suspendScannerForUiPicker();
  }, true);
  document.addEventListener('touchstart', function (event) {
    var target = event.target && event.target.closest && event.target.closest('select, textarea, option');
    if (target) suspendScannerForUiPicker();
  }, { capture: true, passive: true });
  document.addEventListener('focusin', function (event) {
    if (isScannerUiControl(event.target)) suspendScannerForUiPicker();
  }, true);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      if (scannerMediaPickerOpen) resumeScannerAfterMediaPicker(1200);
      else if (shouldKeepScanInputFocus()) setTimeout(focusActiveScanInput, 60);
    }
  });
  // Restoring a page must preserve the in-progress product, quantity and location.
  window.addEventListener('pageshow', function () { if (!scannerMediaPickerOpen && shouldKeepScanInputFocus()) setTimeout(focusActiveScanInput, 60); });
  document.addEventListener('input', function (event) {
    if (event.target && event.target.matches('[data-qty]')) refreshStocktakeCompare();
  });
  document.addEventListener('paste', function (event) {
    clipboardImageData(event).then(function (dataUrl) {
      if (!dataUrl) return;
      var stockinPhotoBox = event.target && event.target.closest ? event.target.closest('[data-stockin-photo-box]') : null;
      var stocktakePhotoBox = event.target && event.target.closest ? event.target.closest('[data-stocktake-photo-box]') : null;
      if (pendingStockinLinePhotoIndex >= 0) {
        event.preventDefault();
        setStockinLinePhoto(pendingStockinLinePhotoIndex, dataUrl, '剪貼簿照片');
        pendingStockinLinePhotoIndex = -1;
        return;
      }
      if (currentTab === 'stockin' && dataUrl) {
        event.preventDefault();
        setStockinBatchPhoto(dataUrl, '剪貼簿照片');
        return;
      }
      if (currentTab === 'stocktake' && dataUrl) {
        event.preventDefault();
        setStocktakePhoto(dataUrl, '剪貼簿照片');
        if (selected) selected.image = dataUrl;
        renderStocktakeFastRows();
        return;
      }
      if (!pendingPanel || pendingPanel.hidden) return;
      event.preventDefault();
      setPendingPastedPhoto(dataUrl, '剪貼簿照片');
    }).catch(function (error) { setMessage(error.message || '貼上照片失敗', 'error'); });
  });
  document.addEventListener('click', function (event) {
    var target = event.target.closest && event.target.closest('button');
    if (!target) return;
    if (target.matches('[data-live-camera-photo]')) {
      openLivePhotoCamera(target.getAttribute('data-live-camera-photo') || 'current');
      return;
    }
    var pasteBox = event.target.closest && event.target.closest('[data-pending-photo-paste]');
    if (pasteBox) { pasteBox.focus(); setMessage('已選取貼上區，請按 Ctrl+V 貼上照片。', 'ok'); }
    var stockinPhotoBox = event.target.closest && event.target.closest('[data-stockin-photo-box]');
    if (stockinPhotoBox) { stockinPhotoBox.focus(); setMessage('已選取快速入庫照片區，請按「開啟相機拍照」或 Ctrl+V；放圖會先縮小。', 'ok'); }
    var stocktakePhotoBox = event.target.closest && event.target.closest('[data-stocktake-photo-box]');
    if (stocktakePhotoBox) { stocktakePhotoBox.focus(); setMessage('已選取盤點照片區；盤點機請按「開啟相機拍照」。', 'ok'); }
    if (target.matches('[data-live-camera-close]')) {
      closeLivePhotoCamera();
      return;
    }
    if (target.matches('[data-stocktake-photo-clear]')) clearStocktakePhoto('本筆盤點照片已清除。');
    if (target.matches('[data-stocktake-photo-toggle]')) {
      var stocktakePhotoPanel = document.querySelector('[data-stocktake-photo-box]');
      if (stocktakePhotoPanel) {
        stocktakePhotoPanel.hidden = !stocktakePhotoPanel.hidden;
        target.setAttribute('aria-expanded', stocktakePhotoPanel.hidden ? 'false' : 'true');
        target.textContent = stocktakePhotoPanel.hidden ? '＋ 展開照片功能' : '－ 收合照片功能';
      }
    }
    if (target.matches('[data-stocktake-photo-paste]')) pastePhotoFromClipboard('stocktake');
    if (target.matches('[data-stockin-photo-paste]')) pastePhotoFromClipboard('stockin');
    if (target.matches('[data-pending-photo-paste-button]')) pastePhotoFromClipboard('pending');
    if (target.matches('[data-fast-change-variant]')) { openStocktakeVariantEditor(); return; }
    if (target.matches('[data-cancel-current-stocktake]')) {
      if (confirm('清除目前尚未完成的這個產品？')) { resetCurrentStocktakeSelection(); setMessage('目前產品已清除，尚未寫入盤點紀錄。','ok'); }
      return;
    }
    if (target.matches('[data-remove-stocktake-entry]')) {
      if (!confirm('確定刪除這筆盤點商品？重新整理後也不會再出現。')) return;
      target.disabled = true;
      removeStocktakeEntry(Number(target.getAttribute('data-remove-stocktake-entry'))).catch(function (error) { target.disabled = false; setMessage(error.message || '刪除盤點商品失敗','error'); });
      return;
    }
    if (target.matches('[data-clear-current-stocktake]')) {
      if (!confirm('確定清除本次盤點中的全部商品？此動作只允許盤點草稿，無法復原。')) return;
      target.disabled = true;
      clearCurrentStocktake().catch(function (error) { target.disabled = false; setMessage(error.message || '清除本次盤點失敗','error'); });
      return;
    }
    if (target.matches('[data-photo-role]')) {
      var roleGroup = target.closest('[data-photo-role-group]');
      var roleBox = roleGroup && (roleGroup.closest('[data-stocktake-photo-box], [data-stockin-photo-box]') || roleGroup);
      if (roleBox) roleBox._photoRole = target.getAttribute('data-photo-role') || 'color';
      if (roleGroup) roleGroup.querySelectorAll('[data-photo-role]').forEach(function (button) { button.classList.toggle('is-active', button === target); });
      setMessage('這張照片會設為「' + (target.getAttribute('data-photo-role') === 'main' ? '商品主圖' : '顏色圖') + '」。', 'ok');
    }
  });
  document.addEventListener('click', function (event) {
    var target = event.target.closest && event.target.closest('button');
    if (!target) return;
    if (target.matches('[data-delete-selected-old-sessions]')) {
      var selectedSessionBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-select-old-session]:checked'));
      var selectedSessions = selectedSessionBoxes.map(function (box) {
        return ownSessionRows[Number(box.getAttribute('data-select-old-session'))];
      }).filter(Boolean);
      if (!selectedSessions.length) { updateOldSessionBulkSelection(); return; }
      var selectedIds = selectedSessions.map(function (item) { return item.id; });
      var selectedSkuCount = selectedSessions.reduce(function (sum, item) { return sum + (item.lines || []).length; }, 0);
      if (!confirm('確定刪除已選的 ' + selectedSessions.length + ' 張盤點單（共 ' + selectedSkuCount + ' 個 SKU）？\n這些盤點單會封存並保留可復原備份，不會更動正式庫存。')) return;
      target.disabled = true;
      post({action:'stocktake_delete_many',sessionIds:selectedIds}).then(function (result) {
        loadOwnSessions();
        setMessage('已從清單移除 ' + Number(result.deletedCount || selectedIds.length) + ' 張盤點單；保留可復原備份，正式庫存未變更。','ok');
      }).catch(function (error) {
        target.disabled = false;
        setMessage(error.message || '多選刪除失敗','error');
      });
      return;
    }
    if (target.matches('[data-own-stocktake-select-all]')) {
      var selectAllSessionIndex = Number(target.getAttribute('data-own-stocktake-select-all'));
      var selectAllBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-own-stocktake-select^="' + selectAllSessionIndex + ':"]'));
      var shouldSelect = selectAllBoxes.some(function (box) { return !box.checked; });
      selectAllBoxes.forEach(function (box) { box.checked = shouldSelect; });
      updateOwnStocktakeSelection(selectAllSessionIndex);
      return;
    }
    if (target.matches('[data-own-stocktake-save-qty]')) {
      var qtyParts = String(target.getAttribute('data-own-stocktake-save-qty') || '').split(':');
      var qtySession = ownSessionRows[Number(qtyParts[0])];
      var qtyLine = qtySession && (qtySession.lines || [])[Number(qtyParts[1])];
      var qtyInput = document.querySelector('[data-own-stocktake-qty="' + qtyParts[0] + ':' + qtyParts[1] + '"]');
      var nextQty = Math.floor(Number(qtyInput && qtyInput.value));
      if (!qtySession || !qtyLine) return;
      if (!isFinite(nextQty) || nextQty < 0) { setMessage('數量必須是 0 以上的整數。', 'error'); return; }
      target.disabled = true;
      post({
        action: 'stocktake_edit_draft',
        sessionId: qtySession.id,
        lineIndex: Number(qtyParts[1]),
        sourceSkuId: qtyLine.sourceSkuId || '',
        countedQty: nextQty,
        note: '盤點單改數量',
        operator: operator
      }).then(function () {
        loadOwnSessions();
        setMessage((qtyLine.barcode || qtyLine.sourceSkuId || '這筆') + ' 已改成 ' + nextQty + ' 件；尚未改正式庫存，核准後才帶入。', 'ok');
      }).catch(function (error) {
        target.disabled = false;
        setMessage(error.message || '改數量失敗', 'error');
      });
      return;
    }
    if (target.matches('[data-own-stocktake-remove-selected]')) {
      var batchSessionIndex = Number(target.getAttribute('data-own-stocktake-remove-selected'));
      var batchSession = ownSessionRows[batchSessionIndex];
      var checkedBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-own-stocktake-select^="' + batchSessionIndex + ':"]:checked'));
      var selectedLines = checkedBoxes.map(function (box) {
        var parts = String(box.getAttribute('data-own-stocktake-select') || '').split(':');
        return batchSession && (batchSession.lines || [])[Number(parts[1])];
      }).filter(Boolean);
      if (!batchSession || !selectedLines.length) { updateOwnStocktakeSelection(batchSessionIndex); return; }
      if (!confirm('確定刪除已選的 ' + selectedLines.length + ' 筆盤點商品？\n只會刪除這張草稿內容，不會更動正式庫存。')) return;
      var selectedEntries = selectedLines.map(function (line) { return ownStocktakeEntryPayload(line, batchSession); });
      target.disabled = true;
      post({action:'stocktake_remove_entries',sessionId:batchSession.id,entries:selectedEntries,operator:operator}).then(function (result) {
        if (session && session.id === batchSession.id) draft = draft.filter(function (line) { return !selectedEntries.some(function (entry) { return draftLineMatchesOwnEntry(line, entry); }); });
        saveDraft();renderStocktakeFastRows();loadOwnSessions();setMessage('已從 NAS 草稿刪除 ' + Number(result.removedCount || selectedLines.length) + ' 筆商品；正式庫存沒有變更。','ok');
      }).catch(function (error) { target.disabled=false;setMessage(error.message || '多選刪除失敗','error'); });
      return;
    }
    if (target.matches('[data-own-stocktake-remove]')) {
      var removeParts = String(target.getAttribute('data-own-stocktake-remove') || '').split(':');
      var removeSession = ownSessionRows[Number(removeParts[0])];
      var removeLine = removeSession && (removeSession.lines || [])[Number(removeParts[1])];
      if (!removeSession || !removeLine || !confirm('確定從盤點單刪除 ' + (removeLine.barcode || removeLine.sourceSkuId || '這個商品') + '？\n只會刪除草稿，不會更動正式庫存。')) return;
      target.disabled = true;
      var removeEntry = ownStocktakeEntryPayload(removeLine, removeSession);
      post({action:'stocktake_remove_entry',sessionId:removeSession.id,skuId:removeEntry.skuId,barcode:removeEntry.barcode,warehouse:removeEntry.warehouse,shelf:removeEntry.shelf,layer:removeEntry.layer,operator:operator}).then(function () {
        if (session && session.id === removeSession.id) draft = draft.filter(function (line) { return !draftLineMatchesOwnEntry(line, removeEntry); });
        saveDraft();renderStocktakeFastRows();loadOwnSessions();setMessage('這筆盤點商品已從 NAS 草稿刪除；正式庫存沒有變更。','ok');
      }).catch(function (error) { target.disabled=false;setMessage(error.message || '刪除失敗','error'); });
      return;
    }
  });
  document.addEventListener('change', function (event) {
    if (event.target && event.target.matches && event.target.matches('[data-select-all-old-sessions]')) {
      var shouldSelectSessions = event.target.checked;
      document.querySelectorAll('[data-select-old-session]').forEach(function (box) { box.checked = shouldSelectSessions; });
      updateOldSessionBulkSelection();
      return;
    }
    if (event.target && event.target.matches && event.target.matches('[data-select-old-session]')) {
      updateOldSessionBulkSelection();
      return;
    }
    if (!event.target || !event.target.matches || !event.target.matches('[data-own-stocktake-select]')) return;
    var parts = String(event.target.getAttribute('data-own-stocktake-select') || '').split(':');
    updateOwnStocktakeSelection(Number(parts[0]));
  });
  document.addEventListener('change', function (event) {
    if (event.target && event.target.matches && event.target.matches('input[type="file"]')) resumeScannerAfterMediaPicker(1000);
    var fastStocktakePhoto = event.target.closest && event.target.closest('[data-fast-stocktake-photo]');
    if (fastStocktakePhoto) {
      var fastPhotoFile = fastStocktakePhoto.files && fastStocktakePhoto.files[0];
      if (!fastPhotoFile) return;
      scannerImageFileToDataUrl(fastPhotoFile).then(function (dataUrl) {
        fastStocktakePhoto.value = '';
        setStocktakePhoto(dataUrl, '更換照片');
        if (selected) selected.image = dataUrl;
        renderStocktakeFastRows();
        setMessage('照片已換成目前實品；會作為「' + ((selected && selected.color) || '目前顏色') + '」顏色圖送主管核准。', 'ok');
      }).catch(function (error) {
        fastStocktakePhoto.value = '';
        setMessage(error.message || '照片讀取失敗，請重新拍照。', 'error');
      });
      return;
    }
    if (event.target.matches('[data-stockin-shelf], [data-pending-shelf], [data-match-shelf]')) syncNoSpaceLocations();
    if (event.target.matches('[data-stockin-shelf], [data-stockin-layer]')) {
      var filled = applyStockinDefaultLocation({ emptyOnly: true, skipRender: true, allowEmpty: true, quietIfEmpty: true });
      var label = event.target.matches('[data-stockin-shelf]') ? '貨架' : '層位';
      var value = String(event.target.value || '').trim();
      setMessage(value
        ? ('本批預設' + label + '已改為「' + value + '」' + (filled ? '，並已補上 ' + filled + ' 筆尚未指定的品項' : '') + '。已有位置的品項不變，要整批改請按「同倉架套用本批」。')
        : ('已取消本批預設' + label + '；後續掃描需再選。'), 'ok');
      resumeScannerAfterUiPicker(1200);
      return;
    }
    if (event.target.matches('[data-stockin-default-category]')) {
      try { localStorage.setItem(stockinRecentCategoryKey, event.target.value); } catch (error) {}
      setMessage('後續掃描固定使用「' + (event.target.value || '沿用商品分類') + '」，直到您切換；已加入的紀錄不變。', 'ok');
      return;
    }
    var target = event.target.closest && event.target.closest('button');
    if (!target) target = { matches: function () { return false; } };
    var pendingCameraPhoto = event.target.closest('[data-pending-camera-photo]');
    if (pendingCameraPhoto) {
      var pendingCameraFile = pendingCameraPhoto.files && pendingCameraPhoto.files[0];
      if (!pendingCameraFile) return;
      scannerImageFileToDataUrl(pendingCameraFile).then(function (dataUrl) {
        pendingCameraPhoto.value = '';
        setPendingPastedPhoto(dataUrl, '手機拍照');
        savePending({autoFromPhoto:true});
      }).catch(function (error) {
        pendingCameraPhoto.value = '';
        setMessage(error.message || '臨時建檔照片讀取失敗', 'error');
      });
      return;
    }
    if (target.matches('[data-cancel-current-stocktake]')) {
      if (confirm('清除目前尚未完成的這個產品？')) {
        resetCurrentStocktakeSelection();
        setMessage('目前產品已清除，尚未寫入盤點紀錄。','ok');
      }
      return;
    }
    if (target.matches('[data-remove-stocktake-entry]')) {
      if (!confirm('確定刪除這筆盤點商品？重新整理後也不會再出現。')) return;
      target.disabled = true;
      removeStocktakeEntry(Number(target.getAttribute('data-remove-stocktake-entry'))).catch(function (error) {
        target.disabled = false;
        setMessage(error.message || '刪除盤點商品失敗','error');
      });
      return;
    }
    if (target.matches('[data-clear-current-stocktake]')) {
      if (!confirm('確定清除本次盤點中的全部商品？此動作只允許盤點草稿，無法復原。')) return;
      target.disabled = true;
      clearCurrentStocktake().catch(function (error) {
        target.disabled = false;
        setMessage(error.message || '清除本次盤點失敗','error');
      });
      return;
    }
    if (target.matches('[data-own-stocktake-remove]')) {
      var removeParts = String(target.getAttribute('data-own-stocktake-remove') || '').split(':');
      var removeSession = ownSessionRows[Number(removeParts[0])];
      var removeLine = removeSession && (removeSession.lines || [])[Number(removeParts[1])];
      if (!removeSession || !removeLine || !confirm('確定從盤點單刪除 ' + (removeLine.barcode || removeLine.sourceSkuId || '這個商品') + '？')) return;
      target.disabled = true;
      post({action:'stocktake_remove_entry',sessionId:removeSession.id,skuId:removeLine.sourceSkuId || '',barcode:removeLine.barcode || '',warehouse:removeLine.warehouse || removeSession.warehouse || '',shelf:removeLine.shelf || '',layer:removeLine.layer || '',operator:operator}).then(function () {
        if (session && session.id === removeSession.id) draft = draft.filter(function (line) { return !((line.skuId || '') === (removeLine.sourceSkuId || '') && (line.shelf || '') === (removeLine.shelf || '') && (line.layer || '') === (removeLine.layer || '')); });
        saveDraft();renderStocktakeFastRows();loadOwnSessions();setMessage('這筆盤點商品已從 NAS 草稿刪除。','ok');
      }).catch(function (error) { target.disabled=false;setMessage(error.message || '刪除失敗','error'); });
      return;
    }
    if (target.matches('[data-own-stocktake-clear]')) {
      var clearSession = ownSessionRows[Number(target.getAttribute('data-own-stocktake-clear'))];
      if (!clearSession || !confirm('確定清除盤點單 ' + clearSession.id + ' 的全部商品？')) return;
      target.disabled = true;
      post({action:'stocktake_clear_entries',sessionId:clearSession.id,operator:operator}).then(function () {
        if (session && session.id === clearSession.id) { draft = draft.filter(function (line) { return line.action !== 'stocktake'; });resetCurrentStocktakeSelection();saveDraft(); }
        loadOwnSessions();setMessage('這張盤點單的商品已全部從 NAS 草稿清除。','ok');
      }).catch(function (error) { target.disabled=false;setMessage(error.message || '清除失敗','error'); });
      return;
    }
    var stocktakeCameraPhoto = event.target.closest('[data-stocktake-camera-photo]');
    if (stocktakeCameraPhoto) {
      var stocktakeCameraFile = stocktakeCameraPhoto.files && stocktakeCameraPhoto.files[0];
      if (!stocktakeCameraFile) return;
      scannerImageFileToDataUrl(stocktakeCameraFile).then(function (dataUrl) {
        stocktakeCameraPhoto.value = '';
        setStocktakePhoto(dataUrl, '手機拍照');
      }).catch(function (error) {
        stocktakeCameraPhoto.value = '';
        setMessage(error.message || '手機照片讀取失敗', 'error');
      });
      return;
    }
    var stockinCameraPhoto = event.target.closest('[data-stockin-camera-photo]');
    if (stockinCameraPhoto) {
      var stockinCameraFile = stockinCameraPhoto.files && stockinCameraPhoto.files[0];
      if (!stockinCameraFile) return;
      scannerImageFileToDataUrl(stockinCameraFile).then(function (dataUrl) {
        stockinCameraPhoto.value = '';
        setStockinBatchPhoto(dataUrl, '手機拍照');
      }).catch(function (error) {
        stockinCameraPhoto.value = '';
        setMessage(error.message || '快速入庫手機照片讀取失敗', 'error');
      });
      return;
    }
    var stocktakePhoto = event.target.closest('[data-stocktake-photo]');
    if (stocktakePhoto) {
      var stocktakeFile = stocktakePhoto.files && stocktakePhoto.files[0];
      if (!stocktakeFile) return;
      scannerImageFileToDataUrl(stocktakeFile).then(function (dataUrl) {
        stocktakePhoto.value = '';
        setStocktakePhoto(dataUrl, '拍照／選取照片');
      }).catch(function (error) {
        stocktakePhoto.value = '';
        setMessage(error.message || '盤點照片讀取失敗', 'error');
      });
      return;
    }
    var stockinPhoto = event.target.closest('[data-stockin-photo]');
    if (stockinPhoto) {
      var batchFile = stockinPhoto.files && stockinPhoto.files[0];
      if (!batchFile) return;
      scannerImageFileToDataUrl(batchFile).then(function (dataUrl) {
        stockinPhoto.value = '';
        setStockinBatchPhoto(dataUrl, '選取照片');
      }).catch(function (error) {
        stockinPhoto.value = '';
        setMessage(error.message || '快速入庫照片讀取失敗', 'error');
      });
      return;
    }
    var stockinLinePhoto = event.target.closest('[data-stockin-line-photo]');
    if (stockinLinePhoto) {
      var lineIndex = Number(stockinLinePhoto.getAttribute('data-stockin-line-photo'));
      var lineFile = stockinLinePhoto.files && stockinLinePhoto.files[0];
      if (!lineFile) return;
      scannerImageFileToDataUrl(lineFile).then(function (dataUrl) {
        stockinLinePhoto.value = '';
        setStockinLinePhoto(lineIndex, dataUrl, '選取照片');
      }).catch(function (error) {
        stockinLinePhoto.value = '';
        setMessage(error.message || '此列照片讀取失敗', 'error');
      });
      return;
    }
    var pendingPhoto = event.target.closest('[data-pending-photo]');
    if (pendingPhoto) {
      if (pendingPanel) pendingPanel._pastedPhotoData = '';
      var pendingPreview = document.querySelector('[data-pending-photo-preview]');
      if (pendingPreview) pendingPreview.hidden = true;
      if (!pendingPanel.hidden && pendingPhoto.files && pendingPhoto.files[0]) savePending({ autoFromPhoto: true });
      return;
    }
      var qtyInput = event.target.closest('[data-transfer-line-qty]');
    if (qtyInput) {
      var index = Number(qtyInput.dataset.transferLineQty);
      var line = transferDraft[index];
      if (!line) return;
      var nextQty = Math.max(1, Number(qtyInput.value || 1));
      if (nextQty > Number(line.availableStock || 0)) {
        beep(2);
        nextQty = Number(line.availableStock || 1);
        setMessage('兩聲：調撥數量不能超過來源庫存，已調整為 ' + nextQty + ' 件。', 'error');
      }
      line.qty = nextQty;
      saveTransferDraft();
      return;
    }
    var transferCategory = event.target.closest('[data-transfer-line-category]');
    if (transferCategory) {
      var transferCategoryIndex = Number(transferCategory.dataset.transferLineCategory);
      if (!transferDraft[transferCategoryIndex]) return;
      transferDraft[transferCategoryIndex].computerCategory = String(transferCategory.value || '').trim();
      saveTransferDraft();
      return;
    }
    var stockinField = event.target.closest('[data-stockin-line-category],[data-stockin-line-color],[data-stockin-line-size],[data-stockin-line-warehouse],[data-stockin-line-shelf],[data-stockin-line-layer],[data-stockin-line-qty],[data-stockin-line-cost],[data-stockin-line-action]');
    if (!stockinField) return;
    var stockinIndex = Number(stockinField.getAttribute('data-stockin-line-category') || stockinField.getAttribute('data-stockin-line-color') || stockinField.getAttribute('data-stockin-line-size') || stockinField.getAttribute('data-stockin-line-warehouse') || stockinField.getAttribute('data-stockin-line-shelf') || stockinField.getAttribute('data-stockin-line-layer') || stockinField.getAttribute('data-stockin-line-qty') || stockinField.getAttribute('data-stockin-line-cost') || stockinField.getAttribute('data-stockin-line-action'));
    var stockinLine = stockinDraft[stockinIndex];
    if (!stockinLine) return;
    if (stockinField.hasAttribute('data-stockin-line-action')) {
      var actionValue = String(stockinField.value || 'review');
      if (actionValue.indexOf('transfer:') === 0) {
        var selectedSourceSkuId = actionValue.slice(9);
        var sourceOption = (stockinLine.otherWarehouseOptions || []).find(function (item) { return String(item.skuId || '') === selectedSourceSkuId; });
        stockinLine.inventoryAction = 'transfer';
        stockinLine.sourceSkuId = selectedSourceSkuId;
        stockinLine.sourceWarehouse = sourceOption ? sourceOption.warehouse : '';
        stockinLine.sourceWarehouseStock = sourceOption ? Number(sourceOption.stock || 0) : 0;
      } else {
        stockinLine.inventoryAction = actionValue === 'stockin' ? 'stockin' : 'review';
        stockinLine.sourceSkuId = ''; stockinLine.sourceWarehouse = ''; stockinLine.sourceWarehouseStock = 0;
      }
    } else if (stockinField.hasAttribute('data-stockin-line-category')) {
      stockinLine.category = String(stockinField.value || '').trim() || '快速入庫待補';
      if (stockinLine.category !== '快速入庫待補') try { localStorage.setItem(stockinRecentCategoryKey, stockinLine.category); } catch (error) {}
    } else if (stockinField.hasAttribute('data-stockin-line-color')) stockinLine.color = String(stockinField.value || '').trim() || '待補顏色';
    else if (stockinField.hasAttribute('data-stockin-line-size')) stockinLine.size = stockinCanonicalSize(stockinField.value);
    else if (stockinField.hasAttribute('data-stockin-line-warehouse')) stockinLine.warehouse = String(stockinField.value || '').trim() || stockinLine.warehouse;
    else if (stockinField.hasAttribute('data-stockin-line-shelf')) stockinLine.shelf = String(stockinField.value || '').trim();
    else if (stockinField.hasAttribute('data-stockin-line-layer')) stockinLine.layer = String(stockinField.value || '').trim();
    else if (stockinField.hasAttribute('data-stockin-line-qty')) stockinLine.qty = Math.max(1, Number(stockinField.value || 1));
    else stockinLine.unitCostTwd = Math.max(0, Number(stockinField.value || 0));
    stockinLine.key = stockinLineKey(stockinLine);
    var keepNativeSelect = stockinField.hasAttribute('data-stockin-line-warehouse') || stockinField.hasAttribute('data-stockin-line-shelf') || stockinField.hasAttribute('data-stockin-line-layer') || stockinField.hasAttribute('data-stockin-line-qty') || stockinField.hasAttribute('data-stockin-line-cost');
    persistStockinDraft({ skipRender: keepNativeSelect });
    resumeScannerAfterUiPicker(800);
  });
  document.addEventListener('keydown', captureHardwareScan, true);
  document.addEventListener('keyup', captureHardwareKeyup, true);
  document.addEventListener('beforeinput', captureHardwareCommittedText, true);
  document.addEventListener('textInput', captureHardwareCommittedText, true);
  window.addEventListener('focus', function () {
    if (!shouldKeepScanInputFocus()) return;
    if (scanInputMode === 'hardware' || scanInputMode === 'ccd') focusActiveScanInput();
  });
  window.addEventListener('beforeunload', function (event) {
    if (!stocktakeAutoSubmitting && !Object.keys(lookupInFlight).length) return;
    event.preventDefault();
    event.returnValue = '';
  });
  warehouse.addEventListener('change', function () {
    if (currentTab === 'stockin') {
      var filled = applyStockinDefaultLocation({ emptyOnly: true, skipRender: true, allowEmpty: true, quietIfEmpty: true, forceWarehouse: false });
      setMessage('後續掃描入庫倉改為「' + warehouse.value + '」' + (filled ? '，並已補上 ' + filled + ' 筆尚未指定倉架的品項' : '') + '。已加入且已有位置的品項不變，要整批改倉請按「同倉架套用本批」。', 'ok');
      resumeScannerAfterUiPicker(1200);
      return;
    }
    if (session && session.status !== 'submitted' && session.warehouse !== warehouse.value && draft.some(function (x) { return x.action === 'stocktake'; })) {
      if (!confirm('切換盤點倉庫會建立新的盤點單，確定？')) {
        warehouse.value = session.warehouse;
        return;
      }
      session = null;
    }
    ensureSession();
    session.warehouse = warehouse.value;
    stocktakeSharedLocation = {warehouse:'',shelf:'',layer:''};
    session.stocktakeActiveLocation = stocktakeSharedLocation;
    localStorage.setItem(sessionKey, JSON.stringify(session));
    resumeScannerAfterUiPicker(800);
  });
  lookupWarehouse.addEventListener('change', function () { if (allMatches.length) renderMatches(allMatches); });
  sourceWarehouse.addEventListener('change', function () { var code = activeScanCode(); if (code) lookup(code); });
  sourceWarehouse.addEventListener('change', function () {
    if (transferMode() === 'clothing' && targetWarehouse.value === sourceWarehouse.value && targetWarehouse.options.length > 1) {
      targetWarehouse.selectedIndex = (sourceWarehouse.selectedIndex + 1) % targetWarehouse.options.length;
    }
  });
  if (transferDepartmentMode) transferDepartmentMode.addEventListener('change', function () {
    var nextMode = transferMode();
    if (transferDraft.length && transferDraft.some(function (line) { return String(line.transferMode || 'clothing') !== nextMode; })) {
      if (!confirm('切換調撥部門會清空目前尚未儲存的調撥草稿，確定要切換？')) {
        transferDepartmentMode.value = nextMode === 'computer' ? 'clothing' : 'computer';
        return;
      }
      transferDraft = [];
      saveTransferDraft();
    }
    applyTransferMode();
    setMessage(nextMode === 'computer' ? '已切換「服裝部 → 電腦部」：掃描後只需替每項選寶輝產品分類，顏色與尺寸會維持。' : '已切回「服裝部 → 服裝部」一般倉庫調撥。', 'ok');
  });
  document.querySelectorAll('[data-scan-input-mode]').forEach(function (button) {
    button.addEventListener('click', function () {
      var nextMode = button.getAttribute('data-scan-input-mode') || 'hardware';
      if (nextMode === 'camera') {
        openCamera();
        return;
      }
      closeCamera();
      setInputMode(nextMode);
    });
  });
  setInputMode(scanInputMode);
  document.body.setAttribute('data-current-scanner-tab', currentTab);
  updateCurrentTabLabel(currentTab);
  setTabsCollapsed(true);
  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    installPrompt = event;
    var installButton = document.querySelector('[data-install-app]');
    if (installButton) {
      installButton.disabled = false;
      installButton.textContent = '安裝 LINGZANZAN';
    }
    if (/[?&]install=1(?:&|$)/.test(location.search)) setMessage('安裝已準備好，請按上方「安裝 LINGZANZAN」。', 'ok');
  });
  window.addEventListener('appinstalled', function () {
    var installButton = document.querySelector('[data-install-app]');
    if (installButton) installButton.hidden = true;
    setMessage('盤點機 APP 已安裝，可從桌面直接開啟。', 'ok');
  });
  document.querySelectorAll('[data-scanner-back]').forEach(function (scannerBack) {
    scannerBack.addEventListener('click', function () {
      if (history.length > 1) history.back(); else location.href = './admin-inventory.html';
    });
  });
  // LZ_SCANNER_APK_ERR_FAILED_20260924
  var scannerApkMode = /(?:^|[?&])apk=1(?:&|$)/.test(location.search || '');
  if ('serviceWorker' in navigator) {
    if (scannerApkMode) {
      navigator.serviceWorker.getRegistrations().then(function (regs) {
        regs.forEach(function (reg) { reg.unregister(); });
      }).catch(function () {});
    } else {
      navigator.serviceWorker.register('./lingzanzan-app-sw.js?v=20260925-barcode-integrity-v152', { scope: './', updateViaCache: 'none' }).then(function (registration) {
        registration.update().catch(function () {});
      }).catch(function () {});
    }
  }

  bindScannerLogin();
  applyInitialStockCopy();
  ['[data-stockin-new-shelf]','[data-stockin-new-layer]'].forEach(function (selector) {
    var field = document.querySelector(selector);
    if (field && field.closest('label')) field.closest('label').remove();
  });
  var addLocationButton = document.querySelector('[data-stockin-add-location]');
  if (addLocationButton) addLocationButton.remove();
  renderSession();
  applyPermissionUi();
  initStockinSelectControls();
  loadWarehouses();
  renderDraft();
  renderTransferDraft();
  renderStockinDraft();
  loadOwnSessions();
  loadLatestMissingStocktakeScan();
  setTimeout(function () { input.focus(); }, 250);
}());

(function loadLingzanzanImageVariants() {
  if (document.querySelector('script[data-lingzanzan-image-variants]')) return;
  var script = document.createElement('script');
  script.src = './assets/image-variants.js?v=20260805-thumb-on-list-3';
  script.defer = true;
  script.setAttribute('data-lingzanzan-image-variants', '');
  document.head.appendChild(script);
}());
