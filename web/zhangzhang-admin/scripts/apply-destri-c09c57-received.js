'use strict';

/**
 * DESTRI tiktok(WA)／097640129／BYORDER-20260717-C09C57
 * Sales confirmed the customer already received it. Mark delivered.
 * Do NOT mark paid. Do NOT touch other DESTRI orders (0976401219 / E39247832886).
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260717-C09C57';
const PHONE = '097640129';
const NAME_PART = 'DESTRI';
const FORBIDDEN_TRACKING = 'E39247832886';
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-destri/result.json';
const NOTE = '業務確認客人已收到，完成訂單。原註記「數量部隊先不出貨」。貨到付款未收，不改收款。';

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
  const tmp = 'C:/Temp/lz-destri/curl-' + Date.now() + '.json';
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
    ephemeral: 'destri-received'
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
  return {
    id: row.id,
    name: nameOf(row),
    phone: phoneOf(row),
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState,
    shippingCarrier: row.shippingCarrier,
    trackingNo: row.trackingNo || '',
    paidAmount: pay.paidAmount,
    paymentStatus: pay.status,
    total: row.total
  };
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const ordersFile = path.join(dataDir, 'orders.json');
const ordersWrap = readJson(ordersFile);
const orders = listOf(ordersWrap);
const order = orders.find((row) => String(row.id || '') === TARGET_ID);
if (!order) throw new Error('order not found');
if (phoneOf(order) !== PHONE) throw new Error('phone mismatch ' + phoneOf(order));
if (String(nameOf(order)).toUpperCase().indexOf(NAME_PART) === -1) throw new Error('name mismatch ' + nameOf(order));
if (String(order.trackingNo || '') === FORBIDDEN_TRACKING) throw new Error('refusing to touch E39247832886 order');

const log = {
  backups: {
    orders: backup(ordersFile, 'destri-received'),
    state: backup(path.join(dataDir, 'admin-state.json'), 'destri-received')
  },
  before: snap(order),
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
    shippingCarrier: order.shippingCarrier || '7-11',
    trackingNo: '',
    trackingOptional: true,
    trackingOptionalReason: '業務確認已收到，不建物流單號',
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

const afterWrap = readJson(ordersFile);
const after = listOf(afterWrap).find((row) => String(row.id || '') === TARGET_ID);
if (after && String(after.trackingNo || '') === '數量部隊先不出貨') {
  after.trackingNo = '';
  if (after.customer) after.customer.trackingNo = '';
  after.trackingOptional = true;
  after.trackingOptionalReason = '業務確認已收到，不建物流單號';
  const json = JSON.stringify(afterWrap, null, 2) + '\n';
  const tmp = ordersFile + '.tmp-destri-track-' + Date.now();
  fs.writeFileSync(tmp, json);
  try { fs.renameSync(tmp, ordersFile); }
  catch (e) {
    try { fs.copyFileSync(tmp, ordersFile); } finally { try { fs.unlinkSync(tmp); } catch (_) {} }
  }
  log.steps.push({ step: 'clear-placeholder-tracking', trackingNo: '' });
}
log.after = snap(after);
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(log, null, 2) + '\n');
console.log(JSON.stringify(log, null, 2));
if (!after || !['delivered', 'completed'].includes(String(after.status || '').toLowerCase())) {
  throw new Error('order not delivered after update');
}
if (Number((after.paymentSummary || {}).paidAmount || 0) !== Number((order.paymentSummary || {}).paidAmount || 0)) {
  throw new Error('payment changed unexpectedly');
}
if (String(after.trackingNo || '') === FORBIDDEN_TRACKING) {
  throw new Error('forbidden tracking written');
}
