'use strict';

/**
 * Ara angin Tiktok（0965522489）只有退貨紀錄，不應因退貨自動／誤列入黑名單。
 * 移除 member-risk.json 中這筆；保留 returnHistory。
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const PHONE = '0965522489';
const NAME = 'Ara angin Tiktok';

function backup(file) {
  const dir = path.join(dataDir, 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dest = path.join(dir, path.basename(file) + '.ara-unblacklist-' + stamp);
  fs.copyFileSync(file, dest);
  return dest;
}

function phoneOf(value) {
  return String(value || '').replace(/\D+/g, '');
}

function writeJson(file, data) {
  const tmp = file + '.tmp-' + Date.now();
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2) + '\n');
  fs.renameSync(tmp, file);
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const riskFile = path.join(dataDir, 'member-risk.json');
const ordersFile = path.join(dataDir, 'orders.json');
const inquiriesFile = path.join(dataDir, 'inquiries.json');

console.log('backup member-risk', backup(riskFile));

const risk = JSON.parse(fs.readFileSync(riskFile, 'utf8').replace(/\0+$/g, '').trimEnd());
const before = Array.isArray(risk.rows) ? risk.rows.slice() : [];
risk.rows = before.filter(function (row) {
  if (!row) return false;
  if (phoneOf(row.phone) !== PHONE) return true;
  if (NAME && String(row.name || '').trim() !== NAME) return true;
  return false;
});
const removed = before.filter(function (row) {
  return row && phoneOf(row.phone) === PHONE && String(row.name || '').trim() === NAME;
});

if (removed.length) {
  risk.updatedAt = new Date().toISOString();
  writeJson(riskFile, risk);
}

function clearOrderStamp(file, key) {
  if (!fs.existsSync(file)) return [];
  console.log('backup', path.basename(file), backup(file));
  const wrap = JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
  const list = Array.isArray(wrap) ? wrap : (wrap[key] || wrap.rows || wrap.items || []);
  const hits = [];
  list.forEach(function (row) {
    if (!row) return;
    const c = row.customer || {};
    if (phoneOf(c.phone || row.phone) !== PHONE) return;
    if (NAME && String(c.name || row.customerName || row.name || '').trim() !== NAME) return;
    if (!row.blacklistHit && !row.blacklisted) return;
    hits.push({ id: row.id || row.orderId, name: c.name || row.customerName });
    row.blacklistHit = false;
    row.blacklisted = false;
    row.blacklistReason = '';
    row.blacklistMatchType = '';
    row.blacklistConfirmedAt = '';
    row.blacklistAskSales = false;
    row.updatedAt = new Date().toISOString();
  });
  if (hits.length) writeJson(file, wrap);
  return hits;
}

const orderHits = clearOrderStamp(ordersFile, 'orders');
const inquiryHits = clearOrderStamp(inquiriesFile, 'inquiries');

console.log(JSON.stringify({ removed: removed, orderHits: orderHits, inquiryHits: inquiryHits }, null, 2));
