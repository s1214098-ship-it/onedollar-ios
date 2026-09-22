<?php
declare(strict_types=1);

function schedule_lifecycle_one_line($v): string
{
    return trim(preg_replace('/\s+/', ' ', (string)$v));
}

function schedule_lifecycle_is_held(array $s): bool
{
    if (!empty($s['auto_relist_blocked'])) return true;
    return in_array(strtolower(schedule_lifecycle_one_line($s['facebook_worker_status'] ?? '')), [
        'awaiting_manual_time', 'cancelled_for_reschedule', 'pending_review',
        'pending_url_correction', 'published_url_pending_review', 'unlisted_manual_review', 'failed',
        'facebook_posted_url_pending', 'facebook_posted_url_missing',
        'blocked', 'blocked_before_queue',
    ], true);
}

function schedule_lifecycle_parse_ts($v): int
{
    $text = trim((string)$v);
    if ($text === '') return 0;
    $ts = strtotime($text);
    return $ts === false ? 0 : (int)$ts;
}

function schedule_lifecycle_is_weekend_date(string $date): bool
{
    $ts = strtotime($date . ' 12:00');
    if ($ts === false) return false;
    return in_array((int)date('N', $ts), [6, 7], true);
}

function schedule_lifecycle_close_times_for_date(string $date): array
{
    return schedule_lifecycle_is_weekend_date($date)
        ? ['23:59']
        : ['12:59', '23:59'];
}

/**
 * Business rollover sequence requested for missed Facebook listings:
 * weekdays use 12:59 then 23:59; Saturday and Sunday use 23:59 only.
 * The returned close always leaves at least two hours after publication.
 */
function schedule_lifecycle_next_requested_close(int $publishTs): string
{
    for ($day = 0; $day < 60; $day++) {
        $date = date('Y-m-d', strtotime('+' . $day . ' day', $publishTs));
        $times = schedule_lifecycle_is_weekend_date($date) ? ['23:59'] : ['12:59', '23:59'];
        foreach ($times as $time) {
            $closeTs = strtotime($date . ' ' . $time);
            if ($closeTs !== false && $closeTs - $publishTs >= 7200) return date('Y-m-d H:i', $closeTs);
        }
    }
    return date('Y-m-d 23:59', strtotime('+1 day', $publishTs));
}

function schedule_lifecycle_apply_weekend_close_rule(array &$s): bool
{
    if (schedule_lifecycle_is_held($s)) return false;
    if (!empty($s['operator_selected_times']) || !empty($s['operator_time_locked'])) return false;
    if (schedule_lifecycle_is_cancelled($s) || schedule_lifecycle_is_listed($s) || schedule_lifecycle_is_posted_status($s) || !empty($s['facebook_publish_completed'])) return false;
    $closeAt = schedule_lifecycle_one_line($s['close_at'] ?? $s['close_remind_at'] ?? '');
    $closeTs = schedule_lifecycle_parse_ts($closeAt);
    if ($closeTs <= 0 || !in_array((int)date('N', $closeTs), [6, 7], true) || date('H:i', $closeTs) === '23:59') return false;
    $corrected = date('Y-m-d', $closeTs) . ' 23:59';
    $s['close_at'] = $corrected;
    $s['close_remind_at'] = $corrected;
    $s['weekend_close_corrected_at'] = date('c');
    $s['weekend_close_corrected_from'] = $closeAt;
    return true;
}

function schedule_lifecycle_real_post_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    return (bool)preg_match('~/posts/|/permalink/|multi_permalinks=|story_fbid=|pfbid~i', $url);
}

function schedule_lifecycle_group_url(string $url): bool
{
    $url = trim($url);
    if ($url === '') return false;
    return (bool)preg_match('~facebook\.com/groups/[^/?#]+/?(?:[?#].*)?$~i', $url);
}

function schedule_lifecycle_is_listed(array $s): bool
{
    $url = schedule_lifecycle_one_line($s['post_url'] ?? '');
    if ($url === '' || !schedule_lifecycle_real_post_url($url)) return false;

    // A permalink alone is not enough when the browser explicitly reported a
    // failure or the URL is still under review.  Only a verified worker report
    // (or a deliberate manual completion backed by a real permalink) may make
    // a schedule enter the listed / reminder / close lifecycle.
    $workerStatus = strtolower(schedule_lifecycle_one_line($s['facebook_worker_status'] ?? ''));
    $progressStatus = strtolower(schedule_lifecycle_one_line($s['progress_status'] ?? ''));
    $publishStatus = schedule_lifecycle_one_line($s['publish_status'] ?? $s['status'] ?? '');
    if (!empty($s['duplicate_post_url']) || !empty($s['duplicate_of_schedule_id'])) return false;
    if (in_array($workerStatus, ['pending_url_correction', 'unlisted_manual_review', 'facebook_posted_url_pending', 'facebook_posted_url_missing'], true)
        || in_array($progressStatus, ['pending_review', 'unlisted_manual_review', 'facebook_posted_url_pending', 'facebook_posted_url_missing'], true)) return false;
    if (!empty($s['facebook_publish_completed']) && (string)$s['facebook_publish_completed'] !== '0') return true;
    if ($workerStatus === 'published') return true;
    $scheduledAt = schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
    $scheduledTs = $scheduledAt !== '' ? strtotime($scheduledAt) : false;
    if ($scheduledTs !== false && $scheduledTs <= time()
        && (in_array($workerStatus, ['scheduled', 'facebook_scheduled', 'manual_facebook_scheduled'], true)
            || in_array($progressStatus, ['scheduled', 'facebook_scheduled'], true)
            || $publishStatus === '已排入 Facebook 預約')) return true;
    if ($progressStatus === 'published'
        && !in_array($publishStatus, ['上架失敗', '上架失敗／待重試', '未上架成功', '上架未確認', '上架異常'], true)) return true;
    if (in_array($workerStatus, [
        'failed', 'blocked', 'pending_review', 'pending_url_correction', 'unlisted_manual_review',
        'published_url_pending_review', 'facebook_posted_url_pending', 'facebook_posted_url_missing',
        'queued', 'queued_pending_build', 'running',
    ], true)) return false;
    if (in_array($progressStatus, ['failed', 'pending_review', 'unlisted_manual_review', 'facebook_posted_url_pending', 'facebook_posted_url_missing'], true)) return false;
    if (in_array($publishStatus, ['上架失敗', '上架失敗／待重試', '未上架成功', '上架未確認', '上架異常', '未上架'], true)) return false;

    return in_array($publishStatus, ['已上架', '上架成功', '自行上架中', '在線販售中', 'published'], true);
}

function schedule_lifecycle_is_codex_managed(array $s): bool
{
    $source = strtolower(schedule_lifecycle_one_line($s['listing_source'] ?? $s['upload_source'] ?? ''));
    return !in_array($source, ['unassigned', 'self', 'staff', 'manual', '人工', '人工上架'], true);
}

function schedule_lifecycle_helper_heartbeat_path(): string
{
    $configured = trim((string)getenv('BAOHUI_HELPER_HEARTBEAT_PATH'));
    if ($configured !== '') return $configured;
    if (function_exists('ops_data_dir')) {
        return ops_data_dir() . DIRECTORY_SEPARATOR . 'facebook-helper-heartbeat.json';
    }
    return __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'facebook-helper-heartbeat.json';
}

