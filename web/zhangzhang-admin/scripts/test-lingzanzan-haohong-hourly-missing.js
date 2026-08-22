'use strict';

const assert = require('assert');
const { trackingKey, trackingsMissingHaohongWeight } = require('./haohong-hourly-sync.js');

assert.strictEqual(trackingKey(' 7902620993352 '), '7902620993352');

const missing = trackingsMissingHaohongWeight([
  { trackingNo: 'OLD', billedWeightKg: 0, logisticsSource: 'haohong', batchId: 'HAOHONG-1' },
  { trackingNo: '7902620993352', billedWeightKg: 0, logisticsSource: 'haohong', progress: '到貨待分類', issueType: '待分類' },
  { trackingNo: 'ALREADY', billedWeightKg: 1.2, haohongWeightUpdatedAt: '2026-08-22T00:10:51+08:00', logisticsSource: 'haohong' },
  { trackingNo: 'SELF', logisticsSource: 'self_pickup', billedWeightKg: 0 },
  { trackingNo: 'CACHED', billedWeightKg: 0, logisticsSource: 'haohong' },
], new Set(['CACHED']));

assert.deepStrictEqual(missing[0], '7902620993352');
assert.deepStrictEqual(missing, ['7902620993352', 'OLD']);
console.log('haohong hourly missing-weight lookup ok');
