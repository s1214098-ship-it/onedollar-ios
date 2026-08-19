'use strict';

/**
 * Honor order.trackingOptional / trackingOptionalReason so a 全家/7-11 order
 * can stay without a tracking number when the manager says 不建單號.
 *
 * Cache-bust: admin.js?v=20260819-nita-no-tracking-1
 */

const fs = require('fs');
const path = require('path');

const root = process.env.LINGZANZAN_ROOT || 'F:/Web/lingzanzan-staging';
const BUST = '20260819-nita-no-tracking-1';
const PREV = '20260819-post-ruzhang-1';
const adminJs = path.join(root, 'assets', 'admin.js');
const api = path.join(root, 'order-admin-api-v6.php');

function backup(file, tag) {
  const dir = path.join(root, 'data', 'audit');
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  const dest = path.join(dir, path.basename(file) + '.' + tag + '-' + new Date().toISOString().replace(/[:.]/g, '-'));
  fs.copyFileSync(file, dest);
  return dest;
}

function replaceOnce(src, oldStr, newStr, label) {
  if (src.indexOf(newStr) !== -1) {
    console.log('already:', label);
    return src;
  }
  const i = src.indexOf(oldStr);
  if (i < 0) throw new Error('missing snippet: ' + label);
  if (src.indexOf(oldStr, i + oldStr.length) !== -1) throw new Error('not unique: ' + label);
  console.log('patched:', label);
  return src.slice(0, i) + newStr + src.slice(i + oldStr.length);
}

const OLD_OPTIONAL = `  function adminOrderTrackingOptional(order, carrier) {
    return adminShippingCarrierTrackingOptional(carrier || order && order.shippingCarrier)
      || adminOrderIsIndonesia(order);
  }`;

const NEW_OPTIONAL = `  function adminOrderTrackingOptional(order, carrier) {
    if (order && (order.trackingOptional === true || String(order.trackingOptionalReason || '').trim())) {
      return true;
    }
    return adminShippingCarrierTrackingOptional(carrier || order && order.shippingCarrier)
      || adminOrderIsIndonesia(order);
  }`;

const OLD_FIFO_CHECK = `      if (!carrier) checks.push('待選物流');
      if (!trackingNo && !trackingOptional) checks.push('待補物流單號');
      checks.push('核對收款方式');`;

const NEW_FIFO_CHECK = `      if (!carrier) checks.push('待選物流');
      if (!trackingNo && !trackingOptional) checks.push('待補物流單號');
      if (!trackingNo && trackingOptional) checks.push(adminTrackingOptionalLabel(carrier, order) || '不建物流單號');
      checks.push('核對收款方式');`;

const OLD_LABEL = `  function adminTrackingOptionalLabel(carrier, order) {
    var value = String(carrier || order && order.shippingCarrier || '').trim();
    if (/自取|不需物流/i.test(value)) return '自取不需物流單號';
    if (/印尼業務自行出貨/i.test(value) || adminOrderIsIndonesia(order)) return '印尼業務自行出貨不需物流單號';
    return '';
  }`;

const NEW_LABEL = `  function adminTrackingOptionalLabel(carrier, order) {
    var reason = String(order && order.trackingOptionalReason || '').trim();
    if (reason) return reason;
    var value = String(carrier || order && order.shippingCarrier || '').trim();
    if (/自取|不需物流/i.test(value)) return '自取不需物流單號';
    if (/印尼業務自行出貨/i.test(value) || adminOrderIsIndonesia(order)) return '印尼業務自行出貨不需物流單號';
    if (order && order.trackingOptional === true) return '管理者指定不建物流單號';
    return '';
  }`;

const OLD_PHP = `    $indonesiaRoute = $destinationWarehouseCode === 'ID'
        || strtoupper(trim((string)($orders[$index]['preorderFulfillWarehouse'] ?? ''))) === 'ID';
    $trackingOptionalCarrier = preg_match('/郵局|新竹|大榮|便利帶|宅配|住家|自取|面交|印尼/u', (string)$orders[$index]['shippingCarrier']) === 1;
    if ($status === 'shipped' && $orders[$index]['trackingNo'] === '' && !$indonesiaRoute && !$trackingOptionalCarrier) {
        respond(['ok' => false, 'error' => '出貨需要填物流單號'], 400);
    }`;

const NEW_PHP = `    $indonesiaRoute = $destinationWarehouseCode === 'ID'
        || strtoupper(trim((string)($orders[$index]['preorderFulfillWarehouse'] ?? ''))) === 'ID';
    $trackingOptionalCarrier = preg_match('/郵局|新竹|大榮|便利帶|宅配|住家|自取|面交|印尼/u', (string)$orders[$index]['shippingCarrier']) === 1;
    $trackingOptionalFlag = !empty($orders[$index]['trackingOptional'])
        || trim((string)($orders[$index]['trackingOptionalReason'] ?? '')) !== '';
    if ($status === 'shipped' && $orders[$index]['trackingNo'] === '' && !$indonesiaRoute && !$trackingOptionalCarrier && !$trackingOptionalFlag) {
        respond(['ok' => false, 'error' => '出貨需要填物流單號'], 400);
    }`;

if (!fs.existsSync(adminJs) || !fs.existsSync(api)) {
  console.log('Not on PHT-SR');
  process.exit(0);
}

console.log('backup js', backup(adminJs, 'nita-optional'));
let src = fs.readFileSync(adminJs, 'utf8');
src = replaceOnce(src, OLD_OPTIONAL, NEW_OPTIONAL, 'adminOrderTrackingOptional honors flag');
src = replaceOnce(src, OLD_LABEL, NEW_LABEL, 'adminTrackingOptionalLabel shows reason');
src = replaceOnce(src, OLD_FIFO_CHECK, NEW_FIFO_CHECK, 'FIFO card shows 不建物流單號');
fs.writeFileSync(adminJs, src);

console.log('backup php', backup(api, 'nita-optional'));
let php = fs.readFileSync(api, 'utf8');
php = replaceOnce(php, OLD_PHP, NEW_PHP, 'update-status empty tracking honors flag');
fs.writeFileSync(api, php);

const names = fs.readdirSync(root).filter(function (name) {
  return /\.(html|php)$/i.test(name) && !/_backups|codex-backup/i.test(name);
});
let n = 0;
names.forEach(function (name) {
  const file = path.join(root, name);
  let html = fs.readFileSync(file, 'utf8');
  const orig = html;
  html = html.split('admin.js?v=' + PREV).join('admin.js?v=' + BUST);
  if (html !== orig) {
    fs.writeFileSync(file, html);
    n++;
    console.log('cache-bust', name);
  }
});
console.log('html files busted', n, BUST);
