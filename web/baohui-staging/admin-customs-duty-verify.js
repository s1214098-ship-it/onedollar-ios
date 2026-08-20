(function () {
  "use strict";

  function num(value) {
    return Number(value) || 0;
  }

  function customsVerifyExpected(duty, late) {
    return num(duty) + num(late);
  }

  function customsVerifyFee(duty, late, charge) {
    return customsVerifyExpected(duty, late) - num(charge);
  }

  function applyGroupVerify(g) {
    if (!g) return g;
    g.expectedTotal = customsVerifyExpected(g.duty, g.late);
    g.diff = g.expectedTotal - num(g.baseCharge);
    g.isAbnormal = typeof cdIsAbnormalDiff === "function" ? cdIsAbnormalDiff(g.diff) : g.diff > 0;
    if (Array.isArray(g.rows) && typeof cdIsAnomalyResolved === "function") {
      g.resolved = g.isAbnormal && g.rows.every(cdIsAnomalyResolved);
    }
    return g;
  }

  window.cdRowExpectedTotal = function (row) {
    if (typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row)) return 0;
    return customsVerifyExpected(
      typeof cdRowDutyTotal === "function" ? cdRowDutyTotal(row) : 0,
      typeof cdRowLateTotal === "function" ? cdRowLateTotal(row) : 0
    );
  };

  const origBuildGroups = window.cdBuildGroups;
  window.cdBuildGroups = function (rows) {
    const groups = typeof origBuildGroups === "function" ? origBuildGroups(rows) : [];
    return (Array.isArray(groups) ? groups : []).map(applyGroupVerify);
  };

  window.updateCustomsDutyPackageFeeHint = function () {
    const extraRows = typeof cdPackageRowsForFee === "function" ? cdPackageRowsForFee() : [];
    const firstTracking = String(document.getElementById("cdTrackingNo")?.value || "").trim();
    const firstCharge = num(document.getElementById("cdBatchCharge")?.value);
    const firstDuty = num(document.getElementById("cdDutyFee")?.value);
    const firstLate = num(document.getElementById("cdLateFee")?.value);
    const customsNo = String(document.getElementById("cdCustomsNo")?.value || "").trim();
    const packageCount = (firstTracking || firstCharge || firstDuty || firstLate ? 1 : 0) + extraRows.length;
    const taxFree = typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow({
      batch: document.getElementById("cdBatch")?.value || "",
      taxFreeWarehouse: document.getElementById("cdTaxFreeWarehouse")?.value || "",
    });
    const handlingCount = taxFree ? 0 : (customsNo ? 1 : 0);
    const handling = handlingCount * 30;
    const single = document.getElementById("cdSingleFee");
    if (single) single.value = handling;
    const singleExplain = document.getElementById("cdSingleFeeExplain");
    if (singleExplain) {
      singleExplain.textContent = taxFree
        ? "免稅物流商 / 免稅集運倉，此筆不計單筆手續費。"
        : (customsNo
          ? `關稅號碼 1 筆 × 30 元 = 30 元手續費（每筆關稅號碼固定收，不加入核實）。台灣快遞 ${packageCount || 0} 筆不加收。`
          : "請先填關稅號碼，手續費才會算 30 元；不是看台灣快遞幾筆。");
    }
    const extraCharge = extraRows.reduce((n, row) => n + num(row.charge), 0);
    const extraDuty = extraRows.reduce((n, row) => n + num(row.dutyFee), 0);
    const extraLate = extraRows.reduce((n, row) => n + num(row.lateFee), 0);
    const expected = taxFree ? 0 : customsVerifyExpected(firstDuty + extraDuty, firstLate + extraLate);
    const chargeTotal = firstCharge + extraCharge;
    const verify = taxFree ? 0 : customsVerifyFee(firstDuty + extraDuty, firstLate + extraLate, chargeTotal);
    const extraFee = document.getElementById("cdExtraFee");
    if (extraFee) extraFee.value = String(verify);
    const hint = document.getElementById("cdPackageFeeHint");
    if (hint) {
      if (taxFree) {
        hint.textContent = "此筆命中免稅物流或免稅集運倉，單筆 30、關稅與滯報費不列入核實。";
      } else if (customsNo) {
        const verifyNote = verify > 0
          ? `核實 ${verify} 元是多收，要追。`
          : (verify < 0 ? `核實 ${verify} 元是少給快遞，視為正常。產品成本以快遞收費 ${chargeTotal} 元為準。` : "核實剛好對上。產品成本以快遞收費為準。");
        hint.textContent = `手續費只算關稅號碼 ${customsNo} 這一筆 30 元，是固定手續費、不加入核實。台灣快遞 ${packageCount || 0} 筆不加收。核實 =（關稅＋滯報費）− 快遞收費。${verifyNote}`;
      } else {
        hint.textContent = "手續費依關稅號碼計算，一筆 30 元，不列入核實加收。台灣快遞加幾筆都不會變 60 元。核實只對關稅＋滯報與快遞收費。少給快遞視為正常，產品成本以快遞收費為準。";
      }
    }
  };

  function patchHelperCopy() {
    const extra = document.getElementById("cdExtraFee");
    const help = extra && extra.parentElement && extra.parentElement.querySelector(".small.text-muted");
    if (help) {
      help.textContent = "核實 =（關稅＋滯報費）− 快遞收費。手續費每筆關稅號碼固定收 30 元，不列入核實加收。少給快遞（負數）視為正常；產品成本以快遞收費為準。";
    }
    document.querySelectorAll("#page-customsDuty .small.text-muted, [data-page='customsDuty'] .small.text-muted").forEach((el) => {
      const t = String(el.textContent || "");
      if (t.includes("系統應收 = 關稅號碼 × 30") || t.includes("系統應收（關稅＋30）") || t.includes("關稅＋30")) {
        el.textContent = "核實費用 =（關稅＋滯報費）− 快遞收費。手續費每筆關稅號碼 30 元另收，不加入核實。少給快遞視為正常，產品成本以快遞收費為準。";
      }
    });
    if (typeof updateCustomsDutyPackageFeeHint === "function") updateCustomsDutyPackageFeeHint();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", patchHelperCopy);
  else patchHelperCopy();
  window.addEventListener("load", patchHelperCopy);

  window.baohuiCustomsVerify = { expected: customsVerifyExpected, fee: customsVerifyFee };
})();
