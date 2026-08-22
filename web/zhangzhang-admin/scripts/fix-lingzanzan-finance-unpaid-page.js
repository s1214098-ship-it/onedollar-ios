#!/usr/bin/env node
"use strict";

/**
 * 財務系統「未收款分類／業務追款名單」：
 * - 客戶卡可展開／收回訂單與收款欄
 * - 客戶名單、訂單明細可整區收回
 * - 十筆一批分頁（客戶、訂單、上方統計明細也一樣）
 *
 * Cache-bust: finance.js ?v=20260820-unpaid-page-1
 * UTF-8 HTML I/O: admin-finance.html is valid UTF-8.
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "finance.js");
const HTML = path.join(ROOT, "admin-finance.html");
const STAMP = "20260820-unpaid-page-1";
const MARKER = "var LIST_PAGE_SIZE = 10;";

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
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error("missing snippet: " + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error("not unique: " + label);
  console.log("patched:", label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const STATE_OLD = `  var chaseKindFilter = 'all';
  var chaseSalesFilter = 'all';
  var lastChasePrintRows = [];
  var statDrill = '';`;

const STATE_NEW = `  var chaseKindFilter = 'all';
  var chaseSalesFilter = 'all';
  var lastChasePrintRows = [];
  var statDrill = '';
  var LIST_PAGE_SIZE = 10;
  var chaseCustomerPage = 1;
  var chaseOrderPage = 1;
  var chaseCustomersCollapsed = false;
  var chaseOrdersCollapsed = false;
  var chaseExpandedCustomers = {};
  var chaseListKey = '';
  var drillEventPage = 1;
  var drillOrderPage = 1;
  var drillEventsCollapsed = false;
  var drillOrdersCollapsed = false;
  var drillListKey = '';`;

const HELPERS = `
  function pagedItems(list, page, size) {
    list = Array.isArray(list) ? list : [];
    size = size || LIST_PAGE_SIZE;
    var total = list.length;
    var pages = Math.max(1, Math.ceil(total / size) || 1);
    page = Number(page || 1);
    if (!(page >= 1)) page = 1;
    if (page > pages) page = pages;
    return { page: page, pages: pages, total: total, items: list.slice((page - 1) * size, page * size) };
  }

  function pagerHtml(kind, page, pages, total, noun) {
    noun = noun || '筆';
    if (!total) return '';
    var html = '<div class="finance-page-bar"><span>每頁 10 ' + noun + '　第 ' + page + '／' + pages + ' 頁　共 ' + total + ' ' + noun + '</span><div class="finance-page-btns">';
    html += '<button type="button" data-fin-page-kind="' + kind + '" data-fin-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>上一頁</button>';
    var shown = {};
    var addPage = function (n) {
      n = Number(n);
      if (n < 1 || n > pages || shown[n]) return;
      shown[n] = true;
      html += '<button type="button"' + (n === page ? ' class="is-current"' : '') + ' data-fin-page-kind="' + kind + '" data-fin-page="' + n + '">' + n + '</button>';
    };
    addPage(1);
    if (page > 4) html += '<span class="finance-page-gap">…</span>';
    var i;
    for (i = page - 2; i <= page + 2; i++) addPage(i);
    if (page < pages - 3) html += '<span class="finance-page-gap">…</span>';
    addPage(pages);
    html += '<button type="button" data-fin-page-kind="' + kind + '" data-fin-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>下一頁</button>';
    html += '</div></div>';
    return html;
  }

  function setCollapseButton(host, kind, collapsed, expandLabel, collapseLabel) {
    if (!host) return;
    var subhead = host.previousElementSibling;
    if (!subhead || !subhead.classList || !subhead.classList.contains('finance-subhead')) return;
    var btn = subhead.querySelector('[data-fin-collapse="' + kind + '"]');
    var label = collapsed ? expandLabel : collapseLabel;
    if (!btn) {
      subhead.insertAdjacentHTML('beforeend', '<button type="button" class="finance-collapse-btn" data-fin-collapse="' + kind + '">' + label + '</button>');
    } else {
      btn.textContent = label;
    }
  }
`;

const GROUPS_OLD = `  function customerGroups(list) {
    var groups = {};
    list.forEach(function (order) {
      var key = customerKey(order);
      if (!groups[key]) groups[key] = { name: customerName(order), phone: customerPhone(order), orders: [], total: 0, paid: 0, balance: 0 };
      groups[key].orders.push(orderId(order));
      groups[key].total += orderTotal(order);
      groups[key].paid += paidFor(order);
      groups[key].balance += balanceFor(order);
    });
    return Object.keys(groups).map(function (key) { return groups[key]; }).sort(function (a, b) { return b.balance - a.balance; });
  }`;

const GROUPS_NEW = `  function customerGroups(list) {
    var groups = {};
    list.forEach(function (order) {
      var key = customerKey(order);
      if (!groups[key]) groups[key] = { key: key, name: customerName(order), phone: customerPhone(order), orders: [], orderRows: [], total: 0, paid: 0, balance: 0 };
      groups[key].orders.push(orderId(order));
      groups[key].orderRows.push(order);
      groups[key].total += orderTotal(order);
      groups[key].paid += paidFor(order);
      groups[key].balance += balanceFor(order);
    });
    return Object.keys(groups).map(function (key) { return groups[key]; }).sort(function (a, b) { return b.balance - a.balance; });
  }`;

const RENDER_CHASE_OLD = `    lastChasePrintRows = visible;
    var visibleOrders = visible.map(function (row) { return row.order; });
    var groups = customerGroups(visibleOrders);
    if ($('[data-fin-chase-heading]')) $('[data-fin-chase-heading]').textContent = chaseKindLabel(chaseKindFilter) + (chaseSalesFilter === 'all' ? '' : '／' + chaseSalesFilter);
    if ($('[data-fin-chase-summary]')) $('[data-fin-chase-summary]').textContent = visible.length + ' 筆／未收 ' + money(visible.reduce(function (sum, row) { return sum + row.info.balance; }, 0));
    var customerHost = $('[data-fin-chase-customers]');
    var orderHost = $('[data-fin-chase-orders]');
    if (customerHost) customerHost.innerHTML = groups.length ? groups.map(function (group) { return customerCard(group, false, chaseKindFilter === 'signed' ? 'is-debt' : 'is-unpaid'); }).join('') : '<div class="finance-empty">目前沒有符合的未收款客戶。</div>';
    if (orderHost) orderHost.innerHTML = visibleOrders.length ? visibleOrders.slice(0, 200).map(function (order) { return orderCard(order, false); }).join('') : '<div class="finance-empty">目前沒有符合的未收款訂單。</div>';
  }`;

const RENDER_CHASE_NEW = `    lastChasePrintRows = visible;
    var visibleOrders = visible.map(function (row) { return row.order; });
    var groups = customerGroups(visibleOrders);
    var nextChaseKey = chaseKindFilter + '|' + chaseSalesFilter + '|' + query();
    if (nextChaseKey !== chaseListKey) {
      chaseListKey = nextChaseKey;
      chaseCustomerPage = 1;
      chaseOrderPage = 1;
    }
    if ($('[data-fin-chase-heading]')) $('[data-fin-chase-heading]').textContent = chaseKindLabel(chaseKindFilter) + (chaseSalesFilter === 'all' ? '' : '／' + chaseSalesFilter);
    if ($('[data-fin-chase-summary]')) $('[data-fin-chase-summary]').textContent = visible.length + ' 筆／未收 ' + money(visible.reduce(function (sum, row) { return sum + row.info.balance; }, 0)) + '／' + groups.length + ' 位客戶';
    var customerHost = $('[data-fin-chase-customers]');
    var orderHost = $('[data-fin-chase-orders]');
    setCollapseButton(customerHost, 'chase-customers', chaseCustomersCollapsed, '展開客戶名單（' + groups.length + ' 位）', '收回客戶名單');
    setCollapseButton(orderHost, 'chase-orders', chaseOrdersCollapsed, '展開訂單明細（' + visibleOrders.length + ' 筆）', '收回訂單明細');
    if (customerHost) {
      if (!groups.length) {
        customerHost.hidden = false;
        customerHost.innerHTML = '<div class="finance-empty">目前沒有符合的未收款客戶。</div>';
      } else if (chaseCustomersCollapsed) {
        customerHost.hidden = true;
        customerHost.innerHTML = '';
      } else {
        var pagedCustomers = pagedItems(groups, chaseCustomerPage, LIST_PAGE_SIZE);
        chaseCustomerPage = pagedCustomers.page;
        customerHost.hidden = false;
        customerHost.innerHTML = pagedCustomers.items.map(function (group) {
          return customerCard(group, false, chaseKindFilter === 'signed' ? 'is-debt' : 'is-unpaid', { expandable: true });
        }).join('') + pagerHtml('chase-customers', pagedCustomers.page, pagedCustomers.pages, pagedCustomers.total, '位客戶');
      }
    }
    if (orderHost) {
      if (!visibleOrders.length) {
        orderHost.hidden = false;
        orderHost.innerHTML = '<div class="finance-empty">目前沒有符合的未收款訂單。</div>';
      } else if (chaseOrdersCollapsed) {
        orderHost.hidden = true;
        orderHost.innerHTML = '';
      } else {
        var pagedOrders = pagedItems(visibleOrders, chaseOrderPage, LIST_PAGE_SIZE);
        chaseOrderPage = pagedOrders.page;
        orderHost.hidden = false;
        orderHost.innerHTML = pagedOrders.items.map(function (order) { return orderCard(order, false); }).join('') + pagerHtml('chase-orders', pagedOrders.page, pagedOrders.pages, pagedOrders.total, '筆');
      }
    }
  }`;

const RENDER_DRILL_OLD = `    if ($('[data-fin-drill-event-count]')) $('[data-fin-drill-event-count]').textContent = data.events.length ? ('合計 ' + money(eventTotal)) : '沒有收款事件';
    if ($('[data-fin-drill-order-count]')) $('[data-fin-drill-order-count]').textContent = data.orders.length + ' 張';
    if ($('[data-fin-drill-events]')) $('[data-fin-drill-events]').innerHTML = data.events.length ? data.events.slice(0, 200).map(eventCard).join('') + (data.events.length > 200 ? '<div class="finance-empty">另有 ' + (data.events.length - 200) + ' 筆事件，請用搜尋縮小範圍。</div>' : '') : '<div class="finance-empty">這個數字目前沒有對應的收款／帳務事件。</div>';
    if ($('[data-fin-drill-orders]')) $('[data-fin-drill-orders]').innerHTML = data.orders.length ? data.orders.slice(0, 200).map(function (order) { return orderCard(order, data.preorder); }).join('') + (data.orders.length > 200 ? '<div class="finance-empty">另有 ' + (data.orders.length - 200) + ' 張訂單，請用搜尋縮小範圍。</div>' : '') : '<div class="finance-empty">這個數字目前沒有對應的訂單。</div>';
  }`;

const RENDER_DRILL_NEW = `    if ($('[data-fin-drill-event-count]')) $('[data-fin-drill-event-count]').textContent = data.events.length ? ('合計 ' + money(eventTotal)) : '沒有收款事件';
    if ($('[data-fin-drill-order-count]')) $('[data-fin-drill-order-count]').textContent = data.orders.length + ' 張';
    var nextDrillKey = String(statDrill || '') + '|' + query();
    if (nextDrillKey !== drillListKey) {
      drillListKey = nextDrillKey;
      drillEventPage = 1;
      drillOrderPage = 1;
    }
    var drillEventHost = $('[data-fin-drill-events]');
    var drillOrderHost = $('[data-fin-drill-orders]');
    setCollapseButton(drillEventHost, 'drill-events', drillEventsCollapsed, '展開事件（' + data.events.length + ' 筆）', '收回事件');
    setCollapseButton(drillOrderHost, 'drill-orders', drillOrdersCollapsed, '展開對應訂單（' + data.orders.length + ' 張）', '收回對應訂單');
    if (drillEventHost) {
      if (!data.events.length) {
        drillEventHost.hidden = false;
        drillEventHost.innerHTML = '<div class="finance-empty">這個數字目前沒有對應的收款／帳務事件。</div>';
      } else if (drillEventsCollapsed) {
        drillEventHost.hidden = true;
        drillEventHost.innerHTML = '';
      } else {
        var pagedEvents = pagedItems(data.events, drillEventPage, LIST_PAGE_SIZE);
        drillEventPage = pagedEvents.page;
        drillEventHost.hidden = false;
        drillEventHost.innerHTML = pagedEvents.items.map(eventCard).join('') + pagerHtml('drill-events', pagedEvents.page, pagedEvents.pages, pagedEvents.total, '筆');
      }
    }
    if (drillOrderHost) {
      if (!data.orders.length) {
        drillOrderHost.hidden = false;
        drillOrderHost.innerHTML = '<div class="finance-empty">這個數字目前沒有對應的訂單。</div>';
      } else if (drillOrdersCollapsed) {
        drillOrderHost.hidden = true;
        drillOrderHost.innerHTML = '';
      } else {
        var pagedDrillOrders = pagedItems(data.orders, drillOrderPage, LIST_PAGE_SIZE);
        drillOrderPage = pagedDrillOrders.page;
        drillOrderHost.hidden = false;
        drillOrderHost.innerHTML = pagedDrillOrders.items.map(function (order) { return orderCard(order, data.preorder); }).join('') + pagerHtml('drill-orders', pagedDrillOrders.page, pagedDrillOrders.pages, pagedDrillOrders.total, '張');
      }
    }
  }`;

const CARD_OLD = `  function customerCard(group, preorder, extraClass) {
    return '<article class="finance-customer ' + (preorder ? 'is-prepaid' : (extraClass || 'is-unpaid')) + '"><header><div><b>' + escapeHtml(group.name) + '</b><span>' + escapeHtml(group.phone || '未填電話') + '</span></div><strong>' + (preorder ? '預收 ' + money(group.paid) : '未收 ' + money(group.balance)) + '</strong></header>' +
      '<small>' + group.orders.length + ' 筆訂單／訂單總額 ' + money(group.total) + '／累計已收 ' + money(group.paid) + (preorder ? '／未出貨待收 ' : '／未收款 ') + money(group.balance) + '</small><small>訂單：' + group.orders.slice(0, 5).map(escapeHtml).join('、') + (group.orders.length > 5 ? '，另 ' + (group.orders.length - 5) + ' 筆' : '') + '</small></article>';
  }`;

const CARD_NEW = `  function customerCard(group, preorder, extraClass, opts) {
    opts = opts || {};
    var key = String(group.key || '');
    var open = !!(opts.expandable && key && chaseExpandedCustomers[key]);
    var toggle = opts.expandable
      ? '<button type="button" class="finance-customer-toggle" data-fin-toggle-customer="' + escapeHtml(key) + '" aria-expanded="' + (open ? 'true' : 'false') + '">' + (open ? '收回' : '展開') + '</button>'
      : '';
    var nested = '';
    if (open) {
      nested = '<div class="finance-customer-orders">' + (group.orderRows && group.orderRows.length ? group.orderRows.map(function (order) { return orderCard(order, preorder); }).join('') : '<div class="finance-empty">沒有可展開的訂單。</div>') + '</div>';
    }
    return '<article class="finance-customer ' + (preorder ? 'is-prepaid' : (extraClass || 'is-unpaid')) + (open ? ' is-open' : '') + '"' + (opts.expandable ? ' data-fin-customer="' + escapeHtml(key) + '"' : '') + '><header><div><b>' + escapeHtml(group.name) + '</b><span>' + escapeHtml(group.phone || '未填電話') + '</span></div><strong>' + (preorder ? '預收 ' + money(group.paid) : '未收 ' + money(group.balance)) + '</strong>' + toggle + '</header>' +
      '<small>' + group.orders.length + ' 筆訂單／訂單總額 ' + money(group.total) + '／累計已收 ' + money(group.paid) + (preorder ? '／未出貨待收 ' : '／未收款 ') + money(group.balance) + '</small><small>訂單：' + group.orders.slice(0, 5).map(escapeHtml).join('、') + (group.orders.length > 5 ? '，另 ' + (group.orders.length - 5) + ' 筆' : '') + '</small>' + nested + '</article>';
  }`;

const CLICK_OLD = `  document.addEventListener('click', function (event) {
    var card = event.target.closest('[data-fin-drill]');
    if (card && !event.target.closest('button, a, input, select, label')) {`;

const CLICK_NEW = `  document.addEventListener('click', function (event) {
    var customerToggle = event.target.closest('[data-fin-toggle-customer]');
    var customerHead = event.target.closest('article.finance-customer[data-fin-customer] header');
    if (customerToggle || (customerHead && !event.target.closest('a, input, select, textarea, button:not([data-fin-toggle-customer])'))) {
      var article = event.target.closest('[data-fin-customer]');
      var key = (customerToggle && customerToggle.getAttribute('data-fin-toggle-customer')) || (article && article.getAttribute('data-fin-customer')) || '';
      if (key) {
        chaseExpandedCustomers[key] = !chaseExpandedCustomers[key];
        render();
        return;
      }
    }
    var card = event.target.closest('[data-fin-drill]');
    if (card && !event.target.closest('button, a, input, select, label')) {`;

const BUTTON_OLD = `    var target = event.target.closest('button');
    if (!target) return;
    if (target.matches('[data-fin-chase-kind]')) {`;

const BUTTON_NEW = `    var target = event.target.closest('button');
    if (!target) return;
    if (target.disabled) return;
    if (target.matches('[data-fin-toggle-customer]')) return;
    if (target.matches('[data-fin-collapse]')) {
      var collapseKind = target.getAttribute('data-fin-collapse') || '';
      if (collapseKind === 'chase-customers') chaseCustomersCollapsed = !chaseCustomersCollapsed;
      else if (collapseKind === 'chase-orders') chaseOrdersCollapsed = !chaseOrdersCollapsed;
      else if (collapseKind === 'drill-events') drillEventsCollapsed = !drillEventsCollapsed;
      else if (collapseKind === 'drill-orders') drillOrdersCollapsed = !drillOrdersCollapsed;
      render();
      return;
    }
    if (target.matches('[data-fin-page-kind]')) {
      var pageKind = target.getAttribute('data-fin-page-kind') || '';
      var nextPage = Number(target.getAttribute('data-fin-page') || 1);
      if (pageKind === 'chase-customers') chaseCustomerPage = nextPage;
      else if (pageKind === 'chase-orders') chaseOrderPage = nextPage;
      else if (pageKind === 'drill-events') drillEventPage = nextPage;
      else if (pageKind === 'drill-orders') drillOrderPage = nextPage;
      render();
      return;
    }
    if (target.matches('[data-fin-chase-kind]')) {`;

const CSS_MARK = "/* 20260820 unpaid expand + 10-per-page */";
const CSS_PATCH = `
    ${CSS_MARK}
    .finance-page-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin:8px 0 2px;grid-column:1/-1}
    .finance-page-bar span{color:var(--fin-muted);font-weight:700}
    .finance-page-btns{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
    .finance-page-btns button{min-width:36px;border:1px solid #927993;background:#493a4d;color:#eadde8;border-radius:9px;padding:8px 10px;font-weight:800;cursor:pointer}
    .finance-page-btns button.is-current{border-color:#ffd06a;color:#ffd06a;background:#614d63}
    .finance-page-btns button:disabled{opacity:.45;cursor:not-allowed}
    .finance-page-gap{color:#eadde8;padding:0 4px}
    .finance-collapse-btn,.finance-customer-toggle{border:1px solid #ffd06a;background:#614d63;color:#ffd06a;border-radius:9px;padding:7px 12px;font-weight:800;cursor:pointer;white-space:nowrap}
    .finance-subhead{flex-wrap:wrap}
    .finance-customer header{align-items:center}
    .finance-customer-toggle{flex:0 0 auto}
    .finance-customer.is-open{grid-column:1/-1}
    .finance-customer-orders{display:grid;gap:8px;margin-top:8px}
    .finance-customer-orders .finance-order{background:#3a2e3d}
`;

