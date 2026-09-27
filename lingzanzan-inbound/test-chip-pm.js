'use strict';

function qtyButtonLabel(isMinus, rawText) {
  return isMinus ? '－' : '＋';
}

function chipTagName(useDiv) {
  return useDiv ? 'div' : 'button';
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

assert('plus is glyph not 增加數量', qtyButtonLabel(false, '增加數量'), '＋');
assert('minus is glyph', qtyButtonLabel(true, '減一件'), '－');
assert('chips are not buttons', chipTagName(true), 'div');

if (failed) process.exit(1);
console.log('all passed');
