const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-11', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-11', 'utf8');
const checks = [
  ['next unadded', js.includes('function nextUnaddedTransferRow()')],
  ['dock next color', js.includes('function pickTransferFromDock()')],
  ['tap no two-beep', js.includes("if (fromTap)") && js.includes('已在本次調撥單')],
  ['fromTap confirm', js.includes("queueTransfer(row, row.scannedBarcode || row.barcode || row.skuId || '', { fromTap: true })")],
  ['already label', js.includes("transferDraftHasRow(row) ? '已加入'")],
  ['autotest dock', js.includes('dock-did-not-add-next')],
  ['marker', js.includes('LZ_YGF_QTY_SAVE11_20260927')],
  ['v172', html.includes('版本 v172')],
  ['bust', html.includes('20260927-ygf-qty-11')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
