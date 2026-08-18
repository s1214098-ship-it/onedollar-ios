#!/usr/bin/env node
/**
 * 從本店公開頁 https://shop.yungching.com.tw/039519001/list 更新 shop-listings.json。
 * 用法：在 web/huowang-portal 執行 node sync-shop.js
 */
const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const SHOP = "https://shop.yungching.com.tw/039519001";
const FEATURED = new Set(["7264835", "7353161", "7355377", "7447476", "7463246", "7386704"]);
const AREA_RE = /^(.*?)[\s　]+((?:宜蘭縣|桃園市|台北市|新北市|臺北市|臺中市|台中市|臺南市|台南市|高雄市|基隆市|新竹市|新竹縣|苗栗縣|彰化縣|南投縣|雲林縣|嘉義市|嘉義縣|屏東縣|臺東縣|台東縣|花蓮縣|澎湖縣).+)$/;
const BOILER = /(把你的需求當成責任|用專業與效率守護每個細節|讓買房成為安心的開始|永慶集團全台最強聯賣體系|精準配對提升效|專業把關每個細節|讓你買得對，也買得安心|成就人生重要選擇|用專業替你把關每一步|為你創造最大價值)[。，,、]?/g;

function fetch(url) {
  return execFileSync("curl", ["-sL", "-A", "Mozilla/5.0", "--max-time", "30", url], { encoding: "utf8" });
}

function parsePage(html) {
  const items = [];
  const re = /<a target="_blank" href="\/\/buy\.yungching\.com\.tw\/house\/(\d+)" class="search-list-title[^"]*"[\s\S]*?item_id="\1">\s*<h1>(.*?)<\/h1>\s*<\/a>\s*<span class="search-list-description">([\s\S]*?)<\/span>\s*<ul class="search-list-item-detail">([\s\S]*?)<\/ul>[\s\S]{0,1200}?class="search-list-item-price">([\s\S]*?)<\/div>/g;
  let m;
  while ((m = re.exec(html))) {
    const hid = m[1];
    const title = decode(m[2]).replace(/\s+/g, " ").trim();
    const desc = decode(m[3].replace(/<[^>]+>/g, " ")).replace(/\s+/g, " ").trim();
    const yc = (desc.match(/YC\d+/) || [""])[0];
    const facts = [...m[4].matchAll(/<li>([\s\S]*?)<\/li>/g)]
      .map((x) => decode(x[1].replace(/<[^>]+>/g, " ")).replace(/\s+/g, " ").trim())
      .filter(Boolean);
    const prices = [...m[5].replace(/<[^>]+>/g, " ").matchAll(/([\d,]+)\s*萬/g)].map((x) => x[1].replace(/,/g, ""));
    let cover = "";
    const imgM = html.match(new RegExp(`item_id="${hid}"[\\s\\S]{0,900}?<img src="([^"]+)"`));
    if (imgM) {
      cover = decode(imgM[1].startsWith("//") ? `https:${imgM[1]}` : imgM[1]);
    }
    items.push({ hid, title, desc, yc, facts, prices, cover });
  }
  return items;
}

function decode(s) {
  return s.replace(/&amp;/g, "&").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&quot;/g, '"').replace(/&#39;/g, "'");
}

function num(s) {
  const m = String(s || "").match(/([\d,]+(?:\.\d+)?)/);
  return m ? m[1].replace(/,/g, "") : "";
}

function clean(raw) {
  const areaM = raw.title.match(AREA_RE);
  const short = areaM ? areaM[1].replace("...", "").replace(/[.\s]+$/, "") : raw.title;
  const area = areaM ? areaM[2].trim() : "";
  const facts = raw.facts;
  const type = facts[0] || "";
  const houseAge = facts.find((x) => /年$/.test(x)) || "";
  let floor = facts.find((x) => /樓/.test(x) && /\d/.test(x)) || "";
  if (floor === type) floor = "";
  const land = num(facts.find((x) => x.startsWith("土地")));
  const mainBuild = num(facts.find((x) => x.startsWith("主")));
  const build = num(facts.find((x) => x.startsWith("建物")));
  const layout = facts.find((x) => /房|廳|衛/.test(x)) || "";
  let note = raw.desc.replace(BOILER, "").replace(raw.yc, "").replace(/\s+/g, " ").trim().slice(0, 160);
  return {
    id: raw.hid,
    ycNo: raw.yc,
    title: short,
    area,
    type,
    houseAge,
    floor,
    land,
    mainBuild,
    build,
    layout,
    price: raw.prices.length ? raw.prices[raw.prices.length - 1] : "",
    priceOrig: raw.prices.length >= 2 ? raw.prices[0] : "",
    url: `https://buy.yungching.com.tw/house/${raw.hid}`,
    featured: FEATURED.has(raw.hid),
    cover: raw.cover || "",
    images: raw.cover ? [raw.cover] : [],
    note
  };
}

const seen = new Set();
const listings = [];
for (let pg = 1; pg <= 12; pg++) {
  const html = fetch(`${SHOP}/list?pg=${pg}`);
  const page = parsePage(html);
  if (!page.length) break;
  for (const raw of page) {
    if (seen.has(raw.hid)) continue;
    seen.add(raw.hid);
    listings.push(clean(raw));
  }
  if (page.length < 30) break;
}

const out = {
  shopUrl: `${SHOP}/`,
  listUrl: `${SHOP}/list`,
  rentUrl: "https://rent.yungching.com.tw/list/039519001_shop",
  shopName: "永慶不動產羅東文化盛群加盟店",
  company: "盛群房屋仲介股份有限公司",
  phone: "03-9519001",
  syncedAt: new Date().toISOString().slice(0, 10),
  count: listings.length,
  featuredIds: [...FEATURED],
  listings
};

const dest = path.join(__dirname, "shop-listings.json");
try {
  const prev = JSON.parse(fs.readFileSync(dest, "utf8"));
  const byId = Object.fromEntries((prev.listings || []).map((x) => [x.id, x]));
  listings.forEach((row) => {
    const old = byId[row.id];
    if (!old) return;
    if (old.images && old.images.length > (row.images || []).length) row.images = old.images;
    if (old.cover && !row.cover) row.cover = old.cover;
  });
} catch (_) { /* first run */ }
fs.writeFileSync(dest, JSON.stringify(out, null, 2) + "\n");
console.log(`已同步本店公開物件 ${listings.length} 筆 → ${dest}`);
