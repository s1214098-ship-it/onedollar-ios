const fs = require("fs");
const path = require("path");
const https = require("https");
const http = require("http");
const { URL } = require("url");

const root = path.resolve(__dirname, "..");
const dbPath = path.join(root, "data", "shared-db.json");
const summaryPath = path.join(root, "本次同業開發池同步摘要.json");
const syncLogPath = path.join(root, "logs", "peer-brands-sync.log");
const HOME_TOWNS = ["羅東鎮", "五結鄉", "冬山鄉", "宜蘭市", "礁溪鄉", "員山鄉"];
const PACIFIC_AUTH = "Basic cHJtczpwcm1z";
const C21_STORES = ["j312", "j313", "j318", "j325", "j333"];
const UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125 Safari/537.36";

function log(msg) {
  const line = String(msg);
  try {
    process.stdout.write(line + "\n");
  } catch {}
  try {
    fs.appendFileSync(syncLogPath, `[${new Date().toISOString()}] ${line}\n`);
  } catch {}
}

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

function num(value) {
  const n = Number(String(value ?? "").replace(/[^\d.]/g, ""));
  return Number.isFinite(n) ? n : 0;
}

function photosFrom(urls, label) {
  const seen = new Set();
  const out = [];
  for (const url of urls || []) {
    const full = clean(url).replace(/[)\]>]+$/, "");
    if (!full.startsWith("http") || seen.has(full)) continue;
    seen.add(full);
    out.push({ name: `${label}${String(out.length + 1).padStart(2, "0")}`, data: full });
  }
  return out;
}

function parseYilanAddress(text) {
  const raw = clean(text);
  const m = raw.match(/宜蘭縣([^\s,，]{2,4}[鄉鎮市區])/);
  return {
    county: /宜蘭/.test(raw) ? "宜蘭縣" : "",
    area: m ? m[1] : "",
    road: raw.replace(/^宜蘭縣/, "").replace(m ? m[1] : "", "").trim(),
  };
}

function isYilan(text) {
  return /宜蘭/.test(clean(text));
}

