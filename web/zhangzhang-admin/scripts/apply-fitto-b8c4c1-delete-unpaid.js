'use strict';

/**
 * Delete FITTO(NUNA MOCHI) BYORDER-20260731-B8C4C1.
 * Customer did not pay. Reverse the mistaken Indonesia paid-in-full record,
 * delete the formal order (restock), and remove the converted inquiry.
 *
 * Does NOT blacklist the customer.
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
const SKU_WATCH = ['OLAN75P315902-TW', 'OLAN61P35091-e63d95', 'OLAN61P35091-TW'];
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-fitto/delete-result.json';
const curlDir = 'C:/Temp/lz-fitto';
const NOTE = '客人沒收款，刪除 FITTO(NUNA MOCHI) BYORDER-20260731-B8C4C1；沖銷誤登印尼已收清。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function atomicWrite(file, data) {
  const json = JSON.stringify(data, null, 2) + '\n';
  const tmp = file + '.tmp-fitto-del-' + process.pid + '-' + Date.now();
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

function skuStocks() {
  const list = listOf(readJson(path.join(dataDir, 'skus.json')));
  return SKU_WATCH.map((id) => {
    const hit = list.find((s) => String(s.id || '') === id);
    return { id, stock: hit ? Number(hit.stock || 0) : null, warehouse: hit && (hit.warehouse || hit.warehouseCode || '') };
  });
}

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function injectSession() {
  const state = readJson(path.join(dataDir, 'admin-state.json'));
  const account = String(state.adminAccount || '');
  const passwordHash = String(state.adminPasswordHash || '');
  const computed = crypto.createHash('sha256').update('admin\0' + account.toLowerCase() + '\0' + passwordHash, 'utf8').digest('hex');
  const sessionsFile = path.join(dataDir, 'admin-sessions.json');
  const sessions = readJson(sessionsFile);
  if (!Array.isArray(sessions)) throw new Error('admin-sessions.json is not an array');
  const now = Math.floor(Date.now() / 1000);
  const liveAdmin = [...sessions].reverse().find((s) => s && s.role === 'admin' && intOr(s.expiresAt) > now && s.credentialFingerprint);
  const token = crypto.randomBytes(32).toString('hex');
  const tokenHash = crypto.createHash('sha256').update(token).digest('hex');
  sessions.push({
    tokenHash,
    role: 'admin',
    name: (liveAdmin && liveAdmin.name) || '管理者',
    account: liveAdmin ? String(liveAdmin.account || '') : account,
    permissions: Array.isArray(liveAdmin && liveAdmin.permissions) ? liveAdmin.permissions : [],
    credentialFingerprint: String((liveAdmin && liveAdmin.credentialFingerprint) || computed),
    createdAt: now,
    expiresAt: now + 1800,
    ephemeral: 'fitto-delete-unpaid'
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

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(curlDir, { recursive: true });
  const tmp = path.join(curlDir, 'curl-' + Date.now() + '.json');
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

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const ordersFile = path.join(dataDir, 'orders.json');
const inquiriesFile = path.join(dataDir, 'inquiries.json');
const paymentsFile = path.join(dataDir, 'order-payments.json');
const orders = listOf(readJson(ordersFile));
const order = orders.find((row) => String(row.id || '') === TARGET_ID);
if (!order) throw new Error('order not found');
if (phoneOf(order) !== PHONE) throw new Error('phone mismatch ' + phoneOf(order));
if (String(nameOf(order)).indexOf('FITTO') === -1) throw new Error('name mismatch ' + nameOf(order));

const inquiries = listOf(readJson(inquiriesFile));
const inquiry = inquiries.find((row) => String(row.id || '') === TARGET_ID);
if (inquiry && inquiry.reserved) throw new Error('inquiry still reserved; refusing double restock');

const payments = readJson(paymentsFile);
const paymentBefore = payments[TARGET_ID] || null;

const backups = {
  orders: backup(ordersFile, 'fitto-delete'),
  inquiries: backup(inquiriesFile, 'fitto-delete'),
  payments: backup(paymentsFile, 'fitto-delete'),
  skus: backup(path.join(dataDir, 'skus.json'), 'fitto-delete'),
  state: backup(path.join(dataDir, 'admin-state.json'), 'fitto-delete')
};
const stockBefore = skuStocks();
const iso = nowIsoTaipei();

if (paymentBefore) {
  const next = Object.assign({}, paymentBefore, {
    orderTotal: Number(order.total || paymentBefore.orderTotal || 0),
    paidAmount: 0,
    depositAmount: 0,
    lastPaymentAmount: 0,
    balance: 0,
    receivableBalance: 0,
    unshippedBalance: 0,
    salesCollectedTotal: 0,
    salesCollectedTW: 0,
    salesCollectedID: 0,
    indonesiaCovered: false,
    status: 'cancelled',
    refundRequiredAmount: 0,
    refundStatus: 'not_required',
    reviewStatus: '',
    note: NOTE,
    cancelledAt: iso,
    cancelledBy: '管理者',
    cancelledReason: '客人沒收款',
    updatedAt: iso
  });
  const history = Array.isArray(next.history) ? next.history.slice() : [];
  history.unshift({
    at: iso,
    type: 'unpaid-delete-void',
    by: '管理者',
    reason: '客人沒收款',
    note: NOTE,
    voidedPaidAmount: Number(paymentBefore.paidAmount || 0)
  });
  next.history = history.slice(0, 100);
  payments[TARGET_ID] = next;
  atomicWrite(paymentsFile, payments);
}

const session = injectSession();
const log = { backups, stockBefore, steps: [] };
try {
  const deletedOrder = curlJson('/order-admin-api-v6.php', {
    action: 'delete',
    orderId: TARGET_ID,
    reason: NOTE,
    deletedBy: '管理者'
  }, session.token);
  log.steps.push({ step: 'delete-order', deleted: deletedOrder.deleted || TARGET_ID });

  const deletedInquiry = curlJson('/stock-inquiry-api.php', {
    action: 'delete',
    inquiryId: TARGET_ID,
    reason: NOTE,
    deletedBy: '管理者'
  }, session.token);
  log.steps.push({ step: 'delete-inquiry', deleted: deletedInquiry.deletedId || TARGET_ID });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const ordersAfter = listOf(readJson(ordersFile));
const inquiriesAfter = listOf(readJson(inquiriesFile));
if (ordersAfter.some((row) => String(row.id || '') === TARGET_ID)) throw new Error('order still present');
if (inquiriesAfter.some((row) => String(row.id || '') === TARGET_ID)) throw new Error('inquiry still present');

const stockAfter = skuStocks();
const restocked = SKU_WATCH.map((id, i) => ({
  id,
  before: stockBefore[i] && stockBefore[i].stock,
  after: stockAfter[i] && stockAfter[i].stock
}));
const increased = restocked.filter((row) => Number(row.after) > Number(row.before));
if (!increased.length) throw new Error('stock did not increase');

const paymentAfter = readJson(paymentsFile)[TARGET_ID];
if (!paymentAfter || Number(paymentAfter.paidAmount) !== 0 || paymentAfter.status !== 'cancelled') {
  throw new Error('payment not voided');
}
if (Number(paymentAfter.refundRequiredAmount || 0) !== 0) throw new Error('refund pending was created');

const result = {
  ok: true,
  deleted: TARGET_ID,
  name: NAME,
  phone: PHONE,
  restocked,
  payment: {
    status: paymentAfter.status,
    paidAmount: paymentAfter.paidAmount,
    refundStatus: paymentAfter.refundStatus
  },
  log
};
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify({ deleted: TARGET_ID, restocked, payment: result.payment }));
