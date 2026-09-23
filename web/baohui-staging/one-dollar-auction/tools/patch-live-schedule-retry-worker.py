#!/usr/bin/env python3
"""Let 啟動上架 re-queue pending_review lots that never got a Facebook permalink."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """        foreach (read_data('schedules') as $schedule) {
            if (!is_array($schedule) || !faw_schedule_needs_post($schedule) || !faw_schedule_is_facebook_auction($schedule)) continue;
            if (function_exists('schedule_lifecycle_is_codex_managed') && !schedule_lifecycle_is_codex_managed($schedule)) continue;
            $scheduleId = faw_one_line($schedule['id'] ?? '');
            if ($scheduleId !== '') $scheduleIds[] = $scheduleId;
        }
""",
        """        foreach (read_data('schedules') as $schedule) {
            if (!is_array($schedule) || !faw_schedule_is_facebook_auction($schedule)) continue;
            if (function_exists('schedule_lifecycle_is_codex_managed') && !schedule_lifecycle_is_codex_managed($schedule)) continue;
            $needsPost = faw_schedule_needs_post($schedule);
            $manualRetry = function_exists('schedule_lifecycle_can_manual_retry') && schedule_lifecycle_can_manual_retry($schedule);
            if (!$needsPost && !$manualRetry) continue;
            $scheduleId = faw_one_line($schedule['id'] ?? '');
            if ($scheduleId !== '') $scheduleIds[] = $scheduleId;
        }
""",
    ),
    (
        """            $rapidSchedules = read_data('schedules');
            $rapidSchedulesChanged = false;
            foreach ($rapidSchedules as &$rapidSchedule) {
""",
        """            $rapidSchedules = read_data('schedules');
            $rapidSchedulesChanged = false;
            foreach ($rapidSchedules as &$retryHoldSchedule) {
                if (!is_array($retryHoldSchedule)) continue;
                $retryHoldId = faw_one_line($retryHoldSchedule['id'] ?? '');
                if ($retryHoldId === '' || !isset($scheduleSet[$retryHoldId])) continue;
                if (!function_exists('schedule_lifecycle_can_manual_retry') || !schedule_lifecycle_can_manual_retry($retryHoldSchedule)) continue;
                schedule_lifecycle_clear_manual_retry_hold($retryHoldSchedule);
                $rapidSchedulesChanged = true;
            }
            unset($retryHoldSchedule);
            foreach ($rapidSchedules as &$rapidSchedule) {
""",
    ),
    (
        """                if (in_array($queuedStatus, [
                    'running', 'scheduled', 'facebook_scheduled',
                    'facebook_posted_url_pending', 'facebook_posted_url_missing',
                    'manual_facebook_scheduled', 'published',
                    'pending_review', 'pending_url_correction', 'unlisted_manual_review',
                    'published_url_pending_review', 'blocked_no_stock',
                    'blocked_before_queue', 'excluded_manual_hold',
                    'cancelled_completed_history', 'cancelled_customer_owned',
                    'cancelled_for_reschedule', 'cancelled_manual_source',
                    'cancelled_unsold_area',
                ], true)) continue;
                if (!in_array($queuedStatus, [
                    'queued', 'failed', 'archived_expired',
                    'deferred_future_not_activated', 'queued_pending_build',
                ], true)) continue;
""",
        """                $queuedScheduleRow = [];
                foreach ($rapidSchedules as $queuedLookup) {
                    if (is_array($queuedLookup) && faw_one_line($queuedLookup['id'] ?? '') === $queuedScheduleId) {
                        $queuedScheduleRow = $queuedLookup;
                        break;
                    }
                }
                $manualRetry = $queuedScheduleRow && function_exists('schedule_lifecycle_can_manual_retry')
                    && schedule_lifecycle_can_manual_retry($queuedScheduleRow, $queuedTask);
                if (in_array($queuedStatus, [
                    'running', 'scheduled', 'facebook_scheduled',
                    'facebook_posted_url_pending', 'facebook_posted_url_missing',
                    'manual_facebook_scheduled', 'published',
                    'pending_url_correction',
                    'published_url_pending_review', 'blocked_no_stock',
                    'blocked_before_queue', 'excluded_manual_hold',
                    'cancelled_completed_history', 'cancelled_customer_owned',
                    'cancelled_for_reschedule', 'cancelled_manual_source',
                    'cancelled_unsold_area',
                ], true) && !$manualRetry) continue;
                if (in_array($queuedStatus, ['pending_review', 'unlisted_manual_review'], true) && !$manualRetry) continue;
                if (!in_array($queuedStatus, [
                    'queued', 'failed', 'archived_expired',
                    'deferred_future_not_activated', 'queued_pending_build',
                    'pending_review', 'unlisted_manual_review',
                ], true)) continue;
""",
    ),
    (
        """        if ($knownIndex !== null && !in_array((string)($queue['tasks'][(int)$knownIndex]['status'] ?? ''), ['queued', 'scheduled'], true)) {
            $knownTask = $queue['tasks'][(int)$knownIndex];
            $s['facebook_queue_id'] = (string)$knownTask['id'];
            $s['facebook_worker_status'] = (string)$knownTask['status'];
            $s['facebook_worker_error'] = faw_task_failure_reason($knownTask);
            continue;
        }
""",
        """        if ($knownIndex !== null && !in_array((string)($queue['tasks'][(int)$knownIndex]['status'] ?? ''), ['queued', 'scheduled'], true)) {
            $knownTask = $queue['tasks'][(int)$knownIndex];
            $manualRetryKnown = $onlyScheduleSet
                && function_exists('schedule_lifecycle_can_manual_retry')
                && schedule_lifecycle_can_manual_retry($s, $knownTask);
            if ($manualRetryKnown) {
                $queue['tasks'][(int)$knownIndex]['status'] = 'queued';
                $queue['tasks'][(int)$knownIndex]['attemptCount'] = 0;
                $queue['tasks'][(int)$knownIndex]['result'] = ['postUrl' => '', 'publishedAt' => '', 'statusAtFacebook' => '', 'failureReason' => ''];
                unset($queue['tasks'][(int)$knownIndex]['workerId'], $queue['tasks'][(int)$knownIndex]['claimAt'], $queue['tasks'][(int)$knownIndex]['claimToken']);
                if (!isset($queue['tasks'][(int)$knownIndex]['audit']) || !is_array($queue['tasks'][(int)$knownIndex]['audit'])) {
                    $queue['tasks'][(int)$knownIndex]['audit'] = [];
                }
                $queue['tasks'][(int)$knownIndex]['audit']['manualRetryAt'] = $manualNow;
                $queue['tasks'][(int)$knownIndex]['audit']['manualRetryFrom'] = (string)($knownTask['status'] ?? '');
                if (function_exists('schedule_lifecycle_clear_manual_retry_hold')) schedule_lifecycle_clear_manual_retry_hold($s);
            } else {
                $s['facebook_queue_id'] = (string)$knownTask['id'];
                $s['facebook_worker_status'] = (string)$knownTask['status'];
                $s['facebook_worker_error'] = faw_task_failure_reason($knownTask);
                continue;
            }
        }
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
