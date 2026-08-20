const fs = require("fs");
const path = require("path");
const crypto = require("crypto").webcrypto;

const root = path.resolve(__dirname, "..");
const dbPath = path.join(root, "data", "shared-db.json");
const summaryPath = path.join(root, "本次永慶同店異店開發池同步摘要.json");
const latestListPath = path.join(root, "原始營業員抓取清單-羅東文化盛群最新.json");
const HOME_SHOP_ID = "039519001";
const HOME_TOWNS = ["羅東鎮", "五結鄉", "冬山鄉"];
const PEER_YC_SOURCE = "永慶買屋公開頁";
const encoder = new TextEncoder();
let keyPair;

function parseMaybeJson(value, fallback) {
  if (Array.isArray(value) || (value && typeof value === "object")) return value;
  if (typeof value !== "string" || !value.trim()) return fallback;
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
}

function stringifyCollections(db) {
  for (const key of Object.keys(db)) {
    if (Array.isArray(db[key]) || (db[key] && typeof db[key] === "object")) {
      db[key] = JSON.stringify(db[key]);
    }
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function clean(value) {
  return String(value ?? "").trim();
}

function writePeerListCache(items) {
  const nodeCrypto = require("crypto");
  const cacheDir = path.join(root, "data", "cache");
  fs.mkdirSync(cacheDir, { recursive: true });
  const slim = [];
  const shards = {};
  let withPhotos = 0;
  const addSrc = (list, value) => {
    const src = clean(typeof value === "string" ? value : value && (value.data || value.src || value.url));
    if (src && !list.includes(src)) list.push(src);
  };
  for (const item of items || []) {
    const source = clean(item.sourceSystem);
    const blob = `${item.county || ""}${item.address || ""}${item.title || ""}`;
    if (source === "公開同業網站同步" && !/宜蘭/.test(blob)) continue;
    const srcs = [];
    for (const key of ["images", "photos", "gallery"]) {
      if (Array.isArray(item[key])) item[key].forEach((img) => addSrc(srcs, img));
    }
    addSrc(srcs, item.image);
    const src = srcs[0] || "";
    if (src) withPhotos += 1;
    slim.push({
      id: item.id || "",
      externalId: item.externalId || "",
      publicNo: item.publicNo || "",
      sourceSystem: item.sourceSystem || "",
      sourceUrl: item.sourceUrl || "",
      sourceHost: item.sourceHost || "",
      sourceCompany: item.sourceCompany || "",
      storeName: item.storeName || "",
      company: item.company || "",
      title: item.title || "",
      county: item.county || "",
      area: item.area || "",
      district: item.district || item.area || "",
      address: item.address || "",
      road: item.road || "",
      price: item.price || "",
      priceNumber: item.priceNumber || 0,
      type: item.type || "",
      layout: item.layout || "",
      landArea: item.landArea || "",
      build: item.build || "",
      status: item.status || "",
      listedDate: item.listedDate || item.listedAt || "",
      image: src,
      images: src ? [{ name: "照片01", data: src }] : [],
      photoCount: srcs.length,
    });
    if (!srcs.length) continue;
    const lookupIds = [item.id, item.externalId, item.publicNo, item.sourceHost && item.externalId ? `${item.sourceHost}|${item.externalId}` : ""]
      .map(clean)
      .filter(Boolean);
    for (const lookupId of lookupIds) {
      const shard = "photos-" + nodeCrypto.createHash("sha1").update(lookupId).digest("hex").slice(0, 2) + ".json";
      shards[shard] = shards[shard] || {};
      shards[shard][lookupId] = srcs;
    }
  }
  const payload = JSON.stringify({ ok: true, cachedAt: new Date().toISOString(), data: { peerDevelopmentItems: slim }, total: slim.length, withPhotos });
  fs.writeFileSync(path.join(cacheDir, "peer-list.json"), payload);
  try {
    fs.writeFileSync(path.join(root, "api", "peer-list.json"), payload);
  } catch (e) {}
  for (const [name, map] of Object.entries(shards)) {
    fs.writeFileSync(path.join(cacheDir, name), JSON.stringify(map));
  }
}

function num(value) {
  if (value === null || value === undefined || value === "") return "";
  const n = Number(value);
  return Number.isFinite(n) ? String(n) : clean(value);
}

function priceNumber(value) {
  const n = Number(String(value ?? "").replace(/[^\d.]/g, ""));
  return Number.isFinite(n) ? n : 0;
}

function withHttps(url) {
  const text = clean(url);
  if (!text) return "";
  if (text.startsWith("//")) return `https:${text}`;
  if (text.startsWith("http://")) return text.replace(/^http:/, "https:");
  return text;
}

function fullImageUrl(url, width = 1200, height = 900) {
  const text = withHttps(url);
  return text.replace(/\{0\}/g, String(width)).replace(/\{1\}/g, String(height));
}

function sourceId(item) {
  const fromUrl = clean(item.sourceUrl).match(/\/house\/(\d+)/);
  if (fromUrl) return fromUrl[1];
  const external = clean(item.externalId).match(/\d{6,}/);
  if (external) return external[0];
  return "";
}

function shopIdFromUrl(url) {
  const match = clean(url).match(/shop\.yungching\.com\.tw\/(\d{6,})/);
  return match ? match[1] : "";
}

function isHomeStore(api, shopId) {
  const id = shopId || shopIdFromUrl(api?.shopInfo?.shopUrl);
  if (id === HOME_SHOP_ID) return true;
  const name = clean([api?.shopInfo?.headName, api?.shopInfo?.shopName, api?.shopInfo?.companyName].filter(Boolean).join(""));
  return /文化盛群|盛群房屋/.test(name);
}

async function makeKey(passphrase) {
  const salt = new Uint8Array([2, 7, 0, 5, 1, 3, 8, 0]);
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", encoder.encode(passphrase)));
  const baseKey = await crypto.subtle.importKey("raw", digest, "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", salt, iterations: 1000, hash: "SHA-1" },
    baseKey,
    384
  );
  const bytes = new Uint8Array(bits);
  return [
    await crypto.subtle.importKey("raw", bytes.slice(0, 32), { name: "AES-CBC" }, false, ["decrypt"]),
    bytes.slice(32, 48),
  ];
}

async function decryptYungchingData(data) {
  keyPair ||= await makeKey("YungChing.Buy");
  const [key, iv] = keyPair;
  const encrypted = Uint8Array.from(Buffer.from(data, "base64"));
  const plain = await crypto.subtle.decrypt({ name: "AES-CBC", iv }, key, encrypted);
  return JSON.parse(new TextDecoder().decode(plain));
}

async function fetchText(url) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15000);
  try {
    const response = await fetch(url, {
      signal: controller.signal,
      headers: {
        "user-agent": "Mozilla/5.0 AppleWebKit/537.36 Chrome/125 Safari/537.36",
        accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      },
    });
    if (!response.ok) throw new Error(`HTTP ${response.status} ${url}`);
    return response.text();
  } finally {
    clearTimeout(timer);
  }
}

