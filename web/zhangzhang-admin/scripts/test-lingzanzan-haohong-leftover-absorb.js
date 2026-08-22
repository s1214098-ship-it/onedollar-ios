'use strict';

const assert = require('assert');
const {
  trackingKey,
  orderIsSigned,
  isFormalHaohongBatch,
  isHaohongLeftoverSheet,
  leftoverBatchTrackKeys,
  absorbLeftoverHaohongSheets,
} = require('./haohong-hourly-sync.js');

assert.strictEqual(orderIsSigned('已簽收完成'), true);
assert.strictEqual(orderIsSigned('已签收'), true);
assert.strictEqual(orderIsSigned('待签收'), false);
assert.strictEqual(orderIsSigned('未簽收'), false);
assert.strictEqual(orderIsSigned('配送中'), false);
assert.strictEqual(orderIsSigned('已入物流表'), false);

assert.strictEqual(isFormalHaohongBatch({ id: 'HAOHONG-134459', haohongOrderId: '134459' }), true);
assert.strictEqual(isFormalHaohongBatch({ id: 'BATCH-20260721-182769', provider: 'haohong' }), false);
assert.strictEqual(isHaohongLeftoverSheet({
  id: 'BATCH-20260721-182769',
  provider: 'haohong',
  forwarder: '豪鴻集運倉',
}), true);
assert.strictEqual(isHaohongLeftoverSheet({
  id: 'HAOHONG-134459',
  haohongOrderId: '134459',
  provider: 'haohong',
}), false);

const leftover = {
  id: 'BATCH-20260721-182769',
  status: '已入物流表',
  provider: 'haohong',
  forwarder: '豪鴻集運倉',
  itemCount: 0,
  packageRows: [],
  trackingNumbers: [],
  logisticsTrackingNo: '465465640238922',
  firstTrackingNo: '79019516166259',
};
const keys = leftoverBatchTrackKeys(leftover, []);
assert.ok(keys.has('79019516166259'));
assert.ok(keys.has('465465640238922'));
assert.strictEqual(trackingKey(' 79019516166259 '), '79019516166259');

const freight = {
  batches: [
    {
      id: 'HAOHONG-134459',
      batchNo: '134459',
      haohongOrderId: '134459',
      status: '已簽收完成',
      provider: 'haohong',
      trackingNumbers: ['79019516166259', '465465640238922'],
    },
    {
      id: 'HAOHONG-137727',
      batchNo: '137727',
      haohongOrderId: '137727',
      status: '配送中',
      provider: 'haohong',
      trackingNumbers: ['773436644528945'],
    },
    leftover,
    {
      id: 'BATCH-OPEN-KEEP',
      status: '已入物流表',
      provider: 'haohong',
      forwarder: '豪鴻集運倉',
      firstTrackingNo: 'NOT-ON-SIGNED-BATCH',
      logisticsTrackingNo: 'STILL-OPEN',
    },
  ],
  items: [
    { id: 'HAOHONG-PACKAGE-79019516166259', trackingNo: '79019516166259', batchId: 'HAOHONG-134459', progress: '已簽收完成' },
  ],
};

const result = absorbLeftoverHaohongSheets(freight);
assert.strictEqual(result.absorbedCount, 1);
assert.strictEqual(result.absorbed[0].leftoverId, 'BATCH-20260721-182769');
assert.strictEqual(result.absorbed[0].into, 'HAOHONG-134459');

const absorbed = freight.batches.find((row) => row.id === 'BATCH-20260721-182769');
assert.strictEqual(absorbed.status, '已簽收完成');
assert.strictEqual(absorbed.absorbedInto, 'HAOHONG-134459');
assert.ok(String(absorbed.note).indexOf('空殼舊表') !== -1);

const keep = freight.batches.find((row) => row.id === 'BATCH-OPEN-KEEP');
assert.strictEqual(keep.status, '已入物流表');

const unsigned = freight.batches.find((row) => row.id === 'HAOHONG-137727');
assert.strictEqual(unsigned.status, '配送中');

const again = absorbLeftoverHaohongSheets(freight);
assert.strictEqual(again.absorbedCount, 0);

console.log('haohong leftover absorb ok');
