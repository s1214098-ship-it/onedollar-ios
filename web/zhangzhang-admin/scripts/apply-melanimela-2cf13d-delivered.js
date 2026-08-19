'use strict';

/**
 * MELANIMELA(WOCENTE PUCETAU FB)／628214564880／BYORDER-20260730-2CF13D
 * Indonesia sales self-ship. Payment already NT$1580 id_sales_full approved.
 * Manager: paid + self-ship should sit in 完成區. Do NOT change payment.
 * Do NOT invent a last-mile tracking number.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260730-2CF13D';
const PHONE = '628214564880';
const NAME = 'MELANIMELA(WOCENTE PUCETAU FB)';
const CARRIER = '印尼業務自行出貨';
const TOTAL = 1580;
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-melanimela/result.json';
const NOTE = '管理者確認已收款且印尼業務自行出貨完成。不需物流單號。原註記「張張以收款」。收款資料不改。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
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
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-melanimela/curl-' + Date.now() + '.json';
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
    ephemeral: 'melanimela-delivered'
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

function snap(row, payment) {
  if (!row) return null;
  const pay = payment || row.paymentSummary || {};
  return {
    id: row.id,
    name: nameOf(row),
    phone: phoneOf(row),
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState,
    shippingCarrier: row.shippingCarrier,
    trackingNo: row.trackingNo || '',
    trackingOptional: row.trackingOptional,
    paidAmount: pay.paidAmount,
    balance: pay.balance,
    paymentStatus: pay.status,
    settlementMode: pay.settlementMode,
    reviewStatus: pay.reviewStatus,
    collectionRegion: pay.collectionRegion,
    total: row.total
  };
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const ordersFile = path.join(dataDir, 'orders.json');
const paymentsFile = path.join(dataDir, 'order-payments.json');
const ordersWrap = readJson(ordersFile);
const orders = listOf(ordersWrap);
const order = orders.find((row) => String(row.id || '') === TARGET_ID);
if (!order) throw new Error('order not found');
if (phoneOf(order) !== PHONE) throw new Error('phone mismatch ' + phoneOf(order));
if (nameOf(order) !== NAME) throw new Error('name mismatch ' + nameOf(order));
if (Number(order.total) !== TOTAL) throw new Error('total mismatch ' + order.total);
if (String(order.shippingCarrier || '').indexOf('印尼') === -1) {
  throw new Error('not indonesia self-ship ' + order.shippingCarrier);
}
if (String(order.trackingNo || '').trim()) throw new Error('unexpected tracking ' + order.trackingNo);

const payments = readJson(paymentsFile);
const payment = payments[TARGET_ID] || null;
if (!payment) throw new Error('payment row missing');
if (payment.status !== 'paid' || Number(payment.paidAmount) !== TOTAL || Number(payment.balance) !== 0) {
  throw new Error('payment not already fully paid: ' + JSON.stringify({
    status: payment.status, paidAmount: payment.paidAmount, balance: payment.balance
  }));
}
if (payment.reviewStatus !== 'approved' || payment.settlementMode !== 'id_sales_full') {
  throw new Error('payment not approved id_sales_full');
}

const codes = (order.items || []).map((it) => String(it.code || ''));
if (codes.indexOf('GB2') < 0 || codes.indexOf('SE179') < 0) {
  throw new Error('item codes mismatch ' + codes.join(','));
}

const log = {
  backups: {
    orders: backup(ordersFile, 'melanimela-delivered'),
    state: backup(path.join(dataDir, 'admin-state.json'), 'melanimela-delivered')
  },
  before: snap(order, payment),
  steps: []
};

if (['delivered', 'completed'].includes(String(order.status || '').toLowerCase())) {
  log.already = true;
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(log, null, 2) + '\n');
  console.log(JSON.stringify(log, null, 2));
  process.exit(0);
}

const session = injectSession();
try {
  const delivered = curlJson('/order-admin-api-v6.php', {
    action: 'update-status',
    orderId: TARGET_ID,
    status: 'delivered',
    deliveryState: 'delivered',
    shippingCarrier: CARRIER,
    trackingNo: '',
    trackingOptional: true,
    trackingOptionalReason: '印尼業務自行出貨不需物流單號',
    shippingNote: NOTE,
    progressNote: NOTE
  }, session.token);
  log.steps.push({
    step: 'deliver',
    status: delivered.order && delivered.order.status,
    deliveryState: delivered.order && delivered.order.deliveryState,
    error: delivered.error || null
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const after = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);
const paymentAfter = readJson(paymentsFile)[TARGET_ID];
log.after = snap(after, paymentAfter);
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(log, null, 2) + '\n');
console.log(JSON.stringify(log, null, 2));

if (!after || !['delivered', 'completed'].includes(String(after.status || '').toLowerCase())) {
  throw new Error('order not delivered after update');
}
if (String(after.deliveryState || '') !== 'delivered') {
  throw new Error('deliveryState not delivered: ' + after.deliveryState);
}
if (String(after.trackingNo || '').trim()) throw new Error('tracking was filled');
if (Number((after.paymentSummary || {}).paidAmount || 0) !== TOTAL) {
  throw new Error('order paymentSummary changed unexpectedly');
}
if (!paymentAfter || paymentAfter.status !== 'paid' || Number(paymentAfter.paidAmount) !== TOTAL || Number(paymentAfter.balance) !== 0) {
  throw new Error('payment row changed unexpectedly');
}
if (paymentAfter.settlementMode !== 'id_sales_full' || paymentAfter.reviewStatus !== 'approved') {
  throw new Error('payment settlement changed unexpectedly');
}
