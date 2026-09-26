const fs = require('fs');
const files = {
  wh: process.argv[2] || '/tmp/lz-wh/admin-warehouse-authority-1.js',
  fc: process.argv[3] || '/tmp/lz-wh/admin-product-forwarder-cost-9.js',
  css: process.argv[4] || '/tmp/lz-wh/admin-products-horizontal-9.css',
  html: process.argv[5] || '/tmp/lz-wh/admin-products.html',
};
const wh = fs.readFileSync(files.wh, 'utf8');
const fc = fs.readFileSync(files.fc, 'utf8');
const css = fs.readFileSync(files.css, 'utf8');
const html = fs.readFileSync(files.html, 'utf8');
const checks = [
  ['wh-marker', wh.includes('LZ_MATRIX_WH_20260926')],
  ['fc-marker', fc.includes('LZ_MATRIX_WH_20260926')],
  ['wh-select', wh.includes('data-matrix-inbound-warehouse')],
  ['fc-select', fc.includes('data-matrix-inbound-warehouse')],
  ['wh-print-kept', wh.includes('LZ_SKU_PRINT_ADD_SIZE_20260926')],
  ['fc-print-kept', fc.includes('LZ_SKU_PRINT_ADD_SIZE_20260926')],
  ['css', css.includes('LZ_MATRIX_WH_20260926')],
  ['html', html.includes('20260926-matrix-wh-1')],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));
