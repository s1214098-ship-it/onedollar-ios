#!/usr/bin/env node
'use strict';

/**
 * 退貨紀錄 ≠ 黑名單。退貨只顯示提醒（琥珀色），不要套用黑名單紅框。
 * 黑名單比對：有電話只比電話；沒電話只比完整姓名，不再用 token 部分重疊（避免 Ara X 誤中 Ara Y）。
 * FIFO 配送中卡片補 data-customer-phone，避免整卡文字掃描。
 *
 * Cache-bust: member-risk-v2.js / member-risk-v2.css ?v=20260820-return-not-blacklist-1
 *             admin.js ?v=20260820-return-not-blacklist-1
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const RISK_JS = path.join(ROOT, 'assets', 'member-risk-v2.js');
const RISK_CSS = path.join(ROOT, 'assets', 'member-risk-v2.css');
const RISK_LIB = path.join(ROOT, 'member-risk-lib.php');
const ADMIN_JS = path.join(ROOT, 'assets', 'admin.js');
const NAV_JS = path.join(ROOT, 'assets', 'admin-navigation.js');
const STAMP = '20260820-return-not-blacklist-1';
const JS_MARKER = 'member-return-notice';
const PHP_MARKER = 'member_risk_exact_name_hit';

function backup(file, tag) {
  const dir = path.join(ROOT, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(
    dir,
    path.basename(file) + '.' + tag + '-' + new Date().toISOString().replace(/[:.]/g, '-')
  );
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log('already:', label);
    return src;
  }
  let from = oldStr;
  let to = newStr;
  let i = src.indexOf(from);
  if (i < 0) {
    from = oldStr.replace(/\n/g, '\r\n');
    to = newStr.replace(/\n/g, '\r\n');
    i = src.indexOf(from);
  }
  if (i < 0) throw new Error('missing snippet: ' + label);
  if (src.indexOf(from, i + from.length) !== -1) throw new Error('not unique: ' + label);
  console.log('patched:', label);
  return src.slice(0, i) + to + src.slice(i + from.length);
}

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter(function (name) {
    return /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name);
  });
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, 'latin1');
    if (!/member-risk-v2\.(js|css)|admin-navigation\.js|admin\.js/.test(html)) continue;
    const next = html
      .replace(/member-risk-v2\.css(?:\?v=[^"']+)?/g, 'member-risk-v2.css?v=' + STAMP)
      .replace(/member-risk-v2\.js(?:\?v=[^"']+)?/g, 'member-risk-v2.js?v=' + STAMP)
      .replace(/admin-navigation\.js(?:\?v=[^"']+)?/g, 'admin-navigation.js?v=' + STAMP)
      .replace(/admin\.js(?:\?v=[^"']+)?/g, 'admin.js?v=' + STAMP);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, 'latin1'));
    n += 1;
    console.log('stamped', name);
  }
  console.log('html stamped', n);
}

const RETURN_BY_OLD = `  function returnByCustomer(phoneValue, nameValue) {
    var phoneValueKey = phone(phoneValue);
    var nameValueKey = nameKey(nameValue);
    var phoneMatch = phoneValueKey ? returnRows.find(function (row) {
      return rowPhone(row) === phoneValueKey;
    }) : null;
    if (phoneMatch) return phoneMatch;
    if (!nameValueKey) return null;
    return returnRows.find(function (row) {
      return rowName(row) === nameValueKey;
    }) || null;
  }`;

const RETURN_BY_NEW = `  function returnByCustomer(phoneValue, nameValue) {
    var phoneValueKey = phone(phoneValue);
    if (phoneValueKey) {
      return returnRows.find(function (row) {
        return phoneHit(phoneValue, row && row.phone);
      }) || null;
    }
    var nameValueKey = nameKey(nameValue);
    if (!nameValueKey) return null;
    return returnRows.find(function (row) {
      return rowName(row) === nameValueKey;
    }) || null;
  }`;

const RISK_BY_OLD = `  function riskByCustomer(phoneValue, nameValue) {
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

const RISK_BY_NEW = `  function riskByCustomer(phoneValue, nameValue) {
    var phoneValueKey = phone(phoneValue);
    if (phoneValueKey) {
      return rows.find(function (row) {
        return phoneHit(phoneValue, row && row.phone);
      }) || null;
    }
    var nameValueKey = nameKey(nameValue);
    if (!nameValueKey) return null;
    return rows.find(function (row) {
      return rowName(row) === nameValueKey;
    }) || null;
  }`;

const RISK_TEXT_OLD = `    return rows.find(function (row) {
      return nameHit(textValue, row && row.name);
    }) || null;
  }`;

const RISK_TEXT_NEW = `    var normalizedText = nameKey(textValue);
    if (!normalizedText || normalizedText.length > 80) return null;
    return rows.find(function (row) {
      return rowName(row) === normalizedText;
    }) || null;
  }`;

const RETURN_LABEL_OLD = `      '⚠ 本客戶有退貨紀錄｜共退貨 ' + count + ' 次' + latest + '｜出貨前請再次確認，請特別小心',
      '⚠ Pelanggan ini memiliki riwayat retur｜Total ' + count + ' kali retur' + latest + '｜Periksa kembali sebelum pengiriman dan harap berhati-hati'`;

const RETURN_LABEL_NEW = `      'ℹ 本客戶有退貨紀錄｜共退貨 ' + count + ' 次' + latest + '｜退貨不等於黑名單，出貨前請再確認',
      'ℹ Pelanggan ini punya riwayat retur｜Total ' + count + ' kali' + latest + '｜Retur bukan daftar hitam; periksa lagi sebelum kirim'`;

const SET_BADGE_OLD = `    if (!risk && !returned && !activityLabel) {
      target.classList.remove('member-risk-warning');
      target.classList.remove('member-order-activity');
      target.classList.remove('member-order-activity-info');
      target.removeAttribute('data-risk-reason');
      target.removeAttribute('data-return-count');
      target.removeAttribute('data-order-count');
      target.removeAttribute('data-order-today-count');
      if (badge) badge.remove();
      return;
    }
    target.classList.toggle('member-risk-warning', Boolean(risk || returned));
    target.classList.toggle('member-order-activity', Boolean((tone === 'duplicate' || tone === 'merge') && !risk && !returned));
    target.classList.toggle('member-order-activity-info', Boolean(tone === 'info' && !risk && !returned));`;

const SET_BADGE_NEW = `    if (!risk && !returned && !activityLabel) {
      target.classList.remove('member-risk-warning');
      target.classList.remove('member-return-notice');
      target.classList.remove('member-order-activity');
      target.classList.remove('member-order-activity-info');
      target.removeAttribute('data-risk-reason');
      target.removeAttribute('data-return-count');
      target.removeAttribute('data-order-count');
      target.removeAttribute('data-order-today-count');
      if (badge) badge.remove();
      return;
    }
    target.classList.toggle('member-risk-warning', Boolean(risk));
    target.classList.toggle('member-return-notice', Boolean(returned && !risk));
    target.classList.toggle('member-order-activity', Boolean((tone === 'duplicate' || tone === 'merge') && !risk && !returned));
    target.classList.toggle('member-order-activity-info', Boolean(tone === 'info' && !risk && !returned));`;

const BADGE_CLASS_OLD = `    var badgeClass = 'member-risk-badge';
    if (!risk && !returned) {
      badgeClass += ' member-order-activity-badge';
      if (tone === 'info') badgeClass += ' is-info';
      else if (tone === 'merge') badgeClass += ' is-merge';
      else if (tone === 'duplicate') badgeClass += ' is-duplicate';
    }
    badge.className = badgeClass;`;

const BADGE_CLASS_NEW = `    var badgeClass = 'member-risk-badge';
    if (risk) {
      badgeClass += ' is-blacklist';
    } else if (returned) {
      badgeClass += ' member-return-badge';
    } else {
      badgeClass += ' member-order-activity-badge';
      if (tone === 'info') badgeClass += ' is-info';
      else if (tone === 'merge') badgeClass += ' is-merge';
      else if (tone === 'duplicate') badgeClass += ' is-duplicate';
    }
    badge.className = badgeClass;`;

const BANNER_OLD = `    banner.classList.toggle('is-order-activity', Boolean(orderActivity && !risk && !returned));
    banner.classList.toggle('is-ask-sales', Boolean(risk));`;

const BANNER_NEW = `    banner.classList.toggle('is-order-activity', Boolean(orderActivity && !risk && !returned));
    banner.classList.toggle('is-return-notice', Boolean(returned && !risk));
    banner.classList.toggle('is-ask-sales', Boolean(risk));`;

const IDENTITY_OLD = `    if (!identity.phone) {
      var phoneNode = target.querySelector('[data-freight-fifo-phone-preview]');
      var preview = String((phoneNode && (phoneNode.value || phoneNode.textContent)) || '').replace(/\\D/g, '');
      if (/^09\\d{8}$/.test(preview)) identity.phone = preview;
    }
    return identity;
  }`;

const IDENTITY_NEW = `    if (!identity.phone) {
      var phoneNode = target.querySelector('[data-freight-fifo-phone-preview]');
      var preview = String((phoneNode && (phoneNode.value || phoneNode.textContent)) || '').replace(/\\D/g, '');
      if (/^09\\d{8}$/.test(preview)) identity.phone = preview;
    }
    var host = (target.closest && target.closest('[data-customer-phone], [data-customer-name], .freight-fifo-active-order, .freight-fifo-customer-card')) || target;
    if (host && host.getAttribute) {
      if (!identity.phone) identity.phone = String(host.getAttribute('data-customer-phone') || '').trim();
      if (!identity.name) identity.name = String(host.getAttribute('data-customer-name') || '').replace(/黑名單/g, '').trim();
    }
    return identity;
  }`;

const CSS_BLOCK = `
.member-return-notice {
  padding: 10px 13px !important;
  border: 2px solid #7a8fa3 !important;
  border-radius: 12px !important;
  background: #24303a !important;
  color: #eef3f7 !important;
  box-shadow: 0 0 0 3px rgba(122, 143, 163, 0.12);
}
.member-risk-badge.member-return-badge,
.member-risk-phone-banner.is-return-notice {
  background: #4a6173;
  color: #eef3f7 !important;
  border: 1px solid rgba(170, 196, 220, 0.35);
}
.member-risk-badge.is-blacklist {
  background: #f06478;
  color: #190b10 !important;
}
.freight-fifo-active-order[data-customer-phone],
.freight-fifo-customer-card[data-customer-phone] {
  /* identity hook only */
}
`;

const ADMIN_TRANSIT_OLD = `      return '<article class="freight-fifo-active-order is-transit' + (isMerged ? ' is-combined-shipment' : '') + (recentlySaved ? ' is-recently-saved' : '') + (selected ? ' is-bulk-selected' : '') + '" data-freight-order-id="' + escapeHtml(orderId) + '">`;

