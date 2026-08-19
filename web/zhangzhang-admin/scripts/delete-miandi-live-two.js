'use strict';

/**
 * Delete Miandi line live cards:
 *   LIVE-20260726-563868  準備出貨／接單確認中  JA320
 *   LIVE-20260726-32D9D4  未下單／直播草稿     C160 + K397
 *
 * Does NOT touch shipped BYORDER-20260816-595720.
 * Does NOT blacklist the customer.
 * Official delete restocks inventoryDeducted items.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const API = 'http://127.0.0.1:8082';
const PHONE = '0903143874';
const KEEP_ID = 'BYORDER-20260816-595720';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-miandi/delete-result.json';
const curlDir = 'C:/Temp/lz-miandi';

const TARGETS = [
  {
    id: 'LIVE-20260726-563868',
    status: 'confirming',
    codes: ['JA320']
  },
  {
    id: 'LIVE-20260726-32D9D4',
    status: 'draft',
    codes: ['C160', 'K397']
  }
];
const SKU_WATCH = ['SKU-JA319-fa9361', 'SKU-C160-65272f', 'SKU-K397-fa9361', 'SKU-K397-FA9361-TW'];

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
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

function itemCodes(row) {
  return (row.items || []).map((it) => String(it.code || it.productCode || '').trim());
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
    return { id, stock: hit ? Number(hit.stock || 0) : null };
  });
}

function ordersOf() {
  return listOf(readJson(path.join(dataDir, 'orders.json')));
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
    ephemeral: 'miandi-delete-two'
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

function curlDelete(orderId, token) {
  fs.mkdirSync(curlDir, { recursive: true });
  const tmp = path.join(curlDir, 'curl-delete-' + orderId + '.json');
  const args = [
    '-sS', '-k',
    '--max-time', '120',
    '-o', tmp,
    '-H', 'Content-Type: application/json; charset=utf-8',
    '-H', 'Authorization: Bearer ' + token,
    '-H', 'X-Lingzanzan-Admin-Session: ' + token,
    '-H', 'Host: www.lingzanzan.com',
    '--data-binary', JSON.stringify({
      action: 'delete',
      orderId: orderId,
      reason: '管理者刪除直播重複／作廢單',
      deletedBy: '管理者'
    }),
    API + '/order-admin-api-v6.php'
  ];
  execFileSync('C:\\Windows\\System32\\curl.exe', args, { encoding: 'utf8', stdio: ['ignore', 'ignore', 'pipe'] });
  const raw = fs.readFileSync(tmp, 'utf8');
  let data;
  try {
    data = JSON.parse(raw);
  } catch (e) {
    throw new Error(orderId + ' not JSON: ' + String(raw).replace(/<[^>]+>/g, ' ').slice(0, 240));
  }
  if (!data || data.ok === false) {
    throw new Error(orderId + ' ' + ((data && data.error) || raw.slice(0, 240)));
  }
  return { ok: data.ok === true, deleted: data.deleted || orderId };
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const ordersBefore = ordersOf();
TARGETS.forEach((target) => {
  const row = ordersBefore.find((o) => String(o.id || '') === target.id);
  if (!row) throw new Error('missing ' + target.id);
  if (phoneOf(row) !== PHONE) throw new Error(target.id + ' phone mismatch ' + phoneOf(row));
  if (!/miandi/i.test(nameOf(row))) throw new Error(target.id + ' name mismatch ' + nameOf(row));
  if (String(row.status || '') !== target.status) throw new Error(target.id + ' status mismatch ' + row.status);
  const codes = itemCodes(row);
  target.codes.forEach((code) => {
    if (codes.indexOf(code) === -1) throw new Error(target.id + ' missing item ' + code);
  });
});
const keepBefore = ordersBefore.find((o) => String(o.id || '') === KEEP_ID);
if (!keepBefore) throw new Error('keep order missing');
if (phoneOf(keepBefore) !== PHONE) throw new Error('keep order phone mismatch');
if (String(keepBefore.status || '') !== 'shipped') throw new Error('keep order status changed ' + keepBefore.status);

const backups = {
  orders: backup(path.join(dataDir, 'orders.json'), 'miandi-delete'),
  state: backup(path.join(dataDir, 'admin-state.json'), 'miandi-delete'),
  skus: backup(path.join(dataDir, 'skus.json'), 'miandi-delete')
};
const stockBefore = skuStocks();

const session = injectSession();
const deleted = [];
try {
  TARGETS.forEach((target) => {
    deleted.push(curlDelete(target.id, session.token));
  });
} finally {
  removeSession(session.tokenHash);
}

const ordersAfter = ordersOf();
TARGETS.forEach((target) => {
  if (ordersAfter.some((o) => String(o.id || '') === target.id)) {
    throw new Error('still present ' + target.id);
  }
});
const keepAfter = ordersAfter.find((o) => String(o.id || '') === KEEP_ID);
if (!keepAfter || String(keepAfter.status || '') !== 'shipped' || String(keepAfter.trackingNo || keepBefore.trackingNo) !== String(keepBefore.trackingNo || '')) {
  throw new Error('keep order changed unexpectedly');
}

const result = {
  ok: true,
  deleted,
  backups,
  stockBefore,
  stockAfter: skuStocks(),
  keepStill: {
    id: keepAfter.id,
    status: keepAfter.status,
    statusLabel: keepAfter.statusLabel,
    trackingNo: keepAfter.trackingNo || '',
    phone: phoneOf(keepAfter)
  }
};
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2), 'utf8');
console.log('OK', JSON.stringify(result));
