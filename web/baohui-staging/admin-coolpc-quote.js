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
    if (document.getElementById("coolpcQuoteStyles")) return;
    const el = document.createElement("style");
    el.id = "coolpcQuoteStyles";
    el.textContent = [
      "#coolpcQuoteBox{background:#fff}",
      ".cpq-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}",
      ".cpq-head h6{margin:0;font-size:18px;font-weight:800}",
      ".cpq-stamp{color:#64748b;font-weight:700}",
      ".cpq-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;max-height:132px;overflow:auto}",
      ".cpq-pill{border:1px solid #d6e1ef;background:#fff;border-radius:999px;padding:7px 12px;font-weight:800;color:#334155;cursor:pointer}",
      ".cpq-pill.is-active{background:#0f766e;border-color:#0f766e;color:#fff}",
      ".cpq-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px}",
      ".cpq-toolbar input{min-width:min(280px,100%)}",
      ".cpq-list{display:grid;gap:6px;max-height:420px;overflow:auto}",
      ".cpq-row{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;align-items:center;border:1px solid #dbe5f2;border-radius:10px;background:#f8fafc;padding:8px 10px}",
      ".cpq-row b{display:block;color:#0f172a;font-size:14px;line-height:1.35}",
      ".cpq-row small{display:block;color:#64748b;font-weight:700}",
      ".cpq-price{font-weight:900;color:#0f766e;white-space:nowrap}",
      ".cpq-empty{border:1px dashed #cbd5e1;border-radius:10px;padding:16px;color:#64748b;font-weight:700}",
    ].join("");
    document.head.appendChild(el);
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
          '<span class="cpq-stamp" id="coolpcQuoteStamp">' + esc(stamp) + (count ? "｜" + count + " 筆" : "") + "</span>" +
          '<button class="btn btn-sm btn-outline-primary" type="button" data-coolpc-refresh="1">重新整理</button>' +
          '<a class="btn btn-sm btn-outline-primary" href="https://coolpc.com.tw/evaluate.php" target="_blank" rel="noopener">開原價屋官網</a>' +
        "</div>" +
      "</div>" +
      '<div class="cpq-pills">' + cats.map((cat) => {
        const on = cat.id === state.activeCat ? " is-active" : "";
        return '<button type="button" class="cpq-pill' + on + '" data-coolpc-cat="' + esc(cat.id) + '">' +
          esc(cat.label) + " " + esc(cat.count || 0) +
          "</button>";
      }).join("") + "</div>" +
      '<div class="cpq-toolbar"><input class="form-control form-control-sm" id="coolpcQuoteFilter" value="' + esc(state.filter) + '" placeholder="搜尋原價屋品項 / 廠牌 / 規格"></div>' +
      (items.length
        ? '<div class="cpq-list">' + items.map((item) => {
            return '<div class="cpq-row">' +
              "<div><b>" + esc(item.name) + "</b><small>" + esc([item.brand, item.category].filter(Boolean).join(" ／ ")) + "</small></div>" +
              '<span class="cpq-price">' + money(item.price) + "</span>" +
              '<button type="button" class="btn btn-sm btn-primary" data-coolpc-insert="' + esc(item.id) + '">帶入</button>' +
              "</div>";
          }).join("") + "</div>"
        : '<div class="cpq-empty">這個分類沒有符合的原價屋品項。</div>');
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
