#!/usr/bin/env node
"use strict";

/**
 * 黑名單用詞與排版：
 * - 確定在名單裡：姓名欄紅框 + 「此客為黑名單」
 * - 功能鈕改叫「加入黑名單」，放到右邊操作區，不要放名字下面
 *
 * Live: member-risk-v2.js / css, admin.js, admin-order-tracking.css
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const RISK_JS = path.join(ROOT, "assets", "member-risk-v2.js");
const RISK_CSS = path.join(ROOT, "assets", "member-risk-v2.css");
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const TRACK_CSS = path.join(ROOT, "assets", "admin-order-tracking.css");
const STAMP = "20260822-blacklist-ui-1";
const JS_MARKER = "加入黑名單";
const STATUS_MARKER = "此客為黑名單";

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
  const names = [
    "admin-order-tracking.html",
    "admin-orders.html",
    "admin-preorders.html",
    "admin-freight.html",
    "admin-preorder-waiting.html",
    "admin-preorder-demand.html",
    "admin.html",
  ];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    let next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/member-risk-v2\.js(?:\?v=[^"']+)?/g, "member-risk-v2.js?v=" + STAMP)
      .replace(/member-risk-v2\.css(?:\?v=[^"']+)?/g, "member-risk-v2.css?v=" + STAMP)
      .replace(/admin-order-tracking\.css(?:\?v=[^"']+)?/g, "admin-order-tracking.css?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const INSTALL_OLD = `  function syncInlineRiskTag(node, risk, kind) {
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

  function installOrderRiskAction(card) {
    if (!isAdminManagerPage() || !card || !card.matches('[data-order-card], [data-preorder-card], .order-tracking-card')) return;
    var customerBox = card.querySelector('.order-first-customer, .order-tracking-customer');
    if (!customerBox) return;
    var nameNode = customerBox.querySelector('b');
    var phoneNode = customerBox.querySelector('span');
    var customerName = String(nameNode && nameNode.textContent || '').replace(/黑名單/g, '').trim();
    var customerPhone = String(phoneNode && phoneNode.textContent || '').replace(/黑名單/g, '').trim();
    var phoneValue = phone(customerPhone);
    if (!phoneValue) return;
    var risk = riskByCustomer(customerPhone, customerName);
    syncInlineRiskTag(nameNode, risk, 'name');
    syncInlineRiskTag(phoneNode, risk, 'phone');
    var button = customerBox.querySelector('[data-order-risk-toggle]');
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-order-risk-toggle', '');
      customerBox.appendChild(button);
    }
    button.setAttribute('data-risk-phone', customerPhone);
    button.setAttribute('data-risk-name', customerName);
    button.disabled = false;
    button.className = 'order-risk-toggle' + (risk ? ' is-listed' : '');
    // Keep this decorator idempotent.  The page-level MutationObserver watches
    // child-list changes; assigning textContent on every pass replaces the
    // button text node and schedules markPage() forever on large order lists.
    var buttonLabel = risk ? text('解除黑名單', 'Hapus daftar hitam') : text('標注黑名單', 'Tandai daftar hitam');`;

const INSTALL_NEW = `  function syncInlineRiskTag(node, risk, kind) {
    if (!node || !node.parentNode) return;
    var existing = node.parentNode.querySelector('[data-member-risk-inline="' + kind + '"]');
    if (existing) existing.remove();
  }

  function paintCustomerBlacklistStatus(customerBox, risk) {
    if (!customerBox) return;
    customerBox.classList.toggle('is-blacklisted-customer', !!risk);
    var status = customerBox.querySelector('[data-blacklist-customer-status]');
    if (!risk) {
      if (status) status.remove();
      return;
    }
    if (!status) {
      status = document.createElement('strong');
      status.setAttribute('data-blacklist-customer-status', '');
      status.className = 'order-tracking-blacklist-status';
      customerBox.appendChild(status);
    }
    var label = text('此客為黑名單', 'Pelanggan ini daftar hitam') + (risk.reason ? '／' + risk.reason : '');
    if (status.textContent !== label) status.textContent = label;
  }

  function orderRiskActionHost(card, customerBox) {
    var host = card && card.querySelector('.order-tracking-actions, .order-card-actions, .order-first-actions, .freight-fifo-search-pending-actions');
    if (host) return host;
    host = card && card.querySelector('.order-card-risk-tools');
    if (host) return host;
    if (card) {
      host = document.createElement('div');
      host.className = 'order-card-risk-tools';
      card.appendChild(host);
      return host;
    }
    return customerBox;
  }

  function installOrderRiskAction(card) {
    if (!isAdminManagerPage() || !card) return;
    var isFifoReturned = !!(card.closest && card.closest('.freight-fifo-search-pending') && String(card.tagName || '').toUpperCase() === 'ARTICLE');
    if (!card.matches('[data-order-card], [data-preorder-card], .order-tracking-card') && !isFifoReturned) return;
    var customerBox = card.querySelector('.order-first-customer, .order-tracking-customer');
    if (!customerBox && isFifoReturned) {
      customerBox = card.querySelector('div');
    }
    if (!customerBox) return;
    var nameNode = customerBox.querySelector('b');
    var phoneNode = customerBox.querySelector('span');
    var customerName = String(nameNode && nameNode.textContent || '').replace(/此客為黑名單|黑名單/g, '').trim();
    var customerPhone = String(phoneNode && phoneNode.textContent || '').replace(/此客為黑名單|黑名單/g, '').trim();
    var phoneValue = phone(customerPhone);
    if (!phoneValue) return;
    var risk = riskByCustomer(customerPhone, customerName);
    syncInlineRiskTag(nameNode, risk, 'name');
    syncInlineRiskTag(phoneNode, risk, 'phone');
    paintCustomerBlacklistStatus(customerBox, risk);
    var stray = customerBox.querySelector('[data-order-risk-toggle]');
    var host = orderRiskActionHost(card, customerBox);
    var button = (host && host.querySelector('[data-order-risk-toggle]')) || stray;
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-order-risk-toggle', '');
    }
    if (host && button.parentNode !== host) host.appendChild(button);
    button.setAttribute('data-risk-phone', customerPhone);
    button.setAttribute('data-risk-name', customerName);
    button.disabled = false;
    button.className = 'order-risk-toggle ghost-button' + (risk ? ' is-listed' : '');
    // Keep this decorator idempotent.  The page-level MutationObserver watches
    // child-list changes; assigning textContent on every pass replaces the
    // button text node and schedules markPage() forever on large order lists.
    var buttonLabel = risk ? text('解除黑名單', 'Hapus daftar hitam') : text('加入黑名單', 'Tambah ke daftar hitam');`;

const CSS_APPEND = `

.order-tracking-customer.is-blacklisted-customer,
.order-first-customer.is-blacklisted-customer,
.freight-fifo-search-pending article > div.is-blacklisted-customer {
  outline: 2px solid #f06478;
  box-shadow: 0 0 0 3px rgba(240, 100, 120, .2);
  background: #4b202b !important;
  border-radius: 12px;
  padding: 8px 10px;
}
.order-tracking-blacklist-status,
[data-blacklist-customer-status] {
  display: block;
  width: 100%;
  margin-top: 6px;
  padding: 6px 8px;
  border-radius: 8px;
  background: #f06478;
  color: #190b10 !important;
  font-size: 13px;
  font-style: normal;
  font-weight: 900;
  line-height: 1.35;
}
.order-tracking-actions .order-risk-toggle,
.order-card-actions .order-risk-toggle,
.order-first-actions .order-risk-toggle,
.freight-fifo-search-pending-actions .order-risk-toggle,
.order-card-risk-tools .order-risk-toggle {
  width: auto;
  min-height: 40px;
  margin-top: 0;
  padding: 8px 12px;
  border: 1px solid #c9a3ab !important;
  border-radius: 12px;
  background: transparent !important;
  color: #fff8ed !important;
  font-size: 13px;
  font-weight: 900;
}
.order-tracking-actions .order-risk-toggle.is-listed,
.freight-fifo-search-pending-actions .order-risk-toggle.is-listed,
.order-card-risk-tools .order-risk-toggle.is-listed {
  border-color: #f06478 !important;
  color: #ffb4c0 !important;
}
.order-card-risk-tools {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  padding: 4px 0 0;
}
`;

const CUSTOMER_HTML_OLD = `  function orderTrackingBlacklistTagHtml(customer) {
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
  }`;

const CUSTOMER_HTML_NEW = `  function orderTrackingBlacklistTagHtml(customer) {
    return '';
  }

  function orderTrackingCustomerHtml(customer, deliverySummary) {
    customer = customer || {};
    var risk = orderTrackingCustomerRisk(customer);
    var html = '<b>' + escapeHtml(customer.name || '-') + '</b><span>' + escapeHtml(customer.phone || '-') + '</span>';
    if (deliverySummary) html += '<small>' + escapeHtml(deliverySummary) + '</small>';
    if (risk) {
      html += '<strong class="order-tracking-blacklist-status" data-blacklist-customer-status>此客為黑名單' + (risk.reason ? '／' + escapeHtml(risk.reason) : '') + '</strong>';
    }
    return html;
  }`;

const COMPACT_OLD = `'<div class="order-tracking-customer">' + orderTrackingCustomerHtml(customer) + '</div>'`;
const COMPACT_NEW = `'<div class="order-tracking-customer' + (orderTrackingCustomerRisk(customer) ? ' is-blacklisted-customer' : '') + '">' + orderTrackingCustomerHtml(customer) + '</div>'`;
const EXPANDED_OLD = `'<div class="order-tracking-customer">' + orderTrackingCustomerHtml(customer, deliverySummary) + '</div>'`;
const EXPANDED_NEW = `'<div class="order-tracking-customer' + (orderTrackingCustomerRisk(customer) ? ' is-blacklisted-customer' : '') + '">' + orderTrackingCustomerHtml(customer, deliverySummary) + '</div>'`;

const FIFO_HEAD_OLD = `<b>黑名單／已取消／已退回，仍可退貨打單</b><small>封鎖後訂單不會消失。按「退貨還原庫存」會把商品回倉，並把運費虧損掛在這位客人。</small>`;
const FIFO_HEAD_NEW = `<b>已取消／已退回，仍可退貨打單</b><small>取消或退回的單仍可搜到，不代表這人已是黑名單。確定在黑名單的客人，姓名欄會紅框並註明「此客為黑名單」。按「退貨還原庫存」會把商品回倉，並把運費虧損掛在這位客人。</small>`;

const FIFO_CARD_OLD = `      return '<article><div><b>' + escapeHtml(customer.name || order.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || order.customerPhone || '未填電話') + '</span><small>' + escapeHtml((order.id || '-') + '／' + status + (order.trackingNo ? '／' + order.trackingNo : '')) + '</small></div><div class="freight-fifo-search-pending-actions">`;
const FIFO_CARD_NEW = `      var listed = !!(window.LingzanzanMemberRisk && typeof window.LingzanzanMemberRisk.find === 'function' && window.LingzanzanMemberRisk.find(customer.phone || order.customerPhone, customer.name || order.customerName));
      return '<article' + (listed ? ' class="is-blacklisted-card"' : '') + '><div class="' + (listed ? 'is-blacklisted-customer' : '') + '"><b>' + escapeHtml(customer.name || order.customerName || '未填客戶') + '</b><span>' + escapeHtml(customer.phone || order.customerPhone || '未填電話') + '</span><small>' + escapeHtml((order.id || '-') + '／' + status + (order.trackingNo ? '／' + order.trackingNo : '')) + '</small>' + (listed ? '<strong class="order-tracking-blacklist-status" data-blacklist-customer-status>此客為黑名單</strong>' : '') + '</div><div class="freight-fifo-search-pending-actions">`;

if (!fs.existsSync(RISK_JS) || !fs.existsSync(ADMIN_JS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup risk js", backup(RISK_JS, "bl-ui"));
let risk = fs.readFileSync(RISK_JS, "utf8");
const CLICK_OLD = `installOrderRiskAction(orderRiskButton.closest('[data-order-card], [data-preorder-card], .order-tracking-card'));`;
const CLICK_NEW = `installOrderRiskAction(orderRiskButton.closest('[data-order-card], [data-preorder-card], .order-tracking-card') || (orderRiskButton.closest('.freight-fifo-search-pending') && orderRiskButton.closest('article')));`;
const MARK_OLD = `.order-card, .order-tracking-card, .business-order-card`;
const MARK_NEW = `.order-card, .order-tracking-card, .freight-fifo-search-pending article, .business-order-card`;

if (risk.indexOf("paintCustomerBlacklistStatus") !== -1 && risk.indexOf("加入黑名單") !== -1) {
  console.log("risk js already patched");
} else {
  risk = replaceOnce(risk, INSTALL_OLD, INSTALL_NEW, "move toggle + status");
  risk = replaceOnce(risk, CLICK_OLD, CLICK_NEW, "click closest includes fifo cards");
  risk = replaceOnce(risk, MARK_OLD, MARK_NEW, "markPage includes fifo returned cards");
  fs.writeFileSync(RISK_JS, risk, "utf8");
}
if (risk.indexOf(JS_MARKER) === -1) throw new Error("加入黑名單 missing");
if (risk.indexOf("paintCustomerBlacklistStatus") === -1) throw new Error("status painter missing");

console.log("backup risk css", backup(RISK_CSS, "bl-ui"));
let riskCss = fs.readFileSync(RISK_CSS, "utf8");
if (riskCss.indexOf(".order-tracking-customer.is-blacklisted-customer") !== -1) {
  console.log("risk css already patched");
} else {
  fs.writeFileSync(RISK_CSS, riskCss.replace(/\s*$/, "\n") + CSS_APPEND, "utf8");
  console.log("patched: risk css status frame");
}

if (fs.existsSync(TRACK_CSS)) {
  console.log("backup track css", backup(TRACK_CSS, "bl-ui"));
  let trackCss = fs.readFileSync(TRACK_CSS, "utf8");
  if (trackCss.indexOf(".order-tracking-customer.is-blacklisted-customer") !== -1) {
    console.log("track css already patched");
  } else {
    fs.writeFileSync(TRACK_CSS, trackCss.replace(/\s*$/, "\n") + CSS_APPEND, "utf8");
    console.log("patched: tracking css status frame");
  }
}

console.log("backup admin js", backup(ADMIN_JS, "bl-ui"));
let admin = fs.readFileSync(ADMIN_JS, "utf8");
if (admin.indexOf(STATUS_MARKER) !== -1 && admin.indexOf(FIFO_HEAD_NEW) !== -1) {
  console.log("admin js already patched");
} else {
  admin = replaceOnce(admin, CUSTOMER_HTML_OLD, CUSTOMER_HTML_NEW, "tracking customer status");
  admin = replaceOnce(admin, COMPACT_OLD, COMPACT_NEW, "compact customer red frame");
  admin = replaceOnce(admin, EXPANDED_OLD, EXPANDED_NEW, "expanded customer red frame");
  admin = replaceOnce(admin, FIFO_HEAD_OLD, FIFO_HEAD_NEW, "fifo cancelled bucket title");
  admin = replaceOnce(admin, FIFO_CARD_OLD, FIFO_CARD_NEW, "fifo cancelled card listed frame");
  fs.writeFileSync(ADMIN_JS, admin, "utf8");
}
if (admin.indexOf(STATUS_MARKER) === -1) throw new Error("admin status copy missing");
if (admin.indexOf("已取消／已退回，仍可退貨打單") === -1) throw new Error("fifo title missing");

stampHtml(ROOT);
console.log("LINGZANZAN blacklist ui wording ok", STAMP);
