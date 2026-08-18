const STORAGE_KEY = "huowang-listing-boards-v1";
const OPEN_DELAY_MS = 500;
const SHORTCUTS = [
  { key: "1", name: "永慶房管", url: "https://dramax.ycut.com.tw/login" },
  { key: "2", name: "591房屋", url: "https://www.591.com.tw/" },
  { key: "3", name: "007比價王", url: "https://007.houseprice.tw/" },
  { key: "4", name: "我家網VIP", url: "https://www.myhomes.com.tw/vip2/login.php" },
  { key: "5", name: "Facebook", url: "https://www.facebook.com/" },
  { key: "6", name: "地籍圖", url: "https://easymap.moi.gov.tw/Z10Web/Index" },
  { key: "7", name: "YES319", url: "https://www.yes319.com/my319/login/" },
  { key: "8", name: "YCUT登入", url: "https://opid.ycut.com.tw/YcutPortal/Login" },
  { key: "9", name: "paohui總路口", url: "https://paohui.org/" }
];
const CORE_URLS = SHORTCUTS.map((item) => item.url);

const state = {
  portals: [],
  boards: [],
  listings: { yongching: [], "same-store": [], development: [], exclusive: [] },
  seed: null,
  rightUrl: ""
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
            <button type="button" data-open-right="${p.url}">右邊開啟</button>
            <button type="button" data-copy="${p.url}">複製網址</button>
          </div>
        </article>`);
      grid.appendChild(card);
    });
    root.appendChild(wrap);
  });
}

function seedBoardItems(prop) {
  const at = "2026/08/18 已從591串入";
  const source = prop.sourceUrl;
  return {
    yongching: [{
      title: `${prop.publicTitle}｜${prop.price}萬｜${prop.build}坪`,
      source,
      note: `永慶主檔。591 ${prop.contractNo} 已上架。單價${prop.unitPrice}萬/坪。請同步 YCUT 我的物件。`,
      at
    }],
    "same-store": [{
      title: `${prop.publicTitle}｜本店可合作帶看`,
      source,
      note: "郭火旺本店物件。同店同事可協助帶看；前台不顯示抽成與精準門牌。",
      at
    }],
    development: [{
      title: `桃園大有路開發件｜${prop.community}`,
      source: "https://market.591.com.tw/24525",
      note: "開發來源：桃園區大有路羅丹一期。社區均價約25萬/坪，本物件約28.86萬/坪。",
      at
    }],
    exclusive: [{
      title: `${prop.contractNo} 專任約｜${prop.publicTitle}`,
      source,
      note: `合約類型專任約，591有效至 ${prop.expireDate}。簽約後同步公開物件與上架通路。`,
      at
    }]
  };
}

function mergeSeedListings(prop) {
  const seeded = seedBoardItems(prop);
  Object.keys(seeded).forEach((key) => {
    const exists = (state.listings[key] || []).some((item) => String(item.source || "").includes("20448597") || String(item.title || "").includes("羅丹藝術家"));
    if (!exists) state.listings[key] = [...seeded[key], ...(state.listings[key] || [])];
  });
  saveListings();
}

function huowangImportPayload(prop) {
  const base = {
    contractNo: prop.contractNo,
    contractType: prop.contractType,
    owner: prop.agent,
    buyer: "",
    title: prop.title,
    unitPrice: prop.unitPrice,
    build: prop.build,
    price: prop.price,
    layout: prop.layout,
    type: prop.type,
    county: prop.county,
    area: prop.area,
    addressDetail: prop.addressDetail,
    dmNearby: prop.dmNearby,
    dmPublicText: prop.publicNotes,
    publishingDate: prop.publishingDate,
    lat: prop.lat,
    lng: prop.lng,
    expireDate: prop.expireDate,
    saleStatus: prop.saleStatus,
    soldPrice: "",
    adText: prop.adText,
    publicNo: prop.publicNo,
    mainBuildArea: prop.mainBuildArea,
    landArea: prop.landArea,
    houseAge: prop.houseAge,
    floor: prop.floor,
    landUseZone: prop.landUseZone,
    registryUse: prop.registryUse,
    keyFeatures: prop.keyFeatures,
    lifeFeatures: prop.lifeFeatures,
    publicNotes: prop.publicNotes,
    importRegion: "桃園市桃園區",
    sourceUrl: prop.sourceUrl,
    images: prop.images
  };
  return {
    properties: [base],
    targets: [{
      id: prop.caseId || prop.contractNo,
      title: prop.title,
      county: prop.county,
      area: prop.area,
      price: prop.price,
      build: prop.build,
      unitPrice: prop.unitPrice,
      status: "已上架",
      sourceUrl: prop.sourceUrl,
      publicNote: prop.publicNotes
    }],
    sameStoreItems: [{
      brand: prop.brand,
      contact: prop.agent,
      title: prop.title,
      price: prop.price + "萬",
      city: prop.county,
      district: prop.area,
      road: prop.addressRoad,
      nearby: prop.dmNearby,
      status: "可合作",
      sourceUrl: prop.sourceUrl
    }]
  };
}

function renderCase(prop) {
  const facts = [
    ["總價", prop.price + " 萬"],
    ["權狀", prop.build + " 坪"],
    ["單價", prop.unitPrice + " 萬/坪"],
    ["格局", prop.layout],
    ["樓層", prop.floor],
    ["型態", prop.type],
    ["社區", prop.community],
    ["管理費", prop.managementFee],
    ["車位", prop.parking],
    ["裝潢", prop.decoration],
    ["公設比", prop.commonAreaRatio],
    ["591編號", prop.contractNo]
  ].map(([k, v]) => `<div><span>${k}</span><b>${v}</b></div>`).join("");
  const photos = (prop.images || []).slice(0, 12).map((src) => `<img src="${src}" alt="${prop.publicTitle}">`).join("");
  $("#dayouCase").innerHTML = `
    <div class="case-hero">
      <img src="${prop.cover}" alt="${prop.publicTitle}">
      <div class="case-body">
        <div class="chip">已串入示範物件 · 桃園大有</div>
        <h2>${prop.publicTitle}</h2>
        <div class="price-line">${prop.price}萬<small>${prop.build}坪　${prop.unitPrice}萬/坪</small></div>
        <p class="note">${prop.publicNotes}</p>
        <div class="facts">${facts}</div>
        <div class="card-actions">
          <button type="button" data-open-right="${prop.sourceUrl}">右邊開591</button>
          <button type="button" data-open-right="${prop.channels.huowangAdmin}">右邊開火旺後台</button>
          <button type="button" data-open-right="${prop.channels.ycut}">右邊開永慶YCUT</button>
          <button type="button" id="copyFb">複製FB上架文案</button>
          <button type="button" id="downloadImport">下載火旺匯入JSON</button>
          <button type="button" id="openCaseUrls">相關網址開在右邊</button>
        </div>
      </div>
    </div>
    <div class="photos">${photos}</div>
    <div style="padding:0 22px 22px">
      <div class="copybox" id="fbCopy">${prop.facebookPost}</div>
    </div>`;
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
          <button type="button" data-open-right="${board.adminUrl}">右邊開火旺後台</button>
          <button type="button" data-open-right="${board.officialUrl}">右邊開官方頁</button>
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

function rightWindowFeatures() {
  const availLeft = Number(window.screen.availLeft || 0);
  const availTop = Number(window.screen.availTop || 0);
  const width = Math.max(640, Math.floor(window.screen.availWidth / 2));
  const height = Math.max(700, window.screen.availHeight);
  const left = availLeft + window.screen.availWidth - width;
  return `left=${left},top=${availTop},width=${width},height=${height},scrollbars=yes,resizable=yes`;
}

function openOnRight(url, name) {
  const win = window.open(url, name || "_blank", rightWindowFeatures());
  if (win && typeof win.moveTo === "function") {
    try {
      const width = Math.max(640, Math.floor(window.screen.availWidth / 2));
      const left = Number(window.screen.availLeft || 0) + window.screen.availWidth - width;
      win.moveTo(left, Number(window.screen.availTop || 0));
      win.resizeTo(width, window.screen.availHeight);
    } catch (_) { /* ignore */ }
  }
  return win;
}

function showInRightPane(url, title) {
  state.rightUrl = url;
  const frame = $("#rightFrame");
  $("#rightTitle").textContent = title || url;
  frame.src = url;
  document.querySelectorAll("[data-shortcut-url]").forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.shortcutUrl === url);
  });
  log(`已在這個窗格開啟：${title || url}`);
}

function renderShortcuts() {
  const root = $("#rightTabs");
  root.innerHTML = "";
  SHORTCUTS.forEach((item) => {
    const btn = el(`<button type="button" data-shortcut-url="${item.url}" title="快捷鍵 ${item.key}"><kbd>${item.key}</kbd><span>${item.name}</span></button>`);
    btn.addEventListener("click", () => showInRightPane(item.url, item.name));
    root.appendChild(btn);
  });
  const extra = el(`<button type="button" data-shortcut-url="https://huowang.paohui.org/admin.html.html" title="火旺後台"><kbd>0</kbd><span>火旺後台</span></button>`);
  extra.addEventListener("click", () => showInRightPane("https://huowang.paohui.org/admin.html.html", "火旺後台"));
  root.appendChild(extra);
}

function bindShortcutKeys() {
  document.addEventListener("keydown", (e) => {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    const tag = (e.target && e.target.tagName || "").toLowerCase();
    if (tag === "input" || tag === "textarea" || tag === "select" || e.target.isContentEditable) return;
    if (e.key === "0") {
      e.preventDefault();
      showInRightPane("https://huowang.paohui.org/admin.html.html", "火旺後台");
      return;
    }
    const hit = SHORTCUTS.find((item) => item.key === e.key);
    if (!hit) return;
    e.preventDefault();
    showInRightPane(hit.url, hit.name);
  });
}

function openUrls(urls) {
  log(`準備在螢幕右邊開啟 ${urls.length} 個網站`);
  urls.forEach((url, i) => {
    setTimeout(() => {
      const win = openOnRight(url, `huowang-right-${i}`);
      if (i === 0) showInRightPane(url, url);
      log(win ? `右邊已開：${url}` : `瀏覽器擋了彈窗，改點右邊分頁：${url}`);
    }, i * OPEN_DELAY_MS);
  });
}

function bind() {
  document.addEventListener("click", (e) => {
    const openRight = e.target.closest("[data-open-right]");
    if (openRight) showInRightPane(openRight.dataset.openRight, openRight.textContent);
    const copy = e.target.closest("[data-copy]");
    if (copy) copyText(copy.dataset.copy).then(() => log(`已複製 ${copy.dataset.copy}`));
    const del = e.target.closest("[data-del]");
    if (del) {
      const [id, idx] = del.dataset.del.split(":");
      state.listings[id].splice(Number(idx), 1);
      saveListings();
      renderBoards();
    }
    if (e.target.id === "copyFb" && state.seed) {
      copyText(state.seed.facebookPost).then(() => log("已複製桃園大有 FB 上架文案"));
    }
    if (e.target.id === "downloadImport" && state.seed) {
      const blob = new Blob([JSON.stringify(huowangImportPayload(state.seed), null, 2)], { type: "application/json" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "huowang-import-dayou-taoyuan.json";
      a.click();
      log("已下載火旺後台匯入檔 huowang-import-dayou-taoyuan.json");
    }
    if (e.target.id === "openCaseUrls" && state.seed) {
      openUrls(Object.values(state.seed.channels));
    }
  });
  $("#openAll").addEventListener("click", () => {
    openUrls(state.portals.filter((p) => p.open).map((p) => p.url));
  });
  $("#openCore").addEventListener("click", () => openUrls(CORE_URLS));
  $("#copyAll").addEventListener("click", () => {
    const text = state.portals.map((p) => `${p.name}\n${p.url}`).join("\n\n");
    copyText(text).then(() => log("已複製全部網址清單"));
  });
  $("#openHubRight").addEventListener("click", () => showInRightPane("https://paohui.org/", "paohui.org"));
  $("#openAdminRight").addEventListener("click", () => showInRightPane("https://huowang.paohui.org/admin.html.html", "火旺後台"));
  $("#openCurrentRightWindow").addEventListener("click", () => {
    if (!state.rightUrl) state.rightUrl = "https://paohui.org/";
    const win = openOnRight(state.rightUrl, "huowang-right");
    log(win ? `已把目前網站開在右邊視窗：${state.rightUrl}` : "瀏覽器擋彈窗，請允許後再開");
  });
}

async function boot() {
  loadListings();
  const data = await fetch("./portals.json").then((r) => r.json());
  const listings = await fetch("./listings.json").then((r) => r.json());
  state.portals = data.portals;
  state.boards = data.listingBoards;
  state.seed = listings.property;
  $("#heroMeta").innerHTML = `
    <span class="chip">${data.agent.name}　${data.agent.phone}</span>
    <span class="chip">${data.agent.brand}</span>
    <span class="chip">本機目錄 ${data.agent.sourceDisk}</span>
    <span class="chip">永慶員編 C56026（密碼不進 Git）</span>
    <span class="chip">桃園大有已串入 ${listings.property.contractNo}</span>`;
  mergeSeedListings(listings.property);
  renderCase(listings.property);
  renderPortals();
  renderBoards();
  renderShortcuts();
  bind();
  bindShortcutKeys();
  showInRightPane(SHORTCUTS[8].url, SHORTCUTS[8].name);
  log("公用網站快捷已放在右邊窗格。按 1～9 或點按鈕，就在這個窗格開啟。");
  log(listings.wired.note);
  log(`已載入桃園大有物件 ${listings.property.title}，總價 ${listings.property.price} 萬。`);
  log("網站會開在右邊。按「指定網站開在右邊」把你給的 9 個網站打開。");
}

boot();
