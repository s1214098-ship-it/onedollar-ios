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

  function clickEl(el) {
    if (!el) return false;
    try { el.scrollIntoView({ block: "center", inline: "nearest" }); } catch (e) {}
    try { el.click(); return true; } catch (e) { return false; }
  }

  function findByLabels(root, labels, selector) {
    var wanted = labels.map(function (x) { return String(x).toLowerCase(); });
    var nodes = (root || document).querySelectorAll(selector || "div[role=button], span[role=button], [role=textbox], button, a[role=link], div[aria-label], span[aria-label]");
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var t = textOf(el).toLowerCase();
      if (!t) continue;
      for (var j = 0; j < wanted.length; j++) {
        if (t === wanted[j] || t.indexOf(wanted[j]) >= 0) {
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
    box.style.cssText = "position:fixed;z-index:2147483647;left:16px;right:16px;top:12px;background:#0f766e;color:#fff;font:700 16px/1.4 sans-serif;padding:12px 16px;border-radius:10px;box-shadow:0 8px 24px #0006";
    document.documentElement.appendChild(box);
    setTimeout(function () { if (box.parentNode) box.remove(); }, 20000);
  }

  function looksLoggedOut() {
    var t = document.body ? document.body.innerText : "";
    return /登入 Facebook|Log in to Facebook|Forgot password|忘記密碼/.test(t) && !document.querySelector("[role=feed], [role=main] [contenteditable=true]");
  }

  function composerBox() {
    return document.querySelector("[role=dialog] [contenteditable=true][role=textbox]")
      || document.querySelector("[role=dialog] [contenteditable=true]")
      || document.querySelector("[aria-label*='建立公開貼文']")
      || document.querySelector("[aria-label*='Create a public post']")
      || document.querySelector("div[role=dialog] div[contenteditable=true]");
  }

  async function openComposer() {
    var box = composerBox();
    if (box) return box;
    var labels = [
      "撰寫貼文", "寫點什麼", "Write something", "What's on your mind", "What’s on your mind",
      "建立貼文", "Create post", "Create a post", "討論", "Discuss", "發佈貼文",
      "公開發佈", "在想什麼", "有什麼新鮮事"
    ];
    var opener = findByLabels(document, labels)
      || document.querySelector("[aria-label*='撰寫']")
      || document.querySelector("[aria-label*='Write something']")
      || document.querySelector("[aria-label*='Create a post']")
      || document.querySelector("[aria-label*='建立貼文']")
      || document.querySelector("[aria-label*='在想什麼']");
    if (opener) clickEl(opener);
    for (var i = 0; i < 25; i++) {
      await sleep(350);
      box = composerBox();
      if (box) return box;
    }
    return null;
  }

  async function insertText(box, text) {
    box.focus();
    await sleep(200);
    try { document.execCommand("selectAll", false, null); } catch (e) {}
    var ok = false;
    try { ok = document.execCommand("insertText", false, text); } catch (e) { ok = false; }
    if (!ok) {
      try {
        var dt = new DataTransfer();
        dt.setData("text/plain", text);
        box.dispatchEvent(new ClipboardEvent("paste", { clipboardData: dt, bubbles: true, cancelable: true }));
      } catch (e) {}
    }
    box.dispatchEvent(new InputEvent("input", { bubbles: true, data: text, inputType: "insertText" }));
    await sleep(400);
    var current = String(box.innerText || box.textContent || "");
    return current.indexOf(String(text || "").slice(0, 10)) >= 0 || current.length >= Math.min(20, String(text || "").length);
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
        var name = String(url.split("/").pop() || "photo.jpg").split("?")[0] || "photo.jpg";
        name = name.replace(/[^\w.\-]+/g, "_") || ("photo-" + (i + 1) + ".jpg");
        files.push(new File([blob], name, { type: blob.type || "image/jpeg" }));
      } catch (e) {}
    }
    return files;
  }

  function fileInput() {
    return document.querySelector("[role=dialog] input[type=file]")
      || document.querySelector("input[type=file][accept*='image']")
      || document.querySelector("form input[type=file][multiple]");
  }

  async function attachFiles(files) {
    if (!files.length) return 0;
    var input = fileInput();
    if (!input) {
      var photo = findByLabels(document.querySelector("[role=dialog]") || document, [
        "相片／影片", "相片/影片", "Photo/video", "Photo / video", "相片和影片", "照片／影片"
      ]);
      if (photo) clickEl(photo);
      await sleep(800);
      input = fileInput();
    }
    if (!input) return 0;
    var dt = new DataTransfer();
    files.forEach(function (file) { dt.items.add(file); });
    input.files = dt.files;
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.dispatchEvent(new Event("change", { bubbles: true }));
    await sleep(2500);
    return files.length;
  }

  function actionButton(labels) {
    var root = document.querySelector("[role=dialog]") || document;
    return findByLabels(root, labels, "[role=button], button");
  }

  async function clickPublish() {
    var labels = ["發佈", "发布", "Post", "Publish", "立即發佈", "Next", "下一步"];
    for (var round = 0; round < 3; round++) {
      var btn = actionButton(labels);
      if (!btn) return false;
      var disabled = btn.getAttribute("aria-disabled") === "true" || btn.hasAttribute("disabled");
      if (disabled) {
        await sleep(800);
        continue;
      }
      clickEl(btn);
      await sleep(1800);
      var confirm = actionButton(["發佈", "发布", "Post", "Publish"]);
      if (confirm && confirm !== btn) clickEl(confirm);
      return true;
    }
    return false;
  }

  async function runPost(job) {
    showBanner("火旺自動化發文中，請勿關閉此分頁");
    if (looksLoggedOut()) {
      return { ok: false, status: "pending_review", note: "Facebook 尚未登入，請先用郭火旺帳號登入這個 Chrome" };
    }
    var text = String(job.postText || "").trim();
    if (!text) return { ok: false, status: "failed", note: "後台沒有貼文文字" };
    await copyText(text);
    var box = await openComposer();
    if (!box) {
      return { ok: false, status: "pending_review", note: "找不到發文框。文案已複製，請在社團／粉絲團手動貼上。" };
    }
    var typed = await insertText(box, text);
    var urls = Array.isArray(job.imageUrls) ? job.imageUrls.slice(0, 10) : [];
    if (job.businessCardUrl) urls.push(job.businessCardUrl);
    var files = await filesFromUrls(urls);
    var attached = await attachFiles(files);
    var posted = await clickPublish();
    if (!posted) {
      return {
        ok: false,
        status: "pending_review",
        note: (typed ? "文案已填入" : "文案可能沒填完整") + "，但找不到發佈按鈕。照片 " + attached + " 張。文案已在剪貼簿。",
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
    var btn = findByLabels(document, ["加入社團", "Join group", "加入", "Join"]);
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
