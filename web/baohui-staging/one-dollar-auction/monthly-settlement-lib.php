<?php
declare(strict_types=1);

function baohui_monthly_settlement_cycle($asOf = '', int $endDay = 24): array
{
    $endDay = max(1, min(28, $endDay));
    $asOf = trim((string)$asOf) ?: date('Y-m-d');
    $ts = strtotime($asOf);
    if ($ts === false) {
        $ts = time();
        $asOf = date('Y-m-d', $ts);
    }
    $day = (int)date('j', $ts);
    if ($day <= $endDay) {
        $month = substr($asOf, 0, 7);
    } else {
        $month = date('Y-m', strtotime(date('Y-m-01', $ts) . ' +1 month'));
    }
    return baohui_monthly_settlement_cycle_for_month($month, $endDay);
}

function baohui_monthly_settlement_cycle_for_month(string $yearMonth, int $endDay = 24): array
{
    $endDay = max(1, min(28, $endDay));
    $yearMonth = trim($yearMonth);
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        return baohui_monthly_settlement_cycle('', $endDay);
    }
    $end = $yearMonth . '-' . sprintf('%02d', $endDay);
    $previousMonth = date('Y-m', strtotime($yearMonth . '-01 -1 day'));
    $start = $previousMonth . '-25';
    return [
        'from' => $start,
        'to' => $end,
        'month' => $yearMonth,
        'label' => $start . ' ～ ' . $end,
        'end_day' => $endDay,
    ];
}

function baohui_billing_is_settled($request): bool
{
    return in_array(trim((string)($request['status'] ?? '')), ['已結清', '已結款'], true);
}

function baohui_receipt_is_settled($receipt): bool
{
    return trim((string)($receipt['status'] ?? '')) === '已確認';
}

function baohui_billing_matches_range(array $request, string $from, string $to, string $basis = 'document'): bool
{
    $from = trim($from);
    $to = trim($to);
    if ($from === '' && $to === '') {
        return true;
    }
    $storedFrom = trim((string)($request['period_from'] ?? ''));
    $storedTo = trim((string)($request['period_to'] ?? ''));
    if ($storedFrom !== '' || $storedTo !== '') {
        $periodFrom = $storedFrom !== '' ? $storedFrom : '0000-01-01';
        $periodTo = $storedTo !== '' ? $storedTo : '9999-12-31';
        if ($from !== '' && $periodTo < $from) {
            return false;
        }
        if ($to !== '' && $periodFrom > $to) {
            return false;
        }
        return true;
    }
    $dates = [substr((string)($request['request_date'] ?? ''), 0, 10)];
    foreach ((array)($request['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $dates[] = substr((string)($item['date'] ?? ''), 0, 10);
        if ($basis === 'delivery') {
            $dates[] = substr((string)($item['delivery_date'] ?? ''), 0, 10);
        }
    }
    foreach ($dates as $date) {
        if ($date === '') {
            continue;
        }
        if ($from !== '' && $date < $from) {
            continue;
        }
        if ($to !== '' && $date > $to) {
            continue;
        }
        return true;
    }
    return false;
}

function baohui_receipt_match_date(array $receipt, string $basis = 'receipt'): string
{
    if ($basis === 'delivery') {
        foreach ((array)($receipt['allocations'] ?? []) as $allocation) {
            $date = substr((string)($allocation['delivery_date'] ?? ''), 0, 10);
            if ($date !== '') {
                return $date;
            }
        }
    }
    if ($basis === 'billing') {
        $date = substr((string)($receipt['billing_date'] ?? ($receipt['request_date'] ?? '')), 0, 10);
        if ($date !== '') {
            return $date;
        }
    }
    return substr((string)($receipt['receipt_date'] ?? ($receipt['date'] ?? ($receipt['created_at'] ?? ''))), 0, 10);
}
