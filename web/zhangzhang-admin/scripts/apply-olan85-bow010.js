'use strict';

/**
 * Live apply: 迪士尼米奇嘉喜 / 465535844280731
 * 餐碗類誤用 OLAN85／OLAN85P36593 → BOW010／BOW010P36593
 */

const fs = require('fs');
const path = require('path');
const { recodeTree, countNeedle } = require('./lz-olan85-bow010.js');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const TRACK = '465535844280731';
const ITEM_ID = 'FRTI-1785441845574-TU3IB';
const INQUIRY_ID = 'BYORDER-20260731-19716B';
const FORBIDDEN_NITA = 'BYORDER-20260720-078596';
const FORBIDDEN_BOW007 = 'BOW007P901907';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-seven/olan85-bow010-result.json';

const FILES = [
  'products.json',
  'skus.json',
  'freight-forwarding-tracking.json',
  'inquiries.json',
  'purchase-receiving-documents.json',
  'public-catalog.json',
  'admin-state.json',
  'admin-state-light-cache.json',
  'admin-state-members-cache.json'
];

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap.items)) return wrap.items;
  if (wrap && Array.isArray(wrap.products)) return wrap.products;
  if (wrap && Array.isArray(wrap.skus)) return wrap.skus;
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.documents)) return wrap.documents;
  return [];
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
  const tmp = file + '.tmp-bow010-' + process.pid + '-' + Date.now();
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

function findById(rows, id) {
  return rows.find(function (row) { return row && String(row.id || '') === String(id); }) || null;
}

function productOf(wrap) {
  return listOf(wrap).find(function (row) { return row && row.id === 'p-olan85'; }) || null;
}

function skuOf(wrap) {
  return listOf(wrap).find(function (row) {
    return row && (row.id === 'BOW010P36593-TW' || row.id === 'OLAN85P36593-TW');
  }) || null;
}

function dump(result) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}

if (!fs.existsSync(dataDir)) {
  dump({ ok: false, error: 'Not on PHT-SR' });
  process.exit(0);
}

const before = {};
FILES.forEach(function (name) {
  const file = path.join(dataDir, name);
  if (!fs.existsSync(file)) return;
  before[name] = readJson(file);
});

const productBefore = productOf(before['products.json']);
const freightBefore = findById(listOf(before['freight-forwarding-tracking.json']), ITEM_ID);
const inquiryBefore = findById(listOf(before['inquiries.json']), INQUIRY_ID);
const nitaBefore = JSON.stringify(before['inquiries.json'] || {}).indexOf(FORBIDDEN_NITA) >= 0
  ? countNeedle(before['inquiries.json'], FORBIDDEN_NITA)
  : 0;
const bow007Before = countNeedle(before['products.json'], FORBIDDEN_BOW007)
  + countNeedle(before['skus.json'], FORBIDDEN_BOW007);

if (!productBefore || productBefore.code !== 'OLAN85') {
  dump({ ok: false, error: 'unexpected-product', product: productBefore });
  process.exit(1);
}
if (!freightBefore || String(freightBefore.trackingNo || '') !== TRACK) {
  dump({ ok: false, error: 'unexpected-freight', freight: freightBefore && freightBefore.id });
  process.exit(1);
}
if (productBefore.category !== '餐碗類') {
  dump({ ok: false, error: 'category-not-bowl', category: productBefore.category });
  process.exit(1);
}

const backups = {};
const after = {};
FILES.forEach(function (name) {
  const file = path.join(dataDir, name);
  if (!fs.existsSync(file)) return;
  backups[name] = backup(file, 'olan85-bow010');
  const next = recodeTree(before[name]);
  const leftover = countNeedle(next, 'OLAN85');
  if (leftover) throw new Error(name + ' still has OLAN85 x' + leftover);
  after[name] = next;
  atomicWrite(file, next);
});

const productAfter = productOf(after['products.json']);
const skuAfter = skuOf(after['skus.json']);
const freightAfter = findById(listOf(after['freight-forwarding-tracking.json']), ITEM_ID);
const inquiryAfter = findById(listOf(after['inquiries.json']), INQUIRY_ID);
const nitaAfter = countNeedle(after['inquiries.json'], FORBIDDEN_NITA);
const bow007After = countNeedle(after['products.json'], FORBIDDEN_BOW007)
  + countNeedle(after['skus.json'], FORBIDDEN_BOW007);

if (!productAfter || productAfter.code !== 'BOW010' || productAfter.id !== 'p-olan85') {
  throw new Error('product recode failed');
}
if (!skuAfter || skuAfter.id !== 'BOW010P36593-TW' || String(skuAfter.productCode || '') !== 'BOW010') {
  throw new Error('sku recode failed');
}
if (!freightAfter || freightAfter.trackingNo !== TRACK || freightAfter.productCode !== 'BOW010') {
  throw new Error('freight recode failed');
}
if (String(freightAfter.productFiledBarcode || freightAfter.barcode || '') !== 'BOW010P36593') {
  throw new Error('freight barcode recode failed');
}
if (inquiryAfter) {
  const line = (inquiryAfter.items || []).find(function (item) {
    return /BOW010|OLAN85/.test(JSON.stringify(item));
  }) || {};
  if (String(line.code || line.productCode || '') !== 'BOW010') throw new Error('inquiry code recode failed');
}
if (nitaAfter !== nitaBefore) throw new Error('Nita inquiry mutated');
if (bow007After !== bow007Before) throw new Error('BOW007 sku mutated');

dump({
  ok: true,
  from: 'OLAN85 / OLAN85P36593',
  to: 'BOW010 / BOW010P36593',
  trackingNo: TRACK,
  freightItemId: ITEM_ID,
  inquiryId: inquiryAfter && inquiryAfter.id,
  product: { id: productAfter.id, code: productAfter.code, title: productAfter.title, category: productAfter.category },
  sku: { id: skuAfter.id, barcode: skuAfter.companyBarcode || skuAfter.barcode, stock: skuAfter.stock },
  freight: {
    productCode: freightAfter.productCode,
    barcode: freightAfter.productFiledBarcode || freightAfter.barcode,
    skuId: freightAfter.productFiledSkuId || freightAfter.skuId,
    progress: freightAfter.progress
  },
  backups: backups
});