function peerKey(item) {
  return `${clean(item.sourceHost)}|${clean(item.externalId)}`;
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

function fetchText(url, options = {}) {
  const timeoutMs = Number(options.timeout || 20000);
  return new Promise((resolve, reject) => {
    const target = new URL(url);
    const lib = target.protocol === "http:" ? http : https;
    const body = options.body ? Buffer.from(String(options.body), "utf8") : null;
    const headers = {
      "user-agent": UA,
      accept: options.accept || "text/html,application/json,*/*",
      "accept-language": "zh-TW,zh;q=0.9",
      connection: "close",
      ...(options.headers || {}),
    };
    if (body) headers["content-length"] = String(body.length);
    let settled = false;
    let req;
    const finish = (err, value) => {
      if (settled) return;
      settled = true;
      clearTimeout(hardTimer);
      if (err && req) {
        try {
          req.destroy();
        } catch {}
      }
      if (err) reject(err);
      else resolve(value);
    };
    const hardTimer = setTimeout(() => finish(new Error("timeout " + url)), timeoutMs);
    req = lib.request(
      {
        hostname: target.hostname,
        port: target.port || (target.protocol === "http:" ? 80 : 443),
        path: target.pathname + target.search,
        method: options.method || "GET",
        headers,
        timeout: timeoutMs,
        agent: false,
        family: 4,
      },
      (res) => {
        const chunks = [];
        res.on("data", (chunk) => chunks.push(chunk));
        res.on("end", () => {
          const headerMap = {
            get(name) {
              const key = Object.keys(res.headers).find((k) => k.toLowerCase() === String(name).toLowerCase());
              const value = key ? res.headers[key] : undefined;
              return Array.isArray(value) ? value.join(", ") : value;
            },
            getSetCookie() {
              const value = res.headers["set-cookie"];
              return Array.isArray(value) ? value : value ? [value] : [];
            },
            forEach(fn) {
              for (const [key, value] of Object.entries(res.headers)) {
                fn(Array.isArray(value) ? value.join(", ") : value, key);
              }
            },
          };
          finish(null, {
            ok: res.statusCode >= 200 && res.statusCode < 400,
            status: res.statusCode,
            text: Buffer.concat(chunks).toString("utf8"),
            headers: headerMap,
          });
        });
      }
    );
    req.on("timeout", () => finish(new Error("timeout " + url)));
    req.on("error", (error) => finish(error));
    if (body) req.write(body);
    req.end();
  });
}

function toPeerRow(partial, existing) {
  const images = partial.images && partial.images.length ? partial.images : existing.images || existing.photos || [];
  const listed = clean(partial.listedDate || existing.listedDate);
  return {
    ...existing,
    id: clean(existing.id) || partial.id,
    externalId: partial.externalId,
    publicNo: partial.publicNo || existing.publicNo || partial.id,
    sourceSystem: partial.sourceSystem,
    sourceUrl: partial.sourceUrl,
    sourceHost: partial.sourceHost,
    sourceCompany: partial.sourceCompany,
    storeName: partial.storeName || existing.storeName || partial.sourceCompany,
    company: partial.sourceCompany,
    title: partial.title || existing.title,
    county: partial.county || existing.county || "宜蘭縣",
    area: partial.area || existing.area || "",
    district: partial.area || existing.district || "",
    address: partial.address || existing.address || "",
    road: partial.road || existing.road || "",
    price: String(partial.priceNumber || existing.price || ""),
    priceNumber: partial.priceNumber || num(existing.price),
    type: partial.type || existing.type || "",
    spec: partial.spec || existing.spec || "",
    layout: partial.layout || existing.layout || "",
    landArea: partial.landArea || existing.landArea || "",
    build: partial.build || existing.build || "",
    images,
    photos: images,
    gallery: images,
    image: images[0]?.data || existing.image || "",
    status: existing.status || "未帶入賣主開發",
    importRegion: "宜蘭縣",
    listedDate: listed,
    listedAt: listed,
    fetchedAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    photoUpdatedAt: images.length ? new Date().toISOString() : existing.photoUpdatedAt,
  };
}

async function syncPacific(summary) {
  const maxPages = Math.max(1, Number(process.env.PACIFIC_MAX_PAGES || 80));
  const areas = clean(process.env.PACIFIC_AREAS || HOME_TOWNS.join("+"));
  const rows = [];
  const payload = {
    Type: 1,
    CityID: "宜蘭縣",
    AreaID: areas,
    TotalPrice: "0-|-999999",
    ObjectAttribut: "",
    Keyword: "",
    TotalPing: "0-|-999999",
    Room: "0-|-999999",
    Age: "0-|-999999",
    Floor: "0-|-999999",
    Direction: "",
    HasStall: false,
    HasElevator: false,
    HasSchool: false,
    HasPark: false,
    HasMarket: false,
    HasMRT: false,
    Order: 1,
    DataType: 0,
    Page: 1,
  };
  let total = 0;
  for (let page = 1; page <= maxPages; page += 1) {
    payload.Page = page;
    let body = null;
    for (let attempt = 1; attempt <= 3; attempt += 1) {
      try {
        log(`pacific fetching page ${page} attempt ${attempt}`);
        const res = await fetchText("https://www.pacific.com.tw/api/ObjectAPI/SearchObject2", {
          method: "POST",
          accept: "application/json",
          timeout: 18000,
          headers: {
            authorization: PACIFIC_AUTH,
            "content-type": "application/json;charset=UTF-8",
            origin: "https://www.pacific.com.tw",
            referer: "https://www.pacific.com.tw/Object/ObjectList",
          },
          body: JSON.stringify(payload),
        });
        if (!res.ok) throw new Error(`pacific search HTTP ${res.status}`);
        body = JSON.parse(res.text);
        break;
      } catch (error) {
        log(`pacific page ${page} error ${error.message}`);
        if (attempt === 3) throw error;
        await sleep(1500 * attempt);
      }
    }
    total = Number(body.totalCount || 0);
    const list = Array.isArray(body.lstData) ? body.lstData : [];
    if (!list.length) break;
    for (const item of list) {
      const address = clean(item.address);
      if (!isYilan(address + clean(item.cityName) + clean(item.areaName))) continue;
      const loc = parseYilanAddress(address);
      const images = photosFrom([item.pic], "太平洋照片");
      const layout = [item.layoutRoom, item.layoutHall, item.layoutToilet]
        .some((n) => Number(n) > 0)
        ? `${Number(item.layoutRoom || 0)}房${Number(item.layoutHall || 0)}廳${Number(item.layoutToilet || 0)}衛`
        : "";
      rows.push(
        toPeerRow(
          {
            id: `EXT-pacific-${item.saleID}`,
            externalId: String(item.saleID),
            sourceSystem: "太平洋房屋公開頁",
            sourceUrl: `https://www.pacific.com.tw/Object/ObjectDetail/?saleID=${item.saleID}`,
            sourceHost: "www.pacific.com.tw",
            sourceCompany: "太平洋房屋",
            storeName: clean(item.storeName) || "太平洋房屋",
            title: clean(item.objectName),
            county: loc.county || "宜蘭縣",
            area: loc.area || clean(item.areaName),
            address,
            road: loc.road,
            priceNumber: num(item.sellTotalPrice),
            type: clean(item.attributName),
            layout,
            landArea: item.landArea ? String(item.landArea) : "",
            build: item.totalArea ? String(item.totalArea) : "",
            spec: [clean(item.attributName), item.totalArea ? `${item.totalArea}坪` : "", layout].filter(Boolean).join(" / "),
            images,
            listedDate: clean(item.lastUpdateDate).slice(0, 10),
          },
          {}
        )
      );
    }
    summary.pacific.pages = page;
    log(`pacific page ${page}/${maxPages} batch=${list.length} rows=${rows.length} total=${total}`);
    if (page % 10 === 0) await sleep(2500);
    else await sleep(900);
    if (list.length < 8) break;
  }
  summary.pacific.listed = total;
  summary.pacific.kept = rows.length;
  log(`pacific kept=${rows.length} listed=${total}`);
  return rows;
}

async function fetchSinyiDetail(id) {
  const res = await fetchText(`https://www.sinyi.com.tw/buy/house/${id}`, {
    headers: { referer: "https://www.sinyi.com.tw/buy/list/Yilan-county" },
  });
  if (!res.ok) throw new Error(`sinyi ${id} HTTP ${res.status}`);
  const jsonld = res.text.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/);
  const data = jsonld ? JSON.parse(jsonld[1]) : {};
  const about = data.about || {};
  const addr = about.address || {};
  const offer = data.offers || {};
  const county = clean(addr.addressRegion);
  const area = clean(addr.addressLocality);
  const road = clean(addr.streetAddress);
  const address = [county, area, road].filter(Boolean).join("");
  if (!isYilan(address + clean(data.name) + clean(data.description))) return null;
  const layout = (about.additionalProperty || []).find((row) => row.name === "格局")?.value || "";
  const imgs = [...res.text.matchAll(/https:\/\/res\.sinyi\.com\.tw\/buy\/[^"'\\\s)]+/g)].map((m) => m[0]);
  const priceTwd = num(offer.price);
  return toPeerRow(
    {
      id: `EXT-sinyi-${id}`,
      externalId: id,
      sourceSystem: "信義房屋公開頁",
      sourceUrl: `https://www.sinyi.com.tw/buy/house/${id}`,
      sourceHost: "www.sinyi.com.tw",
      sourceCompany: "信義房屋",
      storeName: "信義房屋",
      title: clean(data.name),
      county: county || "宜蘭縣",
      area,
      address,
      road,
      priceNumber: priceTwd >= 10000 ? Math.round(priceTwd / 10000) : priceTwd,
      type: layout.includes("土地") ? "土地" : "",
      layout,
      build: about.floorSize?.value ? String(about.floorSize.value) : "",
      spec: [layout, about.floorSize?.value ? `${about.floorSize.value}坪` : ""].filter(Boolean).join(" / "),
      images: photosFrom(imgs, "信義照片"),
    },
    {}
  );
}

async function syncSinyi(summary) {
  const maxPages = Math.max(1, Number(process.env.SINYI_MAX_PAGES || 25));
  const detailMax = Math.max(1, Number(process.env.SINYI_DETAIL_MAX || 200));
  const ids = [];
  const seen = new Set();
  for (let page = 1; page <= maxPages; page += 1) {
    const url = page === 1 ? "https://www.sinyi.com.tw/buy/list/Yilan-county" : `https://www.sinyi.com.tw/buy/list/Yilan-county/${page}`;
    const res = await fetchText(url, { headers: { referer: "https://www.sinyi.com.tw/buy/list/Yilan-county" } });
    if (!res.ok) throw new Error(`sinyi list HTTP ${res.status}`);
    const pageIds = [...new Set([...res.text.matchAll(/\/buy\/house\/([A-Z0-9]{5,})/g)].map((m) => m[1]))];
    for (const id of pageIds) {
      if (!seen.has(id)) {
        seen.add(id);
        ids.push(id);
      }
    }
    summary.sinyi.pages = page;
    if (page % 10 === 0) log(`sinyi list page ${page} ids=${ids.length}`);
    await sleep(160);
    if (!pageIds.length) break;
  }
  const rows = [];
  for (const id of ids.slice(0, detailMax)) {
    try {
      const row = await fetchSinyiDetail(id);
      if (row) rows.push(row);
      summary.sinyi.fetched += 1;
    } catch (error) {
      summary.sinyi.failed += 1;
      summary.errors.push({ brand: "sinyi", id, message: error.message });
    }
    await sleep(280);
    if (rows.length && rows.length % 20 === 0) log(`sinyi details ${rows.length}`);
  }
  summary.sinyi.listed = ids.length;
  summary.sinyi.kept = rows.length;
  log(`sinyi kept=${rows.length} listed=${ids.length}`);
  return rows;
}

function cookieHeader(jar) {
  return Object.entries(jar.cookies)
    .map(([k, v]) => `${k}=${v}`)
    .join("; ");
}

function absorbCookies(jar, headers) {
  const list = [];
  if (headers && typeof headers.getSetCookie === "function") {
    list.push(...headers.getSetCookie());
  }
  if (headers && typeof headers.forEach === "function") {
    headers.forEach((value, key) => {
      if (String(key).toLowerCase() === "set-cookie") list.push(value);
    });
  }
  if (!list.length && headers && typeof headers.get === "function") {
    const single = headers.get("set-cookie");
    if (single) list.push(single);
  }
  for (const line of list) {
    for (const piece of String(line).split(/,(?=\s*[A-Za-z0-9_-]+=)/)) {
      const part = piece.split(";")[0];
      const eq = part.indexOf("=");
      if (eq > 0) jar.cookies[part.slice(0, eq).trim()] = part.slice(eq + 1).trim();
    }
  }
  if (jar.cookies["XSRF-TOKEN"]) jar.xsrf = decodeURIComponent(jar.cookies["XSRF-TOKEN"]);
}

function parseC21Cards(html, storeId) {
  const rows = [];
  const blocks = html.split(/class="recbox"/i);
  for (const block of blocks.slice(1)) {
    const idMatch = block.match(/\/(?:store\/j\d+\/buy|buypage)\/(\d+)/i);
    if (!idMatch) continue;
    const title = clean((block.match(/recbox__title[^>]*>([^<]+)/i) || [])[1]);
    const info = clean(block.replace(/<[^>]+>/g, " "));
    const loc = parseYilanAddress(title + info);
    const priceMatch = info.match(/(\d[\d,]*)\s*萬/);
    const img = (block.match(/url\('?(https:\/\/www\.century21\.com\.tw\/uploads\/[^')\s]+)/i) || [])[1];
    rows.push(
      toPeerRow(
        {
          id: `EXT-century21-${idMatch[1]}`,
          externalId: idMatch[1],
          sourceSystem: "21世紀公開頁",
          sourceUrl: `https://www.century21.com.tw/buypage/${idMatch[1]}`,
          sourceHost: "www.century21.com.tw",
          sourceCompany: "21世紀不動產",
          storeName: `21世紀-${storeId.toUpperCase()}`,
          title,
          county: loc.county || "宜蘭縣",
          area: loc.area,
          address: loc.county ? `${loc.county}${loc.area}${loc.road}` : title,
          road: loc.road,
          priceNumber: num(priceMatch && priceMatch[1]),
          images: photosFrom([img], "21世紀照片"),
        },
        {}
      )
    );
  }
  return rows;
}

async function syncCentury21(summary) {
  const maxPages = Math.max(1, Number(process.env.C21_MAX_PAGES || 8));
  const rows = [];
  const seen = new Set();
  for (const storeId of C21_STORES) {
    const jar = { cookies: {}, xsrf: "" };
    const buyUrl = `https://www.century21.com.tw/store/${storeId}/buy`;
    const first = await fetchText(buyUrl);
    absorbCookies(jar, first.headers);
    if (!jar.xsrf) {
      summary.errors.push({ brand: "c21", id: storeId, message: "missing xsrf" });
      continue;
    }
    let storeKept = 0;
    for (let page = 1; page <= maxPages; page += 1) {
      const res = await fetchText(`https://www.century21.com.tw/get_object_list/${storeId}`, {
        method: "POST",
        accept: "application/json",
        headers: {
          "content-type": "application/json;charset=UTF-8",
          origin: "https://www.century21.com.tw",
          referer: buyUrl,
          "x-requested-with": "XMLHttpRequest",
          "x-xsrf-token": jar.xsrf,
          cookie: cookieHeader(jar),
        },
        body: JSON.stringify({
          store_id: storeId.toUpperCase(),
          ajax: "json",
          iframe: true,
          buyOrRent: "buy",
          city: "all-city",
          zip: "all-district",
          category: "all-category",
          keyword: "",
          order: "newly",
          page,
        }),
      });
      absorbCookies(jar, res.headers);
      if (!res.ok) {
        summary.errors.push({ brand: "c21", id: `${storeId}:${page}`, message: `HTTP ${res.status}` });
        break;
      }
      let body;
      try {
        body = JSON.parse(res.text);
      } catch {
        summary.errors.push({ brand: "c21", id: `${storeId}:${page}`, message: "invalid json" });
        break;
      }
      const pageRows = parseC21Cards(body.html || "", storeId);
      for (const row of pageRows) {
        if (seen.has(row.externalId)) continue;
        seen.add(row.externalId);
        rows.push(row);
        storeKept += 1;
      }
      summary.c21.pages += 1;
      const maxPage = Number(body.max_page || 0);
      if (page >= maxPage) break;
      await sleep(220);
    }
    summary.c21.stores[storeId] = storeKept;
    log(`c21 ${storeId} yilan=${storeKept}`);
  }
  summary.c21.kept = rows.length;
  log(`c21 kept=${rows.length}`);
  return rows;
}

async function main() {
  const summary = {
    checkedAt: new Date().toISOString(),
    pacific: { pages: 0, listed: 0, kept: 0 },
    sinyi: { pages: 0, listed: 0, fetched: 0, failed: 0, kept: 0 },
    c21: { pages: 0, kept: 0, stores: {} },
    merged: 0,
    added: 0,
    updated: 0,
    errors: [],
  };

  log("start peer brands sync");
  const incoming = [];
  try {
    log("pacific start");
    incoming.push(...(await syncPacific(summary)));
  } catch (error) {
    summary.errors.push({ brand: "pacific", message: error.message });
    log("pacific failed " + error.message);
  }
  try {
    log("sinyi start");
    incoming.push(...(await syncSinyi(summary)));
  } catch (error) {
    summary.errors.push({ brand: "sinyi", message: error.message });
    log("sinyi failed " + error.message);
  }
  try {
    log("c21 start");
    incoming.push(...(await syncCentury21(summary)));
  } catch (error) {
    summary.errors.push({ brand: "c21", message: error.message });
    log("c21 failed " + error.message);
  }
  log(`incoming=${incoming.length}; reading shared-db`);

  const raw = JSON.parse(fs.readFileSync(dbPath, "utf8"));
  const db = { ...raw };
  const peerItems = parseMaybeJson(raw.peerDevelopmentItems, []);
  log(`db loaded peerItems=${peerItems.length}`);

  const byKey = new Map();
  for (const item of peerItems) byKey.set(peerKey(item), item);
  for (const row of incoming) {
    const key = peerKey(row);
    const existing = byKey.get(key);
    if (existing) {
      byKey.set(key, toPeerRow(row, existing));
      summary.updated += 1;
    } else {
      byKey.set(key, row);
      summary.added += 1;
    }
  }
  const nextPeer = [...byKey.values()];
  summary.merged = incoming.length;
  summary.peerActive = nextPeer.length;

  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const backup = `${dbPath}.bak-peer-brands-${stamp}`;
  fs.copyFileSync(dbPath, backup);
  summary.backup = backup;

  db.peerDevelopmentItems = nextPeer;
  db.sourceAgentFillLog = parseMaybeJson(raw.sourceAgentFillLog, []);
  db.sourceAgentFillLog.unshift({ at: new Date().toISOString(), mode: "yilan-peer-brands", ...summary });
  db.sourceAgentFillLog = db.sourceAgentFillLog.slice(0, 30);

  const out = { ...db };
  stringifyCollections(out);
  fs.writeFileSync(dbPath, JSON.stringify(out, null, 2), "utf8");
  writePeerListCache(nextPeer);
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2), "utf8");
  log(JSON.stringify({ ...summary, errors: summary.errors.slice(0, 20), errorCount: summary.errors.length }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