function patchJs(src) {
  if (src.indexOf(MARKER) !== -1) {
    console.log("js already patched");
    return src;
  }
  src = replaceOnce(src, STATE_OLD, STATE_NEW, "page/collapse state");
  src = replaceOnce(src, "  function chaseKindLabel(kind) {", HELPERS + "  function chaseKindLabel(kind) {", "pager helpers");
  src = replaceOnce(src, GROUPS_OLD, GROUPS_NEW, "customerGroups keep order rows");
  src = replaceOnce(src, RENDER_CHASE_OLD, RENDER_CHASE_NEW, "chase 10-per-page + collapse");
  src = replaceOnce(src, RENDER_DRILL_OLD, RENDER_DRILL_NEW, "drill 10-per-page + collapse");
  src = replaceOnce(src, CARD_OLD, CARD_NEW, "customer card expand");
  src = replaceOnce(src, CLICK_OLD, CLICK_NEW, "click expand customer");
  src = replaceOnce(src, BUTTON_OLD, BUTTON_NEW, "pager/collapse buttons");
  return src;
}

function patchHtml(html) {
  let next = html;
  if (next.indexOf(CSS_MARK) === -1) {
    const needle = "    .finance-chase-toolbar select{min-width:160px;padding:10px;border-radius:9px;border:1px solid #8c728d;background:#443747;color:#fff}\n  </style>";
    if (next.indexOf(needle) === -1) throw new Error("missing finance css close");
    next = next.split(needle).join(needle.replace("  </style>", CSS_PATCH + "  </style>"));
    console.log("patched: finance unpaid page css");
  } else {
    console.log("already: finance unpaid page css");
  }
  const stamped = next.replace(/finance\.js(?:\?v=[^"']+)?/g, "finance.js?v=" + STAMP);
  if (stamped !== next) console.log("stamped finance.js", STAMP);
  return stamped;
}

if (!fs.existsSync(JS) || !fs.existsSync(HTML)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "unpaid-page"));
console.log("backup html", backup(HTML, "unpaid-page"));

let src = fs.readFileSync(JS, "utf8");
src = patchJs(src);
if (src.indexOf(MARKER) === -1) throw new Error("LIST_PAGE_SIZE missing after patch");
if (src.indexOf("data-fin-toggle-customer") === -1) throw new Error("expand toggle missing");
if (src.indexOf("slice(0, 200)") !== -1 && src.indexOf("pagerHtml('drill-orders'") === -1) {
  throw new Error("drill still capped at 200 without pager");
}
fs.writeFileSync(JS, src, "utf8");
console.log("js written", JS, "len", src.length);

let html = fs.readFileSync(HTML, "utf8");
html = patchHtml(html);
if (html.indexOf("\uFFFD") !== -1) throw new Error("finance html has FFFD");
fs.writeFileSync(HTML, html, "utf8");
console.log("html written", HTML, "len", html.length);
console.log("LINGZANZAN finance unpaid page ok", STAMP);
