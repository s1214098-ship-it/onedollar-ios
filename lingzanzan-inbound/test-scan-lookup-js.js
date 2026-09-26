'use strict';
var fs = require('fs');
var path = require('path');
var src = fs.readFileSync(path.join(__dirname, 'scanner-ygf-v59.js'), 'utf8');
if (src.indexOf('LZ_SCAN_LOOKUP_20260924') < 0) throw new Error('js marker missing');
if (/return \/\^OLAN\/i\.test\(code\) && !\/\^OLAN\[0-9\]\+P\[0-9\]\{3\}/.test(src)) {
  throw new Error('old incomplete OLAN regex still present');
}

function normalizeScanCode(value) {
  return String(value == null ? '' : value).replace(/[^0-9A-Za-z]/g, '').toUpperCase();
}
function isIncompleteOlanBarcode(code) {
  code = normalizeScanCode(code);
  if (!/^OLAN/i.test(code)) return false;
  if (/^OLAN\d+$/i.test(code)) return true;
  if (/^OLAN\d{1,4}P\d{1,3}$/i.test(code)) return true;
  return false;
}
function scannedCodeMode(code) {
  var normalized = String(code || '').trim().toUpperCase().replace(/\s+/g, '');
  if (!normalized) return 'series';
  if (/^[A-Z]+\d+C[A-Z0-9]+S[A-Z0-9]+P\d+$/.test(normalized)) return 'full';
  if (/^[A-Z]+\d+P\d+C[A-Z0-9]+S[A-Z0-9]+$/.test(normalized)) return 'full';
  if (/^[A-Z]+\d{4,}P\d+$/.test(normalized)) return 'full';
  var productCode = (normalized.match(/^([A-Z]+\d+)P\d/) || normalized.match(/^[A-Z]+\d+/) || [normalized])[1] || normalized.match(/^[A-Z]+\d+/)[0];
  return productCode && productCode !== normalized && normalized.indexOf(productCode + 'P') === 0 ? 'full' : 'series';
}

function assert(cond, msg) { if (!cond) throw new Error(msg); }

assert(isIncompleteOlanBarcode('OLAN72') === true, 'OLAN72 series');
assert(isIncompleteOlanBarcode('OLAN72P350') === true, 'OLAN72P350 cost only');
assert(isIncompleteOlanBarcode('OLAN66P34098') === false, 'old OLAN66');
assert(isIncompleteOlanBarcode('OLAN939024P338') === false, 'new P-at-end');
assert(isIncompleteOlanBarcode('OLAN71-93-NO-SIZE') === false, 'ugly olan71');
assert(isIncompleteOlanBarcode('OLAN72P350923') === false, 'legacy olan72');
assert(isIncompleteOlanBarcode('JA3309404P430') === false, 'non-olan');
assert(scannedCodeMode('TOY00392P149') === 'full', 'toy pend full');
assert(scannedCodeMode('OLAN939024P338') === 'full', 'olan pend full');
assert(scannedCodeMode('K406P137C904S4') === 'full', 'v2 full');
assert(scannedCodeMode('OLAN66') === 'series', 'series');
console.log('js ok');
