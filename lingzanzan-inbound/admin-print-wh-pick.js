/* LZ_PRINT_WH_PICK_20260927
   列印倉庫可跟現有庫存倉分開。貨還在中國倉時，也能先印台灣倉標。 */
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

function inventoryPrintWarehouseButtonsHtml(selected) {
  selected = inventoryPrintWarehouseCode(selected) || 'TW';
  return [
    { code: 'TW', name: '台灣倉' },
    { code: 'CN', name: '中國倉' },
    { code: 'ID', name: '印尼倉' }
  ].map(function (row) {
    return '<button type="button" class="inventory-print-warehouse-btn' + (row.code === selected ? ' is-active' : '') + '" data-inventory-print-warehouse="' + row.code + '">' + row.name + '</button>';
  }).join('');
}