const ADMIN_TRANSIT_NEW = `      return '<article class="freight-fifo-active-order is-transit' + (isMerged ? ' is-combined-shipment' : '') + (recentlySaved ? ' is-recently-saved' : '') + (selected ? ' is-bulk-selected' : '') + '" data-freight-order-id="' + escapeHtml(orderId) + '" data-customer-phone="' + escapeHtml(customer.phone || order.customerPhone || '') + '" data-customer-name="' + escapeHtml(customer.name || order.customerName || '') + '">`;

const ADMIN_PENDING_OLD = `      return '<article class="freight-fifo-active-order is-pending' + (isMerged ? ' is-combined-shipment' : '') + '">' + mergeCheckHtml + (previewImage ? '<button type="button" class="freight-fifo-active-photo" data-order-photo="' + escapeHtml(previewImage) + '"><img src="' + escapeHtml(previewImage) + '" alt="客戶購買圖片" loading="lazy" decoding="async"><span>先看圖片</span></button>' : '<div class="freight-fifo-active-photo is-empty"><span>無圖片</span></div>') + '<div class="freight-fifo-active-copy"><b>' + escapeHtml(customer.name || '未填客戶') + '</b><span>' + escapeHtml(isMerged ? mergedGroupId : (order.id || '-')) + '</span><small>' + escapeHtml(checks.join('／')) + '</small>' + freightFifoSalesCardShareBadgeHtml(order) + '</div><strong>' + (isMerged ? '一張合併出貨單' : '正式出貨核對') + '</strong><div>' + (isMerged ? '<button type="button" class="primary-button" data-combined-shipment-card="' + escapeHtml(mergedGroupId) + '">開啟合併出貨單</button>' : '') + '<button type="button" class="' + (isMerged ? 'ghost-button' : 'primary-button') + '" data-freight-fifo-order="' + escapeHtml(order.id || '') + '">核對物流與收款</button>' + (isMerged ? '<button type="button" class="danger-button" disabled title="請先解除合併出貨">合併單先解除再取消</button>' : '<button type="button" class="ghost-button" data-freight-fifo-revert-waiting="' + escapeHtml(order.id || '') + '">改回待配貨</button>' + freightFifoWaitNotifyButtonHtml(order, false) + '<button type="button" class="ghost-button" data-freight-fifo-return-order="' + escapeHtml(order.id || '') + '">退貨還原庫存</button><button type="button" class="danger-button" data-freight-fifo-cancel-order="' + escapeHtml(order.id || '') + '">取消客戶／整單回庫</button>' + freightFifoBlacklistButtonHtml(order.id || '')) + '</div></article>';`;