function schedule_lifecycle_record_helper_heartbeat(string $version = '', string $build = '', ?int $nowTs = null): array
{
    $nowTs = $nowTs ?? time();
    $payload = [
        'seen_at' => date('c', $nowTs),
        'seen_at_ts' => $nowTs,
        'helper_version' => schedule_lifecycle_one_line($version),
        'helper_build' => schedule_lifecycle_one_line($build),
    ];
    $path = schedule_lifecycle_helper_heartbeat_path();
    $dir = dirname($path);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    if (!@rename($tmp, $path) && is_file($tmp)) {
        @unlink($path);
        @rename($tmp, $path);
    }
    if (is_file($tmp)) @unlink($tmp);
    return $payload;
}

function schedule_lifecycle_helper_status(?int $nowTs = null, int $freshSeconds = 180): array
{
    $nowTs = $nowTs ?? time();
    $path = schedule_lifecycle_helper_heartbeat_path();
    $raw = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    $seenTs = is_array($raw) ? (int)($raw['seen_at_ts'] ?? 0) : 0;
    $connected = $seenTs > 0 && ($nowTs - $seenTs) <= max(30, $freshSeconds);
    return [
        'connected' => $connected,
        'seen_at' => is_array($raw) ? schedule_lifecycle_one_line($raw['seen_at'] ?? '') : '',
        'seen_at_ts' => $seenTs,
        'helper_version' => is_array($raw) ? schedule_lifecycle_one_line($raw['helper_version'] ?? '') : '',
        'helper_build' => is_array($raw) ? schedule_lifecycle_one_line($raw['helper_build'] ?? '') : '',
        'age_seconds' => $seenTs > 0 ? max(0, $nowTs - $seenTs) : null,
        'stale' => $seenTs > 0 && !$connected,
    ];
}

function schedule_lifecycle_helper_may_claim_prepared_listing(array $task, bool $operatorRequested = false, bool $prepareScheduled = false): bool
{
    if (schedule_lifecycle_one_line($task['contentType'] ?? '') !== 'facebook_auction_listing') return false;
    $status = schedule_lifecycle_one_line($task['status'] ?? '');
    if (!in_array($status, ['queued', 'scheduled'], true)) return false;
    if ($operatorRequested || $prepareScheduled) return true;
    $audit = is_array($task['audit'] ?? null) ? $task['audit'] : [];
    return !empty($audit['manualFullBatchStartedAt'])
        || !empty($audit['cloudDispatchAt'])
        || !empty($audit['ownGroupRapidBatchStartedAt']);
}

function schedule_lifecycle_archive_manual_listing_to_daily_report(array &$s, ?int $nowTs = null): bool
{
    if (schedule_lifecycle_is_cancelled($s)) return false;
    if (schedule_lifecycle_is_codex_managed($s)) return false;
    $url = schedule_lifecycle_one_line($s['post_url'] ?? $s['facebook_post_url'] ?? '');
    if (!schedule_lifecycle_real_post_url($url)) return false;
    $already = !empty($s['facebook_daily_archived']) && (string)$s['facebook_daily_archived'] !== '0';
    $completed = !empty($s['facebook_publish_completed']) && (string)$s['facebook_publish_completed'] !== '0';
    if ($already && $completed) return false;
    $nowTs = $nowTs ?? time();
    $s['facebook_daily_archived'] = '1';
    $s['facebook_publish_completed'] = '1';
    if (schedule_lifecycle_one_line($s['facebook_publish_completed_at'] ?? '') === '') {
        $s['facebook_publish_completed_at'] = date('Y-m-d H:i', $nowTs);
    }
    if (schedule_lifecycle_one_line($s['facebook_publish_completed_by'] ?? '') === '') {
        $s['facebook_publish_completed_by'] = schedule_lifecycle_one_line(
            $s['created_by'] ?? $s['facebook_publish_assigned_to'] ?? '小姐人工排程'
        ) ?: '小姐人工排程';
    }
    $s['facebook_daily_archived_at'] = date('c', $nowTs);
    return true;
}

function schedule_lifecycle_uses_today_publish_definition(array $s): bool
{
    return schedule_lifecycle_one_line($s['publish_definition'] ?? '') === 'today_every_5_minutes_close_date_independent';
}

function schedule_lifecycle_is_cancelled(array $s): bool
{
    $status = schedule_lifecycle_one_line($s['status'] ?? '');
    $publishStatus = schedule_lifecycle_one_line($s['publish_status'] ?? '');
    $progressStatus = schedule_lifecycle_one_line($s['progress_status'] ?? '');
    $workerStatus = schedule_lifecycle_one_line($s['facebook_worker_status'] ?? '');
    $queueStatus = schedule_lifecycle_one_line($s['facebook_queue_status'] ?? '');
    $values = array_filter([$status, $publishStatus, $progressStatus, $workerStatus, $queueStatus]);
    foreach ($values as $value) {
        $normalized = strtolower($value);
        if (in_array($normalized, ['cancelled', 'canceled', 'deleted', 'disabled'], true)) return true;
        if (in_array($value, [
            '取消', '已取消', '刪除', '停用', '不上架', '已取消不上架', '已取消上架',
            '取消不上架', '取消上架', '已下架',
        ], true)) return true;
        if (mb_stripos($value, '取消', 0, 'UTF-8') !== false && mb_stripos($value, '上架', 0, 'UTF-8') !== false) return true;
    }
    return false;
}

function schedule_lifecycle_is_posted_status(array $s): bool
{
    $status = schedule_lifecycle_one_line($s['publish_status'] ?? $s['status'] ?? '');
    return in_array($status, [
        '已上架', '上架成功', '自行上架中', '在線販售中',
        'published', 'pending_review', '待核對 Facebook 貼文',
    ], true);
}

function schedule_lifecycle_next_publish_slot(int $nowTs): array
{
    static $counters = [];
    for ($day = 0; $day < 60; $day++) {
        $date = date('Y-m-d', strtotime('+' . $day . ' day', $nowTs));
        foreach (schedule_lifecycle_close_times_for_date($date) as $closeTime) {
            $closeTs = strtotime($date . ' ' . $closeTime);
            if ($closeTs === false) continue;
            $key = $date . ' ' . $closeTime;
            $idx = (int)($counters[$key] ?? 0);
            $publishTs = $closeTs - (8 * 3600) + ($idx * 300);
            if ($publishTs > $nowTs && $publishTs <= ($closeTs - 6 * 3600)) {
                $counters[$key] = $idx + 1;
                return [
                    'publish_at' => date('Y-m-d H:i', $publishTs),
                    'close_at' => date('Y-m-d H:i', $closeTs),
                    'slot_label' => ($day === 0 ? 'same_day_' : 'next_day_') . str_replace(':', '', $closeTime),
                ];
            }
        }
    }
    $tomorrow = date('Y-m-d', strtotime('+1 day', $nowTs));
    $fallbackTime = schedule_lifecycle_is_weekend_date($tomorrow) ? '23:59' : '12:59';
    return [
        'publish_at' => date('Y-m-d H:i', strtotime($tomorrow . ' ' . $fallbackTime) - (8 * 3600)),
        'close_at' => $tomorrow . ' ' . $fallbackTime,
        'slot_label' => 'fallback_next_day_' . str_replace(':', '', $fallbackTime),
    ];
}

