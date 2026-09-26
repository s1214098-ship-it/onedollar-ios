(function () {
  "use strict";

  var STYLE_ID = "baohui-hide-codex-report-style";
  var HIDDEN_PAGE = "codexReport";
  var FALLBACK_PAGE = "dashboard";

  function injectStyle() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = [
      "#codexReportDashboardCard,",
      "#codexChangeNoticeDashboardCard,",
      "#page-codexReport,",
      'a[data-page="codexReport"],',
      ".col-md-4:has(#perm_codexReport),",
      ".form-check:has(#perm_codexReport),",
      "#perm_codexReport,",
      "#perm_codexReport + label {",
      "  display: none !important;",
      "}"
    ].join("\n");
    (document.head || document.documentElement).appendChild(style);
  }

  function hideClosest(el, selector) {
    var wrap = el && el.closest ? el.closest(selector) : null;
    if (!wrap) return;
    wrap.style.setProperty("display", "none", "important");
    wrap.setAttribute("hidden", "");
  }

  function hideNodes() {
    [
      "#codexReportDashboardCard",
      "#codexChangeNoticeDashboardCard",
      "#page-codexReport",
      'a[data-page="codexReport"]',
      "#perm_codexReport"
    ].forEach(function (sel) {
      var nodes = document.querySelectorAll(sel);
      Array.prototype.forEach.call(nodes, function (el) {
        el.style.setProperty("display", "none", "important");
        el.setAttribute("hidden", "");
        el.classList.add("page-hide");
        if (el.id === "perm_codexReport") {
          hideClosest(el, ".col-md-4");
          hideClosest(el, ".form-check");
        }
      });
    });
  }

  function wrapGoPage() {
    if (typeof window.goPage !== "function") return;
    if (window.goPage.__baohuiHideCodex) return;
    var orig = window.goPage;
    function wrapped(pg, options) {
      if (pg === HIDDEN_PAGE) pg = FALLBACK_PAGE;
      return orig.call(this, pg, options);
    }
    wrapped.__baohuiHideCodex = true;
    try {
      Object.keys(orig).forEach(function (key) { wrapped[key] = orig[key]; });
    } catch (e) {}
    window.goPage = wrapped;
  }

  function requestedPage() {
    try {
      return String(window.location.hash || "").replace(/^#/, "").split(/[?&/]/)[0];
    } catch (e) {
      return "";
    }
  }

  function bounceHash() {
    if (requestedPage() !== HIDDEN_PAGE) return;
    if (typeof window.goPage === "function") {
      window.goPage(FALLBACK_PAGE, { skipHistory: true, skipMemoApproval: true });
      return;
    }
    try { history.replaceState(null, "", "#" + FALLBACK_PAGE); } catch (e) {}
  }

  function apply() {
    injectStyle();
    hideNodes();
    wrapGoPage();
    bounceHash();
  }

  apply();
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", apply);
  }
  window.addEventListener("hashchange", apply);
})();
