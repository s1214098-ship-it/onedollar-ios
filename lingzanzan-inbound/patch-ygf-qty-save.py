#!/usr/bin/env python3
"""Fix stocktake qty overlay and keep edited qty until save."""
from pathlib import Path

SRC = Path("/tmp/lz-ygf-qty")
OUT = Path("/workspace/lingzanzan-inbound")
OUT.mkdir(parents=True, exist_ok=True)
MARKER = "LZ_YGF_QTY_SAVE_20260927"
TAG = "20260927-ygf-qty-1"

js = (SRC / "scanner-ygf-v59.js").read_text(encoding="utf-8")
css = (SRC / "scanner-ygf-v59.css").read_text(encoding="utf-8")
html = (SRC / "scanner.html").read_text(encoding="utf-8")


def once(src, old, new, label):
    if old not in src:
        raise SystemExit(f"failed to bind {label}")
    if src.count(old) != 1:
        raise SystemExit(f"ambiguous {label} count={src.count(old)}")
    return src.replace(old, new, 1)


helpers = """
  function currentStocktakeQty() {
    var fast = document.querySelector('[data-fast-stocktake-qty]');
    var main = document.querySelector('[data-qty]');
    var live = Number(stocktakeLiveCounts[currentStocktakeScanCode] || 0);
    var n = Number((fast && String(fast.value).trim() !== '' ? fast.value : null) || (main && String(main.value).trim() !== '' ? main.value : null) || live || 0);
    if (!isFinite(n) || n < 0) n = 0;
    return Math.min(999, Math.floor(n));
  }
  function setCurrentStocktakeQty(n) {
    n = Math.max(0, Math.min(999, Math.floor(Number(n) || 0)));
    if (currentStocktakeScanCode) stocktakeLiveCounts[currentStocktakeScanCode] = n;
    var fast = document.querySelector('[data-fast-stocktake-qty]');
    var main = document.querySelector('[data-qty]');
    if (fast) fast.value = String(n);
    if (main) main.value = String(n);
    return n;
  }
"""

js = once(
    js,
    "  function renderStocktakeFastRows() {",
    helpers + "  function renderStocktakeFastRows() {",
    "qty helpers",
)

js = once(
    js,
    "      rows.unshift({ row: selected, qty: Number((document.querySelector('[data-qty]') || {}).value || 1), shelf: loc.shelf, layer: loc.layer, complete: false });",
    "      rows.unshift({ row: selected, qty: Math.max(0, currentStocktakeQty()), shelf: loc.shelf, layer: loc.layer, complete: false });",
    "render qty source",
)

js = once(
    js,
    '<span><small>顏色／尺寸</small><b>',
    '<span class="stocktake-fast-color"><small>顏色／尺寸</small><b>',
    "color class",
)

js = once(
    js,
    '<span><small>倉架位置</small><b>',
    '<span class="stocktake-fast-loc"><small>倉架位置</small><b>',
    "loc class",
)

js = once(
    js,
    """    var targetStock = sameWarehouse(row.warehouse, activeWarehouse()) ? stockOf(row) : 0;
    document.querySelector('[data-before-stock]').textContent = targetStock + ' 件';
    if (currentTab === 'stocktake') {
      currentStocktakeScanCode = String(currentStocktakeScanCode || row.scannedBarcode || row.barcode || row.skuId || '');
      if (!Object.prototype.hasOwnProperty.call(stocktakeLiveCounts, currentStocktakeScanCode)) stocktakeLiveCounts[currentStocktakeScanCode] = 1;
      document.querySelector('[data-qty]').value = String(stocktakeLiveCounts[currentStocktakeScanCode]);
    } else document.querySelector('[data-qty]').value = String(targetStock);""",
    """    var targetStock = sameWarehouse(row.warehouse, activeWarehouse()) ? stockOf(row) : 0;
    document.querySelector('[data-before-stock]').textContent = targetStock + ' 件';
    if (isStocktakeUi() || currentTab === 'stocktake') {
      currentTab = 'stocktake';
      currentStocktakeScanCode = String(currentStocktakeScanCode || row.scannedBarcode || row.barcode || row.skuId || '');
      if (!Object.prototype.hasOwnProperty.call(stocktakeLiveCounts, currentStocktakeScanCode)) stocktakeLiveCounts[currentStocktakeScanCode] = 1;
      document.querySelector('[data-qty]').value = String(stocktakeLiveCounts[currentStocktakeScanCode]);
    } else document.querySelector('[data-qty]').value = String(targetStock);""",
    "choose qty not book stock",
)

