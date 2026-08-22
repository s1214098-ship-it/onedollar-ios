<?php
declare(strict_types=1);

/**
 * Blocking, no-cache boot script: stamp JS/CSS with file mtime and
 * auto-reload when the running page is behind.
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . DIRECTORY_SEPARATOR . 'asset-version-lib.php';

$manifest = lz_asset_manifest(__DIR__);
$json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
if ($json === false) {
    $json = '{"ok":false,"v":"0","files":{}}';
}
?>
(function (manifest) {
  if (!manifest || window.__LZ_ASSET_BOOT) return;
  window.__LZ_ASSET_BOOT = true;
  window.__LZ_ASSET = manifest;
  var files = manifest.files || {};
  var currentV = String(manifest.v || "0");

  function fileName(path) {
    var clean = String(path || "").split("?")[0];
    var parts = clean.split("/");
    return parts[parts.length - 1] || "";
  }

  function stamped(path) {
    var name = fileName(path);
    var ver = files[name] || currentV || String(Date.now());
    return String(path || "").split("?")[0] + "?v=" + ver;
  }

  function copyDataAttrs(from, to) {
    if (!from || !from.attributes) return;
    for (var i = 0; i < from.attributes.length; i += 1) {
      var attr = from.attributes[i];
      if (!attr || !attr.name) continue;
      if (attr.name === "src" || attr.name === "href" || attr.name === "type" || attr.name === "rel" || attr.name === "defer" || attr.name.indexOf("data-lz-") === 0) continue;
      try { to.setAttribute(attr.name, attr.value); } catch (e) {}
    }
  }

  function upgradeLink(el) {
    if (!el || el.getAttribute("data-lz-upgraded") === "1") return;
    var href = el.getAttribute("data-lz-href");
    if (!href) return;
    var link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = stamped(href);
    copyDataAttrs(el, link);
    el.setAttribute("data-lz-upgraded", "1");
    if (el.parentNode) el.parentNode.insertBefore(link, el);
  }

  function watch() {
    if (!document.documentElement || typeof MutationObserver !== "function") return;
    var obs = new MutationObserver(function (muts) {
      for (var i = 0; i < muts.length; i += 1) {
        var nodes = muts[i].addedNodes || [];
        for (var n = 0; n < nodes.length; n += 1) {
          var node = nodes[n];
          if (!node || node.nodeType !== 1) continue;
          if (node.matches && node.matches('link[rel="lz-asset"][data-lz-href]')) upgradeLink(node);
          if (node.querySelectorAll) {
            var found = node.querySelectorAll('link[rel="lz-asset"][data-lz-href]');
            for (var f = 0; f < found.length; f += 1) upgradeLink(found[f]);
          }
        }
      }
    });
    obs.observe(document.documentElement, { childList: true, subtree: true });
  }

  function loadScripts() {
    var queue = Array.prototype.slice.call(document.querySelectorAll('script[type="lz/asset"][data-lz-src]'));
    function next() {
      var el = queue.shift();
      if (!el) return;
      var s = document.createElement("script");
      s.src = stamped(el.getAttribute("data-lz-src"));
      copyDataAttrs(el, s);
      s.onload = s.onerror = next;
      if (el.parentNode) el.parentNode.insertBefore(s, el);
      else (document.body || document.documentElement).appendChild(s);
    }
    next();
  }

  function applyUpdate() {
    if (window.__LZ_ASSET_RELOADING) return;
    window.__LZ_ASSET_RELOADING = true;
    if (!document.getElementById("lz-asset-refresh-banner")) {
      var bar = document.createElement("div");
      bar.id = "lz-asset-refresh-banner";
      bar.setAttribute("role", "status");
      bar.style.cssText = "position:fixed;top:0;left:0;right:0;z-index:2147483000;padding:10px 16px;background:#241b27;color:#fff;font:600 14px/1.4 sans-serif;text-align:center;";
      bar.textContent = "後台已更新，正在自動套用…";
      (document.body || document.documentElement).appendChild(bar);
    }
    location.reload();
  }

  function checkForUpdate() {
    fetch("./asset-version.php", { cache: "no-store" })
      .then(function (res) { return res.json(); })
      .then(function (next) {
        if (next && next.v && String(next.v) !== currentV) applyUpdate();
      })
      .catch(function () {});
  }

  watch();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", loadScripts);
  } else {
    loadScripts();
  }

  setTimeout(checkForUpdate, 1200);
  setInterval(checkForUpdate, 8000);
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible") checkForUpdate();
  });
  window.addEventListener("focus", checkForUpdate);
})(<?php echo $json; ?>);
