'use strict';

/**
 * Official lookup that was skipped after the 18:00 leftover list:
 * - 7-11 E44309162258 / KODARIYAH / BYORDER-20260731-F2689D
 *   SHOPMORE: 已完成包裹取件 2026/08/13 19:55 at 永冠 (取貨截止 2026/08/19).
 *   Mapped delivered via apply-seven-official.js. Do not re-apply here.
 * - KTJ 74385626926 / Aya(Qin Qin) / BYORDER-20260819-724E32
 *   嘉里大榮官網: 路線空結果；低溫零擔「查不到該筆資料」.
 *   apply-official-generic previously defaulted unknown text to in_transit.
 *   This script restores Aya from the pre-sync backup and writes official_no_data.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const ORDER_ID = 'BYORDER-20260819-724E32';
const TRACKING = '74385626926';
const KEY = 'ktj|74385626926|AY';
const PHONE = '0903259800';
const BACKUP_ORDERS = path.join(dataDir, 'audit', 'carrier-delivery-sync', '20260820034918', 'orders.json');
const GENERIC = path.join(root, 'scripts', 'apply-official-generic.js');
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-outline/aya-ktj-revert-result.json';

function nowIsoTaipei() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Taipei',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  }).formatToParts(new Date());
  const get = (t) => parts.find((p) => p.type === t).value;
  return get('year') + '-' + get('month') + '-' + get('day') + 'T' + get('hour') + ':' + get('minute') + ':' + get('second') + '+08:00';
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function writeJson(file, value) {
  const json = JSON.stringify(value, null, 2) + '\n';
  const tmp = file + '.tmp-aya-' + Date.now();
  fs.writeFileSync(tmp, json);
  try {
    fs.renameSync(tmp, file);
  } catch (e) {
    try { fs.copyFileSync(tmp, file); } finally { try { fs.unlinkSync(tmp); } catch (_) {} }
  }
}

function backup(file, tag) {
  const dir = path.join(dataDir, 'audit');
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + Date.now());
  fs.copyFileSync(file, dest);
  return dest;
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || '').replace(/\D/g, '');
}

if (!fs.existsSync(dataDir)) {
  console.log(JSON.stringify({ ok: false, error: 'Not on PHT-SR' }));
  process.exit(0);
}

const iso = nowIsoTaipei();
const ordersFile = path.join(dataDir, 'orders.json');
const stateFile = path.join(dataDir, 'admin-state.json');
const queueFile = path.join(dataDir, 'carrier-tracking-queue.json');
const backups = {
  orders: backup(ordersFile, 'aya-ktj-nodata'),
  state: backup(stateFile, 'aya-ktj-nodata'),
  queue: backup(queueFile, 'aya-ktj-nodata'),
};

const restoredFrom = readJson(BACKUP_ORDERS);
const restored = restoredFrom.find((row) => row && row.id === ORDER_ID);
if (!restored) throw new Error('backup missing ' + ORDER_ID);
if (phoneOf(restored) !== PHONE) throw new Error('backup phone mismatch');
if (String(restored.trackingNo || (restored.customer && restored.customer.trackingNo) || '') !== TRACKING) {
  throw new Error('backup tracking mismatch');
}

const orders = readJson(ordersFile);
const oi = orders.findIndex((row) => row && row.id === ORDER_ID);
if (oi < 0) throw new Error('live missing ' + ORDER_ID);
const live = orders[oi];
if (phoneOf(live) !== PHONE) throw new Error('live phone mismatch');
if (String(live.status) === 'delivered' || String(live.deliveryState) === 'delivered') {
  throw new Error('refuse to overwrite delivered order');
}

const before = {
  status: live.status,
  statusLabel: live.statusLabel,
  deliveryState: live.deliveryState,
  shippedAt: live.shippedAt || '',
  officialStatus: (live.outboundParcels && live.outboundParcels[0] && live.outboundParcels[0].officialStatus) || live.externalStatus || '',
};

orders[oi] = restored;
if (String(restored.status) === 'shipped' || String(restored.deliveryState) === 'in_transit') {
  throw new Error('backup itself already shipped; abort');
}
writeJson(ordersFile, orders);

const state = readJson(stateFile);
if (Array.isArray(state.orders)) {
  const si = state.orders.findIndex((row) => row && row.id === ORDER_ID);
  if (si >= 0) state.orders[si] = restored;
  state.updatedAt = iso;
  writeJson(stateFile, state);
}

const queue = readJson(queueFile);
const qi = (queue.items || []).findIndex((row) => row && row.key === KEY);
if (qi < 0) throw new Error('queue missing ' + KEY);
const row = queue.items[qi];
if (row.orderId !== ORDER_ID || row.trackingNo !== TRACKING || row.customerPrefix !== 'AY' || row.carrierCode !== 'ktj') {
  throw new Error('queue identity mismatch');
}
row.checkedAt = iso;
row.suggestedStatus = '';
row.resultType = 'official_no_data';
row.manualRequired = true;
row.identityVerified = false;
row.verificationSource = '嘉里大榮官網公開查件';
row.sourceStatusText = '查不到該筆資料（路線空結果／低溫零擔）2026/08/20 03:45:36';
row.officialPickupDeadline = '';
row.message = '官方查無資料，單號留在每日必查';
row.deliveryAppliedAt = '';
row.deliveryAppliedOrderId = '';
row.deliveryAppliedOrderIds = [];
row.deliveryAppliedStatus = '';
queue.lastOfficialResultAt = iso;
writeJson(queueFile, queue);

let mapperPatched = false;
if (fs.existsSync(GENERIC)) {
  const src = fs.readFileSync(GENERIC, 'utf8');
  const old = "if (/無資料|查無資料|查無/.test(t)) return { suggested: '', type: 'official_no_data' };";
  const neu = "if (/無資料|查無資料|查無|查不到|找不到資料|找不到/.test(t)) return { suggested: '', type: 'official_no_data' };";
  if (src.includes(old) && !src.includes('查不到|找不到資料|找不到')) {
    backup(GENERIC, 'map-nodata');
    fs.writeFileSync(GENERIC, src.replace(old, neu));
    mapperPatched = true;
  } else if (src.includes('查不到|找不到資料|找不到')) {
    mapperPatched = 'already';
  }
}

const verifyOrders = readJson(ordersFile);
const verify = verifyOrders.find((row) => row && row.id === ORDER_ID);
const verifyQueue = (readJson(queueFile).items || []).find((r) => r && r.key === KEY);
const result = {
  ok: true,
  orderId: ORDER_ID,
  trackingNo: TRACKING,
  before,
  after: {
    status: verify.status,
    statusLabel: verify.statusLabel,
    deliveryState: verify.deliveryState,
    shippedAt: verify.shippedAt || '',
  },
  queue: {
    resultType: verifyQueue.resultType,
    suggestedStatus: verifyQueue.suggestedStatus,
    sourceStatusText: verifyQueue.sourceStatusText,
    deliveryAppliedAt: verifyQueue.deliveryAppliedAt || '',
  },
  mapperPatched,
  backups,
};
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2) + '\n');
console.log(JSON.stringify(result, null, 2));
