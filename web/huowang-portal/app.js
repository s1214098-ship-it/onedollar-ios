const STORAGE_KEY = "huowang-listing-boards-v1";
const OPEN_DELAY_MS = 500;
const SHORTCUTS = [
  { key: "1", name: "永慶加盟系統", url: "https://is.ycut.com.tw/is/home" },
  { key: "2", name: "591房屋", url: "https://www.591.com.tw/" },
  { key: "3", name: "007比價王", url: "https://007.houseprice.tw/" },
  { key: "4", name: "我家網VIP", url: "https://www.myhomes.com.tw/vip2/login.php" },
  { key: "5", name: "Facebook", url: "https://www.facebook.com/" },
  { key: "6", name: "地籍圖", url: "https://easymap.moi.gov.tw/Z10Web/Index" },
  { key: "7", name: "YES319", url: "https://www.yes319.com/my319/login/" },
  { key: "8", name: "YCUT登入頁", url: "https://opid.ycut.com.tw/YcutPortal/Login" },
  { key: "9", name: "總控台", url: "https://paohui.org/" }
];
const HUOWANG_ADMIN = "https://huowang.paohui.org/admin.html.html";
const YCUT_HOME = "https://is.ycut.com.tw/is/home";
const YCUT_LOGIN = "https://is.ycut.com.tw/";
const SHOP_URL = "https://shop.yungching.com.tw/039519001/";
const SHOP_LIST_URL = "https://shop.yungching.com.tw/039519001/list";
const SHOP_WATCH_KEY = "huowang-shop-watch-v1";
const TRACKING_SHORTCUTS = [
  { name: "FamilyMart寄件", url: "https://ecfme.fme.com.tw/FMEDCFPWebV2_II/list.aspx" },
  { name: "FamilyMart寄件", url: "https://ecfme.fme.com.tw/FMEDCFPWebV2_II/index.aspx" },
  { name: "郵件查詢", url: "https://postserv.post.gov.tw/pstmail/main_mail.html?targetTxn=EB500100" },
  { name: "SHOPMORE 貨態查詢系統", url: "https://tracking.shopmore.com.tw/" }
];
const CORE_URLS = SHORTCUTS.map((item) => item.url);

