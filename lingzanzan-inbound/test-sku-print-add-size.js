'use strict';

function nextStocks(existingSizes, colors, sizeName, qty) {
  var sizes = existingSizes.slice();
  if (sizes.indexOf(sizeName) === -1) sizes.push(sizeName);
  var stocks = {};
  colors.forEach(function (color) {
    stocks[(color.name || '未設定顏色') + '||' + sizeName] = qty;
  });
  return { sizes: sizes, stocks: stocks };
}

function printCopies(qty) {
  return Math.min(99, Math.max(1, Math.floor(Number(qty || 1))));
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

var added = nextStocks(['S', 'M', 'L', 'XL', '2XL'], [{ name: '深藍' }], '3XL', 2);
assert('keeps old sizes', added.sizes, ['S', 'M', 'L', 'XL', '2XL', '3XL']);
assert('qty on new size', added.stocks, { '深藍||3XL': 2 });
assert('zero qty still adds size', nextStocks(['S'], [{ name: '深藍' }], 'M', 0).stocks, { '深藍||M': 0 });
assert('print copies from qty 1', printCopies(1), 1);
assert('print copies from qty 0 uses 1', printCopies(0), 1);
assert('print copies from qty 5', printCopies(5), 5);

if (failed) process.exit(1);
console.log('all passed');
