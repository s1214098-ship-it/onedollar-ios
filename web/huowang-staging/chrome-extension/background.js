try { importScripts("config.local.js"); } catch (e) {}
try {
  if (typeof HUOWANG_FB_DEFAULTS === "undefined") importScripts("config.example.js");
} catch (e) {}

var DEFAULTS = typeof HUOWANG_FB_DEFAULTS === "object" && HUOWANG_FB_DEFAULTS
  ? HUOWANG_FB_DEFAULTS
  : { apiBase: "https://huowang.paohui.org", workerKey: "", workerId: "chrome-ext-fengzhi" };
var ALARM_NAME = "huowang-fb-poll";
var tickRunning = false;

function nowIso() {
  return new Date().toISOString();
}

async function saveState(patch) {
  patch.heartbeatAt = nowIso();
  await chrome.storage.local.set(patch);
}

async function getCfg() {
  var stored = await chrome.storage.local.get(["apiBase", "workerKey", "workerId", "enabled"]);
  return {
    apiBase: String(stored.apiBase || DEFAULTS.apiBase || "https://huowang.paohui.org").replace(/\/+$/, ""),
    workerKey: String(stored.workerKey || DEFAULTS.workerKey || ""),
    workerId: String(stored.workerId || DEFAULTS.workerId || "chrome-ext-fengzhi"),
    enabled: stored.enabled !== false
  };
}

async function ensureDefaults() {
  var stored = await chrome.storage.local.get(["apiBase", "workerKey", "workerId", "enabled"]);
  var patch = {};
  if (!stored.apiBase) patch.apiBase = DEFAULTS.apiBase;
  if (!stored.workerKey && DEFAULTS.workerKey) patch.workerKey = DEFAULTS.workerKey;
  if (!stored.workerId) patch.workerId = DEFAULTS.workerId;
  if (typeof stored.enabled === "undefined") patch.enabled = true;
  if (Object.keys(patch).length) await chrome.storage.local.set(patch);
}

async function apiFetch(cfg, path, options) {
  var url = cfg.apiBase + path;
  var headers = Object.assign({
    "X-Facebook-Worker-Key": cfg.workerKey,
    "X-Facebook-Worker-Id": cfg.workerId
  }, (options && options.headers) || {});
  if (options && options.body && !headers["Content-Type"]) headers["Content-Type"] = "application/json";
  var res = await fetch(url, Object.assign({}, options || {}, { headers, cache: "no-store" }));
  var data = null;
  try { data = await res.json(); } catch (e) { data = { ok: false, message: "回傳不是 JSON" }; }
  if (!res.ok || data.ok === false) {
    throw new Error((data && data.message) || ("HTTP " + res.status));
  }
  return data;
}

function sleep(ms) {
  return new Promise(function (resolve) { setTimeout(resolve, ms); });
}

function waitTabComplete(tabId, timeoutMs) {
  return new Promise(function (resolve, reject) {
    var done = false;
    var timer = setTimeout(function () {
      if (done) return;
      done = true;
      chrome.tabs.onUpdated.removeListener(onUpdated);
      reject(new Error("Facebook 頁面載入逾時"));
    }, timeoutMs || 45000);
    function finish() {
      if (done) return;
      done = true;
      clearTimeout(timer);
      chrome.tabs.onUpdated.removeListener(onUpdated);
      resolve();
    }
    function onUpdated(id, info) {
      if (id === tabId && info.status === "complete") finish();
    }
    chrome.tabs.onUpdated.addListener(onUpdated);
    chrome.tabs.get(tabId).then(function (tab) {
      if (tab && tab.status === "complete") finish();
    }).catch(function () {});
  });
}

function urlsMatch(a, b) {
  try {
    var ua = new URL(a);
    var ub = new URL(b);
    var hostA = ua.hostname.replace(/^www\./, "");
    var hostB = ub.hostname.replace(/^www\./, "");
    if (hostA !== hostB) return false;
    var pa = ua.pathname.replace(/\/+$/, "") || "/";
    var pb = ub.pathname.replace(/\/+$/, "") || "/";
    if (pa !== pb) return false;
    if (ub.searchParams.get("id")) return ua.searchParams.get("id") === ub.searchParams.get("id");
    return true;
  } catch (e) {
    return String(a || "").replace(/\/+$/, "") === String(b || "").replace(/\/+$/, "");
  }
}