async function discoverShops() {
  const shops = new Map();
  shops.set(HOME_SHOP_ID, { id: HOME_SHOP_ID, town: "羅東鎮", home: true });
  for (const town of HOME_TOWNS) {
    const url = `https://shop.yungching.com.tw/region/${encodeURIComponent("宜蘭縣-" + town)}_c/`;
    try {
      const html = await fetchText(url);
      for (const match of html.matchAll(/\b(03\d{7})\b/g)) {
        const id = match[1];
        if (!shops.has(id)) shops.set(id, { id, town, home: id === HOME_SHOP_ID });
      }
    } catch (error) {
      console.warn("discover shop failed", town, error.message);
    }
    await new Promise((resolve) => setTimeout(resolve, 120));
  }
  return [...shops.values()];
}

async function fetchShopListIds(shopId) {
  const shopListBase = `https://shop.yungching.com.tw/${shopId}/list`;
  const first = await fetchText(shopListBase);
  const pages = new Set([1]);
  const pageRe = new RegExp(`/${shopId}/list\\?pg=(\\d+)`, "g");
  for (const match of first.matchAll(pageRe)) pages.add(Number(match[1]));

  const ids = new Set();
  const collect = (html) => {
    for (const match of html.matchAll(/buy\.yungching\.com\.tw\/house\/(\d+)/g)) ids.add(match[1]);
    const listIds = html.match(/list_ids:\s*\[([^\]]+)\]/);
    if (listIds) {
      for (const id of listIds[1].split(",").map((x) => clean(x))) {
        if (/^\d+$/.test(id)) ids.add(id);
      }
    }
  };
  collect(first);
  for (const page of [...pages].filter((page) => page !== 1).sort((a, b) => a - b)) {
    await new Promise((resolve) => setTimeout(resolve, 120));
    collect(await fetchText(`${shopListBase}?pg=${page}`));
  }
  return { ids: [...ids], pages: pages.size };
}

