const PAGES = [
  { id: "dashboard", label: "控制台", group: "營運" },
  { id: "externalReport", label: "整體數據統計表", group: "營運" },
  { id: "faultSetting", label: "維修故障選單設定", group: "維修" },
  { id: "repairOrder", label: "維修工單管理", group: "維修" },
  { id: "recycleOrder", label: "回收工單管理", group: "維修" },
  { id: "member", label: "會員管理", group: "客戶" },
  { id: "hr", label: "員工管理", group: "人資" },
  { id: "leaveManage", label: "請假管理", group: "人資" },
  { id: "employeeSalary", label: "我的薪資月報", group: "人資" },
  { id: "task", label: "工作任務", group: "營運" },
  { id: "adminMemo", label: "備忘錄與每月事項", group: "行政" },
  { id: "codexReport", label: "CODEX 回報", group: "行政" },
  { id: "rma", label: "原廠送修管理", group: "維修" },
  { id: "punishSystem", label: "獎懲制度", group: "人資" },
  { id: "salaryReports", label: "薪資報表", group: "人資" },
  { id: "electronicInvoices", label: "電子發票記帳", group: "財務" },
  { id: "customsDuty", label: "關稅系統 / 關稅單號", group: "財務" },
  { id: "hardwareMarket", label: "硬體行情", group: "採購" },
  { id: "cryptoMarket", label: "虛擬貨幣行情預測", group: "採購" },
  { id: "quotes", label: "報價管理", group: "營運" },
  { id: "passwordManager", label: "密碼管理備忘錄", group: "行政" },
  { id: "permission", label: "權限設定", group: "行政" }
];

const state = {
  data: null,
  updatedAt: "",
  user: "",
  isAdmin: false,
  page: "dashboard"
};

function esc(value) {
  return String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}

