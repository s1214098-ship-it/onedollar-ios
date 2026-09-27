const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-7', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-7', 'utf8');
const checks = [
  ['abort helper', js.includes('function abortStocktakeLookups')],
  ['pick writes', js.includes('if (currentTab === \'stocktake\') {\n      abortStocktakeLookups();\n      confirmMatchPick(pickRow, pickIndex);')],
  ['stale classify', js.includes('if (currentTab === \'stocktake\' && (stocktakeCommitUi || stocktakeAutoSubmitting)) return;')],
  ['lookup gen', js.includes('var lookupGen = lookupGeneration')],
  ['lookup catch keep picker', js.includes('allMatches && allMatches.length) || selected')],
  ['start then entry', js.includes('startStocktake(false).catch(function () { return null; })')],
  ['stocktake catch 2', js.includes('顏色已選，這件還沒寫進盤點單')],
  ['post 20s', js.includes('timeoutMs || 20000')],
  ['v168', html.includes('版本 v168')],
  ['bust', html.includes('20260927-ygf-qty-7')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
