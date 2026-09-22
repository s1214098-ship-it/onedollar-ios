<?php
declare(strict_types=1);

function facebook_daily_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Taipei');
}

function facebook_daily_date(?string $raw = null): string
{
    $raw = trim((string)($raw ?? ''));
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    return (new DateTimeImmutable('now', facebook_daily_timezone()))->format('Y-m-d');
}

function facebook_daily_ymd($value): string
{
    $text = trim((string)$value);
    if ($text === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) return $text;
    // Naive wall-clock values (no timezone) are already Taipei business time.
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}/', $text)
        && !preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $text)) {
        return substr($text, 0, 10);
    }
    try {
        return (new DateTimeImmutable($text))->setTimezone(facebook_daily_timezone())->format('Y-m-d');
    } catch (Exception $e) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $m)) return $m[1];
        $ts = strtotime($text);
        return $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone(facebook_daily_timezone())->format('Y-m-d') : '';
    }
}

function facebook_daily_abs_url(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    if (function_exists('public_base_url')) {
        return rtrim(public_base_url(), '/') . '/' . ltrim($path, '/');
    }
    return $path;
}

function facebook_daily_posted(array $s): bool
{
    $status = trim((string)($s['publish_status'] ?? $s['status'] ?? ''));
    $url = trim((string)($s['post_url'] ?? ''));
    $realPostUrl = function_exists('schedule_lifecycle_real_post_url')
        ? schedule_lifecycle_real_post_url($url)
        : (bool)preg_match('~/posts/|/permalink/|multi_permalinks=|story_fbid=|pfbid~i', $url);
    $manualUrlLookup = facebook_daily_manual_url_lookup($s);
    return in_array($status, ['已上架', '上架成功', 'published', 'pending_review', 'Facebook 已上架／人工找網址'], true)
        || $realPostUrl
        || $manualUrlLookup;
}

function facebook_daily_manual_url_lookup(array $s): bool
{
    $postUrl = trim((string)($s['post_url'] ?? ''));
    if ($postUrl !== '') return false;
    $workerStatus = trim((string)($s['facebook_worker_status'] ?? ''));
    return $workerStatus === 'facebook_posted_url_missing'
        || (!empty($s['manual_post_url_required']) && (string)$s['manual_post_url_required'] !== '0');
}

function facebook_daily_verified_post(array $s): bool
{
    if (function_exists('schedule_lifecycle_is_listed')) return schedule_lifecycle_is_listed($s);
    $url = trim((string)($s['post_url'] ?? ''));
    $workerStatus = trim((string)($s['facebook_worker_status'] ?? ''));
    $completed = !empty($s['facebook_publish_completed']) && (string)$s['facebook_publish_completed'] !== '0';
    return $url !== '' && ($workerStatus === 'published' || $completed)
        && (bool)preg_match('~/posts/|/permalink/|multi_permalinks=|story_fbid=|pfbid~i', $url);
}

function facebook_daily_publish_completed(array $s): bool
{
    if (facebook_daily_verified_post($s)) return true;

    // CODEX worker is the authoritative source for automated publication.
    // A queue item is complete only after Facebook returned a real post URL;
    // queued / pending_review / blocked items must stay in the schedule workspace.
    $workerStatus = trim((string)($s['facebook_worker_status'] ?? ''));
    $postUrl = trim((string)($s['post_url'] ?? ''));
    $realPostUrl = function_exists('schedule_lifecycle_real_post_url')
        ? schedule_lifecycle_real_post_url($postUrl)
        : (bool)preg_match('~/posts/|/permalink/|multi_permalinks=|story_fbid=|pfbid~i', $postUrl);
    return $workerStatus === 'published' && $realPostUrl;
}

function facebook_daily_native_scheduled(array $s): bool
{
    if (function_exists('schedule_lifecycle_is_codex_managed') && !schedule_lifecycle_is_codex_managed($s)) return false;
    $workerStatus = trim((string)($s['facebook_worker_status'] ?? ''));
    $queueStatus = trim((string)($s['facebook_queue_status'] ?? ''));
    $facebookStatus = trim((string)($s['facebook_status'] ?? ''));
    $status = trim((string)($s['progress_status'] ?? $s['publish_status'] ?? $s['status'] ?? ''));
    if (preg_match('/^(blocked|failed|cancelled|canceled)/i', $workerStatus)) return false;
    if (preg_match('/^(blocked|failed|cancelled|canceled)/i', $queueStatus)) return false;
    if (in_array($workerStatus, ['scheduled', 'facebook_scheduled'], true)) return true;
    if (in_array($queueStatus, ['scheduled', 'facebook_scheduled'], true)) return true;
    if ($facebookStatus === 'scheduled_visible_no_url') return true;
    if (in_array($status, ['scheduled', 'facebook_scheduled', '已排入 Facebook 預約'], true)) return true;
    return false;
}