function schedule_lifecycle_next_product_slot(int $nowTs, string $productId, array &$occupied): array
{
    static $counters = [];
    for ($day = 0; $day < 120; $day++) {
        $date = date('Y-m-d', strtotime('+' . $day . ' day', $nowTs));
        foreach (schedule_lifecycle_close_times_for_date($date) as $closeTime) {
            $occupiedKey = $productId . '|' . $date . '|' . $closeTime;
            if ($productId !== '' && isset($occupied[$occupiedKey])) continue;
            $closeTs = strtotime($date . ' ' . $closeTime);
            if ($closeTs === false) continue;
            $counterKey = $date . ' ' . $closeTime;
            $idx = (int)($counters[$counterKey] ?? 0);
            $publishTs = $closeTs - (8 * 3600) + ($idx * 300);
            if ($publishTs <= $nowTs || $publishTs > ($closeTs - 6 * 3600)) continue;
            $counters[$counterKey] = $idx + 1;
            if ($productId !== '') $occupied[$occupiedKey] = true;
            return [
                'publish_at' => date('Y-m-d H:i', $publishTs),
                'close_at' => date('Y-m-d H:i', $closeTs),
                'slot_label' => ($day === 0 ? 'same_day_' : 'next_day_') . str_replace(':', '', $closeTime),
            ];
        }
    }
    return schedule_lifecycle_next_publish_slot($nowTs);
}

function schedule_lifecycle_allocate_day_time(string $date, array &$counters, string $baseTime = '12:00', int $intervalSeconds = 600): int
{
    $baseTs = strtotime($date . ' ' . $baseTime);
    if ($baseTs === false) return 0;
    $idx = (int)($counters[$date] ?? 0);
    $counters[$date] = $idx + 1;
    return $baseTs + ($idx * $intervalSeconds);
}

function schedule_lifecycle_after_six_target_ts(int $publishTs, array &$counters): int
{
    if ($publishTs <= 0) return 0;
    $publishDate = date('Y-m-d', $publishTs);
    $sixTs = strtotime($publishDate . ' 18:00');
    if ($sixTs === false || $publishTs < $sixTs) return 0;
    $targetDate = date('Y-m-d', strtotime('+1 day', $publishTs));
    return schedule_lifecycle_allocate_day_time($targetDate, $counters, '12:00', 300);
}

function schedule_lifecycle_roll_close_after_publish(string $oldCloseAt, int $oldPublishTs, int $newPublishTs): string
{
    $oldCloseTs = schedule_lifecycle_parse_ts($oldCloseAt);
    $targetDate = date('Y-m-d', $newPublishTs);
    $closeTime = $oldCloseTs > 0 ? date('H:i', $oldCloseTs) : '23:59';
    $oldPublishDate = $oldPublishTs > 0 ? date('Y-m-d', $oldPublishTs) : '';
    $oldCloseDate = $oldCloseTs > 0 ? date('Y-m-d', $oldCloseTs) : '';
    if ($oldCloseTs <= 0 || $oldCloseTs <= $newPublishTs || ($oldPublishDate !== '' && $oldCloseDate <= $oldPublishDate)) {
        if ($oldPublishDate !== '' && $oldCloseDate <= $oldPublishDate) $closeTime = '23:59';
        $candidate = strtotime($targetDate . ' ' . $closeTime);
        if ($candidate === false || $candidate <= $newPublishTs) $candidate = strtotime($targetDate . ' 23:59');
        return date('Y-m-d H:i', $candidate ?: ($newPublishTs + 5 * 3600));
    }
    return date('Y-m-d H:i', $oldCloseTs);
}

function schedule_lifecycle_apply_after_six_rollover(array &$s, array &$counters, ?int $nowTs = null): bool
{
    if (schedule_lifecycle_is_held($s)) return false;
    if (!empty($s['operator_selected_times']) || !empty($s['operator_time_locked'])) return false;
    $nowTs = $nowTs ?? time();
    if (!schedule_lifecycle_is_codex_managed($s)) return false;
    if (schedule_lifecycle_one_line($s['publish_definition'] ?? '') === 'auction_auto_hourly_six_hour_guard') return false;
    if (schedule_lifecycle_is_cancelled($s) || schedule_lifecycle_is_listed($s) || schedule_lifecycle_uses_today_publish_definition($s)) return false;
    $publishAt = schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
    $publishTs = schedule_lifecycle_parse_ts($publishAt);
    $targetTs = schedule_lifecycle_after_six_target_ts($publishTs, $counters);
    if ($targetTs <= 0) return false;
    if (schedule_lifecycle_one_line($s['auto_after_six_from_publish_at'] ?? '') === '') $s['auto_after_six_from_publish_at'] = $publishAt;
    if (schedule_lifecycle_one_line($s['auto_after_six_from_close_at'] ?? '') === '') $s['auto_after_six_from_close_at'] = schedule_lifecycle_one_line($s['close_at'] ?? $s['close_remind_at'] ?? '');
    $target = date('Y-m-d H:i', $targetTs);
    $close = schedule_lifecycle_roll_close_after_publish(schedule_lifecycle_one_line($s['close_at'] ?? $s['close_remind_at'] ?? ''), $publishTs, $targetTs);
    $s['publish_at'] = $target;
    $s['scheduled_publish_at'] = $target;
    $s['close_at'] = $close;
    $s['close_remind_at'] = $close;
    $s['facebook_worker_status'] = 'queued_pending_build';
    $s['auto_after_six_at'] = date('c', $nowTs);
    $s['auto_after_six_reason'] = 'publish_time_at_or_after_1800_roll_to_next_day_1200';
    $s['auto_after_six_interval_minutes'] = 5;
    $s['next_reminder_at'] = date('Y-m-d H:i', $targetTs + 3600);
    return true;
}

function schedule_lifecycle_latest_publish_ts_from_close($closeAt, int $guardSeconds = 21600): int
{
    $closeTs = schedule_lifecycle_parse_ts($closeAt);
    if ($closeTs <= 0) return 0;
    return $closeTs - $guardSeconds;
}

function schedule_lifecycle_apply_publish_guard(array &$s, ?int $nowTs = null, int $guardSeconds = 21600): bool
{
    if (schedule_lifecycle_is_held($s)) return false;
    if (!empty($s['operator_selected_times']) || !empty($s['operator_time_locked'])) return false;
    $nowTs = $nowTs ?? time();
    if (!schedule_lifecycle_is_codex_managed($s)) return false;
    if (schedule_lifecycle_is_cancelled($s) || schedule_lifecycle_is_listed($s) || schedule_lifecycle_uses_today_publish_definition($s)) return false;
    $closeTs = schedule_lifecycle_parse_ts($s['close_at'] ?? $s['close_remind_at'] ?? '');
    if ($closeTs <= 0 || $closeTs <= $nowTs) return false;

    $latestPublishTs = $closeTs - $guardSeconds;
    $publishAt = schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
    $publishTs = schedule_lifecycle_parse_ts($publishAt);
    $targetTs = 0;
    $reason = '';

    if ($nowTs >= $latestPublishTs) {
        $slot = schedule_lifecycle_next_publish_slot($nowTs);
        $targetTs = schedule_lifecycle_parse_ts($slot['publish_at'] ?? '');
        $newClose = schedule_lifecycle_one_line($slot['close_at'] ?? '');
        if ($targetTs <= 0 || $newClose === '') return false;
        $s['close_at'] = $newClose;
        $s['close_remind_at'] = $newClose;
        $reason = 'inside_final_6_hours_rescheduled_next_slot';
    } elseif ($publishTs <= 0 || $publishTs > $latestPublishTs) {
        $targetTs = $latestPublishTs;
        $reason = 'publish_time_after_close_guard';
    }

    if ($targetTs <= 0) return false;

    $target = date('Y-m-d H:i', $targetTs);
    if ($publishAt !== '' && schedule_lifecycle_one_line($s['auto_publish_guard_from_publish_at'] ?? '') === '') {
        $s['auto_publish_guard_from_publish_at'] = $publishAt;
    }
    $s['publish_at'] = $target;
    $s['scheduled_publish_at'] = $target;
    $s['facebook_worker_status'] = 'queued_pending_build';
    $s['auto_publish_guard_at'] = date('c', $nowTs);
    $s['auto_publish_guard_reason'] = $reason;
    $s['auto_publish_guard_hours_before_close'] = 6;
    $s['auto_publish_urgent_delay_minutes'] = 0;
    $s['next_reminder_at'] = date('Y-m-d H:i', $targetTs + 3600);
    return true;
}

