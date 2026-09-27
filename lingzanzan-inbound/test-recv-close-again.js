'use strict';

function dismissOverlay(nodes) {
  return nodes.filter(function (node) {
    return !(node.attrs && node.attrs['data-freight-received-print-ready'] !== undefined);
  });
}

function closeButtons(html) {
  return (html.match(/data-freight-print-ready-close/g) || []).length;
}

var failed = 0;
function assert(name, actual, expected) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    failed += 1;
    console.error('FAIL', name, actual, expected);
  } else {
    console.log('ok', name);
  }
}

assert('overlay is removed on close', dismissOverlay([
  { attrs: { 'data-freight-received-print-ready': '' } },
  { attrs: { 'data-freight-item-form': '' } }
]), [
  { attrs: { 'data-freight-item-form': '' } }
]);

var html = '<header class="freight-received-print-head"><button data-freight-print-ready-close>關閉</button></header><div class="freight-received-print-actions"><button data-freight-print-ready-close>關閉</button></div>';
assert('header and footer both have close', closeButtons(html), 2);

if (failed) process.exit(1);
console.log('all passed');
