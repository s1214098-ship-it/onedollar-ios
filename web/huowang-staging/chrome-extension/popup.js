function render(state, version) {
  var s = state || {};
  var lines = [
    "版本：" + (version || "0.5.35"),
    "Service Worker：運作中（有心跳才算活著）",
    "心跳：" + (s.heartbeatAt || "尚無"),
    "上次領件：" + (s.lastClaimAt || "尚無"),
    "目前任務：" + (s.lastJobTitle || "沒有"),
    "上次結果：" + (s.lastResult || "尚無"),
    s.lastNote ? ("說明：" + s.lastNote) : "",
    s.lastError ? ("錯誤：" + s.lastError) : "錯誤：無"
  ].filter(Boolean);
  var box = document.getElementById("box");
  box.textContent = lines.join("\n");
  box.className = "status " + (s.lastError ? "bad" : "ok");
  document.getElementById("enabled").checked = s.enabled !== false;
}

async function refresh() {
  const res = await chrome.runtime.sendMessage({ type: "get-state" });
  render(res && res.state, res && res.version);
}

document.getElementById("enabled").addEventListener("change", async function () {
  await chrome.storage.local.set({ enabled: document.getElementById("enabled").checked });
  await refresh();
});
document.getElementById("run").addEventListener("click", async function () {
  document.getElementById("box").textContent = "正在領件並發文...";
  await chrome.runtime.sendMessage({ type: "tick-now" });
  setTimeout(refresh, 1200);
});
document.getElementById("options").addEventListener("click", function () {
  chrome.runtime.openOptionsPage();
});
chrome.runtime.sendMessage({ type: "ping" });
refresh();
