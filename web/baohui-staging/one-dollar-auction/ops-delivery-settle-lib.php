<?php
declare(strict_types=1);

function ops_delivery_settled_labels(): array
{
    return ['已銷帳', '已結清', '已結款', '已付款'];
}

function ops_delivery_is_settled(array $dn): bool
{
    $received = $dn['payment_received'] ?? '';
    if ($received === true || $received === 1 || $received === '1' || $received === 'yes') return true;
    $labels = ops_delivery_settled_labels();
    return in_array(trim((string)($dn['reconciliation_status'] ?? '')), $labels, true)
        || in_array(trim((string)($dn['payment_status'] ?? '')), $labels, true);
}

function ops_delivery_mark_settled(array &$dn, string $operator = '', ?string $at = null): array
{
    $at = $at ?? date('c');
    $dn['payment_status'] = '已結款';
    $dn['payment_received'] = true;
    $dn['payment_date'] = substr($at, 0, 10);
    $dn['reconciliation_status'] = '已銷帳';
    $dn['billing_status'] = '不需月結';
    $dn['updated_at'] = $at;
    if ($operator !== '') $dn['updated_by'] = $operator;
    return $dn;
}

function ops_delivery_linked_schedule_ids(array $dn): array
{
    $ids = [];
    foreach ((array)($dn['schedule_ids'] ?? []) as $id) {
        $id = trim((string)$id);
        if ($id !== '') $ids[] = $id;
    }
    foreach ((array)($dn['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $id = trim((string)($item['schedule_id'] ?? ''));
        if ($id !== '') $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

function ops_delivery_settle_linked_schedules(array &$schedules, array $dn, string $at = '', ?callable $unpaidOf = null): int
{
    $ids = array_fill_keys(ops_delivery_linked_schedule_ids($dn), true);
    if (!$ids) return 0;
    $at = $at !== '' ? $at : date('c');
    $changed = 0;
    foreach ($schedules as &$schedule) {
        if (!is_array($schedule) || !isset($ids[(string)($schedule['id'] ?? '')])) continue;
        $unpaid = 0.0;
        if ($unpaidOf) {
            $unpaid = max(0, (float)$unpaidOf($schedule));
        } else {
            $receivable = (float)($schedule['winning_price'] ?? 0)
                + (float)($schedule['shipping_fee'] ?? 0)
                + (float)($schedule['other_fee'] ?? 0);
            $unpaid = max(0, $receivable - (float)($schedule['paid_amount'] ?? 0));
        }
        if ($unpaid > 0) {
            $schedule['paid_amount'] = (float)($schedule['paid_amount'] ?? 0) + $unpaid;
        }
        $schedule['payment_status'] = '已結款';
        $schedule['reconciliation_status'] = '已銷帳';
        $schedule['payment_date'] = substr($at, 0, 10);
        $schedule['updated_at'] = $at;
        $changed++;
    }
    unset($schedule);
    return $changed;
}
