#!/usr/bin/env python3
"""Force 加入本次盤點 to write the stocktake line even if tab flag is wrong."""
from pathlib import Path

IN = Path("/workspace/lingzanzan-inbound")
js = (IN / "scanner-ygf-v59.js.new-ygf-tap-1").read_text(encoding="utf-8")
css = (IN / "scanner-ygf-v59.css.new-ygf-tap-1").read_text(encoding="utf-8")
html = (IN / "scanner.html.new-ygf-tap-1").read_text(encoding="utf-8")
MARKER = "LZ_YGF_TAP2_20260927"
TAG = "20260927-ygf-tap-2"


def once(src, old, new, label):
    if old not in src:
        raise SystemExit(f"failed to bind {label}")
    if src.count(old) != 1:
        raise SystemExit(f"ambiguous {label} count={src.count(old)}")
    return src.replace(old, new, 1)


if MARKER not in js:
    js = once(
        js,
        "  /* LZ_YGF_TAP_20260927: 卡片與底部碼頭都能加入本次盤點；文字節點點擊也算。 */",
        "  /* LZ_YGF_TAP_20260927: 卡片與底部碼頭都能加入本次盤點；文字節點點擊也算。 */\n"
        "  /* LZ_YGF_TAP2_20260927: 盤點畫面按加入／確定帶入立刻寫入，不看錯分頁。 */",
        "js marker",
    )

old_confirm = """  function confirmMatchPick(row, index) {
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    if (currentTab === 'stocktake') {
      currentStocktakeScanCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
      stocktakeLiveCounts[currentStocktakeScanCode] = Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 1));
      choose(row, index, true, {skipLocation: true, keepPicker: true});
      if (input) input.blur();
      if (manualInput) manualInput.blur();
      if (canReuseStocktakeSharedLocation()) {
        row.shelf = stocktakeSharedLocation.shelf;
        row.layer = stocktakeSharedLocation.layer;
      }
      stocktakeFlowLock = {
        barcode: currentStocktakeScanCode,
        skuId: String(row.skuId || ''),
        shelf: String(row.shelf || (stocktakeSharedLocation && stocktakeSharedLocation.shelf) || ''),
        layer: String(row.layer || (stocktakeSharedLocation && stocktakeSharedLocation.layer) || '')
      };
      var broughtQty = document.querySelector('[data-qty]');
      var broughtCount = Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 1));
      if (broughtQty) broughtQty.value = String(broughtCount);
      var locEditor = document.querySelector('[data-match-first-location]');
      if (locEditor) locEditor.hidden = true;
      stocktakeFlowStatus('正在加入本次盤點 ' + (row.productCode || '') + '／' + (row.color || '') + '／' + (row.size || 'NO SIZE') + '，數量 ' + broughtCount + '。');
      submit('stocktake');
      return;
    }
    choose(row, index, true);
    var matchedCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
    if (currentTab === 'transfer') queueTransfer(row, matchedCode);
    if (currentTab === 'arrival') receiveCrossWarehouse(row, matchedCode);
    if (currentTab === 'stockin') queueStockinRow(row, matchedCode);
  }"""

