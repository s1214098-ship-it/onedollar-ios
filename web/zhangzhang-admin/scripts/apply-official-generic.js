'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

function text(v) { return String(v == null ? '' : v).trim(); }

function mapStatus(carrier, msg) {
  const t = String(msg || '');
  if (/無資料|查無資料|查無|查不到|找不到資料|找不到/.test(t)) return { suggested: '', type: 'official_no_data' };
  if (/已完成包裹取件|已完成取件|入帳成功|投遞成功|已妥投|完成投遞|收件人已領|已取件|配送成功/.test(t)) {
    return { suggested: 'delivered', type: 'official_tracking_only' };
  }
  if (/指定退貨門市|退回物流|退貨回|退回原/.test(t)) return { suggested: 'returned', type: 'official_tracking_only' };
  if (/配達取件門市|到達門市|配達取件店舖|貨件配達|招領/.test(t)) return { suggested: 'arrived_store', type: 'official_tracking_only' };
  if (/尚未至門市寄件|訂單已成立/.test(t)) return { suggested: 'pending', type: 'official_tracking_only' };
  if (/寄件門市已收件|送往物流|物流中心|配送中|等待配送|收寄|處理中/.test(t)) return { suggested: 'in_transit', type: 'official_tracking_only' };
  return { suggested: 'in_transit', type: 'official_tracking_only' };
}

const ROOT = path.join(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const queue = JSON.parse(fs.readFileSync(path.join(DATA, 'carrier-tracking-queue.json'), 'utf8'));
const byNo = {};
for (const row of queue.items || []) {
  if (!row || !row.trackingNo) continue;
  const no = String(row.trackingNo).replace(/\s+/g, '').toUpperCase();
  if (!byNo[no]) byNo[no] = [];
  byNo[no].push(row);
}

const official = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const source = process.argv[3] || '官網公開查件';
const skipped = [];
const results = [];
for (const r of official) {
  const no = String(r.trackingNo || r.paymentNo || r.MAILNO || '').replace(/\s+/g, '').toUpperCase();
  const rows = byNo[no] || [];
  if (!rows.length) {
    skipped.push(no);
    continue;
  }
  const msg = text(r.statusMessage || r.STATUS || r.status || '');
  const office = text(r.recStore || r.BRHNC || r.office || '');
  const when = text(r.DATETIME || r.recDate || r.checkedAt || '');
  const mapped = mapStatus('', msg);
  const sourceStatusText = msg + (office ? '（' + office + '）' : '') + (when ? ' ' + when : '');
  for (const row of rows) {
    results.push({
      key: row.key,
      orderId: row.orderId,
      carrierCode: row.carrierCode,
      trackingNo: row.trackingNo,
      customerPrefix: row.customerPrefix,
      suggestedStatus: mapped.suggested,
      resultType: mapped.type,
      identityVerified: false,
      manualRequired: true,
      verificationSource: source,
      sourceStatusText,
      officialPickupDeadline: '',
      message: sourceStatusText,
    });
  }
}

if (!results.length) {
  console.log(JSON.stringify({ ok: false, applied: 0, skipped }));
  process.exit(1);
}

const out = path.join(DATA, 'tmp-apply-latest.json');
fs.writeFileSync(out, JSON.stringify({ results }, null, 2) + '\n');
const applied = spawnSync(process.execPath, [path.join(__dirname, 'apply-carrier-results.js'), out], { encoding: 'utf8' });
process.stdout.write(applied.stdout || '');
if (applied.stderr) process.stderr.write(applied.stderr);
if (skipped.length) console.log(JSON.stringify({ skipped }));
if (applied.status) process.exit(applied.status);
