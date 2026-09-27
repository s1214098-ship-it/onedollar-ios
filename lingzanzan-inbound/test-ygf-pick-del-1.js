const fs = require('fs');
const path = require('path');
const dir = __dirname;
const js = fs.readFileSync(path.join(dir, 'scanner-ygf-v59.js.new-pick-del-1'), 'utf8');
const css = fs.readFileSync(path.join(dir, 'scanner-ygf-v59.css.new-pick-del-1'), 'utf8');
const api = fs.readFileSync(path.join(dir, 'scanner-api.php.new-pick-del-1'), 'utf8');
const html = fs.readFileSync(path.join(dir, 'scanner.html.new-pick-del-1'), 'utf8');
const checks = {
  'js has confirm button': js.includes('data-match-confirm'),
  'js keeps picker': js.includes('keepPicker'),
  'js does not disable match cards': !js.includes('card.disabled = true'),
  'js allows deleting own stocktake': js.includes('只能刪除') || js.includes('ownSession'),
  'css kills transform overlay': css.includes('LZ_YGF_PICK_DEL_20260927'),
  'api archives approved stocktake': api.includes('LZ_YGF_PICK_DEL_20260927') && api.includes('inventoryUnchanged'),
  'html cache bust': html.includes('20260927-pick-del-1')
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(', '));
  process.exit(1);
}
console.log('ygf pick-del checks passed');
