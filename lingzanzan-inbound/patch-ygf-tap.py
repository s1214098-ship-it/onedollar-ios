#!/usr/bin/env python3
"""Make 選這個／加入本次盤點 tappable on YGF; dock covers 登入盤點機 footer."""
from pathlib import Path

ROOT = Path("/tmp/lz-ygf-tap")
OUT = Path("/workspace/lingzanzan-inbound")
TAG = "20260927-ygf-tap-1"
MARKER = "LZ_YGF_TAP_20260927"

js_path = ROOT / "scanner-ygf-v59.js"
css_path = ROOT / "scanner-ygf-v59.css"
html_path = ROOT / "scanner.html"
js = js_path.read_text(encoding="utf-8")
css = css_path.read_text(encoding="utf-8")
html = html_path.read_text(encoding="utf-8")


def once(src, old, new, label):
    if old not in src:
        raise SystemExit(f"failed to bind {label}")
    if src.count(old) != 1:
        raise SystemExit(f"ambiguous {label} count={src.count(old)}")
    return src.replace(old, new, 1)


if MARKER not in js:
    js = once(
        js,
        "  /* LZ_YGF_QTY_NO_LOC_20260927: 確定帶入加入本次盤點數量，倉位可空白；倉位確定也走 touch。 */",
        "  /* LZ_YGF_QTY_NO_LOC_20260927: 確定帶入加入本次盤點數量，倉位可空白；倉位確定也走 touch。 */\n"
        "  /* LZ_YGF_TAP_20260927: 卡片與底部碼頭都能加入本次盤點；文字節點點擊也算。 */",
        "js marker",
    )

old_btn = (
    """<span class="match-card-actions"><button type="button" data-match-select="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">選這個</button><button type="button" class="primary" data-match-confirm="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">確定帶入</button></span></article>';"""
)
new_btn = (
    """<span class="match-card-actions"><button type="button" data-match-select="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">選這個</button><button type="button" class="primary" data-match-confirm="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">加入本次盤點</button></span></article>';"""
)
js = once(js, old_btn, new_btn, "match card buttons")

js = once(
    js,
    "return '<article class=\"match-card' + (selected === row ? ' is-selected' : '') + '\" data-match-index=\"' + index + '\" data-match-sku=\"' + escapeHtml(String(row.skuId || '')) + '\">",
    "return '<article class=\"match-card' + (selected === row || visible.length === 1 ? ' is-selected' : '') + '\" data-match-index=\"' + index + '\" data-match-sku=\"' + escapeHtml(String(row.skuId || '')) + '\">",
    "auto-select single card",
)

js = once(
    js,
    """    matchGrid.innerHTML = visible.map(function (row, index) {""",
    """    if (visible.length === 1) selected = visible[0];
    matchGrid.innerHTML = visible.map(function (row, index) {""",
    "assign selected single match",
)

old_wait = (
    "var waitingForSetup = !entry.complete && currentTab === 'stocktake' && currentStocktakeScanCode && !(stocktakeFlowLock.barcode === currentStocktakeScanCode && stocktakeFlowLock.skuId === String(row.skuId || '') && stocktakeFlowLock.shelf && stocktakeFlowLock.layer);"
)
new_wait = (
    "var waitingForSetup = !entry.complete && currentTab === 'stocktake' && currentStocktakeScanCode && !(stocktakeFlowLock.barcode === currentStocktakeScanCode && stocktakeFlowLock.skuId === String(row.skuId || ''));"
)
js = once(js, old_wait, new_wait, "waitingForSetup no shelf")

