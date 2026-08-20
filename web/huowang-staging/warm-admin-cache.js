const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const here = __dirname;
const defaultRoot = fs.existsSync(path.join(here, "data", "shared-db.json"))
  ? here
  : path.resolve(here, "..");
const root = process.argv[2] || defaultRoot;
const dbPath = path.join(root, "data", "shared-db.json");
const cacheDir = path.join(root, "data", "cache");
const ADMIN_KEYS = [
  "properties",
  "borrowItems",
  "sameStoreItems",
  "archivedObjects",
  "importLogs",
  "customers",
  "targets",
  "ycutFollowIds",
  "employees",
  "passwordRecords",
  "layouts",
  "types",
  "expireStart",
  "expireLimit",
  "agentInfo"
];
const PUBLIC_KEYS = ["properties", "layouts", "types", "agentInfo", "sameStoreItems", "borrowItems"];

function parseList(value) {
  if (Array.isArray(value)) return value;
  if (typeof value === "string") {
    try {
      const parsed = JSON.parse(value);
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }
  return [];
}

function pick(db, keys) {
  const out = {};
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(db, key)) out[key] = db[key];
  }
  return out;
}

function writeCache(kind, keys, payload) {
  const hash = crypto.createHash("md5").update(keys.join(",")).digest("hex");
  const file = path.join(cacheDir, `${kind}-${hash}.json`);
  fs.writeFileSync(file, JSON.stringify(payload));
  return file;
}

if (!fs.existsSync(dbPath)) {
  throw new Error("missing " + dbPath);
}

fs.mkdirSync(cacheDir, { recursive: true });
const db = JSON.parse(fs.readFileSync(dbPath, "utf8"));
const counts = {
  properties: parseList(db.properties).length,
  sameStoreItems: parseList(db.sameStoreItems).length,
  targets: parseList(db.targets).length,
  borrowItems: parseList(db.borrowItems).length,
  peerDevelopmentItems: parseList(db.peerDevelopmentItems).length,
  storeDevelopmentItems: parseList(db.storeDevelopmentItems).length
};

const adminFile = writeCache("admin", ADMIN_KEYS, { ok: true, data: pick(db, ADMIN_KEYS) });
const publicFile = writeCache("public", PUBLIC_KEYS, { ok: true, public: true, data: pick(db, PUBLIC_KEYS) });
const publicDefaultFile = writeCache("public", ["properties", "sameStoreItems", "borrowItems", "layouts", "types", "agentInfo"], {
  ok: true,
  public: true,
  data: pick(db, ["properties", "sameStoreItems", "borrowItems", "layouts", "types", "agentInfo"])
});

console.log(JSON.stringify({ ok: true, counts, adminFile, publicFile, publicDefaultFile }, null, 2));
