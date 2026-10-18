#!/usr/bin/env python3
"""Show whether 峰志 Chrome helper is connected, and that cloud cannot log into Facebook."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """    <div class="section-head"><h2>競標立即上架</h2><span>設定截標時間後按「啟動上架」。系統依固定場次重排公開時間，先跑完整批、再補漏一次；單筆網址未確認不會卡住後面商品。</span></div>
""",
        """    <div class="section-head"><h2>競標立即上架</h2><span>真正發 Facebook 一定走公司「峰志 Chrome」裡的發文助手。雲端只能把工作入隊；助手已連線時會自動領取已入隊的競標，不必再按啟動上架。設定截標時間後仍可手動按「啟動上架」補送。</span></div>
    <?php
      $facebookHelperStatus = function_exists('schedule_lifecycle_helper_status') ? schedule_lifecycle_helper_status() : ['connected' => false, 'helper_version' => '', 'age_seconds' => null];
      $helperConnected = !empty($facebookHelperStatus['connected']);
      $helperVersionLabel = trim((string)($facebookHelperStatus['helper_version'] ?? ''));
    ?>
    <div class="notice" style="<?= $helperConnected ? 'background:#ecfdf5;border-color:#059669;color:#065f46' : 'background:#fff7ed;border-color:#c2410c;color:#9a3412' ?>">
      <?php if ($helperConnected): ?>
        峰志 Chrome 發文助手<?= $helperVersionLabel !== '' ? ' ' . h($helperVersionLabel) : '' ?> 已連線，雲端入隊的競標會自動建立 Facebook 預約。
      <?php else: ?>
        峰志 Chrome 發文助手未連線。請在公司那台電腦打開 Chrome（Facebook 保持登入），連線後已入隊的工作會自動開始；這個雲端登不了你的 Facebook。
      <?php endif; ?>
    </div>
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
