#!/usr/bin/env python3
"""Unlock pending_review lots in the schedule workspace so 排程 can finish."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """            if (schedule_lifecycle_is_cancelled($timeRow) || schedule_lifecycle_is_listed($timeRow) || !empty($timeRow['post_url'])
                || in_array((string)($timeRow['facebook_worker_status'] ?? ''), ['running','pending_review','pending_url_correction','facebook_scheduled','published'],true)) {
                $notice = '這筆已送出或結果待確認，不能改成新發文；需先核對 Facebook 貼文。'; break;
            }
            if (!$sendNow && !empty($timeRow['operator_selected_times'])
                && (string)($timeRow['scheduled_publish_at'] ?? '') === date('Y-m-d H:i',$publishTs)
                && (string)($timeRow['close_at'] ?? '') === date('Y-m-d H:i',$closeTs)) {
                $timeSaved = true;
                $notice = '已儲存：發文 '.date('Y-m-d H:i',$publishTs).'；結標 '.date('Y-m-d H:i',$closeTs);
                break;
            }
""",
        """            $timeWorker = (string)($timeRow['facebook_worker_status'] ?? '');
            $timeHasRealUrl = function_exists('schedule_lifecycle_real_post_url') && schedule_lifecycle_real_post_url((string)($timeRow['post_url'] ?? ''));
            $timeManualRetry = function_exists('schedule_lifecycle_can_manual_retry') && schedule_lifecycle_can_manual_retry($timeRow);
            if (schedule_lifecycle_is_cancelled($timeRow) || schedule_lifecycle_is_listed($timeRow) || $timeHasRealUrl
                || (in_array($timeWorker, ['running','pending_url_correction','facebook_scheduled','published'], true))
                || (in_array($timeWorker, ['pending_review','unlisted_manual_review','failed'], true) && !$timeManualRetry)) {
                $notice = '這筆已送出或結果待確認，不能改成新發文；需先核對 Facebook 貼文。'; break;
            }
            if ($timeManualRetry && function_exists('schedule_lifecycle_clear_manual_retry_hold')) {
                schedule_lifecycle_clear_manual_retry_hold($timeRow);
            }
            if (!$sendNow && !$timeManualRetry && !empty($timeRow['operator_selected_times'])
                && (string)($timeRow['scheduled_publish_at'] ?? '') === date('Y-m-d H:i',$publishTs)
                && (string)($timeRow['close_at'] ?? '') === date('Y-m-d H:i',$closeTs)) {
                $timeSaved = true;
                $notice = '已儲存：發文 '.date('Y-m-d H:i',$publishTs).'；結標 '.date('Y-m-d H:i',$closeTs);
                break;
            }
""",
    ),
    (
        """$scheduleQueue = array_values(array_filter($shown, function($s) {
    return schedule_lifecycle_is_codex_managed($s)
        && schedule_lifecycle_is_prepare_candidate($s)
        && trim((string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '')) !== ''
        && (empty($s['auto_relist_blocked']) || (string)($s['facebook_worker_status'] ?? '') === 'awaiting_manual_time')
        && trim((string)($s['post_url'] ?? '')) === ''
        && !in_array(trim((string)($s['facebook_worker_status'] ?? '')), ['pending_review', 'pending_url_correction', 'published_url_pending_review', 'unlisted_manual_review', 'blocked_no_stock', 'blocked_before_queue', 'excluded_manual_hold'], true)
        && !ops_facebook_native_scheduled($s);
}));
""",
        """$scheduleQueue = array_values(array_filter($shown, function($s) {
    $workerStatus = trim((string)($s['facebook_worker_status'] ?? ''));
    $manualRetry = function_exists('schedule_lifecycle_can_manual_retry') && schedule_lifecycle_can_manual_retry($s);
    return schedule_lifecycle_is_codex_managed($s)
        && schedule_lifecycle_is_prepare_candidate($s)
        && trim((string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '')) !== ''
        && (empty($s['auto_relist_blocked']) || $workerStatus === 'awaiting_manual_time' || $manualRetry)
        && trim((string)($s['post_url'] ?? '')) === ''
        && (
            !in_array($workerStatus, ['pending_review', 'pending_url_correction', 'published_url_pending_review', 'unlisted_manual_review', 'blocked_no_stock', 'blocked_before_queue', 'excluded_manual_hold'], true)
            || $manualRetry
        )
        && !ops_facebook_native_scheduled($s);
}));
""",
    ),
    (
        """$scheduleQueueNeedsReviewRows = array_values(array_filter($shown, function($s) {
    $publishTs = strtotime((string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '')) ?: 0;
    $closeTs = strtotime((string)($s['close_at'] ?? '')) ?: 0;
    return schedule_lifecycle_is_codex_managed($s)
        && trim((string)($s['post_url'] ?? '')) === ''
        && $publishTs > 0
        && $publishTs <= time()
        && ($closeTs <= 0 || $closeTs > time())
        && in_array(strtolower(trim((string)($s['facebook_worker_status'] ?? ''))), ['pending_review', 'pending_url_correction', 'published_url_pending_review'], true);
}));
""",
        """$scheduleQueueNeedsReviewRows = array_values(array_filter($shown, function($s) {
    $closeTs = strtotime((string)($s['close_at'] ?? '')) ?: 0;
    return schedule_lifecycle_is_codex_managed($s)
        && trim((string)($s['post_url'] ?? '')) === ''
        && ($closeTs <= 0 || $closeTs > time())
        && in_array(strtolower(trim((string)($s['facebook_worker_status'] ?? ''))), ['pending_review', 'pending_url_correction', 'published_url_pending_review', 'unlisted_manual_review'], true);
}));
""",
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
