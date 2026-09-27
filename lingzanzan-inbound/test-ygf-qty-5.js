const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-5', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-5', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/scanner-ygf-v59.css.new-ygf-qty-5', 'utf8');
const checks = [
  ['display', js.includes('function stocktakeDisplayBarcode')],
  ['clear', js.includes('function clearStocktakeCommitUi')],
  ['no overlay text', !js.includes('正在寫入盤點單…')],
  ['login guard', js.includes('請先登入盤點機，再掃品項')],
  ['v166', html.includes('版本 v166')],
  ['bust', html.includes('20260927-ygf-qty-5')],
  ['css', css.includes('never overlay')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
