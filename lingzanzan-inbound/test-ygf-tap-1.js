const fs = require('fs');
const path = require('path');
const root = __dirname;
const js = fs.readFileSync(path.join(root, 'scanner-ygf-v59.js.new-ygf-tap-1'), 'utf8');
const css = fs.readFileSync(path.join(root, 'scanner-ygf-v59.css.new-ygf-tap-1'), 'utf8');
const html = fs.readFileSync(path.join(root, 'scanner.html.new-ygf-tap-1'), 'utf8');
const swap = fs.readFileSync(path.join(root, 'swap-ygf-tap-1.php'), 'utf8');

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

assert(js.includes('LZ_YGF_TAP_20260927'), 'js marker');
assert(js.includes('data-match-action-dock'), 'dock');
assert(js.includes('window.__lzYgfPick'), 'global pick');
assert(js.includes('加入本次盤點</button>'), 'card button label');
assert(js.includes("submit('stocktake')"), 'confirm submits stocktake');
assert(js.includes('function eventElement'), 'text node fix');
assert(js.includes('btn.ontouchend = btn.onclick'), 'direct button bind');
assert(!js.includes('event.target = el'), 'no readonly target assign');
assert(css.includes('LZ_YGF_TAP_20260927'), 'css marker');
assert(css.includes('[data-match-action-dock]'), 'dock css');
assert(css.includes('z-index: 2147483647'), 'dock on top');
assert(html.includes('版本 v160'), 'version badge');
assert(html.includes('20260927-ygf-tap-1'), 'cache bust');
assert(swap.includes('ygf-tap-swap-20260927-1'), 'swap key');
assert(!js.includes('確定帶入</button>'), 'old confirm label gone');
console.log('ok ygf-tap-1');
