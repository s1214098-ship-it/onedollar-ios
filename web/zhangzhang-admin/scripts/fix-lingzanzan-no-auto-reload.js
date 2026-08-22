#!/usr/bin/env node
"use strict";

/**
 * Stop open admin tabs from flash-reloading when JS/CSS mtime changes.
 * Copy asset-boot.php / asset-version.php / asset-version-lib.php.
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const PHP_FILES = ["asset-version-lib.php", "asset-version.php", "asset-boot.php"];
const HERE = (function () {
  const candidates = [
    process.env.LZ_ASSET_PHP_DIR,
    path.join(__dirname, "..", "lingzanzan-pages"),
    path.join(__dirname, "lingzanzan-pages"),
    __dirname,
  ].filter(Boolean);
  for (let i = 0; i < candidates.length; i += 1) {
    if (fs.existsSync(path.join(candidates[i], "asset-boot.php"))) return candidates[i];
  }
  return candidates[1];
})();

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

if (!fs.existsSync(path.join(ROOT, "assets", "admin.js"))) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

PHP_FILES.forEach(function (name) {
  const src = path.join(HERE, name);
  const dest = path.join(ROOT, name);
  if (!fs.existsSync(src)) throw new Error("missing " + src);
  if (fs.existsSync(dest)) backup(dest, "no-auto-reload");
  fs.copyFileSync(src, dest);
  console.log("copied", name);
});

const boot = fs.readFileSync(path.join(ROOT, "asset-boot.php"), "utf8");
if (boot.indexOf("location.reload()") !== -1) throw new Error("boot still auto-reloads");
const version = fs.readFileSync(path.join(ROOT, "asset-version.php"), "utf8");
if (version.indexOf("unset($manifest['v'])") === -1) throw new Error("poll still sends v");
console.log("LINGZANZAN no auto-reload ok");
