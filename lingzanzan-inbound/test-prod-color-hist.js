const fs = require('fs');
const files = {
  html: process.argv[2] || '/tmp/lz-prod-color/admin-products.html',
  css: process.argv[3] || '/tmp/lz-prod-color/admin-products-horizontal-9.css',
  wh: process.argv[4] || '/tmp/lz-prod-color/admin-warehouse-authority-1.js',
  fc: process.argv[5] || '/tmp/lz-prod-color/admin-product-forwarder-cost-9.js',
};
const html = fs.readFileSync(files.html, 'utf8');
const css = fs.readFileSync(files.css, 'utf8');
const wh = fs.readFileSync(files.wh, 'utf8');
const fc = fs.readFileSync(files.fc, 'utf8');
const checks = [
  ['html-select', html.includes('data-new-color-preset')],
  ['html-blank', html.includes('可手動輸入其他顏色')],
  ['html-bust', html.includes('20260927-prod-color-hist-1')],
  ['html-matrix-kept', !html.includes('20260926-matrix-wh-1')],
  ['css-marker', css.includes('LZ_PROD_COLOR_HIST_20260927')],
  ['css-matrix-kept', css.includes('LZ_MATRIX_WH_20260926')],
  ['wh-marker', wh.includes('LZ_PROD_COLOR_HIST_20260927')],
  ['fc-marker', fc.includes('LZ_PROD_COLOR_HIST_20260927')],
  ['wh-select', wh.includes('data-new-color-preset')],
  ['fc-select', fc.includes('data-new-color-preset')],
  ['wh-history', wh.includes('productHistoryColorNames')],
  ['fc-history', fc.includes('productHistoryColorNames')],
  ['wh-remember', wh.includes('rememberProductHistoryColor(colorName)')],
  ['fc-remember', fc.includes('rememberProductHistoryColor(addingColor.name || colorName)')],
  ['wh-matrix-kept', wh.includes('LZ_MATRIX_WH_20260926')],
  ['fc-matrix-kept', fc.includes('LZ_MATRIX_WH_20260926')],
  ['wh-print-kept', wh.includes('LZ_SKU_PRINT_ADD_SIZE_20260926')],
  ['fc-print-kept', fc.includes('LZ_SKU_PRINT_ADD_SIZE_20260926')],
  ['fc-dup-kept', fc.includes('LZ_DUP_COLOR_20260926')],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));
