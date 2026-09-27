/* Live sidecar excerpt. Marker: LZ_WH_BARCODE_20260927
 * Canonical warehouse barcode: {編號}{色碼}{尺碼}P{成本}. Never C/S.
 * Receiving prints one SN per piece (cost-tail + unit index) and a warehouse location.
 */
function composeLatestLabelBarcode(productCode, cost, colorCode, sizeCode) {
  var base = String(productCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  var color = String(colorCode || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  var size = String(sizeCode || '00').toUpperCase().replace(/[^A-Z0-9]/g, '') || '00';
  if (size === 'NOSIZE' || size === 'NOSIZE') size = '00';
  var costPart = String(Math.max(0, Math.round(Number(cost || 0))));
  if (!base || !color || costPart === '0') return '';
  return base + color + size + 'P' + costPart;
}

function inventoryLabelSerialLast6(printBarcode, unitIndex) {
  var unit = Math.max(1, Math.floor(Number(unitIndex || 1)));
  var costTail = (String(printBarcode || '').toUpperCase().match(/P(\d+)$/) || ['', '000'])[1];
  return String(costTail).slice(-3).padStart(3, '0') + String(unit).padStart(3, '0');
}
