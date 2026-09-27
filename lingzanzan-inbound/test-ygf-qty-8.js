const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-8', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-8', 'utf8');
const checks = [
  ['leftover window', js.includes('lastStocktakeCommitAt && Date.now() - lastStocktakeCommitAt < 2500')],
  ['picker leftover', js.includes('var openSeries = normalizeScanCode')],
  ['no loc beep', js.includes('stocktakeCommitUi || stocktakeAutoSubmitting || isCurrentStocktakeItemCode(code)')],
  ['multi exact 1', js.includes("setMessage('一聲：請點正確顏色／尺寸，選定後會寫入本次盤點。', 'ok')")],
  ['skip start', js.includes('var ready = Promise.resolve();')],
  ['no catch 2 stocktake', !js.includes("兩聲：顏色已選，這件還沒寫進盤點單")],
  ['v169', html.includes('版本 v169')],
  ['bust', html.includes('20260927-ygf-qty-8')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