async function openFacebookTab(url, lastTabId) {
  var existing = [];
  try {
    existing = await chrome.tabs.query({ url: ["https://www.facebook.com/*", "https://web.facebook.com/*"] });
  } catch (e) {
    existing = [];
  }
  var tab = existing.find(function (item) { return item.id === lastTabId; }) || existing[0];
  if (tab && tab.id) {
    var patch = { active: true };
    if (!urlsMatch(tab.url || "", url)) patch.url = url;
    await chrome.tabs.update(tab.id, patch);
    if (tab.windowId) {
      try { await chrome.windows.update(tab.windowId, { focused: true }); } catch (e) {}
    }
    return tab.id;
  }
  var created = await chrome.tabs.create({ url: url, active: true });
  return created.id;
}

async function runOnTab(tabId, job, mode) {
  await chrome.scripting.executeScript({
    target: { tabId: tabId },
    files: ["composer.js"]
  });
  var injected = await chrome.scripting.executeScript({
    target: { tabId: tabId },
    func: function (payload, runMode) {
      if (!self.HuowangFbComposer || typeof self.HuowangFbComposer.run !== "function") {
        return { ok: false, status: "pending_review", note: "發文腳本沒有載入" };
      }
      return self.HuowangFbComposer.run(payload, runMode);
    },
    args: [job, mode || "post"]
  });
  return (injected && injected[0] && injected[0].result) || { ok: false, status: "pending_review", note: "沒有回傳結果" };
}

async function claimAndPost(cfg) {
  var data = await apiFetch(cfg, "/api/facebook_publish_worker.php?action=claim&workerId=" + encodeURIComponent(cfg.workerId));
  var job = data && data.job;
  if (!job || !job.id) {
    await saveState({ lastClaimAt: nowIso(), lastJobTitle: "", lastError: "" });
    return { claimed: false };
  }
  await saveState({
    lastClaimAt: nowIso(),
    lastJobId: job.id,
    lastJobTitle: (job.title || "") + " → " + (job.destinationName || ""),
    lastError: "",
    busyUntil: Date.now() + 180000
  });
  var dest = String(job.destinationUrl || "").trim();
  if (!dest) {
    await reportJob(cfg, job.id, "failed", "缺少 Facebook 網址", "");
    return { claimed: true, reported: "failed" };
  }
  var stored = await chrome.storage.local.get(["lastTabId"]);
  var tabId = await openFacebookTab(dest, stored.lastTabId);
  await chrome.storage.local.set({ lastTabId: tabId });
  try {
    await waitTabComplete(tabId, 45000);
  } catch (e) {}
  await sleep(2500);
  var result = await runOnTab(tabId, job, "post");
  var status = result.status || (result.ok ? "published" : "pending_review");
  if (["published", "pending_review", "blocked", "failed"].indexOf(status) < 0) status = "pending_review";
  await reportJob(cfg, job.id, status, result.note || "", result.postUrl || "");
  await saveState({
    lastResult: status,
    lastNote: result.note || "",
    busyUntil: Date.now() + 5000
  });
  return { claimed: true, reported: status, result: result };
}

async function reportJob(cfg, jobId, status, note, postUrl) {
  await apiFetch(cfg, "/api/facebook_publish_worker.php", {
    method: "POST",
    body: JSON.stringify({
      action: "report",
      jobId: jobId,
      status: status,
      note: note || "",
      postUrl: postUrl || "",
      workerId: cfg.workerId
    })
  });
}

