import fs from "node:fs";
import path from "node:path";
import { PUBLIC_DIR } from "./config.js";

export function sendJson(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
    "X-Content-Type-Options": "nosniff"
  });
  res.end(payload);
}

export function sendText(res, status, text, contentType = "text/plain; charset=utf-8") {
  res.writeHead(status, { "Content-Type": contentType, "Cache-Control": "no-store" });
  res.end(text);
}

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".webp": "image/webp",
  ".gif": "image/gif",
  ".svg": "image/svg+xml",
  ".ico": "image/x-icon",
  ".pdf": "application/pdf",
  ".webmanifest": "application/manifest+json"
};

export function safeJoin(root, requestPath) {
  const decoded = decodeURIComponent(requestPath.split("?")[0]);
  const cleaned = path.normalize(decoded).replace(/^(\.\.[/\\])+/, "");
  const full = path.join(root, cleaned);
  if (!full.startsWith(root)) return null;
  return full;
}

export function serveStatic(res, requestPath, extraRoots = []) {
  const rel = requestPath === "/" ? "/index.html" : requestPath;
  const roots = [PUBLIC_DIR, ...extraRoots];
  for (const root of roots) {
    const file = safeJoin(root, rel);
    if (!file) continue;
    let target = file;
    if (fs.existsSync(target) && fs.statSync(target).isDirectory()) {
      target = path.join(target, "index.html");
    }
    if (!fs.existsSync(target) || !fs.statSync(target).isFile()) continue;
    const ext = path.extname(target).toLowerCase();
    res.writeHead(200, { "Content-Type": MIME[ext] || "application/octet-stream" });
    fs.createReadStream(target).pipe(res);
    return true;
  }
  return false;
}

export async function readBody(req, limit = 8 * 1024 * 1024) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > limit) {
      const err = new Error("payload too large");
      err.code = "TOO_LARGE";
      throw err;
    }
    chunks.push(chunk);
  }
  return Buffer.concat(chunks);
}

export function parseJsonBody(buf) {
  if (!buf || !buf.length) return {};
  try {
    return JSON.parse(buf.toString("utf8"));
  } catch {
    const err = new Error("JSON 格式錯誤");
    err.code = "BAD_REQUEST";
    throw err;
  }
}

export function parseMultipart(buf, contentType) {
  const match = String(contentType || "").match(/boundary=([^;]+)/i);
  if (!match) return { fields: {}, files: [] };
  const boundary = match[1].trim().replace(/^"|"$/g, "");
  const raw = buf.toString("latin1");
  const parts = raw.split(`--${boundary}`).slice(1);
  const fields = {};
  const files = [];
  for (const part of parts) {
    if (part === "--\r\n" || part === "--") continue;
    const headerEnd = part.indexOf("\r\n\r\n");
    if (headerEnd < 0) continue;
    const header = part.slice(0, headerEnd);
    let body = part.slice(headerEnd + 4);
    if (body.endsWith("\r\n")) body = body.slice(0, -2);
    const nameMatch = header.match(/name="([^"]+)"/i);
    const fileMatch = header.match(/filename="([^"]*)"/i);
    const mimeMatch = header.match(/Content-Type:\s*([^\r\n]+)/i);
    const name = nameMatch ? nameMatch[1] : "";
    if (fileMatch) {
      files.push({
        field: name,
        filename: fileMatch[1] || "upload.bin",
        mime: mimeMatch ? mimeMatch[1].trim() : "application/octet-stream",
        buffer: Buffer.from(body, "latin1")
      });
    } else if (name) {
      fields[name] = Buffer.from(body, "latin1").toString("utf8");
    }
  }
  return { fields, files };
}

export function cookieHeader(name, value, { maxAgeSec, httpOnly = true } = {}) {
  const parts = [`${name}=${encodeURIComponent(value)}`, "Path=/", "SameSite=Lax"];
  if (httpOnly) parts.push("HttpOnly");
  if (maxAgeSec) parts.push(`Max-Age=${maxAgeSec}`);
  return parts.join("; ");
}

export function clearCookieHeader(name) {
  return `${name}=; Path=/; Max-Age=0; SameSite=Lax; HttpOnly`;
}
