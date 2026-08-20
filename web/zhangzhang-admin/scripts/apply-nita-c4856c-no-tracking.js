'use strict';

/**
 * Nita oktavita sari LINE / 0972956651 / 全家龜山善捷店(021558)
 * BYORDER-20260809-C4856C NT$460 COD: do not create a FamilyMart tracking number.
 *
 * Does NOT mark delivered or paid.
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
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-nita/no-tracking-result.json';
const REASON = '管理者指定本單不建物流單號（貨到付款全家龜山善捷店）';

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
  const tmp = file + '.tmp-nita-' + process.pid + '-' + Date.now();
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
    shippingCarrier: row.shippingCarrier || '',
    trackingOptional: row.trackingOptional,
    trackingOptionalReason: row.trackingOptionalReason || '',
    indonesiaTrackingRequired: row.indonesiaTrackingRequired,
    paid: row.paymentSummary && row.paymentSummary.status,
    paidAmount: row.paymentSummary && row.paymentSummary.paidAmount,
    balance: row.paymentSummary && row.paymentSummary.balance,
    total: row.total,
    address: addressOf(row)
  };
}

function applyNoTracking(row, iso, note) {
  if (String(row.id || '') === FORBIDDEN_ID) throw new Error('refusing to touch forbidden order');
  if (String(row.trackingNo || '') === FORBIDDEN_TRACKING) throw new Error('refusing to write forbidden tracking onto this row');
  if (phoneOf(row) !== PHONE) throw new Error('phone mismatch ' + phoneOf(row));
  if (String(nameOf(row)).toLowerCase().indexOf('nita oktavita sari') === -1) {
    throw new Error('name mismatch ' + nameOf(row));
  }
  if (Number(row.total) !== TOTAL) throw new Error('total mismatch ' + row.total);
  if (addressOf(row).indexOf(STORE) === -1) throw new Error('store mismatch ' + addressOf(row));
  if (String(row.trackingNo || '').trim()) throw new Error('target already has tracking ' + row.trackingNo);

  row.trackingNo = '';
  row.trackingOptional = true;
  row.trackingOptionalReason = REASON;
  row.indonesiaTrackingRequired = false;
  row.updatedAt = iso;
  if (!Array.isArray(row.outboundParcels)) row.outboundParcels = [];
  row.outboundParcels = row.outboundParcels.filter((p) => String(p && p.trackingNo || '').trim());
  const extra = '\n' + note;
  if (String(row.shippingNote || '').indexOf('不建物流單號') === -1) {
    row.shippingNote = (String(row.shippingNote || '').trim() + extra).trim();
  }
  if (!Array.isArray(row.shippingEventHistory)) row.shippingEventHistory = [];
  row.shippingEventHistory.unshift({
    at: iso,
    actor: '管理者',
    summary: note,
    source: 'manual-no-tracking'
  });
  if (!Array.isArray(row.progressHistory)) row.progressHistory = [];
  row.progressHistory.unshift({
    at: iso,
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState || '',
    carrier: row.shippingCarrier || '全家',
    trackingNo: '',
    note: note,
    source: 'manual-no-tracking'
  });
  row.progressHistory = row.progressHistory.slice(0, 20);
  return row;
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const iso = nowIsoTaipei();
const note = '管理者指定本單不建全家物流單號。Nita oktavita sari LINE／0972956651／全家龜山善捷店(021558)／貨到付款 NT$460。單號維持空白。未改收款、未標已收。' + iso;

const ordersFile = path.join(dataDir, 'orders.json');
const stateFile = path.join(dataDir, 'admin-state.json');
const backups = {
  orders: backup(ordersFile, 'nita-no-tracking'),
  state: backup(stateFile, 'nita-no-tracking')
};

const ordersWrap = readJson(ordersFile);
const ordersRef = listRef(ordersWrap);
const order = ordersRef.list.find((o) => String(o.id || '') === TARGET_ID);
if (!order) throw new Error('order not found');
const before = snapshot(order);
applyNoTracking(order, iso, note);
atomicWrite(ordersFile, ordersWrap);

const stateWrap = readJson(stateFile);
if (Array.isArray(stateWrap.orders)) {
  const stateOrder = stateWrap.orders.find((o) => String(o.id || '') === TARGET_ID);
  if (stateOrder) {
    applyNoTracking(stateOrder, iso, note);
    atomicWrite(stateFile, stateWrap);
  }
}

const verifyWrap = readJson(ordersFile);
const verifyRef = listRef(verifyWrap);
const verify = verifyRef.list.find((o) => String(o.id || '') === TARGET_ID);
const forbidden = verifyRef.list.find((o) => String(o.id || '') === FORBIDDEN_ID);
if (!verify) throw new Error('verify missing target');
if (verify.status !== before.status) throw new Error('status changed unexpectedly');
if (String(verify.trackingNo || '') !== '') throw new Error('tracking was filled');
if (verify.trackingOptional !== true) throw new Error('trackingOptional not set');
if (String(verify.trackingOptionalReason || '') !== REASON) throw new Error('reason mismatch');
if (verify.paymentSummary && verify.paymentSummary.status !== 'unpaid') {
  throw new Error('payment status changed');
}
if (Number(verify.paymentSummary && verify.paymentSummary.paidAmount) !== 0) {
  throw new Error('paidAmount changed');
}
if (!forbidden || String(forbidden.trackingNo || '') !== FORBIDDEN_TRACKING) {
  throw new Error('forbidden order tracking changed');
}
if (forbidden.status === 'delivered' || forbidden.trackingOptional === true) {
  throw new Error('forbidden order unexpectedly marked optional/delivered');
}

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
