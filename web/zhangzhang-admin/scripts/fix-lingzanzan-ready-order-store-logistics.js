#!/usr/bin/env node
"use strict";

/**
 * 現貨打單：客戶圖片不要蓋住超商／物流欄；補物流單號；
 * 搜電話若只有客戶檔、沒有出貨單，要明白寫出來。
 * 不改 .is-active 金鈕。不 invent 商品。不自動掛 E32336500671。
 *
 * Cache-bust: admin.js / admin.css ?v=20260822-ready-store-1
 */

const fs = require("fs");
const path = require("path");
const {
  readyOrderStoreFieldsAreMounted,
  readyOrderTrackingGoesOnCreate,
  readyOrderPhoneSearchShowsCustomerOnly,
  readyOrderStoreCssKeepsLogisticsVisible,
  readyOrderCartApiKeepsTracking,
  readyOrderStoreDoesNotRestyleActive,
} = require("./lz-ready-order-store-logistics");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const ADMIN_JS = path.join(ROOT, "assets", "admin.js");
const ADMIN_CSS = path.join(ROOT, "assets", "admin.css");
const CART_PHP = path.join(ROOT, "cart-order-api.php");
const STAMP = "20260822-ready-store-1";
const CSS_MARKER = "/* 20260822 ready-order store logistics: keep 超商/物流 selectable */";

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
  const names = ["admin-orders.html"];
  let n = 0;
  names.forEach(function (name) {
    const file = path.join(dir, name);
    if (!fs.existsSync(file)) return;
    const html = fs.readFileSync(file, "latin1");
    if (html.indexOf("admin.js") === -1) return;
    const next = html
      .replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP)
      .replace(/admin\.css(?:\?v=[^"']+)?/g, "admin.css?v=" + STAMP);
    if (next === html) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

const CSS_PATCH = `
${CSS_MARKER}
.admin-shipment-grid .admin-shipment-customer-photo {
  grid-column: 1 / -1 !important;
  grid-row: auto !important;
  position: static !important;
  z-index: 0;
}
.admin-shipment-grid .customer-shipping-addresses,
.admin-shipment-grid .order-customer-save-bar {
  grid-column: 1 / -1 !important;
  position: relative;
  z-index: 2;
}
.admin-shipment-grid [data-admin-shipment-carrier],
.admin-shipment-grid [data-admin-shipment-tracking],
.admin-shipment-grid [data-admin-shipment-delivery] {
  min-height: 44px;
}
.admin-ready-customer-only {
  display: grid;
  gap: 10px;
  margin: 12px 0;
  padding: 14px 16px;
  border: 1px solid #8d7443;
  border-radius: 16px;
  background: #2a2218;
  color: #fff8ed;
}
.admin-ready-customer-only > b {
  font-size: 18px;
  color: #f4bd4d;
}
.admin-ready-customer-only > p,
.admin-ready-customer-only article span {
  margin: 0;
  color: #d8cbd6;
  line-height: 1.5;
}
.admin-ready-customer-only article {
  display: grid;
  gap: 6px;
  padding: 10px 0 0;
  border-top: 1px solid rgba(255,248,237,.12);
}
.admin-ready-customer-only article strong {
  font-size: 16px;
}
`;

const HELPERS_OLD = `    host.parentNode.replaceChild(wrap.firstElementChild, host);
  }

  function ensureAdminShipmentPanel() {`;

const HELPERS_NEW = `    host.parentNode.replaceChild(wrap.firstElementChild, host);
  }

  function ensureAdminShipmentPhotoPlacement() {
    var form = document.querySelector('[data-admin-shipment-form]');
    var grid = form && form.querySelector('.admin-shipment-grid');
    var photo = form && form.querySelector('[data-admin-shipment-customer-dropzone]');
    if (!form || !grid || !photo) return;
    if (photo.parentElement === grid) form.insertBefore(photo, grid);
  }

  function ensureAdminShipmentTrackingField() {
    var form = document.querySelector('[data-admin-shipment-form]');
    if (!form || form.querySelector('[data-admin-shipment-tracking]')) return;
    var carrier = form.querySelector('[data-admin-shipment-carrier]');
    var host = carrier && (carrier.closest('label') || carrier.parentElement);
    if (!host || !host.parentNode) return;
    var label = document.createElement('label');
    label.innerHTML = '物流單號<input name="trackingNo" data-admin-shipment-tracking placeholder="7-11／全家／郵局單號，可先空白後補" autocomplete="off" spellcheck="false">';
    host.parentNode.insertBefore(label, host.nextSibling);
  }

  function ensureAdminShipmentSaveHint() {
    var bar = document.querySelector('[data-order-customer-save-bar="admin-shipment"] small');
    if (!bar) return;
    if (String(bar.textContent || '').indexOf('不會產生訂單') !== -1) return;
    bar.textContent = '只存客戶檔，不會產生訂單。要找得到訂單，請先帶入商品再按「建立出貨單並轉現貨訂單」。';
  }

  function adminReadyOrderCustomerRows() {
    var rows = state.customers;
    if (Array.isArray(rows)) return rows;
    if (rows && Array.isArray(rows.customers)) return rows.customers;
    if (rows && Array.isArray(rows.items)) return rows.items;
    return [];
  }

  function adminReadyOrderCustomerSearchHits(query) {
    query = String(query || '').trim();
    if (!query) return [];
    var digits = query.replace(/\\D/g, '');
    var phoneQuery = digits.length >= 9 ? digits : '';
    if (phoneQuery && typeof freightQueryLooksLikePhone === 'function' && !freightQueryLooksLikePhone(digits)) phoneQuery = '';
    return adminReadyOrderCustomerRows().filter(function (row) {
      if (!row) return false;
      var nested = row.customer && typeof row.customer === 'object' ? row.customer : null;
      var phone = String((nested && nested.phone) || row.phone || '').replace(/\\D/g, '');
      if (phoneQuery) {
        return phone === phoneQuery
          || phone === phoneQuery.replace(/^0/, '')
          || ('0' + phone) === phoneQuery
          || (phone.length >= 9 && phoneQuery.length >= 9 && phone.slice(-9) === phoneQuery.slice(-9));
      }
      var hay = [
        row.name, nested && nested.name,
        row.address, nested && nested.address,
        row.storeAddress, nested && nested.storeAddress
      ].join(' ').toLowerCase();
      return hay.indexOf(query.toLowerCase()) !== -1;
    }).slice(0, 8);
  }

  function adminReadyOrderCustomerOnlyHtml(query) {
    var hits = adminReadyOrderCustomerSearchHits(query);
    if (!hits.length) {
      return '<div class="empty">目前沒有符合條件的訂單。若剛按「儲存客戶資料」，那只會留下客戶檔，還不會出現訂單；請先帶入商品再按「建立出貨單並轉現貨訂單」。</div>';
    }
    return '<div class="admin-ready-customer-only"><b>只有客戶資料，還沒有現貨出貨單</b><p>剛才存的是客戶檔，不是出貨單。請帶入客戶、選超商／物流公司／物流單號、加入商品後再按「建立出貨單並轉現貨訂單」。</p>' + hits.map(function (row) {
      var nested = row.customer && typeof row.customer === 'object' ? row.customer : {};
      var name = String(nested.name || row.name || '未填姓名');
      var phone = String(nested.phone || row.phone || '');
      var store = String(nested.storeAddress || row.storeAddress || nested.address || row.address || '');
      return '<article><strong>' + escapeHtml(name) + '</strong><span>' + escapeHtml(phone) + (store ? '／' + escapeHtml(store) : '') + '</span><button type="button" class="ghost-button" data-admin-ready-fill-customer="' + escapeHtml(phone) + '">帶入這筆客戶繼續打單</button></article>';
    }).join('') + '</div>';
  }

  function fillAdminReadyOrderCustomer(phone) {
    var hit = adminReadyOrderCustomerSearchHits(phone)[0];
    if (!hit) { toast('沒有這筆已存客戶'); return; }
    var nested = hit.customer && typeof hit.customer === 'object' ? hit.customer : {};
    var draft = {
      name: nested.name || hit.name || '',
      phone: nested.phone || hit.phone || phone || '',
      address: nested.storeAddress || hit.storeAddress || nested.address || hit.address || '',
      storeAddress: nested.storeAddress || hit.storeAddress || nested.address || hit.address || '',
      homeAddress: nested.homeAddress || hit.homeAddress || '',
      addressPrimary: nested.addressPrimary || hit.addressPrimary || nested.deliveryType || hit.deliveryType || 'store',
      deliveryType: nested.deliveryType || hit.deliveryType || 'store',
      shippingFee: nested.shippingFee || hit.shippingFee || 70
    };
    ensureAdminShipmentPanel();
    applyAdminOrderCustomerDraft(draft);
    var form = document.querySelector('[data-admin-shipment-form]');
    fillCustomerShippingAddressFields(form || document, draft);
    if (typeof applyAdminShipmentShippingFee === 'function') applyAdminShipmentShippingFee();
    toast('已帶入客戶。請選超商／物流並加入商品後建立出貨單');
    if (form && form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function ensureAdminShipmentPanel() {`;

const CALLS_OLD = `    if (document.querySelector('[data-admin-shipment-panel]')) {
      ensureAdminShipmentAddressFields();
      ensureAdminShipmentPostDeliveryOption();`;

const CALLS_NEW = `    if (document.querySelector('[data-admin-shipment-panel]')) {
      ensureAdminShipmentPhotoPlacement();
      ensureAdminShipmentAddressFields();
      ensureAdminShipmentTrackingField();
      ensureAdminShipmentSaveHint();
      ensureAdminShipmentPostDeliveryOption();`;

const EMPTY_OLD = `    list.innerHTML = adminMergeReminderHtml(orders) + (orders.map(renderOfficialOrderCard).join('') || (preorderRows.length ? '' : '<div class="empty">目前沒有符合條件的訂單。</div>'));`;

const EMPTY_NEW = `    list.innerHTML = adminMergeReminderHtml(orders) + (orders.map(renderOfficialOrderCard).join('') || (preorderRows.length ? '' : adminReadyOrderCustomerOnlyHtml(query)));`;

const CLICK_OLD = `      var loadButton = event.target.closest && event.target.closest('[data-order-customer-load]');
      if (loadButton) {`;

const CLICK_NEW = `      var fillReadyCustomer = event.target.closest && event.target.closest('[data-admin-ready-fill-customer]');
      if (fillReadyCustomer) {
        event.preventDefault();
        fillAdminReadyOrderCustomer(fillReadyCustomer.getAttribute('data-admin-ready-fill-customer'));
        return;
      }
      var loadButton = event.target.closest && event.target.closest('[data-order-customer-load]');
      if (loadButton) {`;

const PAYLOAD_VARS_OLD = `    var payload = {
      source: 'admin-shipment',`;

const PAYLOAD_VARS_NEW = `    var trackingNo = String(fd.get('trackingNo') || (form.querySelector('[data-admin-shipment-tracking]') || {}).value || '').replace(/\\s+/g, '');
    var outboundParcels = trackingNo ? [{ trackingNo: trackingNo, carrier: String(fd.get('shippingCarrier') || ''), amount: '', note: '' }] : [];
    var payload = {
      source: 'admin-shipment',`;

const PAYLOAD_FIELDS_OLD = `      shippingCarrier: String(fd.get('shippingCarrier') || ''),
      items: adminShipmentItems.map(function (item) { return Object.assign({}, item); }),`;

const PAYLOAD_FIELDS_NEW = `      shippingCarrier: String(fd.get('shippingCarrier') || ''),
      trackingNo: trackingNo,
      shippingTrackingNo: trackingNo,
      outboundParcels: trackingNo ? outboundParcels : [],
      items: adminShipmentItems.map(function (item) { return Object.assign({}, item); }),`;

const PHP_OLD = `    'shippingCarrier' => trim((string)($payload['shippingCarrier'] ?? ($customer['shippingCarrier'] ?? ''))),
    'customer' => $orderCustomer,`;

const PHP_NEW = `    'shippingCarrier' => trim((string)($payload['shippingCarrier'] ?? ($customer['shippingCarrier'] ?? ''))),
    'trackingNo' => strtoupper(preg_replace('/\\s+/', '', (string)($payload['trackingNo'] ?? ($payload['shippingTrackingNo'] ?? '')))),
    'shippingTrackingNo' => strtoupper(preg_replace('/\\s+/', '', (string)($payload['shippingTrackingNo'] ?? ($payload['trackingNo'] ?? '')))),
    'outboundParcels' => is_array($payload['outboundParcels'] ?? null) ? $payload['outboundParcels'] : [],
    'customer' => $orderCustomer,`;

if (CSS_PATCH.indexOf(".is-active") !== -1) throw new Error("refusing to restyle .is-active");
if (!readyOrderStoreDoesNotRestyleActive(HELPERS_NEW + CSS_PATCH + EMPTY_NEW)) {
  throw new Error("refusing to restyle .is-active");
}

if (!fs.existsSync(ADMIN_JS) || !fs.existsSync(ADMIN_CSS)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

backup(ADMIN_JS, "ready-store");
backup(ADMIN_CSS, "ready-store");
if (fs.existsSync(CART_PHP)) backup(CART_PHP, "ready-store");

let js = fs.readFileSync(ADMIN_JS, "utf8");
js = replaceOnce(js, HELPERS_OLD, HELPERS_NEW, "store/logistics helpers");
js = replaceOnce(js, CALLS_OLD, CALLS_NEW, "call store/logistics mounts");
js = replaceOnce(js, EMPTY_OLD, EMPTY_NEW, "phone search shows customer-only");
js = replaceOnce(js, CLICK_OLD, CLICK_NEW, "fill saved customer from search");
js = replaceOnce(js, PAYLOAD_VARS_OLD, PAYLOAD_VARS_NEW, "read tracking before payload");
js = replaceOnce(js, PAYLOAD_FIELDS_OLD, PAYLOAD_FIELDS_NEW, "put tracking on create payload");
fs.writeFileSync(ADMIN_JS, js);

let css = fs.readFileSync(ADMIN_CSS, "utf8");
if (css.indexOf(CSS_MARKER) === -1) {
  css = css.replace(/\s*$/, "") + "\n" + CSS_PATCH;
  console.log("patched: store logistics css");
} else {
  console.log("already: store logistics css");
}
fs.writeFileSync(ADMIN_CSS, css);

if (fs.existsSync(CART_PHP)) {
  let php = fs.readFileSync(CART_PHP, "utf8");
  php = replaceOnce(php, PHP_OLD, PHP_NEW, "cart-order-api keeps tracking");
  fs.writeFileSync(CART_PHP, php);
  if (!readyOrderCartApiKeepsTracking(php)) throw new Error("cart-order-api tracking missing after patch");
}

if (!readyOrderStoreFieldsAreMounted(js)) throw new Error("store fields missing after js patch");
if (!readyOrderTrackingGoesOnCreate(js)) throw new Error("tracking payload missing after js patch");
if (!readyOrderPhoneSearchShowsCustomerOnly(js)) throw new Error("customer-only search missing after js patch");
if (!readyOrderStoreCssKeepsLogisticsVisible(css)) throw new Error("store logistics css missing after patch");

stampHtml(ROOT);
console.log("LINGZANZAN ready order store logistics ok", STAMP);
