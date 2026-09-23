#!/usr/bin/env python3
"""Keep 結標/流標 on archived daily-report lots that have no winner yet.

Staff permalink archive sets facebook_daily_archived=1 so the lot files into
當日臉書日報. Live operations.php previously treated that flag as
「已轉得標結算」 and skipped the 結標 form. Only lots that actually have a
winner / settlement_ready should use the compact settled card.
"""

from __future__ import annotations

from pathlib import Path


PATCHES = [
    (
        """        if (!empty($pendingUnsoldRow['in_close_day']) && !empty($pendingUnsoldRow['can_mark_unsold'])) {
            $facebookDailyPendingUnsoldYesterday[] = $pendingUnsoldRow;
        }
""",
        """        if (!empty($pendingUnsoldRow['in_close_day'])
            && empty($pendingUnsoldRow['unsold'])
            && empty($pendingUnsoldRow['cancelled'])
            && empty($pendingUnsoldRow['archived_as_settled'])
            && (empty($pendingUnsoldRow['has_winner']) || (float)($pendingUnsoldRow['winning_price'] ?? 0) <= 0)) {
            $facebookDailyPendingUnsoldYesterday[] = $pendingUnsoldRow;
        }
""",
    ),
    (
        """        <div class="notice" style="margin:8px 0 0">昨日 <?=h($facebookDailyYesterday)?> 截標尚有 <?=h(count($facebookDailyPendingUnsoldYesterday))?> 筆未得標、也還沒確認流標。請打開昨天日報處理，不要停在今天空的結標畫面。<a class="button-like" href="<?=h($facebookDailyLink($facebookDailyYesterday))?>">打開昨天日報處理流標</a></div>
""",
        """        <div class="notice" style="margin:8px 0 0">昨日 <?=h($facebookDailyYesterday)?> 截標尚有 <?=h(count($facebookDailyPendingUnsoldYesterday))?> 筆還沒結標或確認流標。請打開昨天日報處理，不要停在今天空的結標畫面。<a class="button-like" href="<?=h($facebookDailyLink($facebookDailyYesterday))?>">打開昨天日報結標</a></div>
""",
    ),
    (
        """      $todo = !empty($row['manual_find_url']) || !empty($row['need_post']) || !empty($row['need_manual_publish']) || !empty($row['need_pin_qa']) || !empty($row['need_remind']) || !empty($row['need_winner']);
      $cardClass = !empty($row['need_winner']) ? 'is-close' : ($todo ? 'is-todo' : 'is-done');
""",
        """      $todo = !empty($row['manual_find_url']) || !empty($row['need_post']) || !empty($row['need_manual_publish']) || !empty($row['need_pin_qa']) || !empty($row['need_remind']) || !empty($row['need_winner']) || !empty($row['need_winner_record']) || !empty($row['can_mark_unsold']);
      $cardClass = (!empty($row['need_winner']) || !empty($row['need_winner_record']) || !empty($row['can_mark_unsold'])) ? 'is-close' : ($todo ? 'is-todo' : 'is-done');
""",
    ),
    (
        """    <?php if (!empty($dailySchedule['facebook_daily_archived'])): ?>
    <article class="fb-daily-card is-done fb-daily-archived-record" data-facebook-daily-schedule="<?=h($row['schedule_id'])?>">
""",
        """    <?php if (facebook_daily_archived_as_settled(is_array($dailySchedule) ? $dailySchedule : [])): ?>
    <article class="fb-daily-card is-done fb-daily-archived-record" data-facebook-daily-schedule="<?=h($row['schedule_id'])?>">
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