function schedule_lifecycle_apply_hourly_publish_plan(array &$schedules, ?int $nowTs = null): int
{
    $nowTs = $nowTs ?? time();
    $groups = [];
    foreach ($schedules as $index => $schedule) {
        if (!is_array($schedule) || schedule_lifecycle_is_held($schedule) || !schedule_lifecycle_is_codex_managed($schedule) || schedule_lifecycle_is_cancelled($schedule) || schedule_lifecycle_is_listed($schedule)) continue;
        if (schedule_lifecycle_one_line($schedule['publish_definition'] ?? '') !== 'auction_auto_hourly_six_hour_guard') continue;
        $closeAt = schedule_lifecycle_one_line($schedule['close_at'] ?? $schedule['close_remind_at'] ?? '');
        $closeTs = schedule_lifecycle_parse_ts($closeAt);
        if ($closeTs <= $nowTs) continue;
        $planKey = 'six_hour_hard_guard_v2|' . $closeAt;
        if (schedule_lifecycle_one_line($schedule['auto_publish_plan_key'] ?? '') === $planKey) continue;
        $groups[$closeAt][] = $index;
    }

    $changed = 0;
    foreach ($groups as $closeAt => $indexes) {
        usort($indexes, static function (int $a, int $b) use ($schedules): int {
            $left = schedule_lifecycle_one_line($schedules[$a]['created_at'] ?? $schedules[$a]['id'] ?? '');
            $right = schedule_lifecycle_one_line($schedules[$b]['created_at'] ?? $schedules[$b]['id'] ?? '');
            return strcmp($left, $right);
        });
        $closeTs = schedule_lifecycle_parse_ts($closeAt);
        $deadlineTs = $closeTs - 21600;
        if ($nowTs >= $deadlineTs) {
            foreach ($indexes as $index) {
                $slot = schedule_lifecycle_next_publish_slot($nowTs);
                $schedules[$index]['publish_at'] = $slot['publish_at'];
                $schedules[$index]['scheduled_publish_at'] = $slot['publish_at'];
                $schedules[$index]['close_at'] = $slot['close_at'];
                $schedules[$index]['close_remind_at'] = $slot['close_at'];
                $schedules[$index]['auto_publish_plan_key'] = 'six_hour_hard_guard_v2|' . $slot['close_at'];
                $schedules[$index]['auto_publish_plan_mode'] = 'missed_deadline_rescheduled_next_slot';
                $schedules[$index]['auto_publish_guard_hours_before_close'] = 6;
                $schedules[$index]['auto_publish_urgent_delay_minutes'] = 0;
                $schedules[$index]['facebook_worker_status'] = 'queued_pending_build';
                $schedules[$index]['next_reminder_at'] = $slot['publish_at'];
                $changed++;
            }
            continue;
        }
        $startTs = max((int)(ceil(($nowTs + 600) / 3600) * 3600), $closeTs - 8 * 3600);
        $hours = max(1, (int)floor(($deadlineTs - $startTs) / 3600) + 1);
        $perHour = max(1, (int)ceil(count($indexes) / $hours));
        $spacing = max(60, (int)floor(3600 / $perHour));
        foreach (array_values($indexes) as $position => $index) {
            $hourIndex = (int)floor($position / $perHour);
            $withinHour = $position % $perHour;
            $targetTs = $startTs + ($hourIndex * 3600) + ($withinHour * $spacing);
            $targetTs = min($targetTs, $deadlineTs);
            $target = date('Y-m-d H:i', $targetTs);
            $schedules[$index]['publish_at'] = $target;
            $schedules[$index]['scheduled_publish_at'] = $target;
            $schedules[$index]['auto_publish_plan_key'] = 'six_hour_hard_guard_v2|' . $closeAt;
            $schedules[$index]['auto_publish_plan_mode'] = 'system_arranged_before_six_hour_deadline';
            $schedules[$index]['auto_publish_rate_per_hour'] = $perHour;
            $schedules[$index]['auto_publish_guard_hours_before_close'] = 6;
            $schedules[$index]['auto_publish_urgent_delay_minutes'] = 0;
            $schedules[$index]['facebook_worker_status'] = 'queued_pending_build';
            $schedules[$index]['next_reminder_at'] = date('Y-m-d H:i', $targetTs);
            $changed++;
        }
    }
    return $changed;
}

function schedule_lifecycle_phase(array $s, ?int $nowTs = null): string
{
    $nowTs = $nowTs ?? time();
    $closeTs = schedule_lifecycle_parse_ts($s['close_at'] ?? '');
    if (!schedule_lifecycle_is_listed($s)) return 'publish_prepare';
    if ($closeTs <= 0 || $closeTs > $nowTs) return 'listed_today';
    $winner = schedule_lifecycle_one_line(($s['winner'] ?? '') . ' ' . ($s['winner_facebook'] ?? '') . ' ' . ($s['winner_phone'] ?? ''));
    if ($winner === '') return 'winner_announcement';
    $orderStatus = schedule_lifecycle_one_line($s['order_status'] ?? '');
    if (!in_array($orderStatus, ['已記單', '備貨中', '等待出貨', '已出貨', '完成'], true)) return 'settlement_edit';
    return 'settlement_done';
}

function schedule_lifecycle_is_today_listed(array $s, string $today, ?int $nowTs = null): bool
{
    if (!schedule_lifecycle_is_listed($s)) return false;
    $actual = substr((string)($s['actual_publish_at'] ?? ''), 0, 10);
    $planned = substr((string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? ''), 0, 10);
    $close = substr((string)($s['close_at'] ?? ''), 0, 10);
    return $actual === $today || $planned === $today || $close === $today;
}

