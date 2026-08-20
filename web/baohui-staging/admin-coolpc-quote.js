(function () {
  "use strict";

  const state = {
    catalog: null,
    activeCat: "",
    filter: "",
    loading: false,
  };

  function esc(v) {
    return String(v == null ? "" : v).replace(/[&<>'"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c]));
  }

  function money(n) {
    return (Number(n) || 0).toLocaleString("zh-TW");
  }

  function ensureStyles() {
    const existing = document.getElementById("coolpcQuoteStyles");
    if (existing && existing.dataset.v === "bands") return;
    if (existing) existing.remove();
    const el = document.createElement("style");
    el.id = "coolpcQuoteStyles";
    el.dataset.v = "bands";
    el.textContent = [
      "#coolpcQuoteBox{background:#fff}",
      ".cpq-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:flex-end;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #e8eef5}",
      ".cpq-head h6{margin:0;font-size:16px;font-weight:800;letter-spacing:.02em;color:#0f172a}",
      ".cpq-stamp{color:#64748b;font-size:13px;font-weight:700}",
      ".cpq-body{display:grid;grid-template-columns:248px minmax(0,1fr);gap:14px;align-items:stretch}",
      ".cpq-nav{display:flex;flex-direction:column;gap:10px;max-height:520px;overflow:auto;padding:8px;border:1px solid #e2e8f0;border-radius:14px;background:#eef2f6}",
      ".cpq-group{border-radius:12px;padding:6px;border:1px solid transparent}",
      ".cpq-group-title{display:block;padding:5px 8px 7px;font-size:11px;font-weight:900;letter-spacing:.12em}",
      ".cpq-cat{display:flex;justify-content:space-between;align-items:center;gap:8px;width:100%;margin:0 0 3px;border:0;border-left:4px solid transparent;border-radius:8px;padding:8px 10px;text-align:left;font-weight:800;color:#1e293b;cursor:pointer}",
      ".cpq-cat-label{min-width:0;line-height:1.3}",
      ".cpq-cat-count{flex:0 0 auto;min-width:28px;padding:1px 7px;border-radius:999px;text-align:center;font-size:11px;font-weight:900;font-variant-numeric:tabular-nums;background:rgba(255,255,255,.72)}",
      ".cpq-group.tone-blue{background:#dbeafe;border-color:#93c5fd}",
      ".cpq-group.tone-blue .cpq-group-title{color:#1d4ed8}",
      ".cpq-group.tone-blue .cpq-cat{background:#eff6ff;border-left-color:#60a5fa}",
      ".cpq-group.tone-blue .cpq-cat.is-alt{background:#bfdbfe}",
      ".cpq-group.tone-teal{background:#ccfbf1;border-color:#5eead4}",
      ".cpq-group.tone-teal .cpq-group-title{color:#0f766e}",
      ".cpq-group.tone-teal .cpq-cat{background:#f0fdfa;border-left-color:#2dd4bf}",
      ".cpq-group.tone-teal .cpq-cat.is-alt{background:#99f6e4}",
      ".cpq-group.tone-amber{background:#fde68a;border-color:#fbbf24}",
      ".cpq-group.tone-amber .cpq-group-title{color:#b45309}",
      ".cpq-group.tone-amber .cpq-cat{background:#fffbeb;border-left-color:#f59e0b}",
      ".cpq-group.tone-amber .cpq-cat.is-alt{background:#fcd34d}",
      ".cpq-group.tone-violet{background:#ddd6fe;border-color:#c4b5fd}",
      ".cpq-group.tone-violet .cpq-group-title{color:#6d28d9}",
      ".cpq-group.tone-violet .cpq-cat{background:#f5f3ff;border-left-color:#a78bfa}",
      ".cpq-group.tone-violet .cpq-cat.is-alt{background:#c4b5fd}",
      ".cpq-group.tone-slate{background:#e2e8f0;border-color:#cbd5e1}",
      ".cpq-group.tone-slate .cpq-group-title{color:#475569}",
      ".cpq-group.tone-slate .cpq-cat{background:#f8fafc;border-left-color:#94a3b8}",
      ".cpq-group.tone-slate .cpq-cat.is-alt{background:#cbd5e1}",
      ".cpq-cat:hover{filter:brightness(.97)}",
      ".cpq-cat.is-active{background:#0f766e!important;border-left-color:#facc15!important;color:#fff;box-shadow:0 6px 14px rgba(15,118,110,.22)}",
      ".cpq-cat.is-active .cpq-cat-count{background:rgba(250,204,21,.95);color:#17201d}",
      ".cpq-main{min-width:0}",
      ".cpq-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px}",
      ".cpq-toolbar input{min-width:min(280px,100%)}",
      ".cpq-list{display:grid;gap:6px;max-height:460px;overflow:auto}",
      ".cpq-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;align-items:center;border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:9px 12px}",
      ".cpq-row:hover{border-color:#99c4bf;background:#f8fbfb}",
      ".cpq-row b{display:block;color:#0f172a;font-size:14px;line-height:1.35;font-weight:700}",
      ".cpq-row small{display:block;color:#64748b;font-weight:700;margin-top:2px}",
      ".cpq-price{font-weight:900;color:#0f766e;white-space:nowrap;font-variant-numeric:tabular-nums}",
      ".cpq-empty{border:1px dashed #cbd5e1;border-radius:10px;padding:16px;color:#64748b;font-weight:700}",
      "@media(max-width:860px){.cpq-body{grid-template-columns:1fr}.cpq-nav{max-height:none}}",
    ].join("");
    document.head.appendChild(el);
  }

  const CATEGORY_GROUPS = [
    { id: "pc", title: "整機／筆電", tone: "blue", match: /小主機|AIO|筆電|平板|穿戴|組裝/ },
    { id: "core", title: "核心零件", tone: "teal", match: /處理器|CPU|主機板|MB|記憶體|RAM|固態|SSD|硬碟|HDD|顯示卡|VGA|隨身碟|記憶卡/ },
    { id: "build", title: "散熱／機殼", tone: "amber", match: /散熱|水冷|機殼|CASE|電源|風扇|燈條/ },
    { id: "io", title: "螢幕／週邊", tone: "violet", match: /螢幕|支架|鍵盤|滑鼠|喇叭|耳機|麥克風|燒錄|USB|線材|轉頭|KVM|印表機|UPS/ },
    { id: "net", title: "網通／其他", tone: "slate", match: /網卡|網通|NAS|IPCAM|作業系統|軟體|福利|回收|周邊|消耗/ },
  ];

  function categoryGroupFor(cat) {
    const label = String(cat && cat.label || "");
    return CATEGORY_GROUPS.find((group) => group.match.test(label)) || CATEGORY_GROUPS[CATEGORY_GROUPS.length - 1];
  }

  function groupedCategories(cats) {
    const buckets = CATEGORY_GROUPS.map((group) => ({ id: group.id, title: group.title, tone: group.tone, items: [] }));
    const byId = Object.fromEntries(buckets.map((bucket) => [bucket.id, bucket]));
    (cats || []).forEach((cat) => {
      byId[categoryGroupFor(cat).id].items.push(cat);
    });
    return buckets.filter((bucket) => bucket.items.length);
  }

  function categories(catalog) {
    return (catalog && catalog.categories) || [];
  }

  function visibleItems(catalog) {
    const q = String(state.filter || "").trim().toLowerCase();
    return ((catalog && catalog.items) || []).filter((item) => {
      if (q) {
        const hay = [item.name, item.brand, item.spec, item.category].join(" ").toLowerCase();
        return hay.includes(q);
      }
      return !state.activeCat || item.cat === state.activeCat;
    });
  }

  function insertItem(item) {
    if (!item || typeof addQuoteItemRow !== "function") return;
    addQuoteItemRow({
      name: item.name || "",
      brand: item.brand || "",
      spec: item.spec || item.category || "",
      qty: 1,
      price: Number(item.price) || 0,
      warranty: "依產品或原廠保固條件辦理",
      taxMode: "none",
    });
    if (typeof updateQuotePreviewTotal === "function") updateQuotePreviewTotal();
    const rows = document.querySelectorAll("#quoteItemRows .quote-item-row");
    const last = rows[rows.length - 1];
    if (last) {
      last.dataset.coolpcId = String(item.id || "");
      last.querySelector(".quote-item-price")?.focus();
    }
  }

  function render(catalog) {
    const box = document.getElementById("coolpcQuoteBox");
    if (!box) return;
    ensureStyles();
    const cats = categories(catalog);
    if (!state.activeCat && cats[0]) {
      const cpu = cats.find((cat) => /CPU|處理器/.test(String(cat.label || "")));
      state.activeCat = (cpu || cats[0]).id;
    }
    const items = visibleItems(catalog).slice(0, 400);
    const stamp = catalog && catalog.fetchedAt ? catalog.fetchedAt + " 更新" : "";
    const count = catalog && catalog.itemCount ? catalog.itemCount : ((catalog && catalog.items) || []).length;
    box.innerHTML =
      '<div class="cpq-head">' +
        "<div><h6>原價屋即時報價</h6></div>" +
        '<div class="d-flex flex-wrap gap-2 align-items-center">' +
          '<span class="cpq-stamp" id="coolpcQuoteStamp">' + esc(stamp) + (count ? "｜" + count + " 筆" : "") + "｜每小時更新</span>" +
          '<button class="btn btn-sm btn-outline-primary" type="button" data-coolpc-refresh="1">重新整理</button>' +
          '<a class="btn btn-sm btn-outline-primary" href="https://coolpc.com.tw/evaluate.php" target="_blank" rel="noopener">開原價屋官網</a>' +
        "</div>" +
      "</div>" +
      '<div class="cpq-body">' +
      '<nav class="cpq-nav" aria-label="原價屋分類">' + groupedCategories(cats).map((group) => {
        return '<section class="cpq-group tone-' + esc(group.tone) + '">' +
          '<strong class="cpq-group-title">' + esc(group.title) + "</strong>" +
          group.items.map((cat, index) => {
            const on = cat.id === state.activeCat ? " is-active" : "";
            const alt = index % 2 ? " is-alt" : "";
            return '<button type="button" class="cpq-cat' + on + alt + '" data-coolpc-cat="' + esc(cat.id) + '">' +
              '<span class="cpq-cat-label">' + esc(cat.label) + "</span>" +
              '<span class="cpq-cat-count">' + esc(cat.count || 0) + "</span>" +
              "</button>";
          }).join("") +
          "</section>";
      }).join("") + "</nav>" +
      '<div class="cpq-main">' +
      '<div class="cpq-toolbar"><input class="form-control form-control-sm" id="coolpcQuoteFilter" value="' + esc(state.filter) + '" placeholder="搜尋原價屋品項 / 廠牌 / 規格"></div>' +
      (items.length
        ? '<div class="cpq-list">' + items.map((item) => {
            return '<div class="cpq-row">' +
              "<div><b>" + esc(item.name) + "</b><small>" + esc([item.brand, item.category].filter(Boolean).join(" ／ ")) + "</small></div>" +
              '<span class="cpq-price">' + money(item.price) + "</span>" +
              '<button type="button" class="btn btn-sm btn-primary" data-coolpc-insert="' + esc(item.id) + '">帶入</button>' +
              "</div>";
          }).join("") + "</div>"
        : '<div class="cpq-empty">這個分類沒有符合的原價屋品項。</div>') +
      "</div></div>";
    bind(catalog);
  }

  function bind(catalog) {
    const box = document.getElementById("coolpcQuoteBox");
    if (!box) return;
    box.querySelectorAll("[data-coolpc-cat]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.activeCat = btn.getAttribute("data-coolpc-cat") || "";
        render(catalog);
      });
    });
    box.querySelector("[data-coolpc-refresh]")?.addEventListener("click", () => {
      loadCatalog(true).then(render).catch(showError);
    });
    box.querySelectorAll("[data-coolpc-insert]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-coolpc-insert") || "";
        const item = ((catalog && catalog.items) || []).find((row) => String(row.id) === id);
        insertItem(item);
      });
    });
    const filter = document.getElementById("coolpcQuoteFilter");
    if (filter) {
      filter.addEventListener("input", () => {
        state.filter = filter.value || "";
        render(catalog);
        const next = document.getElementById("coolpcQuoteFilter");
        if (next) {
          next.focus();
          const len = next.value.length;
          next.setSelectionRange(len, len);
        }
      });
    }
  }

  function showError(err) {
    const box = document.getElementById("coolpcQuoteBox");
    if (!box) return;
    ensureStyles();
    box.innerHTML =
      '<div class="cpq-head"><div><h6>原價屋即時報價</h6></div>' +
      '<a class="btn btn-sm btn-outline-primary" href="https://coolpc.com.tw/evaluate.php" target="_blank" rel="noopener">開原價屋官網</a></div>' +
      '<div class="cpq-empty">' + esc(err && err.message ? err.message : "原價屋報價載入失敗") +
      ' <button class="btn btn-sm btn-outline-primary" type="button" data-coolpc-refresh="1">再試一次</button></div>';
    box.querySelector("[data-coolpc-refresh]")?.addEventListener("click", () => {
      loadCatalog(true).then(render).catch(showError);
    });
  }

  async function loadCatalog(force) {
    if (state.loading) return state.catalog;
    state.loading = true;
    try {
      const url = "coolpc-quote-catalog.php" + (force ? "?refresh=1" : "");
      const res = await fetch(url, { credentials: "same-origin", cache: "no-store" });
      const json = await res.json();
      if (!json || !json.ok) throw new Error(json && json.error ? json.error : "原價屋報價載入失敗");
      state.catalog = json;
      return json;
    } finally {
      state.loading = false;
    }
  }

  async function boot() {
    const box = document.getElementById("coolpcQuoteBox");
    if (!box) return;
    ensureStyles();
    if (!state.catalog) {
      box.innerHTML = '<div class="cpq-empty">正在載入原價屋即時報價...</div>';
    }
    try {
      const catalog = await loadCatalog(false);
      render(catalog);
    } catch (err) {
      showError(err);
    }
  }

  const origGo = window.goPage;
  window.goPage = function (page) {
    if (typeof origGo === "function") origGo(page);
    if (page === "quotationManager") setTimeout(boot, 80);
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => setTimeout(boot, 240));
  else setTimeout(boot, 240);
  window.addEventListener("load", () => setTimeout(boot, 450));
  window.baohuiCoolpcQuote = { loadCatalog, insertItem };
})();
