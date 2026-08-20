(function () {
  "use strict";

  const AUDIT_NOTE = "每次回報都要寫適當的進度時間與目前做到哪。可以延長，但系統會核對說明是否與發票／庫存資料相符。";

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

  function parseTs(value) {
    const raw = String(value || "").trim().replace("T", " ");
    if (!raw) return null;
    const d = new Date(raw.replace(/-/g, "/"));
    const t = d.getTime();
    return Number.isFinite(t) ? t : null;
  }

  function esc(value) {
    return String(value || "").replace(/[&<>"']/g, (ch) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    }[ch]));
  }

  function verdictClass(verdict) {
    if (verdict === "核實") return "text-success";
    if (verdict === "部分核實") return "text-warning";
    if (verdict === "與系統不符" || verdict === "時間不合理") return "text-danger";
    return "text-muted";
  }

  function sourceText(task) {
    return [task && task.progressNote, task && task.incompleteReason, task && task.proposedCompleteAt, task && task.approvedCompleteAt].map((v) => String(v || "")).join("|");
  }

  function ensureModalFields() {
    const proposed = document.getElementById("taskReportProposedAt");
    if (!proposed || document.getElementById("taskReportProgressRate")) return;
    const label = proposed.closest(".col-md-8")?.querySelector("label");
    if (label) label.innerHTML = "二次修正進度時間<small class=\"text-muted d-block\">可以延長，但要提出適當、做得到的進度時間，主管評估後才核准</small>";
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
        <textarea class="form-control" id="taskReportProgressNote" rows="3" placeholder="請寫現在做到哪、還剩什麼。例如：電子發票已全印，大陸品庫存還在建檔。"></textarea>
      </div>
      <div class="col-12" id="taskReportProgressHistory"></div>
      <div class="col-12 small text-muted">${AUDIT_NOTE}</div>
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

  function renderHistory(task) {
    const box = document.getElementById("taskReportProgressHistory");
    if (!box) return;
    const logs = Array.isArray(task && task.progressLogs) ? task.progressLogs : [];
    if (!logs.length) {
      box.innerHTML = `<div class="small text-muted border rounded p-2">尚無進度核對紀錄。第一次回報會開始追蹤。</div>`;
      return;
    }
    const rows = logs.slice(-6).reverse().map((log, idx) => {
      const n = logs.length - idx;
      const verdict = (log.audit && log.audit.verdict) || "待核實";
      return `<div class="border-bottom py-1">第 ${n} 次　${esc(log.progressAt || log.at || "")}　完成率 ${clampRate(log.progressRate)}%　提出 ${esc(log.proposedCompleteAt || "")}<div>做到哪：${esc(log.progressNote || "")}</div><div class="${verdictClass(verdict)}">核實：${esc(verdict)}${log.audit && log.audit.summary ? "／" + esc(log.audit.summary) : ""}</div></div>`;
    }).join("");
    box.innerHTML = `<div class="small border rounded p-2 bg-light"><b>進度核對紀錄</b>${rows}</div>`;
  }

  function staffRate(name) {
    const tasks = (window.data && Array.isArray(data.tasks) ? data.tasks : []).filter((t) => String(t.assign || "") === String(name || ""));
    if (!tasks.length) return { total: 0, done: 0, rate: 0, latest: 0, mismatch: 0 };
    const done = tasks.filter((t) => t.status === "完成").length;
    const latest = tasks.reduce((max, t) => Math.max(max, clampRate(t.progressRate)), 0);
    const mismatch = tasks.filter((t) => t.audit && (t.audit.verdict === "與系統不符" || t.audit.verdict === "時間不合理")).length;
    return { total: tasks.length, done, rate: Math.round(done / tasks.length * 100), latest, mismatch };
  }

  function progressBlock(task) {
    if (!task) return "";
    const rate = task.status === "完成" ? 100 : clampRate(task.progressRate);
    const progressAt = task.progressAt || "";
    const note = task.progressNote || task.incompleteReason || "";
    const second = task.approvedCompleteAt
      ? `核准第二時間：${task.approvedCompleteAt}`
      : (task.proposedCompleteAt ? `二次修正進度時間：${task.proposedCompleteAt}（${task.extensionStatus || "待評估可行性"}）` : "");
    const feas = task.feasibility ? `可行性：${task.feasibility}${task.feasibilityNote ? "／" + task.feasibilityNote : ""}` : "";
    const audit = task.audit || {};
    const verdict = audit.verdict || (audit.pending ? "待核實" : "");
    const logs = Array.isArray(task.progressLogs) ? task.progressLogs : [];
    const logLine = logs.length ? `已核對 ${logs.length} 次進度` : "尚未建立進度核對";
    const checks = Array.isArray(audit.checks) ? audit.checks.filter((c) => c.status === "mismatch" || c.status === "caution" || c.status === "blocking").slice(0, 3) : [];
    return `<div class="small text-start border rounded p-2 mb-1 bg-light">
      <div><b>完成率 ${rate}%</b>${progressAt ? `　回填進度時間 ${esc(progressAt)}` : ""}</div>
      ${note ? `<div>目前進度：${esc(note)}</div>` : `<div class="text-danger">尚未回報目前進度</div>`}
      ${second ? `<div>${esc(second)}</div>` : ""}
      ${feas ? `<div>${esc(feas)}</div>` : ""}
      <div class="${verdictClass(verdict)}"><b>AI 核實：${esc(verdict || "尚未核對")}</b>${audit.summary ? "　" + esc(audit.summary) : ""}</div>
      ${checks.map((c) => `<div class="${verdictClass(c.status === "match" ? "核實" : c.status === "caution" ? "部分核實" : "與系統不符")}">· ${esc(c.label)}：${esc(c.detail)}</div>`).join("")}
      <div class="text-muted">${esc(logLine)}</div>
    </div>`;
  }

  function enhanceTaskTable() {
    const rows = document.querySelectorAll("#taskTable tbody tr");
    rows.forEach((tr) => {
      const name = (tr.children[0]?.textContent || "").trim();
      const assign = (tr.children[1]?.textContent || "").trim();
      if (!name || !assign) return;
      const task = (data.tasks || []).find((t) => String(t.name || "") === name && String(t.assign || "") === assign);
      if (!task) return;
      const td = tr.children[7];
      if (!td) return;
      let wrap = tr.querySelector("[data-progress-block]");
      if (!wrap) {
        wrap = document.createElement("div");
        wrap.setAttribute("data-progress-block", "1");
        td.insertBefore(wrap, td.firstChild);
      }
      wrap.innerHTML = progressBlock(task);
    });
    if (!window.__baohuiTaskAuditing) queueBatchAudit();
  }

  function enhanceDashboard() {
    const summary = document.getElementById("taskDashboardSummary");
    if (!summary) return;
    let box = summary.querySelector("[data-staff-rates]");
    const names = Array.from(new Set((data.tasks || []).map((t) => String(t.assign || "").trim()).filter(Boolean)));
    if (!names.length) return;
    const focus = names.filter((n) => /曾憲|曾麒/.test(n));
    const ordered = focus.concat(names.filter((n) => !focus.includes(n)));
    const html = ordered.map((name) => {
      const s = staffRate(name);
      const warn = s.rate < 100 && s.total ? "text-danger" : "text-success";
      const mismatch = s.mismatch ? `　<span class="text-danger">核實不符 ${s.mismatch}</span>` : "";
      return `<span class="me-3 ${warn}"><b>${name}</b> 完成率 ${s.rate}%（${s.done}/${s.total}）｜目前進度 ${s.latest}%${mismatch}</span>`;
    }).join("");
    if (!box) {
      box = document.createElement("div");
      box.className = "mt-2 small";
      box.setAttribute("data-staff-rates", "1");
      summary.appendChild(box);
    }
    box.innerHTML = `<div class="border rounded p-2 bg-white">人員完成率：${html}<div class="text-muted mt-1">${AUDIT_NOTE}</div></div>`;
  }

  let auditTimer = 0;
  function queueBatchAudit() {
    if (auditTimer) return;
    auditTimer = window.setTimeout(runBatchAudit, 200);
  }

  async function runBatchAudit() {
    auditTimer = 0;
    const tasks = (window.data && Array.isArray(data.tasks) ? data.tasks : []).filter((t) => t && t.status !== "完成" && (t.progressNote || t.incompleteReason));
    const need = tasks.filter((t) => sourceText(t) && t.auditSourceText !== sourceText(t));
    if (!need.length) return;
    try {
      const res = await fetch("task-progress-audit.php?action=batch", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "batch",
          tasks: need.map((t) => ({
            id: t.id,
            name: t.name,
            assign: t.assign,
            detail: t.detail,
            incompleteReason: t.incompleteReason,
            progressNote: t.progressNote || t.incompleteReason,
            progressRate: t.progressRate,
            progressAt: t.progressAt,
            proposedCompleteAt: t.proposedCompleteAt || t.approvedCompleteAt,
            approvedCompleteAt: t.approvedCompleteAt,
            progressLogs: t.progressLogs || [],
          })),
        }),
      });
      const json = await res.json();
      if (!json || !json.ok || !json.audits) return;
      need.forEach((t) => {
        if (!json.audits[String(t.id)]) return;
        t.audit = json.audits[String(t.id)];
        t.auditSourceText = sourceText(t);
      });
      window.__baohuiTaskAuditing = true;
      enhanceTaskTable();
      enhanceDashboard();
      window.__baohuiTaskAuditing = false;
    } catch (err) {
      need.forEach((t) => {
        t.audit = Object.assign({ verdict: "無法核實", summary: "核實服務暫時無法連線", pending: true }, t.audit || {});
        t.auditSourceText = sourceText(t);
      });
    }
  }

  async function requestAudit(task, payload, previousLogs) {
    const res = await fetch("task-progress-audit.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ task, payload, previousLogs }),
    });
    const json = await res.json().catch(() => ({}));
    if (json && json.blocking) {
      const err = new Error(json.error || json.audit && json.audit.summary || "請補齊進度時間與目前做到哪");
      err.blocking = true;
      err.audit = json.audit;
      throw err;
    }
    if (!res.ok || !json.ok) {
      return json.audit || { verdict: "無法核實", summary: json.error || "核實服務暫時無法連線，這次先收下回報。", pending: true, engine: "offline" };
    }
    return json.audit;
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
    if (note) note.value = (task && (task.progressNote || task.incompleteReason)) || "";
    const type = document.getElementById("taskReportType");
    if (type && !task?.lastReportType) type.value = "進度回填";
    renderHistory(task);
  };

  const origSubmit = window.submitTaskReportModal;
  window.submitTaskReportModal = async function () {
    ensureModalFields();
    const rate = clampRate(document.getElementById("taskReportProgressRate")?.value);
    const progressAt = fromLocalInput(document.getElementById("taskReportProgressAt")?.value);
    const progressNote = String(document.getElementById("taskReportProgressNote")?.value || "").trim();
    const proposed = document.getElementById("taskReportProposedAt");
    const proposedAt = fromLocalInput(proposed && proposed.value);
    if (!progressAt) return alert("請回填這次的進度時間");
    if (progressNote.length < 4) return alert("請回報目前做到哪，至少 4 個字");
    if (!proposedAt) return alert("可以延長，但請填二次修正進度時間，讓主管評估可不可行");
    const progressTs = parseTs(progressAt);
    const proposedTs = parseTs(proposedAt);
    if (progressTs && proposedTs && proposedTs <= progressTs) {
      return alert("二次修正進度時間必須晚於回填進度時間，請提出適當、還做得到的時間");
    }
    const detail = document.getElementById("taskReportDetail");
    if (detail && String(detail.value || "").trim().length < 3) {
      detail.value = progressNote;
    }
    const taskId = document.getElementById("taskReportTaskId")?.value || "";
    const task = (data.tasks || []).find((x) => String(x.id) === String(taskId));
    if (!task) {
      if (typeof origSubmit === "function") return origSubmit();
      return;
    }
    const previousLogs = Array.isArray(task.progressLogs) ? task.progressLogs.slice() : [];
    let audit;
    try {
      audit = await requestAudit(task, {
        progressRate: rate,
        progressAt,
        progressNote,
        proposedCompleteAt: proposedAt,
        incompleteReason: progressNote,
      }, previousLogs);
    } catch (err) {
      if (err && err.blocking) return alert(err.message);
      audit = { verdict: "無法核實", summary: (err && err.message) || "核實服務暫時無法連線，這次先收下回報。", pending: true, engine: "offline" };
    }
    if (audit && (audit.verdict === "與系統不符" || audit.verdict === "時間不合理")) {
      const ok = confirm(`系統核實結果：${audit.verdict}\n${audit.summary || ""}\n\n仍要送出這次進度？主管會看到這次核實結果。`);
      if (!ok) return;
    } else if (audit && audit.verdict && audit.verdict !== "無法核實") {
      alert(`系統核實結果：${audit.verdict}\n${audit.summary || ""}`);
    }
    if (typeof origSubmit === "function") await origSubmit();
    const saved = (data.tasks || []).find((x) => String(x.id) === String(taskId)) || task;
    saved.progressRate = rate;
    saved.progressAt = progressAt;
    saved.progressNote = progressNote;
    saved.incompleteReason = progressNote;
    saved.feasibility = saved.feasibility || "待評估";
    saved.audit = Object.assign({ pending: false }, audit || {});
    saved.auditSourceText = sourceText(saved);
    const log = {
      at: nowLocal(),
      progressRate: rate,
      progressAt,
      proposedCompleteAt: saved.proposedCompleteAt || proposedAt,
      progressNote,
      audit: saved.audit,
    };
    saved.progressLogs = previousLogs.concat([log]);
    const report = (data.taskReports || []).find((x) => String(x.taskId) === String(saved.id));
    if (report) {
      report.progressRate = rate;
      report.progressAt = progressAt;
      report.progressNote = progressNote;
      report.secondProgressAt = saved.proposedCompleteAt || proposedAt;
      report.audit = saved.audit;
      report.auditPending = false;
    }
    if (typeof save === "function") save();
    if (typeof renderTaskTable === "function") renderTaskTable();
    if (typeof renderTaskDashboard === "function") renderTaskDashboard();
  };

  const origApprove = window.approveTaskExtension;
  window.approveTaskExtension = function (id) {
    const task = (data.tasks || []).find((x) => String(x.id) === String(id));
    if (task) {
      const auditLine = task.audit && task.audit.verdict ? `\nAI 核實：${task.audit.verdict}／${task.audit.summary || ""}` : "";
      const pick = prompt(`二次修正進度時間由回報者提出。請評估可行性：\n1 可行，依此時間核准\n2 可行，但要改時間\n3 不可行，先記下再調整${auditLine}`, "1");
      if (pick === null) return;
      const choice = String(pick).trim();
      if (choice === "3") {
        task.feasibility = "不可行";
        task.feasibilityNote = "主管評估目前提出的時間不可行";
        task.extensionStatus = "待審核";
        if (typeof logActivity === "function") logActivity("評估二次修正進度時間", `結果：不可行；員工：${task.assign || "-"}；提出時間：${task.proposedCompleteAt || "-"}；核實：${(task.audit && task.audit.verdict) || "-"}`, task.name || id);
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
  window.baohuiTaskProgress = { clampRate, staffRate, progressBlock, requestAudit };
})();