function schedule_lifecycle_is_prepare_candidate(array $s): bool
{
    if (schedule_lifecycle_is_cancelled($s)) return false;
    if (schedule_lifecycle_is_listed($s)) return false;
    $workerStatus = strtolower(schedule_lifecycle_one_line($s['facebook_worker_status'] ?? ''));
    $queueStatus = strtolower(schedule_lifecycle_one_line($s['facebook_queue_status'] ?? ''));
    $facebookStatus = strtolower(schedule_lifecycle_one_line($s['facebook_status'] ?? ''));
    $progressStatus = strtolower(schedule_lifecycle_one_line($s['progress_status'] ?? ''));
    $publishStatus = schedule_lifecycle_one_line($s['publish_status'] ?? $s['status'] ?? '');
    if (in_array($workerStatus, ['scheduled', 'facebook_scheduled', 'manual_facebook_scheduled'], true)) return false;
    if (in_array($workerStatus, ['facebook_posted_url_pending', 'facebook_posted_url_missing'], true)) return false;
    if (in_array($queueStatus, ['scheduled', 'facebook_scheduled'], true)) return false;
    if ($facebookStatus === 'scheduled_visible_no_url') return false;
    if (in_array($progressStatus, ['scheduled', 'facebook_scheduled'], true)) return false;
    if ($publishStatus === '已排入 Facebook 預約') return false;
    $orderStatus = schedule_lifecycle_one_line($s['order_status'] ?? '');
    return !in_array($orderStatus, ['已出貨', '完成', '取消'], true);
}

function schedule_lifecycle_can_manual_retry(array $s, array $task = []): bool
{
    if (schedule_lifecycle_is_cancelled($s)) return false;
    $scheduleUrl = schedule_lifecycle_one_line($s['post_url'] ?? $s['facebook_post_url'] ?? '');
    if (schedule_lifecycle_real_post_url($scheduleUrl)) return false;
    $result = is_array($task['result'] ?? null) ? $task['result'] : [];
    $taskUrl = schedule_lifecycle_one_line($result['postUrl'] ?? '');
    if (schedule_lifecycle_real_post_url($taskUrl)) return false;
    $worker = strtolower(schedule_lifecycle_one_line($s['facebook_worker_status'] ?? ($task['status'] ?? '')));
    $taskStatus = strtolower(schedule_lifecycle_one_line($task['status'] ?? ''));
    $retryStates = ['pending_review', 'failed', 'unlisted_manual_review', 'archived_expired'];
    if (!in_array($worker, $retryStates, true) && !in_array($taskStatus, $retryStates, true)) return false;
    if (schedule_lifecycle_is_listed($s)) return false;
    $audit = is_array($task['audit'] ?? null) ? $task['audit'] : [];
    $reason = schedule_lifecycle_one_line($s['facebook_worker_error'] ?? ($result['failureReason'] ?? ''));
    $submitted = schedule_lifecycle_one_line($audit['submissionStartedAt'] ?? '') !== '';
    if (!$submitted) return true;
    return str_contains($reason, 'admin_confirmed_not_posted')
        || str_contains($reason, 'orphan_before_submit')
        || str_contains($reason, '未開始送出')
        || str_contains($reason, 'before_submit');
}

function schedule_lifecycle_clear_manual_retry_hold(array &$s): void
{
    $s['facebook_worker_status'] = 'queued';
    $s['facebook_queue_status'] = 'queued';
    $s['facebook_worker_error'] = '';
    $s['progress_status'] = 'queued';
    $publishStatus = schedule_lifecycle_one_line($s['publish_status'] ?? $s['status'] ?? '');
    if (in_array($publishStatus, ['上架未確認', '未上架', '上架失敗／待重試', '上架失敗', '上架異常'], true)) {
        $s['publish_status'] = '準備中';
        $s['status'] = '準備中';
    }
    $s['auto_relist_blocked'] = '';
    $s['auto_retry_blocked'] = '';
    $s['manual_unlisted_review'] = '';
    $s['updated_at'] = date('c');
}

function schedule_lifecycle_enforce_weekend_close(array &$schedule): bool
{
    $closeAt = schedule_lifecycle_one_line($schedule['close_at'] ?? $schedule['close_remind_at'] ?? '');
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $closeAt, $match)) return false;
    $dayTs = strtotime($match[1] . ' 12:00:00');
    if ($dayTs === false || !in_array((int)date('w', $dayTs), [0, 6], true) || $match[2] === '23:59') return false;
    $corrected = $match[1] . ' 23:59';
    $schedule['close_at'] = $corrected;
    $schedule['close_remind_at'] = $corrected;
    foreach (['codex_post_text', 'reminder_draft', 'progress_note'] as $field) {
        if (isset($schedule[$field]) && is_string($schedule[$field])) $schedule[$field] = str_replace($closeAt, $corrected, $schedule[$field]);
    }
    $schedule['weekend_close_corrected_from'] = $closeAt;
    $schedule['weekend_close_corrected_at'] = date('c');
    return true;
}

/**
 * A rescheduled auction is a new Facebook publishing attempt.  Preserve the
 * previous article as audit evidence, then remove every field that could make
 * reminder/settlement workers treat that old article as the current auction.
 */
function schedule_lifecycle_reset_facebook_attempt(array &$s, string $reason, ?int $nowTs = null): void
{
    $nowTs = $nowTs ?? time();
    $oldUrl = schedule_lifecycle_one_line($s['post_url'] ?? $s['facebook_post_url'] ?? $s['actual_post_url'] ?? '');
    $oldPublishedAt = schedule_lifecycle_one_line($s['actual_publish_at'] ?? $s['published_at'] ?? $s['facebook_publish_completed_at'] ?? '');
    if ($oldUrl !== '' || $oldPublishedAt !== '') {
        if (!isset($s['superseded_facebook_attempts']) || !is_array($s['superseded_facebook_attempts'])) {
            $s['superseded_facebook_attempts'] = [];
        }
        $s['superseded_facebook_attempts'][] = [
            'post_url' => $oldUrl,
            'published_at' => $oldPublishedAt,
            'publish_at' => schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? ''),
            'close_at' => schedule_lifecycle_one_line($s['close_at'] ?? $s['close_remind_at'] ?? ''),
            'reason' => $reason,
            'superseded_at' => date('c', $nowTs),
        ];
        if (count($s['superseded_facebook_attempts']) > 20) {
            $s['superseded_facebook_attempts'] = array_slice($s['superseded_facebook_attempts'], -20);
        }
    }
    foreach ([
        'post_url', 'facebook_post_url', 'actual_post_url', 'actual_publish_at',
        'published_at', 'facebook_publish_completed_at', 'facebook_post_verified_at',
        'facebook_post_verified_by', 'facebook_post_evidence_note', 'duplicate_post_url',
        'duplicate_of_schedule_id', 'last_reminder_at', 'reminder_error',
        'settlement_ready_at', 'closing_queue_started_at',
    ] as $field) {
        $s[$field] = '';
    }
    $s['facebook_publish_completed'] = false;
    $s['published_quantity'] = 0;
    $s['current_bid'] = 0;
    $s['winning_price'] = 0;
    $s['highest_bidder'] = '';
    $s['current_bidder'] = '';
    $s['winner'] = '';
    $s['winner_facebook'] = '';
    $s['reminder_status'] = '等待本次 Facebook 貼文正式上架';
    $s['closing_queue_status'] = '';
    $s['codex_status'] = '等待本次 Facebook 排程';
    $s['group_publish_status'] = '等待 Facebook 排程';
}

/**
 * Move a recently missed, unsuccessful Facebook publish forward by exactly one
 * calendar day.  Keep the publish and close clock times unchanged; the weekend
 * close rule is applied afterwards so Saturday/Sunday auctions still close at
 * 23:59.
 *
 * Historical schedules are deliberately ignored.  A row is eligible only
 * while its planned publish date is today or yesterday.  If it misses the new
 * time again, the next lifecycle run can move it one more day.
 */
