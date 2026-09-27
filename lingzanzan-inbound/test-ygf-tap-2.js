const fs = require('fs');
const path = require('path');
const root = __dirname;
const js = fs.readFileSync(path.join(root, 'scanner-ygf-v59.js.new-ygf-tap-2'), 'utf8');
const css = fs.readFileSync(path.join(root, 'scanner-ygf-v59.css.new-ygf-tap-2'), 'utf8');
const html = fs.readFileSync(path.join(root, 'scanner.html.new-ygf-tap-2'), 'utf8');

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

assert(js.includes('LZ_YGF_TAP2_20260927'), 'js marker');
assert(js.includes('function isStocktakeUi'), 'isStocktakeUi');
assert(js.includes('hideMatchPickerNow'), 'hide picker now');
assert(js.includes("submit('stocktake')"), 'submit');
assert(!js.includes("'pointerup', 'touchend', 'touchstart'"), 'no touchstart consume');
assert(css.includes('LZ_YGF_TAP2_20260927'), 'css marker');
assert(html.includes('版本 v161'), 'v161');
assert(html.includes('20260927-ygf-tap-2'), 'bust');
console.log('ok ygf-tap-2');