const ADMIN_PENDING_NEW = `      return '<article class="freight-fifo-active-order is-pending' + (isMerged ? ' is-combined-shipment' : '') + '" data-customer-phone="' + escapeHtml(customer.phone || order.customerPhone || '') + '" data-customer-name="' + escapeHtml(customer.name || order.customerName || '') + '">' + mergeCheckHtml + (previewImage ? '<button type="button" class="freight-fifo-active-photo" data-order-photo="' + escapeHtml(previewImage) + '"><img src="' + escapeHtml(previewImage) + '" alt="客戶購買圖片" loading="lazy" decoding="async"><span>先看圖片</span></button>' : '<div class="freight-fifo-active-photo is-empty"><span>無圖片</span></div>') + '<div class="freight-fifo-active-copy"><b>' + escapeHtml(customer.name || '未填客戶') + '</b><span>' + escapeHtml(isMerged ? mergedGroupId : (order.id || '-')) + '</span><small>' + escapeHtml(checks.join('／')) + '</small>' + freightFifoSalesCardShareBadgeHtml(order) + '</div><strong>' + (isMerged ? '一張合併出貨單' : '正式出貨核對') + '</strong><div>' + (isMerged ? '<button type="button" class="primary-button" data-combined-shipment-card="' + escapeHtml(mergedGroupId) + '">開啟合併出貨單</button>' : '') + '<button type="button" class="' + (isMerged ? 'ghost-button' : 'primary-button') + '" data-freight-fifo-order="' + escapeHtml(order.id || '') + '">核對物流與收款</button>' + (isMerged ? '<button type="button" class="danger-button" disabled title="請先解除合併出貨">合併單先解除再取消</button>' : '<button type="button" class="ghost-button" data-freight-fifo-revert-waiting="' + escapeHtml(order.id || '') + '">改回待配貨</button>' + freightFifoWaitNotifyButtonHtml(order, false) + '<button type="button" class="ghost-button" data-freight-fifo-return-order="' + escapeHtml(order.id || '') + '">退貨還原庫存</button><button type="button" class="danger-button" data-freight-fifo-cancel-order="' + escapeHtml(order.id || '') + '">取消客戶／整單回庫</button>' + freightFifoBlacklistButtonHtml(order.id || '')) + '</div></article>';`;

