(function () {
  "use strict";

  var CARD_ID = "leaveDashboardCard";
  var BODY_ID = "leaveDashboard";
  var COUNT_ID = "leaveDashboardPendingCount";

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[ch];
    });
  }

  function canManage() {
    try {
      if (typeof canManageLeaveRecords === "function") return !!canManageLeaveRecords();
      if (typeof isAdmin !== "undefined" && isAdmin) return true;
    } catch (e) {}
    return false;
  }

  function leaveList() {
    try {
      if (typeof data === "object" && data && Array.isArray(data.leaves)) return data.leaves.slice();
    } catch (e) {}
    return [];
  }

  function statusOf(row) {
    return String((row && row.status) || "已登錄");
  }

  function isPending(row) {
    return statusOf(row) === "待審核";
  }

  function periodText(row) {
    var start = (row && (row.startDate || row.date)) || "-";
    var end = (row && (row.endDate || row.date)) || start;
    var lunch = (row && (row.startTime || row.endTime))
      ? (row.lunchBreak === false ? "（中午不休）" : "（扣午休）")
      : "";
    var timeRange = (row && (row.startTime || row.endTime))
      ? " " + (row.startTime || "") + " ~ " + (row.endTime || "") + lunch
      : "";
    return start + " 至 " + end + timeRange;
  }

  function unitsText(row) {
    if (row && row.hours) return String(row.hours);
    try {
      if (typeof leaveUnits === "function") return leaveUnits(row) + " 天";
    } catch (e) {}
    return "-";
  }

  function statusBadge(status) {
    var s = status || "已登錄";
    var cls = s === "待審核" ? "warning text-dark" : s === "已退回" ? "danger" : s === "預先請假" ? "info" : "success";
    return '<span class="badge bg-' + cls + '">' + esc(s) + "</span>";
  }

  function sortLeaves(list) {
    return list.slice().sort(function (a, b) {
      var pa = isPending(a) ? 0 : 1;
      var pb = isPending(b) ? 0 : 1;
      if (pa !== pb) return pa - pb;
      return String((b && (b.createdAt || b.date)) || "").localeCompare(String((a && (a.createdAt || a.date)) || ""));
    });
  }

  function ensureCard() {
    if (document.getElementById(CARD_ID)) return document.getElementById(CARD_ID);
    var html = ""
      + '<div class="form-card mb-4" id="' + CARD_ID + '">'
      + '  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">'
      + "    <div>"
      + '      <h5 class="mb-1">員工請假表</h5>'
      + '      <div class="small text-muted">待審核的請假會排在最上面，可直接核准或退回，不必再到請假管理頁。</div>'
      + "    </div>"
      + '    <div class="d-flex flex-wrap align-items-center gap-2">'
      + '      <span class="badge text-bg-warning" id="' + COUNT_ID + '">待審核 0 筆</span>'
      + '      <button class="btn btn-sm btn-outline-secondary" type="button" id="leaveDashboardRefreshBtn">重新整理</button>'
      + '      <button class="btn btn-sm btn-outline-primary" type="button" id="leaveDashboardOpenBtn">打開請假管理</button>'
      + "    </div>"
      + "  </div>"
      + '  <div class="table-responsive">'
      + '    <table class="table table-sm table-bordered align-middle text-center">'
      + "      <thead class=\"table-light\">"
      + "        <tr><th>員工</th><th>假別</th><th>期間</th><th>時數</th><th>事由</th><th>狀態</th><th>送出時間</th><th>操作</th></tr>"
      + "      </thead>"
      + '      <tbody id="' + BODY_ID + '"></tbody>'
      + "    </table>"
      + "  </div>"
      + "</div>";
    var wrap = document.createElement("div");
    wrap.innerHTML = html;
    var card = wrap.firstChild;
    var ref = document.getElementById("taskDashboardCard")
      || document.getElementById("taskReportCenterCard")
      || document.getElementById("salaryFeedbackCard");
    if (ref && ref.parentNode) ref.parentNode.insertBefore(card, ref);
    else {
      var dash = document.getElementById("page-dashboard");
      if (dash) dash.appendChild(card);
    }
    var refreshBtn = document.getElementById("leaveDashboardRefreshBtn");
    if (refreshBtn) refreshBtn.addEventListener("click", function () { renderLeaveDashboard(); });
    var openBtn = document.getElementById("leaveDashboardOpenBtn");
    if (openBtn) openBtn.addEventListener("click", function () {
      if (typeof goPage === "function") goPage("leaveManage");
    });
    return card;
  }

  function renderLeaveDashboard() {
    var card = ensureCard();
    if (!card) return;
    if (!canManage()) {
      card.classList.add("page-hide");
      return;
    }
    card.classList.remove("page-hide");
    var body = document.getElementById(BODY_ID);
    var countEl = document.getElementById(COUNT_ID);
    if (!body) return;
    var all = sortLeaves(leaveList());
    var pending = all.filter(isPending);
    if (countEl) {
      countEl.textContent = "待審核 " + pending.length + " 筆";
      countEl.className = pending.length ? "badge text-bg-warning" : "badge text-bg-light border";
    }
    var rows = all.slice(0, 30);
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="8" class="text-muted">目前沒有員工請假紀錄。</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      var pendingRow = isPending(row);
      var action = pendingRow
        ? '<button class="btn btn-sm btn-success me-1" type="button" data-leave-approve="' + esc(row.id) + '">核准</button>'
          + '<button class="btn btn-sm btn-warning" type="button" data-leave-reject="' + esc(row.id) + '">退回</button>'
        : '<button class="btn btn-sm btn-outline-primary" type="button" data-leave-open="1">查看</button>';
      return "<tr>"
        + "<td>" + esc(row.emp || "-") + "</td>"
        + "<td>" + esc(row.type || "-") + "</td>"
        + '<td class="text-start">' + esc(periodText(row)) + "</td>"
        + "<td>" + esc(unitsText(row)) + "</td>"
        + '<td class="text-start">' + esc(row.memo || "-") + "</td>"
        + "<td>" + statusBadge(statusOf(row)) + "</td>"
        + "<td>" + esc(row.createdAt || row.approvedAt || "-") + "</td>"
        + "<td>" + action + "</td>"
        + "</tr>";
    }).join("");
  }

  function wrapFn(name, after) {
    if (typeof window[name] !== "function" || window[name].__leaveDash) return;
    var orig = window[name];
    function wrapped() {
      var result = orig.apply(this, arguments);
      try { after(); } catch (e) {}
      return result;
    }
    wrapped.__leaveDash = true;
    window[name] = wrapped;
  }

  function onClick(ev) {
    var t = ev.target;
    if (!t || !t.getAttribute) return;
    var approve = t.getAttribute("data-leave-approve");
    var reject = t.getAttribute("data-leave-reject");
    if (approve && typeof approveLeaveRequest === "function") {
      approveLeaveRequest(approve);
      setTimeout(renderLeaveDashboard, 50);
      return;
    }
    if (reject && typeof rejectLeaveRequest === "function") {
      rejectLeaveRequest(reject);
      setTimeout(renderLeaveDashboard, 50);
      return;
    }
    if (t.getAttribute("data-leave-open") && typeof goPage === "function") goPage("leaveManage");
  }

  function apply() {
    wrapFn("refreshDashboard", renderLeaveDashboard);
    wrapFn("approveLeaveRequest", renderLeaveDashboard);
    wrapFn("rejectLeaveRequest", renderLeaveDashboard);
    wrapFn("searchLeaveRecord", renderLeaveDashboard);
    renderLeaveDashboard();
  }

  document.addEventListener("click", onClick);
  apply();
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", apply);
})();
