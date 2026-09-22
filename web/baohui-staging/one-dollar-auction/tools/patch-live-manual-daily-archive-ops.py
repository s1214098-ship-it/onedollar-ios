#!/usr/bin/env python3
"""When 小姐/staff paste a real Facebook permalink, archive the lot to 當日報."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """            $previousPostUrl = safe_http_url($s['post_url'] ?? '');
            $s['post_url'] = safe_http_url($_POST['post_url'] ?? ($s['post_url'] ?? ''));
            $askedToComplete = $markPublishCompleted
""",
        """            $previousPostUrl = safe_http_url($s['post_url'] ?? '');
            $s['post_url'] = safe_http_url($_POST['post_url'] ?? ($s['post_url'] ?? ''));
            if (function_exists('schedule_lifecycle_archive_manual_listing_to_daily_report')
                && function_exists('ops_schedule_has_unique_real_post_url')
                && ops_schedule_has_unique_real_post_url($s, $schedules)) {
                schedule_lifecycle_archive_manual_listing_to_daily_report($s);
            }
            $askedToComplete = $markPublishCompleted
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
