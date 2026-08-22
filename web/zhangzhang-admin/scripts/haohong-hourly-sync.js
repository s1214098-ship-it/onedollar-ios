'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');
const crypto = require('crypto');
const { URLSearchParams } = require('url');

const ROOT = path.resolve(__dirname, '..');
const DATA = path.join(ROOT, 'data');
const FREIGHT = path.join(DATA, 'freight-forwarding-tracking.json');
const CREDS = path.join(DATA, 'haohong-credentials.json');
const LOG = path.join(DATA, 'haohong-hourly-sync-log.json');
const API_HOST = 'shengdaapi.51wkd.com';
const API_PATH = '/Website/';

function readJson(file, fallback) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); }
  catch (e) { return fallback; }
}

function writeJson(file, value) {
  const tmp = file + '.tmp-' + Date.now();
  fs.writeFileSync(tmp, JSON.stringify(value, null, 2) + '\n');
  fs.renameSync(tmp, file);
}

function md5(value) {
  return crypto.createHash('md5').update(String(value), 'utf8').digest('hex');
}

function text(value, limit) {
  return String(value == null ? '' : value).trim().slice(0, limit || 500);
}

function num(value) {
  const n = Number(value);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function declaredGoodsValue() {
  // Dummy declared value only. Haohong blocks 集運 if GoodsValue is empty.
  // Never send real product cost or sale price. 10 / 15 / 20 are all allowed.
  return 15;
}

function trackingKey(value) {
  return String(value || '').replace(/[\s\u200b-\u200d\ufeff]+/g, '').toUpperCase();
}

function itemTrackingNumbers(item) {
  return [item && item.trackingNo, item && item.haohongTrackingNo]
    .concat((item && item.splitPackages || []).map((parcel) => parcel && parcel.trackingNo))
    .map((value) => text(value, 200))
    .filter((value) => value && !/^(待補|未填|無|N\/A|-)$/i.test(value));
}

function itemTrackingKeys(item) {
  return itemTrackingNumbers(item).map(trackingKey).filter(Boolean);
}

function trackingsMissingHaohongWeight(items, packageKeys) {
  const seen = new Set();
  const missing = [];
  const keys = packageKeys instanceof Set ? packageKeys : new Set(packageKeys || []);
  (items || []).forEach((item) => {
    if (isSelfPickup(item)) return;
    itemTrackingNumbers(item).forEach((trackingNo) => {
      const key = trackingKey(trackingNo);
      if (!key || keys.has(key) || seen.has(key)) return;
      const hasWeight = num(item.billedWeightKg) || num(item.actualWeightKg) || num(item.weightKg);
      if (hasWeight && text(item.haohongWeightUpdatedAt || item.weightSyncedAt, 40)) return;
      seen.add(key);
      const pending = /到貨待分類|待分類/.test(String(item.progress || '') + String(item.issueType || ''));
      missing.push({ trackingNo: trackingNo, rank: pending ? 0 : (item.batchId ? 2 : 1) });
    });
  });
  return missing.sort((a, b) => a.rank - b.rank).map((row) => row.trackingNo);
}

async function lookupMissingPackages(site, login, memberId, packagesByTracking, freight) {
  const missing = trackingsMissingHaohongWeight(freight && freight.items, new Set(packagesByTracking.keys()));
  let lookedUp = 0;
  let found = 0;
  for (const trackingNo of missing.slice(0, 80)) {
    const key = trackingKey(trackingNo);
    if (!key || packagesByTracking.has(key)) continue;
    lookedUp += 1;
    for (const packageType of [2, 3, 1]) {
      try {
        const rows = asRows(await hhPost('QueryBillCode', {
          Client: memberId,
          OrdeTeyp: packageType,
          HouseId: 0,
          BillCode: trackingNo,
        }, site, login));
        rows.forEach((row) => {
          const pack = normalizePackage(row, packageType);
          const packKey = trackingKey(pack.trackingNo);
          if (!packKey) return;
          packagesByTracking.set(packKey, mergePackage(packagesByTracking.get(packKey), pack));
        });
        if (packagesByTracking.has(key)) {
          found += 1;
          break;
        }
      } catch (error) {
        // Keep scanning the other HaoHong package lists for this tracking number.
      }
    }
  }
  return { missingCount: missing.length, lookedUp, found };
}

function isSelfPickup(item) {
  const source = String(item && (item.logisticsSource || item.provider) || '').toLowerCase();
  if (source === 'self_pickup') return true;
  return /東莞自取|自取／東莞|自提取回/.test(String(item && item.forwarder || ''));
}

function isFormalNonHaohongBatch(freight, batchId) {
  const id = String(batchId || '');
  if (!id || /^SHEET-/i.test(id) || /^HAOHONG-/i.test(id)) return false;
  const batch = (freight.batches || []).find((row) => String(row.id || '') === id);
  if (!batch) return false;
  const provider = String(batch.provider || '').toLowerCase();
  return provider === 'pinduoduo' || provider === 'self_pickup';
}

function purchaseIdentity(textValue, fallback) {
  const value = String(textValue || '').replace(/拚/g, '拼');
  const known = ['拼張', '拼A', '拼直', '拼郭', '拼電'];
  const account = known.find((name) => value.toUpperCase().indexOf(name.toUpperCase()) >= 0)
    || String(fallback || '').replace(/拚/g, '拼').trim();
  const customerPriority = /拼張|張張|\(張張\)|（張張）/i.test(value);
  return {
    purchasePlatform: '拼多多',
    purchaseAccount: account,
    platform: account || '豪鴻未分類',
    customerPriority,
  };
}

function quantityFromContent(textValue) {
  const matches = String(textValue || '').match(/[＊*xX×]\s*(\d+)/g) || [];
  const total = matches.reduce((sum, token) => {
    const qty = Number(String(token).replace(/[^0-9]/g, ''));
    return sum + (qty > 0 ? qty : 0);
  }, 0);
  return total > 0 ? total : 1;
}

function keepPurchaseIdentity(item, identity) {
  const account = text(identity.purchaseAccount || item.purchaseAccount || item.platform, 100);
  if (!account || account === '豪鴻未分類') {
    return Object.assign({}, identity, {
      purchaseAccount: text(item.purchaseAccount, 100),
      platform: text(item.platform || item.purchaseAccount, 100) || identity.platform,
    });
  }
  return Object.assign({}, identity, { purchaseAccount: account, platform: account });
}

function taipeiNow() {
  return new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Taipei' });
}

function postForm(apiName, form) {
  const body = new URLSearchParams(form).toString();
  return new Promise((resolve, reject) => {
    const req = https.request({
      hostname: API_HOST,
      path: API_PATH + apiName,
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Content-Length': Buffer.byteLength(body),
        'User-Agent': 'LINGZANZAN-HaoHong-Hourly/1.0',
      },
    }, (res) => {
      let data = '';
      res.on('data', (chunk) => { data += chunk; });
      res.on('end', () => {
        try {
          const outer = JSON.parse(data);
          if (!outer || !outer.State) {
            reject(new Error(text(outer && outer.MsgText, 180) || (apiName + ' 失敗')));
            return;
          }
          resolve(parseReturnJson(outer.ReturnJson));
        } catch (error) {
          reject(error);
        }
      });
    });
    req.setTimeout(35000, () => req.destroy(new Error(apiName + ' 逾時')));
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

function parseReturnJson(value) {
  if (value == null || value === '') return [];
  if (typeof value !== 'string') return value;
  try {
    return JSON.parse(value);
  } catch (error) {
    return value;
  }
}

function asRows(value) {
  if (Array.isArray(value)) return value.filter((row) => row && typeof row === 'object');
  if (value && typeof value === 'object') return Object.values(value).filter((row) => row && typeof row === 'object');
  return [];
}

function postFormRaw(apiName, form) {
  const body = new URLSearchParams(form).toString();
  return new Promise((resolve, reject) => {
    const req = https.request({
      hostname: API_HOST,
      path: API_PATH + apiName,
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Content-Length': Buffer.byteLength(body),
        'User-Agent': 'LINGZANZAN-HaoHong-Hourly/1.0',
      },
    }, (res) => {
      let data = '';
      res.on('data', (chunk) => { data += chunk; });
      res.on('end', () => {
        try { resolve(JSON.parse(data) || {}); }
        catch (error) { reject(error); }
      });
    });
    req.setTimeout(35000, () => req.destroy(new Error(apiName + ' 逾時')));
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

async function hhTry(apiName, payload, site, login) {
  const json = JSON.stringify(payload);
  const form = {
    JsonData: json,
    CusID: String(site.CusID || 25),
    KeyMd5: md5(json + text(site.KeyText, 200)),
  };
  if (login && login.Token) form.Token = String(login.Token);
  return postFormRaw(apiName, form);
}

async function registerMissingPackages(site, login, memberId, packagesByTracking, freight) {
  const seen = new Set();
  const missing = [];
  (freight.items || []).forEach((item) => {
    if (isSelfPickup(item)) return;
    if (isFormalNonHaohongBatch(freight, item.batchId)) return;
    const numbers = itemTrackingNumbers(item);
    numbers.forEach((value) => {
      const trackingNo = text(value, 200);
      const key = trackingKey(trackingNo);
      if (!key || packagesByTracking.has(key) || seen.has(key)) return;
      seen.add(key);
      missing.push({
        trackingNo,
        productName: companyName(item) || '後台預報包裹',
        quantity: Math.max(1, Number(item.quantity || 1)),
        note: text(item.note || item.purchaseContentRaw, 180),
      });
    });
  });
  const created = [];
  const skipped = [];
  for (const pack of missing.slice(0, 80)) {
    const payloads = [
      ['AddBillCode', {
        Client: memberId, MemberId: memberId, kdBillCode: pack.trackingNo, BillCode: pack.trackingNo,
        HouseId: 0, Goods: pack.productName, goods: pack.productName, GoodsName: pack.productName,
        Number: pack.quantity, GoodsNums: pack.quantity, goods_id: 22, GoodsTypeId: 22,
        goodstyep: '普货', GoodsTypeName: '普货', goodsName: '普货', Rem: pack.note, GoodsValue: declaredGoodsValue(),
      }],
      ['InsertBillCode', {
        Client: memberId, MemberId: memberId, kdBillCode: pack.trackingNo, BillCode: pack.trackingNo,
        HouseId: 0, Goods: pack.productName, Number: pack.quantity, goods_id: 22, goodstyep: '普货', Rem: pack.note,
        GoodsValue: declaredGoodsValue(),
      }],
      ['AddkdBillCode', {
        Client: memberId, kdBillCode: pack.trackingNo, HouseId: 0, Goods: pack.productName,
        Number: pack.quantity, goods_id: 22, goodstyep: '普货', Rem: pack.note,
        GoodsValue: declaredGoodsValue(),
      }],
    ];
    let ok = false;
    let lastError = '豪鴻沒有接受這個預報接口';
    for (const [path, body] of payloads) {
      try {
        const response = await hhTry(path, body, site, login);
        if (response && response.State) {
          ok = true;
          break;
        }
        lastError = text(response && response.MsgText, 180) || lastError;
        if (lastError && !/不存在|找不到|无效的方法|無效的方法|not found|unknown/i.test(lastError)) break;
      } catch (error) {
        lastError = String(error && error.message || error).slice(0, 180);
      }
    }
    if (ok) created.push(pack.trackingNo);
    else skipped.push({ trackingNo: pack.trackingNo, reason: lastError });
  }
  const now = new Date().toISOString();
  const createdKeys = new Set(created.map(trackingKey));
  const skippedByKey = new Map(skipped.map((row) => [trackingKey(row.trackingNo), row.reason]));
  if (created.length || skipped.length) {
    freight.items = (freight.items || []).map((item) => {
      const itemKeys = [item.trackingNo, item.haohongTrackingNo].concat((item.splitPackages || []).map((parcel) => parcel && parcel.trackingNo)).map(trackingKey);
      if (itemKeys.some((key) => createdKeys.has(key))) {
        return Object.assign({}, item, { haohongForecastStatus: 'registered', haohongForecastReason: '', haohongForecastAt: now, updatedAt: now });
      }
      const failReason = itemKeys.map((key) => skippedByKey.get(key)).find(Boolean);
      if (!failReason) return item;
      return Object.assign({}, item, { haohongForecastStatus: 'failed', haohongForecastReason: failReason, haohongForecastAt: now, updatedAt: now });
    });
  }
  return { createdCount: created.length, skippedCount: skipped.length, created, skipped };
}

async function hhWrap(apiName, form) {
  return postForm(apiName, form);
}

async function hhPost(apiName, payload, site, login) {
  const json = JSON.stringify(payload);
  const form = {
    JsonData: json,
    CusID: String(site.CusID || 25),
    KeyMd5: md5(json + text(site.KeyText, 200)),
  };
  if (login && login.Token) form.Token = String(login.Token);
  return hhWrap(apiName, form);
}

function packageQuality(row) {
  const weightScore = num(row.billedWeightKg) * 1000000;
  const receivedScore = text(row.receivedAt, 40) ? 10000 : 0;
  const statusScore = row.packageType === 2 ? 1000 : (row.packageType === 1 ? 100 : 0);
  return weightScore + receivedScore + statusScore;
}

function normalizePackage(row, packageType) {
  const actual = num(row.Weight);
  const volume = num(row.Weight2);
  const fare = num(row.sFareWeight || row.MaxWeight);
  const statusLabels = { 1: '豪鴻待集運', 2: '豪鴻倉已收到', 3: '豪鴻倉未收到' };
  return {
    packageId: text(row.id || row.ID, 100),
    trackingNo: text(row.kdBillCode || row.kdBillcode, 200),
    warehouse: text(row.HouseName || row.WaveHouse, 100),
    receivedAt: text(row.dd_time || row.ddTime, 100),
    productName: text(row.goods || row.Goods, 500),
    goodsType: text(row.goodstyep || row.goodsName, 200),
    note: text(row.goods_memo || row.Goods_meno, 1000),
    quantity: Math.max(1, Math.round(num(row.Number) || 1)),
    actualWeightKg: Math.round(actual * 1000) / 1000,
    volumeWeightKg: Math.round(volume * 1000) / 1000,
    billedWeightKg: Math.round(Math.max(actual, volume, fare) * 1000) / 1000,
    packageStatus: statusLabels[packageType] || '我的包裹',
    packageType,
    isAwaitingConsolidation: packageType === 1,
    isWarehouseReceived: packageType === 2,
    isNotReceived: packageType === 3,
    statusSources: [packageType],
    GoodsValue: num(row.GoodsValue || row.goods_value),
    GoodsTypeId: text(row.GoodsTypeId || row.goods_id, 100),
    GoodsTypeName: text(row.GoodsTypeName || row.goodstyep || row.goodsName, 200),
    Number: Math.max(1, Math.round(num(row.Number || row.GoodsNum) || 1)),
    syncedAt: new Date().toISOString(),
    weightSource: 'haohong_my_package',
  };
}

function mergePackage(existing, incoming) {
  if (!existing || packageQuality(incoming) > packageQuality(existing)) {
    const merged = Object.assign({}, incoming);
    merged.isAwaitingConsolidation = !!(incoming.isAwaitingConsolidation || (existing && existing.isAwaitingConsolidation));
    merged.isWarehouseReceived = !!(incoming.isWarehouseReceived || (existing && existing.isWarehouseReceived));
    merged.isNotReceived = !!(incoming.isNotReceived || (existing && existing.isNotReceived));
    merged.statusSources = Array.from(new Set([].concat(existing && existing.statusSources || [], incoming.statusSources || []))).sort();
    if (merged.isAwaitingConsolidation) merged.packageStatus = '豪鴻待集運';
    else if (merged.isWarehouseReceived) merged.packageStatus = '豪鴻倉已收到';
    else if (merged.isNotReceived) merged.packageStatus = '豪鴻倉未收到';
    return merged;
  }
  const merged = Object.assign({}, existing);
  merged.isAwaitingConsolidation = !!(existing.isAwaitingConsolidation || incoming.isAwaitingConsolidation);
  merged.isWarehouseReceived = !!(existing.isWarehouseReceived || incoming.isWarehouseReceived);
  merged.isNotReceived = !!(existing.isNotReceived || incoming.isNotReceived);
  merged.statusSources = Array.from(new Set([].concat(existing.statusSources || [], incoming.statusSources || []))).sort();
  if (merged.isAwaitingConsolidation) merged.packageStatus = '豪鴻待集運';
  else if (merged.isWarehouseReceived) merged.packageStatus = '豪鴻倉已收到';
  else if (merged.isNotReceived) merged.packageStatus = '豪鴻倉未收到';
  return merged;
}

function placeholderName(name) {
  return !name || /^(?:豪鴻)?待補|未命名|待確認|未分類/.test(name);
}

function companyName(item) {
  const name = text(item.productName || item.productFiledProductTitle || item.productFiledProductCode || item.productCode, 180);
  if (placeholderName(name)) return '';
  const variants = Array.isArray(item.variants) ? item.variants : [];
  if (variants.length) {
    const bits = variants.map((line) => {
      const color = text(line.color || line.colorName, 40);
      const qty = Math.max(1, Number(line.quantity || 1));
      return color ? color + '*' + qty : '';
    }).filter(Boolean);
    if (bits.length) return (name + ' ' + bits.join(' ')).trim().slice(0, 180);
  }
  return name;
}

function progressOf(weight) {
  if (weight.isAwaitingConsolidation) return '豪鴻待集運';
  if (weight.isWarehouseReceived) return '豪鴻倉已收到';
  if (weight.isNotReceived) return '豪鴻倉未收到';
  return weight.packageStatus || '豪鴻包裹待確認';
}

function applyPackages(freight, packages) {
  const now = new Date().toISOString();
  const today = taipeiNow().slice(0, 10);
  const cache = new Map();
  (freight.haohongPackageWeights || []).forEach((row) => {
    const key = trackingKey(row && row.trackingNo);
    if (key) cache.set(key, row);
  });
  packages.forEach((row) => {
    const key = trackingKey(row.trackingNo);
    if (!key) return;
    cache.set(key, Object.assign({}, row, { syncedAt: now }));
  });
  const matchedTracking = new Set();
  let matchedItemCount = 0;
  freight.items = (freight.items || []).map((item) => {
    if (isSelfPickup(item)) return item;
    const keys = itemTrackingKeys(item);
    let weight = null;
    keys.forEach((key) => {
      if (!cache.has(key)) return;
      if (!weight) weight = cache.get(key);
      matchedTracking.add(key);
    });
    if (!weight) return item;
    matchedItemCount += 1;
    const billed = num(weight.billedWeightKg) || num(item.billedWeightKg) || num(item.weightKg);
    const needsClassification = !text(item.productId || item.productFiledProductId, 80)
      || !text(item.sampleBarcode || item.taiwanBarcode || item.productFiledBarcode, 80);
    const falseSheet = weight.isAwaitingConsolidation && /^SHEET-.*-HH$/i.test(String(item.batchId || ''));
    const keepBatchFormula = !falseSheet && isFormalNonHaohongBatch(freight, item.batchId);
    const next = Object.assign({}, item, {
      haohongTrackingNo: weight.trackingNo || item.haohongTrackingNo || item.trackingNo || '',
      actualWeightKg: num(weight.actualWeightKg) || num(item.actualWeightKg),
      volumeWeightKg: num(weight.volumeWeightKg) || num(item.volumeWeightKg),
      billedWeightKg: billed,
      shippingWeightKg: billed,
      weightKg: billed,
      weightSource: 'haohong_my_package',
      haohongPackageStatus: weight.packageStatus || '',
      haohongWarehouse: weight.warehouse || '',
      haohongReceivedAt: weight.receivedAt || '',
      haohongWeightUpdatedAt: weight.syncedAt || now,
      haohongAwaitingConsolidation: !!weight.isAwaitingConsolidation,
      haohongWarehouseReceived: !!weight.isWarehouseReceived,
      haohongNotReceived: !!weight.isNotReceived,
      batchId: falseSheet ? '' : item.batchId,
      progress: needsClassification && weight.isWarehouseReceived
        ? '到貨待分類'
        : (falseSheet || !item.batchId ? progressOf(weight) : item.progress),
      updatedAt: now,
    });
    if (keepBatchFormula) return next;
    return Object.assign(next, {
      provider: 'haohong',
      logisticsSource: 'haohong',
      forwarder: '豪鴻集運倉',
    });
  });
  let imported = 0;
  packages.forEach((weight) => {
    const key = trackingKey(weight.trackingNo);
    if (!key || matchedTracking.has(key)) return;
    freight.items.push({
      id: 'HAOHONG-PACKAGE-' + key.replace(/[^A-Z0-9_-]/g, ''),
      date: String(weight.receivedAt || today).slice(0, 10),
      provider: 'haohong',
      logisticsSource: 'haohong',
      forwarder: '豪鴻集運倉',
      destinationWarehouse: 'TW_BAOHUI',
      destinationSite: '寶輝據點',
      handlingPerItemTwd: 20,
      trackingNo: weight.trackingNo,
      haohongTrackingNo: weight.trackingNo,
      batchId: '',
      productName: weight.productName || '豪鴻待補產品名稱',
      purchaseContentRaw: weight.note || '',
      note: weight.note || '',
      quantity: Math.max(1, Number(weight.quantity || 1)),
      actualWeightKg: num(weight.actualWeightKg),
      volumeWeightKg: num(weight.volumeWeightKg),
      billedWeightKg: num(weight.billedWeightKg),
      shippingWeightKg: num(weight.billedWeightKg),
      weightKg: num(weight.billedWeightKg),
      weightSource: 'haohong_my_package',
      haohongPackageStatus: weight.packageStatus || '',
      haohongWarehouse: weight.warehouse || '',
      haohongReceivedAt: weight.receivedAt || '',
      haohongWeightUpdatedAt: now,
      progress: weight.isWarehouseReceived ? '到貨待分類' : progressOf(weight),
      classificationStatus: 'pending',
      createdAt: now,
      updatedAt: now,
    });
    imported += 1;
    matchedTracking.add(key);
  });
  freight.haohongPackageWeights = Array.from(cache.values()).sort((a, b) =>
    String(b.receivedAt || b.syncedAt || '').localeCompare(String(a.receivedAt || a.syncedAt || ''))
  );
  freight.importMeta = Object.assign({}, freight.importMeta || {}, {
    haohongPackageLastSyncedAt: now,
    haohongPackageCount: packages.length,
    haohongPackageMatchedItemCount: matchedItemCount,
    haohongPackageImportedPlaceholderCount: imported,
    haohongHourlyAutoSync: true,
  });
  freight.revision = Number(freight.revision || 0) + 1;
  freight.updatedAt = now;
  return { matchedItemCount, imported, packageCount: packages.length };
}

function orderIdOf(order) {
  return text(order && (order.id || order.ID || order.OrderID || order.order_id), 100);
}

function normalizeOrder(detail, summary) {
  const headers = Array.isArray(detail && detail.QueryOrderDetail) ? detail.QueryOrderDetail : [];
  const header = (headers[0] && typeof headers[0] === 'object') ? headers[0] : (summary || {});
  const sourceRows = Array.isArray(detail && detail.QueryOrderToList) ? detail.QueryOrderToList : [];
  const rows = [];
  sourceRows.forEach((row) => {
    if (!row || typeof row !== 'object') return;
    const actual = num(row.Weight);
    const volume = num(row.Weight2);
    const fareWeight = num(row.sFareWeight);
    rows.push({
      trackingNo: text(row.kdBillcode || row.kdBillCode, 200),
      transferOrderNo: text(row.OrderCode, 200),
      warehouse: text(row.WaveHouse, 100),
      receivedAt: text(row.ddTime, 100),
      productName: text(row.Goods, 500),
      note: text(row.Rem, 1000),
      actualWeightKg: Math.round(actual * 1000) / 1000,
      volumeWeightKg: Math.round(volume * 1000) / 1000,
      billedWeightKg: Math.round(Math.max(actual, fareWeight, volume) * 1000) / 1000,
    });
  });
  let actualTotal = num(header.Weight);
  if (actualTotal <= 0) actualTotal = rows.reduce((sum, row) => sum + num(row.actualWeightKg), 0);
  const billedTotal = rows.reduce((sum, row) => sum + num(row.billedWeightKg), 0);
  const transferNo = rows.length ? text(rows[0].transferOrderNo, 200) : '';
  const orderId = orderIdOf(summary) || orderIdOf(header);
  let orderCode = text(header.order_code || header.OrderCode || (summary && (summary.order_code || summary.OrderCode)), 200);
  if (!orderCode || /^@\{/.test(orderCode)) orderCode = orderId;
  return {
    haohongOrderId: orderId,
    haohongOrderCode: orderCode,
    transferOrderNo: transferNo,
    orderDate: text(header.AddTime || header.ddTime || (summary && (summary.add_time || summary.CreateTime)), 100),
    status: text((summary && (summary.senttohk_state || summary.StateName)) || '', 100),
    shippingFeeTwd: Math.round(num(header.Fare || (summary && (summary.Free || summary.Fare))) * 100) / 100,
    totalActualWeightKg: Math.round(actualTotal * 1000) / 1000,
    totalVolumeWeightKg: Math.round(rows.reduce((sum, row) => sum + num(row.volumeWeightKg), 0) * 1000) / 1000,
    totalBilledWeightKg: Math.round((billedTotal > 0 ? billedTotal : actualTotal) * 1000) / 1000,
    packageCount: rows.length,
    rows,
  };
}

async function fetchHaohongOrders(site, login, memberId) {
  const summaries = new Map();
  for (const isConfirm of [0, 1]) {
    for (const isPayed of [0, 1]) {
      for (const isQs of [0, 1]) {
        for (const isComment of [0, 1]) {
          try {
            for (let offset = 1; offset <= 5; offset += 1) {
              const rows = asRows(await hhPost('OrderRecordPage', {
                MemberID: memberId,
                limit: 80,
                offset: offset,
                IsConfirm: isConfirm,
                IsPayed: isPayed,
                IsQs: isQs,
                IsComment: isComment,
              }, site, login));
              if (!rows.length) break;
              rows.forEach((order) => {
                const id = orderIdOf(order);
                if (id) summaries.set(id, order);
              });
              if (rows.length < 80) break;
            }
          } catch (error) {
            // Some HaoHong flag combinations return empty or reject; keep scanning the rest.
          }
        }
      }
    }
  }
  const orders = [];
  for (const [id, summary] of summaries) {
    try {
      const detail = await hhPost('QueryOrderDetail', { MemberID: memberId, ID: id }, site, login);
      const normalized = normalizeOrder(detail && typeof detail === 'object' ? detail : {}, summary);
      if (normalized.rows.length) orders.push(normalized);
    } catch (error) {
      // Skip a single order that HaoHong will not expand.
    }
  }
  orders.sort((left, right) => String(left.orderDate || '').localeCompare(String(right.orderDate || '')));
  return orders;
}

function haohongFormalBatchId(order) {
  const token = String(order.haohongOrderId || order.haohongOrderCode || order.transferOrderNo || Date.now()).replace(/[^A-Za-z0-9_-]/g, '');
  return 'HAOHONG-' + token;
}

function findHaohongLinkedBatch(freight, order) {
  const expectedId = haohongFormalBatchId(order);
  const orderId = String(order.haohongOrderId || '');
  const orderCode = String(order.haohongOrderCode || '');
  const batches = freight.batches || [];
  const exact = batches.find((batch) => String(batch.id || '') === expectedId);
  if (exact) return exact;
  return batches.find((batch) => {
    const isHaohong = String(batch.provider || '').toLowerCase() === 'haohong'
      || /豪鴻/.test(String(batch.forwarder || ''))
      || /^HAOHONG-/i.test(String(batch.id || ''));
    if (!isHaohong) return false;
    return (orderId && String(batch.haohongOrderId || '') === orderId)
      || (orderCode && (String(batch.haohongOrderCode || '') === orderCode || String(batch.batchNo || '') === orderCode))
      || (orderId && String(batch.batchNo || '') === orderId);
  }) || null;
}

function applyHaohongOrder(freight, order) {
  const now = new Date().toISOString();
  const today = taipeiNow().slice(0, 10);
  const existingBatch = findHaohongLinkedBatch(freight, order);
  const batchId = String(existingBatch && existingBatch.id || haohongFormalBatchId(order));
  const batchNo = text(order.haohongOrderId || order.haohongOrderCode || batchId, 150);
  const packageCount = Math.max(0, Number(order.packageCount || (order.rows || []).length || 0));
  const rowByTracking = new Map();
  (order.rows || []).forEach((row) => {
    const key = trackingKey(row.trackingNo);
    if (key) rowByTracking.set(key, row);
  });
  const matchedIds = new Set();
  let matchedItemCount = 0;
  freight.items = (freight.items || []).map((item) => {
    if (isSelfPickup(item)) return item;
    const keys = itemTrackingKeys(item);
    let row = null;
    keys.forEach((key) => {
      if (!row && rowByTracking.has(key)) row = rowByTracking.get(key);
    });
    if (!row) return item;
    matchedItemCount += 1;
    matchedIds.add(String(item.id || ''));
    const identity = keepPurchaseIdentity(item, purchaseIdentity(
      [row.note, row.productName, item.purchaseContentRaw, item.note].filter(Boolean).join(' '),
      item.purchaseAccount || item.platform || ''
    ));
    return Object.assign({}, item, identity, {
      batchId,
      provider: 'haohong',
      logisticsSource: 'haohong',
      forwarder: '豪鴻集運倉',
      destinationWarehouse: 'TW_BAOHUI',
      destinationSite: '寶輝據點',
      handlingPerItemTwd: 20,
      costMode: 'haohong_weight_batch',
      haohongOrderId: order.haohongOrderId || '',
      haohongOrderCode: order.haohongOrderCode || '',
      haohongTrackingNo: row.trackingNo || item.haohongTrackingNo || item.trackingNo || '',
      actualWeightKg: num(row.actualWeightKg) || num(item.actualWeightKg),
      volumeWeightKg: num(row.volumeWeightKg) || num(item.volumeWeightKg),
      billedWeightKg: num(row.billedWeightKg) || num(item.billedWeightKg),
      shippingWeightKg: num(row.billedWeightKg) || num(item.shippingWeightKg) || num(item.billedWeightKg),
      weightKg: num(row.billedWeightKg) || num(item.weightKg),
      weightSource: 'haohong_order_detail',
      haohongPackageStatus: '豪鴻已形成正式批次',
      haohongAwaitingConsolidation: false,
      haohongWeightUpdatedAt: now,
      productName: (function () {
        var nowName = String(item.productName || '').trim();
        var next = String(row.productName || '').trim();
        if (next && (!nowName || nowName === '豪鴻待補產品名稱' || nowName === '後台尚未建檔')) return next;
        return nowName || next || item.productName;
      })(),
      purchaseContentRaw: item.purchaseContentRaw || row.note || '',
      customerPriority: !!(item.customerPriority || identity.customerPriority),
      progress: (function (status) { const text = String(status || ''); if (/待[簽签]收|未[簽签]收/.test(text)) return false; return /已[簽签]收/.test(text); })(order.status) ? '已簽收完成' : (item.progress || '已集運'),
      updatedAt: now,
    });
  });
  const signed = (function (status) {
    const text = String(status || '');
    if (/待[簽签]收|未[簽签]收/.test(text)) return false;
    return /已[簽签]收/.test(text);
  })(order.status);
  let imported = 0;
  if (!signed) {
  (order.rows || []).forEach((row, rowIndex) => {
    const key = trackingKey(row.trackingNo);
    if (!key) return;
    const already = (freight.items || []).some((item) => itemTrackingKeys(item).includes(key));
    if (already) return;
    const identity = purchaseIdentity([row.note, row.productName].filter(Boolean).join(' '), '');
    const id = batchId + '-' + String(rowIndex + 1).padStart(3, '0');
    matchedIds.add(id);
    freight.items.push(Object.assign({
      id,
      date: String(row.receivedAt || order.orderDate || today).slice(0, 10),
      provider: 'haohong',
      logisticsSource: 'haohong',
      forwarder: '豪鴻集運倉',
      destinationWarehouse: 'TW_BAOHUI',
      destinationSite: '寶輝據點',
      handlingPerItemTwd: 20,
      costMode: 'haohong_weight_batch',
      trackingNo: row.trackingNo || '',
      haohongTrackingNo: row.trackingNo || '',
      haohongOrderId: order.haohongOrderId || '',
      haohongOrderCode: order.haohongOrderCode || '',
      batchId,
      productName: row.productName || '豪鴻待補產品名稱',
      purchaseContentRaw: row.note || '',
      note: row.note || '',
      quantity: quantityFromContent(row.note || ''),
      actualWeightKg: num(row.actualWeightKg),
      volumeWeightKg: num(row.volumeWeightKg),
      billedWeightKg: num(row.billedWeightKg),
      shippingWeightKg: num(row.billedWeightKg),
      weightKg: num(row.billedWeightKg),
      weightSource: 'haohong_order_detail',
      haohongWeightUpdatedAt: now,
      haohongPackageStatus: '豪鴻已形成正式批次',
      haohongAwaitingConsolidation: false,
      progress: '到貨待分類',
      classificationStatus: 'pending',
      requiresProductReview: true,
      issueType: '待分類',
      salePrice: 0,
      customerPriority: identity.customerPriority,
      inventoryOwnership: identity.customerPriority ? 'customer_allocated_pending_match' : 'company_stock',
      createdAt: now,
      updatedAt: now,
    }, identity));
    imported += 1;
    });
  }
  if (signed && matchedItemCount === 0 && imported === 0) {
    return { batchId, batchNo, packageCount, matchedItemCount, imported: 0, skipped: true };
  }
  freight.batches = (freight.batches || []).map((batch) => {
    if (String(batch.id || '') === batchId) return batch;
    const packageRows = (batch.packageRows || []).filter((row) => !matchedIds.has(String(row.sourceItemId || row.id || '')));
    const itemCount = packageRows.reduce((sum, row) => sum + Math.max(1, Number(row.quantity || row.qty || 1)), 0);
    const trackingNumbers = Array.from(new Set(packageRows.map((row) => text(row.trackingNo || row.haohongTrackingNo, 200)).filter(Boolean)));
    return Object.assign({}, batch, {
      packageRows,
      itemCount,
      unitCount: itemCount,
      packageCount: trackingNumbers.length,
      trackingNumbers,
    });
  }).filter((batch) => String(batch.id || '') === batchId || Number(batch.itemCount || 0) > 0 || !/^SHEET-.*-HH$/i.test(String(batch.id || '')));
  const items = (freight.items || []).filter((item) => String(item.batchId || '') === batchId);
  const platformAccounts = Array.from(new Set(items.map((item) => item.purchaseAccount || item.platform || '').filter((value) => value && value !== '豪鴻未分類')));
  const packageRows = items.map((item) => Object.assign({}, item, { sourceItemId: item.id, snapshotAt: now }));
  const batch = Object.assign({}, existingBatch || {}, {
    id: batchId,
    batchNo,
    haohongOrderId: order.haohongOrderId || '',
    haohongOrderCode: order.haohongOrderCode || '',
    logisticsTrackingNo: order.transferOrderNo || '',
    firstTrackingNo: items[0] && items[0].trackingNo || '',
    trackingNumbers: Array.from(rowByTracking.keys()),
    date: String(order.orderDate || today).slice(0, 10),
    provider: 'haohong',
    forwarder: '豪鴻集運倉',
    costMode: 'haohong_weight_batch',
    chargeType: 'shipping',
    warehouseTaxType: 'tax_exempt',
    destinationWarehouse: 'TW_BAOHUI',
    destinationSite: '寶輝據點',
    handlingPerItemTwd: 20,
    platform: platformAccounts.length === 1 ? platformAccounts[0] : (platformAccounts.length ? '多帳號' : '豪鴻未分類'),
    purchasePlatform: '拼多多',
    purchaseAccounts: platformAccounts,
    amount: num(order.shippingFeeTwd),
    shippingFeeTwd: num(order.shippingFeeTwd),
    chargeTotalTwd: num(order.shippingFeeTwd),
    totalActualWeightKg: num(order.totalActualWeightKg),
    totalVolumeWeightKg: num(order.totalVolumeWeightKg),
    totalBilledWeightKg: num(order.totalBilledWeightKg),
    itemCount: items.reduce((sum, item) => sum + Math.max(1, Number(item.quantity || 1)), 0),
    unitCount: items.reduce((sum, item) => sum + Math.max(1, Number(item.quantity || 1)), 0),
    packageCount,
    costBasisQty: packageCount,
    costBasisSource: '豪鴻批次 ' + batchNo + ' 登記件數',
    costReferenceNo: batchNo,
    allocationPerItem: 0,
    packageRows,
    status: signed ? '已簽收完成' : (existingBatch && existingBatch.status || '已集運'),
    priorityCustomerOrder: items.some((item) => item.customerPriority),
    haohongSyncedAt: now,
    updatedAt: now,
    createdAt: existingBatch && existingBatch.createdAt || now,
    note: '由豪鴻我的訂單自動同步；件数 ' + packageCount + '，總計費重量 ' + num(order.totalBilledWeightKg) + ' kg，本批運費 NT$' + num(order.shippingFeeTwd) + '。',
  });
  freight.batches = (freight.batches || []).filter((row) => String(row.id || '') !== batchId);
  freight.batches.unshift(batch);
  return { batchId, batchNo, packageCount, matchedItemCount, imported };
}

function orderIsSigned(status) {
  const text = String(status || '');
  if (/待[簽签]收|未[簽签]收/.test(text)) return false;
  return /已[簽签]收/.test(text) || text.indexOf('已簽收完成') !== -1;
}

function isFormalHaohongBatch(batch) {
  return /^HAOHONG-/i.test(String(batch && batch.id || '')) && String(batch && batch.haohongOrderId || '').trim() !== '';
}

function isHaohongLeftoverSheet(batch) {
  if (!batch || isFormalHaohongBatch(batch)) return false;
  const blob = [
    batch.provider,
    batch.forwarder,
    batch.costMode,
    batch.id,
    batch.haohongOrderId,
    batch.haohongOrderCode,
  ].join(' ').toLowerCase();
  return /hao|豪鴻|haohong-/.test(blob);
}

function leftoverBatchTrackKeys(batch, items) {
  const keys = new Set();
  const add = (value) => {
    const key = trackingKey(value);
    if (key) keys.add(key);
  };
  (batch && batch.trackingNumbers || []).forEach(add);
  add(batch && batch.logisticsTrackingNo);
  add(batch && batch.firstTrackingNo);
  add(batch && batch.taiwanTrackingNo);
  (batch && batch.packageRows || []).forEach((row) => {
    add(row && row.trackingNo);
    add(row && row.haohongTrackingNo);
  });
  (items || []).forEach((item) => {
    if (String(item.batchId || '') !== String(batch && batch.id || '')) return;
    itemTrackingKeys(item).forEach((key) => keys.add(key));
  });
  return keys;
}

function absorbLeftoverHaohongSheets(freight) {
  if (!freight || !Array.isArray(freight.batches)) return { absorbedCount: 0, absorbed: [] };
  const batches = freight.batches;
  const items = freight.items || [];
  const signedTracks = new Map();
  batches.forEach((batch) => {
    if (!isFormalHaohongBatch(batch) || !orderIsSigned(batch.status)) return;
    leftoverBatchTrackKeys(batch, items).forEach((key) => {
      if (!signedTracks.has(key)) signedTracks.set(key, batch);
    });
  });
  const absorbed = [];
  const now = new Date().toISOString();
  freight.batches = batches.map((batch) => {
    if (!isHaohongLeftoverSheet(batch) || orderIsSigned(batch.status)) return batch;
    const hit = Array.from(leftoverBatchTrackKeys(batch, items)).find((key) => signedTracks.has(key));
    if (!hit) return batch;
    const host = signedTracks.get(hit);
    absorbed.push({ leftoverId: batch.id, trackingNo: hit, into: host && host.id || '' });
    return Object.assign({}, batch, {
      status: '已簽收完成',
      absorbedInto: host && host.id || '',
      absorbedTrackingNo: hit,
      haohongAbsorbedAt: now,
      updatedAt: now,
      note: [
        batch.note,
        '已併入 ' + (host && (host.batchNo || host.id) || '') + ' 已簽收完成；本列是空殼舊表，不是漏抓。',
      ].filter(Boolean).join(' '),
    });
  });
  return { absorbedCount: absorbed.length, absorbed };
}

function writeLogisticsSnapshot(packages, orders, extra) {
  const SNAPSHOT = path.join(DATA, 'haohong-logistics-snapshot.json');
  writeJson(SNAPSHOT, Object.assign({
    ok: true,
    savedAt: new Date().toISOString(),
    syncedAt: new Date().toISOString(),
    packages: packages || [],
    orders: orders || [],
    warnings: [],
    credentialsStored: true,
  }, extra || {}));
}

function applyHaohongOrders(freight, orders) {
  if (!Array.isArray(freight.batches)) freight.batches = [];
  if (!Array.isArray(freight.items)) freight.items = [];
  const applied = [];
    (orders || []).forEach((order) => {
    if (!order || !Array.isArray(order.rows) || !order.rows.length) return;
    const result = applyHaohongOrder(freight, order);
    if (!result.skipped) applied.push(result);
  });
  const absorbed = absorbLeftoverHaohongSheets(freight);
  const now = new Date().toISOString();
  freight.importMeta = Object.assign({}, freight.importMeta || {}, {
    haohongLastSyncedAt: now,
    haohongLastOrderCode: applied.length ? applied[applied.length - 1].batchNo : (freight.importMeta && freight.importMeta.haohongLastOrderCode || ''),
    haohongOrderBatchCount: applied.length,
    haohongHourlyOrderSync: true,
    haohongAbsorbedLeftoverCount: absorbed.absorbedCount,
  });
  freight.revision = Number(freight.revision || 0) + 1;
  freight.updatedAt = now;
  return { orderCount: applied.length, batches: applied, absorbedCount: absorbed.absorbedCount, absorbed: absorbed.absorbed };
}

async function ensureDeclaredGoodsValues(site, login, memberId, packages) {
  const filled = [];
  const skipped = [];
  for (const pack of packages) {
    if (num(pack.GoodsValue) > 0) continue;
    const id = text(pack.packageId, 100);
    if (!id) {
      skipped.push({ trackingNo: pack.trackingNo, reason: '缺少包裹識別碼' });
      continue;
    }
    try {
      await hhPost('EditGoodsByBillCodes', {
        BillCodeIds: id,
        MemberId: memberId,
        GoodsName: text(pack.productName, 180) || '日用品',
        GoodsValue: declaredGoodsValue(),
        GoodsNums: Math.max(1, Number(pack.Number || pack.quantity || 1)),
        GoodsTypeId: Number(text(pack.GoodsTypeId, 100) || 22) || 22,
        GoodsTypeName: text(pack.GoodsTypeName, 200) || '普货',
      }, site, login);
      pack.GoodsValue = declaredGoodsValue();
      filled.push(pack.trackingNo);
    } catch (error) {
      skipped.push({ trackingNo: pack.trackingNo, reason: String(error && error.message || error).slice(0, 180) });
    }
  }
  return { filledCount: filled.length, skippedCount: skipped.length, filled, skipped };
}

async function syncNames(site, login, memberId, packages, freight) {
  const byTracking = new Map();
  packages.forEach((row) => {
    const key = trackingKey(row.trackingNo);
    if (!key) return;
    const list = byTracking.get(key) || [];
    list.push(row);
    byTracking.set(key, list);
  });
  const updates = [];
  const seen = new Set();
  (freight.items || []).forEach((item) => {
    const key = trackingKey(item.trackingNo || item.haohongTrackingNo);
    if (!key || seen.has(key) || !byTracking.has(key)) return;
    const name = companyName(item);
    if (!name) return;
    const remote = (byTracking.get(key) || [])[0] || {};
    if (text(remote.productName, 180) === name) return;
    seen.add(key);
    updates.push({ trackingNo: remote.trackingNo || key, productName: name, rows: byTracking.get(key) });
  });
  const updated = [];
  const skipped = [];
  for (const update of updates.slice(0, 100)) {
    const ids = Array.from(new Set((update.rows || []).map((row) => text(row.packageId, 100)).filter(Boolean)));
    if (!ids.length) {
      skipped.push({ trackingNo: update.trackingNo, reason: '缺少包裹識別碼' });
      continue;
    }
    const source = update.rows[0] || {};
    try {
      await hhPost('EditGoodsByBillCodes', {
        BillCodeIds: ids.join(','),
        MemberId: memberId,
        GoodsName: update.productName,
        GoodsValue: declaredGoodsValue(),
        GoodsNums: Math.max(1, Number(source.Number || 1)),
        GoodsTypeId: Number(text(source.GoodsTypeId, 100) || 22) || 22,
        GoodsTypeName: text(source.GoodsTypeName, 200) || '普货',
      }, site, login);
      updated.push({ trackingNo: update.trackingNo, productName: update.productName });
    } catch (error) {
      skipped.push({ trackingNo: update.trackingNo, reason: String(error.message || error).slice(0, 180) });
    }
  }
  if (updated.length) {
    const names = new Map(updated.map((row) => [trackingKey(row.trackingNo), row.productName]));
    freight.haohongPackageWeights = (freight.haohongPackageWeights || []).map((row) => {
      const name = names.get(trackingKey(row.trackingNo));
      return name ? Object.assign({}, row, { productName: name }) : row;
    });
  }
  return { updatedCount: updated.length, skippedCount: skipped.length, skipped, updated };
}

async function main() {
  const creds = readJson(CREDS, {});
  const username = text(creds.username, 200);
  const password = text(creds.password, 500);
  if (!username || !password) {
    const result = { ok: false, error: '尚未記住豪鴻登入，無法每小時自動同步' };
    console.log(JSON.stringify(result));
    process.exitCode = 1;
    return result;
  }
  const siteJson = JSON.stringify({ Url: 'member.haohong56.com' });
  const site = await hhWrap('QueryUrl', {
    JsonData: siteJson,
    CusID: '25',
    KeyMd5: md5(siteJson + '123456'),
  });
  if (!site || !site.KeyText) throw new Error('豪鴻站台金鑰無效');
  const loginJson = JSON.stringify({ Uid: username, Pwd: Buffer.from(password, 'utf8').toString('base64') });
  const login = await hhWrap('Client_Login', {
    JsonData: loginJson,
    CusID: String(site.CusID || 25),
    KeyMd5: md5(loginJson + text(site.KeyText, 200)),
  });
  if (!login || !login.Token || !login.id) throw new Error('豪鴻登入失敗；若要求驗證碼，請先在網頁完成驗證');
  const memberId = text(login.id, 100);
  const packagesByTracking = new Map();
  for (const packageType of [2, 3, 1]) {
    const rows = asRows(await hhPost('QueryBillCode', {
      Client: memberId,
      OrdeTeyp: packageType,
      HouseId: 0,
      BillCode: '',
    }, site, login));
    rows.forEach((row) => {
      const pack = normalizePackage(row, packageType);
      const key = trackingKey(pack.trackingNo);
      if (!key) return;
      packagesByTracking.set(key, mergePackage(packagesByTracking.get(key), pack));
    });
  }
  const freightPreview = readJson(FREIGHT, { items: [], haohongPackageWeights: [] });
  const missingLookup = await lookupMissingPackages(site, login, memberId, packagesByTracking, freightPreview);
  const packages = Array.from(packagesByTracking.values());
  if (process.argv.indexOf('--catalog-only') !== -1) {
    const orders = await fetchHaohongOrders(site, login, memberId);
    writeLogisticsSnapshot(packages, orders);
    const freightOnly = readJson(FREIGHT, { items: [], batches: [] });
    freightOnly.items = Array.isArray(freightOnly.items) ? freightOnly.items : [];
    freightOnly.batches = Array.isArray(freightOnly.batches) ? freightOnly.batches : [];
    const now = new Date().toISOString();
    const trackKey = (value) => String(value || '').replace(/\s+/g, '').toUpperCase();
    const itemKeys = (item) => [item.trackingNo, item.haohongTrackingNo].concat(Array.isArray(item.trackingNumbers) ? item.trackingNumbers : []).map(trackKey).filter(Boolean);
    const placeholder = (value) => {
      const name = String(value || '').trim();
      return !name || name === '豪鴻待補產品名稱' || name === '後台尚未建檔';
    };
    let imported = 0;
    let named = 0;
    orders.forEach((order) => {
      const batch = freightOnly.batches.find((row) => {
        const ids = [row.id, row.batchNo, row.haohongOrderId, row.haohongOrderCode].map(String);
        return ids.indexOf(String(order.haohongOrderId || '')) >= 0 || ids.indexOf(String(order.haohongOrderCode || '')) >= 0;
      });
      const batchId = batch ? String(batch.id || '') : '';
      (order.rows || []).forEach((row) => {
        const key = trackKey(row.trackingNo);
        if (!key) return;
        const item = freightOnly.items.find((rowItem) => itemKeys(rowItem).indexOf(key) >= 0);
        if (item) {
          if (placeholder(item.productName) && String(row.productName || '').trim()) {
            item.productName = String(row.productName).trim();
            item.updatedAt = now;
            named += 1;
          }
          return;
        }
        if (!batchId) return;
        freightOnly.items.push({
          id: 'HAOHONG-PACKAGE-' + key.replace(/[^A-Z0-9_-]/g, ''),
          date: String(row.receivedAt || order.orderDate || now).slice(0, 10),
          provider: 'haohong',
          logisticsSource: 'haohong',
          forwarder: '豪鴻集運倉',
          destinationWarehouse: 'TW_BAOHUI',
          destinationSite: '寶輝據點',
          handlingPerItemTwd: 20,
          costMode: 'haohong_weight_batch',
          trackingNo: row.trackingNo || '',
          haohongTrackingNo: row.trackingNo || '',
          haohongOrderId: order.haohongOrderId || '',
          haohongOrderCode: order.haohongOrderCode || '',
          batchId: batchId,
          productName: String(row.productName || '').trim() || '豪鴻待補產品名稱',
          purchaseContentRaw: row.note || '',
          note: row.note || '',
          quantity: Math.max(1, Number(row.quantity || 1)),
          actualWeightKg: Number(row.actualWeightKg || 0),
          volumeWeightKg: Number(row.volumeWeightKg || 0),
          billedWeightKg: Number(row.billedWeightKg || 0),
          progress: '到貨待分類',
          classificationStatus: 'pending',
          requiresProductReview: true,
          issueType: '待分類',
          createdAt: now,
          updatedAt: now
        });
        imported += 1;
      });
      if (batch) {
        const items = freightOnly.items.filter((item) => String(item.batchId || '') === String(batch.id || ''));
        batch.packageRows = items.map((item) => Object.assign({}, item, { sourceItemId: item.id, snapshotAt: now }));
        batch.itemCount = items.reduce((sum, item) => sum + Math.max(1, Number(item.quantity || 1)), 0);
        batch.unitCount = batch.itemCount;
        batch.updatedAt = now;
      }
    });
    const absorbed = absorbLeftoverHaohongSheets(freightOnly);
    if (imported || named || absorbed.absorbedCount) writeJson(FREIGHT, freightOnly);
    console.log(JSON.stringify({
      ok: true,
      catalogOnly: true,
      orderCount: orders.length,
      packageCount: packages.length,
      imported: imported,
      named: named,
      absorbedCount: absorbed.absorbedCount,
      snapshotWritten: true,
    }));
    return { ok: true, catalogOnly: true, imported: imported, named: named, absorbedCount: absorbed.absorbedCount };
  }
  const freight = readJson(FREIGHT, { items: [], haohongPackageWeights: [] });
  const forecast = await registerMissingPackages(site, login, memberId, packagesByTracking, freight);
  if (forecast.createdCount) {
    for (const packageType of [2, 3, 1]) {
      const rows = asRows(await hhPost('QueryBillCode', {
        Client: memberId,
        OrdeTeyp: packageType,
        HouseId: 0,
        BillCode: '',
      }, site, login));
      rows.forEach((row) => {
        const pack = normalizePackage(row, packageType);
        const key = trackingKey(pack.trackingNo);
        if (!key) return;
        packagesByTracking.set(key, mergePackage(packagesByTracking.get(key), pack));
      });
    }
  }
  const refreshed = Array.from(packagesByTracking.values());
  const values = await ensureDeclaredGoodsValues(site, login, memberId, refreshed);
  const applied = applyPackages(freight, refreshed);
  let orderApply = { orderCount: 0, batches: [] };
  let orderError = '';
  try {
    const orders = await fetchHaohongOrders(site, login, memberId);
    orderApply = applyHaohongOrders(freight, orders);
    writeLogisticsSnapshot(refreshed, orders);
    orderApply.snapshotWritten = true;
  } catch (error) {
    orderError = String(error && error.message || error).slice(0, 180);
  }
  const names = await syncNames(site, login, memberId, refreshed, freight);
  writeJson(FREIGHT, freight);
  const result = {
    ok: true,
    at: taipeiNow().replace(' ', 'T') + '+08:00',
    packageCount: applied.packageCount,
    matchedItemCount: applied.matchedItemCount,
    imported: applied.imported,
    forecastCreated: forecast.createdCount,
    forecastSkipped: forecast.skippedCount,
    valuesFilled: values.filledCount,
    valuesSkipped: values.skippedCount,
    orderCount: orderApply.orderCount,
    absorbedCount: orderApply.absorbedCount || 0,
    snapshotWritten: !!orderApply.snapshotWritten,
    orderBatches: orderApply.batches.map((row) => ({
      batchNo: row.batchNo,
      packageCount: row.packageCount,
      matchedItemCount: row.matchedItemCount,
      imported: row.imported,
    })),
    namesUpdated: names.updatedCount,
    namesSkipped: names.skippedCount,
    namesPushed: names.updated,
    namesSkippedDetail: names.skipped,
    missingLookup: missingLookup,
  };
  if (orderError) result.orderError = orderError;
  const log = readJson(LOG, { version: 1, events: [] });
  log.events = [result].concat(Array.isArray(log.events) ? log.events : []).slice(0, 200);
  writeJson(LOG, log);
  console.log(JSON.stringify(result));
  return result;
}

if (require.main === module) {
  main().catch((error) => {
    const result = { ok: false, at: taipeiNow().replace(' ', 'T') + '+08:00', error: String(error && error.message || error).slice(0, 240) };
    const log = readJson(LOG, { version: 1, events: [] });
    log.events = [result].concat(Array.isArray(log.events) ? log.events : []).slice(0, 200);
    writeJson(LOG, log);
    console.log(JSON.stringify(result));
    process.exit(1);
  });
}

module.exports = {
  trackingKey,
  trackingsMissingHaohongWeight,
  orderIsSigned,
  isFormalHaohongBatch,
  isHaohongLeftoverSheet,
  leftoverBatchTrackKeys,
  absorbLeftoverHaohongSheets,
  writeLogisticsSnapshot,
};
