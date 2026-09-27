const fs = require('fs');
const path = require('path');
const dir = __dirname;
const js = fs.readFileSync(path.join(dir, 'scanner-ygf-v59.js.new-xfer-stay-1'), 'utf8');
const css = fs.readFileSync(path.join(dir, 'scanner-ygf-v59.css.new-xfer-stay-1'), 'utf8');
const html = fs.readFileSync(path.join(dir, 'scanner.html.new-xfer-stay-1'), 'utf8');
const checks = {
  'js keeps transfer tab': js.includes('lingzanzan-scanner-tab-v1'),
  'js ignores rescan while picker open': js.includes('同一條碼不會再叮'),
  'js pick by sku': js.includes('data-match-sku'),
  'js same-tab no reset': js.includes('var sameTab = currentTab === tab'),
  'css kills transfer overlay': css.includes('data-current-scanner-tab="transfer"'),
  'html cache bust': html.includes('20260927-xfer-stay-1')
};
const failed = Object.entries(checks).filter(([, ok]) => !ok).map(([name]) => name);
if (failed.length) {
  console.error('FAIL', failed.join(', '));
  process.exit(1);
}
console.log('ygf xfer-stay checks passed');
