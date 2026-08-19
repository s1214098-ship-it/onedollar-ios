#!/usr/bin/env node
"use strict";

/**
 * 出貨核對儲存客戶資料時，若物流單號已被已完成配送的舊單占用，
 * 前端會默默把單號丟掉再存空值。改成明確擋下；空單號也不再覆寫已有單號。
 *
 * Cache-bust: admin.js ?v=20260819-tracking-keep-1
 */

const fs = require("fs");
const path = require("path");

const ROOT = process.env.LINGZANZAN_ROOT || "F:/Web/lingzanzan-staging";
const JS = path.join(ROOT, "assets", "admin.js");
const PHP = path.join(ROOT, "order-admin-api-v6.php");
const STAMP = "20260819-tracking-keep-1";
const JS_MARKER = "function freightFifoClosedTrackingSaveError(";
const PHP_MARKER = "客戶資料儲存若沒帶單號，不可把已有物流單號清掉";

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

const USABLE_OLD = `  function freightFifoUsableOutboundTracking(trackingNo, exceptOrderId) {
    if (freightFifoTrackingOwnedByClosedShipment(trackingNo, exceptOrderId)) return '';
    return String(trackingNo || '').trim();
  }`;

const USABLE_NEW = `  function freightFifoUsableOutboundTracking(trackingNo, exceptOrderId) {
    if (freightFifoTrackingOwnedByClosedShipment(trackingNo, exceptOrderId)) return '';
    return String(trackingNo || '').trim();
  }

  function freightFifoClosedTrackingSaveError(parcels) {
    var blocked = parcels && parcels.blockedByClosedShipment;
    if (!blocked) return '';
    return '這個物流單號 ' + blocked.trackingNo + ' 已用在訂單 ' + blocked.orderId + (blocked.status ? '（' + blocked.status + '）' : '') + '，不能填到這張新單。已完成取件的單號請到原單查看。';
  }`;

const PUSH_OLD = `    function push(trackingNo, amount, note) {
      trackingNo = freightFifoUsableOutboundTracking(String(trackingNo || '').trim(), orderId);
      trackingNo = freightFifoNormTrackingNo(trackingNo);
      if (!trackingNo || seen[trackingNo]) return;`;

const PUSH_NEW = `    function push(trackingNo, amount, note) {
      var rawTracking = String(trackingNo || '').trim();
      var owned = freightFifoTrackingOwnedByClosedShipment(rawTracking, orderId);
      if (owned) {
        parcels.blockedByClosedShipment = {
          trackingNo: freightFifoNormTrackingNo(rawTracking),
          orderId: String(owned.id || ''),
          status: String(owned.statusLabel || owned.status || owned.deliveryState || '')
        };
        return;
      }
      trackingNo = freightFifoNormTrackingNo(rawTracking);
      if (!trackingNo || seen[trackingNo]) return;`;

const PERSIST_OLD = `    var outboundParcels = freightFifoReadOutboundParcels(modal);
    var trackingNo = outboundParcels[0] && outboundParcels[0].trackingNo || '';
    var reservation = freightFifoReservationPayload(modal, decision);`;

const PERSIST_NEW = `    var outboundParcels = freightFifoReadOutboundParcels(modal);
    var closedTrackingError = freightFifoClosedTrackingSaveError(outboundParcels);
    if (closedTrackingError) return Promise.reject(new Error(closedTrackingError));
    var trackingNo = outboundParcels[0] && outboundParcels[0].trackingNo || '';
    var reservation = freightFifoReservationPayload(modal, decision);`;

const DRAFT_OLD = `    var outboundParcels = freightFifoReadOutboundParcels(modal);
    var trackingNo = outboundParcels[0] && outboundParcels[0].trackingNo || '';
    var shippingNote = String((modal.querySelector('[data-freight-fifo-note]') || {}).value || '').trim();
    var result = modal.querySelector('[data-freight-fifo-result]');`;

const DRAFT_NEW = `    var outboundParcels = freightFifoReadOutboundParcels(modal);
    var closedTrackingError = freightFifoClosedTrackingSaveError(outboundParcels);
    if (closedTrackingError) {
      showFreightFifoSubmitProblem(modal, modal.querySelector('[data-freight-fifo-result]'), closedTrackingError);
      return;
    }
    var trackingNo = outboundParcels[0] && outboundParcels[0].trackingNo || '';
    var shippingNote = String((modal.querySelector('[data-freight-fifo-note]') || {}).value || '').trim();
    var result = modal.querySelector('[data-freight-fifo-result]');`;

const CONFIRM_OLD = `    var outboundParcels = freightFifoReadOutboundParcels(modal);
    var trackingNo = outboundParcels[0] && outboundParcels[0].trackingNo || '';
    var shippingNote = String((modal.querySelector('[data-freight-fifo-note]') || {}).value || '').trim();
    var name = String((modal.querySelector('[data-freight-fifo-name]') || {}).value || '').trim();`;

