'use strict';

/**
 * Recheck Mia(Miaandi) BYORDER-20260816-595720 POST 29566000104374.
 * Official 2026-08-19: still 入帳成功（口湖郵局 2026/08/12 14:10:53）— origin accept, NOT 投遞成功.
 * Keep 配送中. Do not touch eno 29566200104374.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const ORDER_ID = 'BYORDER-20260816-595720';
const TRACKING = '29566000104374';
const FORBIDDEN = '29566200104374';
const PHONE = '0903143874';
const OFFICIAL = '入帳成功（口湖郵局 2026/08/12 14:10:53）';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-mia-post/recheck-result.json';

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
  const tmp = file + '.tmp-mia-' + Date.now();
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

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.items)) return wrap.items;
  return [];
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || '').replace(/\D/g, '');
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const iso = nowIsoTaipei();
const ordersFile = path.join(dataDir, 'orders.json');
const stateFile = path.join(dataDir, 'admin-state.json');
const queueFile = path.join(dataDir, 'carrier-tracking-queue.json');
const backups = {
  orders: backup(ordersFile, 'mia-post-recheck'),
  state: backup(stateFile, 'mia-post-recheck'),
  queue: fs.existsSync(queueFile) ? backup(queueFile, 'mia-post-recheck') : null
};

const ordersWrap = readJson(ordersFile);
const orders = listOf(ordersWrap);
const order = orders.find((o) => String(o.id || '') === ORDER_ID);
if (!order) throw new Error('order missing');
if (phoneOf(order) !== PHONE) throw new Error('phone mismatch');
if (String(order.trackingNo || '') !== TRACKING) throw new Error('tracking mismatch ' + order.trackingNo);
if (String(order.trackingNo || '') === FORBIDDEN) throw new Error('refusing forbidden tracking');
if (String(order.status || '') === 'delivered' || String(order.deliveryState || '') === 'delivered') {
  throw new Error('already delivered; refusing to rewrite');
}

order.externalStatus = OFFICIAL;
order.updatedAt = iso;
if (!Array.isArray(order.outboundParcels) || !order.outboundParcels.length) {
  order.outboundParcels = [{ trackingNo: TRACKING, carrier: 'POST 郵局' }];
}
order.outboundParcels[0].trackingNo = TRACKING;
order.outboundParcels[0].officialStatus = OFFICIAL;
order.outboundParcels[0].officialStatusText = OFFICIAL;
order.outboundParcels[0].deliveryState = 'in_transit';
order.outboundParcels[0].lastCheckedAt = iso;
order.deliveryState = 'in_transit';
order.status = 'shipped';
order.statusLabel = '已出貨配送';
if (!Array.isArray(order.shippingEventHistory)) order.shippingEventHistory = [];
order.shippingEventHistory.unshift({
  at: iso,
  actor: '管理者',
  summary: '官方再查｜POST 郵局｜' + TRACKING + '｜' + OFFICIAL + '／不是投遞成功，維持配送中',
  source: 'manual-post-official-recheck'
});
const noteLine = '[' + iso.replace('T', ' ').slice(0, 19) + '] 中華郵政官網再查 ' + TRACKING + ' 仍是入帳成功（口湖郵局 2026/08/12 14:10:53）。入帳成功是收寄局已收件，不是投遞成功，所以維持配送中。';
if (String(order.shippingNote || '').indexOf('口湖郵局 2026/08/12 14:10:53') === -1) {
  order.shippingNote = (String(order.shippingNote || '').trim() + '\n' + noteLine).trim();
}

if (Array.isArray(ordersWrap)) writeJson(ordersFile, orders);
else {
  ordersWrap.orders = orders;
  writeJson(ordersFile, ordersWrap);
}

const state = readJson(stateFile);
if (Array.isArray(state.orders)) {
  const si = state.orders.findIndex((o) => String(o.id || '') === ORDER_ID);
  if (si >= 0) {
    const so = state.orders[si];
    if (phoneOf(so) === PHONE && String(so.trackingNo || '') === TRACKING) {
      so.externalStatus = OFFICIAL;
      so.deliveryState = 'in_transit';
      so.status = 'shipped';
      so.statusLabel = '已出貨配送';
      so.updatedAt = iso;
      if (!Array.isArray(so.outboundParcels) || !so.outboundParcels.length) {
        so.outboundParcels = [{ trackingNo: TRACKING, carrier: 'POST 郵局' }];
      }
      so.outboundParcels[0].officialStatus = OFFICIAL;
      so.outboundParcels[0].deliveryState = 'in_transit';
      so.outboundParcels[0].lastCheckedAt = iso;
      writeJson(stateFile, state);
    }
  }
}

if (fs.existsSync(queueFile)) {
  const queue = readJson(queueFile);
  const items = Array.isArray(queue) ? queue : (queue.items || []);
  const row = items.find((x) => String(x.trackingNo || '') === TRACKING && String(x.orderId || '') === ORDER_ID);
  if (row) {
    row.checkedAt = iso;
    row.lastOfficialRecheckAt = iso;
    row.suggestedStatus = 'in_transit';
    row.sourceStatusText = OFFICIAL;
    row.message = '2026-08-19 官網再查：仍只有入帳成功（口湖郵局），不是投遞成功，維持配送中';
    row.verificationSource = '中華郵政 國內快捷/掛號/包裹公開查件';
    writeJson(queueFile, queue);
  }
}

const eno = orders.find((o) => String(o.id || '') === 'BYORDER-20260720-60F1AD');
if (eno && String(eno.trackingNo || '') !== FORBIDDEN) throw new Error('eno tracking changed');

const result = {
  ok: true,
  iso,
  backups,
  after: {
    id: order.id,
    status: order.status,
    statusLabel: order.statusLabel,
    deliveryState: order.deliveryState,
    trackingNo: order.trackingNo,
    externalStatus: order.externalStatus
  }
};
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify(result.after));
