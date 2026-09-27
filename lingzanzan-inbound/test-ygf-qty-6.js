const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-6', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-6', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-6', 'utf8');
const checks = [
  ['confirm 確定', js.includes(">' + (submittingNow ? '正在寫入…' : '確定') + '</button>")],
  ['rowActions keep confirm', js.includes('rowActions') && js.includes('confirmBtn + extraActions')],
  ['no empty submitting ternary', !js.includes("(stocktakeAutoSubmitting || stocktakeCommitUi) ? ''")],
  ['print still present', js.includes('data-print-stocktake-label')],
  ['saved qty helper', js.includes('function stocktakeSavedQty')],
  ['first scan is 1', js.includes('var nextQty = alreadySaved > 0 ? alreadySaved + 1 : 1')],
  ['no max1 plus one', !js.includes('Math.max(1, countedStocktakeQtyFor(selected, code)) + 1')],
  ['commit ui blocks rescan', js.includes('if (stocktakeCommitUi || stocktakeAutoSubmitting)')],
  ['v167', html.includes('版本 v167')],
  ['bust', html.includes('20260927-ygf-qty-6')],
  ['css confirm primary', css.includes('確定 stays primary')],
  ['css print last', css.includes('grid-row: 12 !important')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