js = once(
    js,
    """    if (currentTab === 'stocktake') {
      if (keepPicker) {
        if (matchPanel) matchPanel.hidden = false;
        if (matchGrid) matchGrid.hidden = false;
      } else {
        matchPanel.hidden = hasLocation;
        if (matchGrid) matchGrid.hidden = true;
      }
      renderStocktakeFastRows();
    } else if (matchGrid) matchGrid.hidden = false;""",
    """    if (currentTab === 'stocktake') {
      if (keepPicker) {
        if (matchPanel) matchPanel.hidden = false;
        if (matchGrid) matchGrid.hidden = false;
      } else if (options.hidePicker !== false) {
        hideMatchPickerNow();
      } else {
        matchPanel.hidden = hasLocation || options.skipLocation === true;
        if (matchGrid) matchGrid.hidden = true;
      }
      renderStocktakeFastRows();
    } else if (matchGrid) matchGrid.hidden = false;""",
    "choose hide picker",
)

js = once(
    js,
    """      currentStocktakeScanCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
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
      return;""",
    """      currentStocktakeScanCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
      if (!Object.prototype.hasOwnProperty.call(stocktakeLiveCounts, currentStocktakeScanCode)) stocktakeLiveCounts[currentStocktakeScanCode] = Math.max(1, currentStocktakeQty() || 1);
      choose(row, index, true, {skipLocation: true, keepPicker: false, hidePicker: true});
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
      var broughtCount = setCurrentStocktakeQty(Math.max(1, currentStocktakeQty() || 1));
      hideMatchPickerNow();
      renderStocktakeFastRows();
      stocktakeFlowStatus('已帶入本次盤點明細 ' + (row.productCode || '') + '／' + (row.color || '') + '／' + (row.size || 'NO SIZE') + '，數量 ' + broughtCount + '。請改數量後按「完成這一件」儲存。');
      setMessage('已帶入本次盤點明細，現在的數量是 ' + broughtCount + ' 件。改完請按「完成這一件」儲存，不要讓它跳回帳面件數。', 'ok');
      return;""",
    "confirm pick keep qty",
)

js = once(
    js,
    """    if (target.matches('[data-fast-qty-minus],[data-fast-qty-plus]')) {
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
    }""",
    """    if (target.matches('[data-fast-qty-minus],[data-fast-qty-plus]')) {
      var nextQuickQty = setCurrentStocktakeQty(currentStocktakeQty() + (target.matches('[data-fast-qty-plus]') ? 1 : -1));
      refreshStocktakeCompare();
      var qtyLabel = document.querySelector('.stocktake-fast-row.is-current .fast-qty small');
      if (qtyLabel) qtyLabel.textContent = '數量 ' + nextQuickQty;
    }
    if (target.matches('[data-fast-confirm-stocktake]')) {
      if (selected && selected.colorConfirmationRequired && !selected.colorConfirmed) { beep(2); setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。','error'); return; }
      setCurrentStocktakeQty(Math.max(0, currentStocktakeQty()));
      submit('stocktake');
    }""",
    "plus minus save qty",
)

js = once(
    js,
    """  document.addEventListener('input', function (event) {
    if (!event.target || !event.target.matches('[data-fast-stocktake-qty]')) return;
    var qtyField = document.querySelector('[data-qty]');
    if (qtyField) qtyField.value = event.target.value;
    refreshStocktakeCompare();
  });""",
    """  document.addEventListener('input', function (event) {
    if (!event.target || !event.target.matches('[data-fast-stocktake-qty]')) return;
    setCurrentStocktakeQty(event.target.value);
    refreshStocktakeCompare();
  });""",
    "typed qty persist",
)

js = once(
    js,
    "    var qty = action === 'stocktake' ? Number(document.querySelector('[data-qty]').value || 0) : action === 'stockin' ? Number(document.querySelector('[data-stockin-qty]').value || 0) : Number(document.querySelector('[data-transfer-qty]').value || 0);",
    "    var qty = action === 'stocktake' ? currentStocktakeQty() : action === 'stockin' ? Number(document.querySelector('[data-stockin-qty]').value || 0) : Number(document.querySelector('[data-transfer-qty]').value || 0);",
    "submit uses live qty",
)

# Hide dock while current row is on screen.
js = once(
    js,
    "    board.hidden = currentTab !== 'stocktake' || !rows.length;",
    """    board.hidden = currentTab !== 'stocktake' || !rows.length;
    var hasCurrent = rows.some(function (entry) { return entry && !entry.complete; });
    if (hasCurrent) hideMatchPickerNow();""",
    "hide dock on current row",
)

