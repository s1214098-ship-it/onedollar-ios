'use strict';

/**
 * 2026-08-19: 訂單列表／進度 compact 卡沒把黑名單貼在姓名／電話旁，
 * 取消的預購單搜尋時還顯示「尚未採購」。
 *
 * Applied on PHT-SR F:\Web\lingzanzan-staging (www.lingzanzan.com).
 *
 * Cache-bust: ?v=20260819-blacklist-card-1
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const BUST = '20260819-blacklist-card-1';
const PREV_ADMIN = '20260819-fifo-open-hang-1';
const PREV_RISK = '20260819-blacklist-ask-sales-1';
const PREV_TRACK_CSS = '20260819-parcel-wrap-1';

const adminJs = path.join(root, 'assets', 'admin.js');
const riskJs = path.join(root, 'assets', 'member-risk-v2.js');
const riskCss = path.join(root, 'assets', 'member-risk-v2.css');
const trackCss = path.join(root, 'assets', 'admin-order-tracking.css');

function backup(file) {
  const dir = path.join(root, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const dest = path.join(dir, path.basename(file) + '.blacklist-card-' + stamp);
  fs.copyFileSync(file, dest);
  return dest;
}

function mustReplace(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1 && src.indexOf(oldStr) === -1) {
    console.log('already:', label);
    return src;
  }
  if (src.indexOf(oldStr) === -1) {
    throw new Error('missing snippet: ' + label);
  }
  const next = src.split(oldStr).join(newStr);
  if (next === src) throw new Error('no change: ' + label);
  console.log('patched:', label);
  return next;
}

function replaceOnce(src, oldStr, newStr, label) {
  const i = src.indexOf(oldStr);
  if (i < 0) {
    if (src.indexOf(newStr) !== -1) {
      console.log('already:', label);
      return src;
    }
    throw new Error('missing snippet: ' + label);
  }
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) {
    throw new Error('snippet not unique: ' + label);
  }
  console.log('patched:', label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const HELPERS = `
  function orderTrackingCustomerRisk(customer) {
    customer = customer || {};
    var api = window.LingzanzanMemberRisk;
    if (!api || typeof api.find !== 'function') return null;
    try { return api.find(customer.phone, customer.name) || null; } catch (error) { return null; }
  }

  function orderTrackingBlacklistTagHtml(customer) {
    if (!orderTrackingCustomerRisk(customer)) return '';
    return '<em class="order-tracking-blacklist-tag">黑名單</em>';
  }

  function orderTrackingCustomerHtml(customer, deliverySummary) {
    customer = customer || {};
    var tag = orderTrackingBlacklistTagHtml(customer);
    var risk = orderTrackingCustomerRisk(customer);
    var html = '<b>' + escapeHtml(customer.name || '-') + tag + '</b><span>' + escapeHtml(customer.phone || '-') + tag + '</span>';
    if (deliverySummary) html += '<small>' + escapeHtml(deliverySummary) + '</small>';
    if (risk) {
      html += '<strong class="order-tracking-blacklist-banner">⚠ 黑名單：' + escapeHtml(risk.reason || '詐騙／風險客戶') + '／請先問業務</strong>';
    }
    return html;
  }

  function orderTrackingCardModifierClass(entry, customer, overdue) {
    var cancelled = !!(entry && entry.stage === 'cancelled');
    var blacklisted = !!orderTrackingCustomerRisk(customer);
    return (overdue ? ' is-overdue' : '') + (cancelled ? ' is-cancelled' : '') + (blacklisted ? ' is-blacklisted' : '');
  }

  function orderTrackingNotifyRiskDecorators() {
    document.dispatchEvent(new CustomEvent('lingzanzan:order-tracking-rendered'));
    if (window.LingzanzanMemberRisk && typeof window.LingzanzanMemberRisk.refresh === 'function') {
      try { window.LingzanzanMemberRisk.refresh(); } catch (error) {}
    }
  }

`;

function patchAdmin(src) {
  if (src.indexOf('function orderTrackingCustomerRisk(') !== -1) {
    console.log('admin.js helpers already present');
  } else {
    src = replaceOnce(
      src,
      '  function orderTrackingPreorderStageLabel(entry) {',
      HELPERS + '  function orderTrackingPreorderStageLabel(entry) {',
      'admin helpers before stage label'
    );
  }

  src = replaceOnce(
    src,
    `  function orderTrackingPreorderStageLabel(entry) {
    if (!entry || entry.category !== 'preorder') return orderTrackingStageLabel(entry && entry.stage);
    var row = entry.record || {};
    var purchaseStatus = normalizePreorderPurchaseStatus(row.purchaseStatus);`,
    `  function orderTrackingPreorderStageLabel(entry) {
    if (!entry || entry.category !== 'preorder') return orderTrackingStageLabel(entry && entry.stage);
    var row = entry.record || {};
    if (entry.stage === 'cancelled' || String(row.status || '').toLowerCase() === 'cancelled' || String(row.status || '').toLowerCase() === 'canceled') {
      return orderTrackingCustomerRisk(orderTrackingCustomer(entry)) || /詐騙|黑名單/.test(String(row.cancelledReason || ''))
        ? '已取消／黑名單'
        : '已取消';
    }
    var purchaseStatus = normalizePreorderPurchaseStatus(row.purchaseStatus);`,
    'cancelled preorder stage label'
  );

  src = replaceOnce(
    src,
    `      if (status !== 'all' && entry.stage !== status) return false;
      if (overdueOnly && !orderTrackingIsOverdue(entry, now)) return false;`,
    `      if (status === 'all' && entry.stage === 'cancelled') return false;
      if (status !== 'all' && entry.stage !== status) return false;
      if (overdueOnly && !orderTrackingIsOverdue(entry, now)) return false;`,
    'hide cancelled from 全部狀態'
  );

  src = replaceOnce(
    src,
    `      '<article class="order-tracking-card order-tracking-card--compact' + (overdue ? ' is-overdue' : '') + '">',
      '<div class="order-tracking-card-source"><p>' + escapeHtml(orderTrackingEntrySourceLabel(entry)) + '</p><b>' + escapeHtml(recordId) + '</b><time>' + escapeHtml(dateKey || '日期待補') + '</time></div>',
      '<div class="order-tracking-customer"><b>' + escapeHtml(customer.name) + '</b><span>' + escapeHtml(customer.phone) + '</span></div>',`,
    `      '<article class="order-tracking-card order-tracking-card--compact' + orderTrackingCardModifierClass(entry, customer, overdue) + '">',
      '<div class="order-tracking-card-source"><p>' + escapeHtml(orderTrackingEntrySourceLabel(entry)) + '</p><b>' + escapeHtml(recordId) + '</b><time>' + escapeHtml(dateKey || '日期待補') + '</time></div>',
      '<div class="order-tracking-customer">' + orderTrackingCustomerHtml(customer) + '</div>',`,
    'compact customer blacklist html'
  );

  src = replaceOnce(
    src,
    `      '<article class="order-tracking-card' + (overdue ? ' is-overdue' : '') + '">',
      '<div class="order-tracking-card-source"><p>' + escapeHtml(orderTrackingEntrySourceLabel(entry)) + '</p><b>' + escapeHtml(recordId) + '</b>' + (formalId ? '<small>正式單：' + escapeHtml(formalId) + '</small>' : '') + '<time>' + escapeHtml(dateKey || '日期待補') + (age !== null ? '／' + age + ' 天' : '') + '</time></div>',
      '<div class="order-tracking-customer"><b>' + escapeHtml(customer.name) + '</b><span>' + escapeHtml(customer.phone) + '</span><small>' + escapeHtml(deliverySummary) + '</small></div>',`,
    `      '<article class="order-tracking-card' + orderTrackingCardModifierClass(entry, customer, overdue) + '">',
      '<div class="order-tracking-card-source"><p>' + escapeHtml(orderTrackingEntrySourceLabel(entry)) + '</p><b>' + escapeHtml(recordId) + '</b>' + (formalId ? '<small>正式單：' + escapeHtml(formalId) + '</small>' : '') + '<time>' + escapeHtml(dateKey || '日期待補') + (age !== null ? '／' + age + ' 天' : '') + '</time></div>',
      '<div class="order-tracking-customer">' + orderTrackingCustomerHtml(customer, deliverySummary) + '</div>',`,
    'expanded customer blacklist html'
  );

  src = replaceOnce(
    src,
    `    var summary = document.querySelector('[data-order-tracking-summary]');
    if (summary) {
      if (orderTrackingAwaitingOrders) {`,
    `    orderTrackingNotifyRiskDecorators();
    var summary = document.querySelector('[data-order-tracking-summary]');
    if (summary) {
      if (orderTrackingAwaitingOrders) {`,
    'refresh risk badges after tracking lists paint'
  );

  return src;
}

function patchRiskJs(src) {
  src = replaceOnce(
    src,
    `    var badge = target.querySelector('[data-member-risk-badge]');
    if (!risk && !returned && !orderActivity) {
      target.classList.remove('member-risk-warning');`,
    `    var host = target.querySelector('.order-tracking-customer, .order-first-customer') || target;
    var badge = host.querySelector('[data-member-risk-badge]');
    if (!risk && !returned && !orderActivity) {
      target.classList.remove('member-risk-warning');`,
    'badge host is name/phone box'
  );

  // appendChild currently uses target; switch to host
  src = replaceOnce(
    src,
    `    if (!badge) {
      badge = document.createElement('span');
      badge.setAttribute('data-member-risk-badge', '');
      target.appendChild(badge);
    }`,
    `    if (!badge) {
      badge = document.createElement('span');
      badge.setAttribute('data-member-risk-badge', '');
      host.appendChild(badge);
    }`,
    'append badge under customer box'
  );

  if (src.indexOf('function syncInlineRiskTag(') === -1) {
    src = replaceOnce(
      src,
      `  function installOrderRiskAction(card) {`,
      `  function syncInlineRiskTag(node, risk, kind) {
    if (!node) return;
    if (node.querySelector && node.querySelector('.order-tracking-blacklist-tag')) return;
    var existing = node.parentNode && node.parentNode.querySelector('[data-member-risk-inline="' + kind + '"]');
    if (!risk) {
      if (existing) existing.remove();
      return;
    }
    if (!existing) {
      existing = document.createElement('em');
      existing.setAttribute('data-member-risk-inline', kind);
      existing.className = 'order-tracking-blacklist-tag member-risk-inline-tag';
      node.insertAdjacentElement('afterend', existing);
    }
    existing.textContent = '黑名單';
    existing.title = risk.reason || text('黑名單', 'Daftar hitam');
  }

  function installOrderRiskAction(card) {`,
      'inline 黑名單 next to name/phone'
    );
  }

  src = replaceOnce(
    src,
    `    var customerName = String(nameNode && nameNode.textContent || '').trim();
    var customerPhone = String(phoneNode && phoneNode.textContent || '').trim();
    var phoneValue = phone(customerPhone);
    if (!phoneValue) return;
    var risk = riskByCustomer(customerPhone, customerName);`,
    `    var customerName = String(nameNode && nameNode.textContent || '').replace(/黑名單/g, '').trim();
    var customerPhone = String(phoneNode && phoneNode.textContent || '').replace(/黑名單/g, '').trim();
    var phoneValue = phone(customerPhone);
    if (!phoneValue) return;
    var risk = riskByCustomer(customerPhone, customerName);
    syncInlineRiskTag(nameNode, risk, 'name');
    syncInlineRiskTag(phoneNode, risk, 'phone');`,
    'call inline tags in installOrderRiskAction'
  );

  src = replaceOnce(
    src,
      `    if (/\\/(?:admin-freight|admin-orders|admin-preorders|admin-order-tracking|admin-preorder-waiting|admin-preorder-demand|admin-inventory|admin-inventory-entry|admin-products|admin-finance|admin-profit)\\.html$/i.test(window.location.pathname)) {
      // These workbench pages are large and redraw their cards themselves.
      // Re-scanning the whole subtree after every card replacement creates a
      // feedback loop (markPage also decorates those cards) and can freeze or
      // crash the renderer. Run bounded passes while async data loads instead.
      // Run a few bounded passes while its async data loads, then stay idle.
      window.setTimeout(markPage, 2500);
      window.setTimeout(markPage, 7000);
    } else {
      new MutationObserver(scheduleMark).observe(document.body, { childList: true, subtree: true });
    }`,
      `    if (/\\/(?:admin-freight|admin-orders|admin-preorders|admin-order-tracking|admin-preorder-waiting|admin-preorder-demand|admin-inventory|admin-inventory-entry|admin-products|admin-finance|admin-profit)\\.html$/i.test(window.location.pathname)) {
      // These workbench pages are large and redraw their cards themselves.
      // Re-scanning the whole subtree after every card replacement creates a
      // feedback loop (markPage also decorates those cards) and can freeze or
      // crash the renderer. Run bounded passes while async data loads instead.
      // Re-decorate when the tracking list paints, plus a few delayed passes.
      document.addEventListener('lingzanzan:order-tracking-rendered', scheduleMark);
      document.addEventListener('lingzanzan:freight-loaded', scheduleMark);
      window.setTimeout(markPage, 2500);
      window.setTimeout(markPage, 7000);
      window.setTimeout(markPage, 15000);
    } else {
      new MutationObserver(scheduleMark).observe(document.body, { childList: true, subtree: true });
    }`,
    'redecorate after tracking render event'
  );

  return src;
}

const CSS_BLOCK = `
.order-tracking-customer b,
.order-first-customer b {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.order-tracking-blacklist-tag,
.member-risk-inline-tag {
  display: inline-flex;
  align-items: center;
  margin-left: 6px;
  padding: 2px 8px;
  border-radius: 999px;
  background: #f06478;
  color: #190b10 !important;
  font-size: 11px;
  font-style: normal;
  font-weight: 900;
  letter-spacing: .04em;
  line-height: 1.2;
  vertical-align: middle;
  white-space: nowrap;
}
.order-tracking-customer span {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.order-tracking-blacklist-banner,
.order-tracking-customer > [data-member-risk-badge] {
  display: block;
  width: 100%;
  margin-top: 6px;
  padding: 7px 9px;
  border-radius: 8px;
  background: #f06478;
  color: #190b10 !important;
  font-size: 12px;
  font-weight: 900;
  line-height: 1.35;
  white-space: pre-line;
}
.order-tracking-card.is-blacklisted {
  border: 2px solid #f06478 !important;
  background: #3a1a22 !important;
}
.order-tracking-card.is-cancelled .order-tracking-stage {
  background: #5c2a31;
  color: #ffb4c0;
}
`;

function patchCss(src, label) {
  if (src.indexOf('.order-tracking-blacklist-tag') !== -1) {
    console.log('already css:', label);
    return src;
  }
  console.log('patched css:', label);
  return src.replace(/\s*$/, '\n') + CSS_BLOCK;
}

function bustHtml() {
  const names = fs.readdirSync(root).filter(function (name) {
    return /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name);
  });
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(root, name);
    let html = fs.readFileSync(file, 'utf8');
    const orig = html;
    html = html.split('admin.js?v=' + PREV_ADMIN).join('admin.js?v=' + BUST);
    html = html.split('member-risk-v2.js?v=' + PREV_RISK).join('member-risk-v2.js?v=' + BUST);
    html = html.split('member-risk-v2.css?v=' + PREV_RISK).join('member-risk-v2.css?v=' + BUST);
    html = html.split('admin-order-tracking.css?v=' + PREV_TRACK_CSS).join('admin-order-tracking.css?v=' + BUST);
    if (html !== orig) {
      fs.writeFileSync(file, html);
      n++;
      console.log('cache-bust', name);
    }
  });
  console.log('html files busted', n);
}

if (!fs.existsSync(adminJs)) {
  console.log('Not on PHT-SR; live files are under F:\\Web\\lingzanzan-staging');
  process.exit(0);
}

const adminBak = backup(adminJs);
const riskBak = backup(riskJs);
backup(riskCss);
backup(trackCss);
console.log('backup admin', adminBak);
console.log('backup risk', riskBak);

fs.writeFileSync(adminJs, patchAdmin(fs.readFileSync(adminJs, 'utf8')));
fs.writeFileSync(riskJs, patchRiskJs(fs.readFileSync(riskJs, 'utf8')));
fs.writeFileSync(riskCss, patchCss(fs.readFileSync(riskCss, 'utf8'), 'member-risk-v2.css'));
fs.writeFileSync(trackCss, patchCss(fs.readFileSync(trackCss, 'utf8'), 'admin-order-tracking.css'));
bustHtml();
console.log('done', BUST);