const PHP_FIND_NAME_OLD = `        } elseif (!$nameHit) {
            continue;
        }`;

const PHP_FIND_NAME_NEW = `        } elseif (!$nameHit || !member_risk_exact_name_hit($name, $lockedName)) {
            continue;
        }`;

const PHP_FN = `
function member_risk_exact_name_hit(string $left, string $right): bool {
    $a = member_risk_name_key($left);
    $b = member_risk_name_key($right);
    return $a !== '' && $a === $b;
}
`;

function patchRiskJs() {
  if (!fs.existsSync(RISK_JS)) {
    console.log('skip risk js, not on PHT-SR');
    return;
  }
  console.log('backup risk js', backup(RISK_JS, 'return-not-blacklist'));
  let src = fs.readFileSync(RISK_JS, 'utf8');
  if (src.indexOf(JS_MARKER) !== -1) {
    console.log('risk js already patched');
    return;
  }
  src = replaceOnce(src, RETURN_BY_OLD, RETURN_BY_NEW, 'returnByCustomer phoneHit');
  src = replaceOnce(src, RISK_BY_OLD, RISK_BY_NEW, 'riskByCustomer exact name');
  src = replaceOnce(src, RISK_TEXT_OLD, RISK_TEXT_NEW, 'riskFromText exact name');
  src = replaceOnce(src, RETURN_LABEL_OLD, RETURN_LABEL_NEW, 'returnLabel copy');
  src = replaceOnce(src, SET_BADGE_OLD, SET_BADGE_NEW, 'setRiskBadge return styling');
  src = replaceOnce(src, BADGE_CLASS_OLD, BADGE_CLASS_NEW, 'badge class split');
  src = replaceOnce(src, BANNER_OLD, BANNER_NEW, 'input banner return class');
  src = replaceOnce(src, IDENTITY_OLD, IDENTITY_NEW, 'customerIdentity data attrs');
  fs.writeFileSync(RISK_JS, src);
  console.log('risk js written', src.length);
}

