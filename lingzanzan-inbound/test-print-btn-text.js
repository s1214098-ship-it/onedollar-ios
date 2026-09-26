'use strict';

function labelFor(inputs) {
  var enabled = inputs.filter(function (input) { return !input.disabled; });
  if (!enabled.length) return '無可列印';
  var allOn = enabled.every(function (input) { return input.checked; });
  return allOn ? '取消全選' : '全選列印';
}

var failed = 0;
function assert(name, actual, expected) {
  if (actual !== expected) {
    failed += 1;
    console.error('FAIL', name, actual, expected);
  } else {
    console.log('ok', name, actual);
  }
}

assert('none printable', labelFor([{ disabled: true, checked: false }]), '無可列印');
assert('all off', labelFor([{ disabled: false, checked: false }, { disabled: false, checked: false }]), '全選列印');
assert('all on', labelFor([{ disabled: false, checked: true }, { disabled: false, checked: true }]), '取消全選');
assert('mixed', labelFor([{ disabled: false, checked: true }, { disabled: false, checked: false }]), '全選列印');

if (failed) process.exit(1);
console.log('all passed');
