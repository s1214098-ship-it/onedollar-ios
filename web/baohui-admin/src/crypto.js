import crypto from "node:crypto";

const SCRYPT_PREFIX = "scrypt$";

export function hashPassword(plain) {
  const salt = crypto.randomBytes(16).toString("hex");
  const hash = crypto.scryptSync(String(plain), salt, 32).toString("hex");
  return `${SCRYPT_PREFIX}${salt}$${hash}`;
}

export function verifyPassword(plain, stored) {
  const value = String(stored || "");
  const candidate = String(plain || "");
  if (!value) return false;
  if (value.startsWith(SCRYPT_PREFIX)) {
    const parts = value.split("$");
    const salt = parts[1];
    const expected = parts[2];
    if (!salt || !expected) return false;
    const actual = crypto.scryptSync(candidate, salt, 32).toString("hex");
    try {
      return crypto.timingSafeEqual(Buffer.from(actual, "hex"), Buffer.from(expected, "hex"));
    } catch {
      return false;
    }
  }
  const a = Buffer.from(candidate);
  const b = Buffer.from(value);
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}

export function signCookie(payload, secret) {
  const body = Buffer.from(JSON.stringify(payload)).toString("base64url");
  const sig = crypto.createHmac("sha256", secret).update(body).digest("base64url");
  return `${body}.${sig}`;
}

export function readCookie(raw, secret) {
  if (!raw || !raw.includes(".")) return null;
  const [body, sig] = raw.split(".");
  const expected = crypto.createHmac("sha256", secret).update(body).digest("base64url");
  const a = Buffer.from(sig);
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  try {
    const payload = JSON.parse(Buffer.from(body, "base64url").toString("utf8"));
    if (!payload || payload.exp < Date.now()) return null;
    return payload;
  } catch {
    return null;
  }
}

export function parseCookies(header) {
  const out = {};
  String(header || "")
    .split(";")
    .forEach((part) => {
      const idx = part.indexOf("=");
      if (idx < 0) return;
      const key = part.slice(0, idx).trim();
      const value = part.slice(idx + 1).trim();
      if (key) out[key] = decodeURIComponent(value);
    });
  return out;
}

export function newId() {
  return Date.now() + Math.floor(Math.random() * 1000);
}

export function nowText() {
  return new Date().toLocaleString("zh-TW", { hour12: false, timeZone: "Asia/Taipei" });
}

export function todayText() {
  const d = new Date();
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Taipei", year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts(d);
  const get = (type) => parts.find((p) => p.type === type)?.value;
  return `${get("year")}-${get("month")}-${get("day")}`;
}

export function monthKey(date = new Date()) {
  return todayText().slice(0, 7);
}
