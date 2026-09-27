#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def must_replace(text, old, new, label):
    if old not in text:
        raise SystemExit(f'missing snippet: {label}')
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'expected 1 occurrence for {label}, found {count}')
    return text.replace(old, new, 1)


def patch_js(text):
    text = must_replace(
        text,
        """  function saveCurrentStocktakeItem() {
    if (!selected) { setMessage('請先掃描並選擇商品。', 'error'); return; }
    if (selected.colorConfirmationRequired && !selected.colorConfirmed) {
      beep(2);
      setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。', 'error');
      return;
    }
    setCurrentStocktakeQty(Math.max(0, currentStocktakeQty()));
    submit('stocktake');
  }""",
        """  function resolveSelectedSkuId(row) {
    row = row || selected || {};
    return String(row.skuId || row.sku || row.id || '').trim();
  }
  function isOlanSeriesOnlyBarcode(code) {
    return /^OLAN\\d+$/i.test(normalizeScanCode(code));
  }
  function isCurrentStocktakeItemCode(code) {
    code = normalizeScanCode(code);
    if (!code || !selected) return false;
    var ids = [
      selected.skuId, selected.sku, selected.id, selected.barcode, selected.labelBarcode,
      selected.scannedBarcode, selected.productCode, currentStocktakeScanCode
    ].map(function (value) { return normalizeScanCode(value); }).filter(Boolean);
    if (ids.indexOf(code) !== -1) return true;
    var series = normalizeScanCode(selected.productCode || '');
    if (!series) return false;
    if (code === series) return true;
    return code.indexOf(series) === 0 && /^(P|-|[0-9])/.test(code.slice(series.length));
  }
  function hushStocktakeScanResidue() {
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
  }
  function saveCurrentStocktakeItem() {
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
        'saveCurrentStocktakeItem',
    )

    text = must_replace(
        text,
        """  function lookup(code, options) {
    options = options || {};
    code = normalizeScanCode(code);
    if (!code) return;
    if (matchPanel && !matchPanel.hidden && matchGrid && !matchGrid.hidden && (allMatches || []).length) {""",
        """  function lookup(code, options) {
    options = options || {};
    code = normalizeScanCode(code);
    if (!code) return;
    if (currentTab === 'stocktake' && selected && resolveSelectedSkuId(selected) && isCurrentStocktakeItemCode(code)) {
      hushStocktakeScanResidue();
      readyForNextScan();
      return;
    }
    if (matchPanel && !matchPanel.hidden && matchGrid && !matchGrid.hidden && (allMatches || []).length) {""",
        'lookup-guard',
    )

    text = must_replace(
        text,
        """  function rejectIncompleteScannedBarcode(code) {
    if (!isIncompleteOlanBarcode(code)) return false;
    beep(3);
    updateScanEcho(code, '條碼不完整：缺少 P成本＋色碼，未加入任何單據', 'error');
    setMessage('三聲：OLAN 條碼必須包含「P＋3位成本＋色碼」，例如 OLAN72P35096。本次未加入盤點或調撥單。', 'error');
    hardwareScanBuffer = '';
    if (input) input.value = '';
    return true;
  }""",
        """  function rejectIncompleteScannedBarcode(code) {
    code = normalizeScanCode(code);
    if (!isIncompleteOlanBarcode(code)) return false;
    // OLAN70 是系列碼，盤點要列出顏色卡給人點；已選 SKU 後掃描欄殘留同一碼不可再三聲。
    if (isOlanSeriesOnlyBarcode(code)) {
      if (selected && resolveSelectedSkuId(selected) && isCurrentStocktakeItemCode(code)) return false;
      if (currentTab === 'stocktake' || currentTab === 'lookup') return false;
    }
    beep(3);
    updateScanEcho(code, '條碼不完整：缺少 P成本＋色碼，未加入任何單據', 'error');
    setMessage('三聲：OLAN 條碼必須包含「P＋3位成本＋色碼」，例如 OLAN72P35096。本次未加入盤點或調撥單。', 'error');
    hardwareScanBuffer = '';
    if (input) input.value = '';
    return true;
  }""",
        'rejectIncomplete',
    )

    text = must_replace(
        text,
        """    var body = {
      action: action === 'stocktake' ? 'stocktake_entry' : action === 'stockin' ? 'quick_stockin' : 'transfer',
      skuId: selected.skuId,
      barcode: selected.barcode,
      qty: qty,""",
        """    var body = {
      action: action === 'stocktake' ? 'stocktake_entry' : action === 'stockin' ? 'quick_stockin' : 'transfer',
      skuId: resolveSelectedSkuId(selected),
      barcode: selected.barcode || selected.labelBarcode || selected.scannedBarcode || currentStocktakeScanCode || '',
      qty: qty,""",
        'submit-body',
    )

    if 'LZ_YGF_QTY_SAVE3_20260927' not in text:
        text += '\n/* LZ_YGF_QTY_SAVE3_20260927 OLAN70 完成不再三聲 */\n'
    return text


def patch_css(text):
    text = must_replace(
        text,
        """body.scanner-pda-layout .stocktake-fast-row > button.primary[data-fast-confirm-stocktake],
body.scanner-pda-layout .stocktake-fast-row > .done {
  display: flex !important;
  grid-column: 3 !important;
  grid-row: 1 / 3 !important;""",
        """body.scanner-pda-layout .stocktake-fast-row > .done {
  display: flex !important;
  grid-column: 3 !important;
  grid-row: 1 / 3 !important;""",
        'confirm-over-qty',
    )
    marker = '/* LZ_YGF_QTY_SAVE3_20260927 OLAN70 complete no triple-beep */'
    if marker not in text:
        text += """

""" + marker + """
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-alias-confirm] {
  display: none !important;
}
html body.scanner-pda-layout .stocktake-fast-row.is-current > button.primary[data-fast-confirm-stocktake]:not([data-fast-alias-confirm]) {
  display: flex !important;
  grid-column: 1 / -1 !important;
  grid-row: 10 !important;
  width: 100% !important;
  min-height: 54px !important;
  z-index: 8 !important;
  pointer-events: auto !important;
}
"""
    return text


def patch_html(text):
    text = text.replace('?v=20260927-ygf-qty-2', '?v=20260927-ygf-qty-3')
    text = text.replace('版本 v163', '版本 v164')
    if '20260927-ygf-qty-3' not in text or '版本 v164' not in text:
        raise SystemExit('html cache-bust failed')
    return text


def patch_api(text):
    old = "$index=null;foreach($skus as $i=>$sku)if((string)($sku['id']??$sku['sku']??'')===$skuId){$index=$i;break;}if($index===null)scanner_response(['ok'=>false,'error'=>'找不到 SKU'],404);"
    new = r"""$index=null;
foreach($skus as $i=>$sku){
    if((string)($sku['id']??$sku['sku']??'')===$skuId){$index=$i;break;}
}
if($index===null){
    $wantBarcode=scanner_norm((string)($payload['barcode']??''));
    $wantCode=scanner_norm((string)($payload['productCode']??''));
    foreach($skus as $i=>$sku){
        $codes=[(string)($sku['id']??''),(string)($sku['sku']??''),(string)($sku['barcode']??''),(string)($sku['companyBarcode']??'')];
        foreach($codes as $code){
            if($skuId!=='' && scanner_norm($code)===$skuId){$index=$i;break 2;}
            if($wantBarcode!=='' && scanner_norm($code)===$wantBarcode){
                if($warehouse==='' || sku_warehouse($sku)===$warehouse){$index=$i;break 2;}
                if($index===null)$index=$i;
            }
        }
        if($index!==null) continue;
        $product=$productMap[(string)($sku['productId']??'')]??[];
        $productCode=scanner_norm((string)($product['code']??$product['id']??''));
        if($wantCode!=='' && $productCode===$wantCode && ($warehouse==='' || sku_warehouse($sku)===$warehouse)){
            $index=$i;
            break;
        }
    }
}
if($index===null)scanner_response(['ok'=>false,'error'=>'找不到 SKU'],404);
if($skuId==='' ) $skuId=(string)($skus[$index]['id']??$skus[$index]['sku']??'');
/* LZ_YGF_QTY_SAVE3_20260927 */
"""
    return must_replace(text, old, new, 'sku-index')


def main():
    js_path = ROOT / 'scanner-ygf-v59.js.new-ygf-qty-3'
    css_path = ROOT / 'scanner-ygf-v59.css.new-ygf-qty-3'
    html_path = ROOT / 'scanner.html.new-ygf-qty-3'
    api_path = ROOT / 'scanner-api.php.new-ygf-qty-3'
    js_path.write_text(patch_js(js_path.read_text(encoding='utf-8')), encoding='utf-8')
    css_path.write_text(patch_css(css_path.read_text(encoding='utf-8')), encoding='utf-8')
    html_path.write_text(patch_html(html_path.read_text(encoding='utf-8')), encoding='utf-8')
    api_path.write_text(patch_api(api_path.read_text(encoding='utf-8')), encoding='utf-8')
    print('patched ok')


if __name__ == '__main__':
    main()