new_confirm = """  function isStocktakeUi() {
    var tab = String(currentTab || (document.body && document.body.getAttribute('data-current-scanner-tab')) || '');
    if (tab === 'stocktake') return true;
    var fields = document.querySelector('[data-stocktake-fields]');
    if (fields && !fields.hidden) return true;
    var compare = document.querySelector('.stock-compare');
    if (compare) {
      try {
        var cs = window.getComputedStyle(compare);
        if (cs && cs.display !== 'none' && cs.visibility !== 'hidden' && compare.offsetHeight > 0) return true;
      } catch (error) {}
    }
    return false;
  }
  function hideMatchPickerNow() {
    var locEditor = document.querySelector('[data-match-first-location]');
    if (locEditor) locEditor.hidden = true;
    if (matchPanel) matchPanel.hidden = true;
    if (matchGrid) matchGrid.hidden = true;
    var dock = document.querySelector('[data-match-action-dock]');
    if (dock) dock.hidden = true;
    document.body.classList.remove('is-match-picker-open');
  }
  function confirmMatchPick(row, index) {
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    if (isStocktakeUi() || currentTab === 'stocktake') {
      if (operator && operator.role === 'unknown') {
        setMessage('請先登入盤點機帳號，再加入本次盤點。', 'error');
        return;
      }
      currentTab = 'stocktake';
      document.body.setAttribute('data-current-scanner-tab', 'stocktake');
      currentStocktakeScanCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
      stocktakeLiveCounts[currentStocktakeScanCode] = Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 1));
      choose(row, index, true, {skipLocation: true, keepPicker: true});
      if (input) input.blur();
      if (manualInput) manualInput.blur();
      if (canReuseStocktakeSharedLocation()) {
        row.shelf = stocktakeSharedLocation.shelf;
        row.layer = stocktakeSharedLocation.layer;
      }
      stocktakeFlowLock = {
        barcode: currentStocktakeScanCode,
        skuId: String(row.skuId || ''),
        shelf: String(row.shelf || (stocktakeSharedLocation && stocktakeSharedLocation.shelf) || ''),
        layer: String(row.layer || (stocktakeSharedLocation && stocktakeSharedLocation.layer) || '')
      };
      var broughtQty = document.querySelector('[data-qty]');
      var broughtCount = Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 1));
      if (broughtQty) broughtQty.value = String(broughtCount);
      hideMatchPickerNow();
      stocktakeFlowStatus('已加入本次盤點 ' + (row.productCode || '') + '／' + (row.color || '') + '／' + (row.size || 'NO SIZE') + '，數量 ' + broughtCount + '。');
      setMessage('已加入本次盤點 ' + broughtCount + ' 件。', 'ok');
      submit('stocktake');
      return;
    }
    choose(row, index, true);
    var matchedCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
    if (currentTab === 'transfer') queueTransfer(row, matchedCode);
    if (currentTab === 'arrival') receiveCrossWarehouse(row, matchedCode);
    if (currentTab === 'stockin') queueStockinRow(row, matchedCode);
  }"""
js = once(js, old_confirm, new_confirm, "confirmMatchPick stocktake ui")

old_dock = """    function dockPick(kind, event) {
      if (event && event.preventDefault) event.preventDefault();
      if (event && event.stopPropagation) event.stopPropagation();
      if (Date.now() - lastMatchPickAt < 450) return;
      lastMatchPickAt = Date.now();
      pickMatchByKind(kind);
    }
    dock.addEventListener('click', function (event) {
      var el = eventElement(event);
      if (!el) return;
      if (el.closest('[data-match-dock-confirm]')) dockPick('confirm', event);
      else if (el.closest('[data-match-dock-select]')) dockPick('select', event);
    });
    ['pointerup', 'touchend', 'touchstart'].forEach(function (type) {
      dock.addEventListener(type, function (event) {
        var el = eventElement(event);
        if (!el) return;
        if (el.closest('[data-match-dock-confirm]')) dockPick('confirm', event);
        else if (el.closest('[data-match-dock-select]')) dockPick('select', event);
      }, {capture: true, passive: false});
    });"""

new_dock = """    function dockPick(kind, event) {
      if (event && event.preventDefault) event.preventDefault();
      if (event && event.stopPropagation) event.stopPropagation();
      if (event && event.stopImmediatePropagation) event.stopImmediatePropagation();
      pickMatchByKind(kind === 'select' && (allMatches || []).length <= 1 ? 'confirm' : kind);
    }
    dock.addEventListener('click', function (event) {
      var el = eventElement(event);
      if (!el) return;
      if (el.closest('[data-match-dock-confirm]')) dockPick('confirm', event);
      else if (el.closest('[data-match-dock-select]')) dockPick('select', event);
    });
    ['pointerup', 'touchend'].forEach(function (type) {
      dock.addEventListener(type, function (event) {
        var el = eventElement(event);
        if (!el) return;
        if (el.closest('[data-match-dock-confirm]')) dockPick('confirm', event);
        else if (el.closest('[data-match-dock-select]')) dockPick('select', event);
      }, {capture: true, passive: false});
    });
    var dockConfirm = dock.querySelector('[data-match-dock-confirm]');
    var dockSelect = dock.querySelector('[data-match-dock-select]');
    if (dockConfirm) {
      dockConfirm.onclick = function (event) { dockPick('confirm', event); return false; };
      dockConfirm.ontouchend = dockConfirm.onclick;
    }
    if (dockSelect) {
      dockSelect.onclick = function (event) { dockPick('select', event); return false; };
      dockSelect.ontouchend = dockSelect.onclick;
    }"""
js = once(js, old_dock, new_dock, "dock no touchstart")

js = once(
    js,
    "    if (confirmBtn) confirmBtn.textContent = currentTab === 'stocktake' ? '加入本次盤點' : '確定帶入';\n    if (selectBtn) selectBtn.hidden = !((allMatches || []).length > 1);",
    "    if (confirmBtn) confirmBtn.textContent = isStocktakeUi() ? '加入本次盤點' : '加入本次盤點';\n    if (selectBtn) selectBtn.hidden = true;",
    "dock always 加入本次盤點",
)

