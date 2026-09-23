(function () {
  "use strict";

  function num(value) {
    return Number(value) || 0;
  }

  function money(value) {
    if (typeof cdMoney === "function") return cdMoney(value);
    return `${Math.round(num(value)).toLocaleString("zh-TW")} 元`;
  }

  function splitCustomsNos(text) {
    return String(text || "")
      .split(/[\s,，、;；/|]+/)
      .map(function (part) { return part.trim(); })
      .filter(Boolean);
  }

  function customsNosFromRow(row) {
    if (Array.isArray(row && row.customsNos) && row.customsNos.length) {
      const fromList = [];
      row.customsNos.forEach(function (value) {
        splitCustomsNos(value).forEach(function (no) { fromList.push(no); });
      });
      if (fromList.length) return uniqueNos(fromList);
    }
    const text = typeof cdCustomsNoText === "function"
      ? cdCustomsNoText(row)
      : String((row && (row.customsNo || row.customsNumber)) || "");
    return uniqueNos(splitCustomsNos(text));
  }

  function uniqueNos(list) {
    const seen = new Set();
    const out = [];
    (list || []).forEach(function (no) {
      const key = String(no || "").trim();
      if (!key || seen.has(key)) return;
      seen.add(key);
      out.push(key);
    });
    return out;
  }

  function handlingCount(row) {
    if (typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row)) return 0;
    return customsNosFromRow(row).length;
  }

  function handlingFee(rowOrCount) {
    const count = typeof rowOrCount === "number" ? rowOrCount : handlingCount(rowOrCount);
    return count * 30;
  }

  function customsVerifyExpected(duty, late) {
    return num(duty) + num(late);
  }

  function customsVerifyFee(duty, late, charge) {
    return customsVerifyExpected(duty, late) - num(charge);
  }

  function productCost(charge, handling) {
    return num(charge) + num(handling);
  }

  function verifyKind(diff) {
    const n = num(diff);
    if (n > 0) return "courier_short";
    if (n < 0) return "overpaid";
    return "even";
  }

  function verifyLabel(diff) {
    const kind = verifyKind(diff);
    if (kind === "courier_short") return "快遞少收（我少給）";
    if (kind === "overpaid") return "我多給";
    return "剛好對上";
  }

  function applyGroupVerify(g) {
    if (!g) return g;
    const nos = [];
    (g.rows || []).forEach(function (row) {
      customsNosFromRow(row).forEach(function (no) { nos.push(no); });
    });
    const unique = uniqueNos(nos);
    const taxFree = num(g.taxableRows) <= 0;
    g.single = taxFree ? 0 : unique.length * 30;
    g.expectedTotal = taxFree ? 0 : customsVerifyExpected(g.duty, g.late);
    g.diff = g.expectedTotal - num(g.baseCharge);
    g.productCost = taxFree ? 0 : productCost(g.baseCharge, g.single);
    g.verifyKind = verifyKind(g.diff);
    g.isAbnormal = g.diff !== 0;
    if (Array.isArray(g.rows) && typeof cdIsAnomalyResolved === "function") {
      g.resolved = g.isAbnormal && g.rows.every(cdIsAnomalyResolved);
    }
    return g;
  }

  window.cdRowPackageCountForFee = function (row) {
    return handlingCount(row);
  };

  window.cdRowExpectedTotal = function (row) {
    if (typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row)) return 0;
    return customsVerifyExpected(
      typeof cdRowDutyTotal === "function" ? cdRowDutyTotal(row) : 0,
      typeof cdRowLateTotal === "function" ? cdRowLateTotal(row) : 0
    );
  };

  window.cdRowVerificationFee = function (row) {
    if (typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row)) return 0;
    return customsVerifyFee(
      typeof cdRowDutyTotal === "function" ? cdRowDutyTotal(row) : 0,
      typeof cdRowLateTotal === "function" ? cdRowLateTotal(row) : 0,
      typeof cdRowChargeTotal === "function" ? cdRowChargeTotal(row) : 0
    );
  };

  window.cdRowProductCost = function (row) {
    if (typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row)) return 0;
    const charge = typeof cdRowChargeTotal === "function" ? cdRowChargeTotal(row) : 0;
    return productCost(charge, handlingFee(row));
  };

  window.cdIsAbnormalDiff = function (value) {
    return num(value) !== 0;
  };

  window.cdDiffClass = function (value) {
    const n = num(value);
    if (n > 0) return "text-warning fw-bold";
    if (n < 0) return "text-danger fw-bold";
    return "text-success fw-bold";
  };

  window.cdDiffResultText = function (value) {
    const n = num(value);
    if (n > 0) return "快遞少收（我少給） " + money(n);
    if (n < 0) return "我多給 " + money(Math.abs(n));
    return "剛好對上";
  };

  const origBuildGroups = window.cdBuildGroups;
  window.cdBuildGroups = function (rows) {
    const groups = typeof origBuildGroups === "function" ? origBuildGroups(rows) : [];
    return (Array.isArray(groups) ? groups : []).map(applyGroupVerify);
  };

  function formCustomsCount() {
    return splitCustomsNos(document.getElementById("cdCustomsNo")?.value || "").length;
  }

  function ensureProductCostBox() {
    if (document.getElementById("cdProductCost")) return;
    const single = document.getElementById("cdSingleFee");
    const col = single && single.closest(".col-md-2, .col-md-3, .col-md-4");
    if (!col || !col.parentElement) return;
    const wrap = document.createElement("div");
    wrap.className = "col-md-2";
    wrap.innerHTML = '<label>產品成本</label><input class="form-control" id="cdProductCost" readonly value="0"><div class="small text-muted" id="cdProductCostExplain">快遞收費 + 每筆關貿手續費 30 元</div>';
    col.insertAdjacentElement("afterend", wrap);
  }

  window.updateCustomsDutyPackageFeeHint = function () {
    ensureProductCostBox();
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
    const nos = splitCustomsNos(customsNo);
    const handlingCountValue = taxFree ? 0 : nos.length;
    const handling = handlingCountValue * 30;
    const single = document.getElementById("cdSingleFee");
    if (single) single.value = handling;
    const singleExplain = document.getElementById("cdSingleFeeExplain");
    if (singleExplain) {
      singleExplain.textContent = taxFree
        ? "免稅物流商 / 免稅集運倉，此筆不計關貿手續費。"
        : (handlingCountValue
          ? `關貿號碼 ${handlingCountValue} 筆 × 30 元 = ${handling} 元手續費。台灣快遞 ${packageCount || 0} 筆不加這 30。`
          : "請先填關貿／關稅號碼，每筆固定收 30 元手續費；不是看台灣快遞幾筆。");
    }
    const extraCharge = extraRows.reduce(function (n, row) { return n + num(row.charge); }, 0);
    const extraDuty = extraRows.reduce(function (n, row) { return n + num(row.dutyFee); }, 0);
    const extraLate = extraRows.reduce(function (n, row) { return n + num(row.lateFee); }, 0);
    const expected = taxFree ? 0 : customsVerifyExpected(firstDuty + extraDuty, firstLate + extraLate);
    const chargeTotal = firstCharge + extraCharge;
    const verify = taxFree ? 0 : customsVerifyFee(firstDuty + extraDuty, firstLate + extraLate, chargeTotal);
    const cost = taxFree ? 0 : productCost(chargeTotal, handling);
    const extraFee = document.getElementById("cdExtraFee");
    if (extraFee) extraFee.value = String(verify);
    const costInput = document.getElementById("cdProductCost");
    if (costInput) costInput.value = String(cost);
    const hint = document.getElementById("cdPackageFeeHint");
    if (hint) {
      if (taxFree) {
        hint.textContent = "此筆命中免稅物流或免稅集運倉，關貿手續費 30、關稅與滯報費不列入核實與產品成本。";
      } else if (handlingCountValue) {
        const verifyNote = verify > 0
          ? `核實 ${verify} 元是快遞少收（我少給）：關貿 ${expected} − 快遞 ${chargeTotal}。`
          : (verify < 0
            ? `核實 ${verify} 元是我多給：關貿 ${expected} − 快遞 ${chargeTotal}。`
            : "核實剛好對上。");
        hint.textContent = `核實只做關貿收費（關稅＋滯報）− 快遞收費，30 元手續費不加入這個差額。關貿 ${nos.join("、")} 共 ${handlingCountValue} 筆，手續費 ${handling} 元。台灣快遞 ${packageCount || 0} 筆不加收 30。${verifyNote} 產品成本 = 快遞 ${chargeTotal} + 手續費 ${handling} = ${cost} 元。`;
      } else {
        hint.textContent = "核實 = 關貿收費 − 快遞收費。正數是快遞少收（我少給），負數是我多給，0 是剛好。30 元是每筆關貿固定手續費，不加入核實差額，但要計入產品成本。";
      }
    }
  };

  const origRead = window.readCustomsDutyForm;
  window.readCustomsDutyForm = function () {
    const row = typeof origRead === "function" ? origRead() : {};
    const count = formCustomsCount();
    const taxFree = typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row);
    row.singleFee = taxFree ? 0 : count * 30;
    row.productCost = taxFree ? 0 : productCost(row.batchCharge, row.singleFee);
    return row;
  };

  function statusBadge(g) {
    if (!g || !g.diff) return '<span class="badge bg-success">剛好</span>';
    const label = g.diff > 0 ? "快遞少收" : "我多給";
    if (g.resolved) return `<span class="badge bg-primary">${label}已查核</span>`;
    return `<span class="badge bg-warning text-dark">${label}待查核</span>`;
  }

  const origBatch = window.renderCustomsDutyBatchTable;
  window.renderCustomsDutyBatchTable = function (rows) {
    if (typeof origBatch === "function") origBatch(rows);
    const list = typeof cdBuildGroups === "function" ? cdBuildGroups(rows) : [];
    const body = document.getElementById("customsDutyBatchTable");
    if (!body) return;
    const trs = Array.prototype.slice.call(body.querySelectorAll("tr"));
    trs.forEach(function (tr, i) {
      const g = list[i];
      if (!g) return;
      const tds = tr.children;
      if (tds.length < 13) return;
      tds[7].textContent = money(g.single);
      tds[10].className = cdDiffClass(g.diff);
      tds[10].textContent = money(g.diff);
      tds[11].innerHTML = `${money(g.expectedTotal)}<div class="small text-muted">產品成本 ${money(g.productCost)}</div>`;
      tds[12].className = cdDiffClass(g.diff);
      tds[12].innerHTML = `${cdDiffResultText(g.diff)}<div class="small text-muted">產品成本 ${money(g.productCost)}</div>`;
      tds[13].innerHTML = statusBadge(g);
      tr.classList.remove("table-warning", "table-danger");
      if (g.diff > 0) tr.classList.add("table-warning");
      if (g.diff < 0) tr.classList.add("table-danger");
    });
  };

  function enhanceDetailTable() {
    const body = document.getElementById("customsDutyTable");
    if (!body || typeof data === "undefined") return;
    Array.prototype.forEach.call(body.querySelectorAll("tr"), function (tr) {
      const id = tr.querySelector(".cdRowCheck") && tr.querySelector(".cdRowCheck").value;
      if (!id) return;
      const row = (data.customsDutyEntries || []).find(function (item) {
        return String(item.id) === String(id);
      });
      if (!row) return;
      const tds = tr.children;
      if (tds.length < 11) return;
      const taxFree = typeof cdIsTaxFreeRow === "function" && cdIsTaxFreeRow(row);
      const handling = handlingFee(row);
      const verify = typeof cdRowVerificationFee === "function" ? cdRowVerificationFee(row) : 0;
      const cost = typeof cdRowProductCost === "function" ? cdRowProductCost(row) : 0;
      tds[7].innerHTML = taxFree ? '<span class="text-muted">不計</span>' : money(handling);
      tds[10].className = taxFree ? "" : cdDiffClass(verify);
      tds[10].innerHTML = taxFree
        ? "免稅不核實"
        : `${money(verify)}<div class="small">${verifyLabel(verify)}</div><div class="small text-muted">產品成本 ${money(cost)}</div>`;
    });
  }

  const origPager = window.renderCustomsDutyPager;
  window.renderCustomsDutyPager = function (totalRows, currentPage, pageSize, totalPages) {
    if (typeof origPager === "function") origPager(totalRows, currentPage, pageSize, totalPages);
    const box = document.getElementById("customsDutyPager");
    if (!box) return;
    Array.prototype.forEach.call(box.querySelectorAll("div"), function (el) {
      const t = String(el.textContent || "");
      if (t.includes("多收") || t.includes("少給快遞") || t.includes("核實正常")) {
        el.textContent = t
          .replace("核實正常七天後會收起來，多收會留著追。", "剛好對上的七天後會收起來；快遞少收或我多給會留著查核。")
          .replace("正常資料（含少給快遞）超過 7 天會從預設清單收起來，沒有刪除。搜尋單號或勾「含七天前已核實」可找回。", "剛好對上的資料超過 7 天會從預設清單收起來，沒有刪除。快遞少收或我多給會留著查核。");
      }
    });
  };

  const origRender = window.renderCustomsDuty;
  window.renderCustomsDuty = function () {
    if (typeof origRender === "function") origRender.apply(this, arguments);
    enhanceDetailTable();
    patchHelperCopy();
  };

  const origSummary = window.renderCustomsDutySummary;
  window.renderCustomsDutySummary = function (rows) {
    if (typeof origSummary === "function") origSummary(rows);
    const box = document.getElementById("customsDutySummary");
    if (!box) return;
    Array.prototype.forEach.call(box.querySelectorAll(".text-muted"), function (el) {
      const t = String(el.textContent || "");
      if (t.includes("正常")) el.textContent = "剛好（關貿＝快遞）";
      if (t.includes("異常未完成")) el.textContent = "差額待查核";
      if (t.includes("系統應收")) el.textContent = "關貿收費合計";
    });
  };

  function patchFilters() {
    const diff = document.getElementById("cdFilterDiff");
    if (diff) {
      Array.prototype.forEach.call(diff.options, function (opt) {
        if (opt.value === "normal") opt.textContent = "剛好對上";
        if (opt.value === "abnormal") opt.textContent = "有差額（少收或我多給）";
        if (opt.value === "positive") opt.textContent = "快遞少收（我少給）";
        if (opt.value === "negative") opt.textContent = "我多給";
      });
    }
    const anomaly = document.getElementById("cdFilterAnomalyStatus");
    if (anomaly) {
      Array.prototype.forEach.call(anomaly.options, function (opt) {
        if (opt.value === "open") opt.textContent = "差額未查核";
        if (opt.value === "done") opt.textContent = "差額已查核";
      });
    }
  }

  function replaceCopy(el, testers, next) {
    if (!el) return;
    const t = String(el.textContent || "");
    if (testers.some(function (fn) { return fn(t); })) el.textContent = next;
  }

  function patchHelperCopy() {
    patchFilters();
    ensureProductCostBox();
    const page = document.getElementById("page-customsDuty");
    if (page) {
      replaceCopy(page.querySelector("h4 + .text-muted, .mb-1 + .text-muted"), [
        function (t) { return t.includes("產品成本") || t.includes("手續費") || t.includes("核實"); }
      ], "核實 = 關貿收費（關稅＋滯報）− 快遞收費。正數是快遞少收（我少給），負數是我多給，0 是剛好。30 元是每筆關貿固定手續費，不加入核實差額，但產品成本要加進去。");
    }
    const extra = document.getElementById("cdExtraFee");
    const help = extra && extra.parentElement && extra.parentElement.querySelector(".small.text-muted");
    if (help) {
      help.textContent = "核實 = 關貿收費 − 快遞收費。這格只查快遞少收、我多給或剛好；30 元手續費不加入此差額。";
    }
    document.querySelectorAll("#page-customsDuty .small.text-muted").forEach(function (el) {
      const t = String(el.textContent || "");
      if (t.includes("系統應收") || t.includes("產品成本以快遞收費為準") || t.includes("少給快遞視為正常") || t.includes("關稅＋30") || t.includes("仍只算這一筆關稅號碼 30")) {
        if (el.id === "cdSingleFeeExplain" || el.id === "cdProductCostExplain") return;
        if (el.closest("td")) return;
        el.textContent = "核實只對關貿收費與快遞收費。30 元是每筆關貿手續費，要計入產品成本，但不加入核實差額。";
      }
    });
    if (typeof updateCustomsDutyPackageFeeHint === "function") updateCustomsDutyPackageFeeHint();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", patchHelperCopy);
  else patchHelperCopy();
  window.addEventListener("load", patchHelperCopy);

  window.baohuiCustomsVerify = {
    split: splitCustomsNos,
    expected: customsVerifyExpected,
    fee: customsVerifyFee,
    handling: handlingFee,
    productCost: productCost,
    kind: verifyKind,
    label: verifyLabel,
  };
})();