function schedule_lifecycle_shift_failed_publish_one_day(array &$s, ?int $nowTs = null): bool
{
    if (schedule_lifecycle_is_held($s)) return false;
    if (!empty($s['operator_selected_times'])) return false;
    if (schedule_lifecycle_one_line($s['publish_mode'] ?? '') === 'instant'
        || schedule_lifecycle_one_line($s['publish_definition'] ?? '') === 'auction_instant_publish') return false;
    $nowTs = $nowTs ?? time();
    if (!empty($s['auto_relist_blocked']) || in_array(schedule_lifecycle_one_line($s['facebook_worker_status'] ?? ''), ['pending_url_correction','facebook_posted_url_pending','facebook_posted_url_missing'], true)) return false;
    if (!schedule_lifecycle_is_codex_managed($s)
        || schedule_lifecycle_is_cancelled($s)
        || schedule_lifecycle_is_listed($s)
        || schedule_lifecycle_is_posted_status($s)
        || !empty($s['facebook_publish_completed'])) return false;

    $publishAt = schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
    $publishTs = schedule_lifecycle_parse_ts($publishAt);
    if ($publishTs <= 0 || $publishTs >= $nowTs) return false;

    // Do not revive old historical rows.  Yesterday/today is enough to provide
    // a daily retry loop while protecting archived schedules from bulk changes.
    $oldestEligibleTs = strtotime('-1 day', strtotime(date('Y-m-d 00:00', $nowTs)));
    if ($oldestEligibleTs === false || $publishTs < $oldestEligibleTs) return false;

    $workerStatus = strtolower(schedule_lifecycle_one_line($s['facebook_worker_status'] ?? ''));
    $failureStates = [
        'archived_expired', 'failed', 'blocked', 'blocked_before_queue',
        'pending_review', 'queued', 'queued_pending_build', 'running', '',
    ];
    if (!in_array($workerStatus, $failureStates, true)) return false;

    $closeAt = schedule_lifecycle_one_line($s['close_at'] ?? $s['close_remind_at'] ?? '');
    $closeTs = schedule_lifecycle_parse_ts($closeAt);
    $newPublishTs = strtotime('+1 day', $publishTs);
    if ($newPublishTs === false) return false;
    $newCloseTs = $closeTs > 0 ? strtotime('+1 day', $closeTs) : false;

    if (schedule_lifecycle_one_line($s['auto_rescheduled_from_publish_at'] ?? '') === '') {
        $s['auto_rescheduled_from_publish_at'] = $publishAt;
    }
    if ($closeAt !== '' && schedule_lifecycle_one_line($s['auto_rescheduled_from_close_at'] ?? '') === '') {
        $s['auto_rescheduled_from_close_at'] = $closeAt;
    }

    $newPublishAt = date('Y-m-d H:i', $newPublishTs);
    schedule_lifecycle_reset_facebook_attempt($s, 'unsuccessful_publish_shifted_one_day', $nowTs);
    // A failed "instant" attempt becomes a normal scheduled retry.  Otherwise
    // the worker would ignore the shifted clock time and post immediately.
    $s['publish_mode'] = 'scheduled';
    $s['publish_definition'] = 'auction_retry_same_time_next_day';
    $s['publish_at'] = $newPublishAt;
    $s['scheduled_publish_at'] = $newPublishAt;
    if ($newCloseTs !== false) {
        $newCloseAt = date('Y-m-d H:i', $newCloseTs);
        $s['close_at'] = $newCloseAt;
        $s['close_remind_at'] = $newCloseAt;
    }
    $s['next_reminder_at'] = date('Y-m-d H:i', $newPublishTs + 3600);
    $s['publish_status'] = '未上架';
    $s['status'] = '未上架';
    $s['facebook_worker_status'] = 'queued_pending_build';
    $s['facebook_worker_error'] = '';
    $s['completion_status'] = '失敗自動延後一天待上架';
    $s['progress_status'] = 'rescheduled';
    $s['remaining_publish_quantity'] = max(1, (int)($s['required_publish_quantity'] ?? $s['reserved_quantity'] ?? $s['quantity'] ?? 1));
    $s['auto_rescheduled_at'] = date('c', $nowTs);
    $s['auto_reschedule_reason'] = 'unsuccessful_publish_shifted_one_day';
    $s['auto_reschedule_days'] = (int)($s['auto_reschedule_days'] ?? 0) + 1;
    schedule_lifecycle_apply_weekend_close_rule($s);
    return true;
}

