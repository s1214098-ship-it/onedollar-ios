#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def must_replace(text, old, new, label):
    if old not in text:
        raise SystemExit(f'missing snippet: {label}\n---\n{old[:180]}')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'expected 1 occurrence for {label}, found {count}')
    return text.replace(old, new, 1)


def patch_js(text):
    text = must_replace(
        text,
        """  function saveCurrentStocktakeItem() {
    if (!selected) { setMessage('請先掃描並選擇商品。', 'error'); return; }
    var skuId = resolveSelectedSkuId(selected);
    if (!skuId) {
      beep(2);
      setMessage('兩聲：OLAN70 這種系列碼要先選顏色／尺寸，選好後再按完成。', 'error');
      return;
    }
    if (selected.colorConfirmationRequired && !selected.colorConfirmed) {
      beep(2);
      setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。', 'error');
      return;
    }
    selected.skuId = skuId;
    hushStocktakeScanResidue();
    setCurrentStocktakeQty(Math.max(0, currentStocktakeQty()));
    submit('stocktake');
  }""",
        """  function countedStocktakeQtyFor(row, code) {
    row = row || selected || {};
    code = String(code || currentStocktakeScanCode || row.scannedBarcode || row.barcode || row.skuId || '');
    var live = Number(stocktakeLiveCounts[code] || stocktakeLiveCounts[String(row.skuId || '')] || 0);
    var saved = 0;
    draft.forEach(function (line) {
      if (!line || line.action !== 'stocktake') return;
      if (String(line.skuId || '') === String(row.skuId || '') || String(line.barcode || '').toUpperCase() === code.toUpperCase()) {
        saved = Math.max(saved, Number(line.qty || 0));
      }
    });
    if (session && Array.isArray(session.lines)) {
      session.lines.forEach(function (line) {
        var sameSku = String(line.sourceSkuId || line.skuId || '') === String(row.skuId || '');
        var sameCode = String(line.barcode || '').toUpperCase() === code.toUpperCase();
        if (sameSku || sameCode) saved = Math.max(saved, Number(line.countedQty || line.qty || 0));
      });
    }
    return Math.max(live, saved, 0);
  }
  function commitStocktakeSelection(row, index, options) {
    options = options || {};
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    currentTab = 'stocktake';
    document.body.setAttribute('data-current-scanner-tab', 'stocktake');
    currentStocktakeScanCode = String(row.scannedBarcode || options.code || row.barcode || row.skuId || '').trim();
    choose(row, index, true, {skipLocation: true, keepPicker: false, hidePicker: true});
    if (canReuseStocktakeSharedLocation()) {
      selected.shelf = stocktakeSharedLocation.shelf;
      selected.layer = stocktakeSharedLocation.layer;
      row.shelf = selected.shelf;
      row.layer = selected.layer;
    }
    stocktakeFlowLock = {
      barcode: currentStocktakeScanCode,
      skuId: resolveSelectedSkuId(selected),
      shelf: String(selected.shelf || ''),
      layer: String(selected.layer || '')
    };
    var already = countedStocktakeQtyFor(selected, currentStocktakeScanCode);
    var nextQty = options.increment && already > 0 ? already + 1 : Math.max(1, already || currentStocktakeQty() || 1);
    setCurrentStocktakeQty(nextQty);
    hideMatchPickerNow();
    hushStocktakeScanResidue();
    renderStocktakeFastRows();
    stocktakeFlowStatus('正在把 ' + (selected.productCode || '') + '／' + (selected.color || '') + ' 寫入本次盤點單…');
    saveCurrentStocktakeItem({ fromScan: true, auto: true });
  }
  function saveCurrentStocktakeItem(options) {
    options = options || {};
    if (!selected) { setMessage('請先掃描並選擇商品。', 'error'); return; }
    var skuId = resolveSelectedSkuId(selected);
    if (!skuId) {
      beep(2);
      setMessage('兩聲：OLAN70 這種系列碼要先選顏色／尺寸。選好就會寫入盤點，不必再按完成。', 'error');
      return;
    }
    if (selected.colorConfirmationRequired && !selected.colorConfirmed) {
      beep(2);
      setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。', 'error');
      return;
    }
    selected.skuId = skuId;
    hushStocktakeScanResidue();
    var qty = currentStocktakeQty();
    if (options.fromScan) qty = Math.max(1, qty || 1);
    setCurrentStocktakeQty(qty);
    submit('stocktake', options);
  }""",
        'save-and-commit',
    )

    text = must_replace(
        text,
        """  function confirmMatchPick(row, index) {
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    if (isStocktakeUi() || currentTab === 'stocktake') {
      if (operator && operator.role === 'unknown') {
        setMessage('請先登入盤點機帳號，再加入本次盤點。', 'error');
        return;
      }
      currentTab = 'stocktake';
      document.body.setAttribute('data-current-scanner-tab', 'stocktake');
      currentStocktakeScanCode = String(row.scannedBarcode || row.barcode || row.skuId || '').trim();
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
      return;
    }""",
        """  function confirmMatchPick(row, index) {
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    if (isStocktakeUi() || currentTab === 'stocktake') {
      if (operator && operator.role === 'unknown') {
        setMessage('請先登入盤點機帳號，再加入本次盤點。', 'error');
        return;
      }
      commitStocktakeSelection(row, index, { code: row.scannedBarcode || row.barcode || row.skuId || '' });
      return;
    }""",
        'confirm-pick',
    )

    text = must_replace(
        text,
        """    var exactRows = allMatches.filter(function (row) { return row.exact; });
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
    }""",
        """    var exactRows = allMatches.filter(function (row) { return row.exact; });
    if (currentTab === 'stocktake' && wh && wh !== 'all') {
      var warehouseExact = exactRows.filter(function (row) { return sameWarehouse(row.warehouse, wh); });
      if (warehouseExact.length) exactRows = warehouseExact;
    }
    // Some valid product codes contain an S segment before P (for example
    // LEN180S2P350C91S6).  The old text-only parser treated those as a series
    // code even when the API had one exact SKU.  A unique exact API match is
    // authoritative and should open the product directly.
    var fullBarcodeScan = scannedCodeMode(code) === 'full' || exactRows.length === 1;
    if (!fullBarcodeScan || (currentTab === 'stocktake' && exactRows.length !== 1 && allMatches.length)) {
      if (currentTab === 'stocktake') renderStocktakeFastRows();
      if (matchGrid) matchGrid.hidden = false;
      archiveScanResult(code, 'series_found', null, allMatches.length, '商品系列碼已找到，等待選擇顏色與尺寸');
      beep(1);
      setMessage('一聲：已找到「' + stockinProductCodeFromBarcode(code) + '」。請點正確顏色／尺寸，選定後會直接寫入本次盤點。', 'ok');
      return;
    }
    if (exactRows.length !== 1) {
      if (matchGrid) matchGrid.hidden = false;
      archiveScanResult(code, exactRows.length ? 'ambiguous' : 'similar_only', null, allMatches.length, exactRows.length ? '完整條碼對到多筆 SKU' : '完整條碼未精準對到 SKU');
      if (allMatches.length) {
        beep(exactRows.length ? 2 : 1);
        setMessage(exactRows.length ? '兩聲：這個條碼對到多個倉庫或規格，請點正確那張，選定後會寫入本次盤點。' : '一聲：已列出相近商品，請點正確顏色／尺寸，選定後會寫入本次盤點。', exactRows.length ? 'error' : 'ok');
        return;
      }
      beep(3);
      setMessage('三聲：沒有找到與完整條碼完全相符的 SKU。', 'error');
      return;
    }""",
        'classify-exact',
    )

    text = must_replace(
        text,
        """    if (currentTab === 'stocktake') {
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
        : '第一刷已計 1 件。請核對縮圖、編號、顏色與尺寸；正確請按「確認產品」或「完成這一件」儲存，倉位可之後再補。第二刷才會變 2。');
      setMessage(canReuseLocation
        ? '已辨識商品並沿用目前倉架 ' + stocktakeSharedLocation.shelf + '／' + stocktakeSharedLocation.layer + '；可直接繼續掃。'
        : '商品已辨識並計入第 1 件；請確認商品與層架，不必重新刷這一件。', 'ok');
      readyForNextScan();
      return;
    }""",
        """    if (currentTab === 'stocktake') {
      archiveScanResult(code, 'matched', autoRow, allMatches.length, '已對到商品，直接寫入本次盤點');
      commitStocktakeSelection(autoRow, allMatches.indexOf(autoRow), { code: code, increment: countedStocktakeQtyFor(autoRow, code) > 0 });
      return;
    }""",
        'unique-autosave',
    )

    text = must_replace(
        text,
        """    if (currentTab === 'stocktake' && selected && resolveSelectedSkuId(selected) && isCurrentStocktakeItemCode(code)) {
      hushStocktakeScanResidue();
      readyForNextScan();
      return;
    }""",
        """    if (currentTab === 'stocktake' && selected && resolveSelectedSkuId(selected) && isCurrentStocktakeItemCode(code)) {
      if (options.physicalScan && !stocktakeAutoSubmitting && Date.now() - lastAcceptedHardwareAt > 900) {
        setCurrentStocktakeQty(Math.max(1, countedStocktakeQtyFor(selected, code)) + 1);
        saveCurrentStocktakeItem({ fromScan: true, auto: true });
        return;
      }
      hushStocktakeScanResidue();
      readyForNextScan();
      return;
    }""",
        'lookup-guard',
    )

    old_btn = " : '<button type=\"button\" class=\"primary\" data-fast-confirm-stocktake data-fast-alias-confirm>確認產品</button>"
    new_btn = " : (stocktakeAutoSubmitting ? '<strong class=\"done\">正在寫入盤點單…</strong>' : '<button type=\"button\" class=\"primary\" data-fast-confirm-stocktake data-fast-alias-confirm>確認產品</button>"
    if old_btn not in text:
        raise SystemExit('missing confirm-button ternary')
    text = text.replace(old_btn, new_btn, 1)
    # close extra paren for ternary: ...完成這一件</button>...'  becomes ...</button>') 
    old_end = '<button type="button" class="primary" data-fast-confirm-stocktake>完成這一件</button><button type="button" data-cancel-current-stocktake>清除這個產品</button>\')'
    new_end = '<button type="button" class="primary" data-fast-confirm-stocktake>完成這一件</button><button type="button" data-cancel-current-stocktake>清除這個產品</button>\')'
    # The original ends with: 清除這個產品</button>') + print
    # After wrapping submitting ternary we need an extra closing paren before + print
    old_close = '清除這個產品</button>\') + \'<button type="button" class="stocktake-print-label"'
    new_close = '清除這個產品</button>\') ) + \'<button type="button" class="stocktake-print-label"'
    if old_close not in text:
        raise SystemExit('missing current-row close for submitting ternary')
    text = text.replace(old_close, new_close, 1)

    if 'LZ_YGF_QTY_SAVE4_20260927' not in text:
        text += '\n/* LZ_YGF_QTY_SAVE4_20260927 scan commits stocktake, no extra 完成這一件 */\n'
    return text


