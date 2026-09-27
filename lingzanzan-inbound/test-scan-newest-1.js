const fs = require('fs');
const path = require('path');
const dir = __dirname;
const js = fs.readFileSync(path.join(dir, 'scanner-ygf-v59.js.new-scan-newest-1'), 'utf8');
const css = fs.readFileSync(path.join(dir, 'scanner-ygf-v59.css.new-scan-newest-1'), 'utf8');
const html = fs.readFileSync(path.join(dir, 'scanner.html.new-scan-newest-1'), 'utf8');
const php = fs.readFileSync(path.join(dir, 'scanner-api.php.new-scan-newest-1'), 'utf8');
const checks = {
  'js newest-first unshift': js.includes('transferDraft.unshift({'),
  'js promote duplicate': js.includes('existingLine.lastScannedAt'),
  'js touch pick': js.includes("['pointerup', 'touchend']"),
  'js match helper': js.includes('function handleMatchPickFromEvent'),
  'js own-session newest': js.includes('scanInstant(b.line.scannedAt)'),
  'js restore newest': js.includes('scanInstant((b || {}).scannedAt)'),
  'js keeps transfer tab': js.includes('lingzanzan-scanner-tab-v1'),
  'css kills all overlay': css.includes('is-match-picker-open'),
  'html cache bust': html.includes('20260927-scan-newest-2'),
  'html v159': html.includes('版本 v159'),
  'js qty without location': js.includes('不必先選貨架'),
  'html add qty button': html.includes('加入本次盤點數量'),
  'php unshift stocktake': php.includes("array_unshift($sessions[$found]['lines'],$line)")
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(', '));
  process.exit(1);
}
console.log('ygf scan-newest checks passed');
