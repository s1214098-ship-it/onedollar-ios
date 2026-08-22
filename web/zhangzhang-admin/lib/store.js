"use strict";

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const DATA_DIR = process.env.ZHANGZHANG_DATA_DIR
  ? path.resolve(process.env.ZHANGZHANG_DATA_DIR)
  : path.join(__dirname, "..", "data");
const DB_FILE = path.join(DATA_DIR, "db.json");
const SEED_FILE = path.join(__dirname, "..", "data", "seed.json");

function nowIso() {
  return new Date().toISOString();
}

function id(prefix) {
  return `${prefix}_${Date.now().toString(36)}${crypto.randomBytes(3).toString("hex")}`;
}

function emptyDb() {
  return {
    version: 1,
    users: [],
    settings: {},
    products: [],
    receipts: [],
    stockMoves: [],
    schedules: [],
    settlements: [],
    members: [],
    suppliers: [],
    warehouses: [],
    logs: []
  };
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function atomicWrite(file, data) {
  ensureDir(path.dirname(file));
  const tmp = `${file}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), "utf8");
  fs.renameSync(tmp, file);
}

function hashPassword(password, salt) {
  const useSalt = salt || crypto.randomBytes(16).toString("hex");
  const hash = crypto.scryptSync(String(password), useSalt, 32).toString("hex");
  return `${useSalt}:${hash}`;
}

function verifyPassword(password, stored) {
  if (!stored) return false;
  if (!stored.includes(":")) {
    return stored === String(password);
  }
  const [salt, hash] = stored.split(":");
  const next = crypto.scryptSync(String(password), salt, 32);
  const prev = Buffer.from(hash, "hex");
  if (prev.length !== next.length) return false;
  return crypto.timingSafeEqual(prev, next);
}

function hardenUsers(users) {
  return (users || []).map((user) => {
    const next = { ...user };
    if (next.password && !String(next.password).includes(":")) {
      next.password = hashPassword(next.password);
    }
    return next;
  });
}

function loadSeed() {
  if (!fs.existsSync(SEED_FILE)) return emptyDb();
  const seed = JSON.parse(fs.readFileSync(SEED_FILE, "utf8"));
  return {
    ...emptyDb(),
    ...seed,
    users: hardenUsers(seed.users)
  };
}

function loadDb() {
  ensureDir(DATA_DIR);
  if (!fs.existsSync(DB_FILE)) {
    const seeded = loadSeed();
    atomicWrite(DB_FILE, seeded);
    return seeded;
  }
  const db = { ...emptyDb(), ...JSON.parse(fs.readFileSync(DB_FILE, "utf8")) };
  const hardened = hardenUsers(db.users);
  const changed = JSON.stringify(hardened) !== JSON.stringify(db.users);
  db.users = hardened;
  if (changed) atomicWrite(DB_FILE, db);
  return db;
}

function saveDb(db) {
  atomicWrite(DB_FILE, db);
}

function pushLog(db, actor, action, target, detail) {
  db.logs.unshift({
    id: id("log"),
    at: nowIso(),
    actor: actor || "system",
    action,
    target: target || "",
    detail: detail || ""
  });
  db.logs = db.logs.slice(0, 1000);
}

function productStock(db, productId) {
  const moves = db.stockMoves.filter((m) => m.productId === productId);
  let qty = 0;
  let reserved = 0;
  let sold = 0;
  for (const move of moves) {
    if (move.type === "in") qty += Number(move.qty) || 0;
    if (move.type === "out") {
      qty -= Number(move.qty) || 0;
      sold += Number(move.qty) || 0;
    }
    if (move.type === "reserve") reserved += Number(move.qty) || 0;
    if (move.type === "release") reserved -= Number(move.qty) || 0;
  }
  return {
    stock_total: qty,
    stock_reserved: Math.max(0, reserved),
    stock_sold: sold,
    stock_available: qty - Math.max(0, reserved)
  };
}

function refreshProductStock(db, product) {
  const stock = productStock(db, product.id);
  return { ...product, ...stock, updated_at: product.updated_at || nowIso() };
}

function overview(db) {
  const products = db.products.map((p) => refreshProductStock(db, p));
  const inStock = products.filter((p) => Number(p.stock_total) > 0).length;
  const pendingShip = db.settlements.filter((s) => s.status === "已收款待出貨" || s.status === "待出貨").length;
  const unpaid = db.settlements.filter((s) => s.status === "待收款" || s.status === "部分收款");
  const received = db.settlements
    .filter((s) => s.status === "已完成" || s.status === "已出貨" || s.status === "已收款待出貨")
    .reduce((sum, s) => sum + Number(s.paid_amount || s.amount || 0), 0);
  const receivable = unpaid.reduce((sum, s) => sum + (Number(s.amount || 0) - Number(s.paid_amount || 0)), 0);
  return {
    products: products.length,
    inStock,
    schedules: db.schedules.length,
    liveSchedules: db.schedules.filter((s) => s.status === "排程中" || s.status === "競標中").length,
    members: db.members.length,
    suppliers: db.suppliers.length,
    warehouses: db.warehouses.filter((w) => w.type === "warehouse").length,
    receipts: db.receipts.length,
    settlements: db.settlements.length,
    pendingShip,
    unpaidCount: unpaid.length,
    received,
    receivable,
    recentReceipts: db.receipts.slice(0, 8),
    recentSettlements: db.settlements.slice(0, 8),
    recentMoves: db.stockMoves.slice(0, 8)
  };
}

function analytics(db) {
  const products = db.products.map((p) => refreshProductStock(db, p));
  const sold = db.settlements.filter((s) => ["已完成", "已出貨", "已收款待出貨"].includes(s.status));
  const revenue = sold.reduce((sum, s) => sum + Number(s.amount || 0), 0);
  const cost = sold.reduce((sum, s) => {
    const product = products.find((p) => p.id === s.productId);
    return sum + Number(product?.cost || 0) * Number(s.qty || 1);
  }, 0);
  const loss = db.settlements
    .filter((s) => s.status === "虧損" || Number(s.loss_amount) > 0)
    .reduce((sum, s) => sum + Number(s.loss_amount || 0), 0);
  return {
    revenue,
    cost,
    margin: revenue - cost,
    marginRate: revenue ? ((revenue - cost) / revenue) * 100 : 0,
    loss,
    soldCount: sold.reduce((sum, s) => sum + Number(s.qty || 1), 0),
    byChannel: groupSum(sold, "channel", "amount"),
    byWarehouse: groupSum(db.stockMoves.filter((m) => m.type === "in"), "warehouse", "qty")
  };
}

function groupSum(rows, key, valueKey) {
  const map = {};
  for (const row of rows) {
    const name = row[key] || "未分類";
    map[name] = (map[name] || 0) + Number(row[valueKey] || 0);
  }
  return Object.entries(map).map(([name, value]) => ({ name, value }));
}

function importLiveDir(db, dir, actor) {
  const map = {
    "products.json": "products",
    "members.json": "members",
    "suppliers.json": "suppliers",
    "warehouses.json": "warehouses",
    "schedules.json": "schedules",
    "settlements.json": "settlements",
    "receipts.json": "receipts"
  };
  const result = {};
  if (!fs.existsSync(dir)) return result;
  for (const [file, name] of Object.entries(map)) {
    const full = path.join(dir, file);
    if (!fs.existsSync(full)) continue;
    const rows = JSON.parse(fs.readFileSync(full, "utf8"));
    result[name] = importCollection(db, name, rows, actor);
  }
  const settingsFile = path.join(dir, "settings.json");
  if (fs.existsSync(settingsFile)) {
    db.settings = JSON.parse(fs.readFileSync(settingsFile, "utf8"));
    result.settings = { imported: true };
  }
  return result;
}

function importCollection(db, name, rows, actor) {
  if (!Array.isArray(rows)) throw new Error(`${name} 必須是陣列`);
  const allowed = {
    products: true,
    members: true,
    suppliers: true,
    warehouses: true,
    schedules: true,
    settlements: true,
    receipts: true,
    stockMoves: true,
    users: true
  };
  if (!allowed[name]) throw new Error(`不支援匯入 ${name}`);
  const existing = new Map((db[name] || []).map((row) => [row.id, row]));
  let added = 0;
  let updated = 0;
  for (const row of rows) {
    if (!row || !row.id) continue;
    if (existing.has(row.id)) {
      existing.set(row.id, { ...existing.get(row.id), ...row, updated_at: nowIso() });
      updated += 1;
    } else {
      existing.set(row.id, row);
      added += 1;
    }
  }
  db[name] = Array.from(existing.values());
  if (name === "users") db.users = hardenUsers(db.users);
  pushLog(db, actor, "import", name, `新增 ${added}、更新 ${updated}`);
  return { added, updated, total: db[name].length };
}

module.exports = {
  DATA_DIR,
  DB_FILE,
  id,
  nowIso,
  emptyDb,
  loadDb,
  saveDb,
  hashPassword,
  verifyPassword,
  pushLog,
  productStock,
  refreshProductStock,
  overview,
  analytics,
  importCollection,
  importLiveDir
};
