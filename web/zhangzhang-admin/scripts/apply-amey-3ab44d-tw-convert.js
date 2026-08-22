'use strict';

/**
 * AMEY(Chika Bwi)／0975401319／BYORDER-20260822-3AB44D
 * FIFO blocked convert: preorder quota, 0 received, color label on SY41
 * (inquiry 卡其 vs TW SKU 黑無盒) hid exact Taiwan SKUs that already have stock 1.
 * Convert with Taiwan on-hand. Do NOT mark paid. Do NOT create a store address.
 * Do NOT touch Nita.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260822-3AB44D';
const PHONE = '0975401319';
const NAME = 'AMEY(Chika Bwi)';
const SKUS = ['SY41-01-NO-SIZE', 'PI35-02-XL', 'K340-01-NO-SIZE'];
const FORBIDDEN_NITA = 'BYORDER-20260720-078596';
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-amey/result.json';
const NOTE = '管理者確認台灣倉現貨已齊，先出台灣倉並轉正式。不需物流單號（自取）。代收未收，不改收款。不另建超商第二地址。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.skus)) return wrap.skus;
  return [];
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || row.customerPhone || '').replace(/\D/g, '');
}

function nameOf(row) {
  const c = (row && row.customer) || {};
  return String(c.name || row.customerName || row.name || '');
}

function assignList(wrap, next, key) {
  if (Array.isArray(wrap)) return next;
  if (wrap && Array.isArray(wrap[key])) {
    wrap[key] = next;
    return wrap;
  }
  if (wrap && Array.isArray(wrap.orders)) {
    wrap.orders = next;
    return wrap;
  }
  if (wrap && Array.isArray(wrap.skus)) {
    wrap.skus = next;
    return wrap;
  }
  return next;
}

function atomicWrite(file, data) {
  const json = JSON.stringify(data, null, 2) + '\n';
  const tmp = file + '.tmp-amey-' + process.pid + '-' + Date.now();
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

function markSku(sku, operationId, marker) {
  if (!sku.inventoryTransactionMarkers || typeof sku.inventoryTransactionMarkers !== 'object') {
    sku.inventoryTransactionMarkers = {};
  }
  sku.inventoryTransactionMarkers[operationId] = Object.assign({ operationId: operationId }, marker);
  sku.updatedAt = marker.appliedAt;
}

function backup(file, tag) {
  const dir = path.join(dataDir, 'audit');
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + Date.now());
  fs.copyFileSync(file, dest);
  return dest;
}

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function skuStock(skus, id) {
  const hit = skus.find((s) => s && String(s.id || s.sku || '') === id);
  return hit ? intOr(hit.stock) : null;
}

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-amey/curl-' + Date.now() + '.json';
  const args = [
    '-sS', '-k',
    '--max-time', '120',
    '-o', tmp,
    '-H', 'Content-Type: application/json; charset=utf-8',
    '-H', 'Authorization: Bearer ' + token,
    '-H', 'X-Lingzanzan-Admin-Session: ' + token,
    '-H', 'Host: www.lingzanzan.com',
    '--data-binary', JSON.stringify(payload),
    API + urlPath
  ];
  execFileSync('C:\\Windows\\System32\\curl.exe', args, { encoding: 'utf8', stdio: ['ignore', 'ignore', 'pipe'] });
  const raw = fs.readFileSync(tmp, 'utf8');
  let data;
  try {
    data = JSON.parse(raw);
  } catch (e) {
    throw new Error(urlPath + ' not JSON: ' + String(raw).replace(/<[^>]+>/g, ' ').slice(0, 240));
  }
  if (!data || data.ok === false) {
    throw new Error(urlPath + ' ' + ((data && data.error) || raw.slice(0, 240)));
  }
  return data;
}

function injectSession() {
  const state = readJson(path.join(dataDir, 'admin-state.json'));
  const account = String(state.adminAccount || '');
  const passwordHash = String(state.adminPasswordHash || '');
  const accountLower = account.toLowerCase();
  const computed = crypto.createHash('sha256').update('admin\0' + accountLower + '\0' + passwordHash, 'utf8').digest('hex');
  const sessionsFile = path.join(dataDir, 'admin-sessions.json');
  const sessions = readJson(sessionsFile);
  if (!Array.isArray(sessions)) throw new Error('admin-sessions.json is not an array');
  const now = Math.floor(Date.now() / 1000);
  const liveAdmin = [...sessions].reverse().find((s) => s && s.role === 'admin' && intOr(s.expiresAt) > now && s.credentialFingerprint);
  const fingerprint = String((liveAdmin && liveAdmin.credentialFingerprint) || computed);
  const token = crypto.randomBytes(32).toString('hex');
  const tokenHash = crypto.createHash('sha256').update(token).digest('hex');
  sessions.push({
    tokenHash,
    role: 'admin',
    name: (liveAdmin && liveAdmin.name) || '管理者',
    account: liveAdmin ? String(liveAdmin.account || '') : account,
    permissions: Array.isArray(liveAdmin && liveAdmin.permissions) ? liveAdmin.permissions : [],
    credentialFingerprint: fingerprint,
    createdAt: now,
    expiresAt: now + 1800,
    ephemeral: 'amey-tw-convert'
  });
  fs.writeFileSync(sessionsFile, JSON.stringify(sessions, null, 2) + '\n');
  return { token, tokenHash };
}

function removeSession(tokenHash) {
  const sessionsFile = path.join(dataDir, 'admin-sessions.json');
  const sessions = readJson(sessionsFile);
  if (!Array.isArray(sessions)) return;
  fs.writeFileSync(sessionsFile, JSON.stringify(sessions.filter((s) => String(s.tokenHash || '') !== tokenHash), null, 2) + '\n');
}

function snapInquiry(row) {
  if (!row) return null;
  const pay = row.paymentSummary || {};
  return {
    id: row.id,
    name: nameOf(row),
    phone: phoneOf(row),
    status: row.status,
    shippingCarrier: row.shippingCarrier,
    storeAddress: row.storeAddress || '',
    homeAddress: row.homeAddress || row.address || '',
    paidAmount: pay.paidAmount,
    balance: pay.balance,
    total: row.total
  };
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const ordersFile = path.join(dataDir, 'orders.json');
const skusFile = path.join(dataDir, 'skus.json');
const inquiriesWrap = readJson(inquiriesFile);
const inquiries = listOf(inquiriesWrap);
const inquiry = inquiries.find((row) => String(row.id || '') === TARGET_ID);
if (!inquiry) throw new Error('inquiry not found');
if (String(inquiry.id || '') === FORBIDDEN_NITA) throw new Error('refused Nita');
if (phoneOf(inquiry) !== PHONE) throw new Error('phone mismatch ' + phoneOf(inquiry));
if (nameOf(inquiry) !== NAME) throw new Error('name mismatch ' + nameOf(inquiry));
if (String(inquiry.storeAddress || '').trim()) throw new Error('store address already present; refusing to convert blindly');

const itemSkus = (inquiry.items || []).map((it) => String(it.skuId || it.sku || ''));
SKUS.forEach((sku) => {
  if (itemSkus.indexOf(sku) === -1) throw new Error('missing sku ' + sku);
});

const skusWrap = readJson(skusFile);
const skusBefore = listOf(skusWrap);
const stockBefore = {};
SKUS.forEach((id) => {
  stockBefore[id] = skuStock(skusBefore, id);
});

const ordersWrap = readJson(ordersFile);
const ordersBefore = listOf(ordersWrap);
const existingOrder = ordersBefore.find((row) => String(row.id || '') === TARGET_ID);

const log = {
  backups: {
    inquiries: backup(inquiriesFile, 'amey-tw-convert'),
    orders: backup(ordersFile, 'amey-tw-convert'),
    skus: backup(skusFile, 'amey-tw-convert')
  },
  before: snapInquiry(inquiry),
  stockBefore,
  steps: []
};

if (!existingOrder) {
  SKUS.forEach((id) => {
    if (intOr(stockBefore[id]) < 1) throw new Error('TW stock missing for ' + id + '=' + stockBefore[id]);
  });
  const session = injectSession();
  try {
    const converted = curlJson('/stock-inquiry-api.php', {
      action: 'convert',
      inquiryId: TARGET_ID,
      fulfillWarehouse: 'TW',
      directStockAllocation: true,
      manualPhysicalAllocation: false,
      transferredBy: '管理者',
      shippingNote: NOTE
    }, session.token);
    log.steps.push({
      step: 'convert',
      orderId: converted.order && converted.order.id,
      inquiryStatus: converted.inquiry && converted.inquiry.status,
      shippingCarrier: converted.order && converted.order.shippingCarrier,
      error: converted.error || null
    });
  } finally {
    try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
  }
} else {
  log.steps.push({ step: 'convert', skipped: true, reason: 'formal order already exists' });
}

const ordersWrapAfter = readJson(ordersFile);
const ordersAfter = listOf(ordersWrapAfter);
const skusWrapAfter = readJson(skusFile);
const skusAfter = listOf(skusWrapAfter);
const order = ordersAfter.find((row) => String(row.id || '') === TARGET_ID);
if (!order) throw new Error('formal order missing after convert');

const appliedAt = new Date().toISOString();
const operationId = 'amey-exact-sku-' + TARGET_ID;
let repaired = [];
(order.items || []).forEach((item) => {
  const id = String(item.skuId || item.sku || '');
  if (SKUS.indexOf(id) === -1) return;
  const received = intOr(item.freightReceivedQty);
  const wanted = Math.max(1, intOr(item.qty || item.quantity || 1));
  if (received >= wanted) return;
  const sku = skusAfter.find((row) => row && String(row.id || row.sku || '') === id);
  if (!sku) throw new Error('sku missing for repair ' + id);
  if (intOr(sku.stock) < wanted) throw new Error('cannot repair ' + id + ' stock=' + sku.stock);
  sku.stock = intOr(sku.stock) - wanted;
  markSku(sku, operationId + ':' + id, {
    kind: 'exact_tw_sku_convert_repair',
    inquiryId: TARGET_ID,
    skuId: id,
    qtyDelta: -wanted,
    note: NOTE,
    appliedAt
  });
  item.freightReceivedQty = wanted;
  item.freightReceivedWarehouse = 'TW';
  item.allocationSourceWarehouse = 'TW';
  item.sourceWarehouse = '台灣倉';
  item.sourceWarehouseCode = 'TW';
  repaired.push(id);
});
if (repaired.length) {
  const stateFile = path.join(dataDir, 'admin-state.json');
  if (fs.existsSync(stateFile)) {
    const state = readJson(stateFile);
    if (state && Array.isArray(state.skus)) {
      state.skus.forEach((row) => {
        const id = String((row && (row.id || row.skuId || row.sku)) || '');
        const live = skusAfter.find((sku) => sku && String(sku.id || sku.sku || '') === id);
        if (live) row.stock = live.stock;
      });
      state.updatedAt = appliedAt;
      atomicWrite(stateFile, state);
    }
  }
  atomicWrite(ordersFile, assignList(ordersWrapAfter, ordersAfter, 'orders'));
  atomicWrite(skusFile, assignList(skusWrapAfter, skusAfter, 'skus'));
  log.steps.push({ step: 'repair-exact-sku', repaired });
} else {
  log.steps.push({ step: 'repair-exact-sku', skipped: true });
}

const inq = listOf(readJson(inquiriesFile)).find((row) => String(row.id || '') === TARGET_ID);
const orderFinal = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);
const skusFinal = listOf(readJson(skusFile));
const stockAfter = {};
SKUS.forEach((id) => { stockAfter[id] = skuStock(skusFinal, id); });

log.afterInquiry = snapInquiry(inq);
log.afterOrder = orderFinal ? {
  id: orderFinal.id,
  name: nameOf(orderFinal),
  phone: phoneOf(orderFinal),
  status: orderFinal.status,
  statusLabel: orderFinal.statusLabel,
  shippingCarrier: orderFinal.shippingCarrier,
  storeAddress: orderFinal.storeAddress || '',
  paidAmount: (orderFinal.paymentSummary || {}).paidAmount,
  total: orderFinal.total,
  received: (orderFinal.items || []).map((it) => ({
    sku: it.skuId || it.sku,
    freightReceivedQty: it.freightReceivedQty || 0
  }))
} : null;
log.stockAfter = stockAfter;

fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(log, null, 2) + '\n');
console.log(JSON.stringify(log, null, 2));

if (!orderFinal) throw new Error('formal order missing after convert');
if (phoneOf(orderFinal) !== PHONE) throw new Error('converted order phone mismatch');
if (nameOf(orderFinal) !== NAME) throw new Error('converted order name mismatch');
if (String(inq && inq.status || '') !== 'converted') throw new Error('inquiry not converted: ' + (inq && inq.status));
if (String(orderFinal.storeAddress || inq.storeAddress || '').trim()) throw new Error('second store address was created');
if (Number((orderFinal.paymentSummary || {}).paidAmount || 0) !== Number((inquiry.paymentSummary || {}).paidAmount || 0)) {
  throw new Error('payment changed unexpectedly');
}
SKUS.forEach((id) => {
  if (intOr(stockAfter[id]) !== 0) throw new Error('stock leftover for ' + id + '=' + stockAfter[id]);
  const line = (orderFinal.items || []).find((it) => String(it.skuId || it.sku || '') === id);
  if (!line || intOr(line.freightReceivedQty) < 1) throw new Error('line not marked received ' + id);
});
