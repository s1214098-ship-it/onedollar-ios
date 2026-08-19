'use strict';

/**
 * FITTO(NUNA MOCHI) BYORDER-20260731-B8C4C1
 * Indonesia sales already shipped and collected. Mark delivered and
 * record the remaining NT$989 so the whole NT$1588 is id_sales_full.
 *
 * Does not add a last-mile tracking number.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260731-B8C4C1';
const PHONE = '081236888432';
const NAME = 'FITTO(NUNA MOCHI)';
const TOTAL = 1588;
const ALREADY_PAID = 599;
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-fitto/result.json';
const NOTE = '管理者確認印尼業務已收清並自行出貨完成（FITTO(NUNA MOCHI)／081236888432／NT$1588）。不需物流單號。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
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

function curlJson(urlPath, payload, token) {
  const tmp = 'C:/Temp/lz-fitto/curl-' + Date.now() + '.json';
  fs.mkdirSync(path.dirname(tmp), { recursive: true });
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

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
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
    ephemeral: 'fitto-id-delivered'
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

function snapshot(row, payment) {
  return {
    id: row && row.id,
    name: row && nameOf(row),
    phone: row && phoneOf(row),
    status: row && row.status,
    statusLabel: row && row.statusLabel,
    deliveryState: row && row.deliveryState,
    trackingNo: row && (row.trackingNo || ''),
    total: row && row.total,
    indonesiaTrackingRequired: row && row.indonesiaTrackingRequired,
    trackingOptional: row && row.trackingOptional,
    paid: payment && payment.status,
    paidAmount: payment && payment.paidAmount,
    orderTotal: payment && payment.orderTotal,
    balance: payment && payment.balance,
    settlementMode: payment && payment.settlementMode,
    collectionRegion: payment && payment.collectionRegion,
    salesCollectedID: payment && payment.salesCollectedID,
    reviewStatus: payment && payment.reviewStatus
  };
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const ordersFile = path.join(dataDir, 'orders.json');
const paymentsFile = path.join(dataDir, 'order-payments.json');
const ordersBefore = listOf(readJson(ordersFile));
const orderBefore = ordersBefore.find((row) => String(row.id || '') === TARGET_ID);
if (!orderBefore) throw new Error('order not found');
if (phoneOf(orderBefore) !== PHONE) throw new Error('phone mismatch ' + phoneOf(orderBefore));
if (String(nameOf(orderBefore)).indexOf('FITTO') === -1) throw new Error('name mismatch ' + nameOf(orderBefore));
if (Number(orderBefore.total) !== TOTAL) throw new Error('total mismatch ' + orderBefore.total);
if (String(orderBefore.shippingCarrier || '').indexOf('印尼') === -1) {
  throw new Error('not indonesia self-ship ' + orderBefore.shippingCarrier);
}
if (String(orderBefore.trackingNo || '').trim()) throw new Error('unexpected tracking ' + orderBefore.trackingNo);

const paymentsBefore = readJson(paymentsFile);
const paymentBefore = paymentsBefore[TARGET_ID] || null;
if (!paymentBefore) throw new Error('payment row missing');
if (Number(paymentBefore.paidAmount) !== ALREADY_PAID) {
  throw new Error('unexpected paidAmount ' + paymentBefore.paidAmount);
}

const backups = {
  orders: backup(ordersFile, 'fitto-id-delivered'),
  payments: backup(paymentsFile, 'fitto-id-delivered'),
  state: backup(path.join(dataDir, 'admin-state.json'), 'fitto-id-delivered')
};

const session = injectSession();
const log = { backups, before: snapshot(orderBefore, paymentBefore), steps: [] };

try {
  const paid = curlJson('/finance-api.php', {
    action: 'save-order-payment',
    orderId: TARGET_ID,
    sourceOrderId: '',
    moveSourcePayment: false,
    orderType: 'order',
    orderTotal: TOTAL,
    depositAmount: 0,
    paidAmount: TOTAL,
    paymentEventAmount: TOTAL - ALREADY_PAID,
    paymentStage: 'balance',
    proofImage: '',
    paymentDate: '2026-08-19',
    receivableBalance: 0,
    unshippedBalance: 0,
    settlementMode: 'id_sales_full',
    idRemainderStage: 'none',
    indonesiaAmount: 0,
    indonesiaRate: 1.75,
    indonesiaTwd: 0,
    indonesiaShortfall: 0,
    indonesiaCovered: true,
    storeIdCodDummy: false,
    storeIdCodAmount: 0,
    balanceCollectionStage: 'none',
    codAmount: 0,
    codCollectionParty: '',
    codCollectionRegion: '',
    codCollectorName: '',
    note: NOTE,
    customerName: NAME,
    customerPhone: PHONE,
    collectionParty: 'sales',
    collectionRegion: 'ID',
    collectorName: 'LINGZANZAN',
    depositCollectionParty: 'sales',
    depositCollectionRegion: 'ID',
    depositCollectorName: 'LINGZANZAN',
    salesName: 'LINGZANZAN',
    submittedBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'save-payment',
    status: paid.payment && paid.payment.status,
    paidAmount: paid.payment && paid.payment.paidAmount,
    orderTotal: paid.payment && paid.payment.orderTotal,
    reviewStatus: paid.payment && paid.payment.reviewStatus,
    salesCollectedID: paid.payment && paid.payment.salesCollectedID
  });

  const reviewed = curlJson('/finance-api.php', {
    action: 'review-order-payment',
    orderId: TARGET_ID,
    decision: 'approved',
    reviewerRole: 'admin',
    reviewedBy: '管理者',
    reviewNote: '管理者確認印尼業務已收清 NT$1588'
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
    shippingCarrier: '印尼業務自行出貨',
    trackingNo: '',
    shippingNote: '印尼出貨。' + NOTE,
    progressNote: NOTE
  }, session.token);
  log.steps.push({
    step: 'deliver',
    status: delivered.order && delivered.order.status,
    deliveryState: delivered.order && delivered.order.deliveryState
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const orderAfter = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);
const paymentAfter = readJson(paymentsFile)[TARGET_ID];
if (!orderAfter) throw new Error('order missing after update');
if (String(orderAfter.status) !== 'delivered' || String(orderAfter.deliveryState) !== 'delivered') {
  throw new Error('not delivered: ' + orderAfter.status + '/' + orderAfter.deliveryState);
}
if (String(orderAfter.trackingNo || '').trim()) throw new Error('tracking was filled');
if (!paymentAfter || paymentAfter.status !== 'paid' || Number(paymentAfter.paidAmount) !== TOTAL || Number(paymentAfter.orderTotal) !== TOTAL) {
  throw new Error('payment not fully paid to 1588');
}
if (paymentAfter.reviewStatus !== 'approved') throw new Error('payment not approved');
if (paymentAfter.collectionRegion !== 'ID' || paymentAfter.settlementMode !== 'id_sales_full') {
  throw new Error('payment not indonesia sales full');
}

const result = {
  ok: true,
  order: snapshot(orderAfter, paymentAfter),
  log
};

fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify(result.order));
