#!/usr/bin/env node
"use strict";

/**
 * Absorb leftover BATCH-* HaoHong shells whose tracking numbers already live
 * on a signed HAOHONG-* batch. Copy compare-table PHP + hourly sync that also
 * writes haohong-logistics-snapshot.json on the regular hourly path.
 * Does not delete freight history. Does not stamp all admin HTML.
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PAGES = [
  path.join(__dirname, "..", "lingzanzan-pages"),
  path.join(__dirname, "lingzanzan-pages"),
].find((dir) => fs.existsSync(path.join(dir, "haohong-logistics-api.php")));
const FREIGHT = path.join(ROOT, "data", "freight-forwarding-tracking.json");
const PHP_SRC = PAGES ? path.join(PAGES, "haohong-logistics-api.php") : "";
const HOURLY_SRC = path.join(__dirname, "haohong-hourly-sync.js");

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  if (fs.existsSync(file)) fs.copyFileSync(file, dest);
  return dest;
}

function atomicWriteJson(file, data) {
  const tmp = file + ".tmp-" + Date.now();
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2) + "\n");
  try {
    fs.copyFileSync(tmp, file);
    try { fs.unlinkSync(tmp); } catch (e) {}
  } catch (e) {
    fs.renameSync(tmp, file);
  }
}

if (!fs.existsSync(path.join(ROOT, "assets", "admin.js"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

function copyIfDifferent(src, dest, label) {
  if (!src || !fs.existsSync(src)) return false;
  if (path.resolve(src) === path.resolve(dest)) {
    console.log("already in place", label);
    return true;
  }
  backup(dest, "leftover-absorb");
  fs.copyFileSync(src, dest);
  console.log("copied", label, fs.statSync(dest).size);
  return true;
}

const phpDest = path.join(ROOT, "haohong-logistics-api.php");
const phpCandidates = [
  PHP_SRC,
  path.join(__dirname, "haohong-logistics-api.php"),
  phpDest,
].filter(Boolean);
if (!phpCandidates.some((src) => copyIfDifferent(src, phpDest, "haohong-logistics-api.php"))) {
  throw new Error("missing haohong-logistics-api.php source");
}
const php = fs.readFileSync(phpDest, "utf8");
if (php.indexOf("hh_table_tracks_already_signed") === -1) throw new Error("php absorb helper missing");
if (php.indexOf("hh_table_batch_track_keys") === -1) throw new Error("php track keys helper missing");

const hourlyDest = path.join(ROOT, "scripts", "haohong-hourly-sync.js");
if (!copyIfDifferent(HOURLY_SRC, hourlyDest, "scripts/haohong-hourly-sync.js") && !fs.existsSync(hourlyDest)) {
  throw new Error("missing haohong-hourly-sync.js source");
}

delete require.cache[require.resolve(hourlyDest)];
const { absorbLeftoverHaohongSheets } = require(hourlyDest);

const freight = JSON.parse(fs.readFileSync(FREIGHT, "utf8"));
backup(FREIGHT, "leftover-absorb");
const absorbed = absorbLeftoverHaohongSheets(freight);
if (absorbed.absorbedCount) {
  freight.revision = Number(freight.revision || 0) + 1;
  freight.updatedAt = new Date().toISOString();
  freight.importMeta = Object.assign({}, freight.importMeta || {}, {
    haohongAbsorbedLeftoverCount: absorbed.absorbedCount,
    haohongAbsorbedLeftoverAt: freight.updatedAt,
  });
  atomicWriteJson(FREIGHT, freight);
}
const leftover = (freight.batches || []).find((row) => String(row.id || "") === "BATCH-20260721-182769");
if (leftover && leftover.status !== "已簽收完成") {
  throw new Error("leftover BATCH-20260721-182769 still unsigned");
}

console.log(JSON.stringify({
  ok: true,
  absorbedCount: absorbed.absorbedCount,
  absorbed: absorbed.absorbed,
  leftoverStatus: leftover && leftover.status || "",
  leftoverInto: leftover && leftover.absorbedInto || "",
}, null, 2));
