"use strict";

/**
 * Pull the published WEB copy of 張張 / 一元競標 data.
 * 1) Try company VPN 100.92.117.104 (Tailscale / QNAP) — usually RST from Cursor cloud.
 * 2) Fall back to https://baohui.paohui.org/one-dollar-auction/ which is the IIS copy of WEB.
 *
 * Usage: node scripts/pull-live-web.js
 *        node scripts/pull-live-web.js --all   # also products + members (large, gitignored)
 */

const fs = require("fs");
const path = require("path");
const http = require("http");
const https = require("https");

const ROOT = path.join(__dirname, "..");
const OUT = path.join(ROOT, "data", "live");
const ALL = process.argv.includes("--all");

const FILES = [
  "settings.json",
  "warehouses.json",
  "suppliers.json",
  "schedules.json"
];
if (ALL) FILES.push("products.json", "members.json");

const SOURCES = [
  "http://100.92.117.104/one-dollar-auction/data/",
  "http://100.92.117.104/WEB/one-dollar-auction/data/",
  "http://100.92.117.104/Web/one-dollar-auction/data/",
  "https://baohui.paohui.org/one-dollar-auction/data/"
];

function get(url) {
  return new Promise((resolve, reject) => {
    const lib = url.startsWith("https") ? https : http;
    const req = lib.get(url, { timeout: 12000, headers: { "User-Agent": "zhangzhang-admin-sync" } }, (res) => {
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        return get(new URL(res.headers.location, url).href).then(resolve, reject);
      }
      const chunks = [];
      res.on("data", (c) => chunks.push(c));
      res.on("end", () => {
        const buf = Buffer.concat(chunks);
        if (res.statusCode !== 200) {
          reject(new Error(`${res.statusCode} ${url}`));
          return;
        }
        resolve(buf);
      });
    });
    req.on("timeout", () => req.destroy(new Error("timeout " + url)));
    req.on("error", reject);
  });
}

async function pullFile(name) {
  let lastErr = null;
  for (const base of SOURCES) {
    const url = base + name;
    try {
      const buf = await get(url);
      JSON.parse(buf.toString("utf8"));
      return { url, buf };
    } catch (error) {
      lastErr = error;
    }
  }
  throw lastErr || new Error("no source for " + name);
}

async function main() {
  fs.mkdirSync(OUT, { recursive: true });
  const report = [];
  for (const name of FILES) {
    try {
      const { url, buf } = await pullFile(name);
      fs.writeFileSync(path.join(OUT, name), buf);
      report.push({ name, ok: true, bytes: buf.length, from: url });
      console.log(`OK ${name} ${buf.length} bytes from ${url}`);
    } catch (error) {
      report.push({ name, ok: false, error: String(error.message || error) });
      console.error(`FAIL ${name}: ${error.message || error}`);
    }
  }
  fs.writeFileSync(path.join(OUT, "pull-report.json"), JSON.stringify({ at: new Date().toISOString(), report }, null, 2));
  if (!report.some((r) => r.ok)) process.exitCode = 1;
}

main();
