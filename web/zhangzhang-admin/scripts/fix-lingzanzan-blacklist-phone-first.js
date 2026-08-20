#!/usr/bin/env node
"use strict";

/**
 * 出貨核對把 YUNI(CINTA MATI ATAU MATI KARNA)／0983876144
 * 誤判成黑名單 CINTA(Cinta remaja titok)／0917285294。
 * 原因：姓名 token「CINTA」重疊就算命中。會員本人沒有在黑名單。
 *
 * 有電話就只比電話；沒電話才比姓名，且外層名字（括號前）要相同。
 * 一併清掉「姓名相同」但電話不在黑名單的訂單／預購標記。
 *
 * Cache-bust: member-risk-v2.js / member-risk-v2.css ?v=20260819-blacklist-phone-1
 */

const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const RISK_JS = path.join(ROOT, "assets", "member-risk-v2.js");
const RISK_CSS = path.join(ROOT, "assets", "member-risk-v2.css");
const RISK_LIB = path.join(ROOT, "member-risk-lib.php");
const NAV_JS = path.join(ROOT, "assets", "admin-navigation.js");
const DATA = path.join(ROOT, "data");
const STAMP = "20260819-blacklist-phone-1";
const JS_MARKER = "function primaryName(value) {";
const PHP_MARKER = "function member_risk_primary_name(";
const PHP_BIN = process.env.LZ_PHP || "C:\\PHP82\\php.exe";

