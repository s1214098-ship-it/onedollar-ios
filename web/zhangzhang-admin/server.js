"use strict";

const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const { URL } = require("url");
const store = require("./lib/store");

const PORT = Number(process.env.PORT || process.env.ZHANGZHANG_PORT || 8788);
const HOST = process.env.HOST || "0.0.0.0";
const PUBLIC_DIR = path.join(__dirname, "public");
const sessions = new Map();
const SESSION_TTL_MS = 24 * 60 * 60 * 1000;

function parseCookies(header) {
  const out = {};
  String(header || "")
    .split(";")
    .forEach((part) => {
      const idx = part.indexOf("=");
      if (idx < 0) return;
      out[part.slice(0, idx).trim()] = decodeURIComponent(part.slice(idx + 1).trim());
    });
  return out;
}

function send(res, status, body, headers) {
  const payload = Buffer.isBuffer(body) ? body : Buffer.from(body == null ? "" : String(body));
  res.writeHead(status, {
    "Content-Length": payload.length,
    "X-Content-Type-Options": "nosniff",
    "Cache-Control": "no-store",
    ...(headers || {})
  });
  res.end(payload);
}

function sendJson(res, status, data, extraHeaders) {
  send(res, status, JSON.stringify(data), {
    "Content-Type": "application/json; charset=utf-8",
    ...(extraHeaders || {})
  });
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > 20 * 1024 * 1024) {
        reject(new Error("payload too large"));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => resolve(Buffer.concat(chunks)));
    req.on("error", reject);
  });
}

function mime(file) {
  const ext = path.extname(file).toLowerCase();
  return (
    {
      ".html": "text/html; charset=utf-8",
      ".css": "text/css; charset=utf-8",
      ".js": "application/javascript; charset=utf-8",
      ".json": "application/json; charset=utf-8",
      ".svg": "image/svg+xml",
      ".png": "image/png",
      ".jpg": "image/jpeg",
      ".ico": "image/x-icon"
    }[ext] || "application/octet-stream"
  );
}

function publicUser(user) {
  if (!user) return null;
  return {
    id: user.id,
    name: user.name,
    account: user.account,
    role: user.role,
    must_change_password: !!user.must_change_password
  };
}

function getSession(req) {
  const token = parseCookies(req.headers.cookie).zz_session;
  if (!token) return null;
  const session = sessions.get(token);
  if (!session || session.exp < Date.now()) {
    sessions.delete(token);
    return null;
  }
  return session;
}

function requireUser(req, res) {
  const session = getSession(req);
  if (!session) {
    sendJson(res, 401, { ok: false, error: "請先登入張張管理後台" });
    return null;
  }
  const db = store.loadDb();
  const user = db.users.find((u) => u.id === session.userId && u.active !== false);
  if (!user) {
    sendJson(res, 401, { ok: false, error: "登入已失效" });
    return null;
  }
  return { db, user, session };
}

function setSessionCookie(res, token) {
  return `zz_session=${encodeURIComponent(token)}; HttpOnly; Path=/; SameSite=Lax; Max-Age=${Math.floor(SESSION_TTL_MS / 1000)}`;
}

function clearSessionCookie() {
  return "zz_session=; HttpOnly; Path=/; SameSite=Lax; Max-Age=0";
}

function serveStatic(req, res, url) {
  let rel = decodeURIComponent(url.pathname);
  if (rel === "/") rel = "/index.html";
  const file = path.normalize(path.join(PUBLIC_DIR, rel));
  if (!file.startsWith(PUBLIC_DIR)) {
    send(res, 403, "Forbidden");
    return true;
  }
  if (!fs.existsSync(file) || fs.statSync(file).isDirectory()) return false;
  send(res, 200, fs.readFileSync(file), {
    "Content-Type": mime(file),
    "Cache-Control": rel === "/index.html" ? "no-store" : "public, max-age=60"
  });
  return true;
}