async function fetchHouse(id) {
  const url = `https://buy.yungching.com.tw/api/v2/house?id=${id}`;
  let lastError = new Error("fetch failed");
  for (let attempt = 1; attempt <= 2; attempt++) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(url, {
        signal: controller.signal,
        headers: {
          "user-agent": "Mozilla/5.0 AppleWebKit/537.36 Chrome/125 Safari/537.36",
          referer: `https://buy.yungching.com.tw/house/${id}`,
          accept: "application/json,text/plain,*/*",
        },
      });
      const text = await response.text();
      if (text.trimStart().startsWith("<")) {
        throw new Error("rate-limited-html");
      }
      const body = JSON.parse(text);
      const encrypted = body.data || body.body?.data;
      if (!response.ok || body.status !== "Success" || !encrypted) {
        throw new Error(`API ${response.status} ${body.status || ""}`);
      }
      return decryptYungchingData(encrypted);
    } catch (error) {
      lastError = error;
      if (attempt < 2) await sleep(2000 * attempt);
    } finally {
      clearTimeout(timer);
    }
  }
  throw lastError;
}

function formatLayout(patternInfo) {
  const info = patternInfo || {};
  const room = Number(info.room || 0);
  const living = Number(info.livingRoom || 0);
  const bath = Number(info.bathRoom || 0);
  if (!room && !living && !bath) return "";
  return `${room}房${living}廳${bath}衛`;
}

function formatFloor(api) {
  if (clean(api.caseTypeName) === "土地" || clean(api.purposeName) === "土地") return "";
  const info = api.floorInfo || {};
  const from = info.fromFloor;
  const to = info.toFloor;
  const up = info.upFloor;
  if (from && to && from !== to && up) return `${from} ~ ${to} / ${up}樓`;
  if (from && up) return `${from}/${up}樓`;
  if (from) return `${from}樓`;
  return "";
}

function formatSpec(api) {
  const parts = [];
  if (api.caseTypeName) parts.push(clean(api.caseTypeName));
  if (api.buildAge !== undefined && api.buildAge !== null && clean(api.caseTypeName) !== "土地") {
    parts.push(`屋齡${api.buildAge}年`);
  }
  const floor = formatFloor(api);
  if (floor) parts.push(`樓層${floor}`);
  const land = num(api.pinInfo?.landArea);
  const reg = num(api.pinInfo?.regArea);
  const main = num(api.pinInfo?.mainArea);
  if (land) parts.push(`地坪${land}坪`);
  if (reg && reg !== "0") parts.push(`建坪${reg}坪`);
  if (main && main !== "0") parts.push(`主建${main}坪`);
  const layout = formatLayout(api.patternInfo);
  if (layout) parts.push(layout);
  return parts.join(" / ");
}

function formatUnitPrice(api) {
  if (api.unitPrice) return `${api.unitPrice}萬/坪`;
  const price = Number(api.price);
  const basis =
    clean(api.caseTypeName) === "土地" || clean(api.purposeName) === "土地"
      ? Number(api.pinInfo?.landArea)
      : Number(api.pinInfo?.regArea || api.pinInfo?.landArea);
  if (Number.isFinite(price) && price > 0 && Number.isFinite(basis) && basis > 0) {
    return `${(price / basis).toFixed(2)}萬/坪`;
  }
  return "";
}

