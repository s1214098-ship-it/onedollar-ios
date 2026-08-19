'use strict';

/**
 * Mark Weni Nmr BYORDER-20260720-4F4559 as delivered.
 * Does NOT touch eno BYORDER-20260720-60F1AD / 29566200104374.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260720-4F4559';
const FORBIDDEN_ID = 'BYORDER-20260720-60F1AD';
const PHONE = '0981940224';
const FORBIDDEN_TRACKING = '29566200104374';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-weni-delivered/result.json';

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

function atomicWrite(file, data) {
  const json = JSON.stringify(data, null, 2) + '\n';
  const tmp = file + '.tmp-weni-' + process.pid + '-' + Date.now();
  fs.writeFileSync(tmp, json);
  try {
    fs.renameSync(tmp, file);
  } catch (e) {
    try {
      fs.copyFileSync(tmp, file);
    } finally {
      try { fs.unlinkSync(tmp); } catch (_) {}
    }
  }
}

function backup(file, tag) {
  const dir = path.join(dataDir, 'audit');
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + Date.now());
  fs.copyFileSync(file, dest);
  return dest;
}

function listRef(wrap) {
  if (Array.isArray(wrap)) return { list: wrap, kind: 'array' };
  if (wrap && Array.isArray(wrap.orders)) return { list: wrap.orders, kind: 'orders' };
  if (wrap && Array.isArray(wrap.inquiries)) return { list: wrap.inquiries, kind: 'inquiries' };
  if (wrap && Array.isArray(wrap.rows)) return { list: wrap.rows, kind: 'rows' };
  if (wrap && Array.isArray(wrap.items)) return { list: wrap.items, kind: 'items' };
  throw new Error('no list in ' + typeof wrap);
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || row.customerPhone || '').replace(/\D+/g, '');
}

function nameOf(row) {
  const c = (row && row.customer) || {};
  return String(c.name || row.customerName || row.name || '');
}

function markDelivered(row, iso, note) {
  if (String(row.id || '') === FORBIDDEN_ID) throw new Error('refusing to touch forbidden order');
  if (String(row.trackingNo || '') === FORBIDDEN_TRACKING) throw new Error('refusing to write forbidden tracking onto this row');
  row.status = 'delivered';
  row.statusLabel = '已完成配送';
  row.deliveryState = 'delivered';
  row.externalStatus = '管理者確認已簽收成功';
  row.internalStatus = 'manual_delivered_no_tracking';
  if (!row.deliveredAt) row.deliveredAt = iso;
  row.receivedAt = row.deliveredAt;
  row.updatedAt = iso;
  row.checkedAt = iso;
  row.needsSalesNotice = false;
  row.noticeType = '';
  row.salesNotice = '';
  row.trackingOptionalReason = '管理者確認客人已收，查無正確物流單號';
  row.indonesiaTrackingRequired = false;
  row.manualDelivered = true;
  row.manualDeliveredReason = '管理者確認已收，查無單號';
  if (!row.shippedAt) row.shippedAt = iso;
  if (!Array.isArray(row.progressHistory)) row.progressHistory = [];
  row.progressHistory.unshift({
    at: iso,
    status: 'delivered',
    statusLabel: '已完成配送',
    deliveryState: 'delivered',
    carrier: row.shippingCarrier || 'POST 郵局',
    trackingNo: '',
    note: note,
    source: 'manual-manager-confirm'
  });
  row.progressHistory = row.progressHistory.slice(0, 20);
  if (!Array.isArray(row.shippingEventHistory)) row.shippingEventHistory = [];
  row.shippingEventHistory.unshift({
    at: iso,
    actor: '管理者',
    summary: note,
    source: 'manual-delivered-no-tracking'
  });
  const extra = '\n' + note;
  row.shippingNote = String(row.shippingNote || '').indexOf('管理者確認客人已收') === -1
    ? (String(row.shippingNote || '').trim() + extra).trim()
    : row.shippingNote;
  return row;
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const iso = nowIsoTaipei();
const note = '管理者確認客人已收貨（Weni Nmr／0981940224）。查無正確物流單號；原誤掛 29566200104374 已改掛 eno BYORDER-20260720-60F1AD。手動標已簽收成功。' + iso;

const ordersFile = path.join(dataDir, 'orders.json');
const stateFile = path.join(dataDir, 'admin-state.json');
const inquiriesFile = path.join(dataDir, 'inquiries.json');

const backups = {
  orders: backup(ordersFile, 'weni-delivered'),
  state: backup(stateFile, 'weni-delivered'),
  inquiries: fs.existsSync(inquiriesFile) ? backup(inquiriesFile, 'weni-delivered') : null
};

const ordersWrap = readJson(ordersFile);
const ordersRef = listRef(ordersWrap);
const order = ordersRef.list.find((o) => String(o.id || '') === TARGET_ID);
if (!order) throw new Error('order not found');
if (phoneOf(order) !== PHONE) throw new Error('phone mismatch ' + phoneOf(order));
if (String(nameOf(order)).toLowerCase().indexOf('weni') === -1) throw new Error('name mismatch ' + nameOf(order));
if (String(order.trackingNo || '') === FORBIDDEN_TRACKING) throw new Error('target still has forbidden tracking');

const before = {
  id: order.id,
  status: order.status,
  deliveryState: order.deliveryState,
  trackingNo: order.trackingNo || '',
  phone: phoneOf(order),
  name: nameOf(order)
};

markDelivered(order, iso, note);
atomicWrite(ordersFile, ordersWrap);

const stateWrap = readJson(stateFile);
if (Array.isArray(stateWrap.orders)) {
  const stateOrder = stateWrap.orders.find((o) => String(o.id || '') === TARGET_ID);
  if (stateOrder) {
    if (phoneOf(stateOrder) !== PHONE) throw new Error('state phone mismatch');
    markDelivered(stateOrder, iso, note);
    atomicWrite(stateFile, stateWrap);
  }
}

let inquiryTouched = false;
if (fs.existsSync(inquiriesFile)) {
  const inqWrap = readJson(inquiriesFile);
  const inqRef = listRef(inqWrap);
  const inq = inqRef.list.find((x) => String(x.id || '') === TARGET_ID);
  if (inq && phoneOf(inq) === PHONE) {
    inq.updatedAt = iso;
    inq.adminNote = ((inq.adminNote || '') + '\n' + note).trim();
    if (inq.tracking && typeof inq.tracking === 'object') {
      inq.tracking.status = 'cleared';
      inq.tracking.cleared = true;
      inq.tracking.trackingNo = '';
    }
    inquiryTouched = true;
    atomicWrite(inquiriesFile, inqWrap);
  }
}

const verifyWrap = readJson(ordersFile);
const verifyRef = listRef(verifyWrap);
const verify = verifyRef.list.find((o) => String(o.id || '') === TARGET_ID);
const forbidden = verifyRef.list.find((o) => String(o.id || '') === FORBIDDEN_ID);
if (!verify || verify.status !== 'delivered' || verify.deliveryState !== 'delivered') {
  throw new Error('verify failed for target');
}
if (forbidden && (forbidden.status === 'delivered' || String(forbidden.trackingNo || '') !== FORBIDDEN_TRACKING)) {
  throw new Error('forbidden order changed unexpectedly');
}

const result = {
  ok: true,
  iso,
  backups,
  before,
  after: {
    id: verify.id,
    status: verify.status,
    statusLabel: verify.statusLabel,
    deliveryState: verify.deliveryState,
    trackingNo: verify.trackingNo || '',
    deliveredAt: verify.deliveredAt,
    receivedAt: verify.receivedAt,
    phone: phoneOf(verify),
    name: nameOf(verify)
  },
  forbiddenStill: {
    id: forbidden && forbidden.id,
    status: forbidden && forbidden.status,
    deliveryState: forbidden && forbidden.deliveryState,
    trackingNo: forbidden && (forbidden.trackingNo || '')
  },
  inquiryTouched
};

fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify(result.after));
