#!/usr/bin/env python3
"""Let 銷貨明細付款區塊 select 已結款, including 競標出貨單 in auction_delivery_notes."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """require_once __DIR__ . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auction-lot-lib.php';
""",
        """require_once __DIR__ . DIRECTORY_SEPARATOR . 'schedule-lifecycle-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'ops-delivery-settle-lib.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'auction-lot-lib.php';
""",
    ),
    (
        """    if (in_array($action, ['settle_delivery_note', 'settle_delivery_notes_bulk'], true)) {
        $opsInitialTab = 'finance-request';
        $settleDeliveryIds = $action === 'settle_delivery_notes_bulk'
            ? (array)($_POST['delivery_ids'] ?? [])
            : [trim((string)($_POST['delivery_id'] ?? ''))];
        $settleDeliveryIds = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $settleDeliveryIds))));
        if (!$settleDeliveryIds) {
            $notice = '銷帳失敗：沒有可處理的出貨單。';
        } else {
            $settledAt = date('c');
            $settledDate = date('Y-m-d');
            $settledIds = [];
            $settledDocumentNos = [];
            $settledAllocations = [];
            $settledCustomerName = '';
            $settledTotal = 0.0;
            foreach ($settleDeliveryIds as $settleDeliveryId) {
                $settleIndex = find_delivery_note_index($deliveryNotes, $settleDeliveryId);
                if ($settleIndex < 0) continue;
                $settleDelivery = $deliveryNotes[$settleIndex];
                $alreadySettled = in_array((string)($settleDelivery['reconciliation_status'] ?? ''), ['已銷帳','已結清'], true)
                    || in_array((string)($settleDelivery['payment_status'] ?? ''), ['已付款','已結清'], true);
                if ($alreadySettled) continue;
                $settledAmount = max(0, (float)($settleDelivery['total'] ?? 0));
                $settledBuyer = is_array($settleDelivery['buyer'] ?? null) ? $settleDelivery['buyer'] : [];
                $settledDocumentNo = trim((string)($settleDelivery['delivery_no'] ?? $settleDeliveryId));
                $deliveryNotes[$settleIndex]['payment_status'] = '已付款';
                $deliveryNotes[$settleIndex]['payment_received'] = true;
                $deliveryNotes[$settleIndex]['payment_date'] = $settledDate;
                $deliveryNotes[$settleIndex]['reconciliation_status'] = '已銷帳';
                $deliveryNotes[$settleIndex]['billing_status'] = '已銷帳';
                $deliveryNotes[$settleIndex]['updated_at'] = $settledAt;
                $deliveryNotes[$settleIndex]['updated_by'] = current_operator();
                $settledIds[] = $settleDeliveryId;
                $settledDocumentNos[] = $settledDocumentNo;
                $settledAllocations[] = ['delivery_id' => $settleDeliveryId, 'document_no' => $settledDocumentNo, 'amount' => $settledAmount];
                $settledTotal += $settledAmount;
                if ($settledCustomerName === '') $settledCustomerName = trim((string)($settledBuyer['name'] ?? ''));
            }
""",
        """    if (in_array($action, ['settle_delivery_note', 'settle_delivery_notes_bulk'], true)) {
        $requestedSettleTab = trim((string)($_POST['ops_tab'] ?? ''));
        $opsInitialTab = in_array($requestedSettleTab, ['sales-history', 'finance-request', 'customer-shipping'], true)
            ? $requestedSettleTab
            : 'sales-history';
        $settleChoice = trim((string)($_POST['settle_choice'] ?? 'settled'));
        $settleDeliveryIds = $action === 'settle_delivery_notes_bulk'
            ? (array)($_POST['delivery_ids'] ?? [])
            : [trim((string)($_POST['delivery_id'] ?? ''))];
        $settleDeliveryIds = array_values(array_unique(array_filter(array_map(static fn($id) => trim((string)$id), $settleDeliveryIds))));
        if ($settleChoice !== '' && $settleChoice !== 'settled') {
            $notice = '未更改結款狀態。';
        } elseif (!$settleDeliveryIds) {
            $notice = '銷帳失敗：沒有可處理的出貨單。';
        } else {
            $settledAt = date('c');
            $settledDate = date('Y-m-d');
            $settledIds = [];
            $settledDocumentNos = [];
            $settledAllocations = [];
            $settledCustomerName = '';
            $settledTotal = 0.0;
            $settledAuction = false;
            foreach ($settleDeliveryIds as $settleDeliveryId) {
                $fromAuction = false;
                $settleIndex = find_delivery_note_index($deliveryNotes, $settleDeliveryId);
                if ($settleIndex < 0) {
                    $settleIndex = find_delivery_note_index($auctionDeliveryNotes, $settleDeliveryId);
                    $fromAuction = $settleIndex >= 0;
                }
                if ($settleIndex < 0) continue;
                $settleDelivery = $fromAuction ? $auctionDeliveryNotes[$settleIndex] : $deliveryNotes[$settleIndex];
                $alreadySettled = function_exists('ops_delivery_is_settled')
                    ? ops_delivery_is_settled($settleDelivery)
                    : (in_array((string)($settleDelivery['reconciliation_status'] ?? ''), ['已銷帳','已結清','已結款'], true)
                        || in_array((string)($settleDelivery['payment_status'] ?? ''), ['已付款','已結清','已結款'], true));
                if ($alreadySettled) continue;
                $settledAmount = max(0, (float)($settleDelivery['total'] ?? 0));
                $settledBuyer = is_array($settleDelivery['buyer'] ?? null) ? $settleDelivery['buyer'] : [];
                $settledDocumentNo = trim((string)($settleDelivery['delivery_no'] ?? $settleDeliveryId));
                if ($fromAuction) {
                    if (function_exists('ops_delivery_mark_settled')) ops_delivery_mark_settled($auctionDeliveryNotes[$settleIndex], current_operator(), $settledAt);
                    else {
                        $auctionDeliveryNotes[$settleIndex]['payment_status'] = '已結款';
                        $auctionDeliveryNotes[$settleIndex]['payment_received'] = true;
                        $auctionDeliveryNotes[$settleIndex]['payment_date'] = $settledDate;
                        $auctionDeliveryNotes[$settleIndex]['reconciliation_status'] = '已銷帳';
                        $auctionDeliveryNotes[$settleIndex]['billing_status'] = '不需月結';
                        $auctionDeliveryNotes[$settleIndex]['updated_at'] = $settledAt;
                        $auctionDeliveryNotes[$settleIndex]['updated_by'] = current_operator();
                    }
                    if (function_exists('ops_delivery_settle_linked_schedules')) {
                        $unpaidOf = function (array $schedule) use ($products): float {
                            $row = product_by_id($products, $schedule['product_id'] ?? '');
                            $totals = function_exists('totals') ? totals($schedule, $row) : [];
                            return max(0, (float)($totals['unpaid'] ?? 0));
                        };
                        ops_delivery_settle_linked_schedules($schedules, $auctionDeliveryNotes[$settleIndex], $settledAt, $unpaidOf);
                    }
                    $settledAuction = true;
                } else {
                    if (function_exists('ops_delivery_mark_settled')) ops_delivery_mark_settled($deliveryNotes[$settleIndex], current_operator(), $settledAt);
                    else {
                        $deliveryNotes[$settleIndex]['payment_status'] = '已結款';
                        $deliveryNotes[$settleIndex]['payment_received'] = true;
                        $deliveryNotes[$settleIndex]['payment_date'] = $settledDate;
                        $deliveryNotes[$settleIndex]['reconciliation_status'] = '已銷帳';
                        $deliveryNotes[$settleIndex]['billing_status'] = '不需月結';
                        $deliveryNotes[$settleIndex]['updated_at'] = $settledAt;
                        $deliveryNotes[$settleIndex]['updated_by'] = current_operator();
                    }
                }
                $settledIds[] = $settleDeliveryId;
                $settledDocumentNos[] = $settledDocumentNo;
                $settledAllocations[] = ['delivery_id' => $settleDeliveryId, 'document_no' => $settledDocumentNo, 'amount' => $settledAmount];
                $settledTotal += $settledAmount;
                if ($settledCustomerName === '') $settledCustomerName = trim((string)($settledBuyer['name'] ?? ''));
            }
""",
    ),
    (
        """                write_data('delivery_notes', $deliveryNotes);
                write_data('collection_receipts', $collectionReceipts);
                write_data('billing_requests', $billingRequests);
                $notice = '已銷帳 ' . count($settledIds) . ' 張出貨單，共 ' . money($settledTotal) . '，合併收款紀錄已建立。';
""",
        """                write_data('delivery_notes', $deliveryNotes);
                if (!empty($settledAuction)) {
                    write_data('auction_delivery_notes', $auctionDeliveryNotes);
                    write_data('schedules', $schedules);
                }
                write_data('collection_receipts', $collectionReceipts);
                write_data('billing_requests', $billingRequests);
                $notice = '已結款 ' . count($settledIds) . ' 張出貨單，共 ' . money($settledTotal) . '，合併收款紀錄已建立。';
""",
    ),
    (
        """                'collection_receipt_no' => trim((string)($_POST['quick_receipt_no'] ?? '')),
                'payment_proof_image' => trim((string)($_POST['quick_payment_proof_image'] ?? '')),
                'reconciliation_status' => trim((string)($_POST['quick_receipt_no'] ?? '')) !== '' ? '已銷帳' : '待核對',
                'created_at' => date('c'),
""",
        """                'collection_receipt_no' => trim((string)($_POST['quick_receipt_no'] ?? '')),
                'payment_proof_image' => trim((string)($_POST['quick_payment_proof_image'] ?? '')),
                'payment_status' => '已結款',
                'payment_received' => true,
                'payment_date' => $shippingDate !== '' ? $shippingDate : date('Y-m-d'),
                'reconciliation_status' => '已銷帳',
                'billing_status' => '不需月結',
                'created_at' => date('c'),
""",
    ),
    (
        """          $isDeliverySettled = in_array(($dn['reconciliation_status']??''),['已銷帳','已結清'],true) || in_array(($dn['payment_status']??''),['已付款','已結清'],true);
""",
        """          $isDeliverySettled = function_exists('ops_delivery_is_settled')
            ? ops_delivery_is_settled($dn)
            : (in_array(($dn['reconciliation_status']??''),['已銷帳','已結清','已結款'],true) || in_array(($dn['payment_status']??''),['已付款','已結清','已結款'],true) || !empty($dn['payment_received']));
""",
    ),
    (
        """                <div class="sales-history-status <?=$isDeliverySettled?'payment-settled':'payment-open'?>"><small>付款區塊</small><b><?=$isDeliverySettled?'✓ 已銷帳':'未銷帳／月結'?></b></div>
""",
        """                <?php if ($isPreorderRecord): ?>
                  <div class="sales-history-status payment-open"><small>付款區塊</small><b>尚未打單</b></div>
                <?php elseif ($isDeliverySettled): ?>
                  <div class="sales-history-status payment-settled"><small>付款區塊</small><b>✓ 已結款</b></div>
                <?php else: ?>
                  <form method="post" class="sales-history-status payment-open sales-history-settle-form">
                    <input type="hidden" name="action" value="settle_delivery_note">
                    <input type="hidden" name="delivery_id" value="<?=h($dn['id'] ?? ($dn['delivery_no'] ?? ''))?>">
                    <input type="hidden" name="ops_tab" value="sales-history">
                    <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                    <small>付款區塊</small>
                    <label><span class="visually-hidden">結款狀態</span>
                      <select name="settle_choice" onchange="if(this.value==='settled') this.form.requestSubmit();">
                        <option value="open" selected>未銷帳／月結</option>
                        <option value="settled">已結款</option>
                      </select>
                    </label>
                    <button type="submit" class="primary small" name="settle_choice" value="settled">改為已結款</button>
                  </form>
                <?php endif; ?>
""",
    ),
    (
        ".sales-history-status.payment-open{background:#fff1f2;color:#be123c}.sales-history-status.time{background:#f1f5f9;color:#334155}",
        ".sales-history-status.payment-open{background:#fff1f2;color:#be123c}.sales-history-status.time{background:#f1f5f9;color:#334155}.sales-history-settle-form select{display:block;width:100%;margin-top:6px;padding:6px 8px;border:1px solid currentColor;border-radius:6px;font-weight:800;background:#fff}.sales-history-settle-form button{margin-top:7px}.sales-history-settle-form .visually-hidden{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}",
    ),
]


def apply(text: str) -> str:
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:180].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:180].replace("\n", " / "))
            continue
        text = text.replace(old, new, 1)
    if missing:
        raise SystemExit("patch targets missing or not unique:\n- " + "\n- ".join(missing))
    return text


def main() -> None:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("path")
    args = parser.parse_args()
    path = Path(args.path)
    original = path.read_text(encoding="utf-8", errors="surrogateescape")
    updated = apply(original)
    path.write_text(updated, encoding="utf-8", errors="surrogateescape")
    print(f"patched {path} bytes {len(original.encode('utf-8'))} -> {len(updated.encode('utf-8'))}")


if __name__ == "__main__":
    main()