def patch_css(text):
    marker = '/* LZ_YGF_QTY_SAVE4_20260927 scan writes stocktake immediately */'
    if marker not in text:
        text += """

""" + marker + """
html body.scanner-pda-layout .stocktake-fast-row.is-current > .done {
  display: flex !important;
  grid-column: 1 / -1 !important;
  min-height: 44px !important;
  align-items: center !important;
  justify-content: center !important;
}
"""
    return text


def patch_html(text):
    text = text.replace('?v=20260927-ygf-qty-3', '?v=20260927-ygf-qty-4')
    text = text.replace('版本 v164', '版本 v165')
    if '20260927-ygf-qty-4' not in text or '版本 v165' not in text:
        raise SystemExit('html cache-bust failed')
    return text


def main():
    js_path = ROOT / 'scanner-ygf-v59.js.new-ygf-qty-4'
    css_path = ROOT / 'scanner-ygf-v59.css.new-ygf-qty-4'
    html_path = ROOT / 'scanner.html.new-ygf-qty-4'
    js_path.write_text(patch_js(js_path.read_text(encoding='utf-8')), encoding='utf-8')
    css_path.write_text(patch_css(css_path.read_text(encoding='utf-8')), encoding='utf-8')
    html_path.write_text(patch_html(html_path.read_text(encoding='utf-8')), encoding='utf-8')
    print('patched ok')


if __name__ == '__main__':
    main()
