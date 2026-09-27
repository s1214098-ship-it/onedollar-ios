/* Node checks for OLAN70 complete-save helpers (mirrors scanner-ygf-v59.js). */
function normalizeScanCode(value) {
  return String(value || '').toUpperCase().replace(/[^A-Z0-9-]/g, '').trim();
}
function isIncompleteOlanBarcode(code) {
  code = normalizeScanCode(code);
  if (!/^OLAN/i.test(code)) return false;
  if (/^OLAN\d+$/i.test(code)) return true;
  if (/^OLAN\d{1,4}P\d{1,3}$/i.test(code)) return true;
  return false;
}
function isOlanSeriesOnlyBarcode(code) {
  return /^OLAN\d+$/i.test(normalizeScanCode(code));
}
function resolveSelectedSkuId(row) {
  row = row || {};
  return String(row.skuId || row.sku || row.id || '').trim();
}
function isCurrentStocktakeItemCode(code, selected, currentStocktakeScanCode) {
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
function rejectIncomplete(code, currentTab, selected) {
  code = normalizeScanCode(code);
  if (!isIncompleteOlanBarcode(code)) return false;
  if (isOlanSeriesOnlyBarcode(code)) {
    if (selected && resolveSelectedSkuId(selected) && isCurrentStocktakeItemCode(code, selected, 'OLAN70')) return false;
    if (currentTab === 'stocktake' || currentTab === 'lookup') return false;
  }
  return true;
}

var selected = { skuId: 'OLAN70P35592', productCode: 'OLAN70', barcode: 'OLAN709200P355', scannedBarcode: 'OLAN70' };
var fails = [];
function assert(name, cond) { if (!cond) fails.push(name); }

assert('series incomplete flag', isIncompleteOlanBarcode('OLAN70') === true);
assert('p-cost incomplete', isIncompleteOlanBarcode('OLAN70P355') === true);
assert('full color not incomplete', isIncompleteOlanBarcode('OLAN70P35592') === false);
assert('old sticker not incomplete', isIncompleteOlanBarcode('OLAN70-906-NO-SIZE') === false);
assert('stocktake series no reject', rejectIncomplete('OLAN70', 'stocktake', null) === false);
assert('selected leftover no reject', rejectIncomplete('OLAN70', 'stocktake', selected) === false);
assert('transfer series still reject', rejectIncomplete('OLAN70', 'transfer', null) === true);
assert('p-cost still reject', rejectIncomplete('OLAN70P355', 'stocktake', selected) === true);
assert('same series leftover', isCurrentStocktakeItemCode('OLAN70', selected, 'OLAN70') === true);
assert('sku leftover', isCurrentStocktakeItemCode('OLAN70P35592', selected, 'OLAN70') === true);
assert('other product', isCurrentStocktakeItemCode('PAN001', selected, 'OLAN70') === false);
assert('resolve sku', resolveSelectedSkuId(selected) === 'OLAN70P35592');
assert('resolve fallback', resolveSelectedSkuId({ sku: 'X1' }) === 'X1');

if (fails.length) {
  console.error('FAIL', fails.join(', '));
  process.exit(1);
}
console.log('ok', Object.keys(selected).length, 'olan70 complete guards');
