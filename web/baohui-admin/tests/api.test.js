import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { after, before, test } from "node:test";

const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), "baohui-admin-"));
process.env.BAOHUI_DATA_DIR = dataDir;
process.env.BAOHUI_ADMIN_USER = "admin";
process.env.BAOHUI_ADMIN_PASSWORD = "ChangeMe-2026!";
process.env.BAOHUI_SESSION_SECRET = "test-secret-baohui";
process.env.PORT = "0";

const { createServer } = await import("../server.js");

let server;
let base;
let cookie = "";

function url(p) {
  return `${base}${p}`;
}

async function req(pathname, { method = "GET", json, headers = {} } = {}) {
  const opts = { method, headers: { ...headers }, redirect: "manual" };
  if (cookie) opts.headers.cookie = cookie;
  if (json !== undefined) {
    opts.headers["Content-Type"] = "application/json;charset=UTF-8";
    opts.body = JSON.stringify(json);
  }
  const res = await fetch(url(pathname), opts);
  const text = await res.text();
  let body = text;
  try { body = JSON.parse(text); } catch {}
  const setCookie = res.headers.getSetCookie?.() || [];
  if (setCookie.length) {
    cookie = setCookie.map((c) => c.split(";")[0]).join("; ");
  }
  return { status: res.status, body, headers: res.headers };
}

before(async () => {
  server = createServer();
  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const addr = server.address();
  base = `http://127.0.0.1:${addr.port}`;
});

after(async () => {
  await new Promise((resolve) => server.close(resolve));
  fs.rmSync(dataDir, { recursive: true, force: true });
});

test("health endpoint is up", async () => {
  const res = await req("/health");
  assert.equal(res.status, 200);
  assert.equal(res.body.ok, true);
  assert.equal(res.body.service, "baohui-admin");
});

test("portal and public pages exist", async () => {
  for (const p of ["/", "/repair", "/recycle", "/project", "/leave", "/admin", "/admin.php", "/repair.php"]) {
    const res = await req(p);
    assert.equal(res.status, 200, p);
    assert.match(String(res.body), /寶輝/);
  }
});

test("public repair and recycle write into the store", async () => {
  const repair = await req("/customer-api/repair", {
    method: "POST",
    json: {
      name: "王小明",
      phone: "0911000111",
      county: "宜蘭縣",
      district: "頭城鎮",
      addr: "頭濱路一段425號",
      fault: "無法開機",
      desc: "主機沒反應"
    }
  });
  assert.equal(repair.status, 200, JSON.stringify(repair.body));
  assert.equal(repair.body.ok, true);

  const recycle = await req("/customer-api/recycle", {
    method: "POST",
    json: {
      name: "林小姐",
      phone: "0922000222",
      county: "宜蘭縣",
      district: "頭城鎮",
      type: "螢幕",
      item: "螢幕 3 台"
    }
  });
  assert.equal(recycle.status, 200);
});

test("admin login, load, and see public tickets", async () => {
  const denied = await req("/api.php?action=load");
  assert.equal(denied.body.ok, false);

  const bad = await req("/api.php?action=login", { method: "POST", json: { user: "admin", password: "wrong" } });
  assert.equal(bad.body.ok, false);

  const login = await req("/api.php?action=login", { method: "POST", json: { user: "admin", password: "ChangeMe-2026!" } });
  assert.equal(login.status, 200, JSON.stringify(login.body));
  assert.equal(login.body.ok, true);
  assert.equal(login.body.isAdmin, true);
  assert.ok(cookie.includes("BAOHUI_ADMIN"));

  const loaded = await req("/api.php?action=load");
  assert.equal(loaded.body.ok, true);
  const names = loaded.body.data.workOrders.map((o) => o.name);
  assert.ok(names.includes("王小明"));
  assert.equal(loaded.body.data.recycles[0].name, "林小姐");
  assert.ok(loaded.body.data.members.length >= 2);
});

test("optimistic save conflict returns 409", async () => {
  const loaded = await req("/api.php?action=load");
  const data = loaded.body.data;
  data.codexReports.unshift({ id: 1, title: "first", status: "待處理" });
  const ok = await req("/api.php?action=save", {
    method: "POST",
    json: { data, updated_at: loaded.body.updated_at }
  });
  assert.equal(ok.body.ok, true);

  const stale = await req("/api.php?action=save", {
    method: "POST",
    json: { data, updated_at: loaded.body.updated_at }
  });
  assert.equal(stale.status, 409);
});

test("employee can log in and apply leave", async () => {
  cookie = "";
  const login = await req("/api.php?action=login", { method: "POST", json: { user: "李建宏", password: "1234" } });
  assert.equal(login.body.ok, true);
  assert.equal(login.body.isAdmin, false);

  const leave = await req("/leave-api", {
    method: "POST",
    json: {
      leave_type: "事假",
      start_date: "2026-08-20",
      start_time: "08:30",
      end_date: "2026-08-20",
      end_time: "17:30",
      lunch_break: "1",
      reason: "家庭事務"
    }
  });
  assert.equal(leave.status, 200, JSON.stringify(leave.body));
  assert.equal(leave.body.ok, true);
  assert.equal(leave.body.hours, 8);
});

test("salary endpoint uses minimum wage floor", async () => {
  cookie = "";
  await req("/api.php?action=login", { method: "POST", json: { user: "admin", password: "ChangeMe-2026!" } });
  const loaded = await req("/api.php?action=load");
  const leave = loaded.body.data.leaves.find((l) => l.employee === "李建宏");
  assert.ok(leave);
  leave.status = "核准";
  await req("/api.php?action=save", {
    method: "POST",
    json: { data: loaded.body.data, updated_at: loaded.body.updated_at }
  });
  const res = await req("/api.php?action=salary", { method: "POST", json: { employee: "李建宏", month: "2026-08" } });
  assert.equal(res.body.ok, true);
  assert.ok(res.body.slip.net >= 0);
  assert.equal(res.body.slip.basePay, 29500);
  assert.ok(res.body.slip.leaveHours >= 8);
});