function extractImages(api, label) {
  const photos = [];
  const add = (url) => {
    const full = fullImageUrl(url);
    if (full && !photos.includes(full)) photos.push(full);
  };
  add(api.photoInfo?.cover);
  for (const pic of api.photoInfo?.pictures || []) add(pic.clearUrl || pic.photoUrl);
  add(api.photoInfo?.layout);
  return photos.map((url, index) => ({
    name: `${label}${String(index + 1).padStart(2, "0")}`,
    data: url,
  }));
}

function storeNameOf(api) {
  const shop = api.shopInfo || {};
  return clean([shop.headName, shop.shopName].filter(Boolean).join("")) || "永慶不動產";
}

function buildCommon(api, houseId, existing = {}, label) {
  const shop = api.shopInfo || {};
  const ivr = api.ivrInfo || {};
  const tel = clean(ivr.tel);
  const ext = clean(ivr.extension);
  const phone = [tel, ext ? `分機${ext}` : ""].filter(Boolean).join(" ");
  const extracted = extractImages(api, label);
  const existingPhotos = Array.isArray(existing.photos) && existing.photos.length
    ? existing.photos
    : (Array.isArray(existing.images) && existing.images.length ? existing.images : []);
  const images = extracted.length ? extracted : existingPhotos;
  const kind = clean(api.caseTypeName) || clean(api.purposeName);
  const layout = formatLayout(api.patternInfo) || (/土地|農地/.test(kind) ? "土地" : "");
  const publicNo = clean(api.showCaseNo) || clean(existing.publicNo);
  return {
    ...existing,
    externalId: houseId,
    publicNo,
    sourceSystem: PEER_YC_SOURCE,
    sourceUrl: `https://buy.yungching.com.tw/house/${houseId}`,
    shopUrl: clean(shop.shopUrl) || "",
    shopId: shopIdFromUrl(shop.shopUrl),
    company: storeNameOf(api),
    storeCompany: clean(shop.companyName),
    storeName: storeNameOf(api),
    storeAddress: clean(shop.address),
    storePhone: tel,
    brand: clean(shop.headName) || "永慶不動產",
    contact: clean(shop.name) || clean(existing.contact) || "承辦人未公開",
    sourceAgent: clean(shop.name) || clean(existing.sourceAgent) || "承辦人未公開",
    phone,
    sourcePhone: phone,
    sourceMobile: clean(ivr.mobile),
    kind,
    type: kind,
    purpose: clean(api.purposeName),
    city: clean(api.county),
    county: clean(api.county),
    district: clean(api.district),
    area: clean(api.district),
    road: clean(api.road),
    address: clean(api.address),
    nearby: [api.district, api.road].filter(Boolean).join(" "),
    title: clean(api.caseName),
    price: api.price ? `${api.price}萬` : clean(existing.price),
    priceNumber: priceNumber(api.price),
    unitPrice: formatUnitPrice(api) || clean(existing.unitPrice),
    layout,
    houseAge: api.buildAge !== undefined && api.buildAge !== null ? `${api.buildAge}` : clean(existing.houseAge),
    floor: formatFloor(api),
    landArea: num(api.pinInfo?.landArea),
    build: num(api.pinInfo?.regArea),
    buildingArea: num(api.pinInfo?.regArea),
    mainBuildArea: num(api.pinInfo?.mainArea),
    spec: formatSpec(api),
    publicNote: clean(api.caseFeature || api.caseDes || existing.publicNote),
    images,
    image: images[0]?.data || clean(existing.image),
    photos: images,
    agentFetchedAt: new Date().toISOString(),
    agentFetchStatus: "ok",
    updatedAt: new Date().toISOString(),
  };
}

