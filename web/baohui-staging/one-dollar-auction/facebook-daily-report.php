<?php
declare(strict_types=1);

function facebook_daily_date(?string $raw = null): string
{
    $raw = trim((string)($raw ?? ''));
    if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    return date('Y-m-d');
}

function facebook_daily_ymd($value): string
{
    $text = trim((string)$value);
    if ($text === '') return '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $m)) return $m[1];
    $ts = strtotime($text);
    return $ts ? date('Y-m-d', $ts) : '';
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
    return in_array($status, ['已上架', '上架成功'], true) || $url !== '';
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
    $posted = facebook_daily_posted($s);
    $closed = facebook_daily_closed($s);
    $hasWinner = facebook_daily_has_winner($s);
    $inPublish = facebook_daily_ymd($publishAt) === $date || facebook_daily_ymd($actual) === $date;
    $inClose = facebook_daily_ymd($closeAt) === $date;
    $qaPinned = !empty($s['fb_qa_pinned']) && (string)$s['fb_qa_pinned'] !== '0';
    $winnerSent = !empty($s['fb_winner_notice_sent']) && (string)$s['fb_winner_notice_sent'] !== '0';
    $needPost = $inPublish && (!$posted || $postUrl === '');
    $needPinQa = $inPublish && !$qaPinned;
    $needRemind = !$closed && $posted && (function_exists('schedule_needs_hourly_update') ? schedule_needs_hourly_update($s) : true);
    $cancelled = (function_exists('mb_stripos') ? mb_stripos((string)($s['order_status'] ?? ''), '取消', 0, 'UTF-8') : strpos((string)($s['order_status'] ?? ''), '取消')) !== false
        || (function_exists('mb_stripos') ? mb_stripos((string)($s['order_status'] ?? ''), '棄標', 0, 'UTF-8') : false) !== false;
    $needWinner = $inClose && $closed && !$cancelled && !$winnerSent;
    $needWinnerRecord = $needWinner && !$hasWinner;
    return [
        'queue' => $queue,
        'schedule_id' => (string)($s['id'] ?? ''),
        'product_id' => (string)($s['product_id'] ?? ($p['id'] ?? '')),
        'barcode' => (string)($s['product_barcode'] ?? ($p['barcode'] ?? '')),
        'title' => (string)(($p['title'] ?? '') ?: ($s['product_title'] ?? '')),
        'spec' => $spec,
        'image' => facebook_daily_abs_url($img),
        'qty' => max(1, (int)($s['quantity'] ?? 1)),
        'publish_at' => $publishAt,
        'actual_publish_at' => $actual,
        'close_at' => $closeAt,
        'current_bid' => (float)($s['current_bid'] ?? ($s['winning_price'] ?? 0)),
        'winning_price' => (float)($s['winning_price'] ?? 0),
        'publish_status' => $status,
        'post_url' => $postUrl,
        'post_set_id' => (string)($set['id'] ?? ''),
        'post_set_name' => (string)($set['name'] ?? ''),
        'auction_status' => function_exists('auction_status_label') ? auction_status_label($s) : ($closed ? '已截標' : '競標中'),
        'winner' => (string)($s['winner'] ?? ''),
        'winner_facebook' => (string)($s['winner_facebook'] ?? ''),
        'order_status' => (string)($s['order_status'] ?? ''),
        'posted' => $posted,
        'closed' => $closed,
        'has_winner' => $hasWinner,
        'in_publish_day' => $inPublish,
        'in_close_day' => $inClose,
        'qa_pinned' => $qaPinned,
        'winner_notice_sent' => $winnerSent,
        'need_post' => $needPost,
        'need_pin_qa' => $needPinQa,
        'need_remind' => $needRemind && ($inPublish || $inClose),
        'need_winner' => $needWinner,
        'need_winner_record' => $needWinnerRecord,
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
        $publishAt = (string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '');
        $actual = (string)($s['actual_publish_at'] ?? '');
        $closeAt = (string)($s['close_at'] ?? $s['close_remind_at'] ?? '');
        $inPublish = facebook_daily_ymd($publishAt) === $date || facebook_daily_ymd($actual) === $date;
        $inClose = facebook_daily_ymd($closeAt) === $date;
        if (!$inPublish && !$inClose) continue;
        $matched[] = $s;
    }
    usort($matched, static function ($a, $b) {
        $ta = (string)($a['scheduled_publish_at'] ?? $a['publish_at'] ?? '');
        $tb = (string)($b['scheduled_publish_at'] ?? $b['publish_at'] ?? '');
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
    $missingPost = 0;
    $missingUrl = 0;
    $closing = 0;
    $needRemind = 0;
    $needWinner = 0;
    $needWinnerRecord = 0;
    $needPin = 0;
    foreach ($rows as $row) {
        if (!empty($row['in_publish_day'])) {
            $planned++;
            if (!empty($row['posted'])) $posted++;
            if (!empty($row['need_post'])) $missingPost++;
            if (!empty($row['posted']) && trim((string)($row['post_url'] ?? '')) === '') $missingUrl++;
            if (!empty($row['need_pin_qa'])) $needPin++;
        }
        if (!empty($row['in_close_day'])) $closing++;
        if (!empty($row['need_remind'])) $needRemind++;
        if (!empty($row['need_winner'])) $needWinner++;
        if (!empty($row['need_winner_record'])) $needWinnerRecord++;
    }
    return [
        'date' => $date,
        'planned' => $planned,
        'posted' => $posted,
        'missing_post' => $missingPost,
        'missing_url' => $missingUrl,
        'need_pin_qa' => $needPin,
        'closing' => $closing,
        'need_remind' => $needRemind,
        'need_winner' => $needWinner,
        'need_winner_record' => $needWinnerRecord,
        'rows' => count($rows),
    ];
}

function facebook_daily_codex_text(array $rows, array $compare, string $date): string
{
    $lines = [];
    $lines[] = '# 寶輝海賊團 Facebook 日報 ' . $date;
    $lines[] = '產生時間：' . date('Y-m-d H:i:s');
    $lines[] = '用途：給人工對帳，也給 Codex 依序執行發文、第一則問答、價格提醒、得標通知。系統不會自動發 Facebook。';
    $lines[] = '';
    $lines[] = '## 比對';
    $lines[] = '- 今日預定／當日上架場次：' . (int)($compare['planned'] ?? 0);
    $lines[] = '- 已上架：' . (int)($compare['posted'] ?? 0);
    $lines[] = '- 待發文／缺貼文網址：' . (int)($compare['missing_post'] ?? 0);
    $lines[] = '- 已上架但尚未回填貼文網址：' . (int)($compare['missing_url'] ?? 0);
    $lines[] = '- 待置頂問答：' . (int)($compare['need_pin_qa'] ?? 0);
    $lines[] = '- 今日截標：' . (int)($compare['closing'] ?? 0);
    $lines[] = '- 待價格提醒：' . (int)($compare['need_remind'] ?? 0);
    $lines[] = '- 待得標通知：' . (int)($compare['need_winner'] ?? 0);
    $lines[] = '- 已截標尚未填得標人：' . (int)($compare['need_winner_record'] ?? 0);
    $lines[] = '';
    $lines[] = '## 任務 A｜發文＋第一則問答（依上架序）';
    $postedAny = false;
    foreach ($rows as $row) {
        if (empty($row['in_publish_day'])) continue;
        $postedAny = true;
        $q = str_pad((string)($row['queue'] ?? ''), 2, '0', STR_PAD_LEFT);
        $todo = [];
        if (!empty($row['need_post'])) $todo[] = '發文';
        if (!empty($row['need_pin_qa'])) $todo[] = '置頂問答';
        if (!$todo) $todo[] = '核對已完成';
        $lines[] = '';
        $lines[] = '### A-' . $q . ' ' . implode('＋', $todo) . '｜' . ($row['product_id'] ?? '') . '｜' . ($row['title'] ?? '');
        $lines[] = '上架序：' . $q . '　預定：' . ($row['publish_at'] ?? '') . '　截標：' . ($row['close_at'] ?? '');
        $lines[] = '規格：' . (($row['spec'] ?? '') !== '' ? $row['spec'] : '-');
        $lines[] = '數量：' . ($row['qty'] ?? 1) . '　狀態：' . ($row['publish_status'] ?? '') . '　目前金額：' . (int)($row['current_bid'] ?? 0);
        $lines[] = '貼文網址：' . (($row['post_url'] ?? '') !== '' ? $row['post_url'] : '（尚未回填）');
        if (!empty($row['image'])) $lines[] = '圖片：' . $row['image'];
        $lines[] = '套組：' . (($row['post_set_name'] ?? '') !== '' ? $row['post_set_name'] : '預設');
        $lines[] = '';
        $lines[] = '【發文稿】';
        $lines[] = (string)($row['listing'] ?? '');
        $lines[] = '';
        $lines[] = '【第一則留言／問答包】';
        $lines[] = (string)($row['qa_pack'] ?? '');
        $lines[] = '';
        $lines[] = '做完後：把 Facebook 貼文網址回填這筆排程，上架狀態改「已上架」，並標記已置頂問答。';
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