js = once(
    js,
    """    }).join('');
    syncMatchPickerChrome();
    if (!matchPanel.hidden && matchPanel.scrollIntoView) {
      try { matchPanel.scrollIntoView({block:'start'}); } catch (error) {}
    }
  }
  function choose(row, index, manualColorConfirmation, options) {""",
    """    }).join('');
    if (matchGrid) {
      matchGrid.querySelectorAll('[data-match-confirm],[data-match-select]').forEach(function (btn) {
        btn.onclick = function (event) {
          if (event && event.preventDefault) event.preventDefault();
          if (event && event.stopPropagation) event.stopPropagation();
          return window.__lzYgfPick && window.__lzYgfPick(btn.hasAttribute('data-match-confirm') ? 'confirm' : 'select', btn.getAttribute(btn.hasAttribute('data-match-confirm') ? 'data-match-confirm' : 'data-match-select'), btn.getAttribute('data-match-sku'), event);
        };
        btn.ontouchend = btn.onclick;
        btn.onpointerup = btn.onclick;
      });
    }
    syncMatchPickerChrome();
    if (!matchPanel.hidden && matchPanel.scrollIntoView) {
      try { matchPanel.scrollIntoView({block:'start'}); } catch (error) {}
    }
  }
  function choose(row, index, manualColorConfirmation, options) {""",
    "bind card buttons after render",
)

old_confirm = """  function confirmMatchPick(row, index) {
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    if (currentTab === 'stocktake') {
      currentStocktakeScanCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
      stocktakeLiveCounts[currentStocktakeScanCode] = Math.max(1, Number(stocktakeLiveCounts[currentStocktakeScanCode] || 0));
      choose(row, index, true, {skipLocation: true});
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
      if (matchPanel) matchPanel.hidden = true;
      if (matchGrid) matchGrid.hidden = true;
      renderStocktakeFastRows();
      syncMatchPickerChrome();
      stocktakeFlowStatus('已加入本次盤點 ' + (row.productCode || '') + '／' + (row.color || '') + '／' + (row.size || 'NO SIZE') + '，數量 ' + broughtCount + '。倉位可之後再補。');
      setMessage('已加入本次盤點數量 ' + broughtCount + ' 件；不必先選貨架。要補倉位再按「更正本筆位置」。', 'ok');
      readyForNextScan();
      return;
    }"""

new_confirm = """  function confirmMatchPick(row, index) {
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
    }"""
js = once(js, old_confirm, new_confirm, "confirmMatchPick submit")

old_sync = """  function syncMatchPickerChrome() {
    var locationOpen = !!document.querySelector('[data-match-first-location]:not([hidden])');
    var open = !!(matchPanel && !matchPanel.hidden && ((matchGrid && !matchGrid.hidden) || locationOpen));
    document.body.classList.toggle('is-match-picker-open', open);
    if (open) {
      if (input) try { input.blur(); } catch (error) {}
      if (manualInput) try { manualInput.blur(); } catch (error) {}
    }
  }"""

new_sync = """  function ensureMatchActionDock() {
    var dock = document.querySelector('[data-match-action-dock]');
    if (dock) return dock;
    dock = document.createElement('div');
    dock.setAttribute('data-match-action-dock', '');
    dock.hidden = true;
    dock.innerHTML = '<button type="button" data-match-dock-select>選這個</button><button type="button" class="primary" data-match-dock-confirm>加入本次盤點</button>';
    document.body.appendChild(dock);
    function dockPick(kind, event) {
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
    });
    return dock;
  }
  function syncMatchPickerChrome() {
    var locationOpen = !!document.querySelector('[data-match-first-location]:not([hidden])');
    var open = !!(matchPanel && !matchPanel.hidden && ((matchGrid && !matchGrid.hidden) || locationOpen));
    document.body.classList.toggle('is-match-picker-open', open);
    var dock = ensureMatchActionDock();
    var confirmBtn = dock.querySelector('[data-match-dock-confirm]');
    var selectBtn = dock.querySelector('[data-match-dock-select]');
    if (confirmBtn) confirmBtn.textContent = currentTab === 'stocktake' ? '加入本次盤點' : '確定帶入';
    if (selectBtn) selectBtn.hidden = !((allMatches || []).length > 1);
    dock.hidden = !open;
    if (open) {
      if (input) try { input.blur(); } catch (error) {}
      if (manualInput) try { manualInput.blur(); } catch (error) {}
    }
  }"""
js = once(js, old_sync, new_sync, "syncMatchPickerChrome dock")

