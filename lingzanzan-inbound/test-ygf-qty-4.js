const fs = require('fs');
const js = fs.readFileSync(process.argv[2] || '/workspace/lingzanzan-inbound/scanner-ygf-v59.js.new-ygf-qty-4', 'utf8');
const html = fs.readFileSync(process.argv[3] || '/workspace/lingzanzan-inbound/scanner.html.new-ygf-qty-4', 'utf8');
const checks = [
  ['commit', js.includes('function commitStocktakeSelection')],
  ['autosave unique', js.includes('commitStocktakeSelection(autoRow')],
  ['write label', js.includes('寫入這件盤點')],
  ['no dock 加入本次盤點', !js.includes("data-match-dock-confirm>加入本次盤點")],
  ['no card 加入本次盤點 button', !js.includes('>加入本次盤點</button></span></article>')],
  ['no 完成這一件', !js.includes('完成這一件')],
  ['send sheet', html.includes('整張盤點單送主管')],
  ['v165', html.includes('版本 v165')],
  ['bust', html.includes('20260927-ygf-qty-4')],
  ['warehouse exact', js.includes('warehouseExact')],
  ['auto beep 1', js.includes('options.auto || options.fromScan')],
];
const failed = checks.filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(','));
  process.exit(1);
}
console.log('ok', checks.map(([name]) => name).join(','));
