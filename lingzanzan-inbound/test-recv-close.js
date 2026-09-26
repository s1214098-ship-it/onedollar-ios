'use strict';

function closeStack(nodes) {
  var keep = [];
  var drop = {
    'data-freight-received-print-ready': 1,
    'data-freight-receiving-workbench': 1,
    'data-freight-quantity-confirm': 1,
    'data-inventory-barcode-print-overlay': 1,
    'data-inventory-label-purpose-picker': 1
  };
  nodes.forEach(function (node) {
    var gone = Object.keys(drop).some(function (key) { return node.attrs && node.attrs[key] !== undefined; });
    if (!gone) keep.push(node);
  });
  return keep;
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

var leftover = closeStack([
  { attrs: { 'data-freight-received-print-ready': '' } },
  { attrs: { 'data-freight-receiving-workbench': '' } },
  { attrs: { 'data-inventory-barcode-print-overlay': '' } },
  { attrs: { 'data-inventory-label-purpose-picker': '' } },
  { attrs: { 'data-admin-toast': '' } }
]);
assert('drops overlay stack, keeps toast', leftover, [{ attrs: { 'data-admin-toast': '' } }]);

assert('empty stack', closeStack([]), []);

if (failed) process.exit(1);
console.log('all passed');