async function api(action, payload, method) {
  const opts = { method: method || (payload ? "POST" : "GET"), credentials: "same-origin", cache: "no-store", headers: {} };
  if (payload && !(payload instanceof FormData)) {
    opts.headers["Content-Type"] = "application/json;charset=UTF-8";
    opts.body = JSON.stringify(payload);
  } else if (payload instanceof FormData) {
    opts.method = "POST";
    opts.body = payload;
  }
  const res = await fetch(`/api.php?action=${encodeURIComponent(action)}&t=${Date.now()}`, opts);
  let data = {};
  try { data = await res.json(); } catch {}
  if (res.status === 409) throw Object.assign(new Error(data.error || "資料衝突"), { conflict: true, data });
  if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

async function load() {
  const payload = await api("load");
  state.data = payload.data;
  state.updatedAt = payload.updated_at;
  return payload.data;
}

async function save() {
  const payload = await api("save", { data: state.data, updated_at: state.updatedAt });
  state.updatedAt = payload.updated_at;
  return true;
}

function canSee(pageId) {
  if (state.isAdmin) return true;
  const list = (state.data?.permissions || {})[state.user] || [];
  const defaults = ["dashboard", "employeeSalary", "task", "repairOrder", "recycleOrder"];
  return list.includes(pageId) || defaults.includes(pageId);
}

function staffOptions(selected = "") {
  return (state.data.employees || []).map((e) => `<option ${e.name === selected ? "selected" : ""}>${esc(e.name)}</option>`).join("");
}

function statusBadge(text) {
  const done = /完成|已完修|核准/.test(text || "");
  const doing = /中|派工|處理/.test(text || "");
  const cls = done ? "st-done" : doing ? "st-doing" : "st-pending";
  return `<span class="badge ${cls}">${esc(text || "未指定")}</span>`;
}

async function login() {
  const button = document.querySelector("#loginPage .btn-primary");
  button.disabled = true;
  try {
    await api("login", { user: document.getElementById("uid").value.trim(), password: document.getElementById("pwd").value });
    location.reload();
  } catch (e) {
    showMsg(document.getElementById("loginMsg"), e.message, false);
    button.disabled = false;
  }
}

async function logout() {
  try { await api("logout", {}, "POST"); } catch {}
  location.href = "/admin";
}

function renderNav() {
  const nav = document.getElementById("sidebarNav");
  let html = "";
  let group = "";
  PAGES.filter((p) => canSee(p.id)).forEach((p) => {
    if (p.group !== group) {
      group = p.group;
      html += `<div class="group-label">${esc(group)}</div>`;
    }
    html += `<a class="nav-link ${state.page === p.id ? "active" : ""}" href="#${p.id}">${esc(p.label)}</a>`;
  });
  nav.innerHTML = html;
}

function goPage(id) {
  if (!PAGES.some((p) => p.id === id) || !canSee(id)) id = "dashboard";
  state.page = id;
  location.hash = id;
  document.body.classList.remove("menu-open");
  renderNav();
  document.getElementById("topTitle").textContent = PAGES.find((p) => p.id === id)?.label || "寶輝總部管理系統";
  document.getElementById("view").innerHTML = renderPage(id);
  bindPage(id);
}

function renderPage(id) {
  const map = {
    dashboard: renderDashboard,
    externalReport: renderExternal,
    faultSetting: renderFaults,
    repairOrder: renderRepairs,
    recycleOrder: renderRecycles,
    member: renderMembers,
    hr: renderHr,
    leaveManage: renderLeaves,
    employeeSalary: renderMySalary,
    task: renderTasks,
    adminMemo: renderMemos,
    codexReport: renderCodex,
    rma: renderRma,
    punishSystem: renderPunish,
    salaryReports: renderSalaryReports,
    electronicInvoices: renderInvoices,
    customsDuty: renderCustoms,
    hardwareMarket: renderHardware,
    cryptoMarket: renderCrypto,
    quotes: renderQuotes,
    passwordManager: renderPasswords,
    permission: renderPermission
  };
  return (map[id] || renderDashboard)();
}

function renderDashboard() {
  const d = state.data;
  const pendingRepair = d.workOrders.filter((o) => o.status !== "已完修").length;
  const pendingRecycle = d.recycles.filter((o) => o.status !== "已完成" && o.status !== "完成").length;
  const pendingLeave = d.leaves.filter((o) => !o.status || o.status === "待審核").length;
  const pendingTask = d.tasks.filter((o) => o.status !== "已完成").length;
  const logs = (d.activityLogs || []).slice(0, 8).map((l) => `<tr><td>${esc(l.at)}</td><td>${esc(l.user)}</td><td>${esc(l.action)}</td><td>${esc(l.detail)}</td></tr>`).join("") || `<tr><td colspan="4" class="muted">尚無紀錄</td></tr>`;
  return `
    <div class="kpi">
      <div class="card">待維修工單<b>${pendingRepair}</b></div>
      <div class="card">待回收工單<b>${pendingRecycle}</b></div>
      <div class="card">待審核請假<b>${pendingLeave}</b></div>
      <div class="card">進行中任務<b>${pendingTask}</b></div>
      <div class="card">會員<b>${d.members.length}</b></div>
      <div class="card">員工<b>${d.employees.length}</b></div>
    </div>
    <div class="form-card">
      <h3>最近活動</h3>
      <div class="table-wrap"><table><thead><tr><th>時間</th><th>人員</th><th>動作</th><th>內容</th></tr></thead><tbody>${logs}</tbody></table></div>
    </div>`;
}

function renderExternal() {
  const d = state.data;
  return `<div class="form-card"><h3>整體數據統計表</h3>
    <p>維修工單 ${d.workOrders.length} 筆｜回收 ${d.recycles.length} 筆｜專案 ${d.projects.length} 筆｜請假 ${d.leaves.length} 筆｜任務 ${d.tasks.length} 筆｜發票 ${d.invoices.length} 筆</p>
    <p class="muted">此頁彙總伺服器共用資料，與線上報修／回收申請即時同步。</p></div>`;
}

function simpleTable(headers, rows) {
  return `<div class="table-wrap"><table><thead><tr>${headers.map((h) => `<th>${h}</th>`).join("")}</tr></thead><tbody>${rows || `<tr><td colspan="${headers.length}" class="muted">尚無資料</td></tr>`}</tbody></table></div>`;
}

function renderFaults() {
  const rows = state.data.faultList.map((f) => `<tr><td>${esc(f.name)}</td><td>${esc(f.price)}</td><td><button class="btn btn-danger btn-sm" data-del-fault="${f.id}">刪除</button></td></tr>`).join("");
  return `<div class="form-card"><h3>新增故障選項</h3>
    <div class="row"><div><label>名稱</label><input id="faultName"></div><div><label>參考價</label><input id="faultPrice" type="number" value="0"></div></div>
    <button class="btn btn-primary" style="margin-top:12px" data-add-fault>新增</button></div>
    <div class="form-card"><h3>故障選單</h3>${simpleTable(["名稱","參考價","操作"], rows)}</div>`;
}

function orderForm(prefix, extra) {
  return `<div class="form-card"><h3>新增</h3><div class="row">
    <div><label>姓名</label><input id="${prefix}_name"></div>
    <div><label>手機</label><input id="${prefix}_phone"></div>
    <div><label>縣市</label><select id="${prefix}_county"></select></div>
    <div><label>鄉鎮</label><select id="${prefix}_district"></select></div>
    <div class="full"><label>地址</label><input id="${prefix}_addr"></div>
    <div><label>指派</label><select id="${prefix}_assign"><option value="">未指派</option>${staffOptions()}</select></div>
    ${extra || ""}
    <div class="full"><label>備註</label><textarea id="${prefix}_memo"></textarea></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add="${prefix}">新增工單</button></div>`;
}

function renderRepairs() {
  const rows = state.data.workOrders.map((o) => `<tr>
    <td>${esc(o.date)}</td><td>${esc(o.name)}<br><span class="muted">${esc(o.phone)}</span></td>
    <td>${esc(o.fullAddr || o.addr)}</td>
    <td><select data-assign-repair="${o.id}"><option value="">未指派</option>${staffOptions(o.assign)}</select></td>
    <td>${statusBadge(o.status)}</td><td>${esc(o.memo)}</td>
    <td><button class="btn btn-green btn-sm" data-done-repair="${o.id}">已完修</button>
        ${state.isAdmin ? `<button class="btn btn-danger btn-sm" data-del-repair="${o.id}">刪除</button>` : ""}</td>
  </tr>`).join("");
  return orderForm("wo", `<div><label>狀態</label><select id="wo_status"><option>報修中</option><option>維修中</option><option>已完修</option></select></div>`)
    + `<div class="form-card"><h3>維修工單</h3>${simpleTable(["日期","客戶","地址","指派","狀態","備註","操作"], rows)}</div>`;
}

function renderRecycles() {
  const rows = state.data.recycles.map((o) => `<tr>
    <td>${esc(o.date || "")}</td><td>${esc(o.name)} / ${esc(o.phone)}</td><td>${esc(o.item)}</td>
    <td><select data-assign-recycle="${o.id}"><option value="">未指派</option>${staffOptions(o.assign)}</select></td>
    <td>${statusBadge(o.status)}</td><td>${esc(o.memo)}</td>
    <td><button class="btn btn-green btn-sm" data-done-recycle="${o.id}">完成</button>
        ${state.isAdmin ? `<button class="btn btn-danger btn-sm" data-del-recycle="${o.id}">刪除</button>` : ""}</td>
  </tr>`).join("");
  return orderForm("rc", `<div><label>品項</label><input id="rc_item"></div>`)
    + `<div class="form-card"><h3>回收工單</h3>${simpleTable(["日期","客戶","品項","指派","狀態","備註","操作"], rows)}</div>`;
}

function renderMembers() {
  const q = (document.getElementById("memberQ")?.value || "").trim();
  const list = state.data.members.filter((m) => !q || `${m.name}${m.phone}${m.addr}`.includes(q));
  const rows = list.map((m) => `<tr><td>${esc(m.name)}</td><td>${esc(m.phone)}</td><td>${esc(m.addr)}</td><td>${esc(m.email)}</td>
    <td>${state.isAdmin ? `<button class="btn btn-danger btn-sm" data-del-member="${m.id}">刪除</button>` : ""}</td></tr>`).join("");
  return `<div class="form-card"><h3>新增會員</h3><div class="row">
    <div><label>姓名</label><input id="m_name"></div><div><label>手機</label><input id="m_phone"></div>
    <div><label>地址</label><input id="m_addr"></div><div><label>Email</label><input id="m_email"></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-member>新增</button></div>
  <div class="form-card"><input id="memberQ" placeholder="搜尋姓名／手機" oninput="goPage('member')">
  ${simpleTable(["姓名","手機","地址","Email","操作"], rows)}</div>`;
}

function renderHr() {
  if (!state.isAdmin && !canSee("hr")) return `<div class="form-card">沒有權限</div>`;
  const rows = state.data.employees.map((e) => `<tr>
    <td>${esc(e.name)}</td><td>${esc(e.department)}</td><td>${esc(e.phone)}</td><td>${esc(e.entryDate)}</td>
    <td><button class="btn btn-danger btn-sm" data-del-emp="${e.id}">刪除</button></td></tr>`).join("");
  return `<div class="form-card"><h3>新增員工</h3><div class="row">
    <div><label>姓名</label><input id="e_name"></div>
    <div><label>密碼</label><input id="e_pwd" value="1234"></div>
    <div><label>部門</label><select id="e_dept">${state.data.departments.map((d) => `<option>${esc(d)}</option>`).join("")}</select></div>
    <div><label>電話</label><input id="e_phone"></div>
    <div><label>到職日</label><input id="e_entry" type="date"></div>
    <div><label>地址</label><input id="e_addr"></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-emp>新增員工</button></div>
  <div class="form-card">${simpleTable(["姓名","部門","電話","到職日","操作"], rows)}</div>`;
}

function renderLeaves() {
  const rows = state.data.leaves.map((l) => `<tr>
    <td>${esc(l.employee)}</td><td>${esc(l.leaveType)}</td>
    <td>${esc(l.startDate)} ${esc(l.startTime)}<br>至 ${esc(l.endDate)} ${esc(l.endTime)}</td>
    <td>${esc(l.hours)}</td><td>${esc(l.reason)}</td><td>${statusBadge(l.status)}</td>
    <td>${state.isAdmin || canSee("leaveManage") ? `<button class="btn btn-green btn-sm" data-leave="${l.id}" data-st="核准">核准</button>
        <button class="btn btn-danger btn-sm" data-leave="${l.id}" data-st="駁回">駁回</button>` : ""}</td>
  </tr>`).join("");
  return `<div class="form-card"><h3>請假管理</h3><p class="muted">員工由 <a href="/leave" target="_blank">請假申請頁</a> 送出後，在此審核。</p>${simpleTable(["員工","假別","期間","時數","事由","狀態","操作"], rows)}</div>`;
}

function renderMySalary() {
  return `<div class="form-card"><h3>我的薪資月報</h3>
    <label>月份</label><input id="myMonth" type="month">
    <button class="btn btn-primary" style="margin-top:12px" data-load-salary>載入</button>
    <div id="salaryBox" class="muted" style="margin-top:12px">選擇月份後載入。</div></div>`;
}

function renderTasks() {
  const rows = state.data.tasks.map((t) => `<tr>
    <td>${esc(t.name)}</td><td>${esc(t.assign)}</td><td>${statusBadge(t.status)}</td>
    <td>${esc(t.deadline)}</td><td>${esc(t.detail)}</td>
    <td><button class="btn btn-warn btn-sm" data-done-task="${t.id}">完成</button>
        ${state.isAdmin ? `<button class="btn btn-danger btn-sm" data-del-task="${t.id}">刪除</button>` : ""}</td>
  </tr>`).join("");
  const form = state.isAdmin ? `<div class="form-card"><h3>指派任務</h3><div class="row">
    <div><label>任務名稱</label><input id="t_name"></div>
    <div><label>負責人</label><select id="t_assign">${staffOptions()}</select></div>
    <div><label>期限</label><input id="t_deadline" type="datetime-local"></div>
    <div class="full"><label>說明</label><textarea id="t_detail"></textarea></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-task>新增任務</button></div>` : "";
  return form + `<div class="form-card"><h3>工作任務</h3>${simpleTable(["任務","負責人","狀態","期限","說明","操作"], rows)}</div>`;
}

function renderMemos() {
  const rows = state.data.adminMemos.map((m) => `<tr><td>${esc(m.kind)}</td><td>${esc(m.title)}</td><td>${esc(m.assignee)}</td><td>${esc(m.day)}</td><td>${statusBadge(m.status || "開放")}</td>
    <td><button class="btn btn-green btn-sm" data-memo-done="${m.id}">回報完成</button></td></tr>`).join("");
  return `<div class="form-card"><h3>新增事項</h3><div class="row">
    <div><label>類型</label><select id="memoKind"><option value="monthly">每月固定要做</option><option value="memo">備忘錄</option></select></div>
    <div><label>每月日</label><input id="memoDay" type="number" min="0" max="31" value="1"></div>
    <div><label>對象</label><select id="memoAssignee">${staffOptions()}</select></div>
    <div><label>標題</label><input id="memoTitle"></div>
    <div class="full"><label>說明</label><textarea id="memoDetail"></textarea></div>
  </div><button class="btn btn-green" style="margin-top:12px" data-add-memo>儲存事項</button></div>
  <div class="form-card">${simpleTable(["類型","標題","對象","日","狀態","操作"], rows)}</div>`;
}

function renderCodex() {
  const rows = state.data.codexReports.map((c) => `<tr><td>${statusBadge(c.status)}</td><td>${esc(c.priority)}</td><td>${esc(c.category)}</td><td>${esc(c.user)}</td><td>${esc(c.title)}<br><span class="muted">${esc(c.detail)}</span></td><td>${esc(c.createdAt)}</td>
    <td>${state.isAdmin ? `<button class="btn btn-green btn-sm" data-codex="${c.id}">完成</button>` : ""}</td></tr>`).join("");
  return `<div class="form-card"><h3>新增回報</h3><div class="row">
    <div><label>分類</label><select id="cxCat"><option>後台錯誤</option><option>功能需求</option><option>資料異常</option><option>畫面調整</option><option>流程建議</option><option>其他</option></select></div>
    <div><label>優先度</label><select id="cxPri"><option>一般</option><option>急</option><option>重大</option></select></div>
    <div><label>相關頁面</label><input id="cxPage"></div>
    <div><label>標題</label><input id="cxTitle"></div>
    <div class="full"><label>內容</label><textarea id="cxDetail"></textarea></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-codex>送出回報</button></div>
  <div class="form-card">${simpleTable(["狀態","優先","分類","回報人","內容","時間","操作"], rows)}</div>`;
}

function renderRma() {
  const rows = state.data.rma.map((r) => `<tr><td>${esc(r.date)}</td><td>${esc(r.brand)}</td><td>${esc(r.model)}</td><td>${esc(r.sn)}</td><td>${esc(r.ownerType)}</td><td>${esc(r.customer)}</td><td>${statusBadge(r.status)}</td>
    <td><select data-rma-st="${r.id}"><option>未收件</option><option>已收件</option><option>送修中</option><option>完成</option></select></td></tr>`).join("");
  return `<div class="form-card"><h3>新增原廠送修單</h3><div class="row">
    <div><label>廠牌</label><input id="rmaBrand"></div><div><label>型號</label><input id="rmaModel"></div>
    <div><label>序號</label><input id="rmaSn"></div><div><label>物流單號</label><input id="rmaLog"></div>
    <div><label>類型</label><select id="rmaType"><option>公司</option><option>客戶</option></select></div>
    <div><label>客戶</label><input id="rmaCust"></div>
    <div class="full"><label>故障說明</label><textarea id="rmaMemo"></textarea></div>
  </div><button class="btn btn-warn" style="margin-top:12px" data-add-rma>新增送修單</button></div>
  <div class="form-card">${simpleTable(["日期","廠牌","型號","序號","類型","客戶","狀態","更新"], rows)}</div>`;
}

function renderPunish() {
  const rows = state.data.punish.map((p) => `<tr><td>${esc(p.date)}</td><td>${esc(p.employee)}</td><td>${esc(p.point)}</td><td>${esc(p.reason)}</td><td>${esc(p.source)}</td></tr>`).join("");
  return `<div class="form-card"><h3>獎懲登錄</h3><div class="row">
    <div><label>員工</label><select id="pEmp">${staffOptions()}</select></div>
    <div><label>點數（負數為扣）</label><input id="pPoint" type="number" value="-1"></div>
    <div class="full"><label>原因</label><input id="pReason"></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-punish>登錄</button></div>
  <div class="form-card">${simpleTable(["日期","員工","點數","原因","來源"], rows)}</div>`;
}

function renderSalaryReports() {
  return `<div class="form-card"><h3>薪資報表</h3>
    <div class="row"><div><label>月份</label><input id="srMonth" type="month"></div>
    <div><label>員工</label><select id="srEmp"><option value="">全部員工</option>${staffOptions()}</select></div></div>
    <button class="btn btn-primary" style="margin-top:12px" data-salary-report>產生報表</button>
    <div id="srBox" style="margin-top:12px"></div></div>`;
}

function renderInvoices() {
  const rows = state.data.invoices.map((i) => `<tr><td>${esc(i.date)}</td><td>${esc(i.number)}</td><td>${esc(i.buyer)}</td><td>${esc(i.amount)}</td><td>${statusBadge(i.status)}</td>
    <td><button class="btn btn-green btn-sm" data-inv="${i.id}">入帳</button></td></tr>`).join("");
  return `<div class="form-card"><h3>新增電子發票</h3><div class="row">
    <div><label>日期</label><input id="invDate" type="date"></div>
    <div><label>發票號碼</label><input id="invNo"></div>
    <div><label>買受人／往來戶</label><input id="invBuyer"></div>
    <div><label>金額</label><input id="invAmt" type="number"></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-inv>新增</button></div>
  <div class="form-card">${simpleTable(["日期","號碼","往來戶","金額","狀態","操作"], rows)}</div>`;
}

function renderCustoms() {
  const rows = state.data.customsDutyEntries.map((c) => `<tr><td>${esc(c.date)}</td><td>${esc(c.number)}</td><td>${esc(c.broker)}</td><td>${esc(c.productCost)}</td><td>${esc(c.fee)}</td><td>${esc(c.note)}</td></tr>`).join("");
  return `<div class="form-card"><h3>關稅單號</h3><p class="muted">手續費一筆 30 元；少給快遞視為正常，產品成本以快遞收費為準。</p>
    <div class="row">
      <div><label>日期</label><input id="cuDate" type="date"></div>
      <div><label>關稅號碼</label><input id="cuNo"></div>
      <div><label>報關行</label><input id="cuBroker"></div>
      <div><label>產品成本（快遞收費）</label><input id="cuCost" type="number"></div>
      <div class="full"><label>備註</label><input id="cuNote"></div>
    </div>
    <button class="btn btn-primary" style="margin-top:12px" data-add-customs>新增</button></div>
    <div class="form-card">${simpleTable(["日期","單號","報關行","產品成本","手續費","備註"], rows)}</div>`;
}

function renderHardware() {
  const rows = state.data.hardwareMarket.map((h) => `<tr>
    <td>${esc(h.category)}</td><td>${esc(h.country)}</td><td>${esc(h.item)}</td>
    <td><input data-hw-price="${h.id}" value="${esc(h.price)}" style="width:100px"></td>
    <td>${esc(h.currency)}</td><td>${esc(h.trend)}</td><td>${esc(h.date)}</td>
  </tr>`).join("");
  return `<div class="form-card"><h3>硬體行情</h3><p class="muted">修改價格後按儲存。</p>
    ${simpleTable(["類別","地區","品項","價格","幣別","趨勢","日期"], rows)}
    <button class="btn btn-primary" style="margin-top:12px" data-save-hw>儲存行情</button></div>`;
}

function renderCrypto() {
  return `<div class="form-card"><h3>虛擬貨幣行情預測</h3>
    <p class="muted">讀取公開市場參考價，僅供內部討論，不構成投資建議。</p>
    <button class="btn btn-primary" data-load-crypto>更新行情</button>
    <div id="cryptoBox" style="margin-top:12px">尚未載入</div></div>`;
}

function renderQuotes() {
  const rows = (state.data.quotes || []).map((q) => `<tr><td>${esc(q.date)}</td><td>${esc(q.customer)}</td><td>${esc(q.title)}</td><td>${esc(q.amount)}</td><td>${statusBadge(q.status)}</td></tr>`).join("");
  return `<div class="form-card"><h3>新增報價</h3><div class="row">
    <div><label>客戶</label><input id="qCust"></div>
    <div><label>主旨</label><input id="qTitle"></div>
    <div><label>金額</label><input id="qAmt" type="number"></div>
    <div><label>狀態</label><select id="qSt"><option>草稿</option><option>已送出</option><option>已成交</option></select></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-quote>新增</button></div>
  <div class="form-card">${simpleTable(["日期","客戶","主旨","金額","狀態"], rows)}</div>`;
}

function renderPasswords() {
  if (!state.isAdmin) return `<div class="form-card">僅管理員與行政可檢視。</div>`;
  const rows = state.data.passwords.map((p) => `<tr><td>${esc(p.name)}</td><td>${esc(p.account)}</td><td><input type="password" value="${esc(p.pwd)}" readonly></td><td>${esc(p.note)}</td>
    <td><button class="btn btn-danger btn-sm" data-del-pwd="${p.id}">刪除</button></td></tr>`).join("");
  return `<div class="form-card"><h3>新增密碼記錄</h3><div class="row">
    <div><label>名稱</label><input id="pwName"></div><div><label>帳號</label><input id="pwAcc"></div>
    <div><label>密碼</label><input id="pwPwd"></div><div><label>備註</label><input id="pwNote"></div>
  </div><button class="btn btn-primary" style="margin-top:12px" data-add-pwd>新增</button></div>
  <div class="form-card">${simpleTable(["名稱","帳號","密碼","備註","操作"], rows)}</div>`;
}

function renderPermission() {
  if (!state.isAdmin) return `<div class="form-card">僅管理員可設定權限。</div>`;
  const emp = document.getElementById("permEmp")?.value || state.data.employees[0]?.name || "";
  const current = (state.data.permissions[emp] || []);
  const checks = PAGES.map((p) => `<label style="font-weight:600"><input type="checkbox" data-perm="${p.id}" ${current.includes(p.id) ? "checked" : ""}> ${esc(p.label)}</label>`).join("");
  return `<div class="form-card"><h3>權限設定</h3>
    <label>員工</label><select id="permEmp" onchange="goPage('permission')">${staffOptions(emp)}</select>
    <div class="grid" style="margin-top:12px">${checks}</div>
    <button class="btn btn-primary" style="margin-top:12px" data-save-perm>儲存權限</button></div>`;
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function bindPage(id) {
  if (id === "repairOrder" || id === "recycleOrder") {
    const prefix = id === "repairOrder" ? "wo" : "rc";
    fillCountyDistrict(`${prefix}_county`, `${prefix}_district`);
  }
  const view = document.getElementById("view");
  view.onclick = async (e) => {
    const t = e.target;
    try {
      if (t.dataset.addFault != null) {
        state.data.faultList.push({ id: Date.now(), name: val("faultName"), price: Number(val("faultPrice")) || 0 });
        await save(); goPage("faultSetting");
      } else if (t.dataset.delFault) {
        state.data.faultList = state.data.faultList.filter((f) => String(f.id) !== t.dataset.delFault);
        await save(); goPage("faultSetting");
      } else if (t.dataset.add === "wo") {
        pushOrder("workOrders", "wo", val("wo_status") || "報修中");
        await save(); goPage("repairOrder");
      } else if (t.dataset.add === "rc") {
        const order = buildOrder("rc");
        order.item = val("rc_item");
        order.status = "未完成";
        state.data.recycles.unshift(order);
        await save(); goPage("recycleOrder");
      } else if (t.dataset.doneRepair) {
        const o = state.data.workOrders.find((x) => String(x.id) === t.dataset.doneRepair);
        if (o) o.status = "已完修";
        await save(); goPage("repairOrder");
      } else if (t.dataset.doneRecycle) {
        const o = state.data.recycles.find((x) => String(x.id) === t.dataset.doneRecycle);
        if (o) o.status = "已完成";
        await save(); goPage("recycleOrder");
      } else if (t.dataset.delRepair) {
        state.data.workOrders = state.data.workOrders.filter((x) => String(x.id) !== t.dataset.delRepair);
        await save(); goPage("repairOrder");
      } else if (t.dataset.delRecycle) {
        state.data.recycles = state.data.recycles.filter((x) => String(x.id) !== t.dataset.delRecycle);
        await save(); goPage("recycleOrder");
      } else if (t.dataset.addMember != null) {
        state.data.members.unshift({ id: Date.now(), name: val("m_name"), phone: val("m_phone"), addr: val("m_addr"), email: val("m_email") });
        await save(); goPage("member");
      } else if (t.dataset.delMember) {
        state.data.members = state.data.members.filter((x) => String(x.id) !== t.dataset.delMember);
        await save(); goPage("member");
      } else if (t.dataset.addEmp != null) {
        state.data.employees.push({ id: Date.now(), name: val("e_name"), pwd: val("e_pwd"), department: val("e_dept"), phone: val("e_phone"), entryDate: val("e_entry"), addr: val("e_addr"), openingUsedLeave: 0, openingSickDays: 0, openingPersonalDays: 0 });
        await save(); goPage("hr");
      } else if (t.dataset.delEmp) {
        state.data.employees = state.data.employees.filter((x) => String(x.id) !== t.dataset.delEmp);
        await save(); goPage("hr");
      } else if (t.dataset.leave) {
        const row = state.data.leaves.find((x) => String(x.id) === t.dataset.leave);
        if (row) row.status = t.dataset.st;
        await save(); goPage("leaveManage");
      } else if (t.dataset.addTask != null) {
        state.data.tasks.unshift({ id: Date.now(), name: val("t_name"), assign: val("t_assign"), deadline: val("t_deadline"), detail: val("t_detail"), status: "待處理" });
        await save(); goPage("task");
      } else if (t.dataset.doneTask) {
        const row = state.data.tasks.find((x) => String(x.id) === t.dataset.doneTask);
        if (row) row.status = "已完成";
        await save(); goPage("task");
      } else if (t.dataset.delTask) {
        state.data.tasks = state.data.tasks.filter((x) => String(x.id) !== t.dataset.delTask);
        await save(); goPage("task");
      } else if (t.dataset.addMemo != null) {
        state.data.adminMemos.unshift({ id: Date.now(), kind: val("memoKind"), day: val("memoDay"), assignee: val("memoAssignee"), title: val("memoTitle"), detail: val("memoDetail"), status: "開放" });
        await save(); goPage("adminMemo");
      } else if (t.dataset.memoDone) {
        const row = state.data.adminMemos.find((x) => String(x.id) === t.dataset.memoDone);
        if (row) row.status = "完成";
        await save(); goPage("adminMemo");
      } else if (t.dataset.addCodex != null) {
        state.data.codexReports.unshift({ id: Date.now(), category: val("cxCat"), priority: val("cxPri"), page: val("cxPage"), title: val("cxTitle"), detail: val("cxDetail"), user: state.user, status: "待處理", createdAt: new Date().toLocaleString("zh-TW", { hour12: false }) });
        await save(); goPage("codexReport");
      } else if (t.dataset.codex) {
        const row = state.data.codexReports.find((x) => String(x.id) === t.dataset.codex);
        if (row) row.status = "已完成";
        await save(); goPage("codexReport");
      } else if (t.dataset.addRma != null) {
        state.data.rma.unshift({ id: Date.now(), date: today(), brand: val("rmaBrand"), model: val("rmaModel"), sn: val("rmaSn"), logistic: val("rmaLog"), ownerType: val("rmaType"), customer: val("rmaCust"), memo: val("rmaMemo"), status: "未收件" });
        await save(); goPage("rma");
      } else if (t.dataset.addPunish != null) {
        state.data.punish.unshift({ id: Date.now(), date: today(), employee: val("pEmp"), point: Number(val("pPoint")) || 0, reason: val("pReason"), source: "後台登錄" });
        await save(); goPage("punishSystem");
      } else if (t.dataset.addInv != null) {
        state.data.invoices.unshift({ id: Date.now(), date: val("invDate") || today(), number: val("invNo"), buyer: val("invBuyer"), amount: Number(val("invAmt")) || 0, status: "未入帳" });
        await save(); goPage("electronicInvoices");
      } else if (t.dataset.inv) {
        const row = state.data.invoices.find((x) => String(x.id) === t.dataset.inv);
        if (row) row.status = "已入帳";
        await save(); goPage("electronicInvoices");
      } else if (t.dataset.addCustoms != null) {
        state.data.customsDutyEntries.unshift({ id: Date.now(), date: val("cuDate") || today(), number: val("cuNo"), broker: val("cuBroker"), productCost: Number(val("cuCost")) || 0, fee: 30, note: val("cuNote") });
        await save(); goPage("customsDuty");
      } else if (t.dataset.saveHw != null) {
        document.querySelectorAll("[data-hw-price]").forEach((inp) => {
          const row = state.data.hardwareMarket.find((x) => String(x.id) === inp.dataset.hwPrice);
          if (row) { row.prevPrice = row.price; row.price = Number(inp.value) || 0; row.date = today(); }
        });
        await save(); goPage("hardwareMarket");
      } else if (t.dataset.addQuote != null) {
        state.data.quotes.unshift({ id: Date.now(), date: today(), customer: val("qCust"), title: val("qTitle"), amount: Number(val("qAmt")) || 0, status: val("qSt") });
        await save(); goPage("quotes");
      } else if (t.dataset.addPwd != null) {
        state.data.passwords.unshift({ id: Date.now(), name: val("pwName"), account: val("pwAcc"), pwd: val("pwPwd"), note: val("pwNote") });
        await save(); goPage("passwordManager");
      } else if (t.dataset.delPwd) {
        state.data.passwords = state.data.passwords.filter((x) => String(x.id) !== t.dataset.delPwd);
        await save(); goPage("passwordManager");
      } else if (t.dataset.savePerm != null) {
        const empName = document.getElementById("permEmp").value;
        state.data.permissions[empName] = [...document.querySelectorAll("[data-perm]:checked")].map((x) => x.dataset.perm);
        await save(); goPage("permission");
      } else if (t.dataset.loadSalary != null) {
        const month = document.getElementById("myMonth").value;
        const payload = await api("salary", { month, employee: state.user });
        renderSlip(document.getElementById("salaryBox"), payload.slip);
      } else if (t.dataset.salaryReport != null) {
        const month = document.getElementById("srMonth").value;
        const emp = document.getElementById("srEmp").value;
        const payload = await api("salary", emp ? { month, employee: emp } : { month, all: true });
        const box = document.getElementById("srBox");
        if (payload.slip) renderSlip(box, payload.slip);
        else {
          box.innerHTML = simpleTable(["員工","底薪","工作獎金","全勤","請假扣","獎懲","實發"], payload.rows.map((r) => `<tr><td>${esc(r.employee)}</td><td>${r.basePay}</td><td>${r.workBonus}</td><td>${r.fullBonus}</td><td>${r.leaveOffset}</td><td>${r.pointAmount}</td><td><b>${r.net}</b></td></tr>`).join(""));
        }
      } else if (t.dataset.loadCrypto != null) {
        loadCrypto();
      }
    } catch (err) {
      alert(err.message);
    }
  };
  view.onchange = async (e) => {
    const t = e.target;
    if (t.dataset.assignRepair) {
      const o = state.data.workOrders.find((x) => String(x.id) === t.dataset.assignRepair);
      if (o) { o.assign = t.value; if (o.status === "報修中") o.status = "維修中"; await save(); }
    } else if (t.dataset.assignRecycle) {
      const o = state.data.recycles.find((x) => String(x.id) === t.dataset.assignRecycle);
      if (o) { o.assign = t.value; await save(); }
    } else if (t.dataset.rmaSt) {
      const o = state.data.rma.find((x) => String(x.id) === t.dataset.rmaSt);
      if (o) { o.status = t.value; await save(); goPage("rma"); }
    }
  };
}

function val(id) { return document.getElementById(id)?.value.trim() || ""; }

function buildOrder(prefix) {
  const county = val(`${prefix}_county`);
  const district = val(`${prefix}_district`);
  const addr = val(`${prefix}_addr`);
  return {
    id: Date.now(),
    name: val(`${prefix}_name`),
    phone: val(`${prefix}_phone`),
    addr,
    fullAddr: `${county} ${district} ${addr}`.trim(),
    date: today(),
    assign: val(`${prefix}_assign`),
    memo: val(`${prefix}_memo`)
  };
}

function pushOrder(listKey, prefix, status) {
  const order = buildOrder(prefix);
  order.status = status;
  state.data[listKey].unshift(order);
  if (order.name && order.phone) {
    const found = state.data.members.find((m) => m.phone === order.phone);
    if (found) { found.name = order.name; found.addr = order.fullAddr; }
    else state.data.members.unshift({ id: Date.now() + 1, name: order.name, phone: order.phone, addr: order.fullAddr, email: "" });
  }
}

function renderSlip(el, slip) {
  if (!el) return;
  if (!slip) { el.innerHTML = "找不到薪資資料"; return; }
  el.innerHTML = `<div class="salary-slip">
    <h4>${esc(slip.employee)}　${esc(slip.month)} 薪資單</h4>
    <p class="muted">到職 ${esc(slip.entryDate)}｜特休剩餘 ${slip.remainLeaveDays} 天（應有 ${slip.entitledLeaveDays}）</p>
    <table class="table"><tbody>
      <tr><td>底薪（不低於基本工資）</td><td>${slip.basePay}</td></tr>
      <tr><td>工作獎金</td><td>${slip.workBonus}</td></tr>
      <tr><td>全勤獎</td><td>${slip.fullBonus}</td></tr>
      <tr><td>加項</td><td>${slip.extrasAdd}</td></tr>
      <tr><td>扣項</td><td>${slip.extrasSubtract}</td></tr>
      <tr><td>請假時數／扣款</td><td>${slip.leaveHours} 小時／${slip.leaveOffset}</td></tr>
      <tr><td>獎懲點數／金額</td><td>${slip.points}／${slip.pointAmount}</td></tr>
      <tr><th>應發</th><th>${slip.gross}</th></tr>
      <tr><th>扣除</th><th>${slip.deduct}</th></tr>
      <tr class="salary-total-row"><th>實發</th><th>${slip.net}</th></tr>
    </tbody></table></div>`;
}

async function loadCrypto() {
  const box = document.getElementById("cryptoBox");
  box.textContent = "載入中...";
  try {
    const res = await fetch("https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,solana,tether&vs_currencies=twd,usd");
    const json = await res.json();
    box.innerHTML = simpleTable(["幣別","TWD","USD"], Object.entries(json).map(([k, v]) => `<tr><td>${esc(k)}</td><td>${v.twd}</td><td>${v.usd}</td></tr>`).join(""));
  } catch {
    box.textContent = "無法連到行情來源，請稍後再試。";
  }
}

async function boot() {
  try {
    const status = await api("status");
    if (!status.loggedIn) return;
    state.user = status.user;
    state.isAdmin = !!status.isAdmin;
    await load();
    document.getElementById("loginPage").style.display = "none";
    document.getElementById("adminApp").classList.add("on");
    document.getElementById("who").textContent = `目前登入：${state.user}｜${state.isAdmin ? "管理員" : "員工"}`;
    goPage((location.hash || "#dashboard").slice(1));
  } catch {
    // stay on login
  }
}

window.addEventListener("hashchange", () => {
  if (state.data) goPage(location.hash.slice(1));
});
window.login = login;
window.logout = logout;
window.goPage = goPage;
boot();