if MARKER not in js:
    js += f"\n/* {MARKER} keep edited stocktake qty visible and savable */\n"

html = html.replace("?v=20260927-ygf-tap-2", f"?v={TAG}")
html = html.replace("版本 v161", "版本 v162")
if TAG not in html:
    raise SystemExit("html bust missing")
if "版本 v162" not in html:
    raise SystemExit("html version missing")

css += f"""

/* {MARKER} 數量獨立一列，不要跟「尚未選擇」疊在一起；改完要看得見才能儲存。 */
html body.scanner-pda-layout .stocktake-fast-row.is-current {{
  display: grid !important;
  grid-template-columns: 56px minmax(0, 1fr) !important;
  grid-template-rows: auto auto auto auto auto auto auto auto auto !important;
  gap: 8px 10px !important;
  align-items: start !important;
  overflow: visible !important;
  padding: 10px !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > img {{
  grid-column: 1 !important;
  grid-row: 1 / 3 !important;
  width: 52px !important;
  height: 52px !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .identity {{
  grid-column: 2 !important;
  grid-row: 1 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .stocktake-fast-color,
html body.scanner-pda-layout .stocktake-fast-row.is-current > span.stocktake-fast-color {{
  grid-column: 2 !important;
  grid-row: 2 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .fast-qty {{
  grid-column: 1 / -1 !important;
  grid-row: 3 !important;
  display: grid !important;
  gap: 4px !important;
  width: 100% !important;
  margin: 0 !important;
  padding: 8px 10px !important;
  border: 2px solid #1f4fb2 !important;
  border-radius: 14px !important;
  background: #fff !important;
  z-index: 3 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .fast-qty small {{
  display: block !important;
  font-size: 13px !important;
  font-weight: 950 !important;
  color: #1f4fb2 !important;
  -webkit-text-fill-color: #1f4fb2 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .fast-qty > span {{
  display: grid !important;
  grid-template-columns: 56px minmax(80px, 1fr) 56px !important;
  width: 100% !important;
  gap: 8px !important;
  align-items: center !important;
  flex-wrap: nowrap !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .fast-qty button {{
  min-width: 56px !important;
  width: 56px !important;
  min-height: 48px !important;
  font-size: 26px !important;
  font-weight: 950 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .fast-qty input,
html body.scanner-pda-layout .stocktake-fast-row.is-current > .fast-qty input[data-fast-stocktake-qty] {{
  width: 100% !important;
  min-width: 80px !important;
  min-height: 48px !important;
  height: 48px !important;
  font-size: 28px !important;
  font-weight: 950 !important;
  color: #10231f !important;
  -webkit-text-fill-color: #10231f !important;
  background: #fff !important;
  z-index: 4 !important;
  position: relative !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .stocktake-fast-loc,
html body.scanner-pda-layout .stocktake-fast-row.is-current > span.stocktake-fast-loc {{
  grid-column: 1 / -1 !important;
  grid-row: 4 !important;
  display: flex !important;
  flex-direction: column !important;
  min-height: 28px !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-change-location] {{
  grid-column: 1 / -1 !important;
  grid-row: 5 !important;
  width: 100% !important;
  min-height: 40px !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .stocktake-fast-edit-tools {{
  grid-column: 1 / -1 !important;
  grid-row: 6 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-print-stocktake-label] {{
  grid-column: 1 / -1 !important;
  grid-row: 7 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-split-location] {{
  grid-column: 1 / -1 !important;
  grid-row: 8 !important;
  width: 100% !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-confirm-stocktake] {{
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  grid-column: 1 / -1 !important;
  grid-row: 9 !important;
  width: 100% !important;
  min-height: 54px !important;
  margin: 0 !important;
  background: #1f4fb2 !important;
  color: #fff !important;
  -webkit-text-fill-color: #fff !important;
  font-size: 18px !important;
  font-weight: 950 !important;
  z-index: 5 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-cancel-current-stocktake] {{
  grid-column: 1 / -1 !important;
  grid-row: 10 !important;
  width: 100% !important;
}}
html body.scanner-pda-layout:has(.stocktake-fast-row.is-current) [data-match-action-dock] {{
  display: none !important;
}}
"""

(OUT / "scanner-ygf-v59.js.new-ygf-qty-1").write_text(js, encoding="utf-8")
(OUT / "scanner-ygf-v59.css.new-ygf-qty-1").write_text(css, encoding="utf-8")
(OUT / "scanner.html.new-ygf-qty-1").write_text(html, encoding="utf-8")
print("js helpers", "currentStocktakeQty" in js, "css marker", MARKER in css, "html", TAG in html, "v162", "v162" in html)
print("ok")
