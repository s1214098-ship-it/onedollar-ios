const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-12', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-12', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-12', 'utf8');
const checks = [
  ['unique helper', js.includes('function uniqueBarcodeRow(')],
  ['barcode match', js.includes('function rowBarcodeMatchesScan(')],
  ['hide picker unique', js.includes('hideColorPicker: !!uniqueRow')],
  ['no reselect transfer', js.includes("currentTab !== 'transfer' && currentTab !== 'stockin'")],
  ['dock stay', js.includes('function showTransferRepeatDock()')],
  ['dock kind', js.includes("acceptMatchPick('dock'")],
  ['autotest unique', js.includes('unique-barcode-did-not-auto-add')],
  ['autotest picker', js.includes('color-picker-still-open')],
  ['marker', js.includes('LZ_YGF_QTY_SAVE12_20260927')],
  ['v173', html.includes('版本 v173')],
  ['bust', html.includes('20260927-ygf-qty-12')],
  ['css hidden picker', css.includes('[data-match-color-picker][hidden]')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
