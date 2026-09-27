'use strict';

function adjustedExpectedQty(expectedQty, actualQty) {
  return Math.max(Number(expectedQty || 0), Number(actualQty || 0));
}

function applyOverageExpected(expected, actual, voided) {
  expected = Number(expected || 0);
  actual = voided ? 0 : Number(actual || 0);
  if (!voided && expected < actual) expected = actual;
  return { expected: expected, actual: actual, difference: actual - expected };
}

function bumpVariantQty(variantQty, variantActual) {
  variantQty = Math.max(1, Number(variantQty || 1));
  variantActual = Math.max(0, Number(variantActual || 0));
  if (variantActual > variantQty) variantQty = variantActual;
  return variantQty;
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

assert('already arrived 1 plus inspect 1 raises expected to 2', adjustedExpectedQty(1, 2), 2);
assert('under-receipt keeps original expected', adjustedExpectedQty(4, 3), 4);
assert('php overage makes difference 0 so inbound can finish', applyOverageExpected(1, 2, false), {
  expected: 2,
  actual: 2,
  difference: 0
});
assert('voided line does not raise expected', applyOverageExpected(3, 5, true), {
  expected: 3,
  actual: 0,
  difference: -3
});
assert('variant quantity follows extra pieces received', bumpVariantQty(1, 2), 2);
assert('variant quantity does not shrink on shortage', bumpVariantQty(4, 2), 4);

if (failed) process.exit(1);
console.log('all passed');
