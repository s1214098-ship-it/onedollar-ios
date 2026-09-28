const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/admin.js.new-recv-print-1', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/admin.css.new-recv-print-1', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/admin-freight.html.new-recv-print-1', 'utf8');
const checks = [
  ['fallback fn', js.includes('function freightOtherWarehouseFallbackPrintPayload(')],
  ['fallback use', js.split('freightOtherWarehouseFallbackPrintPayload(').length >= 4],
  ['picker over overlay', js.includes("data-inventory-label-purpose-picker") && js.includes('z-index:2147483647')],
  ['print overlay z', js.includes("data-inventory-barcode-print-overlay") && js.includes("overlay.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647")],
  ['js marker', js.includes('LZ_RECV_PRINT_Z_20260928')],
  ['css picker', css.includes('.inventory-label-purpose-picker') && css.includes('z-index: 2147483647')],
  ['css overlay force', css.includes('LZ_RECV_PRINT_Z_20260928') && css.includes('z-index:2147483647!important')],
  ['html css bust', html.includes('admin.css?v=20260928-recv-print-1')],
  ['html js bust', html.includes('admin.js?v=20260928-recv-print-1')],
  ['still has inbound print', js.includes('data-freight-print-received') && js.includes('立即列印本次條碼')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
