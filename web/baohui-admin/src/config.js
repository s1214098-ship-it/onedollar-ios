import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const here = path.dirname(fileURLToPath(import.meta.url));
export const ROOT = path.resolve(here, "..");
export const PUBLIC_DIR = path.join(ROOT, "public");

function env(name, fallback = "") {
  const value = process.env[name];
  return value == null || value === "" ? fallback : value;
}

export const config = {
  host: env("HOST", "0.0.0.0"),
  port: Number(env("PORT", "8787")) || 8787,
  adminUser: env("BAOHUI_ADMIN_USER", "admin"),
  adminPassword: env("BAOHUI_ADMIN_PASSWORD", "ChangeMe-2026!"),
  sessionSecret: env("BAOHUI_SESSION_SECRET", "baohui-dev-secret-change-me"),
  dataDir: path.resolve(ROOT, env("BAOHUI_DATA_DIR", "data")),
  companyEmail: env("BAOHUI_COMPANY_EMAIL", "s1214098@gmail.com"),
  lineNotifyToken: env("BAOHUI_LINE_NOTIFY_TOKEN", ""),
  cookieName: "BAOHUI_ADMIN",
  sessionHours: 24
};

export const company = {
  name: "寶輝科技有限公司",
  shortName: "寶輝科技",
  en: "PAO HUI Technology Co. Ltd.",
  taxId: "23365425",
  phone: "03-9773280",
  fax: "03-9773669",
  line: "s1214098",
  wechat: "a84118326",
  address: "宜蘭縣頭城鎮竹安里頭濱路一段425號",
  dongguan: {
    name: "東莞市寶輝電腦科技有限公司",
    phone: "18822974478",
    address: "東莞市清溪鎮凰文路東三巷一號"
  }
};

export function ensureDirs() {
  fs.mkdirSync(config.dataDir, { recursive: true });
  fs.mkdirSync(path.join(config.dataDir, "uploads"), { recursive: true });
  fs.mkdirSync(path.join(config.dataDir, "quotes"), { recursive: true });
}

export const dbPath = () => path.join(config.dataDir, "baohui.sqlite");