async function handleApi(req, res, url) {
  const method = req.method || "GET";
  const pathname = url.pathname;

  if (method === "GET" && pathname === "/api/health") {
    sendJson(res, 200, { ok: true, name: "張張管理後台", port: PORT });
    return;
  }

  if (method === "POST" && pathname === "/api/login") {
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    const db = store.loadDb();
    const user = db.users.find((u) => u.account === String(body.account || "").trim());
    if (!user || user.active === false || !store.verifyPassword(body.password, user.password)) {
      sendJson(res, 401, { ok: false, error: "帳號或密碼錯誤" });
      return;
    }
    const token = crypto.randomBytes(24).toString("hex");
    sessions.set(token, { userId: user.id, exp: Date.now() + SESSION_TTL_MS });
    store.pushLog(db, user.account, "login", "session", "登入張張管理後台");
    store.saveDb(db);
    sendJson(res, 200, { ok: true, user: publicUser(user) }, { "Set-Cookie": setSessionCookie(res, token) });
    return;
  }

  if (method === "POST" && pathname === "/api/logout") {
    const token = parseCookies(req.headers.cookie).zz_session;
    if (token) sessions.delete(token);
    sendJson(res, 200, { ok: true }, { "Set-Cookie": clearSessionCookie() });
    return;
  }

  const ctx = requireUser(req, res);
  if (!ctx) return;
  const { db, user } = ctx;

  if (method === "GET" && pathname === "/api/me") {
    sendJson(res, 200, { ok: true, user: publicUser(user) });
    return;
  }

  if (method === "GET" && pathname === "/api/overview") {
    sendJson(res, 200, { ok: true, data: store.overview(db) });
    return;
  }

  if (method === "GET" && pathname === "/api/analytics") {
    sendJson(res, 200, { ok: true, data: store.analytics(db) });
    return;
  }

  if (method === "GET" && pathname === "/api/logs") {
    sendJson(res, 200, { ok: true, data: db.logs.slice(0, 200) });
    return;
  }

  const collections = {
    "/api/products": "products",
    "/api/members": "members",
    "/api/suppliers": "suppliers",
    "/api/warehouses": "warehouses",
    "/api/schedules": "schedules",
    "/api/settlements": "settlements",
    "/api/receipts": "receipts",
    "/api/stock": "stockMoves"
  };

  if (method === "GET" && collections[pathname]) {
    let rows = db[collections[pathname]];
    if (pathname === "/api/products") rows = rows.map((p) => store.refreshProductStock(db, p));
    const q = (url.searchParams.get("q") || "").trim().toLowerCase();
    if (q) {
      rows = rows.filter((row) => JSON.stringify(row).toLowerCase().includes(q));
    }
    sendJson(res, 200, { ok: true, data: rows });
    return;
  }

  if (method === "POST" && pathname === "/api/products") {
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    const item = {
      id: body.barcode || body.id || store.id("p"),
      barcode: body.barcode || body.id || "",
      title: body.title || `${body.product_name || "未命名"} - ${body.color || ""}`.trim(),
      product_name: body.product_name || body.title || "未命名商品",
      main_category: body.main_category || "電腦",
      department: body.department || "電腦部門",
      category_type: body.category_type || body.main_category || "電腦",
      color: body.color || "",
      spec: body.spec || "",
      cost: Number(body.cost || 0),
      warehouse_name: body.warehouse_name || "沒在貨架上",
      shelf_code: body.shelf_code || "",
      warehouse_location: body.warehouse_location || "",
      status: body.status || "可排程",
      image: body.image || "",
      created_at: store.nowIso(),
      updated_at: store.nowIso(),
      updated_by: user.account
    };
    db.products.unshift(item);
    store.pushLog(db, user.account, "create", "product", item.barcode);
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: store.refreshProductStock(db, item) });
    return;
  }

  const productPatch = pathname.match(/^\/api\/products\/([^/]+)$/);
  if (productPatch && (method === "PATCH" || method === "DELETE")) {
    const idx = db.products.findIndex((p) => p.id === decodeURIComponent(productPatch[1]));
    if (idx < 0) {
      sendJson(res, 404, { ok: false, error: "找不到產品" });
      return;
    }
    if (method === "DELETE") {
      const removed = db.products.splice(idx, 1)[0];
      store.pushLog(db, user.account, "delete", "product", removed.barcode);
      store.saveDb(db);
      sendJson(res, 200, { ok: true });
      return;
    }
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    db.products[idx] = { ...db.products[idx], ...body, updated_at: store.nowIso(), updated_by: user.account };
    store.pushLog(db, user.account, "update", "product", db.products[idx].barcode);
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: store.refreshProductStock(db, db.products[idx]) });
    return;
  }

  if (method === "POST" && pathname === "/api/receipts") {
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    const items = Array.isArray(body.items) ? body.items : [body];
    const readyStock = body.readyStock !== false;
    const logisticsNo = String(body.logisticsNo || "").trim();
    if (!readyStock && !logisticsNo) {
      sendJson(res, 400, { ok: false, error: "非現貨入庫需要物流單號" });
      return;
    }
    const savedItems = [];
    for (const raw of items) {
      if (!raw.product_name && !raw.title && !raw.barcode) continue;
      const barcode = String(raw.barcode || store.id("ZZ")).toUpperCase();
      let product = db.products.find((p) => p.barcode === barcode || p.id === barcode);
      if (!product) {
        product = {
          id: barcode,
          barcode,
          title: raw.title || `${raw.product_name || "張張電腦"} - ${raw.color || ""}`.trim(),
          product_name: raw.product_name || raw.title || "張張電腦",
          main_category: raw.main_category || "電腦",
          department: "電腦部門",
          category_type: raw.category_type || raw.main_category || "電腦",
          color: raw.color || "",
          spec: raw.spec || "",
          cost: Number(raw.cost || 0),
          warehouse_name: body.warehouse || raw.warehouse_name || "電腦倉",
          shelf_code: body.shelf || raw.shelf_code || "",
          warehouse_location: body.layer || raw.warehouse_location || "",
          status: "可排程",
          image: raw.image || "",
          import_source: "張張電腦已帶入",
          created_at: store.nowIso(),
          updated_at: store.nowIso(),
          updated_by: user.account
        };
        db.products.unshift(product);
      } else {
        product.updated_at = store.nowIso();
        product.updated_by = user.account;
        if (raw.cost) product.cost = Number(raw.cost);
        if (body.warehouse) product.warehouse_name = body.warehouse;
        if (body.shelf) product.shelf_code = body.shelf;
        if (body.layer) product.warehouse_location = body.layer;
      }
      const qty = Number(raw.qty || 1);
      db.stockMoves.unshift({
        id: store.id("mv"),
        productId: product.id,
        barcode: product.barcode,
        type: "in",
        qty,
        warehouse: body.warehouse || product.warehouse_name || "電腦倉",
        shelf: body.shelf || product.shelf_code || "",
        layer: body.layer || product.warehouse_location || "",
        note: body.note || "張張電腦已帶入，送出即進產品庫",
        logisticsNo: readyStock ? "" : logisticsNo,
        readyStock,
        created_at: store.nowIso(),
        actor: user.account
      });
      savedItems.push({
        productId: product.id,
        barcode: product.barcode,
        title: product.title,
        qty,
        cost: Number(product.cost || 0)
      });
    }
    if (!savedItems.length) {
      sendJson(res, 400, { ok: false, error: "請至少填一筆電腦品項" });
      return;
    }
    const receipt = {
      id: store.id("rc"),
      source: "張張電腦已帶入",
      readyStock,
      logisticsNo: readyStock ? "" : logisticsNo,
      warehouse: body.warehouse || "電腦倉",
      shelf: body.shelf || "",
      layer: body.layer || "",
      note: body.note || "現貨免物流單號，送出即進產品庫。",
      items: savedItems,
      created_at: store.nowIso(),
      actor: user.account
    };
    db.receipts.unshift(receipt);
    store.pushLog(db, user.account, "receipt", receipt.id, `${savedItems.length} 筆已帶入`);
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: receipt });
    return;
  }

  if (method === "POST" && pathname === "/api/stock") {
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    const product = db.products.find((p) => p.id === body.productId || p.barcode === body.barcode);
    if (!product) {
      sendJson(res, 404, { ok: false, error: "找不到產品，請先建檔或用張張電腦已帶入" });
      return;
    }
    const move = {
      id: store.id("mv"),
      productId: product.id,
      barcode: product.barcode,
      type: body.type || "in",
      qty: Number(body.qty || 0),
      warehouse: body.warehouse || product.warehouse_name || "",
      shelf: body.shelf || product.shelf_code || "",
      layer: body.layer || product.warehouse_location || "",
      note: body.note || "",
      logisticsNo: body.logisticsNo || "",
      readyStock: !!body.readyStock,
      created_at: store.nowIso(),
      actor: user.account
    };
    if (!move.qty) {
      sendJson(res, 400, { ok: false, error: "數量不可為 0" });
      return;
    }
    db.stockMoves.unshift(move);
    store.pushLog(db, user.account, "stock", move.type, `${product.barcode} x${move.qty}`);
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: move, product: store.refreshProductStock(db, product) });
    return;
  }

  if (method === "POST" && ["/api/members", "/api/suppliers", "/api/warehouses", "/api/schedules", "/api/settlements"].includes(pathname)) {
    const map = {
      "/api/members": "members",
      "/api/suppliers": "suppliers",
      "/api/warehouses": "warehouses",
      "/api/schedules": "schedules",
      "/api/settlements": "settlements"
    };
    const name = map[pathname];
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    const prefixes = { members: "mem", suppliers: "sup", warehouses: "wh", schedules: "sch", settlements: "st" };
    const item = {
      ...body,
      id: body.id || store.id(prefixes[name]),
      created_at: body.created_at || store.nowIso(),
      updated_at: store.nowIso()
    };
    db[name].unshift(item);
    store.pushLog(db, user.account, "create", name, item.id);
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: item });
    return;
  }

  const crudPatch = pathname.match(/^\/api\/(members|suppliers|warehouses|schedules|settlements)\/([^/]+)$/);
  if (crudPatch && (method === "PATCH" || method === "DELETE")) {
    const name = crudPatch[1];
    const id = decodeURIComponent(crudPatch[2]);
    const idx = db[name].findIndex((row) => row.id === id);
    if (idx < 0) {
      sendJson(res, 404, { ok: false, error: "找不到資料" });
      return;
    }
    if (method === "DELETE") {
      db[name].splice(idx, 1);
      store.pushLog(db, user.account, "delete", name, id);
      store.saveDb(db);
      sendJson(res, 200, { ok: true });
      return;
    }
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    db[name][idx] = { ...db[name][idx], ...body, updated_at: store.nowIso() };
    store.pushLog(db, user.account, "update", name, id);
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: db[name][idx] });
    return;
  }

  if (method === "POST" && pathname === "/api/import") {
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    const result = {};
    for (const [name, rows] of Object.entries(body)) {
      if (name === "ok") continue;
      result[name] = store.importCollection(db, name, rows, user.account);
    }
    store.saveDb(db);
    sendJson(res, 200, { ok: true, data: result });
    return;
  }

  if (method === "GET" && pathname === "/api/backup") {
    sendJson(res, 200, { ok: true, data: db, exported_at: store.nowIso() });
    return;
  }

  if (method === "POST" && pathname === "/api/password") {
    const body = JSON.parse((await readBody(req)).toString("utf8") || "{}");
    if (!store.verifyPassword(body.currentPassword, user.password)) {
      sendJson(res, 400, { ok: false, error: "目前密碼不正確" });
      return;
    }
    if (!body.newPassword || String(body.newPassword).length < 8) {
      sendJson(res, 400, { ok: false, error: "新密碼至少 8 碼" });
      return;
    }
    const idx = db.users.findIndex((u) => u.id === user.id);
    db.users[idx].password = store.hashPassword(body.newPassword);
    db.users[idx].must_change_password = false;
    store.pushLog(db, user.account, "password", user.id, "已修改密碼");
    store.saveDb(db);
    sendJson(res, 200, { ok: true });
    return;
  }

  sendJson(res, 404, { ok: false, error: "找不到 API" });
}

async function onRequest(req, res) {
  try {
    const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);
    if (url.pathname.startsWith("/api/")) {
      await handleApi(req, res, url);
      return;
    }
    if (serveStatic(req, res, url)) return;
    const index = path.join(PUBLIC_DIR, "index.html");
    send(res, 200, fs.readFileSync(index), { "Content-Type": "text/html; charset=utf-8" });
  } catch (error) {
    sendJson(res, 500, { ok: false, error: error.message || "伺服器錯誤" });
  }
}

function createServer() {
  return http.createServer(onRequest);
}

if (require.main === module) {
  const server = createServer();
  server.listen(PORT, HOST, () => {
    process.stdout.write(`張張管理後台已啟動 http://127.0.0.1:${PORT}\n`);
  });
}

module.exports = { createServer, PORT };
