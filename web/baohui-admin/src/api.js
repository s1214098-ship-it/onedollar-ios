import fs from "node:fs";
import path from "node:path";
import { config } from "./config.js";
import {
  authenticate,
  changeAdminPassword,
  getStore,
  saveStore,
  mutateStore,
  logActivity,
  upsertMember,
  recordUpload
} from "./db.js";
import { PAGES, ensureDataShape } from "./shape.js";
import { settleSalary, companyStats, calculateLeaveHours, statutoryAnnualLeaveDays } from "./salary.js";
import { newId, nowText, todayText, monthKey, signCookie } from "./crypto.js";
import { sendJson } from "./http.js";

function requireUser(req) {
  if (!req.session?.user) {
    const err = new Error("尚未登入");
    err.code = "UNAUTHORIZED";
    throw err;
  }
  return req.session;
}

function requireAdmin(req) {
  const session = requireUser(req);
  if (!session.isAdmin) {
    const err = new Error("需要管理員權限");
    err.code = "FORBIDDEN";
    throw err;
  }
  return session;
}

function publicOk(payload) {
  return { ok: true, ...payload };
}

export async function handleApi(req, res, { action, body, files }) {
  try {
    const result = await dispatch(req, action, body || {}, files || []);
    if (result && result._headers) {
      for (const [key, value] of Object.entries(result._headers)) res.setHeader(key, value);
      delete result._headers;
    }
    const status = result?._status || 200;
    if (result) delete result._status;
    sendJson(res, status, result);
  } catch (err) {
    const map = { UNAUTHORIZED: 401, FORBIDDEN: 403, CONFLICT: 409, BAD_REQUEST: 400, TOO_LARGE: 413 };
    const status = map[err.code] || 500;
    sendJson(res, status, { ok: false, error: err.message || "伺服器錯誤", updated_at: err.updatedAt });
  }
}

async function dispatch(req, action, body, files) {
  switch (action) {
    case "status":
      return publicOk({
        loggedIn: !!req.session?.user,
        user: req.session?.user || "",
        isAdmin: !!req.session?.isAdmin
      });
    case "login":
      return loginAction(body);
    case "logout":
      return {
        ok: true,
        _headers: { "Set-Cookie": `${config.cookieName}=; Path=/; Max-Age=0; SameSite=Lax; HttpOnly` }
      };
    case "load":
      return loadAction(req);
    case "save":
      return saveAction(req, body);
    case "data_version":
      requireUser(req);
      return publicOk({ updated_at: getStore().updatedAt });
    case "live_sync":
      return liveSyncAction(req);
    case "change_admin_password":
      requireAdmin(req);
      return publicOk({ updated_at: changeAdminPassword(body.old_password, body.new_password) });
    case "one_dollar_summary":
      requireUser(req);
      return publicOk({
        listings: 0,
        scheduled: 0,
        closed: 0,
        note: "一元競標後台可另接 one-dollar-auction 服務"
      });
    case "product_catalog":
      requireUser(req);
      return productCatalog(body.q || req.query?.q || "");
    case "task_report_photo_upload":
      requireUser(req);
      return uploadFiles(files, "task-report");
    case "quote_seal_upload":
      requireAdmin(req);
      return uploadFiles(files, "quote-seal");
    case "codex_line_notify":
      requireUser(req);
      return publicOk({ sent: false, note: "未設定 LINE Notify Token 時僅寫入後台回報" });
    case "pages":
      requireUser(req);
      return publicOk({ pages: PAGES });
    case "stats":
      requireUser(req);
      return publicOk({ stats: companyStats(getStore().data), generatedAt: nowText() });
    case "salary":
      return salaryAction(req, body);
    case "leave_preview":
      return leavePreview(body);
    default:
      sendUnknown(action);
  }
}

function sendUnknown(action) {
  const err = new Error(`未知的 action：${action || "(空白)"}`);
  err.code = "BAD_REQUEST";
  throw err;
}