const state = {
  portals: [],
  boards: [],
  listings: { yongching: [], "same-store": [], development: [], exclusive: [] },
  seed: null,
  rightUrl: "",
  ycutWin: null,
  hubWin: null,
  ycutBridge: null,
  shop: null,
  shopWatch: { prices: {}, watched: [] }
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

function loadShopWatch() {
  try {
    const raw = localStorage.getItem(SHOP_WATCH_KEY);
    if (raw) Object.assign(state.shopWatch, JSON.parse(raw));
  } catch (_) { /* ignore */ }
}
function saveShopWatch() {
  localStorage.setItem(SHOP_WATCH_KEY, JSON.stringify(state.shopWatch));
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

function shopRows(filter) {
  const rows = (state.shop && state.shop.listings) || [];
  if (filter === "watched") {
    const ids = new Set(state.shopWatch.watched || []);
    return rows.filter((r) => ids.has(r.id) || r.featured);
  }
  if (filter === "featured") return rows.filter((r) => r.featured);
  if (filter === "drop") return rows.filter((r) => r.priceOrig && r.price && Number(r.price) < Number(r.priceOrig));
  return rows;
}

function shopToBoardItem(row) {
  const drop = row.priceOrig && row.price && Number(row.price) < Number(row.priceOrig)
    ? ` 降價 ${row.priceOrig}→${row.price}萬`
    : "";
  return {
    title: `${row.title}｜${row.price}萬｜${row.build || row.land || "—"}坪｜${row.ycNo || row.id}`,
    source: row.url,
    note: `本店公開追蹤 ${row.area || ""} ${row.type || ""} ${row.layout || ""} ${row.houseAge || ""}${drop}`.trim(),
    at: new Date().toLocaleString("zh-TW"),
    shopId: row.id,
    ycNo: row.ycNo
  };
}

function syncShopToBoards(target, filter) {
  const rows = shopRows(filter);
  const boards = target === "both" ? ["yongching", "same-store"] : [target];
  let added = 0;
  boards.forEach((boardId) => {
    const list = state.listings[boardId] || [];
    rows.forEach((row) => {
      const exists = list.some((item) => item.shopId === row.id || String(item.source || "").includes(`/house/${row.id}`) || (row.ycNo && String(item.title || "").includes(row.ycNo)));
      if (exists) return;
      list.unshift(shopToBoardItem(row));
      added += 1;
    });
    state.listings[boardId] = list;
  });
  saveListings();
  renderBoards();
  log(`已把 ${rows.length} 筆本店物件對進${boards.join("／")}看板，新增 ${added} 筆（其餘已在追蹤）。請到快捷 9 火旺後台用匯入檔寫入。`);
}

function shopHuowangPayload(rows) {
  return {
    source: "shop.yungching.com.tw/039519001",
    shopName: (state.shop && state.shop.shopName) || "",
    syncedAt: (state.shop && state.shop.syncedAt) || "",
    properties: rows.map((row) => ({
      contractNo: row.ycNo || row.id,
      publicNo: row.ycNo || row.id,
      contractType: "專任約",
      owner: "郭火旺",
      title: `${row.title}${row.area ? " " + row.area : ""}`,
      price: row.price,
      unitPrice: row.build && row.price ? (Number(row.price) / Number(row.build || 1)).toFixed(2) : "",
      build: row.build,
      mainBuildArea: row.mainBuild,
      landArea: row.land,
      layout: row.layout,
      type: row.type,
      floor: row.floor,
      houseAge: row.houseAge,
      county: (row.area || "").slice(0, 3),
      area: row.area,
      addressDetail: row.area,
      publicNotes: row.note,
      adText: `${row.title} ${row.price}萬 ${row.build || row.land || ""}坪 ${row.layout || ""} ${row.type || ""}`,
      sourceUrl: row.url,
      saleStatus: "unsold",
      images: (row.images && row.images.length ? row.images : (row.cover ? [row.cover] : [])).map((src, i) => ({
        name: `物件照片${String(i + 1).padStart(2, "0")}`,
        data: src
      }))
    }))
  };
}

function downloadShopPhotoRestore() {
  fetch(encodeURI("./原始營業員抓取清單-羅東文化盛群最新.json"))
    .then((r) => r.blob())
    .then((blob) => {
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = "原始營業員抓取清單-羅東文化盛群最新.json";
      a.click();
      log("已下載後台照片還原檔（202 筆同店物件含照片）。請覆蓋到火旺網站根目錄，再到同店物件管理按「重載完整照片」。");
    })
    .catch(() => log("照片還原檔讀不到，請確認 web/huowang-portal 裡有原始營業員抓取清單檔。"));
}

function downloadShopImport(filter) {
  const rows = shopRows(filter);
  const blob = new Blob([JSON.stringify(shopHuowangPayload(rows), null, 2)], { type: "application/json" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "huowang-import-shop-039519001.json";
  a.click();
  log(`已下載火旺匯入檔 ${rows.length} 筆本店物件。請在快捷 9 後台匯入。`);
}

function toggleShopWatch(id) {
  const watched = new Set(state.shopWatch.watched || []);
  if (watched.has(id)) watched.delete(id);
  else watched.add(id);
  state.shopWatch.watched = [...watched];
  saveShopWatch();
  renderShopTracker();
}

function rememberShopPrices() {
  const prices = { ...(state.shopWatch.prices || {}) };
  const listings = (state.shop && state.shop.listings) || [];
  listings.forEach((row) => {
    if (prices[row.id] == null) prices[row.id] = row.price;
  });
  state.shopWatch.prices = prices;
  if (!(state.shopWatch.watched || []).length) {
    state.shopWatch.watched = listings.filter((r) => r.featured).map((r) => r.id);
  }
  saveShopWatch();
}

function shopChanged(row) {
  const prev = state.shopWatch.prices && state.shopWatch.prices[row.id];
  if (prev && row.price && String(prev) !== String(row.price)) return `${prev}→${row.price}萬`;
  if (row.priceOrig && row.price && Number(row.price) < Number(row.priceOrig)) return `官網降價 ${row.priceOrig}→${row.price}萬`;
  return "";
}

function renderShopTracker() {
  const root = $("#shopTracker");
  if (!root || !state.shop) return;
  const q = String(($("#shopFilter") && $("#shopFilter").value) || "").trim().toLowerCase();
  const mode = ($("#shopMode") && $("#shopMode").value) || "all";
  let rows = shopRows(mode);
  if (q) {
    rows = rows.filter((r) => `${r.title} ${r.area} ${r.ycNo} ${r.type} ${r.layout}`.toLowerCase().includes(q));
  }
  const watched = new Set(state.shopWatch.watched || []);
  const drops = shopRows("drop").length;
  const body = rows.slice(0, 80).map((row) => {
    const change = shopChanged(row);
    const on = watched.has(row.id) || row.featured;
    return `<tr>
      <td><button type="button" class="btn ghost" data-shop-watch="${row.id}">${on ? "追蹤中" : "加緊追蹤"}</button></td>
      <td>${row.cover ? `<img class="shop-thumb" src="${row.cover}" alt="" referrerpolicy="no-referrer" loading="lazy">` : ""}</td>
      <td>${row.featured ? '<span class="tag warn">店長強打</span> ' : ""}${row.ycNo || row.id}</td>
      <td><a href="${row.url}" target="_blank" rel="noopener">${row.title}</a><div class="note">${row.area || ""}　${(row.images || []).length} 張照片</div></td>
      <td>${row.price}萬${change ? `<div class="tag warn">${change}</div>` : ""}</td>
      <td>${row.type || ""} ${row.layout || ""}</td>
      <td>${row.build || row.land || "—"}坪</td>
    </tr>`;
  }).join("");
  root.innerHTML = `
    <div class="section">
      <h2>本店公開物件加緊追蹤</h2>
      <span>${state.shop.shopName}　已同步 ${state.shop.count} 筆　${state.shop.syncedAt}</span>
    </div>
    <article class="card bridge-card">
      <p class="note">來源：${SHOP_LIST_URL}。202 筆公開物件照片已從本店官網／同店資料庫還原。後台若照片空白，請下載還原檔覆蓋火旺根目錄的「原始營業員抓取清單-羅東文化盛群最新.json」，再到同店物件管理按「重載完整照片」。不要用會打 95MB 的完整 API。</p>
      <div class="card-actions">
        <button type="button" data-open-right="${SHOP_URL}">開本店官網</button>
        <button type="button" data-open-right="${SHOP_LIST_URL}">開本店買屋清單</button>
        <button type="button" id="downloadShopPhotos">下載後台照片還原檔</button>
        <button type="button" id="syncShopYongching">同步全部到永慶物件看板</button>
        <button type="button" id="syncShopSameStore">同步全部到同店看板</button>
        <button type="button" id="syncShopWatched">只同步加緊追蹤</button>
        <button type="button" id="downloadShopImport">下載全部火旺匯入JSON</button>
        <button type="button" id="downloadShopWatched">下載追蹤中匯入JSON</button>
      </div>
      <div class="shop-tools">
        <select id="shopMode">
          <option value="all"${mode === "all" ? " selected" : ""}>全部 ${state.shop.count}</option>
          <option value="featured"${mode === "featured" ? " selected" : ""}>店長強打 ${shopRows("featured").length}</option>
          <option value="watched"${mode === "watched" ? " selected" : ""}>加緊追蹤 ${watched.size || shopRows("featured").length}</option>
          <option value="drop"${mode === "drop" ? " selected" : ""}>官網降價 ${drops}</option>
        </select>
        <input id="shopFilter" value="${q}" placeholder="搜尋案名、YC編號、路段、格局">
      </div>
      <table class="bridge-table shop-table">
        <thead><tr><th>追蹤</th><th>照片</th><th>編號</th><th>案名</th><th>總價</th><th>型態／格局</th><th>坪數</th></tr></thead>
        <tbody>${body || `<tr><td colspan="6">沒有符合的物件</td></tr>`}</tbody>
      </table>
      ${rows.length > 80 ? `<p class="note">先顯示 80 筆，請用搜尋或篩選縮小。</p>` : ""}
    </article>`;
  const modeEl = $("#shopMode");
  const filterEl = $("#shopFilter");
  if (modeEl) modeEl.addEventListener("change", renderShopTracker);
  if (filterEl) filterEl.addEventListener("keydown", (e) => {
    if (e.key === "Enter") renderShopTracker();
  });
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
          <button type="button" id="copyYcutPackCase">複製永慶上架包</button>
          <button type="button" id="copyFb">複製FB上架文案</button>
          <button type="button" id="downloadImport">下載火旺匯入JSON</button>
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

function isYcutUrl(url) {
  return /ycut\.com\.tw/.test(String(url || ""));
}

function openLoginTab(url) {
  const a = document.createElement("a");
  a.href = url;
  a.target = "_blank";
  a.rel = "noopener";
  document.body.appendChild(a);
  a.click();
  a.remove();
}

function isHubUrl(url) {
  return /paohui\.org/.test(String(url || ""));
}

function alreadyOpen(label) {
  log(`${label}你已經開好了，不再另開分頁。左邊／快捷 9 編輯，右邊／快捷 1 存檔。`);
}

function focusYcutWindow(url, force) {
  if (state.ycutWin && !state.ycutWin.closed) {
    try { state.ycutWin.focus(); } catch (_) { /* ignore */ }
    alreadyOpen("快捷 1 永慶 IS：");
    return state.ycutWin;
  }
  if (force !== true) {
    alreadyOpen("快捷 1 永慶 IS：");
    return null;
  }
  const win = window.open(url || YCUT_HOME, "huowang-ycut", rightWindowFeatures());
  state.ycutWin = win || state.ycutWin;
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

function focusHubWindow(url, force) {
  if (state.hubWin && !state.hubWin.closed) {
    try { state.hubWin.focus(); } catch (_) { /* ignore */ }
    alreadyOpen("快捷 9 總控台／後台：");
    return state.hubWin;
  }
  if (force !== true) {
    alreadyOpen("快捷 9 總控台／後台：");
    return null;
  }
  const win = window.open(url || "https://paohui.org/", "huowang-hub", rightWindowFeatures());
  state.hubWin = win || state.hubWin;
  return win;
}

function openOnRight(url, name) {
  if (isYcutUrl(url)) return focusYcutWindow(url);
  if (isHubUrl(url)) return focusHubWindow(url);
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

function showYcutHelp(url, title, autoOpen) {
  const help = $("#rightHelp");
  const target = url || YCUT_HOME;
  if (help) {
    help.hidden = false;
    help.innerHTML = `
      <h3>${title || "快捷 1 × 快捷 9 已開好"}</h3>
      <p><b>1</b> 永慶 IS 上班網站已開在右邊。<b>9</b> 總控台／火旺後台也開好了。這裡不再另開分頁。</p>
      <p>在快捷 9 的後台編輯（自有物件、合約、YCUT流通作業）。複製上架包，貼到快捷 1 的「我的物件」，再按官方儲存。</p>
      <p class="note">帶看、委託、聯賣一定要在右邊 IS 存檔。火旺後台不能直接寫進永慶官方庫。</p>
      <p>
        <button class="btn red" type="button" id="copyYcutPackFromHelp">複製永慶上架包</button>
        <button class="btn gold" type="button" id="focusYcutWin">叫出右邊 IS</button>
        <button class="btn green" type="button" id="focusHubWin">叫出快捷 9 後台</button>
      </p>`;
  }
  if (autoOpen === true) focusYcutWindow(target);
  else alreadyOpen("快捷 1 與快捷 9：");
}

function showInRightPane(url, title, autoOpen) {
  state.rightUrl = url;
  const frame = $("#rightFrame");
  const help = $("#rightHelp");
  if (help) help.hidden = true;
  $("#rightTitle").textContent = title || url;
  document.querySelectorAll("[data-shortcut-url]").forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.shortcutUrl === url);
  });
  if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
  if (isYcutUrl(url) || isHubUrl(url)) {
    showYcutHelp(url, title, autoOpen);
    return;
  }
  if (needsTypingWindow(url)) {
    frame.removeAttribute("src");
    const win = openOnRight(url, "huowang-login");
    if (win) {
      try { win.focus(); } catch (_) { /* ignore */ }
      log(`已開可輸入登入視窗：${title || url}。請在新分頁打帳號、密碼、驗證碼。`);
    } else {
      log(`${title || url} 請用已開的視窗，這裡不另開分頁。`);
    }
    return;
  }
  frame.src = url;
  setTimeout(() => {
    try { frame.focus(); } catch (_) { /* ignore */ }
  }, 50);
  log(`已在這個窗格開啟：${title || url}`);
}

function needsTypingWindow(url) {
  return /591\.com\.tw|facebook\.com|myhomes\.com\.tw|yes319\.com|houseprice\.tw|easymap\.moi|ecfme\.fme|shopmore\.com|post\.gov\.tw/.test(String(url || ""));
}

function ycutPackText(prop) {
  if (!prop) return "";
  return [
    "【永慶加盟系統上架包】從快捷 9 後台帶到快捷 1 IS → 我的物件",
    `物件標題：${prop.title || ""}`,
    `公開物件編號：${prop.publicNo || prop.contractNo || ""}`,
    `總價：${prop.price || ""} 萬`,
    `建坪／建物總坪：${prop.build || ""} 坪`,
    `主建物坪數：${prop.mainBuildArea || ""}`,
    `土地坪數：${prop.landArea || ""}`,
    `單價：${prop.unitPrice || ""} 萬/坪`,
    `格局：${prop.layout || ""}`,
    `型態：${prop.type || ""}`,
    `樓層：${prop.floor || ""}`,
    `屋齡：${prop.houseAge || ""}`,
    `社區：${prop.community || ""}`,
    `縣市：${prop.county || ""}`,
    `區域：${prop.area || ""}`,
    `路段：${prop.addressRoad || ""} ${prop.addressDetail || ""}`,
    `登記用途：${prop.registryUse || ""}`,
    `使用分區：${prop.landUseZone || ""}`,
    `管理費：${prop.managementFee || ""}`,
    `車位：${prop.parking || ""}`,
    `緯度：${prop.lat || ""}`,
    `經度：${prop.lng || ""}`,
    `特色：${prop.keyFeatures || ""}`,
    `生活機能：${prop.lifeFeatures || ""}`,
    `房屋描述：${prop.publicNotes || ""}`,
    `廣告文案：${prop.adText || ""}`,
    `照片：\n${(prop.images || []).join("\n")}`
  ].join("\n");
}

function copyYcutPack() {
  const text = ycutPackText(state.seed);
  if (!text) {
    log("沒有可複製的物件資料");
    return;
  }
  copyText(text).then(() => log("已複製永慶上架包。請貼到已開的快捷 1「我的物件」再按官方儲存。"));
}

function saveWhere(kind) {
  if (kind === "is") return "只在快捷 1 IS 存檔";
  if (kind === "huowang") return "快捷 9 後台即可";
  return "兩邊都要對過";
}

function renderYcutBridge(bridge) {
  const root = $("#ycutBridge");
  if (!root || !bridge) return;
  const modules = (bridge.modules || []).map((m) => `
    <tr>
      <td><b>${m.is}</b><div class="note">${m.role}</div></td>
      <td>${m.huowang}</td>
      <td>${saveWhere(m.saveIn)}</td>
    </tr>`).join("");
  const fields = (bridge.fieldMap || []).map(([ycut, hw]) => `<tr><td>${ycut}</td><td>${hw}</td></tr>`).join("");
  root.innerHTML = `
    <div class="section">
      <h2>快捷 1 × 快捷 9 對接</h2>
      <span>兩邊都已開好：1＝IS 上班網站，9＝總控台／火旺後台</span>
    </div>
    <article class="card bridge-card">
      <p class="note">${bridge.limit}</p>
      <div class="card-actions">
        <button type="button" id="copyYcutPack">複製永慶上架包到剪貼簿</button>
        <button type="button" id="focusYcutExisting">叫出快捷 1 IS</button>
        <button type="button" id="focusHubExisting">叫出快捷 9 後台</button>
      </div>
      <ol class="bridge-steps">
        <li>在快捷 9 火旺後台改價格、文案、照片（自有物件／合約／YCUT流通作業）。</li>
        <li>按「複製永慶上架包」。</li>
        <li>切到快捷 1 IS → 我的物件，對欄位貼上，按官方儲存。</li>
        <li>帶看、委託、聯賣只在 IS 做。</li>
      </ol>
      <h3>功能對照</h3>
      <table class="bridge-table">
        <thead><tr><th>快捷 1　IS 上班網站</th><th>快捷 9　火旺後台</th><th>存檔位置</th></tr></thead>
        <tbody>${modules}</tbody>
      </table>
      <h3>欄位對照（貼到我的物件）</h3>
      <table class="bridge-table">
        <thead><tr><th>永慶 IS／物件分享欄位</th><th>火旺後台欄位</th></tr></thead>
        <tbody>${fields}</tbody>
      </table>
      <pre class="copybox" id="ycutPackPreview">${ycutPackText(state.seed)}</pre>
    </article>`;
}

function displayHost(url) {
  return String(url || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
}

const GLOBE_ICON = `<svg class="globe" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="#2f6fed" stroke-width="1.8"/><ellipse cx="12" cy="12" rx="4" ry="9" fill="none" stroke="#2f6fed" stroke-width="1.8"/><path d="M3.5 12h17M12 3c2.6 2.4 3.8 5.3 3.8 9s-1.2 6.6-3.8 9c-2.6-2.4-3.8-5.3-3.8-9s1.2-6.6 3.8-9z" fill="none" stroke="#2f6fed" stroke-width="1.6"/></svg>`;

function recentRow(item) {
  const row = el(`
    <button type="button" class="recent-item" data-shortcut-url="${item.url}" title="${item.name}">
      ${GLOBE_ICON}
      <span class="recent-title">${item.name}</span>
      <span class="recent-url">${displayHost(item.url)}</span>
    </button>`);
  row.addEventListener("click", () => showInRightPane(item.url, item.name));
  return row;
}

function renderShortcuts() {
  const root = $("#rightTabs");
  root.innerHTML = "";
  root.appendChild(el(`<div class="recent-label">Recents</div>`));
  TRACKING_SHORTCUTS.forEach((item) => root.appendChild(recentRow(item)));
  root.appendChild(el(`<div class="recent-label">公用網站</div>`));
  SHORTCUTS.forEach((item) => root.appendChild(recentRow({ name: item.name, url: item.url })));
  root.appendChild(recentRow({ name: "本店官網", url: SHOP_URL }));
  root.appendChild(recentRow({ name: "本店買屋", url: SHOP_LIST_URL }));
  root.appendChild(recentRow({ name: "火旺後台", url: "https://huowang.paohui.org/admin.html.html" }));
}

function bindShortcutKeys() {
  document.addEventListener("keydown", (e) => {
    if (!e.altKey || e.ctrlKey || e.metaKey) return;
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
    if (e.target.id === "copyYcutPack" || e.target.id === "copyYcutPackFromHelp" || e.target.id === "copyYcutPackBar" || e.target.id === "copyYcutPackCase") copyYcutPack();
    const watchBtn = e.target.closest("[data-shop-watch]");
    if (watchBtn) toggleShopWatch(watchBtn.dataset.shopWatch);
    if (e.target.id === "syncShopYongching") syncShopToBoards("yongching", "all");
    if (e.target.id === "syncShopSameStore") syncShopToBoards("same-store", "all");
    if (e.target.id === "syncShopWatched") syncShopToBoards("both", "watched");
    if (e.target.id === "downloadShopPhotos") downloadShopPhotoRestore();
    if (e.target.id === "downloadShopImport") downloadShopImport("all");
    if (e.target.id === "downloadShopWatched") downloadShopImport("watched");
    if (e.target.id === "focusYcutWin" || e.target.id === "focusYcutExisting") focusYcutWindow(YCUT_HOME);
    if (e.target.id === "focusHubWin" || e.target.id === "focusHubExisting") focusHubWindow("https://paohui.org/");
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
  $("#openHubRight").addEventListener("click", () => showInRightPane("https://paohui.org/", "總控台"));
  $("#openAdminRight").addEventListener("click", () => showInRightPane(HUOWANG_ADMIN, "火旺後台"));
  $("#openCurrentRightWindow").addEventListener("click", () => {
    if (!state.rightUrl) state.rightUrl = SHORTCUTS[0].url;
    if (isYcutUrl(state.rightUrl)) {
      focusYcutWindow(state.rightUrl);
      return;
    }
    if (isHubUrl(state.rightUrl)) {
      focusHubWindow(state.rightUrl);
      return;
    }
    const win = openOnRight(state.rightUrl, "huowang-right");
    log(win ? `已把目前網站開在右邊視窗：${state.rightUrl}` : "瀏覽器擋彈窗，請允許後再開");
  });
}

async function boot() {
  loadListings();
  const data = await fetch("./portals.json").then((r) => r.json());
  const listings = await fetch("./listings.json").then((r) => r.json());
  const shop = await fetch("./shop-listings.json").then((r) => r.json()).catch(() => null);
  state.portals = data.portals;
  state.boards = data.listingBoards;
  state.ycutBridge = data.ycutBridge;
  state.seed = listings.property;
  state.shop = shop;
  loadShopWatch();
  $("#heroMeta").innerHTML = `
    <span class="chip">${data.agent.name}　${data.agent.phone}</span>
    <span class="chip">${data.agent.brand}</span>
    <span class="chip">本機目錄 ${data.agent.sourceDisk}</span>
    <span class="chip">永慶員編 C56026（密碼不進 Git）</span>
    <span class="chip">桃園大有已串入 ${listings.property.contractNo}</span>
    ${shop ? `<span class="chip">本店公開物件 ${shop.count} 筆</span>` : ""}`;
  mergeSeedListings(listings.property);
  if (shop) rememberShopPrices();
  renderYcutBridge(data.ycutBridge);
  renderShopTracker();
  renderCase(listings.property);
  renderPortals();
  renderBoards();
  renderShortcuts();
  bind();
  bindShortcutKeys();
  showInRightPane(SHORTCUTS[0].url, SHORTCUTS[0].name, false);
  log("快捷 1 IS 與快捷 9 後台都已開好，不再另開分頁。");
  log("在 9 編輯 → 複製永慶上架包 → 貼到 1 的我的物件 → 官方儲存。");
  log(listings.wired.note);
  log(`已載入桃園大有物件 ${listings.property.title}，總價 ${listings.property.price} 萬。`);
  if (shop) log(`本店官網 ${SHOP_URL} 已追蹤 ${shop.count} 筆公開物件，店長強打 ${shop.featuredIds.length} 筆。可同步到看板或下載火旺匯入檔。`);
}

boot();