async function claimAndJoin(cfg) {
  var data = await apiFetch(cfg, "/api/facebook_group_candidates.php?action=claim&key=" + encodeURIComponent(cfg.workerKey));
  var job = data && data.job;
  if (!job || !job.id) return { claimed: false };
  await saveState({
    lastClaimAt: nowIso(),
    lastJobTitle: "加入社團 " + (job.name || job.id),
    lastError: "",
    busyUntil: Date.now() + 120000
  });
  var stored = await chrome.storage.local.get(["lastTabId"]);
  var tabId = await openFacebookTab(job.url, stored.lastTabId);
  await chrome.storage.local.set({ lastTabId: tabId });
  try { await waitTabComplete(tabId, 45000); } catch (e) {}
  await sleep(2000);
  var result = await runOnTab(tabId, job, "join");
  var mapped = result.result || (result.ok ? "joined" : "needs_manual");
  await apiFetch(cfg, "/api/facebook_group_candidates.php", {
    method: "POST",
    body: JSON.stringify({
      action: "worker_report",
      key: cfg.workerKey,
      id: job.id,
      result: mapped,
      message: result.note || ""
    })
  });
  await saveState({ lastResult: mapped, lastNote: result.note || "", busyUntil: Date.now() + 5000 });
  return { claimed: true, reported: mapped };
}

async function tick(force) {
  if (tickRunning) return { skipped: "busy" };
  tickRunning = true;
  try {
    await ensureDefaults();
    var cfg = await getCfg();
    await saveState({ heartbeatAt: nowIso(), lastTickAt: nowIso() });
    if (!cfg.enabled && !force) {
      await saveState({ lastError: "執行器已暫停（可在彈出視窗打開）" });
      return { skipped: "disabled" };
    }
    if (!cfg.workerKey) {
      await saveState({ lastError: "尚未設定執行器金鑰，請打開選項頁" });
      return { skipped: "no-key" };
    }
    var busy = await chrome.storage.local.get(["busyUntil"]);
    if (!force && busy.busyUntil && Date.now() < Number(busy.busyUntil)) {
      return { skipped: "cooldown" };
    }
    try {
      var joined = await claimAndJoin(cfg);
      if (joined.claimed) return joined;
    } catch (joinErr) {
      await saveState({ lastError: "加入社團：" + joinErr.message });
    }
    return await claimAndPost(cfg);
  } catch (err) {
    await saveState({ lastError: String(err && err.message ? err.message : err) });
    return { error: String(err && err.message ? err.message : err) };
  } finally {
    tickRunning = false;
  }
}

async function startAlarms() {
  await chrome.alarms.clear(ALARM_NAME);
  chrome.alarms.create(ALARM_NAME, { periodInMinutes: 1, delayInMinutes: 0.1 });
  await saveState({ swStartedAt: nowIso(), lastError: "" });
}

chrome.runtime.onInstalled.addListener(function () {
  ensureDefaults().then(startAlarms).then(function () { return tick(false); });
});
chrome.runtime.onStartup.addListener(function () {
  ensureDefaults().then(startAlarms).then(function () { return tick(false); });
});
chrome.alarms.onAlarm.addListener(function (alarm) {
  if (alarm && alarm.name === ALARM_NAME) tick(false);
});
chrome.runtime.onMessage.addListener(function (msg, _sender, sendResponse) {
  if (!msg || !msg.type) return;
  if (msg.type === "ping") {
    saveState({ heartbeatAt: nowIso() }).then(function () { sendResponse({ ok: true, version: "0.5.35" }); });
    return true;
  }
  if (msg.type === "tick-now") {
    tick(true).then(function (result) { sendResponse({ ok: true, result: result }); });
    return true;
  }
  if (msg.type === "get-state") {
    chrome.storage.local.get(null).then(function (state) { sendResponse({ ok: true, state: state, version: "0.5.35" }); });
    return true;
  }
});

self.addEventListener("error", function (event) {
  saveState({ lastError: "SW 錯誤：" + (event && event.message ? event.message : "unknown") });
});
self.addEventListener("unhandledrejection", function (event) {
  var reason = event && event.reason;
  saveState({ lastError: "SW 未處理：" + (reason && reason.message ? reason.message : String(reason || "")) });
});

ensureDefaults().then(startAlarms);
