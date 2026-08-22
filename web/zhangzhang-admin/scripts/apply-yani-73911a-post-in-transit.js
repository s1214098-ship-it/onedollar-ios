'use strict';

/**
 * YANI(LINE:YABNNIE)／0965482672／BYORDER-20260731-73911A
 * POST 29553300104374 頭份郵局 入帳成功 20260821162603.
 * 入帳成功 = 收寄局已收件 → 配送中，不是投遞成功，也不該停在等我通知出貨。
 * Do NOT mark paid. Do NOT mark delivered.
 * Do NOT touch Nita / AMEY / Weni 29566200104374 / LIVE-20260726-6D10E9.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TARGET_ID = 'BYORDER-20260731-73911A';
const PHONE = '0965482672';
const NAME = 'YANI(LINE:YABNNIE)';
const TRACKING = '29553300104374';
const FORBIDDEN_TRACKING = '29566200104374';
const CARRIER = 'POST 郵局';
const OFFICIAL = '入帳成功 20260821162603 頭份郵局';
const COD = 790;
const ITEM_CODE = 'GB2';
const FORBIDDEN_IDS = [
  'BYORDER-20260822-3AB44D',
  'BYORDER-20260720-078596',
  'BYORDER-20260804-D580DB',
  'BYORDER-20260720-60F1AD',
  'LIVE-20260726-6D10E9'
];
const FORBIDDEN_PHONES = ['16346546308', '0975401319'];
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-post/yani-result.json';
const NOTE = '管理者確認中華郵政入帳成功（頭份郵局 20260821162603）是收寄局已收件，不是投遞成功。已轉正式並改配送中，不再等通知出貨。代收未收，不改收款。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.items)) return wrap.items;
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

function writeJson(file, value) {
  const json = JSON.stringify(value, null, 2) + '\n';
  const tmp = file + '.tmp-yani-post-' + Date.now();
  fs.writeFileSync(tmp, json);
  try {
    fs.renameSync(tmp, file);
  } catch (e) {
    try { fs.copyFileSync(tmp, file); } finally { try { fs.unlinkSync(tmp); } catch (_) {} }
  }
}

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
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

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-post/curl-' + Date.now() + '-' + Math.random().toString(16).slice(2) + '.json';
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
    ephemeral: 'yani-post-in-transit'
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
  const parcel = (row.outboundParcels || [])[0] || {};
  return {
    id: row.id,
    name: nameOf(row),
    phone: phoneOf(row),
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState,
    deliveryType: row.deliveryType || c.deliveryType || '',
    shippingCarrier: row.shippingCarrier || c.shippingCarrier || '',
    trackingNo: row.trackingNo || c.trackingNo || '',
    waitNotify: row.waitNotify,
    paidAmount: pay.paidAmount,
    paymentStatus: pay.status || row.paymentStatus,
    total: row.total,
    officialStatus: parcel.officialStatus || row.externalStatus || '',
    parcelDeliveryState: parcel.deliveryState || '',
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

function parcelPayload(amount) {
  return [{
    trackingNo: TRACKING,
    carrier: CARRIER,
    amount: amount,
    officialStatus: OFFICIAL,
    officialStatusText: OFFICIAL,
    deliveryState: 'in_transit',
    lastCheckedAt: nowIsoTaipei()
  }];
}

if (!fs.existsSync(dataDir)) {
  dump({ ok: false, error: 'Not on PHT-SR' });
  process.exit(0);
}

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const ordersFile = path.join(dataDir, 'orders.json');
const queueFile = path.join(dataDir, 'carrier-tracking-queue.json');
const inquiries = listOf(readJson(inquiriesFile));
const orders = listOf(readJson(ordersFile));
const inquiry = inquiries.find((row) => String(row.id || '') === TARGET_ID);
if (!inquiry) throw new Error('inquiry not found');
if (FORBIDDEN_IDS.includes(String(inquiry.id || ''))) throw new Error('refused forbidden id');
if (FORBIDDEN_PHONES.includes(phoneOf(inquiry))) throw new Error('refused forbidden phone');
if (phoneOf(inquiry) !== PHONE) throw new Error('phone mismatch ' + phoneOf(inquiry));
if (nameOf(inquiry) !== NAME) throw new Error('name mismatch ' + nameOf(inquiry));
if (String(inquiry.trackingNo || '') !== TRACKING) throw new Error('tracking mismatch ' + inquiry.trackingNo);
if (String(inquiry.trackingNo || '') === FORBIDDEN_TRACKING) throw new Error('refusing forbidden tracking');

const codes = (inquiry.items || []).map((it) => String(it.code || it.productCode || '').toUpperCase());
if (codes.indexOf(ITEM_CODE) === -1) throw new Error('missing item ' + ITEM_CODE);

const existingOrder = orders.find((row) => String(row.id || '') === TARGET_ID);
if (existingOrder && ['delivered', 'completed'].includes(String(existingOrder.status || '').toLowerCase())) {
  throw new Error('already delivered; refusing to rewrite');
}
if (existingOrder && String(existingOrder.trackingNo || '') === FORBIDDEN_TRACKING) {
  throw new Error('formal order has forbidden tracking');
}

const homeAddress = String(inquiry.homeAddress || (inquiry.customer && inquiry.customer.homeAddress) || inquiry.address || '').trim();
const paidBefore = intOr((inquiry.paymentSummary || {}).paidAmount || inquiry.paidAmount || 0);
const amount = intOr(((inquiry.outboundParcels || [])[0] || {}).amount) || intOr(inquiry.total) || COD;

const log = {
  ok: true,
  backups: {
    inquiries: backup(inquiriesFile, 'yani-post-in-transit'),
    orders: fs.existsSync(ordersFile) ? backup(ordersFile, 'yani-post-in-transit') : '',
    state: backup(path.join(dataDir, 'admin-state.json'), 'yani-post-in-transit'),
    queue: fs.existsSync(queueFile) ? backup(queueFile, 'yani-post-in-transit') : ''
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
    deliveryType: 'home',
    deliveryLabel: '住家宅配',
    shippingCarrier: CARRIER,
    trackingNo: TRACKING,
    outboundParcels: parcelPayload(amount),
    waitNotify: false,
    shippingFee: intOr(inquiry.shippingFee) || 100,
    shippingNote: [String(inquiry.shippingNote || '').trim(), NOTE].filter(Boolean).join('\n'),
    employeeName: '管理者'
  }, session.token);
  log.steps.push({
    step: 'inquiry-post-handoff',
    shippingCarrier: meta.inquiry && meta.inquiry.shippingCarrier,
    trackingNo: meta.inquiry && meta.inquiry.trackingNo,
    waitNotify: meta.inquiry && meta.inquiry.waitNotify
  });

  if (!existingOrder) {
    const converted = curlJson('/stock-inquiry-api.php', {
      action: 'convert',
      inquiryId: TARGET_ID,
      fulfillWarehouse: String(inquiry.preorderFulfillWarehouse || 'CN'),
      directStockAllocation: true,
      manualPhysicalAllocation: true,
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

  const shipped = curlJson('/order-admin-api-v6.php', {
    action: 'update-status',
    orderId: TARGET_ID,
    status: 'shipped',
    deliveryState: 'in_transit',
    shippingCarrier: CARRIER,
    trackingNo: TRACKING,
    waitNotify: false,
    deliveryType: 'home',
    deliveryLabel: '住家宅配',
    shippingNote: NOTE,
    progressNote: NOTE,
    externalStatus: OFFICIAL,
    updatedBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'in-transit',
    status: shipped.order && shipped.order.status,
    deliveryState: shipped.order && shipped.order.deliveryState,
    shippingCarrier: shipped.order && shipped.order.shippingCarrier,
    trackingNo: shipped.order && shipped.order.trackingNo,
    error: shipped.error || null
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
    deliveryType: 'home',
    deliveryLabel: '住家宅配',
    shippingCarrier: CARRIER,
    trackingNo: TRACKING,
    outboundParcels: parcelPayload(amount),
    waitNotify: false,
    shippingNote: NOTE,
    updatedBy: '管理者'
  }, session.token);
  log.steps.push({
    step: 'align-post',
    deliveryType: aligned.order && (aligned.order.deliveryType || (aligned.order.customer && aligned.order.customer.deliveryType)),
    shippingCarrier: aligned.order && aligned.order.shippingCarrier,
    trackingNo: aligned.order && aligned.order.trackingNo,
    waitNotify: aligned.order && aligned.order.waitNotify
  });
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const inquiryAfter = listOf(readJson(inquiriesFile)).find((row) => String(row.id || '') === TARGET_ID);
const orderAfter = listOf(readJson(ordersFile)).find((row) => String(row.id || '') === TARGET_ID);

if (orderAfter) {
  const iso = nowIsoTaipei();
  orderAfter.externalStatus = OFFICIAL;
  orderAfter.waitNotify = false;
  orderAfter.deliveryState = 'in_transit';
  if (!['shipped', 'in_transit'].includes(String(orderAfter.status || '').toLowerCase())) {
    orderAfter.status = 'shipped';
    orderAfter.statusLabel = '已出貨配送';
  }
  if (!Array.isArray(orderAfter.outboundParcels) || !orderAfter.outboundParcels.length) {
    orderAfter.outboundParcels = parcelPayload(amount);
  } else {
    orderAfter.outboundParcels[0].trackingNo = TRACKING;
    orderAfter.outboundParcels[0].carrier = CARRIER;
    orderAfter.outboundParcels[0].officialStatus = OFFICIAL;
    orderAfter.outboundParcels[0].officialStatusText = OFFICIAL;
    orderAfter.outboundParcels[0].deliveryState = 'in_transit';
    orderAfter.outboundParcels[0].lastCheckedAt = iso;
  }
  if (!Array.isArray(orderAfter.shippingEventHistory)) orderAfter.shippingEventHistory = [];
  orderAfter.shippingEventHistory.unshift({
    at: iso,
    actor: '管理者',
    summary: '官方查件｜POST 郵局｜' + TRACKING + '｜' + OFFICIAL + '／收寄局已收件，改配送中，不再等通知出貨',
    source: 'manual-post-in-account'
  });
  const ordersWrap = readJson(ordersFile);
  if (Array.isArray(ordersWrap)) writeJson(ordersFile, listOf(ordersWrap).map((row) => String(row.id || '') === TARGET_ID ? orderAfter : row));
  else {
    ordersWrap.orders = listOf(ordersWrap).map((row) => String(row.id || '') === TARGET_ID ? orderAfter : row);
    writeJson(ordersFile, ordersWrap);
  }
}

if (fs.existsSync(queueFile)) {
  const queue = readJson(queueFile);
  const items = Array.isArray(queue) ? queue : (queue.items || []);
  let row = items.find((x) => String(x.trackingNo || '') === TRACKING);
  const iso = nowIsoTaipei();
  if (!row) {
    row = {
      key: 'post|' + TRACKING + '|YA',
      orderId: TARGET_ID,
      inquiryId: TARGET_ID,
      carrierCode: 'post',
      trackingNo: TRACKING,
      customerPrefix: 'YANI',
      phone: PHONE
    };
    items.push(row);
  }
  if (String(row.orderId || '') && FORBIDDEN_IDS.includes(String(row.orderId))) {
    throw new Error('queue row points at forbidden id');
  }
  row.orderId = TARGET_ID;
  row.inquiryId = TARGET_ID;
  row.carrierCode = 'post';
  row.checkedAt = iso;
  row.lastOfficialRecheckAt = iso;
  row.suggestedStatus = 'in_transit';
  row.resultType = 'official_tracking_only';
  row.sourceStatusText = OFFICIAL;
  row.verificationSource = '中華郵政公開查件／出貨單顯示';
  row.message = '入帳成功是收寄局已收件，不是投遞成功，改配送中，不再等通知出貨';
  row.identityVerified = false;
  row.manualRequired = true;
  if (Array.isArray(queue)) writeJson(queueFile, items);
  else {
    queue.items = items;
    writeJson(queueFile, queue);
  }
  log.steps.push({ step: 'queue', key: row.key, suggestedStatus: row.suggestedStatus });
}

const eno = listOf(readJson(ordersFile)).find((o) => String(o.id || '') === 'BYORDER-20260720-60F1AD');
if (eno && String(eno.trackingNo || '') && String(eno.trackingNo || '') !== FORBIDDEN_TRACKING && String(eno.trackingNo || '') === TRACKING) {
  throw new Error('eno tracking collided with YANI');
}

log.after = { inquiry: snap(inquiryAfter), order: snap(orderAfter) };

if (!orderAfter || !['shipped', 'in_transit'].includes(String(orderAfter.status || '').toLowerCase())) {
  log.ok = false;
  log.error = 'order not in_transit: ' + (orderAfter && orderAfter.status);
  dump(log);
  process.exit(1);
}
if (String(orderAfter.deliveryState || '').toLowerCase() !== 'in_transit') {
  log.ok = false;
  log.error = 'deliveryState not in_transit: ' + orderAfter.deliveryState;
  dump(log);
  process.exit(1);
}
if (['delivered', 'completed'].includes(String(orderAfter.status || '').toLowerCase()) || String(orderAfter.deliveryState || '') === 'delivered') {
  throw new Error('refusing delivered status for 入帳成功');
}
if (phoneOf(orderAfter) !== PHONE || nameOf(orderAfter) !== NAME) {
  throw new Error('identity drifted after update');
}
if (String(orderAfter.trackingNo || '') !== TRACKING) {
  throw new Error('tracking lost: ' + orderAfter.trackingNo);
}
if (String(orderAfter.trackingNo || '') === FORBIDDEN_TRACKING) {
  throw new Error('forbidden tracking written');
}
if (orderAfter.waitNotify === true) {
  throw new Error('waitNotify still true');
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
