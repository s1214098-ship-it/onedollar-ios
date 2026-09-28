const fs = require('fs');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/admin.js.new-recv-print-1', 'utf8');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/admin.css.new-recv-print-1', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/admin-freight.html.new-recv-print-1', 'utf8');
const php = fs.readFileSync('/workspace/lingzanzan-inbound/admin-freight-search-api.php.new-recv-print-1', 'utf8');
const checks = [
  ['fallback fn', js.includes('function freightOtherWarehouseFallbackPrintPayload(')],
  ['fallback use', js.split('freightOtherWarehouseFallbackPrintPayload(').length >= 4],
  ['picker over overlay', js.includes("data-inventory-label-purpose-picker") && js.includes('z-index:2147483647')],
  ['print overlay z', js.includes("data-inventory-barcode-print-overlay") && js.includes("overlay.setAttribute('style', 'position:fixed;inset:0;z-index:2147483647")],
  ['js marker', js.includes('LZ_RECV_PRINT_Z_20260928')],
  ['lookup marker', js.includes('LZ_RECV_LOOKUP_20260928')],
  ['collect matches', js.includes('function freightReceivingCollectMatches(')],
  ['server hits', js.includes('function fetchFreightReceivingServerHits(')],
  ['aliases supplemental', js.includes('item.supplementalTrackingNo')],
  ['catalog timeout', js.includes('function freightReceivingPromiseTimeout(')],
  ['force open lookup', js.includes('forceOpen: true')],
  ['print click skip overlay', js.includes('[data-freight-print-received]') && js.includes('data-inventory-label-purpose-picker')],
  ['extra sku checkbox', js.includes('data-freight-receiving-extra-sku')],
  ['thumb zoom', js.includes('data-freight-receiving-zoom')],
  ['vendor shortage', js.includes('vendorShortages') && js.includes('此規格沒到／廠商沒發')],
  ['css picker', css.includes('.inventory-label-purpose-picker') && css.includes('z-index: 2147483647')],
  ['css overlay force', css.includes('LZ_RECV_PRINT_Z_20260928') && css.includes('z-index:2147483647!important')],
  ['css lookup', css.includes('LZ_RECV_LOOKUP_20260928')],
  ['html css bust', html.includes('admin.css?v=20260928-recv-color-1')],
  ['html js bust', html.includes('admin.js?v=20260928-recv-color-1')],
  ['plus one chip', js.includes('data-freight-receiving-plus-one')],
  ['extra local add', js.includes('function addFreightReceivingExtraForecastLocal(')],
  ['no leftover write hint', !js.includes('要增加其他顏色／尺寸，直接點下面就會新增一列')],
  ['html lookup placeholder', html.includes('集運號碼')],
  ['php supplemental', php.includes('supplementalTrackingNo') && php.includes('LZ_RECV_LOOKUP_20260928')],
  ['php no extra brace', !php.includes('array\n{ /* LZ_RECV_LOOKUP_20260928 include split/supplemental tracking */\n{')],
  ['still has inbound print', js.includes('data-freight-print-received') && js.includes('立即列印本次條碼')],
];
const failed = checks.filter(([, ok]) => !ok).map(([n]) => n);
if (failed.length) { console.error('FAIL', failed.join(',')); process.exit(1); }
console.log('ok');
