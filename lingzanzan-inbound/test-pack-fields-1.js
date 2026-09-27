const fs = require('fs');
const path = require('path');
const root = __dirname;
const html = fs.readFileSync(path.join(root, 'admin-freight.html.new-pack-fields-1'), 'utf8');
const css = fs.readFileSync(path.join(root, 'admin.css.new-pack-fields-1'), 'utf8');
const js = fs.readFileSync(path.join(root, 'admin.js.new-pack-fields-1'), 'utf8');

function assert(cond, msg) {
  if (!cond) throw new Error(msg);
}

assert(html.includes('data-freight-package-pool-grid'), 'grid host');
assert(html.includes('＋新增一格'), 'add cell');
assert(html.includes('textarea hidden'), 'hidden textarea');
assert(html.includes('20260927-pack-fields-1'), 'bust');
assert(!html.includes('rows="7"'), 'old textarea gone');
assert(css.includes('LZ_PACK_FIELDS_20260927'), 'css marker');
assert(css.includes('.freight-pack-slot'), 'slot css');
assert(js.includes('installFreightPackagePoolGrid'), 'install');
assert(js.includes('freightPackagePoolAddSlot'), 'add slot');
assert(js.includes('請先填物流單號（一格一個）'), 'toast');
console.log('ok pack-fields-1');