function loginAction(body) {
  const authed = authenticate(body.user || body.username, body.password || body.pwd);
  if (!authed) {
    const err = new Error("帳號或密碼錯誤");
    err.code = "BAD_REQUEST";
    throw err;
  }
  try {
    mutateStore((data) => {
      logActivity(data, "登入", authed.isAdmin ? "管理員登入" : "員工登入", authed.user, authed.user);
    });
  } catch {
    // login still succeeds even if activity log write races
  }
  const token = signCookie(
    {
      user: authed.user,
      isAdmin: authed.isAdmin,
      exp: Date.now() + config.sessionHours * 3600 * 1000
    },
    config.sessionSecret
  );
  return {
    ok: true,
    user: authed.user,
    isAdmin: authed.isAdmin,
    _headers: {
      "Set-Cookie": `${config.cookieName}=${encodeURIComponent(token)}; Path=/; Max-Age=${config.sessionHours * 3600}; HttpOnly; SameSite=Lax`
    }
  };
}

function loadAction(req) {
  requireUser(req);
  const { data, updatedAt } = getStore();
  return { ok: true, data, updated_at: updatedAt };
}

function saveAction(req, body) {
  const session = requireUser(req);
  if (!body || typeof body.data !== "object") {
    const err = new Error("缺少資料");
    err.code = "BAD_REQUEST";
    throw err;
  }
  try {
    const saved = saveStore(ensureDataShape(body.data), body.updated_at);
    return { ok: true, updated_at: saved.updatedAt, user: session.user };
  } catch (err) {
    if (err.code === "CONFLICT") throw err;
    throw err;
  }
}

function liveSyncAction(req) {
  requireUser(req);
  const { data, updatedAt } = getStore();
  return {
    ok: true,
    updated_at: updatedAt,
    server_time: nowText(),
    user: req.session.user,
    isAdmin: !!req.session.isAdmin,
    tasks: data.tasks || [],
    taskReports: data.taskReports || [],
    codexReports: data.codexReports || [],
    activityLogs: data.activityLogs || []
  };
}

function productCatalog(query) {
  const { data } = getStore();
  const q = String(query || "").trim().toLowerCase();
  const faults = (data.faultList || []).map((item) => ({
    id: item.id,
    name: item.name,
    price: item.price,
    kind: "fault"
  }));
  const list = q ? faults.filter((item) => String(item.name).toLowerCase().includes(q)) : faults;
  return { ok: true, items: list };
}

function uploadFiles(files, kind) {
  const saved = [];
  const dir = path.join(config.dataDir, "uploads");
  fs.mkdirSync(dir, { recursive: true });
  const selected = files.filter((f) => f.filename).slice(0, 6);
  for (const file of selected) {
    if (file.buffer.length > 8 * 1024 * 1024) {
      const err = new Error("單張照片需小於 8MB");
      err.code = "BAD_REQUEST";
      throw err;
    }
    const ext = path.extname(file.filename || "").toLowerCase() || ".bin";
    const filename = `${kind}-${newId()}${ext}`;
    const full = path.join(dir, filename);
    fs.writeFileSync(full, file.buffer);
    saved.push(recordUpload({ kind, filename, mime: file.mime, relativePath: filename }));
  }
  return { ok: true, files: saved };
}

function salaryAction(req, body) {
  const session = requireUser(req);
  const { data } = getStore();
  const month = body.month || monthKey();
  const empName = session.isAdmin ? (body.employee || session.user) : session.user;
  if (body.all && session.isAdmin) {
    const rows = (data.employees || []).map((emp) => settleSalary(data, emp.name, month)).filter(Boolean);
    return { ok: true, month, rows };
  }
  return { ok: true, month, slip: settleSalary(data, empName, month) };
}

function leavePreview(body) {
  const hours = calculateLeaveHours(body.startDate, body.startTime, body.endDate, body.endTime, body.lunchBreak !== false && body.lunchBreak !== "0");
  return { ok: true, hours, days: Math.round((hours / 8) * 100) / 100 };
}

export function handlePublicCustomer(kind, body) {
  if (kind === "repair") return createRepair(body);
  if (kind === "recycle") return createRecycle(body);
  if (kind === "project") return createProject(body);
  const err = new Error("未知的申請類型");
  err.code = "BAD_REQUEST";
  throw err;
}

function required(body, keys) {
  for (const key of keys) {
    if (!String(body[key] || "").trim()) {
      const err = new Error("請填寫完整資料");
      err.code = "BAD_REQUEST";
      throw err;
    }
  }
}

