#!/usr/bin/env node
"use strict";

/**
 * Live admin-state.json was truncated at 2026-08-19 20:08 and no longer
 * parses. Login reads that file, so manager/staff always fail.
 * Restore the last complete copy from 19:49 (after Aya/KTJ apply).
 */

const fs = require("fs");
const path = require("path");
const http = require("http");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const DATA = path.join(ROOT, "data");
const LIVE = path.join(DATA, "admin-state.json");
const SRC = path.join(DATA, "audit", "admin-state.json.aya-ktj-nodata-1787169124354");
const AUDIT = path.join(DATA, "audit");
const CACHES = [
  "admin-state-auth-cache.json",
  "admin-state-light-cache.json",
  "admin-state-members-cache.json"
];

function parseFile(file) {
  return JSON.parse(fs.readFileSync(file, "utf8").replace(/\0+$/g, "").trimEnd());
}

function staffSummary(data) {
  const staff = Array.isArray(data.staff) ? data.staff : [];
  return {
    adminAccount: data.adminAccount || "",
    hasHash: !!data.adminPasswordHash,
    staff: staff.length,
    staffNames: staff.map((s) => String((s && (s.name || s.account)) || "")),
    orders: Array.isArray(data.orders) ? data.orders.length : -1
  };
}

function get(urlPath) {
  return new Promise((resolve, reject) => {
    http.get("http://127.0.0.1:8082/" + urlPath.replace(/^\//, ""), (res) => {
      const chunks = [];
      res.on("data", (c) => chunks.push(c));
      res.on("end", () => resolve({ status: res.statusCode, buf: Buffer.concat(chunks) }));
    }).on("error", reject);
  });
}

function post(urlPath, body) {
  return new Promise((resolve, reject) => {
    const data = Buffer.from(JSON.stringify(body));
    const req = http.request(
      {
        hostname: "127.0.0.1",
        port: 8082,
        path: "/" + urlPath.replace(/^\//, ""),
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Content-Length": data.length
        }
      },
      (res) => {
        const chunks = [];
        res.on("data", (c) => chunks.push(c));
        res.on("end", () => resolve({ status: res.statusCode, buf: Buffer.concat(chunks) }));
      }
    );
    req.on("error", reject);
    req.write(data);
    req.end();
  });
}

if (!fs.existsSync(SRC)) throw new Error("missing backup " + SRC);
const backupData = parseFile(SRC);
const summary = staffSummary(backupData);
if (!summary.hasHash) throw new Error("backup missing admin password hash");
if (summary.staff < 1) throw new Error("backup has no staff");
console.log("backup ok", summary);

fs.mkdirSync(AUDIT, { recursive: true });
const corruptDest = path.join(
  AUDIT,
  "admin-state.json.truncated-login-" + Date.now()
);
fs.copyFileSync(LIVE, corruptDest);
console.log("corrupt saved", corruptDest, fs.statSync(corruptDest).size);

fs.copyFileSync(SRC, LIVE);
const restored = parseFile(LIVE);
console.log("restored", staffSummary(restored), "bytes", fs.statSync(LIVE).size);

CACHES.forEach((name) => {
  const file = path.join(DATA, name);
  if (fs.existsSync(file)) {
    fs.unlinkSync(file);
    console.log("cleared cache", name);
  }
});

(async () => {
  const auth = await get("admin-state-api-v3.php?auth=1&_=" + Date.now());
  const payload = JSON.parse(auth.buf.toString("utf8"));
  const staffN = Array.isArray(payload && payload.state && payload.state.staff)
    ? payload.state.staff.length
    : -1;
  console.log("auth api", {
    status: auth.status,
    ok: payload.ok,
    sessionValid: payload.sessionValid,
    adminAccount: payload.state && payload.state.adminAccount,
    staff: staffN
  });
  if (auth.status !== 200 || payload.ok !== true || staffN < 1) {
    throw new Error("auth=1 still empty after restore");
  }
  const login = await post("admin-state-api-v3.php?login=1&_=" + Date.now(), {
    account: "admin",
    password: "definitely-not-the-password-" + Date.now(),
    roleHint: "admin"
  });
  const loginPayload = JSON.parse(login.buf.toString("utf8") || "{}");
  console.log("wrong-password probe", {
    status: login.status,
    ok: loginPayload.ok,
    error: loginPayload.error || ""
  });
  if (login.status !== 401) throw new Error("login endpoint did not reject wrong password");
  console.log("LINGZANZAN admin-state login restore ok");
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
