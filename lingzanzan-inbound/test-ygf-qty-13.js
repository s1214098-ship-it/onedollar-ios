const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-13', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-13', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-13', 'utf8');
const checks = [
  ['sync chrome', js.includes('function syncStocktakeOnlyChrome(')],
  ['no tab steal', js.includes("if (currentTab === 'transfer')") && js.includes('queueTransfer(row, row.scannedBarcode')],
  ['finish transfer msg', js.includes('這 ') && js.includes('項是調撥，不是盤點')],
  ['title 調撥', js.includes("tab === 'transfer' ? '商品調撥'")],
  ['autotest actions', js.includes('stocktake-actions-on-transfer')],
  ['autotest save', js.includes('transfer-save-blocked')],
  ['marker', js.includes('LZ_YGF_QTY_SAVE13_20260927')],
  ['v174', html.includes('版本 v174')],
  ['bust', html.includes('20260927-ygf-qty-13')],
  ['css hide session', css.includes('調撥頁不要出現盤點送出')],
  ['css dock hidden', css.includes('[data-match-action-dock][hidden]')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