function facebook_daily_recordable(array $s): bool
{
    // Facebook already accepted and published this item. A missing permalink
    // belongs in the daily report for manual lookup, not in publish preparation.
    if (facebook_daily_manual_url_lookup($s)) return true;
    if (facebook_daily_publish_completed($s)) return true;
    if (!facebook_daily_native_scheduled($s)) return false;
    $postUrl = trim((string)($s['post_url'] ?? ''));
    $realPostUrl = function_exists('schedule_lifecycle_real_post_url')
        ? schedule_lifecycle_real_post_url($postUrl)
        : (bool)preg_match('~/posts/|/permalink/|multi_permalinks=|story_fbid=|pfbid~i', $postUrl);
    $facebookStatus = trim((string)($s['facebook_status'] ?? ''));
    return $realPostUrl || $facebookStatus === 'scheduled_visible_no_url';
}

function facebook_daily_report_date(array $s): string
{
    $publishAt = (string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
    $actual = trim((string)($s['actual_publish_at'] ?? ''));
    $completed = trim((string)($s['facebook_publish_completed_at'] ?? ''));
    $scheduledYmd = facebook_daily_ymd($publishAt);
    $actualYmd = facebook_daily_ymd($actual);
    $completedYmd = facebook_daily_ymd($completed);

    // 當日臉書日報 belongs to the Facebook listing calendar day in Taipei:
    // immediate publish → that day; reservation → scheduled public date.
    // Worker confirmation after midnight (actual_publish_at next morning)
    // must not move a 9/21 13:xx listing onto the 9/22 date chip.
    if ($scheduledYmd !== '') {
        if ($actualYmd !== '' && $actualYmd < $scheduledYmd) {
            return $actualYmd;
        }
        return $scheduledYmd;
    }
    if ($actualYmd !== '') return $actualYmd;
    return $completedYmd;
}

function facebook_daily_operator_label($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '未指定';
    if (preg_match('/facebook-auction-worker|worker-queue|system|cron/i', $value)) return '系統自動上架';
    return $value;
}

function facebook_daily_assignee(array $s): string
{
    $source = trim((string)($s['listing_source'] ?? ''));
    if ($source === 'self') return facebook_daily_operator_label($s['created_by'] ?? '自行上架');
    if ($source === 'staff') return facebook_daily_operator_label($s['created_by'] ?? '員工上架');
    $assigned = trim((string)($s['facebook_publish_assigned_to'] ?? ''));
    if ($assigned !== '') return facebook_daily_operator_label($assigned);
    $automationOwner = trim((string)($s['automation_owner'] ?? ''));
    if ($automationOwner !== '') return facebook_daily_operator_label($automationOwner);
    if ($source === 'codex') return 'CODEX 上架';
    return '未指定';
}

function facebook_daily_publish_reason(array $s): string
{
    if (facebook_daily_manual_url_lookup($s)) {
        return 'Facebook 已上架；專屬貼文網址未抓到，請在當日報人工找網址並回填，不會重複發文';
    }
    if (function_exists('schedule_lifecycle_is_codex_managed') && !schedule_lifecycle_is_codex_managed($s) && !facebook_daily_publish_completed($s)) {
        return '人工上架排程，等待負責人發布並回填 Facebook 貼文網址';
    }
    if (function_exists('schedule_publish_result_reason')) {
        return schedule_publish_result_reason($s);
    }
    if (facebook_daily_publish_completed($s)) return '已完成今日上架';
    if (facebook_daily_native_scheduled($s)) return '已排入 Facebook 排程，待到點發佈並回填正式貼文網址';
    if (facebook_daily_posted($s)) {
        return trim((string)($s['post_url'] ?? '')) === '' ? '已上架，但尚未回填 Facebook 貼文網址' : '已上架';
    }
    $status = trim((string)($s['publish_status'] ?? $s['status'] ?? ''));
    $facebookStatus = trim((string)($s['facebook_status'] ?? ''));
    $workerError = trim((string)($s['facebook_worker_error'] ?? ''));
    $note = trim((string)($s['facebook_note'] ?? $s['publish_note'] ?? $s['progress_note'] ?? ''));
    if ($facebookStatus === 'published_text_visible_image_missing') return '文字可見但圖片缺失，需補圖或重新上架';
    if ($workerError !== '') return '自動上架錯誤：' . $workerError;
    if (in_array($status, ['上架異常', '未上架成功'], true)) return $note !== '' ? $note : '狀態為上架異常，待補圖、補連結或重上架';
    return $note !== '' ? $note : '已排程，等待上架處理';
}

function facebook_daily_closed(array $s): bool
{
    $close = strtotime((string)($s['close_at'] ?? $s['close_remind_at'] ?? ''));
    return $close !== false && $close <= time();
}

function facebook_daily_has_winner(array $s): bool
{
    return trim((string)($s['winner'] ?? '')) !== ''
        || trim((string)($s['winner_facebook'] ?? '')) !== ''
        || trim((string)($s['winner_phone'] ?? '')) !== '';
}

function facebook_daily_can_mark_unsold(array $s): bool
{
    if (facebook_daily_has_winner($s) || (float)($s['winning_price'] ?? 0) > 0) return false;
    if (!facebook_daily_closed($s)) return false;
    $order = (string)($s['order_status'] ?? '');
    $cancelled = (function_exists('mb_stripos') ? mb_stripos($order, '取消', 0, 'UTF-8') : strpos($order, '取消')) !== false
        || (function_exists('mb_stripos') ? mb_stripos($order, '棄標', 0, 'UTF-8') : false) !== false;
    $unsold = (string)($s['auction_result'] ?? '') === 'unsold'
        || (function_exists('mb_stripos') ? mb_stripos($order, '流標', 0, 'UTF-8') : strpos($order, '流標')) !== false;
    if ($cancelled || $unsold) return false;
    return facebook_daily_recordable($s)
        && (facebook_daily_verified_post($s) || facebook_daily_posted($s) || facebook_daily_manual_url_lookup($s));
}

function facebook_daily_build_row(array $s, array $p, array $sets, int $queue, string $date): array
{
    $setId = (string)($s['post_set_id'] ?? '');
    $set = function_exists('post_reply_find_set') ? post_reply_find_set($sets, $setId) : ['id' => $setId, 'name' => ''];
    $spec = trim((string)(
        ($s['product_color'] ?? ($p['color'] ?? '')) . ' / '
        . ($s['product_size'] ?? ($p['size'] ?? '')) . ' / '
        . ($s['product_spec'] ?? ($p['spec'] ?? ''))
    ), ' /');
    $img = trim((string)($s['schedule_image'] ?? ($p['image'] ?? '')));
    $listing = function_exists('render_post_reply_listing')
        ? render_post_reply_listing($s, $p, $sets, $setId)
        : (function_exists('facebook_listing_draft_text') ? facebook_listing_draft_text($s, $p, $setId) : '');
    $qa = function_exists('render_post_reply_qa_pack') ? render_post_reply_qa_pack($s, $p, $sets, $setId) : '';
    $reminder = trim((string)($s['reminder_draft'] ?? '')) ?: (function_exists('reminder_draft_text') ? reminder_draft_text($s, $p) : '');
    $t = function_exists('totals') ? totals($s, $p) : ['receivable' => 0, 'tax' => 0, 'paid' => 0, 'unpaid' => 0];
    $winnerNotice = trim((string)($s['winner_notice_message'] ?? ''));
    if ($winnerNotice === '' && function_exists('winner_notice_text')) {
        $winnerNotice = winner_notice_text($s, $p, $t);
        $link = function_exists('buyer_order_url') ? buyer_order_url($s) : '';
        if ($link !== '') $winnerNotice .= "\n訂單填寫/查詢連結：" . $link;
    }
    $winnerComment = function_exists('render_post_reply_listing')
        ? render_post_reply_listing($s, $p, $sets, 'winner_followup')
        : '';
    $publishAt = (string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
    $closeAt = (string)($s['close_at'] ?? $s['close_remind_at'] ?? '');
    $actual = (string)($s['actual_publish_at'] ?? '');
    $status = trim((string)($s['publish_status'] ?? $s['status'] ?? '未上架'));
    $postUrl = trim((string)($s['post_url'] ?? ''));
    $productUrl = trim((string)($s['product_url'] ?? ''));
    $winnerFacebookUrl = trim((string)($s['winner_facebook_url'] ?? ''));
    if ($winnerFacebookUrl === '' && function_exists('facebook_profile_url')) {
        $winnerFacebookUrl = facebook_profile_url($s['winner_facebook'] ?? '');
    }
    $publishCompleted = facebook_daily_publish_completed($s);
    $nativeScheduled = facebook_daily_native_scheduled($s);
    $posted = $publishCompleted || facebook_daily_posted($s);
    $verifiedPost = facebook_daily_verified_post($s);
    $closed = facebook_daily_closed($s);
    $hasWinner = facebook_daily_has_winner($s);
    $completedAt = (string)($s['facebook_publish_completed_at'] ?? '');
    $inPublish = facebook_daily_recordable($s) && facebook_daily_report_date($s) === $date;
    $inClose = facebook_daily_ymd($closeAt) === $date;
    $qaPinned = (!empty($s['fb_qa_pinned']) && (string)$s['fb_qa_pinned'] !== '0') || $publishCompleted;
    $winnerSent = !empty($s['fb_winner_notice_sent']) && (string)$s['fb_winner_notice_sent'] !== '0';
    $needPinQa = $inPublish && !$qaPinned;
    $cancelled = (function_exists('mb_stripos') ? mb_stripos((string)($s['order_status'] ?? ''), '取消', 0, 'UTF-8') : strpos((string)($s['order_status'] ?? ''), '取消')) !== false
        || (function_exists('mb_stripos') ? mb_stripos((string)($s['order_status'] ?? ''), '棄標', 0, 'UTF-8') : false) !== false;
    $unsold = (string)($s['auction_result'] ?? '') === 'unsold'
        || (function_exists('mb_stripos') ? mb_stripos((string)($s['order_status'] ?? ''), '流標', 0, 'UTF-8') : strpos((string)($s['order_status'] ?? ''), '流標')) !== false;
    $terminalWithoutWinner = $cancelled || $unsold;
    $publishTimestamp = strtotime($publishAt);
    $publishDue = $publishTimestamp !== false && $publishTimestamp <= time();
    $codexManaged = !function_exists('schedule_lifecycle_is_codex_managed') || schedule_lifecycle_is_codex_managed($s);
    $manualUrlLookup = facebook_daily_manual_url_lookup($s);
    $needPost = $codexManaged && !$terminalWithoutWinner && $publishDue && !$verifiedPost && !$manualUrlLookup;
    $needManualPublish = !$codexManaged && !$terminalWithoutWinner && !$verifiedPost;
    $needRemind = !$terminalWithoutWinner && !$closed && $verifiedPost
        && (function_exists('schedule_needs_hourly_update') ? schedule_needs_hourly_update($s) : true);
    $needWinnerRecord = $inClose && $closed && !$terminalWithoutWinner && ($verifiedPost || $manualUrlLookup) && !$hasWinner;
    $needWinner = $inClose && $closed && !$terminalWithoutWinner && $verifiedPost && $hasWinner && !$winnerSent;
    $canMarkUnsold = facebook_daily_can_mark_unsold($s);
    return [
        'queue' => $queue,
        'schedule_id' => (string)($s['id'] ?? ''),
        'product_id' => (string)($s['product_id'] ?? ($p['id'] ?? '')),
        'barcode' => (string)($s['product_barcode'] ?? ($p['barcode'] ?? '')),
        'title' => (string)(($p['title'] ?? '') ?: ($s['product_title'] ?? '')),
        'spec' => $spec,
        'image' => facebook_daily_abs_url($img),
        'qty' => max(1, (int)($s['quantity'] ?? 1)),
        'product_at' => (string)($p['created_at'] ?? ($s['product_created_at'] ?? ($s['created_at'] ?? ''))),
        'scheduled_publish_at' => $publishAt,
        'publish_at' => $publishAt,
        'actual_publish_at' => $actual,
        'close_at' => $closeAt,
        'current_bid' => (float)($s['current_bid'] ?? ($s['winning_price'] ?? 0)),
        'winning_price' => (float)($s['winning_price'] ?? 0),
        'base' => (float)($t['base'] ?? 0),
        'tax' => (float)($t['tax'] ?? 0),
        'receivable' => (float)($t['receivable'] ?? 0),
        'paid' => (float)($t['paid'] ?? 0),
        'unpaid' => (float)($t['unpaid'] ?? 0),
        'cost' => (float)($t['cost'] ?? 0),
        'profit' => (float)($t['profit'] ?? 0),
        'publish_status' => $status,
        'post_url' => $postUrl,
        'product_url' => $productUrl,
        'post_set_id' => (string)($set['id'] ?? ''),
        'post_set_name' => (string)($set['name'] ?? ''),
        'auction_status' => function_exists('auction_status_label') ? auction_status_label($s) : ($closed ? '已截標' : '競標中'),
        'winner' => (string)($s['winner'] ?? ''),
        'winner_facebook' => (string)($s['winner_facebook'] ?? ''),
        'winner_facebook_url' => $winnerFacebookUrl,
        'winner_notice_status' => (string)($s['winner_notice_status'] ?? ''),
        'winner_notice_step' => (string)($s['winner_notice_step'] ?? ''),
        'winner_notice_sent_at' => (string)($s['winner_notice_sent_at'] ?? ''),
        'order_status' => (string)($s['order_status'] ?? ''),
        'posted' => $posted,
        'verified_post' => $verifiedPost,
        'native_scheduled' => $nativeScheduled,
        'publish_completed' => $publishCompleted,
        'publish_completed_at' => (string)($s['facebook_publish_completed_at'] ?? ''),
        'publish_completed_by' => (string)($s['facebook_publish_completed_by'] ?? ''),
        'publish_completed_by_label' => facebook_daily_operator_label($s['facebook_publish_completed_by'] ?? ''),
        'publish_assigned_to' => facebook_daily_assignee($s),
        'publish_reason' => facebook_daily_publish_reason($s),
        'closed' => $closed,
        'cancelled' => $cancelled,
        'unsold' => $unsold,
        'auction_result' => (string)($s['auction_result'] ?? ''),
        'unsold_reason' => (string)($s['unsold_reason'] ?? ''),
        'unsold_confirmed_at' => (string)($s['unsold_confirmed_at'] ?? ''),
        'unsold_confirmed_by' => (string)($s['unsold_confirmed_by'] ?? ''),
        'has_winner' => $hasWinner,
        'in_publish_day' => $inPublish,
        'in_close_day' => $inClose,
        'qa_pinned' => $qaPinned,
        'winner_notice_sent' => $winnerSent,
        'need_post' => $needPost,
        'need_manual_publish' => $needManualPublish,
        'manual_find_url' => $manualUrlLookup,
        'listing_source' => (string)($s['listing_source'] ?? ''),
        'need_pin_qa' => $needPinQa,
        'publish_due' => $publishDue,
        'need_remind' => $needRemind && ($inPublish || $inClose),
        'need_winner' => $needWinner,
        'need_winner_record' => $needWinnerRecord,
        'can_mark_unsold' => $canMarkUnsold,
        'listing' => $listing,
        'qa_pack' => $qa,
        'reminder' => $reminder,
        'winner_notice' => $winnerNotice,
        'winner_comment' => $winnerComment,
        'last_reminder_at' => (string)($s['last_reminder_at'] ?? ''),
        'bid_updated_at' => (string)($s['bid_updated_at'] ?? ''),
        'reminder_status' => (string)($s['reminder_status'] ?? ''),
    ];
}

function facebook_daily_collect(array $schedules, array $products, array $sets, string $date): array
{
    $matched = [];
    foreach ($schedules as $s) {
        if (!is_array($s)) continue;
        if (!facebook_daily_recordable($s)) continue;
        $publishAt = (string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
        $closeAt = (string)($s['close_at'] ?? $s['close_remind_at'] ?? '');
        $inPublish = facebook_daily_report_date($s) === $date;
        $inClose = facebook_daily_ymd($closeAt) === $date;
        if (!$inPublish && !$inClose) continue;
        $matched[] = $s;
    }
    usort($matched, static function ($a, $b) {
        $ta = (string)($a['scheduled_publish_at'] ?? $a['publish_at'] ?? '');
        $tb = (string)($b['scheduled_publish_at'] ?? $b['publish_at'] ?? '');
        $cmp = strcmp($ta, $tb);
        if ($cmp !== 0) return $cmp;
        $ta = (string)($a['close_at'] ?? $a['close_remind_at'] ?? '');
        $tb = (string)($b['close_at'] ?? $b['close_remind_at'] ?? '');
        $cmp = strcmp($ta, $tb);
        return $cmp !== 0 ? $cmp : strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
    });
    $rows = [];
    foreach ($matched as $i => $s) {
        $p = function_exists('product_by_id') ? product_by_id($products, $s['product_id'] ?? '') : [];
        if (!is_array($p)) $p = [];
        $rows[] = facebook_daily_build_row($s, $p, $sets, $i + 1, $date);
    }
    return $rows;
}

function facebook_daily_compare(array $rows, string $date): array
{
    $planned = 0;
    $posted = 0;
    $completed = 0;
    $nativeScheduled = 0;
    $missingPost = 0;
    $missingUrl = 0;
    $closing = 0;
    $verifiedClosing = 0;
    $needRemind = 0;
    $needWinner = 0;
    $needWinnerRecord = 0;
    $needUnsold = 0;
    $needPin = 0;
    $assigneeStats = [];
    $publisherStats = [];
    $finance = [
        'orders' => 0,
        'quantity' => 0,
        'revenue' => 0.0,
        'tax' => 0.0,
        'receivable' => 0.0,
        'paid' => 0.0,
        'unpaid' => 0.0,
        'cost' => 0.0,
        'profit' => 0.0,
        'margin' => 0.0,
        'missing_cost' => 0,
    ];
    foreach ($rows as $row) {
        if (!empty($row['in_publish_day'])) {
            $planned++;
            $assignee = trim((string)($row['publish_assigned_to'] ?? '')) ?: '未指定';
            if (!isset($assigneeStats[$assignee])) {
                $assigneeStats[$assignee] = ['name' => $assignee, 'responsible' => 0, 'quantity' => 0, 'completed' => 0, 'pending' => 0];
            }
            $assigneeStats[$assignee]['responsible']++;
            $assigneeStats[$assignee]['quantity'] += max(1, (int)($row['qty'] ?? 1));
            if (!empty($row['native_scheduled'])) $nativeScheduled++;
            if (!empty($row['posted'])) $posted++;
            if (!empty($row['verified_post'])) {
                $completed++;
                $assigneeStats[$assignee]['completed']++;
                $publisher = trim((string)($row['publish_completed_by_label'] ?? '')) ?: '未指定';
                if (!isset($publisherStats[$publisher])) $publisherStats[$publisher] = ['name' => $publisher, 'completed' => 0, 'quantity' => 0];
                $publisherStats[$publisher]['completed']++;
                $publisherStats[$publisher]['quantity'] += max(1, (int)($row['qty'] ?? 1));
            } else {
                $assigneeStats[$assignee]['pending']++;
            }
            if (!empty($row['need_pin_qa'])) $needPin++;
        }
        if (!empty($row['need_post']) || !empty($row['need_manual_publish'])) {
            $missingPost++;
        }
        if (!empty($row['manual_find_url'])) {
            $missingUrl++;
        }
        if (!empty($row['in_close_day'])) {
            $closing++;
            if (!empty($row['verified_post']) && empty($row['cancelled'])) $verifiedClosing++;
        }
        if (!empty($row['need_remind'])) $needRemind++;
        if (!empty($row['need_winner'])) $needWinner++;
        if (!empty($row['need_winner_record'])) $needWinnerRecord++;
        if (!empty($row['can_mark_unsold'])) $needUnsold++;
        $isCompletedSale = !empty($row['in_close_day'])
            && !empty($row['has_winner'])
            && empty($row['cancelled'])
            && (float)($row['winning_price'] ?? 0) > 0;
        if ($isCompletedSale) {
            $finance['orders']++;
            $finance['quantity'] += max(1, (int)($row['qty'] ?? 1));
            $finance['revenue'] += (float)($row['base'] ?? 0);
            $finance['tax'] += (float)($row['tax'] ?? 0);
            $finance['receivable'] += (float)($row['receivable'] ?? 0);
            $finance['paid'] += (float)($row['paid'] ?? 0);
            $finance['unpaid'] += (float)($row['unpaid'] ?? 0);
            $finance['cost'] += (float)($row['cost'] ?? 0);
            $finance['profit'] += (float)($row['profit'] ?? 0);
            if ((float)($row['cost'] ?? 0) <= 0) $finance['missing_cost']++;
        }
    }
    if ($finance['revenue'] > 0) $finance['margin'] = $finance['profit'] / $finance['revenue'] * 100;
    $assigneeStats = array_values($assigneeStats);
    $publisherStats = array_values($publisherStats);
    usort($assigneeStats, static fn($a, $b) => ($b['responsible'] <=> $a['responsible']) ?: strcmp($a['name'], $b['name']));
    usort($publisherStats, static fn($a, $b) => ($b['completed'] <=> $a['completed']) ?: strcmp($a['name'], $b['name']));
    return [
        'date' => $date,
        'planned' => $planned,
        'posted' => $posted,
        'completed' => $completed,
        'native_scheduled' => $nativeScheduled,
        'missing_post' => $missingPost,
        'missing_url' => $missingUrl,
        'need_pin_qa' => $needPin,
        'closing' => $closing,
        'verified_closing' => $verifiedClosing,
        'unverified_closing' => max(0, $closing - $verifiedClosing),
        'need_remind' => $needRemind,
        'need_winner' => $needWinner,
        'need_winner_record' => $needWinnerRecord,
        'need_unsold' => $needUnsold,
        'rows' => count($rows),
        'assignees' => $assigneeStats,
        'publishers' => $publisherStats,
        'finance' => $finance,
    ];
}

function facebook_daily_codex_text(array $rows, array $compare, string $date): string
{
    $lines = [];
    $lines[] = '# 寶輝海賊團 Facebook 日報 ' . $date;
    $lines[] = '產生時間：' . date('Y-m-d H:i:s');
    $lines[] = '用途：Facebook 排程與上架紀錄日報；已排入 Facebook 原生排程即可入列，正式貼文網址用於標記實際已發布。';
    $lines[] = '';
    $lines[] = '## 比對';
    $lines[] = '- 今日已排入 Facebook：' . (int)($compare['native_scheduled'] ?? 0);
    $lines[] = '- 今日正式已發布：' . (int)($compare['completed'] ?? 0);
    $lines[] = '- 已到上架時間但尚無正式貼文網址：' . (int)($compare['missing_url'] ?? 0);
    $lines[] = '- 待置頂問答：' . (int)($compare['need_pin_qa'] ?? 0);
    $lines[] = '- 預定當日截標：' . (int)($compare['closing'] ?? 0);
    $lines[] = '- 其中有效 Facebook 貼文：' . (int)($compare['verified_closing'] ?? 0);
    $lines[] = '- 競標中需價格提醒：' . (int)($compare['need_remind'] ?? 0);
    $lines[] = '- 已有得標人但尚未通知：' . (int)($compare['need_winner'] ?? 0);
    $lines[] = '- 已截標尚未填得標人：' . (int)($compare['need_winner_record'] ?? 0);
    $lines[] = '';
    $lines[] = '## 上架人員統計';
    foreach ((array)($compare['assignees'] ?? []) as $staff) {
        $lines[] = '- ' . ($staff['name'] ?? '未指定') . '：負責 ' . (int)($staff['responsible'] ?? 0) . ' 項／' . (int)($staff['quantity'] ?? 0) . ' 件；完成 ' . (int)($staff['completed'] ?? 0) . ' 項；待完成 ' . (int)($staff['pending'] ?? 0) . ' 項';
    }
    if (empty($compare['assignees'])) $lines[] = '- 這天沒有上架工作';
    $lines[] = '實際完成：' . implode('、', array_map(static fn($staff) => ($staff['name'] ?? '未指定') . ' ' . (int)($staff['completed'] ?? 0) . ' 項', (array)($compare['publishers'] ?? [])));
    $lines[] = '';
    $lines[] = '## A｜Facebook 排程／上架紀錄（依預定上架時間）';
    $postedAny = false;
    foreach ($rows as $row) {
        if (empty($row['in_publish_day'])) continue;
        $postedAny = true;
        $q = str_pad((string)($row['queue'] ?? ''), 2, '0', STR_PAD_LEFT);
        $todo = [];
        if (!empty($row['native_scheduled']) && empty($row['publish_completed'])) $todo[] = 'Facebook已排定';
        if (!empty($row['need_post'])) $todo[] = 'CODEX 發文';
        if (!empty($row['need_manual_publish'])) $todo[] = '人工上架／回填貼文';
        if (!empty($row['need_pin_qa'])) $todo[] = '置頂問答';
        if (!$todo) $todo[] = '核對已完成';
        $lines[] = '';
        $lines[] = '### A-' . $q . ' ' . implode('＋', $todo) . '｜' . ($row['product_id'] ?? '') . '｜' . ($row['title'] ?? '');
        $lines[] = '上架序：' . $q . '　預定：' . ($row['publish_at'] ?? '') . '　截標：' . ($row['close_at'] ?? '');
        $lines[] = '規格：' . (($row['spec'] ?? '') !== '' ? $row['spec'] : '-');
        $lines[] = '數量：' . ($row['qty'] ?? 1) . '　狀態：' . ($row['publish_status'] ?? '') . '　目前金額：' . (int)($row['current_bid'] ?? 0);
        $lines[] = 'Facebook排程：' . (!empty($row['native_scheduled']) ? '已排定' : '-') . '　正式發布：' . (!empty($row['publish_completed']) ? '已發布' : '未到點／未回填網址') . (!empty($row['publish_completed_at']) ? '　發布時間：' . $row['publish_completed_at'] : '');
        $lines[] = '上架狀態原因：' . (($row['publish_reason'] ?? '') !== '' ? $row['publish_reason'] : '-');
        $lines[] = '貼文連結（結標用）：' . (($row['post_url'] ?? '') !== '' ? $row['post_url'] : '（尚未回填）');
        $lines[] = '產品連結：' . (($row['product_url'] ?? '') !== '' ? $row['product_url'] : '（尚未回填）');
        if (!empty($row['image'])) $lines[] = '圖片：' . $row['image'];
        $lines[] = '套組：' . (($row['post_set_name'] ?? '') !== '' ? $row['post_set_name'] : '預設');
        $lines[] = '';
        $lines[] = '【發文稿】';
        $lines[] = (string)($row['listing'] ?? '');
        $lines[] = '';
        $lines[] = '【第一則留言／問答包】';
        $lines[] = (string)($row['qa_pack'] ?? '');
        $lines[] = '';
        $lines[] = '到點發布後：把 Facebook 貼文網址回填這筆排程，上架狀態改「已上架」，並標記已置頂問答。';
    }
    if (!$postedAny) $lines[] = '（今日沒有上架場次）';

    $lines[] = '';
    $lines[] = '## 任務 B｜價格提醒';
    $remindAny = false;
    foreach ($rows as $row) {
        if (empty($row['need_remind'])) continue;
        $remindAny = true;
        $q = str_pad((string)($row['queue'] ?? ''), 2, '0', STR_PAD_LEFT);
        $lines[] = '';
        $lines[] = '### B-' . $q . ' 提醒｜' . ($row['product_id'] ?? '') . '｜' . ($row['title'] ?? '');
        $lines[] = '貼文：' . (($row['post_url'] ?? '') !== '' ? $row['post_url'] : '（無網址，先完成發文）');
        $lines[] = '目前金額：' . (int)($row['current_bid'] ?? 0) . '　截標：' . ($row['close_at'] ?? '') . '　上次提醒：' . (($row['last_reminder_at'] ?? '') !== '' ? $row['last_reminder_at'] : '尚未');
        $lines[] = '';
        $lines[] = '【提醒稿】';
        $lines[] = (string)($row['reminder'] ?? '');
        $lines[] = '';
        $lines[] = '做完後：在排程標記已提醒，並更新目前競標金額。';
    }
    if (!$remindAny) $lines[] = '（目前沒有需要價格提醒的場次）';

    $lines[] = '';
    $lines[] = '## 任務 C｜得標通知';
    $winAny = false;
    foreach ($rows as $row) {
        if (empty($row['need_winner'])) continue;
        $winAny = true;
        $q = str_pad((string)($row['queue'] ?? ''), 2, '0', STR_PAD_LEFT);
        $lines[] = '';
        $lines[] = '### C-' . $q . (empty($row['need_winner_record']) ? ' 通知得標' : ' 先補得標人') . '｜' . ($row['product_id'] ?? '') . '｜' . ($row['title'] ?? '');
        $lines[] = '截標：' . ($row['close_at'] ?? '') . '　得標者：' . (($row['winner'] ?? '') !== '' ? $row['winner'] : '尚未填') . '　Facebook：' . (($row['winner_facebook'] ?? '') !== '' ? $row['winner_facebook'] : '-');
        $lines[] = '會員臉書連結：' . (($row['winner_facebook_url'] ?? '') !== '' ? $row['winner_facebook_url'] : '（尚未回填）');
        $lines[] = '得標通知狀態：' . (($row['winner_notice_status'] ?? '') !== '' ? $row['winner_notice_status'] : '未通知') . '　通知時間：' . (($row['winner_notice_sent_at'] ?? '') !== '' ? $row['winner_notice_sent_at'] : '-');
        $lines[] = '通知流程：' . (($row['winner_notice_step'] ?? '') !== '' ? $row['winner_notice_step'] : '1. 確認得標人 → 2. 開會員臉書連結 → 3. 傳送得標通知 → 4. 等待填單/回覆 → 5. 對帳收款 → 6. 轉出貨單');
        $lines[] = '得標金額：' . (int)($row['winning_price'] ?? $row['current_bid'] ?? 0);
        $lines[] = '貼文：' . (($row['post_url'] ?? '') !== '' ? $row['post_url'] : '（無）');
        $lines[] = '';
        $lines[] = '【貼文留言／得標短訊】';
        $lines[] = (string)($row['winner_comment'] ?? '');
        $lines[] = '';
        $lines[] = '【完整得標通知】';
        $lines[] = (string)($row['winner_notice'] ?? '');
        $lines[] = '';
        $lines[] = empty($row['need_winner_record'])
            ? '做完後：把得標通知貼到該篇或私訊得標者，並在日報標記已通知。'
            : '這筆已截標但還沒填得標人。請先在得標結算補上，再發通知。';
    }
    if (!$winAny) $lines[] = '（今日沒有待得標通知）';
    $lines[] = '';
    $lines[] = '## 規則';
    $lines[] = '1. 發文順序以上架序／預定上架時間為準。';
    $lines[] = '2. 發文後立刻貼問答包當第一則留言。';
    $lines[] = '3. 回填貼文網址後，這張日報的比對才會變成已上架。';
    $lines[] = '4. 不要略過待發文或已截標未填得標人的場次。';
    return implode("\n", $lines);
}
