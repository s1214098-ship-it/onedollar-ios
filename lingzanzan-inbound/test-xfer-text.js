/* LZ_XFER_TEXT_20260926 smoke: transfer-draft cream/white fills exist. */
const fs = require('fs');
const css = fs.readFileSync(process.argv[2] || '/tmp/lz-xfer/scanner-ygf-v59.css', 'utf8');
const html = fs.readFileSync(process.argv[3] || '/tmp/lz-xfer/scanner.html', 'utf8');
const checks = [
  ['marker', css.includes('LZ_XFER_TEXT_20260926')],
  ['cream-clear', css.includes('#fff8ed')],
  ['heading-teal', css.includes('#1f6b63')],
  ['save-white', css.includes('transfer-save-button') && css.includes('#fff')],
  ['html-bust', html.includes('20260926-xfer-text-1')],
  ['xfer-select-kept', css.includes('LZ_XFER_SELECT_20260926')],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));
