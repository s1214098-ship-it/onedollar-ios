const STORAGE_KEY = "huowang-listing-boards-v1";
const OPEN_DELAY_MS = 700;

const state = {
  portals: [],
  boards: [],
  listings: { yongching: [], "same-store": [], development: [], exclusive: [] }
};

function $(sel, root = document) { return root.querySelector(sel); }
function el(html) {
  const t = document.createElement("template");
  t.innerHTML = html.trim();
  return t.content.firstElementChild;
}
function log(msg) {
  const box = $("#openLog");
  const line = `${new Date().toLocaleTimeString("zh-TW")}  ${msg}`;
  box.textContent = box.textContent ? `${box.textContent}\n${line}` : line;
  box.scrollTop = box.scrollHeight;
}

function loadListings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) Object.assign(state.listings, JSON.parse(raw));
  } catch (_) { /* ignore */ }
}
function saveListings() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state.listings));
}

function statusTag(status) {
  if (status === "ok") return `<span class="tag">可開</span>`;
  if (status === "check") return `<span class="tag warn">待確認</span>`;
  if (status === "search") return `<span class="tag search">搜尋頁</span>`;
  if (status === "redirect") return `<span class="tag redirect">改走正式登入</span>`;
  return `<span class="tag">${status}</span>`;
}

function renderPortals() {
  const groups = [...new Set(state.portals.map((p) => p.group))];
  const root = $("#portalGroups");
  root.innerHTML = "";
  groups.forEach((group) => {
    const wrap = el(`<section><div class="section"><h2>${group}</h2><span></span></div><div class="grid"></div></section>`);
    const grid = wrap.querySelector(".grid");
    state.portals.filter((p) => p.group === group).forEach((p) => {
      const account = p.account ? `<div class="chip">帳號／識別：${p.account}</div>` : "";
      const card = el(`
        <article class="card">
          ${statusTag(p.status)}
          <h3>${p.name}</h3>
          <div class="url">${p.url}</div>
          <p class="note">${p.note}</p>
          ${account}
          <div class="card-actions">
            <a href="${p.url}" target="_blank" rel="noopener">開啟</a>
            <button type="button" data-copy="${p.url}">複製網址</button>
          </div>
        </article>`);
      grid.appendChild(card);
    });
    root.appendChild(wrap);
  });
}

function renderBoards() {
  const root = $("#boardGrid");
  root.innerHTML = "";
  state.boards.forEach((board) => {
    const items = state.listings[board.id] || [];
    const col = el(`
      <div class="col" data-board="${board.id}">
        <h3>${board.name} <small>(${items.length})</small></h3>
        <p class="note">${board.note}</p>
        <div class="card-actions">
          <a href="${board.adminUrl}" target="_blank" rel="noopener">開火旺後台</a>
          <a href="${board.officialUrl}" target="_blank" rel="noopener">開官方頁</a>
        </div>
        <form>
          <input name="title" required placeholder="案名／編號">
          <input name="source" placeholder="來源網址">
          <textarea name="note" rows="2" placeholder="上架備註：同店／開發／專任／永慶狀態"></textarea>
          <button class="btn gold" type="submit">記入待更新</button>
        </form>
        <div class="list"></div>
      </div>`);
    const list = col.querySelector(".list");
    if (!items.length) list.appendChild(el(`<div class="empty">尚未記入。之後更新永慶物件、同店、開發件、專任合約會顯示在這裡。</div>`));
    items.forEach((item, idx) => {
      list.appendChild(el(`
        <div class="item">
          <b>${item.title}</b>
          <small>${item.source || "未填來源"}</small>
          <small>${item.note || ""}</small>
          <small>${item.at}</small>
          <button class="btn ghost" type="button" data-del="${board.id}:${idx}">刪除</button>
        </div>`));
    });
    col.querySelector("form").addEventListener("submit", (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      state.listings[board.id].unshift({
        title: String(fd.get("title") || "").trim(),
        source: String(fd.get("source") || "").trim(),
        note: String(fd.get("note") || "").trim(),
        at: new Date().toLocaleString("zh-TW")
      });
      saveListings();
      renderBoards();
    });
    root.appendChild(col);
  });
}

function copyText(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text);
  }
  const ta = document.createElement("textarea");
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand("copy");
  ta.remove();
  return Promise.resolve();
}

function openUrls(urls) {
  log(`準備開啟 ${urls.length} 個網址`);
  urls.forEach((url, i) => {
    setTimeout(() => {
      const win = window.open(url, "_blank", "noopener");
      log(win ? `已開：${url}` : `瀏覽器擋了彈窗，改請點卡片開啟：${url}`);
    }, i * OPEN_DELAY_MS);
  });
}

function bind() {
  document.addEventListener("click", (e) => {
    const copy = e.target.closest("[data-copy]");
    if (copy) {
      copyText(copy.dataset.copy).then(() => log(`已複製 ${copy.dataset.copy}`));
    }
    const del = e.target.closest("[data-del]");
    if (del) {
      const [id, idx] = del.dataset.del.split(":");
      state.listings[id].splice(Number(idx), 1);
      saveListings();
      renderBoards();
    }
  });
  $("#openAll").addEventListener("click", () => {
    openUrls(state.portals.filter((p) => p.open).map((p) => p.url));
  });
  $("#openCore").addEventListener("click", () => {
    openUrls([
      "https://paohui.org/",
      "https://huowang.paohui.org/",
      "https://huowang.paohui.org/admin.html.html",
      "https://is.ycut.com.tw/",
      "https://www.591.com.tw/",
      "https://007.houseprice.tw/",
      "https://www.myhomes.com.tw/vip2/login.php",
      "https://www.facebook.com/search/top/?q=%E7%BE%85%E6%9D%B1%E8%BE%B2%E8%88%8D%20%E9%83%AD%E7%81%AB%E6%97%BA",
      "https://easymap.moi.gov.tw/Z10Web/Index",
      "https://www.yes319.com/my319/login/"
    ]);
  });
  $("#copyAll").addEventListener("click", () => {
    const text = state.portals.map((p) => `${p.name}\n${p.url}`).join("\n\n");
    copyText(text).then(() => log("已複製全部網址清單"));
  });
}

async function boot() {
  loadListings();
  const data = await fetch("./portals.json").then((r) => r.json());
  state.portals = data.portals;
  state.boards = data.listingBoards;
  $("#heroMeta").innerHTML = `
    <span class="chip">${data.agent.name}　${data.agent.phone}</span>
    <span class="chip">${data.agent.brand}</span>
    <span class="chip">本機目錄 ${data.agent.sourceDisk}</span>
    <span class="chip">永慶員編 C56026（密碼不進 Git）</span>`;
  renderPortals();
  renderBoards();
  bind();
  log(`已載入 ${state.portals.length} 個網站紀錄。可按「一次開啟全部網址」。`);
}

boot();
