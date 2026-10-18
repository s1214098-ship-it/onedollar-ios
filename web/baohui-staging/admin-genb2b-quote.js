(function () {
  "use strict";
  const state = { query: "", items: [], loading: false, activeCategory: "", autoRefreshStarted: false, autoRefreshed: false };
  const CATEGORY_GROUPS = [
    { title: "整機／行動裝置", items: [
      ["桌上型電腦", "桌上型電腦"], ["筆記型電腦", "筆記型電腦"], ["平板／手機", "平板 手機"], ["伺服器／工作站", "伺服器 工作站"]
    ]},
    { title: "核心零件", items: [
      ["處理器 CPU", "CPU 處理器"], ["主機板 MB", "主機板"], ["記憶體 RAM", "記憶體"], ["顯示卡 VGA", "顯示卡"], ["電源供應器", "電源供應器"], ["機殼", "機殼"], ["散熱組件", "CPU 散熱器"]
    ]},
    { title: "儲存設備", items: [
      ["固態硬碟 SSD", "SSD 固態硬碟"], ["傳統硬碟 HDD", "硬碟 HDD"], ["NAS 網路儲存", "NAS"], ["隨身碟／記憶卡", "隨身碟 記憶卡"]
    ]},
    { title: "顯示／列印", items: [
      ["螢幕 LCD", "螢幕"], ["投影機／數位看板", "投影機 數位看板"], ["印表機", "印表機"], ["掃描器／標籤機", "掃描器 標籤機"], ["墨水／碳粉／耗材", "墨水 碳粉 耗材"]
    ]},
    { title: "周邊／網通", items: [
      ["鍵盤／滑鼠", "鍵盤 滑鼠"], ["耳機／喇叭", "耳機 喇叭"], ["網路設備", "網路 路由器"], ["UPS 不斷電", "UPS 不斷電"], ["視訊／監控", "視訊 監控"], ["線材／轉接器", "線材 轉接器"]
    ]},
    { title: "軟體／其他", items: [
      ["Windows", "Windows"], ["Office", "Office"], ["防毒／應用軟體", "防毒 軟體"], ["消費電子／家電", "消費電子 家電"]
    ]}
  ];
  const esc = (v) => String(v == null ? "" : v).replace(/[&<>'"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c]));
  const money = (n) => (Number(n) || 0).toLocaleString("zh-TW");

  function styles() {
    if (document.getElementById("genb2bQuoteStyles")) return;
    const el = document.createElement("style");
    el.id = "genb2bQuoteStyles";
    el.textContent = ".gbq-box{background:#fff}.gbq-layout{display:grid;grid-template-columns:236px minmax(0,1fr);gap:16px;margin-top:12px}.gbq-nav{display:flex;flex-direction:column;max-height:520px;overflow:auto;padding:8px;border-radius:12px;background:#18201d;color:#dfe7e2}.gbq-group+.gbq-group{margin-top:4px}.gbq-group-title{display:block;margin:4px 6px 2px;padding:9px 8px 5px;border-top:1px solid rgba(255,255,255,.08);font-size:11px;font-weight:700;letter-spacing:.12em;color:#9fb3aa}.gbq-group:first-child .gbq-group-title{border-top:0}.gbq-cat{display:flex;width:100%;border:0;background:transparent;border-radius:8px;padding:8px 12px;text-align:left;font-size:13px;font-weight:650;line-height:1.35;color:#dfe7e2;cursor:pointer}.gbq-cat:hover{background:#2c3b35;color:#fff}.gbq-cat.is-active{background:#0f766e;color:#fff;box-shadow:inset 4px 0 0 #facc15}.gbq-main{min-width:0}.gbq-search{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:8px}.gbq-list{margin-top:12px;border:1px solid #dbe5ee;border-radius:10px;max-height:430px;overflow:auto;background:#fff}.gbq-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #edf2f7}.gbq-row:last-child{border-bottom:0}.gbq-row:hover{background:#f0fdfa}.gbq-name{font-weight:800;color:#0f172a}.gbq-meta{font-size:12px;color:#64748b;margin-top:3px}.gbq-stock{font-size:12px;font-weight:800;color:#0f766e}.gbq-price{font-size:16px;font-weight:900;color:#b45309;white-space:nowrap}.gbq-empty{margin-top:12px;padding:15px;border:1px dashed #cbd5e1;border-radius:10px;color:#64748b}@media(max-width:860px){.gbq-layout{grid-template-columns:1fr}.gbq-nav{max-height:280px}}@media(max-width:720px){.gbq-search{grid-template-columns:1fr}.gbq-row{grid-template-columns:1fr auto}.gbq-row .btn{grid-column:1/-1}.gbq-price{grid-row:1;grid-column:2}}";
    document.head.appendChild(el);
  }

  function shell(message) {
    const box = document.getElementById("genb2bQuoteBox");
    if (!box) return null;
    styles();
    box.classList.add("gbq-box");
    const categoryHtml = CATEGORY_GROUPS.map((group) => '<section class="gbq-group"><strong class="gbq-group-title">' + esc(group.title) + '</strong>' + group.items.map((item) => '<button type="button" class="gbq-cat' + (state.activeCategory === item[1] ? ' is-active' : '') + '" data-genb2b-category="' + esc(item[1]) + '">' + esc(item[0]) + '</button>').join('') + '</section>').join('');
    box.innerHTML = '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><div><h6 class="mb-0">捷元分類選品</h6><div class="small text-muted">' + (state.autoRefreshed ? '本次進入估價單已自動更新；可再選分類或搜尋型號、品名與捷元料號。' : '進入估價單會自動更新；也可選分類或搜尋型號、品名與捷元料號。') + '</div></div><a class="btn btn-sm btn-outline-success" href="https://www.genb2b.com/" target="_blank" rel="noopener">開捷元官網</a></div><div class="gbq-layout"><nav class="gbq-nav" aria-label="捷元產品分類">' + categoryHtml + '</nav><div class="gbq-main"><div class="gbq-search"><input class="form-control" id="genb2bQuoteSearch" value="' + esc(state.query) + '" placeholder="搜尋型號、品名或捷元料號（至少 2 字）"><button class="btn btn-success" type="button" data-genb2b-search>搜尋捷元</button></div><div id="genb2bQuoteResults">' + (message || '<div class="gbq-empty">正在自動更新捷元商品...</div>') + '</div></div></div>';
    const input = box.querySelector("#genb2bQuoteSearch");
    input?.addEventListener("keydown", (event) => { if (event.key === "Enter") { event.preventDefault(); search(true); } });
    box.querySelector("[data-genb2b-search]")?.addEventListener("click", () => search(true));
    box.querySelectorAll("[data-genb2b-category]").forEach((button) => button.addEventListener("click", () => {
      state.activeCategory = button.dataset.genb2bCategory || "";
      state.query = state.activeCategory;
      search(true, state.activeCategory);
    }));
    return box;
  }

  function render() {
    const box = shell("");
    if (!box) return;
    const target = box.querySelector("#genb2bQuoteResults");
    if (!state.items.length) { target.innerHTML = '<div class="gbq-empty">捷元沒有找到符合「' + esc(state.query) + '」的公開商品。</div>'; return; }
    target.innerHTML = '<div class="small text-muted mt-2">找到 ' + state.items.length + ' 筆；價格標示為捷元官網公開售價（登錄價），實際成交與供貨仍以捷元為準。</div><div class="gbq-list">' + state.items.map((item) => '<div class="gbq-row"><div><div class="gbq-name">' + esc(item.id) + '｜' + esc(item.name) + '</div><div class="gbq-meta">' + esc(item.spec || "未提供公開規格") + '</div><div class="gbq-stock">' + esc(item.availability) + '</div></div><div class="gbq-price">$' + money(item.price) + '</div><button class="btn btn-sm btn-success" type="button" data-genb2b-insert="' + esc(item.id) + '">帶入估價單</button></div>').join("") + "</div>";
    box.querySelectorAll("[data-genb2b-insert]").forEach((button) => button.addEventListener("click", () => insert(state.items.find((item) => item.id === button.dataset.genb2bInsert))));
  }

  function insert(item) {
    if (!item || typeof window.addQuoteItemRow !== "function") return;
    window.addQuoteItemRow({ name: item.name || "", brand: item.brand || "", spec: [item.id, item.spec].filter(Boolean).join("｜"), qty: 1, price: Number(item.price) || 0, warranty: "依產品或原廠保固條件辦理", taxMode: "external", productSource: "genb2b" });
    if (typeof window.updateQuotePreviewTotal === "function") window.updateQuotePreviewTotal();
    const rows = document.querySelectorAll("#quoteItemRows .quote-item-row");
    const last = rows[rows.length - 1];
    if (last) { last.dataset.genb2bId = item.id || ""; last.dataset.productSource = "genb2b"; last.querySelector(".quote-item-price")?.focus(); }
  }

  async function search(force, requestedQuery) {
    const input = document.getElementById("genb2bQuoteSearch");
    const query = String(requestedQuery || input?.value || "").trim();
    if (query.length < 2) { alert("請輸入至少 2 個字元，例如 RTX5070 或捷元料號。"); input?.focus(); return; }
    if (state.loading) return;
    state.query = query; state.loading = true;
    const box = shell('<div class="gbq-empty">正在查詢捷元公開商品…</div>');
    box?.querySelector("[data-genb2b-search]")?.setAttribute("disabled", "disabled");
    try {
      const url = "genb2b-quote-catalog.php?q=" + encodeURIComponent(query) + (force ? "&refresh=1" : "");
      const res = await fetch(url, { credentials: "same-origin", cache: "no-store" });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || "捷元商品查詢失敗");
      state.items = Array.isArray(json.items) ? json.items : [];
      if (force) state.autoRefreshed = true;
      render();
    } catch (error) {
      shell('<div class="gbq-empty text-danger">' + esc(error.message || "捷元商品查詢失敗") + '</div>');
    } finally { state.loading = false; }
  }

  function boot() {
    if (!document.getElementById("genb2bQuoteBox")) return;
    if (state.autoRefreshStarted) {
      if (state.query) render();
      else shell("");
      return;
    }
    state.autoRefreshStarted = true;
    state.activeCategory = "CPU 處理器";
    state.query = state.activeCategory;
    shell('<div class="gbq-empty">正在自動更新捷元處理器商品...</div>');
    search(true, state.activeCategory);
  }
  const previousGoPage = window.goPage;
  window.goPage = function (page) { if (typeof previousGoPage === "function") previousGoPage(page); if (page === "quotationManager") setTimeout(boot, 100); };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => setTimeout(boot, 250)); else setTimeout(boot, 250);
  window.baohuiGenb2bQuote = { search, insert };
})();
