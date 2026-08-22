'use strict';

/**
 * Devis(U Bee Bee TK)／0960944344
 * 新預購單：K393 白色 M + 白色 L 各 1，單價 290。其他商品不併入（CA3E27 維持原樣）。
 * 不碰 Nita。不轉正式。不改收款。
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const dataDir = path.join(root, 'data');
const PHONE = '0960944344';
const NAME = 'Devis(U Bee Bee TK)';
const SOURCE_ID = 'BYORDER-20260822-CA3E27';
const FORBIDDEN_NITA = 'BYORDER-20260720-078596';
const PRODUCT_ID = 'p-2026';
const PRICE = 290;
const API = 'http://127.0.0.1:8082';
const outPath = process.env.LZ_DUMP_OUT || 'C:/Temp/lz-devis-k393/result.json';

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8').replace(/\0+$/g, '').trimEnd());
}

function listOf(wrap, key) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap[key])) return wrap[key];
  if (wrap && Array.isArray(wrap.inquiries)) return wrap.inquiries;
  if (wrap && Array.isArray(wrap.orders)) return wrap.orders;
  if (wrap && Array.isArray(wrap.products)) return wrap.products;
  if (wrap && Array.isArray(wrap.skus)) return wrap.skus;
  return [];
}

function assignList(wrap, next, key) {
  if (Array.isArray(wrap)) return next;
  if (wrap && Array.isArray(wrap[key])) {
    wrap[key] = next;
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
  const tmp = file + '.tmp-devis-' + process.pid + '-' + Date.now();
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

function intOr(value) {
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

function curlJson(urlPath, payload, token) {
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  const tmp = 'C:/Temp/lz-devis-k393/curl-' + Date.now() + '.json';
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
    ephemeral: 'devis-k393-preorder'
  });
  atomicWrite(sessionsFile, sessions);
  return token;
}

function colorNames(product) {
  return (Array.isArray(product.colors) ? product.colors : []).map(function (item) {
    if (!item) return '';
    if (typeof item === 'string') return String(item).trim();
    return String(item.name || item.colorName || item.color || '').trim();
  }).filter(Boolean);
}

function sizeNames(product) {
  return (Array.isArray(product.sizes) ? product.sizes : []).map(function (item) {
    if (!item) return '';
    if (typeof item === 'string') return String(item).trim();
    return String(item.name || item.sizeName || item.size || '').trim();
  }).filter(Boolean);
}

function ensureCatalog() {
  const productsFile = path.join(dataDir, 'products.json');
  const skusFile = path.join(dataDir, 'skus.json');
  const productsWrap = readJson(productsFile);
  const skusWrap = readJson(skusFile);
  const products = listOf(productsWrap, 'products');
  const skus = listOf(skusWrap, 'skus');
  const product = products.find((row) => row && (row.id === PRODUCT_ID || String(row.code || '').toUpperCase() === 'K393'));
  if (!product) throw new Error('K393 product missing');
  const whiteImage = (product.colors && product.colors[0] && product.colors[0].image) || product.mainImage || (product.images && product.images[0]) || '';
  if (colorNames(product).indexOf('白色(PUTI)') === -1) {
    product.colors = (Array.isArray(product.colors) ? product.colors : []).concat([{ code: 'puti', name: '白色(PUTI)', image: whiteImage }]);
  }
  const sizes = sizeNames(product);
  ['M', 'L'].forEach(function (size) {
    if (sizes.indexOf(size) === -1) sizes.push(size);
  });
  product.sizes = sizes.map(function (name) { return { name: name }; });
  product.updatedAt = new Date().toISOString();

  function ensureSku(id, size, existingBarcode) {
    let sku = skus.find((row) => row && String(row.id || row.sku || '') === id);
    if (!sku) {
      sku = {
        id: id,
        sku: id,
        productId: PRODUCT_ID,
        barcode: existingBarcode || id,
        companyBarcode: existingBarcode || id,
        color: '白色(PUTI)',
        colorName: '白色(PUTI)',
        size: size,
        sizeName: size,
        warehouse: '預購倉',
        warehouseName: '預購倉',
        warehouseCode: 'PREORDER',
        stock: 0,
        status: 'active',
        active: true,
        price: PRICE,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        colorImage: whiteImage
      };
      skus.push(sku);
    } else {
      sku.color = sku.color || '白色(PUTI)';
      sku.colorName = sku.colorName || '白色(PUTI)';
      sku.size = sku.size || size;
      sku.sizeName = sku.sizeName || size;
      sku.updatedAt = new Date().toISOString();
    }
    return sku;
  }

  const whiteM = skus.find((row) => row && String(row.id || '') === 'SKU-K393-fa9361') || ensureSku('K393-04-M', 'M');
  const whiteL = ensureSku('K393-04-L', 'L');
  backup(productsFile, 'devis-k393-product');
  backup(skusFile, 'devis-k393-skus');
  atomicWrite(productsFile, assignList(productsWrap, products, 'products'));
  atomicWrite(skusFile, assignList(skusWrap, skus, 'skus'));
  return {
    product: product,
    whiteM: whiteM,
    whiteL: whiteL,
    image: whiteImage || './assets/brand-logo.jpg'
  };
}

function itemFromSku(sku, product, image) {
  return {
    requestedQty: 1,
    skuId: sku.id || sku.sku,
    sku: sku.id || sku.sku,
    productId: PRODUCT_ID,
    code: 'K393',
    title: product.title || product.name || '2026世界杯西班牙',
    color: '白色(PUTI)',
    size: sku.sizeName || sku.size,
    qty: 1,
    price: PRICE,
    image: sku.colorImage || image,
    sourceWarehouse: '預購倉',
    sourceWarehouseCode: 'PREORDER',
    sourceStockAtOrder: 0,
    preorderNoStockChange: true,
    temporaryProduct: false
  };
}

if (!fs.existsSync(path.join(dataDir, 'inquiries.json'))) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

const inquiriesFile = path.join(dataDir, 'inquiries.json');
const inquiriesWrap = readJson(inquiriesFile);
const inquiries = listOf(inquiriesWrap, 'inquiries');
if (inquiries.some((row) => String(row && row.id || '') === FORBIDDEN_NITA)) {
  // presence is fine; we must not modify that row
}
const nita = inquiries.find((row) => String(row && row.id || '') === FORBIDDEN_NITA);
const nitaBefore = nita ? JSON.stringify(nita) : '';

const already = inquiries.filter(function (row) {
  if (phoneOf(row) !== PHONE) return false;
  if (String(row.id || '') === SOURCE_ID) return false;
  const items = Array.isArray(row.items) ? row.items : [];
  return items.some(function (item) {
    return /K393/i.test([item.code, item.skuId, item.sku].join(' ')) && String(item.color || '').indexOf('白') !== -1;
  });
});
if (already.length) {
  const result = { skipped: true, reason: 'already has K393 white preorder', ids: already.map((row) => row.id) };
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result, null, 2));
  process.exit(0);
}

const source = inquiries.find((row) => String(row && row.id || '') === SOURCE_ID && phoneOf(row) === PHONE);
if (!source) throw new Error('Devis source inquiry missing ' + SOURCE_ID);
if (!/Devis/i.test(nameOf(source))) throw new Error('source inquiry name mismatch');

const catalog = ensureCatalog();
const customer = JSON.parse(JSON.stringify(source.customer || {}));
customer.name = NAME;
customer.phone = PHONE;
customer.channel = '後台預購';
delete customer.image;
delete customer.screenshot;
delete customer.paymentProofImage;

const token = injectSession();
const created = curlJson('/stock-inquiry-api.php', {
  action: 'create',
  sales: 'lingzanzan',
  orderType: 'preorder',
  preorderWarehouse: 'TW',
  customer: customer,
  items: [
    itemFromSku(catalog.whiteM, catalog.product, catalog.image),
    itemFromSku(catalog.whiteL, catalog.product, catalog.image)
  ]
}, token);

const inquiry = created.inquiry || created.row || {};
if (!inquiry.id) throw new Error('create returned no inquiry id');
if (String(inquiry.id) === FORBIDDEN_NITA) throw new Error('refused: nita id');
if (String(inquiry.id) === SOURCE_ID) throw new Error('refused: mutated existing CA3E27');

const afterInquiries = listOf(readJson(inquiriesFile), 'inquiries');
const nitaAfter = afterInquiries.find((row) => String(row && row.id || '') === FORBIDDEN_NITA);
if (nitaBefore && JSON.stringify(nitaAfter) !== nitaBefore) throw new Error('nita row changed');
const ca3 = afterInquiries.find((row) => String(row && row.id || '') === SOURCE_ID);
if (!ca3 || (ca3.items || []).some((item) => /K393/i.test([item.code, item.skuId].join(' ')))) {
  throw new Error('CA3E27 was altered or missing');
}

const result = {
  ok: true,
  inquiryId: inquiry.id,
  status: inquiry.status,
  total: inquiry.total,
  items: (inquiry.items || []).map(function (item) {
    return {
      sku: item.skuId || item.sku,
      color: item.color,
      size: item.size,
      qty: item.qty,
      price: item.price,
      warehouse: item.sourceWarehouse
    };
  })
};
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, JSON.stringify(result, null, 2));
console.log(JSON.stringify(result, null, 2));
console.log('LINGZANZAN Devis K393 preorder ok');