# pickMatchByKind: single card select = confirm; debounce only after success
old_pick = """  window.__lzYgfPick = function (kind, index, sku, event) {
    if (event && event.preventDefault) event.preventDefault();
    if (event && event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 450) return false;
    lastMatchPickAt = Date.now();
    pickMatchByKind(kind, index, sku);
    return false;
  };"""
new_pick = """  window.__lzYgfPick = function (kind, index, sku, event) {
    if (event && event.preventDefault) event.preventDefault();
    if (event && event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 250) return false;
    var usedKind = kind === 'select' && (allMatches || []).length <= 1 ? 'confirm' : kind;
    var ok = pickMatchByKind(usedKind, index, sku);
    if (ok) lastMatchPickAt = Date.now();
    return false;
  };"""
js = once(js, old_pick, new_pick, "global pick debounce after success")

# handleMatchPickFromEvent dock/card debounce: set lastMatchPickAt only after pick
js = js.replace(
    """    if (el.closest('[data-match-dock-confirm]')) {
      if (event.preventDefault) event.preventDefault();
      if (event.stopPropagation) event.stopPropagation();
      if (Date.now() - lastMatchPickAt < 450) return true;
      lastMatchPickAt = Date.now();
      pickMatchByKind('confirm');
      return true;
    }
    if (el.closest('[data-match-dock-select]')) {
      if (event.preventDefault) event.preventDefault();
      if (event.stopPropagation) event.stopPropagation();
      if (Date.now() - lastMatchPickAt < 450) return true;
      lastMatchPickAt = Date.now();
      pickMatchByKind('select');
      return true;
    }""",
    """    if (el.closest('[data-match-dock-confirm]') || el.closest('[data-match-dock-select]')) {
      if (event.preventDefault) event.preventDefault();
      if (event.stopPropagation) event.stopPropagation();
      if (Date.now() - lastMatchPickAt < 250) return true;
      var dockKind = el.closest('[data-match-dock-confirm]') ? 'confirm' : 'select';
      if (dockKind === 'select' && (allMatches || []).length <= 1) dockKind = 'confirm';
      if (pickMatchByKind(dockKind)) lastMatchPickAt = Date.now();
      return true;
    }""",
)

js = once(
    js,
    """    if (event.preventDefault) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 450) return true;
    lastMatchPickAt = Date.now();
    var pickEl = confirmPick || selectPick || matchCard;
    var pickIndex = Number(pickEl.getAttribute(confirmPick ? 'data-match-confirm' : (selectPick ? 'data-match-select' : 'data-match-index')));
    var pickSku = String(pickEl.getAttribute('data-match-sku') || '');
    pickMatchByKind(confirmPick ? 'confirm' : 'select', pickIndex, pickSku);
    return true;""",
    """    if (event.preventDefault) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 250) return true;
    var pickEl = confirmPick || selectPick || matchCard;
    var pickIndex = Number(pickEl.getAttribute(confirmPick ? 'data-match-confirm' : (selectPick ? 'data-match-select' : 'data-match-index')));
    var pickSku = String(pickEl.getAttribute('data-match-sku') || '');
    var kind = confirmPick ? 'confirm' : 'select';
    if (kind === 'select' && (allMatches || []).length <= 1) kind = 'confirm';
    if (pickMatchByKind(kind, pickIndex, pickSku)) lastMatchPickAt = Date.now();
    return true;""",
    "card pick debounce after success",
)

css += """

/* LZ_YGF_TAP2_20260927 底部永遠是加入本次盤點，蓋過登入／確定帶入。 */
html body [data-match-action-dock] [data-match-dock-confirm] {
  flex: 1 1 100% !important;
}
html body.is-match-picker-open [data-match-action-dock] {
  min-height: 72px !important;
}
"""

html = html.replace("版本 v160", "版本 v161")
html = html.replace("?v=20260927-ygf-tap-1", "?v=" + TAG)
if "版本 v161" not in html:
    raise SystemExit("html version")
if TAG not in html:
    raise SystemExit("html bust")

(IN / "scanner-ygf-v59.js.new-ygf-tap-2").write_text(js, encoding="utf-8")
(IN / "scanner-ygf-v59.css.new-ygf-tap-2").write_text(css, encoding="utf-8")
(IN / "scanner.html.new-ygf-tap-2").write_text(html, encoding="utf-8")
print("ok", MARKER in js, "isStocktakeUi", "function isStocktakeUi" in js)
print("hide now", "hideMatchPickerNow" in js)
print("v161", "v161" in html)