function patchRiskCss() {
  if (!fs.existsSync(RISK_CSS)) return;
  let src = fs.readFileSync(RISK_CSS, 'utf8');
  if (src.indexOf('.member-return-notice') !== -1) {
    console.log('risk css already patched');
    return;
  }
  console.log('backup risk css', backup(RISK_CSS, 'return-not-blacklist'));
  fs.writeFileSync(RISK_CSS, src.replace(/\s*$/, '\n') + CSS_BLOCK);
  console.log('risk css written');
}

function patchAdminJs() {
  if (!fs.existsSync(ADMIN_JS)) return;
  console.log('backup admin js', backup(ADMIN_JS, 'return-not-blacklist'));
  let src = fs.readFileSync(ADMIN_JS, 'utf8');
  src = replaceOnce(src, ADMIN_TRANSIT_OLD, ADMIN_TRANSIT_NEW, 'transit card customer attrs');
  src = replaceOnce(src, ADMIN_PENDING_OLD, ADMIN_PENDING_NEW, 'pending card customer attrs');
  fs.writeFileSync(ADMIN_JS, src);
  console.log('admin js written', src.length);
}

function patchPhp() {
  if (!fs.existsSync(RISK_LIB)) return;
  let src = fs.readFileSync(RISK_LIB, 'utf8');
  if (src.indexOf(PHP_MARKER) !== -1) {
    console.log('php already patched');
    return;
  }
  console.log('backup php', backup(RISK_LIB, 'return-not-blacklist'));
  src = replaceOnce(src, 'function member_risk_find(string $baseDir, string $phone, string $name): ?array {', PHP_FN + 'function member_risk_find(string $baseDir, string $phone, string $name): ?array {', 'php exact name helper');
  src = replaceOnce(src, PHP_FIND_NAME_OLD, PHP_FIND_NAME_NEW, 'php find exact name');
  fs.writeFileSync(RISK_LIB, src);
  console.log('php written');
}

function nodeSelfTest() {
  const sample = fs.readFileSync('/tmp/member-risk-v2.js', 'utf8');
  if (!sample) return;
  const fn = new Function(sample + '\n;return { riskByCustomer, nameHit, nameKey, rowName, phone, phoneHit, rows: [{phone:"0965522489",name:"Ara angin Tiktok",reason:"詐騙"}] };');
  try {
    const api = fn();
    const wrong = api.riskByCustomer('', 'Ara Other Tiktok');
    console.log('self-test wrong-name hit', wrong ? wrong.name : null);
  } catch (error) {
    console.log('self-test skipped', error.message);
  }
}

if (!fs.existsSync(ROOT)) {
  console.log('Not on PHT-SR; script ready for', ROOT);
  process.exit(0);
}

patchRiskJs();
patchRiskCss();
patchAdminJs();
patchPhp();
stampHtml(ROOT);
nodeSelfTest();
console.log('LINGZANZAN return-not-blacklist:', STAMP);
