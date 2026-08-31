const fs = require("fs");
const path = require("path");

const dbPath = process.argv[2] || "F:/Web/huowang-staging/data/shared-db.json";
const keys = ["YCUT-1012453", "d70563f5-e6cb-4563-a3b9-b99ceb78bd5f", "羅東旁阿嬤ㄟ厝靜巷超值透天"];
const collections = ["properties", "borrowItems", "sameStoreItems", "peerDevelopmentItems", "targets", "storeDevelopmentItems"];

function blob(item) {
  if (!item || typeof item !== "object") return "";
  return [item.id, item.publicNo, item.externalId, item.contractNo, item.sourceUrl, item.title, item.showCaseNo, item.caseName].join(" ");
}

function isBlocked(item) {
  const text = blob(item);
  return keys.some((key) => key && text.indexOf(key) >= 0);
}

function parseMaybe(value, fallback) {
  if (Array.isArray(value) || (value && typeof value === "object")) return value;
  if (typeof value !== "string" || !value.trim()) return fallback;
  try {
    return JSON.parse(value);
  } catch {
    return fallback;
  }
}

const raw = JSON.parse(fs.readFileSync(dbPath, "utf8"));
const report = {};
let changed = false;
for (const key of collections) {
  if (!(key in raw)) {
    report[key] = "missing";
    continue;
  }
  const wasString = typeof raw[key] === "string";
  const rows = parseMaybe(raw[key], []);
  if (!Array.isArray(rows)) {
    report[key] = "not-array";
    continue;
  }
  const kept = rows.filter((item) => !isBlocked(item));
  const removed = rows.length - kept.length;
  report[key] = { before: rows.length, after: kept.length, removed };
  if (removed) {
    changed = true;
    raw[key] = wasString ? JSON.stringify(kept) : kept;
  }
}

if (changed) {
  const stamp = new Date().toISOString().replace(/[:.]/g, "-");
  const backup = dbPath + ".bak-block-" + stamp;
  fs.copyFileSync(dbPath, backup);
  fs.writeFileSync(dbPath, JSON.stringify(raw, null, 2), "utf8");
  report.backup = backup;
}

const cacheDir = path.join(path.dirname(dbPath), "cache");
const apiDir = path.join(path.dirname(path.dirname(dbPath)), "api");
let cacheDeleted = 0;
const cacheNames = [];
for (const dir of [cacheDir, apiDir]) {
  if (!fs.existsSync(dir)) continue;
  for (const name of fs.readdirSync(dir)) {
    if (!/^(admin-|public-|peer-list).*\.json$/.test(name) && name !== "peer-list.json") continue;
    try {
      fs.unlinkSync(path.join(dir, name));
      cacheDeleted += 1;
      cacheNames.push(name);
    } catch (e) {}
  }
}
report.cacheNames = cacheNames;
report.changed = changed;
report.cacheDeleted = cacheDeleted;
console.log(JSON.stringify(report, null, 2));
