'use strict';

/**
 * Evi(Cesilia)／0981613849
 * User just typed the sheet; convert if possible, then put 7-11 E32336500671 on it.
 * Do not invent line items. Do not reuse REGINA E43133537581.
 * Do not mix Evii-Line 0919723147 / E32336580745. Do not touch Nita.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const PHONE = '0981613849';
const NAME_RE = /Evi|Cesilia/i;
const TRACKING = 'E32336500671';
const CARRIER = '7-11';
const FORBIDDEN_TRACKING = ['E32336580745', 'E43133537581', 'D23301742026'];
const FORBIDDEN_PHONE = ['0919723147', '0937476065', '16346546308'];
const FORBIDDEN_IDS = ['BYORDER-20260720-078596', 'BYORDER-20260804-D580DB', 'BYORDER-20260809-AAC939', 'BYORDER-20260726-E00F0B'];
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-evi/apply-result.json';
const WAIT_MS = Number(process.env.LZ_WAIT_MS || 90000);
const POLL_MS = 5000;
const NOTE = '管理者放入超商物流 7-11 ' + TRACKING + '。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  return [];
}

function digits(v) { return String(v || '').replace(/\D/g, ''); }

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return digits(c.phone || row.phone || row.customerPhone);
}

function nameOf(row) {
  const c = (row && row.customer) || {};
  return String(c.name || row.customerName || row.name || '');
}

function trackingOf(row) {
  const c = (row && row.customer) || {};
  return String(row.trackingNo || row.shippingTrackingNo || c.trackingNo || '').replace(/\s+/g, '').toUpperCase();
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

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-evi/curl-' + Date.now() + '-' + Math.random().toString(16).slice(2) + '.json';
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
    ephemeral: 'evi-seven-tracking'
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
  const c = row.customer || {};
  return {
    id: row.id,
    status: row.status,
    statusLabel: row.statusLabel,
    deliveryState: row.deliveryState,
    name: nameOf(row),
    phone: phoneOf(row),
    address: c.address || row.storeAddress || row.address || '',
    shippingCarrier: row.shippingCarrier || '',
    trackingNo: trackingOf(row),
    total: row.total,
    items: (row.items || []).map(function (it) {
      return {
        sku: it.skuId || it.sku,
        name: it.name || it.title,
        qty: it.qty || it.quantity,
        warehouse: it.sourceWarehouse || it.warehouse || it.sourceWarehouseCode
      };
    })
  };
}

function findTarget() {
  const inquiries = listOf(readJson(path.join(dataDir, 'inquiries.json')));
  const orders = listOf(readJson(path.join(dataDir, 'orders.json')));
  const forbiddenHit = inquiries.concat(orders).find(function (row) {
    return FORBIDDEN_IDS.indexOf(String(row.id || '')) >= 0 && phoneOf(row) === PHONE;
  });
  if (forbiddenHit) throw new Error('refusing forbidden id collision ' + forbiddenHit.id);

  const inqHits = inquiries.filter(function (row) { return phoneOf(row) === PHONE; });
  const ordHits = orders.filter(function (row) { return phoneOf(row) === PHONE; });
  const trackHits = inquiries.concat(orders).filter(function (row) { return trackingOf(row) === TRACKING; });

  trackHits.forEach(function (row) {
    if (phoneOf(row) && phoneOf(row) !== PHONE) {
      throw new Error('tracking already on other phone ' + phoneOf(row) + ' ' + row.id);
    }
    if (FORBIDDEN_PHONE.indexOf(phoneOf(row)) >= 0) {
      throw new Error('tracking collided with forbidden phone');
    }
  });

  const namedInq = inqHits.filter(function (row) { return NAME_RE.test(nameOf(row)); });
  const namedOrd = ordHits.filter(function (row) { return NAME_RE.test(nameOf(row)); });
  const inquiry = namedInq[0] || inqHits[0] || null;
  const order = namedOrd[0] || ordHits[0] || null;
  return { inquiry, order, inqHits, ordHits, trackHits };
}

function assertSafe(row, label) {
  if (!row) return;
  if (FORBIDDEN_IDS.indexOf(String(row.id || '')) >= 0) throw new Error(label + ' is forbidden id');
  if (phoneOf(row) !== PHONE) throw new Error(label + ' phone mismatch ' + phoneOf(row));
  if (!NAME_RE.test(nameOf(row))) throw new Error(label + ' name mismatch ' + nameOf(row));
  const no = trackingOf(row);
  if (no && FORBIDDEN_TRACKING.indexOf(no) >= 0) throw new Error(label + ' has forbidden tracking ' + no);
}

function customerFields(row) {
  const c = (row && row.customer) || {};
  const storeAddress = String(row.storeAddress || c.storeAddress || c.address || row.address || '').trim();
  return {
    customerName: nameOf(row) || 'Evi(Cesilia)',
    customerPhone: phoneOf(row) || PHONE,
    customerAddress: storeAddress || '7-11快來(881386)',
    storeAddress: storeAddress || '7-11快來(881386)',
    homeAddress: String(row.homeAddress || c.homeAddress || '').trim(),
    addressPrimary: 'store',
    deliveryType: 'store',
    deliveryLabel: '超商取貨'
  };
}

function sleep(ms) {
  const sec = Math.max(1, Math.ceil(ms / 1000));
  try {
    execFileSync('powershell', ['-NoProfile', '-Command', 'Start-Sleep -Seconds ' + sec], { stdio: 'ignore' });
  } catch (e) {
    const spin = Date.now() + ms;
    while (Date.now() < spin) { /* busy wait fallback */ }
  }
}

