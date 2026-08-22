(function () {
  "use strict";

  const AUDIT_NOTE = "每次回報都要寫適當的進度時間與目前做到哪。可以延長，但系統會核對說明是否與發票／庫存資料相符。";
  const progressState = { board: null, loading: false };

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
    if (label) label.innerHTML = "何時可以完成<small class=\"text-muted d-block\">二次修正進度時間。來不及完成時必填，讓主管知道何時可以完成</small>";
    const row = proposed.closest(".row") || proposed.parentElement;
    const extra = document.createElement("div");
    extra.className = "row g-3 mt-0";
    extra.innerHTML = `
      <div class="col-md-4">
        <label class="form-label">完成綠比例（%）</label>
        <input type="number" class="form-control" id="taskReportProgressRate" min="0" max="100" step="1" value="0">
      </div>
      <div class="col-md-8">
        <label class="form-label">回填進度時間</label>
        <input type="datetime-local" class="form-control" id="taskReportProgressAt">
        <div class="form-text">這次實際做到這個進度的時間，不是截止日期。</div>
      </div>
      <div class="col-12">
        <label class="form-label">目前做到哪裡（必填）</label>
        <textarea class="form-control" id="taskReportProgressNote" rows="3" placeholder="請寫現在做到哪、還剩什麼。來不及完成時也要寫還沒好的內容。"></textarea>
      </div>
      <div class="col-12" id="taskReportLeftover"></div>
      <div class="col-12" id="taskReportProgressHistory"></div>
      <div class="col-12 small text-muted">${AUDIT_NOTE} 來不及完成時請選「來不及完成」，列出綠比例與何時可以完成。</div>
    `;
    row.parentNode.insertBefore(extra, row.nextSibling);
    const type = document.getElementById("taskReportType");
    if (type) {
      const extras = [
        ["進度回填", "進度回填"],
        ["來不及完成", "來不及完成（列出綠比例與何時可以完成）"],
      ];
      extras.forEach(([value, text]) => {
        if (![...type.options].some((o) => o.value === value)) {
          const opt = document.createElement("option");
          opt.value = value;
          opt.textContent = text;
          type.insertBefore(opt, type.firstChild);
        }
      });
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
    const leftover = leftoverText(task);
    const share = task.shareQty != null && task.shareQty !== ""
      ? `<div>等分數量：${esc(task.shareQty)}${esc(task.unit || "")}</div>`
      : "";
    return `<div class="small text-start border rounded p-2 mb-1 bg-light">
      <div><b>完成綠比例 ${rate}%</b>${progressAt ? `　回填進度時間 ${esc(progressAt)}` : ""}</div>
      ${share}
      ${note ? `<div>目前進度：${esc(note)}</div>` : `<div class="text-danger">尚未回報目前進度</div>`}
      ${leftover ? `<div>還沒好：${leftover}</div>` : ""}
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

  function leftoverText(task) {
    if (!task) return "";
    const kind = String(task.leftoverKind || "").trim();
    const items = Array.isArray(task.leftover) ? task.leftover.filter(Boolean) : [];
    const samples = items.slice(0, 8).map((v) => esc(v)).join("、");
    if (!kind && !samples) return "";
    return `${esc(kind || "剩餘內容")}${samples ? "：" + samples : ""}`;
  }

  function enhanceDashboard() {
    const summary = document.getElementById("taskDashboardSummary");
    if (!summary) return;
    let boardBox = summary.querySelector("[data-progress-board]");
    if (!boardBox) {
      boardBox = document.createElement("div");
      boardBox.className = "mb-2";
      boardBox.setAttribute("data-progress-board", "1");
      summary.insertBefore(boardBox, summary.firstChild);
    }
    boardBox.innerHTML = `<h6 class="mb-2">目前進度表（數量與還沒好的內容）</h6>${boardHtml(progressState.board)}`;
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
    renderReportLeftover(task);
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
    if (!proposedAt) return alert("來不及完成時也要填何時可以完成，讓主管評估");
    const reportType = document.getElementById("taskReportType")?.value || "";
    if (reportType === "來不及完成" && rate <= 0) {
      return alert("來不及完成時請列出目前完成綠比例");
    }
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

  function equalSplit(remaining, people) {
    const n = Math.max(1, Number(people) || 1);
    const left = Math.max(0, Number(remaining) || 0);
    const base = Math.floor(left / n);
    const extra = left % n;
    return Array.from({ length: n }, (_, i) => base + (i < extra ? 1 : 0));
  }

  function shareNote(row, shareQty, people, index) {
    const title = String((row && row.title) || "工作");
    const remaining = Number((row && row.remaining) || 0);
    const done = Number((row && row.done) || 0);
    const total = Number((row && row.total) || 0);
    const rate = Number((row && row.rate) || 0);
    const unit = String((row && row.unit) || "筆");
    const kind = String((row && row.leftoverKind) || "剩餘內容");
    const samples = Array.isArray(row && row.leftover) ? row.leftover.slice(0, 8) : [];
    const sampleText = samples.length ? samples.join("、") : "見進度表剩餘清單";
    return `【進度表等分】${title}\n目前進度：已完成 ${done}／共 ${total}${unit}（綠比例 ${rate}%）\n還沒好：${kind}，剩餘 ${remaining}${unit}\n等分：${people} 人，你是第 ${index + 1} 位，分到 ${shareQty}${unit}\n剩餘內容例：${sampleText}\n來不及完成時請在回報勾選「來不及完成」，填綠比例與何時可以完成。`;
  }

  function boardHtml(board) {
    if (!board || !Array.isArray(board.rows)) {
      return `<div class="text-muted small">${progressState.loading ? "進度表載入中…" : "尚未載入目前進度表。"}</div>`;
    }
    const rows = board.rows.map((row) => {
      const leftover = Array.isArray(row.leftover) ? row.leftover.slice(0, 8) : [];
      const leftoverText = leftover.length
        ? leftover.map((v) => esc(v)).join("、")
        : (Number(row.remaining) > 0 ? "剩餘項目請到對應作業查看完整清單" : "這項已沒有剩餘");
      return `<tr>
        <td class="text-start">${esc(row.title)}</td>
        <td>${esc(row.done)}／${esc(row.total)}${esc(row.unit || "")}</td>
        <td>
          <div class="progress" style="height:10px"><div class="progress-bar bg-success" style="width:${clampRate(row.rate)}%"></div></div>
          <div class="small">${clampRate(row.rate)}%</div>
        </td>
        <td class="${Number(row.remaining) > 0 ? "text-danger" : "text-success"}">${esc(row.remaining)}${esc(row.unit || "")}</td>
        <td class="text-start small"><b>${esc(row.leftoverKind || "無")}</b><div>${leftoverText}</div></td>
      </tr>`;
    }).join("");
    return `<div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0">
        <thead class="table-light"><tr><th>進度表項目</th><th>數量（完成／全部）</th><th>綠比例</th><th>剩餘數量</th><th>還沒好的內容</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
    </div>
    <div class="small text-muted mt-1">剩餘合計 ${esc(board.remainingTotal)}。指派時可依剩餘數量等分；來不及完成請回報綠比例與何時可以完成。</div>`;
  }

  function selectedBoardRow() {
    const id = document.getElementById("taskBoardRow")?.value || "";
    const rows = progressState.board && Array.isArray(progressState.board.rows) ? progressState.board.rows : [];
    return rows.find((row) => String(row.id) === String(id)) || rows[0] || null;
  }

  function selectedMany() {
    const sel = document.getElementById("taskAssignMany");
    if (!sel) return [];
    return [...sel.selectedOptions].map((o) => String(o.value || "").trim()).filter(Boolean);
  }

  function syncAssignMany() {
    const src = document.getElementById("taskAssign");
    const dest = document.getElementById("taskAssignMany");
    if (!src || !dest) return;
    const keep = new Set(selectedMany());
    dest.innerHTML = [...src.options].map((o) => `<option value="${esc(o.value)}"${keep.has(o.value) ? " selected" : ""}>${esc(o.textContent)}</option>`).join("");
  }

  function fillBoardSelect() {
    const sel = document.getElementById("taskBoardRow");
    if (!sel) return;
    const rows = progressState.board && Array.isArray(progressState.board.rows) ? progressState.board.rows : [];
    const keep = sel.value;
    sel.innerHTML = rows.map((row) => {
      const label = `${row.title}（完成 ${row.done}／${row.total}${row.unit || ""}，剩餘 ${row.remaining}${row.unit || ""}，還沒好：${row.leftoverKind || "無"}）`;
      return `<option value="${esc(row.id)}">${esc(label)}</option>`;
    }).join("");
    if (keep && [...sel.options].some((o) => o.value === keep)) sel.value = keep;
    updateSplitPreview();
  }

  function updateSplitPreview() {
    const box = document.getElementById("taskEqualSplitPreview");
    if (!box) return;
    const row = selectedBoardRow();
    const names = selectedMany();
    if (!row) {
      box.innerHTML = `<span class="text-muted">請先載入進度表。</span>`;
      return;
    }
    if (!names.length) {
      box.innerHTML = `<span class="text-muted">目前進度：已完成 ${esc(row.done)}／共 ${esc(row.total)}${esc(row.unit || "")}，綠比例 ${clampRate(row.rate)}%。還沒好：${esc(row.leftoverKind || "無")}，剩餘 ${esc(row.remaining)}${esc(row.unit || "")}。請複選人員後等分。</span>`;
      return;
    }
    const shares = equalSplit(row.remaining, names.length);
    const leftover = Array.isArray(row.leftover) && row.leftover.length ? `；還沒好內容例：${row.leftover.slice(0, 6).map((v) => esc(v)).join("、")}` : "";
    box.innerHTML = `<b>等分預覽</b>　目前 ${esc(row.done)}／${esc(row.total)}${esc(row.unit || "")}（綠比例 ${clampRate(row.rate)}%），剩餘 ${esc(row.remaining)}${esc(row.unit || "")}（${esc(row.leftoverKind || "無")}）${leftover}<div>${names.map((name, i) => `${esc(name)}：${shares[i]}${esc(row.unit || "")}`).join("　")}</div>`;
  }

  function renderAllBoards() {
    document.querySelectorAll("[data-progress-board-table]").forEach((el) => {
      el.innerHTML = boardHtml(progressState.board);
    });
    const dash = document.querySelector("#taskDashboardSummary [data-progress-board]");
    if (dash) {
      dash.innerHTML = `<h6 class="mb-2">目前進度表（數量與還沒好的內容）</h6>${boardHtml(progressState.board)}`;
    }
    fillBoardSelect();
  }

  function ensureProgressBoardUi() {
    const page = document.getElementById("page-task");
    const addCard = document.getElementById("taskAddCard");
    if (page && addCard && !document.getElementById("taskProgressBoardCard")) {
      const card = document.createElement("div");
      card.className = "form-card mb-3";
      card.id = "taskProgressBoardCard";
      card.innerHTML = `
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <h5 class="mb-0">目前進度表（數量與還沒好的內容）</h5>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="taskProgressBoardRefresh">重新載入進度表</button>
        </div>
        <div data-progress-board-table>${boardHtml(progressState.board)}</div>
      `;
      addCard.parentNode.insertBefore(card, addCard);
      card.querySelector("#taskProgressBoardRefresh")?.addEventListener("click", () => loadProgressBoard(true));
    }
    if (addCard && !document.getElementById("taskEqualSplitBox")) {
      const box = document.createElement("div");
      box.className = "border rounded p-3 mt-3";
      box.id = "taskEqualSplitBox";
      box.style.borderColor = "#0f766e";
      box.innerHTML = `
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="taskEqualSplitOn">
          <label class="form-check-label" for="taskEqualSplitOn">依目前進度表等分剩餘數量（可多人；會帶入完成／剩餘數量與還沒好的內容）</label>
        </div>
        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label">要等分的進度表項目</label>
            <select class="form-select" id="taskBoardRow"></select>
          </div>
          <div class="col-md-7">
            <label class="form-label">分給哪些人（可複選）</label>
            <select class="form-select" id="taskAssignMany" multiple size="5"></select>
          </div>
          <div class="col-12 small" id="taskEqualSplitPreview"></div>
        </div>
      `;
      addCard.appendChild(box);
      box.querySelector("#taskBoardRow")?.addEventListener("change", updateSplitPreview);
      box.querySelector("#taskAssignMany")?.addEventListener("change", updateSplitPreview);
      box.querySelector("#taskEqualSplitOn")?.addEventListener("change", updateSplitPreview);
    }
    syncAssignMany();
    fillBoardSelect();
  }

  function renderReportLeftover(task) {
    const box = document.getElementById("taskReportLeftover");
    if (!box) return;
    const row = (progressState.board && progressState.board.rows || []).find((r) => String(r.id) === String(task && task.boardId || "")) || null;
    const leftover = leftoverText(task) || (row ? leftoverText({ leftoverKind: row.leftoverKind, leftover: row.leftover }) : "");
    const qty = task && task.shareQty != null ? `你這份剩餘 ${esc(task.shareQty)}${esc(task.unit || "")}。` : "";
    const boardLine = row
      ? `進度表目前 ${esc(row.done)}／${esc(row.total)}${esc(row.unit || "")}，綠比例 ${clampRate(row.rate)}%，還沒好 ${esc(row.remaining)}${esc(row.unit || "")}（${esc(row.leftoverKind || "無")}）。`
      : "";
    box.innerHTML = `<div class="small border rounded p-2 bg-light"><b>目前進度表還沒好的內容</b><div>${boardLine} ${qty}</div><div>${leftover || "沒有對應到進度表剩餘清單時，請自己寫還剩什麼。"}</div><div class="text-muted">來不及完成請選「來不及完成」，填綠比例與何時可以完成。</div></div>`;
  }

  async function loadProgressBoard(force) {
    if (progressState.loading && !force) return;
    progressState.loading = true;
    renderAllBoards();
    try {
      const res = await fetch("task-progress-audit.php?action=snapshot", { credentials: "same-origin" });
      const json = await res.json();
      if (json && json.ok && json.evidence && json.evidence.board) {
        progressState.board = json.evidence.board;
      }
    } catch (err) {
      if (!progressState.board) progressState.board = { remainingTotal: 0, rows: [] };
    }
    progressState.loading = false;
    ensureProgressBoardUi();
    renderAllBoards();
    if (document.getElementById("taskDashboardSummary")) enhanceDashboard();
  }

  function formField(id) {
    return document.getElementById(id);
  }

  function injectSingleBoardNote() {
    const row = selectedBoardRow();
    const desc = formField("taskDesc");
    if (!row || !desc) return;
    const current = String(desc.value || "");
    if (current.includes("【進度表等分】") || current.includes("【進度表】")) return;
    desc.value = shareNote(row, Number(row.remaining || 0), 1, 0) + (current ? "\n" + current : "");
    const name = formField("taskName");
    if (name && !String(name.value || "").trim()) name.value = row.title;
  }

  function attachBoardMeta(task, row, extra) {
    if (!task || !row) return;
    task.boardId = row.id;
    task.shareQty = extra && extra.shareQty != null ? extra.shareQty : row.remaining;
    task.unit = row.unit || "";
    task.leftoverKind = row.leftoverKind || "";
    task.leftover = Array.isArray(row.leftover) ? row.leftover.slice(0, 12) : [];
    task.boardDone = row.done;
    task.boardTotal = row.total;
    task.boardRate = row.rate;
    if (!task.progressNote) task.progressNote = shareNote(row, task.shareQty, extra && extra.people || 1, extra && extra.index || 0);
  }

  function addEqualSplitTasks() {
    if (typeof isAdmin !== "undefined" && !isAdmin) return alert("僅管理員可新增任務");
    const row = selectedBoardRow();
    const names = selectedMany();
    if (!row) return alert("請先載入進度表並選擇要等分的項目");
    if (!names.length) return alert("請複選要等分的人員");
    if (Number(row.remaining) <= 0) return alert("這項目前沒有剩餘數量可分");
    if (!window.data) window.data = {};
    if (!Array.isArray(data.tasks)) data.tasks = [];
    const shares = equalSplit(row.remaining, names.length);
    const createdAt = formField("taskCreatedAt")?.value || new Date().toISOString().slice(0, 10);
    const dueDate = formField("taskDueDate")?.value || "";
    const reportDeadline = formField("taskReportDeadline")?.value || "";
    const status = formField("taskStatus")?.value || "待處理";
    const extraDesc = String(formField("taskDesc")?.value || "").trim();
    const customName = String(formField("taskName")?.value || "").trim();
    const autoDeductPoint = typeof taskAutoDeductPointValue === "function"
      ? taskAutoDeductPointValue()
      : Number(formField("taskAutoDeductPoint")?.value || 1);
    const baseId = Date.now();
    names.forEach((assign, i) => {
      const shareQty = shares[i];
      const note = shareNote(row, shareQty, names.length, i);
      const name = customName || `${row.title}（等分 ${i + 1}/${names.length}）`;
      const desc = extraDesc && !extraDesc.includes("【進度表等分】") ? `${note}\n${extraDesc}` : note;
      const task = {
        id: baseId + i,
        name,
        assign,
        status,
        desc,
        createdAt,
        dueDate,
        reportDeadline,
        autoDeductPoint,
        completedAt: "",
        progressRate: 0,
        progressNote: note,
      };
      attachBoardMeta(task, row, { shareQty, people: names.length, index: i });
      data.tasks.push(task);
      if (typeof logActivity === "function") {
        logActivity("新增工作任務", `進度表等分：${assign}；剩餘 ${row.remaining}${row.unit || ""}；分到 ${shareQty}${row.unit || ""}；還沒好：${row.leftoverKind || ""}`, name);
      }
      if (typeof sendCodexLineNotify === "function") {
        sendCodexLineNotify({
          type: "task_created",
          title: assign,
          summary: `${name}｜綠比例 ${row.rate}%｜分到 ${shareQty}${row.unit || ""}`,
          impact: `還沒好：${row.leftoverKind || ""}；剩餘 ${row.remaining}${row.unit || ""}；截止：${dueDate || "-"}`,
        });
      }
    });
    if (typeof save === "function") save();
    if (typeof resetTaskForm === "function") resetTaskForm();
    if (typeof renderTaskTable === "function") renderTaskTable();
    if (typeof renderTaskCompletedArchive === "function") renderTaskCompletedArchive();
    if (typeof refreshDashboard === "function") refreshDashboard();
    alert(`已依目前進度表等分 ${names.length} 人，剩餘 ${row.remaining}${row.unit || ""}（還沒好：${row.leftoverKind || "無"}）。`);
  }

  const origFillAssign = window.fillTaskAssign;
  window.fillTaskAssign = function () {
    if (typeof origFillAssign === "function") origFillAssign();
    syncAssignMany();
  };

  const origAddTask = window.addTask;
  window.addTask = function () {
    const editing = typeof currentEditingTaskId === "function" ? currentEditingTaskId() : 0;
    const splitOn = !!document.getElementById("taskEqualSplitOn")?.checked;
    if (!editing && splitOn) {
      return addEqualSplitTasks();
    }
    injectSingleBoardNote();
    const beforeIds = new Set((window.data && Array.isArray(data.tasks) ? data.tasks : []).map((t) => String(t.id)));
    const result = typeof origAddTask === "function" ? origAddTask() : undefined;
    const created = (window.data && Array.isArray(data.tasks) ? data.tasks : []).find((t) => !beforeIds.has(String(t.id)));
    attachBoardMeta(created, selectedBoardRow(), {
      shareQty: selectedBoardRow() ? Number(selectedBoardRow().remaining || 0) : "",
      people: 1,
      index: 0,
    });
    if (created && typeof save === "function") save();
    if (typeof renderTaskTable === "function") renderTaskTable();
    return result;
  };

  function boot() {
    ensureModalFields();
    ensureProgressBoardUi();
    if (typeof window.renderTaskTable === "function" && document.getElementById("taskTable")) {
      enhanceTaskTable();
    }
    if (document.getElementById("taskDashboardSummary")) enhanceDashboard();
    loadProgressBoard();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
  window.baohuiTaskProgress = {
    clampRate,
    staffRate,
    progressBlock,
    requestAudit,
    equalSplit,
    shareNote,
    loadProgressBoard,
  };
})();