old_handle = Path("/tmp/lz-ygf-tap/scanner-ygf-v59.js").read_text(encoding="utf-8")
start = old_handle.find("  var lastMatchPickAt = 0;\n  function handleMatchPickFromEvent(event) {")
end = old_handle.find("  document.addEventListener('click', function (event) {\n    if (handleMatchPickFromEvent(event)")
if start < 0 or end < 0:
    raise SystemExit(f"failed handle bounds start={start} end={end}")
old_handle_fn = old_handle[start:end]
new_handle = r'''  var lastMatchPickAt = 0;
  function eventElement(event) {
    var t = event && event.target;
    if (!t) return null;
    if (t.nodeType === 3) t = t.parentNode;
    while (t && t.nodeType !== 1) t = t.parentNode;
    return t && t.closest ? t : null;
  }
  function pickMatchByKind(kind, index, sku) {
    var confirmBtn = document.querySelector('.match-card.is-selected [data-match-confirm]') || document.querySelector('[data-match-confirm]');
    var selectBtn = document.querySelector('.match-card.is-selected [data-match-select]') || document.querySelector('[data-match-select]');
    var pickEl = kind === 'confirm' ? confirmBtn : selectBtn;
    var pickIndex = index != null && index !== '' ? Number(index) : Number(pickEl && pickEl.getAttribute(kind === 'confirm' ? 'data-match-confirm' : 'data-match-select'));
    var pickSku = String(sku || (pickEl && pickEl.getAttribute('data-match-sku')) || '');
    var pickRow = (pickSku && (allMatches || []).find(function (row) { return String(row && row.skuId || '') === pickSku; })) || (allMatches || [])[pickIndex];
    if (!pickRow) { setMessage('產品資料已過期，請再掃一次。', 'error'); return false; }
    if (kind === 'confirm') confirmMatchPick(pickRow, pickIndex);
    else {
      choose(pickRow, pickIndex, true, {keepPicker: true});
      document.querySelectorAll('.match-card').forEach(function (card) {
        card.classList.toggle('is-selected', String(card.getAttribute('data-match-sku') || '') === String(pickRow.skuId || ''));
      });
      setMessage('已選 ' + (pickRow.productCode || '') + '／' + (pickRow.color || '') + '。確認是這件就按「加入本次盤點」。', 'ok');
    }
    return true;
  }
  window.__lzYgfPick = function (kind, index, sku, event) {
    if (event && event.preventDefault) event.preventDefault();
    if (event && event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 450) return false;
    lastMatchPickAt = Date.now();
    pickMatchByKind(kind, index, sku);
    return false;
  };
  function handleMatchPickFromEvent(event) {
    var el = eventElement(event);
    if (!el) return false;
    if (el.closest('[data-match-dock-confirm]')) {
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
    }
    var saveLocation = el.closest('[data-save-match-location]');
    if (saveLocation) {
      if (event.type !== 'click') {
        if (event.preventDefault) event.preventDefault();
        if (event.stopPropagation) event.stopPropagation();
        if (Date.now() - lastMatchPickAt < 450) return true;
        lastMatchPickAt = Date.now();
        saveLocation.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
        return true;
      }
      return false;
    }
    var confirmPick = el.closest('[data-match-confirm]');
    var selectPick = el.closest('[data-match-select]');
    var matchCard = (!confirmPick && !selectPick) ? el.closest('[data-match-index]') : null;
    if (!confirmPick && !selectPick && !matchCard) return false;
    if (event.preventDefault) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 450) return true;
    lastMatchPickAt = Date.now();
    var pickEl = confirmPick || selectPick || matchCard;
    var pickIndex = Number(pickEl.getAttribute(confirmPick ? 'data-match-confirm' : (selectPick ? 'data-match-select' : 'data-match-index')));
    var pickSku = String(pickEl.getAttribute('data-match-sku') || '');
    pickMatchByKind(confirmPick ? 'confirm' : 'select', pickIndex, pickSku);
    return true;
  }
'''
js = once(js, old_handle_fn, new_handle, "handleMatchPick text+dock")
js = js.replace("確認是這件就按「確定帶入」。", "確認是這件就按「加入本次盤點」。")

