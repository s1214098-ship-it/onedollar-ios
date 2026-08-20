'use strict';

/**
 * SUTIAH 0900044250 had two cards for the same GB2+OLAN66 NT$1150 / 7-11 加賀屋.
 * Keep delivered BYORDER-20260731-19685C (E28558606160).
 * Cancelled preorder BYORDER-20260717-5E0A99 stays cancelled, with a pointer note.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const KEEP_ID = 'BYORDER-20260731-19685C';
const DUP_ID = 'BYORDER-20260717-5E0A99';
const PHONE = '0900044250';
const TRACKING = 'E28558606160';
const KEEP_NOTE = '同客人舊預購 ' + DUP_ID + ' 已取消。本單為實際出貨／7-11 ' + TRACKING + '。';
const DUP_NOTE = '重複打單。實際出貨單 ' + KEEP_ID + '／7-11 ' + TRACKING + '（已完成配送）。這張預購不保留。';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function atomicWrite(file, data) {
  const json = JSON.stringify(data, null, 2) + '\n';
  const tmp = file + '.tmp-sutiah-' + process.pid + '-' + Date.now();
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

function backup(file, tag) {
  const dir = path.join(dataDir, 'audit');
  fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + Date.now());
  fs.copyFileSync(file, dest);
  return dest;
}

function listOf(wrap, key) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap[key])) return wrap[key];
  return [];
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || row.customerPhone || '').replace(/\D/g, '');
}

function appendNote(row, note, extra) {
  const prev = String(row.shippingNote || row.note || '').trim();
  if (prev.indexOf(KEEP_ID) !== -1 && prev.indexOf(DUP_ID) !== -1) return false;
  row.shippingNote = prev ? prev + '\n' + note : note;
  if (extra) Object.assign(row, extra);
  row.updatedAt = new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Taipei' }).replace(' ', 'T') + '+08:00';
  return true;
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const ordersFile = path.join(dataDir, 'orders.json');
const inquiriesFile = path.join(dataDir, 'inquiries.json');
console.log('backup orders', backup(ordersFile, 'sutiah-keep'));
console.log('backup inquiries', backup(inquiriesFile, 'sutiah-keep'));

const ordersWrap = readJson(ordersFile);
const inquiriesWrap = readJson(inquiriesFile);
const orders = listOf(ordersWrap, 'orders');
const inquiries = listOf(inquiriesWrap, 'inquiries');

const keep = orders.find((row) => String(row.id || '') === KEEP_ID);
const dup = inquiries.find((row) => String(row.id || '') === DUP_ID);
if (!keep) throw new Error('keep order missing');
if (!dup) throw new Error('dup inquiry missing');
if (phoneOf(keep) !== PHONE) throw new Error('keep phone mismatch');
if (phoneOf(dup) !== PHONE) throw new Error('dup phone mismatch');
if (String(keep.trackingNo || '') !== TRACKING) throw new Error('keep tracking mismatch');
if (!['delivered', 'completed'].includes(String(keep.status || '').toLowerCase())) {
  throw new Error('keep order is not delivered: ' + keep.status);
}

const hits = {
  keep: appendNote(keep, KEEP_NOTE, { relatedInquiryId: DUP_ID, relatedOrderId: KEEP_ID }),
  dup: appendNote(dup, DUP_NOTE, {
    relatedOrderId: KEEP_ID,
    cancelledReason: dup.cancelledReason || '重複打單，保留 ' + KEEP_ID
  })
};

atomicWrite(ordersFile, ordersWrap);
atomicWrite(inquiriesFile, inquiriesWrap);
console.log(JSON.stringify({
  keep: { id: keep.id, status: keep.status, trackingNo: keep.trackingNo, noted: hits.keep },
  dup: { id: dup.id, status: dup.status, noted: hits.dup }
}, null, 2));
