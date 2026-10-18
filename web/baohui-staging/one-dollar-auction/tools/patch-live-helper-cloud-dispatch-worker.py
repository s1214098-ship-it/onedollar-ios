#!/usr/bin/env python3
"""Let the HQ Chrome helper auto-claim cloud-queued listings without another 啟動上架 click."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """    if (!$isManualInstantRequest && (version_compare($helperVersion !== '' ? $helperVersion : '0', '1.10.220', '<') || $helperBuild !== 'batch-three-pass-v162')) {
        faw_json_response(['ok'=>false, 'tasks'=>[], 'error'=>'整批上架啟動回覆與慢速預約核對已更新；請更新並重新載入發文助手 1.10.220'], 426);
    }
    $automaticActivation = ['changed' => 0, 'schedule_ids' => []];
""",
        """    if (!$isManualInstantRequest && (version_compare($helperVersion !== '' ? $helperVersion : '0', '1.10.220', '<') || $helperBuild !== 'batch-three-pass-v162')) {
        faw_json_response(['ok'=>false, 'tasks'=>[], 'error'=>'整批上架啟動回覆與慢速預約核對已更新；請更新並重新載入發文助手 1.10.220'], 426);
    }
    if (function_exists('schedule_lifecycle_record_helper_heartbeat')) {
        schedule_lifecycle_record_helper_heartbeat($helperVersion, $helperBuild);
    }
    $automaticActivation = ['changed' => 0, 'schedule_ids' => []];
""",
    ),
    (
        """        $isScheduledPreparation = $isListing && !$isInstantListing && $startTs > $now
            && ($prepareScheduled || !empty($task['audit']['manualFullBatchStartedAt']));
        if ($isInstantListing && !$isConfirmedForce && !$isCatchupInstant && !$isRequestedInstant && !$isOwnGroupRapidListing) continue;
""",
        """        $isPreparedBatchListing = $isListing && function_exists('schedule_lifecycle_helper_may_claim_prepared_listing')
            ? schedule_lifecycle_helper_may_claim_prepared_listing(
                $task,
                $isOperatorStartRequest || isset($requestedTaskSet[(string)($task['id'] ?? '')]),
                $prepareScheduled
            )
            : ($isListing && (
                !empty($task['audit']['manualFullBatchStartedAt'])
                || !empty($task['audit']['cloudDispatchAt'])
            ));
        $isScheduledPreparation = $isListing && !$isInstantListing && $startTs > $now
            && ($prepareScheduled || $isPreparedBatchListing || !empty($task['audit']['manualFullBatchStartedAt']));
        if ($isInstantListing && !$isConfirmedForce && !$isCatchupInstant && !$isRequestedInstant && !$isOwnGroupRapidListing && !$isPreparedBatchListing) continue;
""",
    ),
    (
        """                    $batchTask['audit']['manualFullBatchStartedAt'] = faw_now();
                    $batchTask['audit']['manualFullBatchRule'] = $batchMode === 'instant'
""",
        """                    $batchTask['audit']['manualFullBatchStartedAt'] = faw_now();
                    $batchTask['audit']['cloudDispatchAt'] = faw_now();
                    $batchTask['audit']['manualFullBatchRule'] = $batchMode === 'instant'
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
