'use strict';

function composeLatestLabelBarcode(productCode, cost, colorCode, sizeCode) {
  var base = String(productCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  var color = String(colorCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  var size = String(sizeCode || '00').toUpperCase().replace(/[^A-Z0-9]/g, '') || '00';
  if (size === 'NOSIZE') size = '00';
  var costPart = String(Math.max(0, Math.round(Number(cost || 0))));
  if (!base || !color || costPart === '0') return '';
  return base + color + size + 'P' + costPart;
}

function inventoryLabelSerialLast6(printBarcode, unitIndex) {
  var unit = Math.max(1, Math.floor(Number(unitIndex || 1)));
  var costTail = (String(printBarcode || '').toUpperCase().match(/P(\d+)$/) || ['', '000'])[1];
  return String(costTail).slice(-3).padStart(3, '0') + String(unit).padStart(3, '0');
}

var failed = 0;
function assert(name, actual, expected) {
  if (actual !== expected) {
    failed += 1;
    console.error('FAIL', name, actual, expected);
  } else {
    console.log('ok', name);
  }
}

assert('warehouse concat', composeLatestLabelBarcode('TA176', 430, '93', '00'), 'TA1769300P430');
assert('no C/S letters', composeLatestLabelBarcode('TA176', 430, '93', '00').indexOf('C93') === -1, true);
assert('serial piece 1', inventoryLabelSerialLast6('TA1769300P430', 1), '430001');
assert('serial piece 2', inventoryLabelSerialLast6('TA1769300P430', 2), '430002');
assert('keep alias shape distinct', composeLatestLabelBarcode('TA176', 430, '93', '00') === 'TA176C93S00P430', false);

if (failed) process.exit(1);
console.log('all passed');
