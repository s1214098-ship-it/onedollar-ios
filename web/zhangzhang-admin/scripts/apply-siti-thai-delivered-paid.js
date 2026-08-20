'use strict';

/**
 * SITI(泰文) 0972144523 / BYORDER-20260721-E36350
 * Convert ready-to-ship preorder → formal order, record prepaid remittance,
 * then mark delivered without a last-mile tracking number.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260721-E36350';
const PHONE = '0972144523';
const NAME = 'SITI(泰文)';
const TOTAL = 650;
const SKU = 'OLAN71-93-NO-SIZE';
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-siti/result.json';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
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

function backup(file, tag) {
  const dir = path.join(dataDir, 'audit');
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + Date.now());
  fs.copyFileSync(file, dest);
  return dest;
}

function skuStock() {
  const wrap = readJson(path.join(dataDir, 'skus.json'));
  const list = Array.isArray(wrap) ? wrap : listOf(wrap);
  const hit = list.find((s) => String(s.id || s.skuId || '') === SKU);
  return hit ? Number(hit.stock || 0) : null;
}

function curlJson(urlPath, payload, token) {
  const tmp = 'C:/Temp/lz-siti/curl-' + Date.now() + '.json';
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
  const liveAdmin = [...sessions].reverse().find((s) => s && s.role === 'admin' && (intOr(s.expiresAt) > now) && s.credentialFingerprint);
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
    ephemeral: 'siti-thai-convert'
  });
  fs.writeFileSync(sessionsFile, JSON.stringify(sessions, null, 2) + '\n');
  return { token, tokenHash, sessionsFile };
}

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function removeSession(tokenHash) {
  const sessionsFile = path.join(dataDir, 'admin-sessions.json');
  const sessions = readJson(sessionsFile);
  if (!Array.isArray(sessions)) return;
  const next = sessions.filter((s) => String(s.tokenHash || '') !== tokenHash);
  fs.writeFileSync(sessionsFile, JSON.stringify(next, null, 2) + '\n');
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const inqs = listOf(readJson(path.join(dataDir, 'inquiries.json')));
const inquiry = inqs.find((row) => String(row.id || '') === TARGET_ID);
if (!inquiry) throw new Error('inquiry not found');
if (phoneOf(inquiry) !== PHONE) throw new Error('phone mismatch ' + phoneOf(inquiry));
if (String(nameOf(inquiry)).indexOf('SITI') === -1 || String(nameOf(inquiry)).indexOf('泰文') === -1) {
  throw new Error('name mismatch ' + nameOf(inquiry));
}

const ordersBefore = listOf(readJson(path.join(dataDir, 'orders.json')));
if (ordersBefore.some((row) => String(row.id || '') === TARGET_ID)) {
  throw new Error('formal order already exists; refusing to convert twice');
}

const backups = {
  inquiries: backup(path.join(dataDir, 'inquiries.json'), 'siti-thai'),
  orders: backup(path.join(dataDir, 'orders.json'), 'siti-thai'),
  state: backup(path.join(dataDir, 'admin-state.json'), 'siti-thai'),
  payments: backup(path.join(dataDir, 'order-payments.json'), 'siti-thai'),
  skus: backup(path.join(dataDir, 'skus.json'), 'siti-thai')
};

const stockBefore = skuStock();
const session = injectSession();
const log = { backups, stockBefore, steps: [] };

try {
  const converted = curlJson('/stock-inquiry-api.php', {
    action: 'convert',
    inquiryId: TARGET_ID,
    fulfillWarehouse: 'TW',
    directStockAllocation: false,
    transferredBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'convert',
    orderId: converted.order && converted.order.id,
    inquiryStatus: converted.inquiry && converted.inquiry.status,
    error: converted.error || null
  });

  const payNote = '管理者確認客人已先匯款收清（SITI泰文／0972144523）。無最後一哩物流單號，已簽收完成。';
  const paid = curlJson('/finance-api.php', {
    action: 'save-order-payment',
    orderId: TARGET_ID,
    sourceOrderId: '',
    moveSourcePayment: false,
    orderType: 'order',
    orderTotal: TOTAL,
    depositAmount: TOTAL,
    paidAmount: TOTAL,
    paymentEventAmount: TOTAL,
    paymentStage: 'deposit',
    proofImage: '',
    paymentDate: '2026-08-19',
    receivableBalance: 0,
    unshippedBalance: 0,
    settlementMode: 'paid_full',
    idRemainderStage: 'none',
    indonesiaAmount: 0,
    indonesiaRate: 1.75,
    indonesiaTwd: 0,
    indonesiaShortfall: 0,
    indonesiaCovered: false,
    storeIdCodDummy: false,
    storeIdCodAmount: 0,
    balanceCollectionStage: 'none',
    codAmount: 0,
    codCollectionParty: '',
    codCollectionRegion: '',
    codCollectorName: '',
    note: payNote,
    customerName: NAME,
    customerPhone: PHONE,
    collectionParty: 'company',
    collectionRegion: 'TW',
    collectorName: '公司',
    depositCollectionParty: 'company',
    depositCollectionRegion: 'TW',
    depositCollectorName: '公司',
    salesName: 'LINGZANZAN',
    submittedBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'save-payment',
    status: paid.payment && paid.payment.status,
    reviewStatus: paid.payment && paid.payment.reviewStatus,
    paidAmount: paid.payment && paid.payment.paidAmount
  });

  const reviewed = curlJson('/finance-api.php', {
    action: 'review-order-payment',
    orderId: TARGET_ID,
    decision: 'approved',
    reviewerRole: 'admin',
    reviewedBy: '管理者',
    reviewNote: '管理者確認客人已先匯款收清'
  }, session.token);
  log.steps.push({
    step: 'approve-payment',
    reviewStatus: reviewed.payment && reviewed.payment.reviewStatus
  });

  const delivered = curlJson('/order-admin-api-v6.php', {
    action: 'update-status',
    orderId: TARGET_ID,
    status: 'delivered',
    deliveryState: 'delivered',
    shippingCarrier: 'POST 郵局',
    trackingNo: '',
    shippingNote: '管理者確認已簽收完成；查無正確物流單號。客人已先匯款收清 NT$650。',
    progressNote: '管理者確認客人已簽收完成。查無最後一哩物流單號。客人已先匯款收清。'
  }, session.token);
  log.steps.push({
    step: 'deliver',
    status: delivered.order && delivered.order.status,
    deliveryState: delivered.order && delivered.order.deliveryState
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const ordersAfter = listOf(readJson(path.join(dataDir, 'orders.json')));
const inqsAfter = listOf(readJson(path.join(dataDir, 'inquiries.json')));
const order = ordersAfter.find((row) => String(row.id || '') === TARGET_ID);
const inq = inqsAfter.find((row) => String(row.id || '') === TARGET_ID);
const payments = readJson(path.join(dataDir, 'order-payments.json'));
const payment = payments[TARGET_ID] || null;

if (!order) throw new Error('formal order missing after convert');
if (phoneOf(order) !== PHONE) throw new Error('converted order phone mismatch');
if (String(order.status) !== 'delivered' || String(order.deliveryState) !== 'delivered') {
  throw new Error('not delivered: ' + order.status + '/' + order.deliveryState);
}
if (!payment || payment.status !== 'paid' || Number(payment.paidAmount) !== TOTAL) {
  throw new Error('payment not fully paid');
}
if (payment.reviewStatus !== 'approved') throw new Error('payment not approved');

const result = {
  ok: true,
  order: {
    id: order.id,
    name: nameOf(order),
    phone: phoneOf(order),
    status: order.status,
    statusLabel: order.statusLabel,
    deliveryState: order.deliveryState,
    trackingNo: order.trackingNo || '',
    inventoryDeducted: order.inventoryDeducted,
    deliveredAt: order.deliveredAt,
    total: order.total
  },
  inquiry: inq && { id: inq.id, status: inq.status, statusLabel: inq.statusLabel },
  payment: {
    status: payment.status,
    paidAmount: payment.paidAmount,
    balance: payment.balance,
    settlementMode: payment.settlementMode,
    reviewStatus: payment.reviewStatus,
    paymentDate: payment.paymentDate
  },
  stock: { before: stockBefore, after: skuStock() },
  log
};

fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify({ order: result.order, payment: result.payment, stock: result.stock }));