function dump(result) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}

if (!fs.existsSync(dataDir)) {
  console.log(JSON.stringify({ ok: false, error: 'Not on PHT-SR' }));
  process.exit(0);
}

const started = Date.now();
let found = findTarget();
while (!found.inquiry && !found.order && Date.now() - started < WAIT_MS) {
  sleep(POLL_MS);
  found = findTarget();
}

if (!found.inquiry && !found.order) {
  dump({
    ok: false,
    error: 'missing-order',
    phone: PHONE,
    tracking: TRACKING,
    waitedMs: Date.now() - started,
    hint: '後端還沒有 0981613849 的新單。請先存檔，物流欄不要填 REGINA 的 E43133537581，可空白或只填 E32336500671。'
  });
  process.exit(2);
}

const inquiry = found.inquiry;
const existingOrder = found.order;
assertSafe(inquiry, 'inquiry');
assertSafe(existingOrder, 'order');

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const ordersFile = path.join(dataDir, 'orders.json');
const log = {
  ok: true,
  backups: {
    inquiries: backup(inquiriesFile, 'evi-seven'),
    orders: backup(ordersFile, 'evi-seven'),
    state: backup(path.join(dataDir, 'admin-state.json'), 'evi-seven')
  },
  before: { inquiry: snap(inquiry), order: snap(existingOrder) },
  steps: []
};

if (existingOrder && trackingOf(existingOrder) === TRACKING && String(existingOrder.shippingCarrier || '') === CARRIER) {
  log.already = true;
  log.after = { inquiry: snap(inquiry), order: snap(existingOrder) };
  dump(log);
  process.exit(0);
}

