const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-10', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-10', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-10', 'utf8');
const checks = [
  ['tab-only stocktake ui', js.includes("return tab === 'stocktake';") && !js.includes('offsetHeight > 0) return true')],
  ['choose never steals tab', !js.includes("if (isStocktakeUi() || currentTab === 'stocktake') {\n      currentTab = 'stocktake';")],
  ['transfer confirm first', js.includes("if (currentTab === 'transfer') {\n      choose(row, index, true, {keepPicker: true});")],
  ['transfer tap confirms', js.includes("if (currentTab === 'stocktake' || currentTab === 'transfer' || currentTab === 'stockin' || currentTab === 'arrival') usedKind = 'confirm';")],
  ['acceptMatchPick', js.includes('function acceptMatchPick(kind, sku)')],
  ['keep picker after add', js.includes('var stayRows = (allMatches || []).slice();')],
  ['再加入 label', js.includes("transferDraft.length ? '再加入調撥單' : '加入調撥單'")],
  ['autotest hook', js.includes("autotest=xfer") && js.includes('OLAN70P35592')],
  ['marker', js.includes('LZ_YGF_QTY_SAVE10_20260927')],
  ['v171', html.includes('版本 v171')],
  ['bust', html.includes('20260927-ygf-qty-10')],
  ['css dock transfer', css.includes('[data-current-scanner-tab="transfer"] [data-match-action-dock]')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