js = once(
    js,
    """      button.addEventListener('click', function () {
        var account = document.querySelector('[data-scanner-login-account]');
        if (account) account.focus();
        var gate = document.querySelector('[data-scanner-login-gate]');
        if (gate && gate.scrollIntoView) gate.scrollIntoView({ block: 'center' });
      });""",
    """      button.addEventListener('click', function (event) {
        if (document.body.classList.contains('is-match-picker-open')) {
          if (event.preventDefault) event.preventDefault();
          if (event.stopPropagation) event.stopPropagation();
          return;
        }
        var account = document.querySelector('[data-scanner-login-account]');
        if (account) account.focus();
        var gate = document.querySelector('[data-scanner-login-gate]');
        if (gate && gate.scrollIntoView) gate.scrollIntoView({ block: 'center' });
      });""",
    "login-focus ignore while picker",
)

css_append = """

/* LZ_YGF_TAP_20260927 產品卡按鈕與底部「加入本次盤點」碼頭蓋過登入盤點機。 */
html body .match-panel:not([hidden]) .match-grid:not([hidden]),
html body .match-panel:not([hidden]) [data-match-first-location]:not([hidden]) {
  position: static !important;
  transform: none !important;
  box-shadow: none !important;
  z-index: auto !important;
}
html body.is-match-picker-open footer {
  visibility: hidden !important;
  pointer-events: none !important;
}
html body [data-match-action-dock] {
  display: none;
}
html body.is-match-picker-open [data-match-action-dock],
html body [data-match-action-dock]:not([hidden]) {
  display: flex !important;
  position: fixed !important;
  left: 0 !important;
  right: 0 !important;
  bottom: 0 !important;
  z-index: 2147483647 !important;
  gap: 8px;
  padding: 10px 12px calc(12px + env(safe-area-inset-bottom, 0px));
  background: #101c18 !important;
  pointer-events: auto !important;
}
html body [data-match-action-dock] button {
  flex: 1 1 0;
  min-height: 56px !important;
  border-radius: 12px !important;
  font-size: 18px !important;
  font-weight: 900 !important;
  pointer-events: auto !important;
  touch-action: manipulation !important;
  -webkit-tap-highlight-color: rgba(31,79,178,.28);
}
html body [data-match-action-dock] [data-match-dock-select] {
  background: #fff !important;
  color: #1f3f9a !important;
  border: 1px solid #355bd8 !important;
}
html body [data-match-action-dock] [data-match-dock-confirm],
html body [data-match-action-dock] [data-match-dock-confirm].primary {
  background: #1f4fb2 !important;
  color: #fff !important;
  -webkit-text-fill-color: #fff !important;
  border: 1px solid #1f4fb2 !important;
}
html body.is-match-picker-open .match-card-actions,
html body.is-match-picker-open .match-card-actions button {
  position: relative !important;
  z-index: 2147483000 !important;
  pointer-events: auto !important;
  touch-action: manipulation !important;
}
html body.is-match-picker-open .match-card-actions button {
  min-height: 52px !important;
}
html body.scanner-pda-layout {
  padding-bottom: 72px;
}
"""
if MARKER not in css:
    css += css_append

html = html.replace("版本 v159", "版本 v160")
html = html.replace("?v=20260927-scan-newest-2", f"?v={TAG}")
if "版本 v160" not in html:
    raise SystemExit("failed html version")
if TAG not in html:
    raise SystemExit("failed html bust")

out_js = OUT / "scanner-ygf-v59.js.new-ygf-tap-1"
out_css = OUT / "scanner-ygf-v59.css.new-ygf-tap-1"
out_html = OUT / "scanner.html.new-ygf-tap-1"
out_js.write_text(js, encoding="utf-8")
out_css.write_text(css, encoding="utf-8")
out_html.write_text(html, encoding="utf-8")
print("js", out_js.stat().st_size, "css", out_css.stat().st_size, "html", out_html.stat().st_size)
print("js marker", MARKER in js)
print("dock", "data-match-action-dock" in js)
print("inline pick", "__lzYgfPick" in js)
print("submit in confirm", "submit('stocktake')" in js[js.find("function confirmMatchPick"):js.find("function confirmMatchPick")+1800])
print("html v160", "v160" in html, TAG in html)
