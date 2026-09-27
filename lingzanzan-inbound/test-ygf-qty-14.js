const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-14', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-14', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-14', 'utf8');
const checks = [
  ['sync chrome', js.includes('function syncStocktakeOnlyChrome(')],
  ['save in flight', js.includes('var transferSaveInFlight = false')],
  ['save 90s', js.includes('}, 90000')],
  ['no leftover then', !/function saveTransferSlip\(\)[\s\S]*request\.then[\s\S]*request\.then/.test(js)],
  ['dock save', js.includes("if (kind === 'confirm' && transferDraft.length) saveTransferSlip()")],
  ['event save', js.includes("if (dockKind === 'confirm' && transferDraft.length) saveTransferSlip()")],
  ['no save beep3', js.includes('調撥單還沒寫入，庫存未變更') && !/function saveTransferSlip\(\)[\s\S]*beep\(3\)[\s\S]*function stockinDraftTotals/.test(js)],
  ['siblings', js.includes('function queueWantedTransferSiblings(') && js.includes('function isWantedTransferSiblingColor(')],
  ['fetch series', js.includes('function fetchTransferSeriesSiblings(')],
  ['autotest save', js.includes('pending-save') && js.includes('draft-not-cleared')],
  ['autotest pink', js.includes('pink-not-auto-added')],
  ['marker', js.includes('LZ_YGF_QTY_SAVE14_20260927')],
  ['v175', html.includes('版本 v175')],
  ['bust', html.includes('20260927-ygf-qty-14')],
  ['css save dock', css.includes('底部主按鈕是儲存整張調撥單')],
  ['css dock hidden', css.includes('[data-match-action-dock][hidden]')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
