'use strict';

/**
 * Map ShopMore (7-11) official query JSON onto the live carrier queue.
 *
 * Official text uses 已完成包裹配件 (配件, not 取件) and 包裹已送抵取件門市
 * (送抵, not 配達). Expired pickup deadlines must not override a completed
 * pickup into returned. 「今日23:59後退回物流中心」stays arrived_store.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

function mapStatus(msg) {
  const t = String(msg || '');
  if (/將退回/.test(t) && /今日|23\s*[:：]?\s*59/.test(t)) return 'arrived_store';
  if (/指定退貨門市|退貨回原|已退回物流|退貨完成/.test(t)) return 'returned';
  if (/已完成包裹取件|已完成包裹配件|已完成取件/.test(t) && /退貨/.test(t)) return 'returned';
  if (/已完成包裹取件|已完成包裹配件|已完成取件/.test(t)) return 'delivered';
  if (/配達取件門市|送抵取件門市|到達門市|配達取件店舖|貨件配達/.test(t)) return 'arrived_store';
  if (/尚未至門市寄件|訂單已成立|訂單成立未寄件/.test(t)) return 'pending';
  if (/寄件門市已收件|送往物流|物流中心|配送中|等待配送/.test(t)) return 'in_transit';
  if (/查無|無資料/.test(t)) return 'official_no_data';
  return 'in_transit';
}

function isoDate(d) {
  const m = String(d || '').match(/(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/);
  if (!m) return '';
  return m[1] + '-' + m[2].padStart(2, '0') + '-' + m[3].padStart(2, '0');
}

function trackingKey(value) {
  return String(value || '').replace(/\s+/g, '').toUpperCase();
}

function resolveDataDir() {
  const localData = path.join(__dirname, '..', 'data');
  if (fs.existsSync(path.join(localData, 'carrier-tracking-queue.json'))) return localData;
  return path.join('U:/Web/lingzanzan-staging/data');
}

function buildResults(official, queue, source) {
  const byNo = {};
  for (const row of queue.items || []) {
    if (!row || !row.trackingNo) continue;
    const no = trackingKey(row.trackingNo);
    if (!byNo[no]) byNo[no] = [];
    byNo[no].push(row);
  }

  const skipped = [];
  const results = [];
  for (const r of official || []) {
    const no = trackingKey(r.trackingNo || r.paymentNo);
    const rows = byNo[no] || [];
    if (!rows.length) {
      skipped.push(no);
      continue;
    }
    const status = mapStatus(r.statusMessage);
    const deadline = isoDate(r.pickupDeadline) || isoDate(r.recDate);
    const store = r.recStore ? '（' + r.recStore + '）' : '';
    for (const row of rows) {
      results.push({
        key: row.key,
        orderId: row.orderId,
        carrierCode: row.carrierCode,
        trackingNo: row.trackingNo,
        customerPrefix: row.customerPrefix,
        suggestedStatus: status === 'official_no_data' ? '' : status,
        resultType: status === 'official_no_data' ? 'official_no_data' : 'official_tracking_only',
        identityVerified: false,
        manualRequired: true,
        verificationSource: source,
        sourceStatusText: String(r.statusMessage || '') + store,
        officialPickupDeadline: deadline,
        message: String(r.statusMessage || '') + (deadline ? '，取貨截止 ' + deadline : '')
      });
    }
  }
  return { results, skipped };
}

function applyFromFile(jsonPath, source) {
  const DATA = resolveDataDir();
  const queue = JSON.parse(fs.readFileSync(path.join(DATA, 'carrier-tracking-queue.json'), 'utf8'));
  const official = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
  const { results, skipped } = buildResults(official, queue, source || '7-11 SHOPMORE 公開查件');
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
}

module.exports = { mapStatus, isoDate, trackingKey, buildResults };

if (require.main === module) {
  applyFromFile(process.argv[2], process.argv[3]);
}
