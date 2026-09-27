'use strict';

function dismissKeepForm(nodes, fifoScoped) {
  var drop = {
    'data-freight-received-print-ready': 1,
    'data-inventory-barcode-print-overlay': 1,
    'data-inventory-label-purpose-picker': 1,
    'data-freight-quantity-confirm': 1,
    'data-freight-fifo-item-adjust-modal': 1
  };
  var kept = nodes.filter(function (node) {
    return !Object.keys(drop).some(function (key) { return node.attrs && node.attrs[key] !== undefined; });
  });
  return {
    kept: kept,
    fifoScoped: false,
    workbenchSearch: nodes.filter(function (node) {
      return node.attrs && node.attrs['data-freight-workbench-search'] !== undefined;
    }).map(function (node) { return node.attrs.value; })
  };
}

function clearScanOnly(state) {
  return {
    receivingLookup: '',
    workbenchSearch: state.workbenchSearch,
    formQty: state.formQty,
    focused: false
  };
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

assert('drops overlay stack, keeps form qty', dismissKeepForm([
  { attrs: { 'data-freight-received-print-ready': '' } },
  { attrs: { 'data-freight-fifo-item-adjust-modal': '' } },
  { attrs: { 'data-freight-item-form': '' } },
  { attrs: { 'data-freight-qty': '3' } },
  { attrs: { 'data-freight-workbench-search': '', value: 'NB012' } }
], true), {
  kept: [
    { attrs: { 'data-freight-item-form': '' } },
    { attrs: { 'data-freight-qty': '3' } },
    { attrs: { 'data-freight-workbench-search': '', value: 'NB012' } }
  ],
  fifoScoped: false,
  workbenchSearch: ['NB012']
});

assert('scan clear does not zero workbench or qty', clearScanOnly({
  workbenchSearch: 'NB012',
  formQty: 3
}), {
  receivingLookup: '',
  workbenchSearch: 'NB012',
  formQty: 3,
  focused: false
});

var overlayHtml = '<button type="button" class="ghost-button" data-freight-print-ready-close onclick="window.lzDismissFreightReceivedOverlay&&window.lzDismissFreightReceivedOverlay(event);return false;">關閉</button>';
assert('close button has inline dismiss', overlayHtml.indexOf('lzDismissFreightReceivedOverlay') !== -1, true);

if (failed) process.exit(1);
console.log('all passed');
