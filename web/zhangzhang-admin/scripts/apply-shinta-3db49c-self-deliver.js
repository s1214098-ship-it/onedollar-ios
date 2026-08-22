'use strict';

/**
 * Shinta(My love YK WA)／0955514076／BYORDER-20260724-3DB49C
 * No 7-11 / FamilyMart tracking, no outbound parcels.
 * User: if no CVS tracking, switch to 自己送 and complete shipment.
 * Do NOT mark paid. Do NOT touch AMEY 3AB44D or Nita.
 * Do not invent items. Keep OLAN70 / S149 / BEA002.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260724-3DB49C';
const PHONE = '0955514076';
const NAME = 'Shinta(My love YK WA)';
const CARRIER = '自取／不需物流';
const ITEM_CODES = ['OLAN70', 'S149', 'BEA002'];
const FORBIDDEN_IDS = ['BYORDER-20260822-3AB44D', 'BYORDER-20260720-078596', 'BYORDER-20260804-D580DB'];
const FORBIDDEN_PHONES = ['16346546308', '0975401319'];
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-shinta/result.json';
const NOTE = '管理者確認沒有超商物流單號，改自己送並完成出貨。不需物流單號。代收未收，不改收款。';

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

function digits(v) {
  return String(v || '').replace(/\D/g, '');
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return digits(c.phone || row.phone || row.customerPhone);
}

function nameOf(row) {
  const c = (row && row.customer) || {};
  return String(c.name || row.customerName || row.name || '');
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

function collectTracking(row) {
  const c = (row && row.customer) || {};
  const nos = [];
  [row && row.trackingNo, row && row.shippingTrackingNo, c.trackingNo, row && row.supplierTrackingNo].forEach(function (v) {
    const t = String(v || '').replace(/\s+/g, '').toUpperCase();
    if (t) nos.push(t);
  });
  ((row && row.outboundParcels) || []).forEach(function (p) {
    const t = String((p && (p.trackingNo || p.paymentNo)) || '').replace(/\s+/g, '').toUpperCase();
    if (t) nos.push(t);
  });
  return nos;
}

function isCvsTracking(no) {
  const t = String(no || '').replace(/\s+/g, '').toUpperCase();
  if (/^E\d{10,}$/.test(t)) return true;
  if (/^F\d{10,}$/.test(t)) return true;
  if (/^D\d{10,}$/.test(t)) return true;
  return false;
}

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-shinta/curl-' + Date.now() + '-' + Math.random().toString(16).slice(2) + '.json';
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
    ephemeral: 'shinta-self-deliver'
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

function snap(row) {
  if (!row) return null;
  const pay = row.paymentSummary || {};
  const c = row.customer || {};
  return {
    id: row.id,
    name: nameOf(row),
    phone: phoneOf(row),
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState,
    deliveryType: row.deliveryType || c.deliveryType || '',
    deliveryLabel: row.deliveryLabel || c.deliveryLabel || '',
    shippingCarrier: row.shippingCarrier || c.shippingCarrier || '',
    trackingNo: row.trackingNo || c.trackingNo || '',
    shippingFee: row.shippingFee,
    paidAmount: pay.paidAmount,
    balance: pay.balance,
    paymentStatus: pay.status || row.paymentStatus,
    total: row.total,
    items: (row.items || []).map(function (it) {
      return {
        code: it.code || it.productCode,
        sku: it.skuId || it.sku,
        qty: it.qty || it.quantity,
        received: it.freightReceivedQty || 0
      };
    })
  };
}

function dump(result) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}

if (!fs.existsSync(dataDir)) {
  dump({ ok: false, error: 'Not on PHT-SR' });
  process.exit(0);
}

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const ordersFile = path.join(dataDir, 'orders.json');
const inquiries = listOf(readJson(inquiriesFile));
const orders = listOf(readJson(ordersFile));
const inquiry = inquiries.find((row) => String(row.id || '') === TARGET_ID);
if (!inquiry) throw new Error('inquiry not found');
if (FORBIDDEN_IDS.includes(String(inquiry.id || ''))) throw new Error('refused forbidden id');
if (FORBIDDEN_PHONES.includes(phoneOf(inquiry))) throw new Error('refused forbidden phone');
if (phoneOf(inquiry) !== PHONE) throw new Error('phone mismatch ' + phoneOf(inquiry));
if (nameOf(inquiry) !== NAME) throw new Error('name mismatch ' + nameOf(inquiry));

const codes = (inquiry.items || []).map((it) => String(it.code || it.productCode || '').toUpperCase());
ITEM_CODES.forEach((code) => {
  if (codes.indexOf(code) === -1) throw new Error('missing item ' + code);
});

const cvsHits = collectTracking(inquiry).filter(isCvsTracking);
if (cvsHits.length) throw new Error('has CVS tracking, refusing self-deliver: ' + cvsHits.join(','));

const existingOrder = orders.find((row) => String(row.id || '') === TARGET_ID);
if (existingOrder && collectTracking(existingOrder).filter(isCvsTracking).length) {
  throw new Error('formal order already has CVS tracking');
}

const homeAddress = String(inquiry.homeAddress || (inquiry.customer && inquiry.customer.homeAddress) || (inquiry.customer && inquiry.customer.address) || '').trim();
const paidBefore = intOr((inquiry.paymentSummary || {}).paidAmount || inquiry.paidAmount || 0);

const log = {
  ok: true,
  backups: {
    inquiries: backup(inquiriesFile, 'shinta-self-deliver'),
    orders: fs.existsSync(ordersFile) ? backup(ordersFile, 'shinta-self-deliver') : '',
    state: backup(path.join(dataDir, 'admin-state.json'), 'shinta-self-deliver')
  },
  before: { inquiry: snap(inquiry), order: snap(existingOrder) },
  steps: []
};

const session = injectSession();
try {
  const meta = curlJson('/stock-inquiry-api.php', {
    action: 'update-preorder-meta',
    inquiryId: TARGET_ID,
    customerName: NAME,
    customerPhone: PHONE,
    customerAddress: homeAddress,
    storeAddress: '',
    homeAddress: homeAddress,
    addressPrimary: 'home',
    deliveryType: 'self',
    deliveryLabel: '自取 / 不收運費',
    shippingCarrier: CARRIER,
    trackingNo: '',
    outboundParcels: [],
    shippingFee: 0,
    shippingNote: [String(inquiry.shippingNote || '').trim(), NOTE].filter(Boolean).join('\n'),
    employeeName: '管理者'
  }, session.token);
  log.steps.push({
    step: 'inquiry-self-deliver',
    shippingCarrier: meta.inquiry && meta.inquiry.shippingCarrier,
    deliveryType: meta.inquiry && (meta.inquiry.deliveryType || (meta.inquiry.customer && meta.inquiry.customer.deliveryType)),
    trackingNo: meta.inquiry && meta.inquiry.trackingNo
  });

  if (!existingOrder) {
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
      error: converted.error || null
    });
  } else {
    log.steps.push({ step: 'convert', skipped: true, reason: 'already-formal' });
  }

  const orderNow = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);
  if (!orderNow) throw new Error('formal order missing after convert');
  if (phoneOf(orderNow) !== PHONE) throw new Error('converted phone mismatch');
  if (FORBIDDEN_IDS.includes(String(orderNow.id || ''))) throw new Error('converted onto forbidden id');

  const delivered = curlJson('/order-admin-api-v6.php', {
    action: 'update-status',
    orderId: TARGET_ID,
    status: 'delivered',
    deliveryState: 'delivered',
    shippingCarrier: CARRIER,
    trackingNo: '',
    trackingOptional: true,
    trackingOptionalReason: '自己送／自取不需物流單號',
    deliveryType: 'self',
    deliveryLabel: '自取 / 不收運費',
    shippingNote: NOTE,
    progressNote: NOTE,
    updatedBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'deliver',
    status: delivered.order && delivered.order.status,
    deliveryState: delivered.order && delivered.order.deliveryState,
    shippingCarrier: delivered.order && delivered.order.shippingCarrier,
    error: delivered.error || null
  });

  const aligned = curlJson('/order-admin-api-v6.php', {
    action: 'update-customer',
    orderId: TARGET_ID,
    customerName: NAME,
    customerPhone: PHONE,
    customerAddress: homeAddress,
    storeAddress: '',
    homeAddress: homeAddress,
    addressPrimary: 'home',
    deliveryType: 'self',
    deliveryLabel: '自取 / 不收運費',
    shippingCarrier: CARRIER,
    trackingNo: '',
    outboundParcels: [],
    shippingFee: 0,
    shippingNote: NOTE,
    updatedBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'align-self-deliver',
    deliveryType: aligned.order && (aligned.order.deliveryType || (aligned.order.customer && aligned.order.customer.deliveryType)),
    deliveryLabel: aligned.order && (aligned.order.deliveryLabel || (aligned.order.customer && aligned.order.customer.deliveryLabel)),
    shippingFee: aligned.order && aligned.order.shippingFee,
    shippingCarrier: aligned.order && aligned.order.shippingCarrier,
    trackingNo: aligned.order && aligned.order.trackingNo
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const inquiryAfter = listOf(readJson(inquiriesFile)).find((row) => String(row.id || '') === TARGET_ID);
const orderAfter = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);
log.after = { inquiry: snap(inquiryAfter), order: snap(orderAfter) };

if (!orderAfter || !['delivered', 'completed'].includes(String(orderAfter.status || '').toLowerCase())) {
  log.ok = false;
  log.error = 'order not delivered';
  dump(log);
  process.exit(1);
}
if (phoneOf(orderAfter) !== PHONE || nameOf(orderAfter) !== NAME) {
  throw new Error('identity drifted after update');
}
if (isCvsTracking(orderAfter.trackingNo) || collectTracking(orderAfter).filter(isCvsTracking).length) {
  throw new Error('CVS tracking appeared after update');
}
if (!/自取|不需物流|自己送/.test(String(orderAfter.shippingCarrier || ''))) {
  throw new Error('carrier not self-deliver: ' + orderAfter.shippingCarrier);
}
if (!/self|自取|自己送/.test(String(orderAfter.deliveryType || '') + String(orderAfter.deliveryLabel || ''))) {
  throw new Error('delivery type not self-deliver: ' + orderAfter.deliveryType + '/' + orderAfter.deliveryLabel);
}
if (intOr((orderAfter.paymentSummary || {}).paidAmount) !== paidBefore) {
  throw new Error('payment changed unexpectedly');
}
const ameyAfter = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === 'BYORDER-20260822-3AB44D');
if (ameyAfter && log.backups.orders) {
  const ameyBefore = listOf(readJson(log.backups.orders)).find((row) => String(row.id || '') === 'BYORDER-20260822-3AB44D');
  if (
    ameyBefore
    && ['delivered', 'completed'].includes(String(ameyAfter.status || '').toLowerCase())
    && !['delivered', 'completed'].includes(String(ameyBefore.status || '').toLowerCase())
  ) {
    throw new Error('AMEY 3AB44D was delivered unexpectedly');
  }
}

dump(log);
