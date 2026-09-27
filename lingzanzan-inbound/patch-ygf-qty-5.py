#!/usr/bin/env python3
from pathlib import Path

js_path = Path(__file__).resolve().parent / 'scanner-ygf-v59.js.new-ygf-qty-4'
js = js_path.read_text(encoding='utf-8')


def must_replace(old, new, label):
    global js
    if old not in js:
        raise SystemExit('missing ' + label)
    if js.count(old) != 1:
        raise SystemExit('%s count %s' % (label, js.count(old)))
    js = js.replace(old, new, 1)


must_replace(
    "escapeHtml(row.labelBarcode || row.scannedBarcode || row.barcode || row.skuId || row.productCode || '-')",
    "escapeHtml(stocktakeDisplayBarcode(row))",
    'display-barcode',
)

must_replace(
    "  function resolveSelectedSkuId(row) {",
    """  function stocktakeDisplayBarcode(row) {
    row = row || {};
    var scanned = String(row.scannedBarcode || currentStocktakeScanCode || '').trim();
    var sticker = String(row.barcode || '').trim();
    var sku = String(row.skuId || row.productCode || '').trim();
    var label = String(row.labelBarcode || '').trim();
    if (scanned) return scanned;
    if (sticker) return sticker;
    if (sku) return sku;
    return label || '-';
  }
  function resolveSelectedSkuId(row) {""",
    'helper',
)

must_replace(
    ": ((stocktakeAutoSubmitting || stocktakeCommitUi) ? '<strong class=\"done\">正在寫入盤點單…</strong>' : '",
    ": ((stocktakeAutoSubmitting || stocktakeCommitUi) ? '' : '",
    'overlay',
)

must_replace(
    "  function saveCurrentStocktakeItem(options) {",
    """  function clearStocktakeCommitUi() {
    stocktakeAutoSubmitting = false;
    stocktakeCommitUi = false;
  }
  function saveCurrentStocktakeItem(options) {""",
    'clear-fn',
)

must_replace(
    """    if (!selected) { setMessage('請先掃描並選擇商品。', 'error'); return; }
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
    }""",
    """    if (!selected) { clearStocktakeCommitUi(); setMessage('請先掃描並選擇商品。', 'error'); renderStocktakeFastRows(); return; }
    var skuId = resolveSelectedSkuId(selected);
    if (!skuId) {
      clearStocktakeCommitUi();
      beep(2);
      setMessage('兩聲：OLAN70 這種系列碼要先選顏色／尺寸。選好就會寫入盤點，不必再按完成。', 'error');
      renderStocktakeFastRows();
      return;
    }
    if (selected.colorConfirmationRequired && !selected.colorConfirmed) {
      clearStocktakeCommitUi();
      beep(2);
      setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。', 'error');
      renderStocktakeFastRows();
      return;
    }""",
    'save-early',
)

must_replace(
    """    if (!selected) {
      setMessage('請先掃描並選擇商品。', 'error');
      return;
    }
    if (operator.role === 'unknown') {
      setMessage('請先從管理者後台登入員工帳號，再開啟盤點機。', 'error');
      return;
    }""",
    """    if (!selected) {
      clearStocktakeCommitUi();
      setMessage('請先掃描並選擇商品。', 'error');
      renderStocktakeFastRows();
      return;
    }
    if (operator.role === 'unknown') {
      clearStocktakeCommitUi();
      beep(2);
      setMessage('兩聲：請先登入盤點機，這件還沒寫進盤點單。', 'error');
      renderStocktakeFastRows();
      return;
    }""",
    'submit-early',
)

must_replace(
    """  function commitStocktakeSelection(row, index, options) {
    options = options || {};
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    currentTab = 'stocktake';""",
    """  function commitStocktakeSelection(row, index, options) {
    options = options || {};
    if (!row) { setMessage('請先選正確產品。', 'error'); return; }
    if (operator && operator.role === 'unknown') {
      beep(2);
      setMessage('兩聲：請先登入盤點機，再掃品項。', 'error');
      return;
    }
    currentTab = 'stocktake';""",
    'commit-login',
)

if 'LZ_YGF_QTY_SAVE5_20260927' not in js:
    js += '\n/* LZ_YGF_QTY_SAVE5_20260927 no overlay, show scanned barcode */\n'

js_path.write_text(js, encoding='utf-8')
print('ok')
