var HuowangFbComposer = (function () {
  function sleep(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  }

  function visible(el) {
    if (!el) return false;
    var st = window.getComputedStyle(el);
    if (st.display === "none" || st.visibility === "hidden" || Number(st.opacity) === 0) return false;
    var r = el.getBoundingClientRect();
    return r.width > 8 && r.height > 8;
  }

  function textOf(el) {
    if (!el) return "";
    return String(el.innerText || el.getAttribute("aria-label") || el.getAttribute("placeholder") || "").replace(/\s+/g, " ").trim();
  }

  function isDismissLabel(t) {
    t = String(t || "").toLowerCase();
    return /關閉|close|cancel|取消|not now|稍後|略過|skip|dismiss/.test(t);
  }

  function clickEl(el) {
    if (!el) return false;
    try {
      var opts = { bubbles: true, cancelable: true, view: window, composed: true };
      el.dispatchEvent(new PointerEvent("pointerdown", opts));
      el.dispatchEvent(new MouseEvent("mousedown", opts));
      el.dispatchEvent(new PointerEvent("pointerup", opts));
      el.dispatchEvent(new MouseEvent("mouseup", opts));
      el.dispatchEvent(new MouseEvent("click", opts));
      return true;
    } catch (e) {
      try { el.click(); return true; } catch (err) { return false; }
    }
  }

  function findByLabels(root, labels, selector) {
    var wanted = labels.map(function (x) { return String(x).toLowerCase(); });
    var nodes = (root || document).querySelectorAll(selector || "div[role=button], span[role=button], [role=textbox], button, a[role=link], div[aria-label], span[aria-label]");
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var t = textOf(el);
      var low = t.toLowerCase();
      if (!low || isDismissLabel(low)) continue;
      for (var j = 0; j < wanted.length; j++) {
        if (low === wanted[j] || (wanted[j].length >= 4 && low.indexOf(wanted[j]) >= 0)) {
          if (visible(el) || el.getAttribute("aria-label")) return el;
        }
      }
    }
    return null;
  }

  function showBanner(text) {
    var old = document.getElementById("huowang-fb-banner");
    if (old) old.remove();
    var box = document.createElement("div");
    box.id = "huowang-fb-banner";
    box.textContent = text;
    box.style.cssText = "position:fixed;z-index:2147483647;left:16px;right:16px;top:12px;background:#0f766e;color:#fff;font:700 16px/1.4 sans-serif;padding:12px 16px;border-radius:10px;box-shadow:0 8px 24px #0006;pointer-events:none";
    document.documentElement.appendChild(box);
    setTimeout(function () { if (box.parentNode) box.remove(); }, 25000);
  }

  function looksLoggedOut() {
    var t = document.body ? document.body.innerText : "";
    return /登入 Facebook|Log in to Facebook|Forgot password|忘記密碼/.test(t) && !document.querySelector("[role=feed], [role=main] [contenteditable=true]");
  }

  function dialogRoot() {
    var dialogs = document.querySelectorAll("[role=dialog]");
    for (var i = dialogs.length - 1; i >= 0; i--) {
      if (!visible(dialogs[i])) continue;
      if (dialogs[i].querySelector("[contenteditable=true]")) return dialogs[i];
    }
    for (var j = dialogs.length - 1; j >= 0; j--) {
      if (visible(dialogs[j])) return dialogs[j];
    }
    return null;
  }

  function composerBox() {
    var dialog = dialogRoot();
    if (!dialog) return null;
    return dialog.querySelector("[contenteditable=true][role=textbox]")
      || dialog.querySelector("[aria-label*='建立公開貼文']")
      || dialog.querySelector("[aria-label*='Create a public post']")
      || dialog.querySelector("[contenteditable=true]");
  }

  function snippet(text) {
    return String(text || "").replace(/\s+/g, " ").trim().slice(0, 16);
  }

  function hasPostText(text) {
    var dialog = dialogRoot();
    var box = composerBox();
    var hay = ((box && (box.innerText || box.textContent)) || "") + "\n" + ((dialog && dialog.innerText) || "");
    var needle = snippet(text);
    if (!needle) return false;
    return hay.replace(/\s+/g, " ").indexOf(needle) >= 0;
  }

  async function waitForDialogBox(timeoutMs) {
    var start = Date.now();
    var stable = 0;
    while (Date.now() - start < (timeoutMs || 12000)) {
      var box = composerBox();
      if (box && visible(box) && dialogRoot()) {
        stable += 1;
        if (stable >= 3) return box;
      } else {
        stable = 0;
      }
      await sleep(300);
    }
    return composerBox();
  }

  async function openComposer() {
    if (composerBox()) return waitForDialogBox(3000);
    var labels = [
      "撰寫貼文", "寫點什麼", "Write something", "What's on your mind", "What’s on your mind",
      "建立貼文", "Create post", "Create a post", "在想什麼", "有什麼新鮮事"
    ];
    var opener = findByLabels(document, labels)
      || document.querySelector("[aria-label*='撰寫貼文']")
      || document.querySelector("[aria-label*='Write something']")
      || document.querySelector("[aria-label*='Create a post']")
      || document.querySelector("[aria-label*='建立貼文']")
      || document.querySelector("[aria-label*='在想什麼']");
    if (opener) clickEl(opener);
    var box = await waitForDialogBox(12000);
    if (box) return box;
    if (opener) clickEl(opener);
    return waitForDialogBox(8000);
  }

  async function pasteInto(box, text) {
    box.focus();
    await sleep(180);
    try {
      document.execCommand("selectAll", false, null);
      document.execCommand("delete", false, null);
    } catch (e) {}
    var ok = false;
    try { ok = document.execCommand("insertText", false, text); } catch (e) { ok = false; }
    try {
      var dt = new DataTransfer();
      dt.setData("text/plain", text);
      box.dispatchEvent(new ClipboardEvent("paste", { clipboardData: dt, bubbles: true, cancelable: true }));
    } catch (e) {}
    try {
      box.dispatchEvent(new InputEvent("beforeinput", { bubbles: true, cancelable: true, inputType: "insertFromPaste", data: text }));
      box.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertFromPaste", data: text }));
    } catch (e) {}
    if (!ok) {
      try {
        await navigator.clipboard.writeText(text);
        document.execCommand("paste");
      } catch (e) {}
    }
    return ok;
  }

  async function fillText(text) {
    for (var attempt = 0; attempt < 6; attempt++) {
      var box = composerBox() || await waitForDialogBox(4000);
      if (!box) {
        await sleep(400);
        continue;
      }
      await pasteInto(box, text);
      await sleep(700);
      if (hasPostText(text)) return true;
      showBanner("發文框文字被 Facebook 清掉，正在重貼第 " + (attempt + 1) + " 次");
    }
    return hasPostText(text);
  }

  async function copyText(text) {
    try { await navigator.clipboard.writeText(text || ""); return true; } catch (e) { return false; }
  }

  async function filesFromUrls(urls) {
    var files = [];
    for (var i = 0; i < urls.length; i++) {
      var url = urls[i];
      if (!url) continue;
      try {
        var res = await fetch(url, { credentials: "omit" });
        if (!res.ok) continue;
        var blob = await res.blob();
        var type = blob.type || "image/jpeg";
        if (type.indexOf("image/") !== 0 && type !== "application/octet-stream") continue;
        if (type === "application/octet-stream") type = "image/jpeg";
        var name = String(url.split("/").pop() || "photo.jpg").split("?")[0] || "photo.jpg";
        name = name.replace(/[^\w.\-]+/g, "_") || ("photo-" + (i + 1) + ".jpg");
        files.push(new File([blob], name, { type: type }));
      } catch (e) {}
    }
    return files;
  }

  function allFileInputs() {
    return Array.prototype.slice.call(document.querySelectorAll("input[type=file]"));
  }

  function assignFiles(input, files) {
    var dt = new DataTransfer();
    files.forEach(function (file) { dt.items.add(file); });
    input.files = dt.files;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function dropFiles(box, files) {
    if (!box || !files.length) return;
    var dt = new DataTransfer();
    files.forEach(function (file) { dt.items.add(file); });
    ["dragenter", "dragover", "drop"].forEach(function (type) {
      try {
        box.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: dt }));
      } catch (e) {}
    });
    try {
      var pasteDt = new DataTransfer();
      files.forEach(function (file) { pasteDt.items.add(file); });
      box.dispatchEvent(new ClipboardEvent("paste", { bubbles: true, cancelable: true, clipboardData: pasteDt }));
    } catch (e) {}
  }

  async function attachFiles(files) {
    if (!files.length) return 0;
    var box = composerBox();
    var inputs = allFileInputs();
    var input = inputs.filter(function (el) {
      return (el.accept || "").indexOf("image") >= 0 || el.multiple;
    })[0] || inputs[0];
    if (input) {
      assignFiles(input, files);
      await sleep(1800);
      return files.length;
    }
    if (box) dropFiles(box, files);
    await sleep(1800);
    inputs = allFileInputs();
    if (inputs[0]) {
      assignFiles(inputs[0], files);
      await sleep(1200);
      return files.length;
    }
    return 0;
  }

  function actionButton(labels) {
    var root = dialogRoot() || document;
    return findByLabels(root, labels, "[role=button], button");
  }

  async function clickPublish() {
    var labels = ["發佈", "发布", "立即發佈", "Publish"];
    for (var round = 0; round < 4; round++) {
      var btn = actionButton(labels);
      if (!btn) {
        await sleep(600);
        continue;
      }
      if (isDismissLabel(textOf(btn))) continue;
      var disabled = btn.getAttribute("aria-disabled") === "true" || btn.hasAttribute("disabled");
      if (disabled) {
        await sleep(800);
        continue;
      }
      clickEl(btn);
      await sleep(1800);
      var confirm = actionButton(["發佈", "发布", "立即發佈", "Publish"]);
      if (confirm && confirm !== btn && !isDismissLabel(textOf(confirm))) clickEl(confirm);
      return true;
    }
    return false;
  }

  async function runPost(job) {
    showBanner("火旺自動化發文中：不開系統相簿，避免把發文框關掉");
    if (looksLoggedOut()) {
      return { ok: false, status: "pending_review", note: "Facebook 尚未登入，請先用郭火旺帳號登入這個 Chrome" };
    }
    var text = String(job.postText || "").trim();
    if (!text) return { ok: false, status: "failed", note: "後台沒有貼文文字" };
    await copyText(text);
    var urls = Array.isArray(job.imageUrls) ? job.imageUrls.slice(0, 10) : [];
    if (job.businessCardUrl) urls.push(job.businessCardUrl);
    var files = await filesFromUrls(urls);
    var box = await openComposer();
    if (!box) {
      return { ok: false, status: "pending_review", note: "找不到發文彈窗。文案已複製，請在社團／粉絲團手動貼上。" };
    }
    var typed = await fillText(text);
    var attached = 0;
    if (dialogRoot()) {
      attached = await attachFiles(files);
      await sleep(800);
      if (!hasPostText(text)) typed = await fillText(text);
    } else {
      return {
        ok: false,
        status: "pending_review",
        note: "發文框一開就被關掉。文案已在剪貼簿，請手動打開發文框貼上。",
        postUrl: location.href
      };
    }
    if (!hasPostText(text)) {
      return {
        ok: false,
        status: "pending_review",
        note: "發文框文字被 Facebook 清掉，已把完整文案放在剪貼簿。照片 " + attached + " 張。請 Ctrl+V 貼上後再按發佈。",
        postUrl: location.href,
        attached: attached
      };
    }
    var posted = await clickPublish();
    await sleep(800);
    if (dialogRoot() && !hasPostText(text)) {
      showBanner("按發佈前文字又消失，正在重貼");
      typed = await fillText(text);
      if (hasPostText(text)) posted = await clickPublish();
    }
    if (!posted) {
      return {
        ok: false,
        status: "pending_review",
        note: (hasPostText(text) ? "文案還在發文框" : "文案已複製") + "，但找不到發佈按鈕。照片 " + attached + " 張。",
        postUrl: location.href
      };
    }
    await sleep(2500);
    return {
      ok: true,
      status: "published",
      note: "已按發佈｜照片 " + attached + " 張｜文案 " + text.length + " 字",
      postUrl: location.href,
      attached: attached
    };
  }

  async function runJoin(job) {
    showBanner("火旺自動化加入社團中，請勿關閉此分頁");
    if (looksLoggedOut()) {
      return { ok: false, result: "needs_manual", note: "Facebook 尚未登入" };
    }
    var body = document.body ? document.body.innerText : "";
    if (/已加入|Joined|你是這個社團的成員|You're a member/.test(body)) {
      return { ok: true, result: "joined", note: "已是社團成員" };
    }
    if (/等待核准|Awaiting approval|申請已送出/.test(body)) {
      return { ok: true, result: "pending", note: "已送出加入申請，等待核准" };
    }
    var btn = findByLabels(document, ["加入社團", "Join group"]);
    if (!btn) return { ok: false, result: "needs_manual", note: "找不到加入按鈕，可能要回答問題" };
    clickEl(btn);
    await sleep(2000);
    body = document.body ? document.body.innerText : "";
    if (/等待核准|Awaiting approval|申請已送出/.test(body)) {
      return { ok: true, result: "pending", note: "已送出加入申請" };
    }
    if (/已加入|Joined/.test(body)) {
      return { ok: true, result: "joined", note: "已加入社團" };
    }
    if (/回答問題|Answer questions/.test(body)) {
      return { ok: false, result: "needs_manual", note: "需要回答社團問題" };
    }
    return { ok: true, result: "pending", note: "已點加入，請在後台確認狀態" };
  }

  return {
    run: function (job, mode) {
      if (mode === "join") return runJoin(job || {});
      return runPost(job || {});
    }
  };
})();