function backup(file, tag) {
  const dir = path.join(ROOT, "data", "audit");
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + "." + tag + "-" + new Date().toISOString().replace(/[:.]/g, "-")
  );
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log("already:", label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, "\r\n");
    to = newStr.replace(/\n/g, "\r\n");
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/member-risk-v2\.(js|css)|admin-navigation\.js/.test(html)) continue;
    const next = html
      .replace(/member-risk-v2\.css(?:\?v=[^"']+)?/g, "member-risk-v2.css?v=" + STAMP)
      .replace(/member-risk-v2\.js(?:\?v=[^"']+)?/g, "member-risk-v2.js?v=" + STAMP)
      .replace(/admin-navigation\.js(?:\?v=[^"']+)?/g, "admin-navigation.js?v=" + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

const NAME_PARTS_OLD = `  function nameParts(value) {
    var raw = String(value || '').trim();
    var parts = raw.split(/[\\s\\(\\)\\[\\]\\/|,，、]+/);
    parts.push(nameKey(raw));
    var out = {};
    parts.forEach(function (part) {
      part = nameKey(part);
      if (part.length >= 3) out[part] = true;
    });
    return Object.keys(out);
  }`;

const NAME_PARTS_NEW = `  function nameParts(value) {
    var raw = String(value || '').trim();
    var parts = raw.split(/[\\s\\(\\)\\[\\]\\/|,，、]+/);
    parts.push(nameKey(raw));
    var out = {};
    parts.forEach(function (part) {
      part = nameKey(part);
      if (part.length >= 3) out[part] = true;
    });
    return Object.keys(out);
  }

  function primaryName(value) {
    var raw = String(value || '').trim();
    var part = raw.split(/[\\s\\(\\)\\[\\]\\/|,，、]+/)[0] || '';
    return nameKey(part);
  }`;

const NAME_HIT_OLD = `  function nameHit(left, right) {
    var a = nameKey(left);
    var b = nameKey(right);
    if (!a || !b) return false;
    if (a === b) return true;
    var leftParts = nameParts(left);
    var rightParts = nameParts(right);
    return leftParts.some(function (x) { return rightParts.indexOf(x) !== -1; });
  }`;

const NAME_HIT_NEW = `  function nameHit(left, right) {
    var a = nameKey(left);
    var b = nameKey(right);
    if (!a || !b) return false;
    if (a === b) return true;
    var pa = primaryName(left);
    var pb = primaryName(right);
    if (!pa || !pb || pa !== pb) return false;
    var leftParts = nameParts(left);
    var rightParts = nameParts(right);
    return leftParts.some(function (x) { return rightParts.indexOf(x) !== -1; });
  }`;

const RISK_BY_OLD = `  function riskByCustomer(phoneValue, nameValue) {
    return rows.find(function (row) {
      return phoneHit(phoneValue, row && row.phone) || nameHit(nameValue, row && row.name);
    }) || null;
  }`;

const RISK_BY_NEW = `  function riskByCustomer(phoneValue, nameValue) {
    var phoneValueKey = phone(phoneValue);
    if (phoneValueKey) {
      return rows.find(function (row) {
        return phoneHit(phoneValue, row && row.phone);
      }) || null;
    }
    return rows.find(function (row) {
      return nameHit(nameValue, row && row.name);
    }) || null;
  }`;

const RISK_TEXT_OLD = `  function riskFromText(value) {
    var textValue = String(value || '');
    var digits = phone(textValue);
    return rows.find(function (row) {
      return phoneHit(digits, row && row.phone) || nameHit(textValue, row && row.name) || (
        nameKey(row && row.name).length >= 3 && nameKey(textValue).indexOf(nameKey(row && row.name)) !== -1
      );
    }) || null;
  }`;

const RISK_TEXT_NEW = `  function extractMobiles(value) {
    var found = [];
    String(value || '').replace(/09\\d{8}/g, function (m) {
      if (found.indexOf(m) === -1) found.push(m);
      return m;
    });
    return found;
  }

  function riskFromText(value) {
    var textValue = String(value || '');
    var mobiles = extractMobiles(textValue);
    if (mobiles.length) {
      return rows.find(function (row) {
        return mobiles.some(function (m) { return phoneHit(m, row && row.phone); });
      }) || null;
    }
    return rows.find(function (row) {
      return nameHit(textValue, row && row.name);
    }) || null;
  }`;

const PHP_NAME_HIT_OLD = `function member_risk_name_hit(string $left, string $right): bool {
    $a = member_risk_name_key($left);
    $b = member_risk_name_key($right);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $leftParts = member_risk_name_parts($left);
    $rightParts = member_risk_name_parts($right);
    foreach ($leftParts as $x) {
        foreach ($rightParts as $y) {
            if ($x === $y) return true;
        }
    }
    return false;
}`;

const PHP_NAME_HIT_NEW = `function member_risk_primary_name(string $name): string {
    $parts = preg_split('/[\\s\\(\\)\\[\\]\\/|,，、]+/u', trim($name)) ?: [];
    return member_risk_name_key((string)($parts[0] ?? ''));
}

function member_risk_name_hit(string $left, string $right): bool {
    $a = member_risk_name_key($left);
    $b = member_risk_name_key($right);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    $pa = member_risk_primary_name($left);
    $pb = member_risk_primary_name($right);
    if ($pa === '' || $pb === '' || $pa !== $pb) return false;
    $leftParts = member_risk_name_parts($left);
    $rightParts = member_risk_name_parts($right);
    foreach ($leftParts as $x) {
        foreach ($rightParts as $y) {
            if ($x === $y) return true;
        }
    }
    return false;
}`;

const PHP_FIND_OLD = `function member_risk_find(string $baseDir, string $phone, string $name): ?array {
    $phone = trim($phone);
    $name = trim($name);
    if ($phone === '' && $name === '') return null;
    foreach (member_risk_rows($baseDir) as $row) {
        $lockedPhone = (string)($row['phone'] ?? '');
        $lockedName = (string)($row['name'] ?? '');
        $phoneHit = $phone !== '' && member_risk_phone_hit($phone, $lockedPhone);
        $nameHit = $name !== '' && member_risk_name_hit($name, $lockedName);
        if (!$phoneHit && !$nameHit) continue;
        $matchType = $phoneHit && $nameHit ? '電話與姓名相同' : ($phoneHit ? '電話相同' : '姓名相同');`;

const PHP_FIND_NEW = `function member_risk_find(string $baseDir, string $phone, string $name): ?array {
    $phone = trim($phone);
    $name = trim($name);
    if ($phone === '' && $name === '') return null;
    foreach (member_risk_rows($baseDir) as $row) {
        $lockedPhone = (string)($row['phone'] ?? '');
        $lockedName = (string)($row['name'] ?? '');
        $phoneHit = $phone !== '' && member_risk_phone_hit($phone, $lockedPhone);
        $nameHit = $name !== '' && member_risk_name_hit($name, $lockedName);
        if ($phone !== '') {
            if (!$phoneHit) continue;
        } elseif (!$nameHit) {
            continue;
        }
        $matchType = $phoneHit && $nameHit ? '電話與姓名相同' : ($phoneHit ? '電話相同' : '姓名相同');`;

function patchJs() {
  console.log("backup js", backup(RISK_JS, "blacklist-phone"));
  let src = fs.readFileSync(RISK_JS, "utf8");
  if (src.includes(JS_MARKER)) {
    console.log("js already patched");
    return;
  }
  src = replaceOnce(src, NAME_PARTS_OLD, NAME_PARTS_NEW, "js primaryName");
  src = replaceOnce(src, NAME_HIT_OLD, NAME_HIT_NEW, "js nameHit");
  src = replaceOnce(src, RISK_BY_OLD, RISK_BY_NEW, "js riskByCustomer");
  src = replaceOnce(src, RISK_TEXT_OLD, RISK_TEXT_NEW, "js riskFromText");
  fs.writeFileSync(RISK_JS, src);
  console.log("js written", src.length);
}

function patchNav() {
  console.log("backup nav", backup(NAV_JS, "blacklist-phone"));
  let src = fs.readFileSync(NAV_JS, "utf8");
  const next = src
    .replace(/member-risk-v2\.css\?v=[^"']+/g, "member-risk-v2.css?v=" + STAMP)
    .replace(/member-risk-v2\.js\?v=[^"']+/g, "member-risk-v2.js?v=" + STAMP);
  if (next === src) {
    if (src.indexOf("member-risk-v2.js?v=" + STAMP) !== -1) {
      console.log("nav already stamped");
      return;
    }
    throw new Error("missing nav member-risk stamp");
  }
  fs.writeFileSync(NAV_JS, next);
  console.log("nav stamped");
}

function patchPhp() {
  console.log("backup php", backup(RISK_LIB, "blacklist-phone"));
  let src = fs.readFileSync(RISK_LIB, "utf8");
  if (src.includes(PHP_MARKER)) {
    console.log("php already patched");
    return;
  }
  src = replaceOnce(src, PHP_NAME_HIT_OLD, PHP_NAME_HIT_NEW, "php name_hit");
  src = replaceOnce(src, PHP_FIND_OLD, PHP_FIND_NEW, "php find phone-first");
  fs.writeFileSync(RISK_LIB, src);
  console.log("php written", src.length);
}

function phoneKey(value) {
  return String(value || "").replace(/\D+/g, "");
}

function phoneOf(row) {
  const c = (row && row.customer) || {};
  return phoneKey(c.phone || row.phone || row.customerPhone || "");
}

function last9(value) {
  const d = phoneKey(value);
  return d.length >= 9 ? d.slice(-9) : d;
}

function atomicWrite(file, data) {
  const json = JSON.stringify(data, null, 2) + "\n";
  const tmp = file + ".tmp-blacklist-phone-" + process.pid + "-" + Date.now();
  fs.writeFileSync(tmp, json);
  try {
    fs.renameSync(tmp, file);
  } catch (e) {
    try {
      fs.copyFileSync(tmp, file);
    } finally {
      try {
        fs.unlinkSync(tmp);
      } catch (_) {}
    }
  }
}

function listOf(wrap, key) {
  if (Array.isArray(wrap)) return wrap;
  if (wrap && Array.isArray(wrap[key])) return wrap[key];
  if (wrap && Array.isArray(wrap.rows)) return wrap.rows;
  if (wrap && Array.isArray(wrap.items)) return wrap.items;
  return [];
}

function clearFalseStamp(row, listedTails) {
  if (!row || !row.blacklistHit) return false;
  if (String(row.blacklistMatchType || "") !== "姓名相同") return false;
  const p = phoneOf(row);
  if (!p) return false;
  if (listedTails[last9(p)]) return false;
  row.blacklistHit = false;
  row.blacklistMatchType = "";
  row.blacklistReason = "";
  row.blacklistConfirmedAt = "";
  row.blacklistAskSales = false;
  row.blacklistConfirmedBy = "";
  row.updatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, "+00:00");
  return true;
}

function clearData() {
  const riskFile = path.join(DATA, "member-risk.json");
  const ordersFile = path.join(DATA, "orders.json");
  const inquiriesFile = path.join(DATA, "inquiries.json");
  const risk = JSON.parse(fs.readFileSync(riskFile, "utf8"));
  const listedTails = {};
  (Array.isArray(risk.rows) ? risk.rows : []).forEach((row) => {
    const tail = last9(row && row.phone);
    if (tail) listedTails[tail] = true;
  });

  function sweep(file, key) {
    console.log("backup data", backup(file, "false-name-blacklist"));
    const wrap = JSON.parse(fs.readFileSync(file, "utf8").replace(/\0+$/g, "").trimEnd());
    const list = listOf(wrap, key);
    const hits = [];
    list.forEach((row) => {
      if (!clearFalseStamp(row, listedTails)) return;
      hits.push({
        id: row.id || row.orderId || row.inquiryId,
        name: (row.customer && row.customer.name) || row.name,
        phone: phoneOf(row),
      });
    });
    if (hits.length) atomicWrite(file, wrap);
    console.log(path.basename(file), "cleared", hits.length, JSON.stringify(hits));
  }

  sweep(ordersFile, "orders");
  sweep(inquiriesFile, "inquiries");
}

function phpCheck() {
  if (!fs.existsSync(PHP_BIN)) {
    console.log("skip php check, no binary");
    return;
  }
  const code =
    "require '" +
    RISK_LIB.replace(/\\/g, "/") +
    "'; $base='" +
    ROOT.replace(/\\/g, "/") +
    "'; echo json_encode([" +
    "member_risk_find($base,'0983876144','YUNI(CINTA MATI ATAU MATI KARNA)')," +
    "member_risk_find($base,'0917285294','YUNI(CINTA MATI ATAU MATI KARNA)')," +
    "member_risk_find($base,'','YUNI(CINTA MATI ATAU MATI KARNA)')," +
    "member_risk_find($base,'','CINTA(Cinta remaja titok)')," +
    "member_risk_find($base,'0917285294','CINTA(Cinta remaja titok)')" +
    "], JSON_UNESCAPED_UNICODE);";
  const out = execFileSync(PHP_BIN, ["-r", code], { encoding: "utf8" });
  console.log("php check", out);
}

patchJs();
patchPhp();
patchNav();
stampHtml(ROOT);
clearData();
phpCheck();
console.log("LINGZANZAN blacklist phone-first:", STAMP);
