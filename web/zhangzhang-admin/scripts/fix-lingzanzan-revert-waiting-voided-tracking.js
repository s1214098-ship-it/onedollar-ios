#!/usr/bin/env node
"use strict";

/**
 * 第 4 區改回待配貨：核對單若還留著已刪除的超商單號，
 * 填原因後允許改回，並把單號從正式單／來源單清掉。
 * 已交寄／配送中仍不可用。
 *
 * Cache-bust: admin.js ?v=20260821-revert-waiting-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "order-admin-api-v6.php");
const STAMP = "20260821-revert-waiting-1";
const JS_MARKER = "data-revert-waiting-reason";
const PHP_MARKER = "若已刪除或作廢，請填原因後再改回待配貨";

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
  if (src.indexOf(newStr) !== -1) {
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

const PHP_OLD = `    if (trim((string)($order['trackingNo'] ?? '')) !== '') {
        respond(['ok' => false, 'error' => '已有出貨物流單號，不能改回待配貨。請先作廢單號，或改用取消客戶／整單回庫'], 409);
    }
    $inquiryId = '';`;

const PHP_NEW = `    $reason = trim((string)($payload['reason'] ?? ($payload['note'] ?? '')));
    $leftoverTracking = trim((string)($order['trackingNo'] ?? ''));
    if ($leftoverTracking === '') {
        foreach (is_array($order['outboundParcels'] ?? null) ? $order['outboundParcels'] : [] as $parcel) {
            if (!is_array($parcel)) continue;
            $leftoverTracking = trim((string)($parcel['trackingNo'] ?? ''));
            if ($leftoverTracking !== '') break;
        }
    }
    if ($leftoverTracking !== '' && $reason === '') {
        respond(['ok' => false, 'error' => '這張還留有超商／物流單號 ' . $leftoverTracking . '。若已刪除或作廢，請填原因後再改回待配貨'], 409);
    }
    $inquiryId = '';`;

const PHP_ARCHIVE_OLD = `    $order['revertedToWaitingAt'] = $now;
    $order['revertedToWaitingBy'] = $operator;
    $order['revertedFromOrderId'] = $originalOrderId;
    $order['revertedToInquiryId'] = $inquiryId;
    $order['waitNotify'] = false;
    $order['updatedAt'] = $now;
    $orders[$index] = $order;

    $inquiry = reopen_inquiry_for_waiting_queue($inquiries[$inquiryIndex], [
        'id' => $originalOrderId,
    ], $operator, $now);
    $inquiries[$inquiryIndex] = $inquiry;`;

const PHP_ARCHIVE_NEW = `    $order['revertedToWaitingAt'] = $now;
    $order['revertedToWaitingBy'] = $operator;
    $order['revertedFromOrderId'] = $originalOrderId;
    $order['revertedToInquiryId'] = $inquiryId;
    $order['revertedToWaitingReason'] = $reason;
    if ($leftoverTracking !== '') {
        $order['voidedTrackingNo'] = $leftoverTracking;
        $order['voidedTrackingReason'] = $reason;
        $order['trackingVoidedAt'] = $now;
        $order['trackingNo'] = '';
        $order['outboundParcels'] = [];
    }
    $order['waitNotify'] = false;
    $order['updatedAt'] = $now;
    $orders[$index] = $order;

    $inquiry = reopen_inquiry_for_waiting_queue($inquiries[$inquiryIndex], [
        'id' => $originalOrderId,
    ], $operator, $now);
    if ($reason !== '') $inquiry['revertedToWaitingReason'] = $reason;
    if ($leftoverTracking !== '') {
        $inquiry['voidedTrackingNo'] = $leftoverTracking;
        $inquiry['voidedTrackingReason'] = $reason;
        $inquiry['trackingNo'] = '';
        $inquiry['outboundParcels'] = [];
    }
    $inquiries[$inquiryIndex] = $inquiry;`;

const JS_MODAL_OLD = `    var order = (state.orders || []).find(function (row) { return String(row && row.id || '') === orderId; }) || {};
    var customer = order.customer || {};
    var existing = document.querySelector('[data-freight-fifo-revert-waiting-modal]');
    if (existing) existing.remove();
    var modal = document.createElement('div');
    modal.className = 'freight-formal-priority-modal';
    modal.setAttribute('data-freight-fifo-revert-waiting-modal', orderId);
    modal.innerHTML = '<section role="dialog" aria-modal="true" aria-labelledby="revert-waiting-title">'
      + '<header><div><small>BACK TO WAITING／改回待配貨</small><h3 id="revert-waiting-title">把 ' + escapeHtml(customer.name || order.customerName || '這位客戶') + ' 改回第 1 區待配貨</h3><p>' + escapeHtml(orderId) + '。這不是取消客人，也不算退貨。</p></div><button type="button" data-revert-waiting-close>關閉</button></header>'
      + '<ul class="freight-cancel-order-impact"><li><b>正式出貨單</b><strong>離開第 4 區核對</strong></li><li><b>台灣庫存</b><strong>退回，可供下一位先出</strong></li><li><b>這位客人</b><strong>依原下單時間回到待配貨排隊</strong></li></ul>'
      + '<p class="freight-fifo-return-score-hint">之後若還要出給她，再到第 1 區待配貨重新打正式單。已交寄或已有出貨單號的不能用這顆。</p>'
      + '<label class="freight-cancel-order-confirm"><input type="checkbox" data-revert-waiting-check><span>我已確認改回待配貨，並把這件貨釋出給其他人先配</span></label>'
      + '<footer><button type="button" class="ghost-button" data-revert-waiting-close>留在核對區</button><button type="button" class="primary-button" data-revert-waiting-confirm disabled>確認改回待配貨</button><span data-revert-waiting-status>勾選確認後才可送出</span></footer></section>';
    document.body.appendChild(modal);
    var checkbox = modal.querySelector('[data-revert-waiting-check]');
    var confirmButton = modal.querySelector('[data-revert-waiting-confirm]');
    var status = modal.querySelector('[data-revert-waiting-status]');
    var close = function () { if (modal.parentNode) modal.remove(); };
    modal.querySelectorAll('[data-revert-waiting-close]').forEach(function (button) { button.addEventListener('click', close); });
    checkbox.addEventListener('change', function () { confirmButton.disabled = !checkbox.checked; });
    confirmButton.addEventListener('click', function () {
      if (!checkbox.checked || confirmButton.disabled) return;
      confirmButton.disabled = true;
      status.textContent = '正在改回待配貨並退回台灣庫存…';
      if (sourceButton) {
        sourceButton.disabled = true;
        sourceButton.textContent = '改回中…';
      }
      callOrderAdmin({
        action: 'revert-formal-to-waiting',
        orderId: orderId,
        operator: currentLogin().name || currentLogin().account || '管理者'
      }).then(function (result) {`;

const JS_MODAL_NEW = `    var order = (state.orders || []).find(function (row) { return String(row && row.id || '') === orderId; }) || {};
    var customer = order.customer || {};
    var leftoverTracking = String(order.trackingNo || '').trim();
    if (!leftoverTracking && Array.isArray(order.outboundParcels)) {
      (order.outboundParcels || []).some(function (parcel) {
        leftoverTracking = String(parcel && parcel.trackingNo || '').trim();
        return !!leftoverTracking;
      });
    }
    var existing = document.querySelector('[data-freight-fifo-revert-waiting-modal]');
    if (existing) existing.remove();
    var modal = document.createElement('div');
    modal.className = 'freight-formal-priority-modal';
    modal.setAttribute('data-freight-fifo-revert-waiting-modal', orderId);
    modal.innerHTML = '<section role="dialog" aria-modal="true" aria-labelledby="revert-waiting-title">'
      + '<header><div><small>BACK TO WAITING／改回待配貨</small><h3 id="revert-waiting-title">把 ' + escapeHtml(customer.name || order.customerName || '這位客戶') + ' 改回第 1 區待配貨</h3><p>' + escapeHtml(orderId) + '。這不是取消客人，也不算退貨。</p></div><button type="button" data-revert-waiting-close>關閉</button></header>'
      + '<ul class="freight-cancel-order-impact"><li><b>正式出貨單</b><strong>離開第 4 區核對</strong></li><li><b>台灣庫存</b><strong>退回，可供下一位先出</strong></li><li><b>這位客人</b><strong>依原下單時間回到待配貨排隊</strong></li></ul>'
      + (leftoverTracking
        ? '<div class="freight-formal-priority-warning"><b>資料還留有超商／物流單號 ' + escapeHtml(leftoverTracking) + '</b><span>若已在超商系統刪除或作廢，填原因後即可改回待配貨。系統會把這組單號從核對單清掉，不會代替超商作廢。</span></div>'
        : '')
      + '<p class="freight-fifo-return-score-hint">之後若還要出給她，再到第 1 區待配貨重新打正式單。已交寄／配送中不能用這顆。核對區若還留著已刪除的超商單號，填原因後可以改回待配貨。</p>'
      + (leftoverTracking
        ? '<label class="freight-pending-priority-reason"><span>已刪除／作廢超商單號的原因（必填）</span><input type="text" data-revert-waiting-reason maxlength="200" placeholder="例如：已刪除 7-11 寄件編號，改回待配貨"></label>'
        : '<label class="freight-pending-priority-reason"><span>改回原因（選填）</span><input type="text" data-revert-waiting-reason maxlength="200" placeholder="例如：先讓其他客人出"></label>')
      + '<label class="freight-cancel-order-confirm"><input type="checkbox" data-revert-waiting-check><span>我已確認改回待配貨，並把這件貨釋出給其他人先配</span></label>'
      + '<footer><button type="button" class="ghost-button" data-revert-waiting-close>留在核對區</button><button type="button" class="primary-button" data-revert-waiting-confirm disabled>確認改回待配貨</button><span data-revert-waiting-status>' + (leftoverTracking ? '請填原因並勾選確認後才可送出' : '勾選確認後才可送出') + '</span></footer></section>';
    document.body.appendChild(modal);
    var checkbox = modal.querySelector('[data-revert-waiting-check]');
    var reasonInput = modal.querySelector('[data-revert-waiting-reason]');
    var confirmButton = modal.querySelector('[data-revert-waiting-confirm]');
    var status = modal.querySelector('[data-revert-waiting-status]');
    var close = function () { if (modal.parentNode) modal.remove(); };
    var revertReasonValue = function () {
      return String((reasonInput && reasonInput.value) || '').trim();
    };
    var updateRevertReady = function () {
      var reason = revertReasonValue();
      var needReason = !!leftoverTracking;
      var ready = checkbox.checked && (!needReason || reason.length >= 2);
      confirmButton.disabled = !ready;
      status.textContent = !checkbox.checked
        ? (needReason ? '請填原因並勾選確認後才可送出' : '勾選確認後才可送出')
        : (needReason && reason.length < 2 ? '這張還留有超商單號，請填已刪除／作廢原因' : '可以送出');
    };
    modal.querySelectorAll('[data-revert-waiting-close]').forEach(function (button) { button.addEventListener('click', close); });
    checkbox.addEventListener('change', updateRevertReady);
    if (reasonInput) reasonInput.addEventListener('input', updateRevertReady);
    confirmButton.addEventListener('click', function () {
      var reason = revertReasonValue();
      if (!checkbox.checked || confirmButton.disabled) return;
      if (leftoverTracking && reason.length < 2) {
        status.textContent = '這張還留有超商單號，請填已刪除／作廢原因';
        return;
      }
      confirmButton.disabled = true;
      status.textContent = '正在改回待配貨並退回台灣庫存…';
      if (sourceButton) {
        sourceButton.disabled = true;
        sourceButton.textContent = '改回中…';
      }
      callOrderAdmin({
        action: 'revert-formal-to-waiting',
        orderId: orderId,
        reason: reason,
        operator: currentLogin().name || currentLogin().account || '管理者'
      }).then(function (result) {`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  names.forEach((name) => {
    const file = path.join(dir, name);
    if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return;
    const page = fs.readFileSync(file, "latin1");
    if (page.indexOf("admin.js") === -1) return;
    const next = page.replace(/admin\.js(?:\?v=[^"']+)?/g, "admin.js?v=" + STAMP);
    if (next === page) return;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n += 1;
    console.log("stamped", name);
  });
  console.log("html stamped", n);
}

if (!fs.existsSync(JS) || !fs.existsSync(PHP)) {
  console.log("Not on PHT-SR");
  process.exit(0);
}

console.log("backup js", backup(JS, "revert-waiting"));
let js = fs.readFileSync(JS, "utf8");
js = replaceOnce(js, JS_MODAL_OLD, JS_MODAL_NEW, "revert waiting reason field");
try {
  new Function(js);
  console.log("js syntax ok");
} catch (error) {
  throw new Error("admin.js syntax: " + error.message);
}
fs.writeFileSync(JS, js);
console.log("admin.js written", js.length);

console.log("backup php", backup(PHP, "revert-waiting"));
let php = fs.readFileSync(PHP, "utf8");
php = replaceOnce(php, PHP_OLD, PHP_NEW, "php leftover tracking needs reason");
php = replaceOnce(php, PHP_ARCHIVE_OLD, PHP_ARCHIVE_NEW, "php void leftover tracking");
fs.writeFileSync(PHP, php);

stampHtml(ROOT);
if (js.indexOf(JS_MARKER) === -1) throw new Error("reason field missing");
if (php.indexOf(PHP_MARKER) === -1) throw new Error("php reason gate missing");
console.log("done", STAMP);
