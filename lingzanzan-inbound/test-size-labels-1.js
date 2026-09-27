const fs = require('fs');
const css = fs.readFileSync('/workspace/lingzanzan-inbound/admin.css.new-size-labels-1', 'utf8');
const js = fs.readFileSync('/workspace/lingzanzan-inbound/admin.js.new-size-labels-1', 'utf8');
const html = fs.readFileSync('/workspace/lingzanzan-inbound/admin-freight.html.new-size-labels-1', 'utf8');
const checks = {
  cssMarker: css.includes('LZ_SIZE_LABELS_20260927'),
  cssName: css.includes('.freight-size-matrix-name'),
  cssFill: css.includes('-webkit-text-fill-color: #0f3d36 !important'),
  jsName: js.includes('freight-size-matrix-name'),
  jsLabel: js.includes('data-size-label'),
  htmlBust: html.includes('20260927-size-labels-1'),
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([k]) => k);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', Object.keys(checks).join(','));