function toSameStore(api, houseId, existing) {
  return {
    ...buildCommon(api, houseId, existing, "同店公開頁照片"),
    id: clean(existing.id) || `STORE-${clean(api.showCaseNo) || houseId}`,
    status: "可合作",
    sameStoreSourceListStatus: "current",
    publicAgentName: clean(existing.publicAgentName) || "郭火旺",
    publicAgentPhone: clean(existing.publicAgentPhone) || "0911-294-374",
    publicAgentLine: clean(existing.publicAgentLine) || "0911294374",
    outboundContactLocked: true,
    outboundContactPolicy: "public-post-use-huowang-only",
  };
}

function toBorrow(api, houseId, existing) {
  return {
    ...buildCommon(api, houseId, existing, "異店公開頁照片"),
    id: clean(existing.id) || `YC-${houseId}`,
    status: "可合作",
    privateNote: clean(existing.privateNote) || `永慶異店流通物件。原門市：${storeNameOf(api)}。前台不顯示原承辦敏感資訊。`,
    sourceCheckStatus: "live",
    sourceLive: true,
    sourceCheckAt: new Date().toISOString(),
  };
}

function toPeer(api, houseId, existing) {
  const common = buildCommon(api, houseId, existing, "開發池照片");
  const listed = clean(api.onsaleDate || api.createDate || existing.listedDate);
  return {
    ...existing,
    id: clean(existing.id) || `YC-${houseId}`,
    externalId: houseId,
    publicNo: common.publicNo,
    sourceSystem: PEER_YC_SOURCE,
    sourceUrl: common.sourceUrl,
    sourceHost: "buy.yungching.com.tw",
    sourceCompany: common.storeName,
    storeName: common.storeName,
    company: common.storeName,
    title: common.title,
    county: common.county,
    area: common.district,
    district: common.district,
    address: common.road,
    road: common.road,
    price: String(common.priceNumber || ""),
    priceNumber: common.priceNumber,
    type: common.kind,
    spec: common.spec,
    layout: common.layout,
    publicNotes: common.publicNote,
    keyFeatures: common.publicNote,
    images: common.images,
    photos: common.photos,
    gallery: common.photos,
    status: existing.status || "未帶入賣主開發",
    importRegion: "宜蘭縣",
    listedDate: listed,
    listedAt: listed,
    fetchedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    photoUpdatedAt: new Date().toISOString(),
  };
}

function indexById(list) {
  const byHouseId = new Map();
  const byPublicNo = new Map();
  for (const item of list) {
    const id = sourceId(item);
    if (id) byHouseId.set(id, item);
    if (clean(item.publicNo)) byPublicNo.set(clean(item.publicNo), item);
  }
  return { byHouseId, byPublicNo };
}

