'use strict';

/**
 * Stamp Ana 0979805508 inquiries as 詐騙 cancelled and keep member-risk row.
 * Does NOT touch other Ana phones.
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const PHONE = '0979805508';
const IDS = {
  'BYORDER-20260816-A03D5A': true,
  'BYORDER-20260721-59ED9D': true,
  'REVERT-BYORDER-20260816-A03D5A': true
};

function backup(file) {
  const dir = path.join(dataDir, 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dest = path.join(dir, path.basename(file) + '.ana-blacklist-' + stamp);
  fs.copyFileSync(file, dest);
  return dest;
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function writeJson(file, data) {
  const tmp = file + '.tmp-' + Date.now();
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2) + '\n');
  fs.renameSync(tmp, file);
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return String(c.phone || row.phone || row.customerPhone || '').replace(/\D+/g, '');
}

function stampInquiry(row) {
  const now = '2026-08-19T12:40:00+08:00';
  row.status = 'cancelled';
  row.statusLabel = '詐騙／已列入黑名單';
  row.cancelledReason = '詐騙';
  row.cancelledBy = row.cancelledBy || '管理者';
  row.cancelledAt = row.cancelledAt || now;
  row.blacklisted = true;
  row.blacklistReason = '詐騙';
  row.updatedAt = now;
  if (!row.adminNote) row.adminNote = '0979805508 已封鎖黑名單／詐騙，不再出現在待處理預購';
  else if (String(row.adminNote).indexOf('黑名單') === -1) row.adminNote += '／已封鎖黑名單';
  return row;
}

if (!fs.existsSync(dataDir)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const ordersFile = path.join(dataDir, 'orders.json');
const riskFile = path.join(dataDir, 'member-risk.json');

console.log('backup inquiries', backup(inquiriesFile));
console.log('backup orders', backup(ordersFile));
console.log('backup member-risk', backup(riskFile));

const inquiries = readJson(inquiriesFile);
const orders = readJson(ordersFile);
const risk = readJson(riskFile);
const inquiryList = Array.isArray(inquiries) ? inquiries : (inquiries.rows || inquiries.items || []);
const orderList = Array.isArray(orders) ? orders : (orders.rows || orders.items || []);
const riskRows = Array.isArray(risk.rows) ? risk.rows : [];

const inquiryHits = [];
inquiryList.forEach(function (row) {
  if (!row || !IDS[String(row.id || '')]) return;
  if (phoneOf(row) && phoneOf(row) !== PHONE) return;
  stampInquiry(row);
  inquiryHits.push({ id: row.id, status: row.status, cancelledReason: row.cancelledReason, statusLabel: row.statusLabel });
});

const orderHits = [];
orderList.forEach(function (row) {
  if (!row || !IDS[String(row.id || '')]) return;
  if (phoneOf(row) && phoneOf(row) !== PHONE) return;
  row.cancelledReason = '詐騙';
  row.blacklisted = true;
  row.blacklistReason = '詐騙';
  row.statusLabel = row.statusLabel && /詐騙/.test(row.statusLabel) ? row.statusLabel : '客戶取消／商品已回庫／詐騙黑名單';
  orderHits.push({ id: row.id, status: row.status, cancelledReason: row.cancelledReason });
});

let riskHit = riskRows.filter(function (row) { return String(row.phone || '').replace(/\D+/g, '') === PHONE; });
if (!riskHit.length) {
  riskRows.push({
    phone: PHONE,
    name: 'Ana(Ana Bunda Ana)',
    reason: '詐騙',
    createdBy: '管理者',
    createdAt: new Date().toISOString()
  });
  risk.rows = riskRows;
  risk.updatedAt = new Date().toISOString();
  writeJson(riskFile, risk);
  riskHit = riskRows.filter(function (row) { return String(row.phone || '').replace(/\D+/g, '') === PHONE; });
} else {
  riskHit.forEach(function (row) {
    if (!/詐騙/.test(String(row.reason || ''))) row.reason = '詐騙';
  });
  risk.updatedAt = new Date().toISOString();
  writeJson(riskFile, risk);
}

writeJson(inquiriesFile, Array.isArray(inquiries) ? inquiryList : inquiries);
writeJson(ordersFile, Array.isArray(orders) ? orderList : orders);

console.log(JSON.stringify({ inquiryHits: inquiryHits, orderHits: orderHits, riskHit: riskHit }, null, 2));
