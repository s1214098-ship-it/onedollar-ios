"use strict";

/**
 * Convert static admin HTML so JS/CSS are not fetched until asset-boot.php
 * stamps them with file mtime. ASCII-only edits; safe for latin1 files.
 */

const BOOT_SRC = "./asset-boot.php";
const BOOT_TAG = '<script src="' + BOOT_SRC + '"></script>';
const SKIP_NAMES = /backup|拷貝|copy|design-options|dark-indonesia|fresh-concepts|zhangzhang\.html/i;

function shouldTouchFile(name, html) {
  if (!name || SKIP_NAMES.test(name)) return false;
  if (!/\.html$/i.test(name)) return false;
  if (
    !/^(admin|business|sales-)/i.test(name) &&
    !/^(scanner|shipping-watch|stock-inquiry)\.html$/i.test(name)
  ) {
    return false;
  }
  if (!html) return false;
  if (
    html.indexOf("assets/admin.js") === -1 &&
    html.indexOf("assets/admin.css") === -1 &&
    html.indexOf("assets/business.js") === -1 &&
    !/assets\/[^"' ]+\.(?:js|css)\?v=/.test(html)
  ) {
    return false;
  }
  return true;
}

function insertBoot(html) {
  if (html.indexOf(BOOT_SRC) !== -1) return html;
  const re = /<head([^>]*)>/i;
  const m = html.match(re);
  if (!m) return html;
  const nl = html.indexOf("\r\n") !== -1 ? "\r\n" : "\n";
  return html.replace(re, m[0] + nl + "  " + BOOT_TAG);
}

function stripTypeAttr(attrs) {
  return String(attrs || "").replace(/\s*type\s*=\s*(["'])[^"']*\1/gi, "");
}

function convertScripts(html) {
  return html.replace(
    /<script\b([^>]*?)\bsrc=(["'])((?:\.\.\/|\.\/)?assets\/[^"']+\.js)(?:\?v=[^"']*)?\2([^>]*)>/gi,
    function (full, before, quote, src, after) {
      if (/asset-boot\.php/i.test(src) || /asset-boot\.php/i.test(full)) return full;
      if (/type\s*=\s*(["'])lz\/asset\1/i.test(full)) return full;
      const rest = stripTypeAttr(before + " " + after);
      const defer = /\bdefer\b/i.test(full) ? " defer" : "";
      const extra = rest
        .replace(/\bdefer\b/gi, " ")
        .replace(/\bsrc\s*=\s*(["'])[^"']*\1/gi, " ")
        .replace(/\s+/g, " ")
        .trim();
      return (
        '<script type="lz/asset" data-lz-src="' +
        src.replace(/\?v=[^"']*$/, "") +
        '"' +
        defer +
        (extra ? " " + extra : "") +
        ">"
      );
    }
  );
}

function convertStyleLinks(html) {
  return html.replace(
    /<link\b([^>]*?)\bhref=(["'])((?:\.\.\/|\.\/)?assets\/[^"']+\.css)(?:\?v=[^"']*)?\2([^>]*)>/gi,
    function (full, before, quote, href, after) {
      if (/rel\s*=\s*(["'])lz-asset\1/i.test(full)) return full;
      if (!/\brel\s*=\s*(["'])stylesheet\1/i.test(full) && !/\brel\s*=\s*stylesheet\b/i.test(full)) {
        return full;
      }
      const rest = (before + " " + after)
        .replace(/\brel\s*=\s*(["'])[^"']*\1/gi, " ")
        .replace(/\bhref\s*=\s*(["'])[^"']*\1/gi, " ")
        .replace(/\s+/g, " ")
        .trim();
      return (
        '<link rel="lz-asset" data-lz-href="' +
        href.replace(/\?v=[^"']*$/, "") +
        '"' +
        (rest ? " " + rest : "") +
        ">"
      );
    }
  );
}

function transformHtml(html) {
  let next = String(html || "");
  next = insertBoot(next);
  next = convertStyleLinks(next);
  next = convertScripts(next);
  return next;
}

function isTransformed(html) {
  html = String(html || "");
  return html.indexOf(BOOT_SRC) !== -1 && html.indexOf('type="lz/asset"') !== -1;
}

module.exports = {
  BOOT_SRC,
  BOOT_TAG,
  SKIP_NAMES,
  shouldTouchFile,
  insertBoot,
  convertScripts,
  convertStyleLinks,
  transformHtml,
  isTransformed,
};