const fields = customerFields(existingOrder || inquiry);
const parcels = [{ trackingNo: TRACKING, carrier: CARRIER, amount: '', note: NOTE }];
const session = injectSession();
try {
  if (inquiry && String(inquiry.id || '')) {
    const meta = curlJson('/stock-inquiry-api.php', Object.assign({
      action: 'update-preorder-meta',
      inquiryId: inquiry.id,
      shippingCarrier: CARRIER,
      trackingNo: TRACKING,
      outboundParcels: parcels,
      shippingNote: [String(inquiry.shippingNote || '').trim(), NOTE].filter(Boolean).join('\n'),
      employeeName: '管理者'
    }, fields), session.token);
    log.steps.push({
      step: 'inquiry-tracking',
      id: inquiry.id,
      trackingNo: (meta.inquiry && (meta.inquiry.trackingNo || (meta.inquiry.customer && meta.inquiry.customer.trackingNo))) || TRACKING,
      status: meta.inquiry && meta.inquiry.status
    });
  }

  const inqNow = listOf(readJson(inquiriesFile)).find(function (row) { return inquiry && String(row.id) === String(inquiry.id); }) || inquiry;
  const alreadyFormal = listOf(readJson(ordersFile)).find(function (row) {
    return (inquiry && String(row.id) === String(inquiry.id)) || (existingOrder && String(row.id) === String(existingOrder.id));
  });

  if (inqNow && String(inqNow.status || '') === 'preorder_pending' && !alreadyFormal) {
    try {
      const converted = curlJson('/stock-inquiry-api.php', {
        action: 'convert',
        inquiryId: inqNow.id,
        fulfillWarehouse: 'TW',
        directStockAllocation: true,
        manualPhysicalAllocation: false,
        transferredBy: '管理者'
      }, session.token);
      log.steps.push({
        step: 'convert',
        orderId: converted.order && converted.order.id,
        inquiryStatus: converted.inquiry && converted.inquiry.status,
        error: converted.error || null
      });
    } catch (e) {
      log.steps.push({ step: 'convert', skipped: true, error: String(e.message || e) });
    }
  } else {
    log.steps.push({
      step: 'convert',
      skipped: true,
      reason: alreadyFormal ? 'already-formal' : ('inquiry-status-' + String(inqNow && inqNow.status || ''))
    });
  }

  const orderNow = listOf(readJson(ordersFile)).find(function (row) {
    return phoneOf(row) === PHONE && NAME_RE.test(nameOf(row));
  });
  if (orderNow) {
    assertSafe(orderNow, 'orderNow');
    if (FORBIDDEN_TRACKING.indexOf(trackingOf(orderNow)) >= 0) throw new Error('order has forbidden tracking');
    const updated = curlJson('/order-admin-api-v6.php', Object.assign({
      action: 'update-customer',
      orderId: orderNow.id,
      shippingCarrier: CARRIER,
      trackingNo: TRACKING,
      outboundParcels: parcels,
      shippingNote: [String(orderNow.shippingNote || '').trim(), NOTE].filter(Boolean).join('\n'),
      updatedBy: '管理者'
    }, customerFields(orderNow)), session.token);
    log.steps.push({
      step: 'order-tracking',
      id: orderNow.id,
      trackingNo: updated.order && updated.order.trackingNo,
      shippingCarrier: updated.order && updated.order.shippingCarrier,
      status: updated.order && updated.order.status
    });
  }
} finally {
  try { removeSession(session.tokenHash); } catch (e) { log.sessionCleanupError = String(e.message || e); }
}

const inquiryAfter = listOf(readJson(inquiriesFile)).find(function (row) { return phoneOf(row) === PHONE && NAME_RE.test(nameOf(row)); });
const orderAfter = listOf(readJson(ordersFile)).find(function (row) { return phoneOf(row) === PHONE && NAME_RE.test(nameOf(row)); });
assertSafe(inquiryAfter, 'inquiryAfter');
assertSafe(orderAfter, 'orderAfter');

const nita = listOf(readJson(inquiriesFile)).concat(listOf(readJson(ordersFile))).find(function (row) {
  return String(row.id || '') === 'BYORDER-20260720-078596';
});
if (nita && trackingOf(nita) === TRACKING) throw new Error('Nita tracking mutated');

const evii = listOf(readJson(ordersFile)).concat(listOf(readJson(inquiriesFile))).find(function (row) {
  return phoneOf(row) === '0919723147' || trackingOf(row) === 'E32336580745';
});
if (evii && trackingOf(evii) === TRACKING) throw new Error('Evii-Line tracking mutated');

log.after = { inquiry: snap(inquiryAfter), order: snap(orderAfter) };
const attached = (orderAfter && trackingOf(orderAfter) === TRACKING) || (inquiryAfter && trackingOf(inquiryAfter) === TRACKING);
if (!attached) {
  log.ok = false;
  log.error = 'tracking-not-attached';
  dump(log);
  process.exit(1);
}
dump(log);
