const fs = require('fs');
const path = require('path');
const dir = __dirname;
const css = fs.readFileSync(path.join(dir, 'admin.css.new-recv-row-1'), 'utf8');
const freight = fs.readFileSync(path.join(dir, 'admin-freight.html.new-recv-row-1'), 'utf8');
const inv = fs.readFileSync(path.join(dir, 'admin-inventory.html.new-recv-row-1'), 'utf8');
const checks = {
  'css marker': css.includes('LZ_RECV_ROW_20260927'),
  'css flex row': css.includes('flex-direction: row !important'),
  'css full width actions': css.includes('grid-column: 1 / -1 !important'),
  'freight bust': freight.includes('20260927-recv-row-1'),
  'inventory bust': inv.includes('20260927-recv-row-1')
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(', '));
  process.exit(1);
}
console.log('recv-row checks passed');
