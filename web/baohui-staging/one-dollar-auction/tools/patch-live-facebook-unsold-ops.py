#!/usr/bin/env python3
"""Surgical patches for live operations.php: keep 流標 on the daily report.

Does not replace the whole file (live ops is ~2.6MB and has 寄庫).
"""

from __future__ import annotations

from pathlib import Path


PATCHES = [
    (
        """$facebookDailyDateCounts = [];
foreach ($schedules as $dailySchedule) {
    if (!is_array($dailySchedule)) continue;
    if (!facebook_daily_recordable($dailySchedule)) continue;
    $dailyPublishDate = facebook_daily_report_date($dailySchedule);
    if ($dailyPublishDate !== '') $facebookDailyDateCounts[$dailyPublishDate] = (int)($facebookDailyDateCounts[$dailyPublishDate] ?? 0) + 1;
}
""",
        """$facebookDailyDateCounts = [];
foreach ($schedules as $dailySchedule) {
    if (!is_array($dailySchedule)) continue;
    if (!facebook_daily_recordable($dailySchedule)) continue;
    $seenDailyDates = [];
    $dailyPublishDate = facebook_daily_report_date($dailySchedule);
    if ($dailyPublishDate !== '') $seenDailyDates[$dailyPublishDate] = true;
    $dailyCloseDate = facebook_daily_ymd((string)($dailySchedule['close_at'] ?? $dailySchedule['close_remind_at'] ?? ''));
    if ($dailyCloseDate !== '') $seenDailyDates[$dailyCloseDate] = true;
    foreach ($seenDailyDates as $countedDailyDate => $_) {
        $facebookDailyDateCounts[$countedDailyDate] = (int)($facebookDailyDateCounts[$countedDailyDate] ?? 0) + 1;
    }
}
""",
    ),
    (
        """$facebookDailyAllRows = facebook_daily_collect($schedules, $products, $postReplySets ?? [], $facebookDailyDate);
$facebookDailyAllRows = array_values(array_filter($facebookDailyAllRows, static function ($row) use ($facebookDailyDate) {
    return !empty($row['in_publish_day']);
}));
$facebookDailyCompare = facebook_daily_compare($facebookDailyAllRows, $facebookDailyDate);
""",
        """$facebookDailyAllRows = facebook_daily_collect($schedules, $products, $postReplySets ?? [], $facebookDailyDate);
$facebookDailyCompare = facebook_daily_compare($facebookDailyAllRows, $facebookDailyDate);
$facebookDailyYesterday = date('Y-m-d', strtotime($facebookDailyDate . ' -1 day'));
$facebookDailyPendingUnsoldYesterday = [];
if ($facebookDailyDate === date('Y-m-d')) {
    foreach (facebook_daily_collect($schedules, $products, $postReplySets ?? [], $facebookDailyYesterday) as $pendingUnsoldRow) {
        if (!empty($pendingUnsoldRow['in_close_day']) && !empty($pendingUnsoldRow['can_mark_unsold'])) {
            $facebookDailyPendingUnsoldYesterday[] = $pendingUnsoldRow;
        }
    }
}
""",
    ),
    (
        """$facebookDailyRows = array_values(array_filter($facebookDailyAllRows, static function ($row) use ($facebookDailyDate) {
    return !empty($row['in_publish_day']);
}));
""",
        """$facebookDailyRows = $facebookDailyAllRows;
""",
    ),
    (
        """        if ($postUrl === '') $errors[] = '請先填正式 Facebook 貼文連結';
        elseif (!schedule_lifecycle_real_post_url($postUrl)) $errors[] = '這不是 Facebook 正式貼文網址';
        elseif (ops_facebook_post_url_used_elsewhere($schedules, $postUrl, $id)) $errors[] = '這個 Facebook 貼文網址已屬於另一筆排程';
        if ($reason === '') $errors[] = '請填流標核對原因';
        if ($errors) {
            $notice = '尚未歸入流標：' . implode('；', $errors) . '。';
        } else {
            foreach ($schedules as &$s) {
                if (($s['id'] ?? '') !== $id) continue;
                $proofSchedule = $s;
                $proofSchedule['post_url'] = $postUrl;
                if (!ops_schedule_has_publish_proof($proofSchedule, $schedules)) {
                    $notice = '尚未歸入流標：這筆尚未有 Facebook 成功上架證明。';
                    break;
                }
""",
        """        $listedWithoutPermalink = false;
        foreach ($schedules as $lookupSchedule) {
            if (($lookupSchedule['id'] ?? '') !== $id) continue;
            $listedWithoutPermalink = (function_exists('facebook_daily_manual_url_lookup') && facebook_daily_manual_url_lookup($lookupSchedule))
                || (function_exists('facebook_daily_posted') && facebook_daily_posted($lookupSchedule));
            break;
        }
        if ($postUrl === '') {
            if (!$listedWithoutPermalink) $errors[] = '請先填正式 Facebook 貼文連結';
        } elseif (!schedule_lifecycle_real_post_url($postUrl)) {
            $errors[] = '這不是 Facebook 正式貼文網址';
        } elseif (ops_facebook_post_url_used_elsewhere($schedules, $postUrl, $id)) {
            $errors[] = '這個 Facebook 貼文網址已屬於另一筆排程';
        }
        if ($reason === '') $errors[] = '請填流標核對原因';
        if ($errors) {
            $notice = '尚未歸入流標：' . implode('；', $errors) . '。';
        } else {
            foreach ($schedules as &$s) {
                if (($s['id'] ?? '') !== $id) continue;
                if ($postUrl !== '') {
                    $proofSchedule = $s;
                    $proofSchedule['post_url'] = $postUrl;
                    if (!ops_schedule_has_publish_proof($proofSchedule, $schedules) && !$listedWithoutPermalink) {
                        $notice = '尚未歸入流標：這筆尚未有 Facebook 成功上架證明。';
                        break;
                    }
                    $s['post_url'] = $postUrl;
                } elseif (!$listedWithoutPermalink) {
                    $notice = '尚未歸入流標：這筆尚未有 Facebook 成功上架證明。';
                    break;
                }
""",
    ),
    (
        """<?php if (!empty($row['closed']) && !empty($row['verified_post']) && empty($row['has_winner'])): ?><button type="button" class="secondary open-fb-daily-detail" data-dialog="fbAuctionUnsold-<?=h($row['schedule_id'])?>">確認流標</button><?php endif; ?>""",
        """<?php if (!empty($row['can_mark_unsold'])): ?><button type="button" class="secondary open-fb-daily-detail" data-dialog="fbAuctionUnsold-<?=h($row['schedule_id'])?>">確認流標</button><?php endif; ?>""",
    ),
    (
        """      <?php if (!empty($row['closed']) && !empty($row['verified_post']) && empty($row['has_winner'])): ?>
      <dialog id="fbAuctionUnsold-<?=h($row['schedule_id'])?>" class="fb-daily-dialog auction-action-dialog">""",
        """      <?php if (!empty($row['can_mark_unsold'])): ?>
      <dialog id="fbAuctionUnsold-<?=h($row['schedule_id'])?>" class="fb-daily-dialog auction-action-dialog">""",
    ),
    (
        """          <?php if ($facebookDailyDate !== date('Y-m-d')): ?><a class="button-like small" href="<?=h($facebookDailyLink(date('Y-m-d')))?>">回到今天</a><?php else: ?><span class="status-pill settled">目前是今天</span><?php endif; ?>
          <a class="button-like small" href="<?=h($facebookDailyLink($facebookDailyNextDay))?>" aria-label="顯示後一天">後一天</a>
        </div>
      </div>
      <div class="fb-daily-finance-grid">
""",
        """          <?php if ($facebookDailyDate !== date('Y-m-d')): ?><a class="button-like small" href="<?=h($facebookDailyLink(date('Y-m-d')))?>">回到今天</a><?php else: ?><span class="status-pill settled">目前是今天</span><?php endif; ?>
          <a class="button-like small" href="<?=h($facebookDailyLink($facebookDailyNextDay))?>" aria-label="顯示後一天">後一天</a>
        </div>
      </div>
      <?php if (!empty($facebookDailyPendingUnsoldYesterday)): ?>
        <div class="notice" style="margin:8px 0 0">昨日 <?=h($facebookDailyYesterday)?> 截標尚有 <?=h(count($facebookDailyPendingUnsoldYesterday))?> 筆未得標、也還沒確認流標。請打開昨天日報處理，不要停在今天空的結標畫面。<a class="button-like" href="<?=h($facebookDailyLink($facebookDailyYesterday))?>">打開昨天日報處理流標</a></div>
      <?php endif; ?>
      <div class="fb-daily-finance-grid">
""",
    ),
]


def apply(text: str) -> str:
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:160].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:160].replace("\n", " / "))
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
