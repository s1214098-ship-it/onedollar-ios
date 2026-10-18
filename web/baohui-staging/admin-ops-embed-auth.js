(function () {
  "use strict";

  const TICKET_URL = "/ops-embed-auth.php";
  const AUTH_NEEDED = "baohui-ops-auth-needed";
  let pendingTicket = "";

  function withTicket(url, ticket) {
    if (!url || !ticket) return url;
    try {
      const parsed = new URL(url, window.location.origin);
      parsed.searchParams.set("baohui_ticket", ticket);
      return parsed.pathname + parsed.search + parsed.hash;
    } catch (e) {
      const hashIndex = url.indexOf("#");
      const hash = hashIndex >= 0 ? url.slice(hashIndex) : "";
      const base = hashIndex >= 0 ? url.slice(0, hashIndex) : url;
      const joiner = base.indexOf("?") >= 0 ? "&" : "?";
      return base + joiner + "baohui_ticket=" + encodeURIComponent(ticket) + hash;
    }
  }

  async function issueTicket() {
    const res = await fetch(TICKET_URL, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" }
    });
    let payload = {};
    try { payload = await res.json(); } catch (e) {}
    if (!res.ok || !payload || !payload.ok || !payload.ticket) return "";
    return String(payload.ticket);
  }

  function wrapUrlBuilder() {
    if (typeof window.oneDollarWorkspaceUrl !== "function") return;
    if (window.oneDollarWorkspaceUrl.__baohuiOpsAuthWrapped) return;
    const orig = window.oneDollarWorkspaceUrl;
    window.oneDollarWorkspaceUrl = function (meta) {
      const url = orig(meta);
      return pendingTicket ? withTicket(url, pendingTicket) : url;
    };
    window.oneDollarWorkspaceUrl.__baohuiOpsAuthWrapped = true;
  }

  function wrapOpenModule() {
    if (typeof window.openOneDollarModule !== "function") return;
    if (window.openOneDollarModule.__baohuiOpsAuthWrapped) return;
    const orig = window.openOneDollarModule;
    window.openOneDollarModule = function (module, options) {
      const opts = options || {};
      if (opts._baohuiTicketReady) {
        orig(module, opts);
        return;
      }
      wrapUrlBuilder();
      const frame = document.getElementById("oneDollarWorkspaceFrame");
      const currentSrc = frame ? (frame.getAttribute("src") || "") : "";
      const alreadyOps = currentSrc.indexOf("/one-dollar-auction/operations.php") !== -1;
      if (alreadyOps && !opts.forceReload) {
        orig(module, Object.assign({}, opts, { _baohuiTicketReady: true }));
        return;
      }
      issueTicket().then(function (ticket) {
        pendingTicket = ticket || "";
        orig(module, Object.assign({}, opts, { _baohuiTicketReady: true, forceReload: !!ticket || !!opts.forceReload }));
      }).catch(function () {
        pendingTicket = "";
        orig(module, Object.assign({}, opts, { _baohuiTicketReady: true }));
      });
    };
    window.openOneDollarModule.__baohuiOpsAuthWrapped = true;
  }

  function wrapLoginFetch() {
    if (typeof window.login !== "function") return;
    if (window.login.__baohuiOpsAuthWrapped) return;
    const orig = window.login;
    window.login = async function () {
      const nativeFetch = window.fetch;
      window.fetch = function (input, init) {
        const url = typeof input === "string" ? input : (input && input.url) || "";
        init = init ? Object.assign({}, init) : {};
        if (String(url).indexOf("api.php?action=login") !== -1) {
          init.credentials = init.credentials || "same-origin";
        }
        return nativeFetch.call(this, input, init);
      };
      try {
        return await orig.apply(this, arguments);
      } finally {
        window.fetch = nativeFetch;
      }
    };
    window.login.__baohuiOpsAuthWrapped = true;
  }

  function reloadFrameWithTicket(ticket) {
    const frame = document.getElementById("oneDollarWorkspaceFrame");
    if (!frame || !ticket) return;
    const current = frame.getAttribute("src") || "/one-dollar-auction/operations.php?embed=1#stock-search";
    frame.setAttribute("src", withTicket(current, ticket));
  }

  window.addEventListener("message", function (event) {
    const data = event.data || {};
    if (data.type !== AUTH_NEEDED) return;
    issueTicket().then(function (ticket) {
      if (!ticket) return;
      pendingTicket = ticket;
      reloadFrameWithTicket(ticket);
    }).catch(function () {});
  });

  function boot() {
    wrapUrlBuilder();
    wrapOpenModule();
    wrapLoginFetch();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
})();
