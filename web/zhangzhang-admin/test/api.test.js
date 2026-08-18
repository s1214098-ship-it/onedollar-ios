"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("fs");
const os = require("os");
const path = require("path");
const http = require("http");

const dataDir = fs.mkdtempSync(path.join(os.tmpdir(), "zhangzhang-"));
process.env.ZHANGZHANG_DATA_DIR = dataDir;
process.env.PORT = "0";

const { createServer } = require("../server");

function listen(server) {
  return new Promise((resolve) => {
    server.listen(0, "127.0.0.1", () => resolve(server.address().port));
  });
}

function request(port, method, urlPath, { body, cookie } = {}) {
  return new Promise((resolve, reject) => {
    const payload = body ? Buffer.from(JSON.stringify(body)) : null;
    const req = http.request(
      {
        hostname: "127.0.0.1",
        port,
        path: urlPath,
        method,
        headers: {
          "Content-Type": "application/json",
          ...(payload ? { "Content-Length": payload.length } : {}),
          ...(cookie ? { Cookie: cookie } : {})
        }
      },
      (res) => {
        const chunks = [];
        res.on("data", (c) => chunks.push(c));
        res.on("end", () => {
          const text = Buffer.concat(chunks).toString("utf8");
          let json = null;
          try { json = JSON.parse(text); } catch { json = { raw: text }; }
          const setCookie = res.headers["set-cookie"] || [];
          resolve({ status: res.statusCode, json, setCookie });
        });
      }
    );
    req.on("error", reject);
    if (payload) req.write(payload);
    req.end();
  });
}

function cookieHeader(setCookie) {
  return setCookie.map((row) => row.split(";")[0]).join("; ");
}

test("張張伺服器：登入、現貨入庫、免物流單號", async (t) => {
  const server = createServer();
  const port = await listen(server);
  t.after(() => new Promise((resolve) => server.close(resolve)));

  const health = await request(port, "GET", "/api/health");
  assert.equal(health.status, 200);
  assert.equal(health.json.name, "張張管理後台");

  const bad = await request(port, "POST", "/api/login", { body: { account: "admin", password: "wrong" } });
  assert.equal(bad.status, 401);

  const login = await request(port, "POST", "/api/login", { body: { account: "admin", password: "ChangeMe-2026!" } });
  assert.equal(login.status, 200);
  assert.equal(login.json.user.account, "admin");
  const cookie = cookieHeader(login.setCookie);
  assert.match(cookie, /zz_session=/);

  const denied = await request(port, "POST", "/api/receipts", {
    body: { readyStock: false, product_name: "集運主機", qty: 1 }
  });
  assert.equal(denied.status, 401);

  const freightFail = await request(port, "POST", "/api/receipts", {
    cookie,
    body: { readyStock: false, product_name: "集運主機", qty: 1 }
  });
  assert.equal(freightFail.status, 400);

  const receipt = await request(port, "POST", "/api/receipts", {
    cookie,
    body: {
      readyStock: true,
      product_name: "張張到貨主機",
      barcode: "ZZTEST001",
      color: "黑",
      cost: 1500,
      qty: 3,
      warehouse: "電腦倉",
      shelf: "B01",
      note: "現貨免單號"
    }
  });
  assert.equal(receipt.status, 200, JSON.stringify(receipt.json));
  assert.equal(receipt.json.data.readyStock, true);
  assert.equal(receipt.json.data.logisticsNo, "");
  assert.equal(receipt.json.data.items[0].qty, 3);

  const products = await request(port, "GET", "/api/products", { cookie });
  const found = products.json.data.find((p) => p.barcode === "ZZTEST001");
  assert.ok(found);
  assert.equal(found.stock_total, 3);
  assert.equal(found.import_source, "張張電腦已帶入");

  const overview = await request(port, "GET", "/api/overview", { cookie });
  assert.ok(overview.json.data.receipts >= 2);
  assert.ok(overview.json.data.inStock >= 1);

  const home = await request(port, "GET", "/");
  assert.equal(home.status, 200);
  assert.match(home.json.raw || "", /張張管理後台/);

  const imported = await request(port, "POST", "/api/import", {
    cookie,
    body: {
      members: [{ id: "mem_import_1", name: "匯入買家", phone: "0900000000", blacklist_status: "正常" }]
    }
  });
  assert.equal(imported.status, 200);
  assert.equal(imported.json.data.members.added, 1);

  const settlement = await request(port, "POST", "/api/settlements", {
    cookie,
    body: {
      productId: "ZZTEST001",
      title: "張張到貨主機",
      memberId: "mem_import_1",
      memberName: "匯入買家",
      qty: 1,
      amount: 2000,
      paid_amount: 2000,
      status: "已收款待出貨",
      carrier: "7-11"
    }
  });
  assert.equal(settlement.status, 200);
  const shipped = await request(port, "PATCH", `/api/settlements/${settlement.json.data.id}`, {
    cookie,
    body: { status: "已出貨", trackingNo: "88888888" }
  });
  assert.equal(shipped.status, 200);
  assert.equal(shipped.json.data.status, "已出貨");
});
