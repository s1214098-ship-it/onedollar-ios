'use strict';

const assert = require('assert');
const { mapStatus, isoDate, buildResults } = require('./apply-seven-official.js');

assert.strictEqual(mapStatus('已完成包裹配件'), 'delivered');
assert.strictEqual(mapStatus('已完成包裹取件'), 'delivered');
assert.strictEqual(mapStatus('包裹已送抵取件門市'), 'arrived_store');
assert.strictEqual(mapStatus('貨件配達取件門市'), 'arrived_store');
assert.strictEqual(mapStatus('包裹今日23:59後將退回物流中心'), 'arrived_store');
assert.strictEqual(mapStatus('包裹已退回物流中心'), 'returned');
assert.strictEqual(mapStatus('入帳成功'), 'in_transit');
assert.strictEqual(isoDate('2026/08/29'), '2026-08-29');
assert.strictEqual(isoDate('2026/8/9'), '2026-08-09');

const { results, skipped } = buildResults(
  [
    {
      trackingNo: 'E48244629063',
      statusMessage: '已完成包裹配件',
      recStore: '彰園',
      recDate: '2026/08/12 21:05',
      pickupDeadline: '2026/08/20'
    },
    {
      paymentNo: 'E77396429349',
      statusMessage: '包裹已送抵取件門市',
      recStore: '逢辰',
      recDate: '2026/08/21 15:29',
      pickupDeadline: '2026/08/29'
    },
    {
      trackingNo: 'E99999999999',
      statusMessage: '已完成包裹配件'
    }
  ],
  {
    items: [
      {
        key: 'k1',
        orderId: 'o1',
        carrierCode: 'seven',
        trackingNo: 'E48244629063',
        customerPrefix: 'Sunarjoi'
      },
      {
        key: 'k2',
        orderId: 'o2',
        carrierCode: 'seven',
        trackingNo: 'E77396429349',
        customerPrefix: 'Ika'
      }
    ]
  },
  'test'
);

assert.strictEqual(skipped.join(','), 'E99999999999');
assert.strictEqual(results.length, 2);
assert.strictEqual(results[0].suggestedStatus, 'delivered');
assert.strictEqual(results[0].officialPickupDeadline, '2026-08-20');
assert.strictEqual(results[0].sourceStatusText, '已完成包裹配件（彰園）');
assert.strictEqual(results[1].suggestedStatus, 'arrived_store');
assert.strictEqual(results[1].officialPickupDeadline, '2026-08-29');
assert.ok(!/2026-08-12/.test(results[0].officialPickupDeadline), 'must prefer pickupDeadline over recDate');

console.log(JSON.stringify({ ok: true, tests: 12 }));
