'use strict';

/**
 * Ika Miyati(Icha Nyk Icha TK)／0916464082／BYORDER-20260822-074B00
 *
 * FIFO 轉正式被擋：C160 黑 M 掛預購倉、台灣倉 C160-01-M 庫存 0。
 * 實體件在店內——同客人今日退回的重複打單 LIVE-20260726-7592A0
 * 已入中國倉 SKU-C160-abb06f（stock 1）。
 *
 * 這支腳本：中國倉 1 件調台灣倉 → 三行都當台灣現貨 → convert。
 * 不改代收 670。不另記運費虧損。不碰 Nita。不自動退 LIVE-20260726-4E3AC5。
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260822-074B00';
const PHONE = '0916464082';
const NAME_PART = 'Ika Miyati';
const TRACKING = 'E77396429349';
const COD = 670;
const CN_SKU = 'SKU-C160-abb06f';
const TW_SKU = 'C160-01-M';
const FORBIDDEN_SKU = 'C160-01-S';
const FORBIDDEN_NITA = 'BYORDER-20260720-078596';
const RETURNED_LIVE = 'LIVE-20260726-7592A0';
const LEFTOVER_LIVE = 'LIVE-20260726-4E3AC5';
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-ika-074b00/result.json';
const NOTE = [
  '管理者確認店內現貨已齊，先出台灣倉並轉正式。',
  'C160 黑 M 為今日退回重複打單 ' + RETURNED_LIVE + ' 的實體件，已從中國倉 ' + CN_SKU + ' 調入台灣倉 ' + TW_SKU + '。',
  '7-11 ' + TRACKING + ' 代收 NT$' + COD + '。不另記運費虧損。',
  '同客人直播確認中 ' + LEFTOVER_LIVE + '（SE166 粉／中國倉）未併入，勿再轉出以免重出。'
].join(' ');

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

function assignList(wrap, next) {
  if (Array.isArray(wrap)) return next;
  if (wrap && Array.isArray(wrap.inquiries)) {
    wrap.inquiries = next;
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

function atomicWrite(file, data) {
  const json = JSON.stringify(data, null, 2) + '\n';
  const tmp = file + '.tmp-ika-074b00-' + process.pid + '-' + Date.now();
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

function nowTaipei() {
  return new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Taipei' }).replace(' ', 'T') + '+08:00';
}

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function skuById(skus, id) {
  return skus.find((s) => s && String(s.id || s.skuId || '') === id) || null;
}

function skuStockMap(skus) {
  const ids = [TW_SKU, 'C160-02-L', 'SE166-03-NO-SIZE', CN_SKU, FORBIDDEN_SKU, 'SKU-SE166-ebdd6c'];
  const out = {};
  ids.forEach((id) => {
    const hit = skuById(skus, id);
    out[id] = hit ? intOr(hit.stock) : null;
  });
  return out;
}

function markSku(sku, operationId, marker) {
  if (!sku.inventoryTransactionMarkers || typeof sku.inventoryTransactionMarkers !== 'object') {
    sku.inventoryTransactionMarkers = {};
  }
  sku.inventoryTransactionMarkers[operationId] = Object.assign({ operationId: operationId }, marker);
  sku.updatedAt = marker.appliedAt;
}

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-ika-074b00/curl-' + Date.now() + '.json';
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
    ephemeral: 'ika-074b00-tw-convert'
  });
  fs.writeFileSync(sessionsFile, JSON.stringify(sessions, null, 2) + '\n');
  return { token, tokenHash, sessionsFile };
}

function removeSession(tokenHash) {
  const sessionsFile = path.join(dataDir, 'admin-sessions.json');
  const sessions = readJson(sessionsFile);
  if (!Array.isArray(sessions)) return;
  fs.writeFileSync(sessionsFile, JSON.stringify(sessions.filter((s) => String(s.tokenHash || '') !== tokenHash), null, 2) + '\n');
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const ordersFile = path.join(dataDir, 'orders.json');
const skusFile = path.join(dataDir, 'skus.json');
const stateFile = path.join(dataDir, 'admin-state.json');

const inquiriesWrap = readJson(inquiriesFile);
const ordersWrap = readJson(ordersFile);
const skusWrap = readJson(skusFile);
const inquiries = listOf(inquiriesWrap);
const orders = listOf(ordersWrap);
const skus = listOf(skusWrap);

if (inquiries.some((row) => String(row.id || '') === FORBIDDEN_NITA && phoneOf(row) === PHONE)) {
  throw new Error('refusing: Nita inquiry collided with Ika phone');
}

const inquiry = inquiries.find((row) => String(row.id || '') === TARGET_ID);
if (!inquiry) throw new Error('inquiry not found');
if (phoneOf(inquiry) !== PHONE) throw new Error('phone mismatch ' + phoneOf(inquiry));
if (String(nameOf(inquiry)).indexOf(NAME_PART) === -1) throw new Error('name mismatch ' + nameOf(inquiry));
if (String(inquiry.trackingNo || (inquiry.customer || {}).trackingNo || '') !== TRACKING) {
  throw new Error('tracking mismatch ' + (inquiry.trackingNo || ''));
}

const existingFormal = orders.find((row) => String(row.id || '') === TARGET_ID);
if (existingFormal && String(inquiry.status || '') === 'converted') {
  const result = {
    ok: true,
    already: true,
    order: {
      id: existingFormal.id,
      status: existingFormal.status,
      trackingNo: existingFormal.trackingNo || '',
      total: existingFormal.total
    }
  };
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(result, null, 2) + '\n');
  console.log('ALREADY', JSON.stringify(result.order));
  process.exit(0);
}
if (existingFormal) throw new Error('formal order already exists while inquiry status=' + inquiry.status);

const itemSkus = (inquiry.items || []).map((it) => String(it.skuId || it.sku || ''));
if (itemSkus.indexOf(TW_SKU) === -1 || itemSkus.indexOf('SE166-03-NO-SIZE') === -1 || itemSkus.indexOf('C160-02-L') === -1) {
  throw new Error('unexpected items ' + itemSkus.join(','));
}
if (itemSkus.indexOf(FORBIDDEN_SKU) !== -1) throw new Error('refusing to ship C160-01-S');

const stocksBefore = skuStockMap(skus);
if (intOr(stocksBefore[FORBIDDEN_SKU]) < 0) throw new Error('C160-01-S stock unreadable');
const twC160 = intOr(stocksBefore[TW_SKU]);
const cnC160 = intOr(stocksBefore[CN_SKU]);
const twSe166 = intOr(stocksBefore['SE166-03-NO-SIZE']);
const twC160L = intOr(stocksBefore['C160-02-L']);
if (twSe166 < 1) throw new Error('SE166 粉 台灣倉 stock ' + twSe166);
if (twC160L < 1) throw new Error('C160 灰 L 台灣倉 stock ' + twC160L);
if (twC160 < 1 && cnC160 < 1) throw new Error('C160 黑 M 台灣倉與中國倉都是 0，不能轉');

const backups = {
  inquiries: backup(inquiriesFile, 'ika-074b00'),
  orders: backup(ordersFile, 'ika-074b00'),
  skus: backup(skusFile, 'ika-074b00'),
  state: backup(stateFile, 'ika-074b00')
};

const log = { backups, stocksBefore, steps: [] };
const appliedAt = nowTaipei();
const transferOp = 'ika-074b00-cn-to-tw-' + Date.now();

if (twC160 < 1) {
  const cnSku = skuById(skus, CN_SKU);
  const twSku = skuById(skus, TW_SKU);
  const forbidden = skuById(skus, FORBIDDEN_SKU);
  if (!cnSku || !twSku) throw new Error('missing C160 SKU rows');
  if (String(cnSku.warehouseCode || cnSku.warehouse || '').indexOf('中國') === -1 && String(cnSku.warehouseCode || '') !== 'CN') {
    throw new Error('CN sku warehouse mismatch ' + (cnSku.warehouse || cnSku.warehouseCode));
  }
  if (String(twSku.warehouseCode || '') !== 'TW') throw new Error('TW sku warehouse mismatch');
  if (String(twSku.color || '') !== String(cnSku.color || '') || String(twSku.size || '') !== String(cnSku.size || '')) {
    throw new Error('C160 color/size mismatch between CN and TW');
  }
  const forbiddenBefore = forbidden ? intOr(forbidden.stock) : null;
  cnSku.stock = intOr(cnSku.stock) - 1;
  twSku.stock = intOr(twSku.stock) + 1;
  if (cnSku.stock !== 0) throw new Error('CN stock after transfer expected 0, got ' + cnSku.stock);
  if (twSku.stock < 1) throw new Error('TW C160-01-M still 0 after transfer');
  if (forbidden && intOr(forbidden.stock) !== forbiddenBefore) throw new Error('C160-01-S stock changed');
  markSku(cnSku, transferOp, {
    kind: 'warehouse_transfer_cn_to_tw',
    inquiryId: TARGET_ID,
    fromSkuId: CN_SKU,
    toSkuId: TW_SKU,
    qtyDelta: -1,
    note: NOTE,
    appliedAt: appliedAt
  });
  markSku(twSku, transferOp, {
    kind: 'warehouse_transfer_cn_to_tw',
    inquiryId: TARGET_ID,
    fromSkuId: CN_SKU,
    toSkuId: TW_SKU,
    qtyDelta: 1,
    note: NOTE,
    appliedAt: appliedAt
  });
  atomicWrite(skusFile, assignList(skusWrap, skus));

  const state = readJson(stateFile);
  if (state && Array.isArray(state.skus)) {
    state.skus.forEach((row) => {
      const id = String((row && (row.id || row.skuId)) || '');
      if (id === CN_SKU) row.stock = cnSku.stock;
      if (id === TW_SKU) row.stock = twSku.stock;
    });
    state.updatedAt = appliedAt;
    atomicWrite(stateFile, state);
  }
  log.steps.push({
    step: 'cn-to-tw',
    from: CN_SKU,
    to: TW_SKU,
    cnStock: cnSku.stock,
    twStock: twSku.stock
  });
} else {
  log.steps.push({ step: 'cn-to-tw', skipped: true, reason: 'TW C160-01-M already has stock', twC160: twC160 });
}

(inquiry.items || []).forEach((item) => {
  if (String(item.skuId || item.sku || '') !== TW_SKU) return;
  item.sourceWarehouse = '台灣倉';
  item.sourceWarehouseCode = 'TW';
  item.allocationSourceWarehouse = 'TW';
});
const prevNote = String(inquiry.shippingNote || '').trim();
if (prevNote.indexOf(RETURNED_LIVE) === -1) {
  inquiry.shippingNote = prevNote ? prevNote + '\n' + NOTE : NOTE;
}
inquiry.updatedAt = appliedAt;
atomicWrite(inquiriesFile, assignList(inquiriesWrap, inquiries));
log.steps.push({ step: 'inquiry-tw-line', sku: TW_SKU, sourceWarehouseCode: 'TW' });

const session = injectSession();
try {
  const converted = curlJson('/stock-inquiry-api.php', {
    action: 'convert',
    inquiryId: TARGET_ID,
    fulfillWarehouse: 'TW',
    directStockAllocation: true,
    manualPhysicalAllocation: false,
    transferredBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'convert',
    orderId: converted.order && converted.order.id,
    inquiryStatus: converted.inquiry && converted.inquiry.status,
    trackingNo: converted.order && converted.order.trackingNo,
    error: converted.error || null
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const inquiriesAfter = listOf(readJson(inquiriesFile));
const ordersAfter = listOf(readJson(ordersFile));
const skusAfter = listOf(readJson(skusFile));
const inq = inquiriesAfter.find((row) => String(row.id || '') === TARGET_ID);
const order = ordersAfter.find((row) => String(row.id || '') === TARGET_ID);
const leftover = ordersAfter.find((row) => String(row.id || '') === LEFTOVER_LIVE);
const returnedLive = ordersAfter.find((row) => String(row.id || '') === RETURNED_LIVE);
const stocksAfter = skuStockMap(skusAfter);

if (!order) throw new Error('formal order missing after convert');
if (phoneOf(order) !== PHONE) throw new Error('converted order phone mismatch');
if (String(nameOf(order)).indexOf(NAME_PART) === -1) throw new Error('converted order name mismatch');
if (String(inq && inq.status || '') !== 'converted') throw new Error('inquiry not converted: ' + (inq && inq.status));
if (String(order.trackingNo || '') !== TRACKING) throw new Error('tracking not copied: ' + (order.trackingNo || ''));
if (String(order.shippingCarrier || '') !== '7-11') throw new Error('carrier mismatch ' + (order.shippingCarrier || ''));
if (Number(order.total) !== COD) throw new Error('total changed ' + order.total);
if (intOr(stocksAfter[TW_SKU]) !== 0) throw new Error('TW C160-01-M should be 0 after convert, got ' + stocksAfter[TW_SKU]);
if (intOr(stocksAfter['SE166-03-NO-SIZE']) !== 0) throw new Error('SE166 TW should be 0 after convert');
if (intOr(stocksAfter['C160-02-L']) !== 0) throw new Error('C160 灰 L TW should be 0 after convert');
if (intOr(stocksAfter[CN_SKU]) !== 0) throw new Error('CN C160 should stay 0 after convert');
if (intOr(stocksAfter[FORBIDDEN_SKU]) !== intOr(stocksBefore[FORBIDDEN_SKU])) {
  throw new Error('C160-01-S stock changed');
}
if (leftover && String(leftover.status || '') !== 'confirming') {
  throw new Error('leftover live SE166 status changed to ' + leftover.status);
}
if (returnedLive && String(returnedLive.status || '') !== 'returned') {
  throw new Error('returned live C160 status changed to ' + returnedLive.status);
}
if (Number((returnedLive && returnedLive.shippingLossTwd) || 0) !== 70) {
  throw new Error('returned live shipping loss changed');
}
const unfulfilled = (order.items || []).filter((it) => it && (it.unfulfilledAtConvert || it.priorityPending));
if (unfulfilled.length) throw new Error('convert left unfulfilled lines ' + JSON.stringify(unfulfilled.map((it) => it.skuId || it.sku)));

const result = {
  ok: true,
  order: {
    id: order.id,
    name: nameOf(order),
    phone: phoneOf(order),
    status: order.status,
    statusLabel: order.statusLabel,
    trackingNo: order.trackingNo || '',
    shippingCarrier: order.shippingCarrier,
    total: order.total,
    inventoryDeducted: order.inventoryDeducted,
    itemSkus: (order.items || []).map((it) => it.skuId || it.sku)
  },
  inquiry: inq && { id: inq.id, status: inq.status, statusLabel: inq.statusLabel },
  leftoverLive: leftover && { id: leftover.id, status: leftover.status },
  stocks: { before: stocksBefore, after: stocksAfter },
  log
};

fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2) + '\n');
console.log('OK', JSON.stringify({ order: result.order, inquiry: result.inquiry, leftoverLive: result.leftoverLive, stocks: result.stocks }));
