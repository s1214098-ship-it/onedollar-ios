#!/usr/bin/env python3
"""人工排程 keeps the operator's Facebook times; do not apply the 3-hour Codex lead."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """        $listingSourceForCreate = trim((string)($_POST['listing_source'] ?? 'codex'));
        if (!in_array($listingSourceForCreate, $allowedSourcesForCreate, true)) $listingSourceForCreate = 'codex';
""",
        """        $listingSourceForCreate = trim((string)($_POST['listing_source'] ?? 'codex'));
        if (!in_array($listingSourceForCreate, $allowedSourcesForCreate, true)) $listingSourceForCreate = 'codex';
        $manualListingCreate = function_exists('schedule_lifecycle_requires_facebook_lead')
            ? !schedule_lifecycle_requires_facebook_lead(['listing_source' => $listingSourceForCreate])
            : in_array($listingSourceForCreate, ['unassigned', 'self', 'staff'], true);
""",
    ),
    (
        """        $requestedFacebookLeadTooShort = false;
        $facebookLeadFloor = (int)(ceil((time() + 3 * 3600) / 300) * 300);
        if ($publishModeForCreate !== 'instant') {
            foreach ($jobs as $requestedJob) {
                $requestedTs = strtotime((string)($requestedJob['publish_date'] ?? '') . ' ' . (string)($requestedJob['publish_time'] ?? '')) ?: 0;
                if ($requestedTs > 0 && $requestedTs < $facebookLeadFloor) $requestedFacebookLeadTooShort = true;
            }
""",
        """        $requestedFacebookLeadTooShort = false;
        $facebookLeadFloor = (int)(ceil((time() + 3 * 3600) / 300) * 300);
        if ($publishModeForCreate !== 'instant' && empty($manualListingCreate)) {
            foreach ($jobs as $requestedJob) {
                $requestedTs = strtotime((string)($requestedJob['publish_date'] ?? '') . ' ' . (string)($requestedJob['publish_time'] ?? '')) ?: 0;
                if ($requestedTs > 0 && $requestedTs < $facebookLeadFloor) $requestedFacebookLeadTooShort = true;
            }
""",
    ),
    (
        """                    $jobStartTs = max($facebookLeadFloor, $requestedStartTs, (int)($closeSlotCursors[$closeKey] ?? $windowStart), $windowStart);
""",
        """                    $jobStartTs = max($manualListingCreate ? $requestedStartTs : $facebookLeadFloor, $requestedStartTs, (int)($closeSlotCursors[$closeKey] ?? $windowStart), $windowStart);
""",
    ),
    (
        """            if ($publishModeForCreate !== 'instant' && ($jobStartTs <= time() || !schedule_lifecycle_valid_auction_window($jobStartTs,$jobEndTs))) $invalidJobWindow = true;
""",
        """            if ($publishModeForCreate !== 'instant' && empty($manualListingCreate) && ($jobStartTs <= time() || !schedule_lifecycle_valid_auction_window($jobStartTs,$jobEndTs))) $invalidJobWindow = true;
            if ($publishModeForCreate !== 'instant' && !empty($manualListingCreate) && ($jobStartTs <= 0 || $jobEndTs <= $jobStartTs || date('Y-m-d', $jobStartTs) !== date('Y-m-d', $jobEndTs))) $invalidJobWindow = true;
""",
    ),
    (
        """          <div class="schedule-plan-rule-note" role="note"><b>已套用多筆上架規則</b><span>同一產品編號可拆成多標；當日排滿後日期自動往後；週六、週日只排 23:59；上架總件數仍須配對相同數量的產品序號後六碼。</span></div>
""",
        """          <div class="schedule-plan-rule-note" role="note"><b>已套用多筆上架規則</b><span>同一產品編號可拆成多標；當日排滿後日期自動往後；週六、週日只排 23:59；上架總件數仍須配對相同數量的產品序號後六碼。員工／自行上架不套用「距現在 3 小時」距離，時間以你選的為準。</span></div>
""",
    ),
    (
        """function regenerateScheduleTimeJobs() {
""",
        """function scheduleListingNeedsFacebookLead() {
  const source = String(document.querySelector('.schedule-form [name="listing_source"]')?.value || 'codex').toLowerCase();
  return !['staff', 'self', 'unassigned', 'manual', '人工', '人工上架'].includes(source);
}
function regenerateScheduleTimeJobs() {
""",
    ),
    (
        """  const facebookLeadFloor = Date.now() + (3 * 60 * 60 * 1000);
  let autoShiftedStart = false;
  if (Number.isFinite(requestedFirstPublish.getTime()) && requestedFirstPublish.getTime() < facebookLeadFloor) {
    startDate = addDaysYmd(startDate, 1);
    if (startDateInput) startDateInput.value = startDate;
    autoShiftedStart = true;
  }
""",
        """  const facebookLeadFloor = Date.now() + (3 * 60 * 60 * 1000);
  let autoShiftedStart = false;
  if (scheduleListingNeedsFacebookLead() && Number.isFinite(requestedFirstPublish.getTime()) && requestedFirstPublish.getTime() < facebookLeadFloor) {
    startDate = addDaysYmd(startDate, 1);
    if (startDateInput) startDateInput.value = startDate;
    autoShiftedStart = true;
  }
""",
    ),
    (
        """  if (summary) summary.textContent = `${autoShiftedStart ? `原起始時間距現在不足 3 小時，整批已自動改從 ${startDate} 開始。` : ''}${ruleSummary} 共 ${requested} 件；每個排程日都從 ${publishStartTime} 起，每 5 分鐘一標。`;
""",
        """  if (summary) summary.textContent = `${scheduleListingNeedsFacebookLead() ? (autoShiftedStart ? `原起始時間距現在不足 3 小時，整批已自動改從 ${startDate} 開始。` : '') : '人工排程使用你選的上架時間，不套用距現在 3 小時的距離。'}${ruleSummary} 共 ${requested} 件；每個排程日都從 ${publishStartTime} 起，每 5 分鐘一標。`;
""",
    ),
    (
        """  if (publishMode !== 'instant') {
    const minimumScheduledAt = Date.now() + (3 * 60 * 60 * 1000);
    const tooSoon = jobs.find(job => {
      const stamp = new Date(`${job.publish_date}T${job.publish_time || '00:00'}:00`).getTime();
      return Number.isFinite(stamp) && stamp < minimumScheduledAt;
    });
    if (tooSoon) {
      showStatus('您選的上架時間距現在不足 3 小時，系統不會自行改時間。請改選較晚時間，或回上方使用「立即上架」直接發文。', true);
      restoreButton();
      return;
    }
  }
""",
        """  if (publishMode !== 'instant' && scheduleListingNeedsFacebookLead()) {
    const minimumScheduledAt = Date.now() + (3 * 60 * 60 * 1000);
    const tooSoon = jobs.find(job => {
      const stamp = new Date(`${job.publish_date}T${job.publish_time || '00:00'}:00`).getTime();
      return Number.isFinite(stamp) && stamp < minimumScheduledAt;
    });
    if (tooSoon) {
      showStatus('您選的上架時間距現在不足 3 小時，系統不會自行改時間。請改選較晚時間，或回上方使用「立即上架」直接發文。', true);
      restoreButton();
      return;
    }
  }
""",
    ),
    (
        """['schedulePlanMode','schedulePlanTotalQty','schedulePlanLotPreset','schedulePlanLotQty','schedulePlanStartDate','schedulePlanPublishStartTime','schedulePlanLotsPerDay'].forEach(id => {
  document.getElementById(id)?.addEventListener('change', () => {
    const customWrap = document.getElementById('schedulePlanCustomWrap');
    if (customWrap) customWrap.hidden = document.getElementById('schedulePlanLotPreset')?.value !== 'custom';
    regenerateScheduleTimeJobs();
  });
  if (['schedulePlanTotalQty','schedulePlanLotQty','schedulePlanLotsPerDay'].includes(id)) document.getElementById(id)?.addEventListener('input', regenerateScheduleTimeJobs);
});
""",
        """['schedulePlanMode','schedulePlanTotalQty','schedulePlanLotPreset','schedulePlanLotQty','schedulePlanStartDate','schedulePlanPublishStartTime','schedulePlanLotsPerDay'].forEach(id => {
  document.getElementById(id)?.addEventListener('change', () => {
    const customWrap = document.getElementById('schedulePlanCustomWrap');
    if (customWrap) customWrap.hidden = document.getElementById('schedulePlanLotPreset')?.value !== 'custom';
    regenerateScheduleTimeJobs();
  });
  if (['schedulePlanTotalQty','schedulePlanLotQty','schedulePlanLotsPerDay'].includes(id)) document.getElementById(id)?.addEventListener('input', regenerateScheduleTimeJobs);
});
document.querySelector('.schedule-form [name="listing_source"]')?.addEventListener('change', regenerateScheduleTimeJobs);
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
