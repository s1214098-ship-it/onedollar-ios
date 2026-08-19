'use strict';

/**
 * Show POST official text (入帳成功) on order-tracking compact cards.
 * 入帳成功 still maps to 配送中, not 取件完成.
 *
 * Cache-bust: admin.js?v=20260819-post-ruzhang-1
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const BUST = '20260819-post-ruzhang-1';
const PREV = '20260819-blacklist-card-1';
const adminJs = path.join(root, 'assets', 'admin.js');

function backup(file) {
  const dir = path.join(root, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.post-ruzhang-' + new Date().toISOString().replace(/[:.]/g, '-'));
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  const i = src.indexOf(oldStr);
  if (i < 0) {
    if (src.indexOf('function orderTrackingOfficialStatusText(') !== -1) {
      console.log('already:', label);
      return src;
    }
    throw new Error('missing snippet: ' + label);
  }
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error('not unique: ' + label);
  console.log('patched:', label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const OLD = `  function orderTrackingStatusDetail(entry) {
    var row = entry && entry.record || {};
    var order = entry && entry.order || {};
    if (entry && entry.stage === 'ordered' && order.trackingNo && !orderTrackingHasCarrierHandoff(order)) return '物流單號已建立／尚未確認交寄';
    if (entry.category === 'preorder') {
      if (preorderInquiryConverted(row) && !entry.order) return '已轉單，但找不到正式訂單，請核對資料';
      return [
        row.purchaseStatusLabel || (row.purchaseStatus ? preorderPurchaseStatusLabel(normalizePreorderPurchaseStatus(row.purchaseStatus)) : ''),
        row.preorderWaitStatusLabel || (row.preorderWaitStatus ? preorderWaitStatusLabel(row.preorderWaitStatus) : ''),
        order.statusLabel || order.status,
        order.deliveryState
      ].filter(Boolean).join('／') || orderTrackingStageLabel(entry.stage);
    }
    return [order.statusLabel || row.statusLabel || order.status || row.status, order.deliveryState || row.deliveryState].filter(Boolean).join('／') || orderTrackingStageLabel(entry.stage);
  }`;

const NEW = `  function orderTrackingOfficialStatusText(entry) {
    var row = entry && entry.record || {};
    var order = entry && entry.order || {};
    var parcels = [].concat(
      Array.isArray(order.outboundParcels) ? order.outboundParcels : [],
      Array.isArray(row.outboundParcels) ? row.outboundParcels : []
    );
    var fromParcel = '';
    parcels.some(function (parcel) {
      fromParcel = String(parcel && (parcel.officialStatus || parcel.officialStatusText) || '').trim();
      return !!fromParcel;
    });
    return fromParcel || String(order.externalStatus || row.externalStatus || '').trim();
  }

  function orderTrackingStatusDetail(entry) {
    var row = entry && entry.record || {};
    var order = entry && entry.order || {};
    var official = orderTrackingOfficialStatusText(entry);
    if (entry && entry.stage === 'ordered' && order.trackingNo && !orderTrackingHasCarrierHandoff(order)) return '物流單號已建立／尚未確認交寄';
    if (entry.category === 'preorder') {
      if (preorderInquiryConverted(row) && !entry.order) return '已轉單，但找不到正式訂單，請核對資料';
      return [
        row.purchaseStatusLabel || (row.purchaseStatus ? preorderPurchaseStatusLabel(normalizePreorderPurchaseStatus(row.purchaseStatus)) : ''),
        row.preorderWaitStatusLabel || (row.preorderWaitStatus ? preorderWaitStatusLabel(row.preorderWaitStatus) : ''),
        official || order.statusLabel || order.status,
        official ? '' : order.deliveryState
      ].filter(Boolean).join('／') || orderTrackingStageLabel(entry.stage);
    }
    return [
      order.statusLabel || row.statusLabel || order.status || row.status,
      official || order.deliveryState || row.deliveryState
    ].filter(Boolean).join('／') || orderTrackingStageLabel(entry.stage);
  }`;

if (!fs.existsSync(adminJs)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

console.log('backup', backup(adminJs));
let src = fs.readFileSync(adminJs, 'utf8');
src = replaceOnce(src, OLD, NEW, 'status detail shows official 入帳成功');
fs.writeFileSync(adminJs, src);

const names = fs.readdirSync(root).filter(function (name) {
  return /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name);
});
let n = 0;
names.forEach(function (name) {
  const file = path.join(root, name);
  let html = fs.readFileSync(file, 'utf8');
  const orig = html;
  html = html.split('admin.js?v=' + PREV).join('admin.js?v=' + BUST);
  if (html !== orig) {
    fs.writeFileSync(file, html);
    n++;
    console.log('cache-bust', name);
  }
});
console.log('html files busted', n, BUST);
