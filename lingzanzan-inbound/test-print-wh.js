'use strict';

function inventoryPrintWarehouseCode(raw) {
  var code = String(raw || '').trim().toUpperCase();
  if (code === 'TW' || code === 'CN' || code === 'ID') return code;
  if (/台灣|台湾|寶輝|宝辉/.test(String(raw || ''))) return 'TW';
  if (/中國|中国|東莞|东莞/.test(String(raw || ''))) return 'CN';
  if (/印尼/.test(String(raw || ''))) return 'ID';
  return '';
}

function inventoryStampPrintWarehouse(payload, warehouseCode) {
  var map = { TW: '台灣倉', CN: '中國倉', ID: '印尼倉' };
  warehouseCode = inventoryPrintWarehouseCode(warehouseCode) || 'TW';
  payload = payload && typeof payload === 'object' ? Object.assign({}, payload) : {};
  payload.warehouseCode = warehouseCode;
  payload.warehouse = map[warehouseCode];
  payload.warehouseName = map[warehouseCode];
  return payload;
}

var failed = 0;
function assert(name, actual, expected) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    failed += 1;
    console.error('FAIL', name, actual, expected);
  } else {
    console.log('ok', name);
  }
}

assert('china stock can still print taiwan location', inventoryStampPrintWarehouse({
  barcode: 'SE2599105P206',
  warehouse: '中國倉',
  warehouseCode: 'CN'
}, 'TW'), {
  barcode: 'SE2599105P206',
  warehouse: '台灣倉',
  warehouseCode: 'TW',
  warehouseName: '台灣倉'
});

assert('named china maps to CN', inventoryPrintWarehouseCode('中國東莞倉'), 'CN');
assert('blank falls back to TW', inventoryStampPrintWarehouse({}, '').warehouse, '台灣倉');

if (failed) process.exit(1);
console.log('all passed');