const CONFIRM_NEW = `    var outboundParcels = freightFifoReadOutboundParcels(modal);
    var closedTrackingError = freightFifoClosedTrackingSaveError(outboundParcels);
    if (closedTrackingError) {
      toast(closedTrackingError);
      return;
    }
    var trackingNo = outboundParcels[0] && outboundParcels[0].trackingNo || '';
    var shippingNote = String((modal.querySelector('[data-freight-fifo-note]') || {}).value || '').trim();
    var name = String((modal.querySelector('[data-freight-fifo-name]') || {}).value || '').trim();`;

const PHP_OLD = `    if (array_key_exists('trackingNo', $payload) || array_key_exists('outboundParcels', $payload)) {
        $parcels = lz_outbound_parcels_from_payload($payload, $orders[$index]);
        $exceptId = trim((string)($orders[$index]['id'] ?? ''));`;

const PHP_NEW = `    if (array_key_exists('trackingNo', $payload) || array_key_exists('outboundParcels', $payload)) {
        $incomingNos = [];
        foreach (is_array($payload['outboundParcels'] ?? null) ? $payload['outboundParcels'] : [] as $incomingParcel) {
            $incomingNo = lz_norm_tracking((string)(is_array($incomingParcel) ? ($incomingParcel['trackingNo'] ?? '') : $incomingParcel));
            if ($incomingNo !== '') $incomingNos[] = $incomingNo;
        }
        $payloadPrimary = lz_norm_tracking((string)($payload['trackingNo'] ?? ''));
        if ($payloadPrimary !== '') $incomingNos[] = $payloadPrimary;
        $existingNo = lz_norm_tracking((string)($orders[$index]['trackingNo'] ?? ''));
        if (!$incomingNos && $existingNo !== '') {
            // 客戶資料儲存若沒帶單號，不可把已有物流單號清掉
            $parcels = [];
        } else {
        $parcels = lz_outbound_parcels_from_payload($payload, $orders[$index]);
        $exceptId = trim((string)($orders[$index]['id'] ?? ''));`;

const PHP_APPLY_OLD = `        $carrier = trim((string)($payload['shippingCarrier'] ?? ($orders[$index]['shippingCarrier'] ?? '')));
        lz_apply_outbound_parcels($orders[$index], $parcels, $carrier);
    }
    $dispatchDecision = strtolower(trim((string)($payload['freightDispatchDecision'] ?? '')));`;

const PHP_APPLY_NEW = `        $carrier = trim((string)($payload['shippingCarrier'] ?? ($orders[$index]['shippingCarrier'] ?? '')));
        lz_apply_outbound_parcels($orders[$index], $parcels, $carrier);
        }
    }
    $dispatchDecision = strtolower(trim((string)($payload['freightDispatchDecision'] ?? '')));`;

function stampHtml(dir) {
  const names = fs.readdirSync(dir).filter((name) => /\.html$/i.test(name));
  let n = 0;
  for (const name of names) {
    const file = path.join(dir, name);
    let html = fs.readFileSync(file, "latin1");
    if (!/admin\.js/.test(html)) continue;
    const next = html.replace(/admin\.js(?:\?v=[^"']+)?/g, `admin.js?v=${STAMP}`);
    if (next === html) continue;
    fs.writeFileSync(file, Buffer.from(next, "latin1"));
    n++;
    console.log("stamped", name);
  }
  console.log("html stamped", n);
}

function main() {
  console.log("backup js", backup(JS, "tracking-keep"));
  console.log("backup php", backup(PHP, "tracking-keep"));
  let js = fs.readFileSync(JS, "utf8");
  if (js.includes(JS_MARKER)) {
    console.log("js already patched");
  } else {
    js = replaceOnce(js, USABLE_OLD, USABLE_NEW, "closed tracking error helper");
    js = replaceOnce(js, PUSH_OLD, PUSH_NEW, "do not silently drop closed tracking");
    js = replaceOnce(js, PERSIST_OLD, PERSIST_NEW, "persist reject closed tracking");
    js = replaceOnce(js, DRAFT_OLD, DRAFT_NEW, "draft reject closed tracking");
    js = replaceOnce(js, CONFIRM_OLD, CONFIRM_NEW, "confirm reject closed tracking");
    fs.writeFileSync(JS, js);
    console.log("js written", js.length);
    try {
      new Function(js);
      console.log("js syntax ok");
    } catch (error) {
      throw new Error("admin.js syntax: " + error.message);
    }
  }

  let php = fs.readFileSync(PHP, "utf8");
  if (php.includes(PHP_MARKER)) {
    console.log("php already patched");
  } else {
    php = replaceOnce(php, PHP_OLD, PHP_NEW, "skip empty tracking wipe start");
    php = replaceOnce(php, PHP_APPLY_OLD, PHP_APPLY_NEW, "skip empty tracking wipe end");
    fs.writeFileSync(PHP, php);
    console.log("php written", php.length);
  }
  stampHtml(ROOT);
}

main();