function schedule_lifecycle_normalize(array &$schedules, ?int $nowTs = null): array
{
    $nowTs = $nowTs ?? time();
    $autoPlanMigrated = 0;
    foreach ($schedules as &$pendingSchedule) {
        if (is_array($pendingSchedule) && schedule_lifecycle_is_held($pendingSchedule)) continue;
        if (is_array($pendingSchedule) && schedule_lifecycle_enforce_weekend_close($pendingSchedule)) $autoPlanMigrated++;
        if (!is_array($pendingSchedule) || !schedule_lifecycle_is_codex_managed($pendingSchedule) || schedule_lifecycle_is_cancelled($pendingSchedule) || schedule_lifecycle_is_listed($pendingSchedule)) continue;
        if (schedule_lifecycle_one_line($pendingSchedule['publish_definition'] ?? '') !== 'today_every_5_minutes_close_date_independent') continue;
        $pendingSchedule['publish_definition'] = 'auction_auto_hourly_six_hour_guard';
        unset($pendingSchedule['auto_publish_plan_key']);
        $autoPlanMigrated++;
    }
    unset($pendingSchedule);
    $hourlyDistributed = schedule_lifecycle_apply_hourly_publish_plan($schedules, $nowTs);
    $changed = $hourlyDistributed + $autoPlanMigrated;
    $rescheduled = 0;
    $groupUrlFixed = 0;
    $afterSixRollover = 0;
    $failedPublishRollover = 0;
    $afterSixCounters = [];
    $occupiedProductSlots = [];
    foreach ($schedules as &$archiveManualSchedule) {
        if (!is_array($archiveManualSchedule)) continue;
        if (schedule_lifecycle_archive_manual_listing_to_daily_report($archiveManualSchedule, $nowTs)) {
            $changed++;
        }
    }
    unset($archiveManualSchedule);
    foreach ($schedules as $existing) {
        if (!is_array($existing) || schedule_lifecycle_is_cancelled($existing)) continue;
        $existingProductId = schedule_lifecycle_one_line($existing['product_id'] ?? '');
        $existingCloseTs = schedule_lifecycle_parse_ts($existing['close_at'] ?? $existing['close_remind_at'] ?? '');
        if ($existingProductId === '' || $existingCloseTs <= $nowTs) continue;
        $occupiedProductSlots[$existingProductId . '|' . date('Y-m-d', $existingCloseTs) . '|' . date('H:i', $existingCloseTs)] = true;
    }
    foreach ($schedules as &$s) {
        if (!is_array($s) || schedule_lifecycle_is_held($s)) continue;
        $changedThis = false;
        if (schedule_lifecycle_apply_weekend_close_rule($s)) {
            $changedThis = true;
        }
        $postUrl = schedule_lifecycle_one_line($s['post_url'] ?? '');
        if ($postUrl !== '' && !schedule_lifecycle_real_post_url($postUrl) && schedule_lifecycle_group_url($postUrl)) {
            if (schedule_lifecycle_one_line($s['facebook_group_url'] ?? '') === '') $s['facebook_group_url'] = $postUrl;
            $s['post_url'] = '';
            $s['post_url_cleared_reason'] = 'generic_group_url_not_real_post';
            $groupUrlFixed++;
            $changedThis = true;
        }
        $listed = schedule_lifecycle_is_listed($s);
        // Keep previously shifted instant failures as scheduled retries even if
        // an older worker process wrote a stale copy of the row back to disk.
        $isFailedPublishRetry = empty($s['operator_selected_times']) && schedule_lifecycle_one_line($s['auto_reschedule_reason'] ?? '') === 'unsuccessful_publish_shifted_one_day'
            && schedule_lifecycle_one_line($s['publish_mode'] ?? '') !== 'instant'
            && schedule_lifecycle_one_line($s['publish_definition'] ?? '') !== 'auction_instant_publish';
        if (!$listed
            && !schedule_lifecycle_is_cancelled($s)
            && $isFailedPublishRetry
            && (schedule_lifecycle_one_line($s['publish_mode'] ?? '') === 'instant'
                || schedule_lifecycle_one_line($s['publish_definition'] ?? '') === 'auction_instant_publish')) {
            $s['publish_mode'] = 'scheduled';
            $s['publish_definition'] = 'auction_retry_same_time_next_day';
            $changedThis = true;
        }
        if (!$listed && !schedule_lifecycle_is_cancelled($s) && $isFailedPublishRetry) {
            $originalRetryPublishTs = schedule_lifecycle_parse_ts($s['auto_rescheduled_from_publish_at'] ?? '');
            $originalRetryCloseTs = schedule_lifecycle_parse_ts($s['auto_rescheduled_from_close_at'] ?? '');
            $retryDays = max(1, (int)($s['auto_reschedule_days'] ?? 1));
            if ($originalRetryPublishTs > 0) {
                $expectedRetryPublishAt = date('Y-m-d H:i', strtotime('+' . $retryDays . ' day', $originalRetryPublishTs));
                if (schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '') !== $expectedRetryPublishAt) {
                    $s['publish_at'] = $expectedRetryPublishAt;
                    $s['scheduled_publish_at'] = $expectedRetryPublishAt;
                    $s['next_reminder_at'] = date('Y-m-d H:i', schedule_lifecycle_parse_ts($expectedRetryPublishAt) + 3600);
                    $changedThis = true;
                }
            }
            if ($originalRetryCloseTs > 0) {
                $expectedRetryCloseAt = date('Y-m-d H:i', strtotime('+' . $retryDays . ' day', $originalRetryCloseTs));
                if (schedule_lifecycle_is_weekend_date(substr($expectedRetryCloseAt, 0, 10))) {
                    $expectedRetryCloseAt = substr($expectedRetryCloseAt, 0, 10) . ' 23:59';
                }
                if (schedule_lifecycle_one_line($s['close_at'] ?? $s['close_remind_at'] ?? '') !== $expectedRetryCloseAt) {
                    $s['close_at'] = $expectedRetryCloseAt;
                    $s['close_remind_at'] = $expectedRetryCloseAt;
                    $changedThis = true;
                }
            }
        }
        $isInstant = !empty($s['operator_selected_times']) || schedule_lifecycle_one_line($s['publish_mode'] ?? '') === 'instant'
            || schedule_lifecycle_one_line($s['publish_definition'] ?? '') === 'auction_instant_publish';
        $plannedPublishTs = schedule_lifecycle_parse_ts($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
        $plannedCloseTs = schedule_lifecycle_parse_ts($s['close_at'] ?? $s['close_remind_at'] ?? '');
        if (!$isFailedPublishRetry
            && !$isInstant
            && !$listed
            && !schedule_lifecycle_is_cancelled($s)
            && schedule_lifecycle_is_codex_managed($s)
            && $plannedPublishTs > 0
            && $plannedPublishTs <= $nowTs
            && $plannedCloseTs > $nowTs
            && date('Y-m-d', $plannedCloseTs) === date('Y-m-d', $nowTs)) {
            // A missed publish time must not move an auction whose close time is
            // still later today. Publish it immediately and preserve the close.
            $s['publish_mode'] = 'instant';
            $s['publish_definition'] = 'auction_instant_publish';
            $s['instant_publish_reason'] = 'scheduled_time_passed_close_still_open_today';
            $s['instant_publish_converted_at'] = date('c', $nowTs);
            $s['facebook_worker_status'] = 'queued_pending_build';
            $s['completion_status'] = '待立即上架';
            $isInstant = true;
            $changedThis = true;
        }
        $failedPublishShifted = schedule_lifecycle_shift_failed_publish_one_day($s, $nowTs);
        if ($failedPublishShifted) {
            $failedPublishRollover++;
            $rescheduled++;
            $changedThis = true;
        }
        if (!$isFailedPublishRetry && !$failedPublishShifted && !$isInstant && schedule_lifecycle_apply_after_six_rollover($s, $afterSixCounters, $nowTs)) {
            $afterSixRollover++;
            $changedThis = true;
        }
        if (!$isFailedPublishRetry && !$failedPublishShifted && !$isInstant && schedule_lifecycle_apply_publish_guard($s, $nowTs, 6 * 3600)) {
            $changedThis = true;
        }
        $publishAt = schedule_lifecycle_one_line($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
        $publishTs = schedule_lifecycle_parse_ts($publishAt);
        $closeTsForPastPublish = schedule_lifecycle_parse_ts($s['close_at'] ?? $s['close_remind_at'] ?? '');
        $insideFinalPublishGuard = $closeTsForPastPublish > $nowTs && $nowTs >= ($closeTsForPastPublish - 6 * 3600);
        // Give the Facebook worker time to claim a task that has just become due.
        // Without this grace period, the lifecycle reconciler moves the schedule
        // to the next slot before api_due can claim it (timestamps are minute-only).
        $facebookWorkerStatus = schedule_lifecycle_one_line($s['facebook_worker_status'] ?? '');
        $insideWorkerClaimGrace = in_array($facebookWorkerStatus, ['queued', 'queued_pending_build', 'running'], true)
            && $publishTs > 0
            && $publishTs >= ($nowTs - 15 * 60);
        if (!$isFailedPublishRetry && !$failedPublishShifted && !$isInstant && schedule_lifecycle_is_codex_managed($s) && !$listed && !$insideFinalPublishGuard && !$insideWorkerClaimGrace && !schedule_lifecycle_is_cancelled($s) && !schedule_lifecycle_uses_today_publish_definition($s) && schedule_lifecycle_one_line($s['publish_definition'] ?? '') !== 'auction_auto_hourly_six_hour_guard' && $publishTs > 0 && $publishTs < $nowTs) {
            $productId = schedule_lifecycle_one_line($s['product_id'] ?? '');
            $slot = schedule_lifecycle_next_product_slot($nowTs, $productId, $occupiedProductSlots);
            if (($slot['slot_label'] ?? '') === 'next_day_1200_after_1800') {
                $slotDate = substr((string)($slot['publish_at'] ?? ''), 0, 10);
                $allocatedTs = schedule_lifecycle_allocate_day_time($slotDate, $afterSixCounters, '12:00', 300);
                if ($allocatedTs > 0) $slot['publish_at'] = date('Y-m-d H:i', $allocatedTs);
            }
            if (schedule_lifecycle_one_line($s['auto_rescheduled_from_publish_at'] ?? '') === '') {
                $s['auto_rescheduled_from_publish_at'] = $publishAt;
            }
            if (schedule_lifecycle_one_line($s['auto_rescheduled_from_close_at'] ?? '') === '') {
                $s['auto_rescheduled_from_close_at'] = schedule_lifecycle_one_line($s['close_at'] ?? '');
            }
            schedule_lifecycle_reset_facebook_attempt($s, 'publish_time_in_past', $nowTs);
            $s['publish_at'] = $slot['publish_at'];
            $s['scheduled_publish_at'] = $slot['publish_at'];
            $s['close_at'] = $slot['close_at'];
            $s['close_remind_at'] = $slot['close_at'];
            $s['next_reminder_at'] = date('Y-m-d H:i', strtotime($slot['publish_at']) + 3600);
            $s['publish_status'] = '未上架';
            $s['status'] = '未上架';
            $s['facebook_worker_status'] = 'queued_pending_build';
            $s['upload_source'] = 'codex';
            $s['automation_owner'] = 'CODEX 上架';
            $s['completion_status'] = '自動補排待上架';
            $s['remaining_publish_quantity'] = max(1, (int)($s['required_publish_quantity'] ?? $s['reserved_quantity'] ?? $s['quantity'] ?? 1));
            $s['auto_rescheduled_at'] = date('c', $nowTs);
            $s['auto_reschedule_reason'] = 'publish_time_in_past';
            $s['auto_reschedule_slot'] = $slot['slot_label'];
            $rescheduled++;
            $changedThis = true;
        }
        $phase = schedule_lifecycle_phase($s, $nowTs);
        if (($s['lifecycle_phase'] ?? '') !== $phase) {
            $s['lifecycle_phase'] = $phase;
            $changedThis = true;
        }
        if ($listed && schedule_lifecycle_one_line($s['actual_publish_at'] ?? '') === '') {
            $s['actual_publish_at'] = date('Y-m-d H:i', $nowTs);
            $changedThis = true;
        }
        if ($changedThis) {
            $s['lifecycle_updated_at'] = date('c', $nowTs);
            $changed++;
        }
    }
    unset($s);

    $closingCandidates = [];
    foreach ($schedules as $idx => &$s) {
        if (!is_array($s) || schedule_lifecycle_is_cancelled($s)) continue;
        $closeTs = schedule_lifecycle_parse_ts($s['close_at'] ?? $s['close_remind_at'] ?? '');
        if ($closeTs <= 0 || $closeTs > $nowTs) continue;
        $unsold = schedule_lifecycle_one_line($s['auction_result'] ?? '') === 'unsold';
        $winner = schedule_lifecycle_one_line(($s['winner'] ?? '') . ' ' . ($s['winner_facebook'] ?? ''));
        $winningPrice = (float)($s['winning_price'] ?? 0);
        if ($unsold || ($winner !== '' && $winningPrice > 0)) {
            if (in_array(schedule_lifecycle_one_line($s['closing_queue_status'] ?? ''), ['active', 'queued', 'blocked_missing_article'], true)) {
                $s['closing_queue_status'] = 'completed';
                $s['closing_queue_completed_at'] = date('c', $nowTs);
                $s['closing_queue_position'] = 0;
                $s['lifecycle_updated_at'] = date('c', $nowTs);
                $changed++;
            }
            continue;
        }
        $postUrl = schedule_lifecycle_one_line($s['post_url'] ?? '');
        if (!schedule_lifecycle_real_post_url($postUrl)) {
            if (schedule_lifecycle_one_line($s['closing_queue_status'] ?? '') !== 'blocked_missing_article') {
                $s['closing_queue_status'] = 'blocked_missing_article';
                $s['closing_queue_started_at'] = date('c', $nowTs);
                $s['closing_queue_position'] = 0;
                $s['winner_exception_type'] = '找不到 Facebook 文章';
                $s['winner_exception_state'] = 'pending';
                $s['winner_exception_updated_at'] = date('c', $nowTs);
                $s['winner_exception_updated_by'] = 'system';
                $s['lifecycle_updated_at'] = date('c', $nowTs);
                $changed++;
            }
            continue;
        }
        $closingCandidates[] = ['index' => $idx, 'close_ts' => $closeTs];
    }
    unset($s);
    usort($closingCandidates, static function ($a, $b) use ($schedules) {
        $cmp = ($a['close_ts'] ?? 0) <=> ($b['close_ts'] ?? 0);
        if ($cmp !== 0) return $cmp;
        $sa = $schedules[$a['index']] ?? [];
        $sb = $schedules[$b['index']] ?? [];
        $qa = (int)($sa['slot_no'] ?? $sa['publish_sequence'] ?? 0);
        $qb = (int)($sb['slot_no'] ?? $sb['publish_sequence'] ?? 0);
        $cmp = $qa <=> $qb;
        return $cmp !== 0 ? $cmp : strcmp((string)($sa['id'] ?? ''), (string)($sb['id'] ?? ''));
    });
    foreach ($closingCandidates as $position => $candidate) {
        $idx = (int)$candidate['index'];
        if (!isset($schedules[$idx]) || !is_array($schedules[$idx])) continue;
        $state = $position === 0 ? 'active' : 'queued';
        if (schedule_lifecycle_one_line($schedules[$idx]['closing_queue_status'] ?? '') !== $state
            || (int)($schedules[$idx]['closing_queue_position'] ?? 0) !== $position + 1) {
            $schedules[$idx]['closing_queue_status'] = $state;
            $schedules[$idx]['closing_queue_position'] = $position + 1;
            if (schedule_lifecycle_one_line($schedules[$idx]['closing_queue_started_at'] ?? '') === '') {
                $schedules[$idx]['closing_queue_started_at'] = date('c', $nowTs);
            }
            $schedules[$idx]['lifecycle_updated_at'] = date('c', $nowTs);
            $changed++;
        }
    }
    return [
        'changed' => $changed,
        'rescheduled' => $rescheduled,
        'failed_publish_rollover' => $failedPublishRollover,
        'after_six_rollover' => $afterSixRollover,
        'hourly_distributed' => $hourlyDistributed,
        'auto_plan_migrated' => $autoPlanMigrated,
        'group_url_fixed' => $groupUrlFixed,
        'closing_active' => count($closingCandidates) > 0 ? 1 : 0,
        'closing_queued' => max(0, count($closingCandidates) - 1),
    ];
}


function schedule_lifecycle_earliest_publish_ts(string $closeAt): int
{
    $end=strtotime($closeAt);if($end===false)return 0;
    $day=date('Y-m-d',$end);$clock=date('H:i',$end);
    if($clock==='12:59')return (int)strtotime($day.' 00:15');
    if($clock==='23:59')return (int)strtotime($day.' 13:15');
    return max((int)strtotime($day.' 00:00'),$end-21600);
}
function schedule_lifecycle_valid_auction_window(int $start,int $end): bool
{
    return $start > 0 && $end - $start >= 7200
        && date('Y-m-d', $start) === date('Y-m-d', $end)
        && $start >= schedule_lifecycle_earliest_publish_ts(date('Y-m-d H:i', $end));
}
