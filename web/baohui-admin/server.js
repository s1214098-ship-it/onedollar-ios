import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { config, ensureDirs } from "./src/config.js";
import { openDb } from "./src/db.js";
import { parseCookies, readCookie } from "./src/crypto.js";
import {
  sendJson,
  sendText,
  serveStatic,
  readBody,
  parseJsonBody,
  parseMultipart
} from "./src/http.js";
import { handleApi, handlePublicCustomer, handlePublicLeave, leaveBalanceFor } from "./src/api.js";
import { getStore } from "./src/db.js";
import { companyStats } from "./src/salary.js";

const PAGE_ALIASES = {
  "/admin": "/admin.html",
  "/admin.php": "/admin.html",
  "/repair": "/repair.html",
  "/repair.php": "/repair.html",
  "/recycle": "/recycle.html",
  "/recycle.php": "/recycle.html",
  "/project": "/project.html",
  "/project.php": "/project.html",
  "/leave": "/leave.html",
  "/leave.php": "/leave.html",
  "/login": "/admin.html"
};

function attachSession(req) {
  const cookies = parseCookies(req.headers.cookie);
  req.session = readCookie(cookies[config.cookieName], config.sessionSecret);
}

function pathnameOf(req) {
  try {
    return new URL(req.url, "http://127.0.0.1").pathname;
  } catch {
    return "/";
  }
}

function queryOf(req) {
  try {
    return Object.fromEntries(new URL(req.url, "http://127.0.0.1").searchParams.entries());
  } catch {
    return {};
  }
}

async function onRequest(req, res) {
  attachSession(req);
  const pathname = pathnameOf(req);
  const query = queryOf(req);
  req.query = query;

  try {
    if (pathname === "/health") {
      const { updatedAt } = getStore();
      return sendJson(res, 200, { ok: true, service: "baohui-admin", updated_at: updatedAt, stats: companyStats(getStore().data) });
    }

    if (pathname === "/api.php" || pathname === "/api") {
      const contentType = String(req.headers["content-type"] || "");
      let body = {};
      let files = [];
      if (req.method !== "GET" && req.method !== "HEAD") {
        const buf = await readBody(req);
        if (contentType.includes("multipart/form-data")) {
          const parsed = parseMultipart(buf, contentType);
          body = parsed.fields;
          files = parsed.files;
        } else if (buf.length) {
          body = parseJsonBody(buf);
        }
      }
      const action = query.action || body.action || "";
      return handleApi(req, res, { action, body, files });
    }

    if (pathname.startsWith("/customer-api/")) {
      const kind = pathname.replace("/customer-api/", "").replace(/\/$/, "");
      if (req.method !== "POST") return sendJson(res, 405, { ok: false, error: "請使用 POST" });
      const body = parseJsonBody(await readBody(req));
      const result = handlePublicCustomer(kind, body);
      return sendJson(res, 200, result);
    }

    if (pathname === "/leave-api" || pathname === "/api/leave") {
      if (req.method === "GET" && query.employee) {
        const row = leaveBalanceFor(query.employee);
        if (!row) return sendJson(res, 404, { ok: false, error: "找不到員工" });
        return sendJson(res, 200, { ok: true, ...row });
      }
      if (req.method !== "POST") return sendJson(res, 405, { ok: false, error: "請使用 POST" });
      const body = parseJsonBody(await readBody(req));
      const result = handlePublicLeave(body, req.session);
      return sendJson(res, 200, result);
    }

    if (pathname.startsWith("/uploads/")) {
      const file = path.join(config.dataDir, "uploads", path.basename(pathname));
      if (!fs.existsSync(file)) return sendText(res, 404, "Not found", "text/plain; charset=utf-8");
      const stream = fs.createReadStream(file);
      res.writeHead(200);
      return stream.pipe(res);
    }

    const mapped = PAGE_ALIASES[pathname] || pathname;
    if (serveStatic(res, mapped, [path.join(config.dataDir)])) return;
    if (pathname.endsWith(".php") && serveStatic(res, pathname.replace(/\.php$/, ".html"))) return;
    sendText(res, 404, "Not found", "text/plain; charset=utf-8");
  } catch (err) {
    const map = { UNAUTHORIZED: 401, FORBIDDEN: 403, CONFLICT: 409, BAD_REQUEST: 400, TOO_LARGE: 413 };
    const status = map[err.code] || 500;
    sendJson(res, status, { ok: false, error: err.message || "伺服器錯誤" });
  }
}

export function createServer() {
  ensureDirs();
  openDb();
  return http.createServer(onRequest);
}

if (import.meta.url === `file://${process.argv[1]}` || process.argv[1]?.endsWith("server.js")) {
  const server = createServer();
  server.listen(config.port, config.host, () => {
    console.log(`寶輝管理後台已啟動 http://${config.host === "0.0.0.0" ? "127.0.0.1" : config.host}:${config.port}`);
    console.log(`管理登入：${config.adminUser} ／ 公開報修：/repair  回收：/recycle`);
  });
}