function createRepair(body) {
  required(body, ["name", "phone", "county", "district"]);
  const fullAddr = `${body.county} ${body.district} ${body.addr || ""}`.trim();
  const saved = mutateStore((data) => {
    upsertMember(data, { name: body.name, phone: body.phone, addr: fullAddr });
    data.workOrders.unshift({
      id: newId(),
      name: body.name.trim(),
      phone: body.phone.trim(),
      addr: body.addr || "",
      fullAddr,
      date: todayText(),
      assign: "",
      status: "報修中",
      fault: body.fault || "",
      contactTime: body.contactTime || "",
      memo: `線上報修｜${body.fault || ""}｜描述：${body.desc || body.memo || ""}`,
      source: "web"
    });
    logActivity(data, "線上報修", `${body.name}／${body.phone}`, body.fault || "", "public");
  });
  return { ok: true, updated_at: saved.updatedAt };
}

function createRecycle(body) {
  required(body, ["name", "phone", "county", "district"]);
  const fullAddr = `${body.county} ${body.district} ${body.addr || ""}`.trim();
  const saved = mutateStore((data) => {
    upsertMember(data, { name: body.name, phone: body.phone, addr: fullAddr });
    data.recycles.unshift({
      id: newId(),
      name: body.name.trim(),
      phone: body.phone.trim(),
      addr: body.addr || "",
      fullAddr,
      item: body.item || body.type || "",
      assign: "",
      memo: body.memo || body.goods || body.desc || "",
      status: "未完成",
      date: todayText(),
      source: "web"
    });
    logActivity(data, "線上回收", `${body.name}／${body.item || body.type || ""}`, body.phone, "public");
  });
  return { ok: true, updated_at: saved.updatedAt };
}

function createProject(body) {
  required(body, ["name", "phone"]);
  const saved = mutateStore((data) => {
    upsertMember(data, { name: body.name, phone: body.phone, addr: body.addr || "" });
    data.projects.unshift({
      id: newId(),
      name: body.name.trim(),
      phone: body.phone.trim(),
      email: body.email || "",
      addr: body.addr || "",
      kind: body.kind || body.type || "專案",
      memo: body.memo || body.desc || "",
      status: "待評估",
      date: todayText(),
      source: "web"
    });
    logActivity(data, "專案申請", `${body.name}／${body.kind || ""}`, body.phone, "public");
  });
  return { ok: true, updated_at: saved.updatedAt };
}

export function handlePublicLeave(body, session) {
  if (!session?.user || session.isAdmin) {
    const empName = String(body.employee || body.employee_id || "").trim();
    const pwd = String(body.pwd || body.password || "");
    const authed = authenticate(empName, pwd);
    if (!authed || authed.isAdmin) {
      const err = new Error("員工帳號或密碼錯誤");
      err.code = "UNAUTHORIZED";
      throw err;
    }
    session = authed;
  }
  const hours = Number(body.hours) || calculateLeaveHours(
    body.start_date || body.startDate,
    body.start_time || body.startTime,
    body.end_date || body.endDate,
    body.end_time || body.endTime,
    body.lunch_break !== "0" && body.lunchBreak !== false
  );
  const saved = mutateStore((data) => {
    data.leaves.unshift({
      id: newId(),
      employee: session.user,
      leaveType: body.leave_type || body.leaveType || "事假",
      startDate: body.start_date || body.startDate,
      startTime: body.start_time || body.startTime || "08:30",
      endDate: body.end_date || body.endDate || body.start_date || body.startDate,
      endTime: body.end_time || body.endTime || "17:30",
      hours,
      reason: body.reason || "",
      signature: body.signature || "",
      proof: body.proof || "",
      status: "待審核",
      createdAt: nowText()
    });
    logActivity(data, "請假申請", `${session.user} ${body.leave_type || "事假"} ${hours} 小時`, "", session.user);
  });
  return { ok: true, hours, updated_at: saved.updatedAt };
}

export function leaveBalanceFor(empName) {
  const { data } = getStore();
  const emp = (data.employees || []).find((e) => e.name === empName);
  if (!emp) return null;
  const entitled = statutoryAnnualLeaveDays(emp.entryDate);
  const used = Number(emp.openingUsedLeave || 0) + (data.leaves || [])
    .filter((l) => l.employee === empName && ["核准", "已核准"].includes(l.status || "") && (l.leaveType || l.type) === "特休")
    .reduce((sum, l) => sum + (Number(l.hours) || 0) / 8, 0);
  return {
    name: emp.name,
    entryDate: emp.entryDate,
    entitled,
    used: Math.round(used * 100) / 100,
    remain: Math.round((entitled - used) * 100) / 100
  };
}

export { requireUser };
