(function () {
  "use strict";

  const AUDIT_READY_NOTE = "完成時間、準確度與內容稽核稍後由系統執行，這次先收齊回報。";

  function clampRate(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    return Math.max(0, Math.min(100, Math.round(n)));
  }

  function nowLocal() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, "0");
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + " " + p(d.getHours()) + ":" + p(d.getMinutes());
  }

  function toLocalInput(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    return raw.replace(" ", "T").slice(0, 16);
  }

  function fromLocalInput(value) {
    return String(value || "").trim().replace("T", " ");
  }

  function ensureModalFields() {
    const proposed = document.getElementById("taskReportProposedAt");
    if (!proposed || document.getElementById("taskReportProgressRate")) return;
    const label = proposed.closest(".col-md-8")?.querySelector("label");
    if (label) label.innerHTML = "二次修正進度時間<small class=\"text-muted d-block\">回報者提出的進度／完成時間，主管評估可行性後才核准</small>";
    const row = proposed.closest(".row") || proposed.parentElement;
    const extra = document.createElement("div");
    extra.className = "row g-3 mt-0";
    extra.innerHTML = `
      <div class="col-md-4">
        <label class="form-label">目前完成率（%）</label>
        <input type="number" class="form-control" id="taskReportProgressRate" min="0" max="100" step="1" value="0">
      </div>
      <div class="col-md-8">
        <label class="form-label">回填進度時間</label>
        <input type="datetime-local" class="form-control" id="taskReportProgressAt">
        <div class="form-text">這次實際做到這個進度的時間，不是截止日期。</div>
      </div>
      <div class="col-12">
        <label class="form-label">目前做到哪裡（必填）</label>
        <textarea class="form-control" id="taskReportProgressNote" rows="3" placeholder="請寫現在做到哪、還剩什麼、卡在哪。例如：電子發票已全印，大陸品庫存還在建檔。"></textarea>
      </div>
      <div class="col-12 small text-muted">${AUDIT_READY_NOTE}</div>
    `;
    row.parentNode.insertBefore(extra, row.nextSibling);
    const type = document.getElementById("taskReportType");
    if (type && ![...type.options].some((o) => o.value === "進度回填")) {
      const opt = document.createElement("option");
      opt.value = "進度回填";
      opt.textContent = "進度回填";
      type.insertBefore(opt, type.firstChild);
    }
    const detail = document.getElementById("taskReportDetail");
    if (detail) {
      const dLabel = detail.previousElementSibling;
      if (dLabel && dLabel.tagName === "LABEL") dLabel.textContent = "回報說明 / 需要主管協助什麼";
    }
  }

  function staffRate(name) {
    const tasks = (window.data && Array.isArray(data.tasks) ? data.tasks : []).filter((t) => String(t.assign || "") === String(name || ""));
    if (!tasks.length) return { total: 0, done: 0, rate: 0, latest: 0 };
    const done = tasks.filter((t) => t.status === "完成").length;
    const latest = tasks.reduce((max, t) => Math.max(max, clampRate(t.progressRate)), 0);
    return { total: tasks.length, done, rate: Math.round(done / tasks.length * 100), latest };
  }

  function progressBlock(task) {
    if (!task) return "";
    const rate = task.status === "完成" ? 100 : clampRate(task.progressRate);
    const progressAt = task.progressAt || "";
    const note = task.progressNote || "";
    const second = task.approvedCompleteAt
      ? `核准第二時間：${task.approvedCompleteAt}`
      : (task.proposedCompleteAt ? `二次修正進度時間：${task.proposedCompleteAt}（${task.extensionStatus || "待評估可行性"}）` : "");
    const feas = task.feasibility ? `可行性：${task.feasibility}${task.feasibilityNote ? "／" + task.feasibilityNote : ""}` : "";
    return `<div class="small text-start border rounded p-2 mb-1 bg-light">
      <div><b>完成率 ${rate}%</b>${progressAt ? `　回填進度時間 ${progressAt}` : ""}</div>
      ${note ? `<div>目前進度：${String(note).replace(/[<>]/g, "")}</div>` : `<div class="text-danger">尚未回報目前進度</div>`}
      ${second ? `<div>${second}</div>` : ""}
      ${feas ? `<div>${feas}</div>` : ""}
    </div>`;
  }

  function enhanceTaskTable() {
    const rows = document.querySelectorAll("#taskTable tbody tr");
    rows.forEach((tr) => {
      if (tr.querySelector("[data-progress-block]")) return;
      const name = (tr.children[0]?.textContent || "").trim();
      const assign = (tr.children[1]?.textContent || "").trim();
      if (!name || !assign) return;
      const task = (data.tasks || []).find((t) => String(t.name || "") === name && String(t.assign || "") === assign);
      if (!task) return;
      const td = tr.children[7];
      if (!td) return;
      const wrap = document.createElement("div");
      wrap.setAttribute("data-progress-block", "1");
      wrap.innerHTML = progressBlock(task);
      td.insertBefore(wrap, td.firstChild);
    });
  }

  function enhanceDashboard() {
    const summary = document.getElementById("taskDashboardSummary");
    if (!summary || summary.querySelector("[data-staff-rates]")) return;
    const names = Array.from(new Set((data.tasks || []).map((t) => String(t.assign || "").trim()).filter(Boolean)));
    if (!names.length) return;
    const focus = names.filter((n) => /曾憲|曾麒/.test(n));
    const ordered = focus.concat(names.filter((n) => !focus.includes(n)));
    const html = ordered.map((name) => {
      const s = staffRate(name);
      const warn = s.rate < 100 && s.total ? "text-danger" : "text-success";
      return `<span class="me-3 ${warn}"><b>${name}</b> 完成率 ${s.rate}%（${s.done}/${s.total}）｜目前進度 ${s.latest}%</span>`;
    }).join("");
    const box = document.createElement("div");
    box.className = "mt-2 small";
    box.setAttribute("data-staff-rates", "1");
    box.innerHTML = `<div class="border rounded p-2 bg-white">人員完成率：${html}<div class="text-muted mt-1">${AUDIT_READY_NOTE}</div></div>`;
    summary.appendChild(box);
  }

  const origOpen = window.openTaskReportModal;
  window.openTaskReportModal = function (taskId) {
    if (typeof origOpen === "function") origOpen(taskId);
    ensureModalFields();
    const task = (data.tasks || []).find((x) => String(x.id) === String(taskId));
    const rate = document.getElementById("taskReportProgressRate");
    const at = document.getElementById("taskReportProgressAt");
    const note = document.getElementById("taskReportProgressNote");
    if (rate) rate.value = String(clampRate(task && task.progressRate));
    if (at) at.value = toLocalInput((task && task.progressAt) || nowLocal());
    if (note) note.value = (task && task.progressNote) || "";
    const type = document.getElementById("taskReportType");
    if (type && !task?.lastReportType) type.value = "進度回填";
  };

  const origSubmit = window.submitTaskReportModal;
  window.submitTaskReportModal = async function () {
    ensureModalFields();
    const rate = clampRate(document.getElementById("taskReportProgressRate")?.value);
    const progressAt = fromLocalInput(document.getElementById("taskReportProgressAt")?.value);
    const progressNote = String(document.getElementById("taskReportProgressNote")?.value || "").trim();
    if (!progressAt) return alert("請回填這次的進度時間");
    if (progressNote.length < 4) return alert("請回報目前做到哪，至少 4 個字");
    const detail = document.getElementById("taskReportDetail");
    if (detail && String(detail.value || "").trim().length < 3) {
      detail.value = progressNote;
    }
    const proposed = document.getElementById("taskReportProposedAt");
    if (proposed && !String(proposed.value || "").trim()) {
      return alert("請填二次修正進度時間，讓主管評估可不可行");
    }
    if (typeof origSubmit === "function") await origSubmit();
    const taskId = document.getElementById("taskReportTaskId")?.value || "";
    const task = (data.tasks || []).find((x) => String(x.id) === String(taskId));
    if (!task) return;
    task.progressRate = rate;
    task.progressAt = progressAt;
    task.progressNote = progressNote;
    task.feasibility = task.feasibility || "待評估";
    task.audit = Object.assign({ completionTime: null, accuracy: null, content: null, pending: true }, task.audit || {});
    const report = (data.taskReports || []).find((x) => String(x.taskId) === String(task.id));
    if (report) {
      report.progressRate = rate;
      report.progressAt = progressAt;
      report.progressNote = progressNote;
      report.secondProgressAt = task.proposedCompleteAt || "";
      report.auditPending = true;
    }
    if (typeof save === "function") save();
    if (typeof renderTaskTable === "function") renderTaskTable();
    if (typeof renderTaskDashboard === "function") renderTaskDashboard();
  };

  const origApprove = window.approveTaskExtension;
  window.approveTaskExtension = function (id) {
    const task = (data.tasks || []).find((x) => String(x.id) === String(id));
    if (task) {
      const pick = prompt("二次修正進度時間由回報者提出。請評估可行性：\n1 可行，依此時間核准\n2 可行，但要改時間\n3 不可行，先記下再調整", "1");
      if (pick === null) return;
      const choice = String(pick).trim();
      if (choice === "3") {
        task.feasibility = "不可行";
        task.feasibilityNote = "主管評估目前提出的時間不可行";
        task.extensionStatus = "待審核";
        if (typeof logActivity === "function") logActivity("評估二次修正進度時間", `結果：不可行；員工：${task.assign || "-"}；提出時間：${task.proposedCompleteAt || "-"}`, task.name || id);
        if (typeof save === "function") save();
        if (typeof renderTaskTable === "function") renderTaskTable();
        alert("已記成不可行。員工仍需繼續回報目前進度，時間尚未核准。");
        return;
      }
      task.feasibility = choice === "2" ? "可行需調整" : "可行";
      task.feasibilityNote = choice === "2" ? "主管認為可行，但時間要再改" : "主管評估可行";
    }
    if (typeof origApprove === "function") origApprove(id);
  };

  const origFinish = window.finishTask;
  window.finishTask = function (id) {
    const task = (data.tasks || []).find((x) => String(x.id) === String(id));
    if (task) {
      task.progressRate = 100;
      task.progressAt = task.progressAt || nowLocal();
      task.progressNote = task.progressNote || "已完成";
      task.audit = Object.assign({ completionTime: null, accuracy: null, content: null, pending: true }, task.audit || {});
    }
    if (typeof origFinish === "function") origFinish(id);
  };

  const origTable = window.renderTaskTable;
  window.renderTaskTable = function () {
    if (typeof origTable === "function") origTable();
    enhanceTaskTable();
  };

  const origDash = window.renderTaskDashboard;
  window.renderTaskDashboard = function () {
    if (typeof origDash === "function") origDash();
    enhanceDashboard();
  };

  function boot() {
    ensureModalFields();
    if (typeof window.renderTaskTable === "function" && document.getElementById("taskTable")) {
      enhanceTaskTable();
    }
    if (document.getElementById("taskDashboardSummary")) enhanceDashboard();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
  window.baohuiTaskProgress = { clampRate, staffRate, progressBlock };
})();
