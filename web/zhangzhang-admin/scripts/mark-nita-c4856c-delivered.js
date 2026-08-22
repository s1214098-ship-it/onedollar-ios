'use strict';

/**
 * Mark Nita oktavita sari LINE BYORDER-20260809-C4856C as delivered.
 * Sales confirmed the customer received the goods. No FamilyMart tracking number.
 *
 * Does NOT mark paid (COD NT$460 stays unpaid unless finance records it).
 * Does NOT touch BYORDER-20260720-078596 / 16346546308.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260809-C4856C';
const FORBIDDEN_ID = 'BYORDER-20260720-078596';
const FORBIDDEN_TRACKING = '16346546308';
const PHONE = '0972956651';
const TOTAL = 460;
const STORE = '021558';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-nita/delivered-result.json';
const REASON = '業務確認客戶已收到；本單不建全家物流單號';

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
  const tmp = file + '.tmp-nita-del-' + process.pid + '-' + Date.now();
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
  throw new Error('no orders list');
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || row.customerPhone || '').replace(/\D+/g, '');
}

function nameOf(row) {
  const c = (row && row.customer) || {};
  return String(c.name || row.customerName || row.name || '');
}

function addressOf(row) {
  const c = (row && row.customer) || {};
  return String(row.address || c.address || c.storeAddress || '');
}

function snapshot(row) {
  if (!row) return null;
  return {
    id: row.id,
    name: nameOf(row),
    phone: phoneOf(row),
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState,
    trackingNo: row.trackingNo || '',
    trackingOptional: row.trackingOptional,
    trackingOptionalReason: row.trackingOptionalReason || '',
    paid: row.paymentSummary && row.paymentSummary.status,
    paidAmount: row.paymentSummary && row.paymentSummary.paidAmount,
    balance: row.paymentSummary && row.paymentSummary.balance,
    total: row.total,
    deliveredAt: row.deliveredAt,
    receivedAt: row.receivedAt,
    address: addressOf(row)
  };
}

function markDelivered(row, iso, note) {
  if (String(row.id || '') === FORBIDDEN_ID) throw new Error('refusing to touch forbidden order');
  if (String(row.trackingNo || '') === FORBIDDEN_TRACKING) throw new Error('refusing to write forbidden tracking onto this row');
  if (phoneOf(row) !== PHONE) throw new Error('phone mismatch ' + phoneOf(row));
  if (String(nameOf(row)).toLowerCase().indexOf('nita oktavita sari') === -1) {
    throw new Error('name mismatch ' + nameOf(row));
  }
  if (Number(row.total) !== TOTAL) throw new Error('total mismatch ' + row.total);
  if (addressOf(row).indexOf(STORE) === -1) throw new Error('store mismatch ' + addressOf(row));
  if (String(row.trackingNo || '').trim()) throw new Error('target has tracking ' + row.trackingNo);

  row.status = 'delivered';
  row.statusLabel = '已完成配送';
  row.deliveryState = 'delivered';
  row.externalStatus = '業務確認客戶已收到';
  row.internalStatus = 'manual_delivered_no_tracking';
  row.trackingNo = '';
  row.trackingOptional = true;
  row.trackingOptionalReason = REASON;
  row.indonesiaTrackingRequired = false;
  row.manualDelivered = true;
  row.manualDeliveredReason = '業務確認客戶已收到，不建全家單號';
  if (!row.deliveredAt) row.deliveredAt = iso;
  row.receivedAt = row.deliveredAt;
  row.updatedAt = iso;
  row.checkedAt = iso;
  row.needsSalesNotice = false;
  row.noticeType = '';
  row.salesNotice = '';
  if (!row.shippedAt) row.shippedAt = iso;
  if (!Array.isArray(row.outboundParcels)) row.outboundParcels = [];
  row.outboundParcels = row.outboundParcels.filter((p) => String(p && p.trackingNo || '').trim());
  if (!Array.isArray(row.progressHistory)) row.progressHistory = [];
  row.progressHistory.unshift({
    at: iso,
    status: 'delivered',
    statusLabel: '已完成配送',
    deliveryState: 'delivered',
    carrier: row.shippingCarrier || '全家',
    trackingNo: '',
    note: note,
    source: 'manual-sales-confirm-received'
  });
  row.progressHistory = row.progressHistory.slice(0, 20);
  if (!Array.isArray(row.shippingEventHistory)) row.shippingEventHistory = [];
  row.shippingEventHistory.unshift({
    at: iso,
    actor: '管理者',
    summary: note,
    source: 'manual-delivered-no-tracking'
  });
  if (String(row.shippingNote || '').indexOf('業務確認客戶已收到') === -1) {
    row.shippingNote = (String(row.shippingNote || '').trim() + '\n' + note).trim();
  }
  return row;
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const iso = nowIsoTaipei();
const note = '業務確認客戶已收到（Nita oktavita sari LINE／0972956651／全家龜山善捷店 021558／貨到付款 NT$460）。不建全家物流單號。未改收款。' + iso;

const ordersFile = path.join(dataDir, 'orders.json');
const stateFile = path.join(dataDir, 'admin-state.json');
const backups = {
  orders: backup(ordersFile, 'nita-delivered'),
  state: backup(stateFile, 'nita-delivered')
};

const ordersWrap = readJson(ordersFile);
const ordersRef = listRef(ordersWrap);
const order = ordersRef.list.find((o) => String(o.id || '') === TARGET_ID);
if (!order) throw new Error('order not found');
const before = snapshot(order);
const paidBefore = {
  status: order.paymentSummary && order.paymentSummary.status,
  paidAmount: order.paymentSummary && order.paymentSummary.paidAmount,
  balance: order.paymentSummary && order.paymentSummary.balance
};
markDelivered(order, iso, note);
atomicWrite(ordersFile, ordersWrap);

const stateWrap = readJson(stateFile);
if (Array.isArray(stateWrap.orders)) {
  const stateOrder = stateWrap.orders.find((o) => String(o.id || '') === TARGET_ID);
  if (stateOrder) {
    markDelivered(stateOrder, iso, note);
    atomicWrite(stateFile, stateWrap);
  }
}

const verifyWrap = readJson(ordersFile);
const verifyRef = listRef(verifyWrap);
const verify = verifyRef.list.find((o) => String(o.id || '') === TARGET_ID);
const forbidden = verifyRef.list.find((o) => String(o.id || '') === FORBIDDEN_ID);
if (!verify || verify.status !== 'delivered' || verify.deliveryState !== 'delivered') {
  throw new Error('verify failed for target');
}
if (String(verify.trackingNo || '') !== '') throw new Error('tracking was filled');
if (verify.paymentSummary && verify.paymentSummary.status !== paidBefore.status) {
  throw new Error('payment status changed');
}
if (Number(verify.paymentSummary && verify.paymentSummary.paidAmount) !== Number(paidBefore.paidAmount || 0)) {
  throw new Error('paidAmount changed');
}
if (!forbidden || String(forbidden.trackingNo || '') !== FORBIDDEN_TRACKING) {
  throw new Error('forbidden order tracking changed');
}
if (forbidden.status === 'delivered') throw new Error('forbidden order marked delivered');

const result = {
  ok: true,
  iso,
  backups,
  before,
  after: snapshot(verify),
  forbiddenStill: snapshot(forbidden)
};

fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify(result.after));
