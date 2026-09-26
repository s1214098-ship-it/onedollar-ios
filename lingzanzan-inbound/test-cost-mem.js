const fs = require('fs');
const js = fs.readFileSync(process.argv[2] || '/tmp/lz-cost/inventory-freight-entry-20260810.js', 'utf8');
const html = fs.readFileSync(process.argv[3] || '/tmp/lz-cost/admin-inventory-entry.html', 'utf8');
const checks = [
  ['marker', js.includes('LZ_COST_MEM_20260926')],
  ['remember', js.includes('function rememberInboundCost')],
  ['apply', js.includes('cost = rememberedInboundCost()')],
  ['input', js.includes("rememberInboundCost(event.target.value)")],
  ['color-kept', js.includes('LZ_COLOR_MEM_20260926')],
  ['html-bust', html.includes('20260926-cost-mem-1')],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));
