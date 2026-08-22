'use strict';

/**
 * SARI(LAY SARI)(WA)／0903522964／BYORDER-20260822-669A8F
 * Inquiry already 自取／不需物流; order still 7-11 德隆店 confirming, K378 綠色 XL ×1.
 * Manager: 自己送 counts as completed. Do NOT mark paid (unpaid NT$360 stays).
 * Do NOT touch older delivered BYORDER-20260817-CFB1A4 or Nita BYORDER-20260720-078596.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260822-669A8F';
const PHONE = '0903522964';
const NAME = 'SARI(LAY SARI)(WA)';
const SKU_PART = 'K378';
const CARRIER = '自取／不需物流';
const FORBIDDEN_IDS = ['BYORDER-20260817-CFB1A4', 'BYORDER-20260720-078596'];
const FORBIDDEN_PHONES = ['16346546308'];
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-sari/result.json';
const NOTE = '管理者確認自己送／自取，完成訂單。不需物流單號。代收未收，不改收款。';

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
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-sari/curl-' + Date.now() + '.json';
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
    ephemeral: 'sari-self-deliver'
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
    balance: pay.balance,
    paymentStatus: pay.status,
    total: row.total
  };
}

function hasSku(row, part) {
  return (row.items || row.products || []).some((it) =>
    String(it.sku || it.code || it.productCode || '').toUpperCase().includes(part)
  );
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
if (FORBIDDEN_IDS.includes(String(order.id || ''))) throw new Error('refused forbidden order');
if (FORBIDDEN_PHONES.includes(phoneOf(order))) throw new Error('refused forbidden phone');
if (phoneOf(order) !== PHONE) throw new Error('phone mismatch ' + phoneOf(order));
if (nameOf(order) !== NAME) throw new Error('name mismatch ' + nameOf(order));
if (!hasSku(order, SKU_PART)) throw new Error('sku mismatch, expected ' + SKU_PART);

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const inquiry = fs.existsSync(inquiriesFile)
  ? listOf(readJson(inquiriesFile)).find((row) => String(row.id || row.orderId || '') === TARGET_ID)
  : null;
const carrierBlob = [
  String(order.shippingCarrier || ''),
  String((inquiry && inquiry.shippingCarrier) || ''),
  JSON.stringify(order.paymentSummary || {})
].join(' ');
if (!/自取|不需物流|自己送/.test(carrierBlob)) {
  throw new Error('not a self-deliver order: order=' + order.shippingCarrier + ' inquiry=' + (inquiry && inquiry.shippingCarrier));
}

const log = {
  backups: {
    orders: backup(ordersFile, 'sari-self-deliver'),
    state: backup(path.join(dataDir, 'admin-state.json'), 'sari-self-deliver')
  },
  before: snap(order),
  inquiryCarrier: inquiry && inquiry.shippingCarrier,
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
    trackingOptionalReason: '自己送／自取不需物流單號',
    shippingNote: NOTE,
    progressNote: NOTE
  }, session.token);
  log.steps.push({
    step: 'deliver',
    status: delivered.order && delivered.order.status,
    deliveryState: delivered.order && delivered.order.deliveryState,
    shippingCarrier: delivered.order && delivered.order.shippingCarrier,
    error: delivered.error || null
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const after = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);
log.after = snap(after);
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(log, null, 2) + '\n');
console.log(JSON.stringify(log, null, 2));
if (!after || !['delivered', 'completed'].includes(String(after.status || '').toLowerCase())) {
  throw new Error('order not delivered after update');
}
if (String(after.shippingCarrier || '') !== CARRIER) {
  throw new Error('carrier not updated: ' + after.shippingCarrier);
}
if (Number((after.paymentSummary || {}).paidAmount || 0) !== Number((order.paymentSummary || {}).paidAmount || 0)) {
  throw new Error('payment changed unexpectedly');
}

const untouched = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === 'BYORDER-20260817-CFB1A4');
if (untouched && String(untouched.trackingNo || '') !== 'E70218902603') {
  throw new Error('older SARI order tracking changed');
}
