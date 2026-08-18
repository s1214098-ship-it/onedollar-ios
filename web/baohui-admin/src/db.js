import { DatabaseSync } from "node:sqlite";
import { config, dbPath, ensureDirs } from "./config.js";
import { getDefaultData, ensureDataShape } from "./shape.js";
import { hashPassword, verifyPassword, newId, nowText, todayText } from "./crypto.js";

let db;

export function openDb() {
  ensureDirs();
  db = new DatabaseSync(dbPath());
  db.exec(`
    PRAGMA journal_mode = WAL;
    PRAGMA foreign_keys = ON;
    CREATE TABLE IF NOT EXISTS kv (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS auth (
      username TEXT PRIMARY KEY,
      password_hash TEXT NOT NULL,
      is_admin INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS uploads (
      id TEXT PRIMARY KEY,
      kind TEXT NOT NULL,
      filename TEXT NOT NULL,
      mime TEXT,
      path TEXT NOT NULL,
      created_at TEXT NOT NULL
    );
  `);
  seedIfNeeded();
  return db;
}

function getKv(key) {
  const row = db.prepare("SELECT value FROM kv WHERE key = ?").get(key);
  return row ? row.value : null;
}

function setKv(key, value) {
  db.prepare("INSERT INTO kv(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value").run(key, value);
}

function seedIfNeeded() {
  const existing = getKv("app_data");
  if (!existing) {
    const data = ensureDataShape(getDefaultData());
    setKv("app_data", JSON.stringify(data));
    setKv("updated_at", new Date().toISOString());
  }
  const admin = db.prepare("SELECT username FROM auth WHERE username = ?").get(config.adminUser);
  if (!admin) {
    db.prepare("INSERT INTO auth(username, password_hash, is_admin) VALUES(?, ?, 1)").run(
      config.adminUser,
      hashPassword(config.adminPassword)
    );
  }
}

export function getStore() {
  const raw = getKv("app_data");
  const updatedAt = getKv("updated_at") || "";
  const data = ensureDataShape(raw ? JSON.parse(raw) : getDefaultData());
  return { data, updatedAt };
}

export function saveStore(data, expectedUpdatedAt) {
  const current = getKv("updated_at") || "";
  if (expectedUpdatedAt && current && expectedUpdatedAt !== current) {
    const err = new Error("conflict");
    err.code = "CONFLICT";
    err.updatedAt = current;
    throw err;
  }
  const next = ensureDataShape(data);
  const updatedAt = new Date().toISOString();
  db.exec("BEGIN");
  try {
    setKv("app_data", JSON.stringify(next));
    setKv("updated_at", updatedAt);
    db.exec("COMMIT");
  } catch (err) {
    try { db.exec("ROLLBACK"); } catch {}
    throw err;
  }
  return { data: next, updatedAt };
}

export function mutateStore(mutator) {
  const { data, updatedAt } = getStore();
  mutator(data);
  return saveStore(data, updatedAt);
}

export function logActivity(data, action, detail, ref = "", user = "") {
  data.activityLogs = data.activityLogs || [];
  data.activityLogs.unshift({
    id: newId(),
    at: nowText(),
    user,
    action,
    detail,
    ref
  });
  data.activityLogs = data.activityLogs.slice(0, 800);
}

export function upsertMember(data, { name, phone, addr = "", email = "" }) {
  if (!name || !phone) return;
  data.members = data.members || [];
  const found = data.members.find((m) => String(m.phone) === String(phone) || (m.name === name && m.phone === phone));
  if (found) {
    found.name = name;
    found.phone = phone;
    if (addr) found.addr = addr;
    if (email) found.email = email;
    found.updatedAt = nowText();
    return found;
  }
  const member = {
    id: newId(),
    name,
    phone,
    addr,
    email,
    createdAt: nowText(),
    updatedAt: nowText()
  };
  data.members.unshift(member);
  return member;
}

export function findEmployee(data, name) {
  return (data.employees || []).find((e) => String(e.name || "").trim() === String(name || "").trim());
}

export function authenticate(user, password) {
  const username = String(user || "").trim();
  const pwd = String(password || "");
  if (!username || !pwd) return null;

  const adminRow = db.prepare("SELECT username, password_hash, is_admin FROM auth WHERE username = ?").get(username);
  if (adminRow && verifyPassword(pwd, adminRow.password_hash)) {
    return { user: adminRow.username, isAdmin: true };
  }

  const { data } = getStore();
  const emp = findEmployee(data, username);
  if (emp && verifyPassword(pwd, emp.pwd)) {
    return { user: emp.name, isAdmin: false };
  }
  return null;
}

export function changeAdminPassword(oldPassword, newPassword) {
  if (String(newPassword || "").length < 8) {
    const err = new Error("新密碼至少需要 8 個字元");
    err.code = "BAD_REQUEST";
    throw err;
  }
  const row = db.prepare("SELECT password_hash FROM auth WHERE username = ?").get(config.adminUser);
  if (!row || !verifyPassword(oldPassword, row.password_hash)) {
    const err = new Error("舊密碼不正確");
    err.code = "BAD_REQUEST";
    throw err;
  }
  db.prepare("UPDATE auth SET password_hash = ? WHERE username = ?").run(hashPassword(newPassword), config.adminUser);
  const saved = mutateStore((data) => {
    data.adminPwd = "";
  });
  return saved.updatedAt;
}

export function recordUpload({ kind, filename, mime, relativePath }) {
  const id = String(newId());
  db.prepare("INSERT INTO uploads(id, kind, filename, mime, path, created_at) VALUES(?, ?, ?, ?, ?, ?)").run(
    id,
    kind,
    filename,
    mime || "application/octet-stream",
    relativePath,
    todayText()
  );
  return { id, url: `/uploads/${encodeURIComponent(filename)}`, name: filename };
}

export function getDb() {
  return db;
}
