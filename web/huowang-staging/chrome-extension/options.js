async function load() {
  const s = await chrome.storage.local.get(["apiBase", "workerKey", "workerId"]);
  document.getElementById("apiBase").value = s.apiBase || "https://huowang.paohui.org";
  document.getElementById("workerKey").value = s.workerKey || "";
  document.getElementById("workerId").value = s.workerId || "chrome-ext-fengzhi";
}
document.getElementById("save").addEventListener("click", async function () {
  await chrome.storage.local.set({
    apiBase: document.getElementById("apiBase").value.trim().replace(/\/+$/, "") || "https://huowang.paohui.org",
    workerKey: document.getElementById("workerKey").value.trim(),
    workerId: document.getElementById("workerId").value.trim() || "chrome-ext-fengzhi"
  });
  document.getElementById("msg").textContent = "已儲存，擴充功能會在下一分鐘自動領件。";
  chrome.runtime.sendMessage({ type: "tick-now" });
});
load();