async function main() {
  const raw = JSON.parse(fs.readFileSync(dbPath, "utf8"));
  const db = { ...raw };
  const sameStoreItems = parseMaybeJson(raw.sameStoreItems, []);
  const borrowItems = parseMaybeJson(raw.borrowItems, []);
  const peerItems = parseMaybeJson(raw.peerDevelopmentItems, []);
  const sameIdx = indexById(sameStoreItems);
  const borrowIdx = indexById(borrowItems);
  const existingYcPeers = peerItems.filter((item) => clean(item.sourceSystem) === PEER_YC_SOURCE || clean(item.sourceHost) === "buy.yungching.com.tw");
  const peerIdx = indexById(existingYcPeers);

  const summary = {
    checkedAt: new Date().toISOString(),
    shops: [],
    shopPages: {},
    uniqueHouses: 0,
    fetched: 0,
    failed: 0,
    sameStore: { added: 0, updated: 0, active: 0, missing: 0 },
    borrow: { added: 0, updated: 0, keptManual: 0, active: 0 },
    peer: { added: 0, updated: 0, keptOtherSources: 0, active: 0 },
    errors: [],
    backup: "",
  };

  const found = new Map();
  const retryOnly = process.env.RETRY_FAILED === "1";
  if (retryOnly && fs.existsSync(summaryPath)) {
    const prev = JSON.parse(fs.readFileSync(summaryPath, "utf8"));
    for (const row of prev.errors || []) {
      if (row.houseId) found.set(String(row.houseId), "retry");
    }
    console.log(`retry-only ids=${found.size}`);
  } else {
    const shops = await discoverShops();
    summary.shops = shops.map((shop) => shop.id);
    for (const shop of shops) {
      const { ids, pages } = await fetchShopListIds(shop.id);
      summary.shopPages[shop.id] = { town: shop.town, home: !!shop.home, pages, ids: ids.length };
      console.log(`shop ${shop.id} ${shop.town}${shop.home ? " HOME" : ""} pages=${pages} ids=${ids.length}`);
      for (const id of ids) {
        if (!found.has(id)) found.set(id, shop.id);
      }
      await sleep(200);
    }
  }
  summary.uniqueHouses = found.size;
  console.log(`uniqueHouses=${found.size}`);
  let processed = 0;

  const nextSame = [...sameStoreItems];
  const nextBorrow = [...borrowItems];
  const nextPeerYc = [...existingYcPeers];
  const maxNewPeer = Math.max(0, Number(process.env.MAX_NEW_PEER || 40));
  const confirmedSame = new Set();
  const homeFetchFailed = new Set();
  const existingSameIds = new Set(sameStoreItems.map(sourceId).filter(Boolean));
  const seenBorrow = new Set(borrowItems.filter((item) => item.status === "可合作").map(sourceId).filter(Boolean));
  const seenPeer = new Set(existingYcPeers.map(sourceId).filter(Boolean));
  let consecutiveFails = 0;
  let newPeerFetched = 0;
  summary.maxNewPeer = maxNewPeer;
  summary.homeListed = [...found.values()].filter((shopId) => shopId === HOME_SHOP_ID).length;
  console.log(`daily home refresh homeListed=${summary.homeListed} maxNewPeer=${maxNewPeer}`);

  for (const [houseId, shopId] of found) {
    const isHomeList = shopId === HOME_SHOP_ID;
    const alreadyOther = seenBorrow.has(houseId) || seenPeer.has(houseId);
    if (!isHomeList) {
      if (alreadyOther || existingSameIds.has(houseId)) {
        processed += 1;
        continue;
      }
      if (newPeerFetched >= maxNewPeer) {
        processed += 1;
        continue;
      }
    }
    try {
      const api = await fetchHouse(houseId);
      consecutiveFails = 0;
      summary.fetched += 1;
      processed += 1;
      if (processed % 20 === 0 || processed === found.size) {
        console.log(`fetched ${processed}/${found.size} ok=${summary.fetched} failed=${summary.failed}`);
      }
      const home = isHomeList || isHomeStore(api, shopIdFromUrl(api.shopInfo?.shopUrl));
      if (home) {
        const existing = sameIdx.byHouseId.get(houseId) || sameIdx.byPublicNo.get(clean(api.showCaseNo));
        const next = toSameStore(api, houseId, existing || {});
        if (existing) {
          Object.assign(existing, next);
          summary.sameStore.updated += 1;
        } else {
          nextSame.push(next);
          summary.sameStore.added += 1;
        }
        confirmedSame.add(houseId);
      } else {
        newPeerFetched += 1;
        const existingBorrow = borrowIdx.byHouseId.get(houseId) || borrowIdx.byPublicNo.get(clean(api.showCaseNo));
        const next = toBorrow(api, houseId, existingBorrow || {});
        if (existingBorrow) {
          Object.assign(existingBorrow, next);
          summary.borrow.updated += 1;
        } else {
          nextBorrow.push(next);
          summary.borrow.added += 1;
        }
        seenBorrow.add(houseId);
        const existingPeer = peerIdx.byHouseId.get(houseId) || peerIdx.byPublicNo.get(clean(api.showCaseNo));
        const peerRow = toPeer(api, houseId, existingPeer || {});
        if (existingPeer) {
          Object.assign(existingPeer, peerRow);
          summary.peer.updated += 1;
        } else {
          nextPeerYc.push(peerRow);
          summary.peer.added += 1;
        }
        seenPeer.add(houseId);
      }
      if (summary.fetched % 30 === 0) await sleep(4000);
      else await sleep(350);
    } catch (error) {
      consecutiveFails += 1;
      summary.failed += 1;
      processed += 1;
      if (isHomeList) homeFetchFailed.add(houseId);
      summary.errors.push({ houseId, message: error.message || String(error) });
      if (processed % 20 === 0 || processed === found.size) {
        console.log(`fetched ${processed}/${found.size} ok=${summary.fetched} failed=${summary.failed}`);
      }
      if (consecutiveFails >= 8) {
        console.log("rate limit pause 25s");
        await sleep(25000);
        consecutiveFails = 0;
      } else {
        await sleep(800);
      }
    }
  }

  const keptSame = [];
  for (const item of nextSame) {
    const id = sourceId(item);
    const homeText = /文化盛群|盛群房屋/.test(clean(item.storeName) + clean(item.company) + clean(item.storeCompany));
    if (id && seenBorrow.has(id) && !confirmedSame.has(id)) continue;
    if (id && confirmedSame.has(id)) {
      keptSame.push(item);
      continue;
    }
    if (id && homeFetchFailed.has(id)) {
      keptSame.push(item);
      continue;
    }
    if (homeText) {
      item.sameStoreSourceListStatus = "missing-from-current-shop-list";
      summary.sameStore.missing += 1;
      keptSame.push(item);
    }
  }
  const keptBorrow = [];
  for (const item of nextBorrow) {
    const id = sourceId(item);
    const isYc = /buy\.yungching\.com\.tw\/house\//.test(clean(item.sourceUrl));
    if (id && confirmedSame.has(id)) continue;
    if (isYc && id && !seenBorrow.has(id)) {
      item.status = "來源找不到-可刪除";
      item.sourceLive = false;
    }
    if (!isYc) summary.borrow.keptManual += 1;
    keptBorrow.push(item);
  }
  nextSame.length = 0;
  nextSame.push(...keptSame);
  nextBorrow.length = 0;
  nextBorrow.push(...keptBorrow);
  summary.sameStore.active = nextSame.filter((item) => clean(item.sameStoreSourceListStatus) === "current").length;
  summary.borrow.active = nextBorrow.filter((item) => item.status === "可合作").length;

  const otherPeers = peerItems.filter((item) => clean(item.sourceSystem) !== PEER_YC_SOURCE && clean(item.sourceHost) !== "buy.yungching.com.tw");
  summary.peer.keptOtherSources = otherPeers.length;
  summary.peer.active = nextPeerYc.length;
  const nextPeer = nextPeerYc.concat(otherPeers);

  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const backup = `${dbPath}.bak-yc-pools-${stamp}`;
  fs.copyFileSync(dbPath, backup);
  summary.backup = backup;

  db.sameStoreItems = nextSame;
  db.borrowItems = nextBorrow;
  db.peerDevelopmentItems = nextPeer;
  db.sourceAgentFillLog = parseMaybeJson(raw.sourceAgentFillLog, []);
  db.sourceAgentFillLog.unshift({ at: new Date().toISOString(), mode: "yilan-yungching-pools", ...summary });
  db.sourceAgentFillLog = db.sourceAgentFillLog.slice(0, 30);

  const out = { ...db };
  stringifyCollections(out);
  fs.writeFileSync(dbPath, JSON.stringify(out, null, 2), "utf8");
  writePeerListCache(nextPeer);
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2), "utf8");
  fs.writeFileSync(
    latestListPath,
    JSON.stringify(
      nextSame
        .filter((item) => item.sameStoreSourceListStatus === "current")
        .map((item) => ({
          id: item.id,
          publicNo: item.publicNo,
          title: item.title,
          price: item.price,
          unitPrice: item.unitPrice,
          layout: item.layout,
          floor: item.floor,
          landArea: item.landArea,
          build: item.build,
          sourceUrl: item.sourceUrl,
        })),
      null,
      2
    ),
    "utf8"
  );
  const printSummary = {
    ...summary,
    errors: summary.errors.slice(0, 20),
    errorCount: summary.errors.length,
  };
  console.log(JSON.stringify(printSummary, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
