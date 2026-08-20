(function () {
  "use strict";

  const state = {
    catalog: null,
    activeSlotId: "",
    selected: {},
    filter: "",
    showExtra: false,
  };

  function esc(v) {
    return String(v == null ? "" : v).replace(/[&<>'"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c]));
  }

  function money(n) {
    return (Number(n) || 0).toLocaleString("zh-TW") + " 元";
  }

  function allSlots(catalog) {
    return (catalog.slots || []).concat(catalog.extra || []);
  }

  function visibleSlots(catalog) {
    const main = (catalog.slots || []).filter((s) => (s.count || 0) > 0);
    const extra = state.showExtra ? (catalog.extra || []).filter((s) => (s.count || 0) > 0) : [];
    return main.concat(extra);
  }

  function findSlot(catalog, slotId) {
    return allSlots(catalog).find((slot) => String(slot.id) === String(slotId)) || null;
  }

  function findItem(catalog, productId) {
    const id = String(productId || "");
    for (const slot of allSlots(catalog)) {
      const hit = (slot.items || []).find((it) => String(it.id) === id);
      if (hit) return hit;
    }
    return null;
  }

  function itemPriceText(item) {
    const price = Number(item.price) || 0;
    const cost = Number(item.cost) || 0;
    if (price) return { kind: "sale", text: money(price) };
    if (cost) return { kind: "cost", text: "成本 " + money(cost) };
    return { kind: "empty", text: "售價待填" };
  }

  function removeSlotRow(slotId) {
    document.querySelectorAll("#quoteItemRows .quote-item-row").forEach((tr) => {
      if (tr.dataset.builderSlot === slotId) tr.remove();
    });
  }

  function applySlot(catalog, slotId, productId) {
    removeSlotRow(slotId);
    if (!productId) {
      delete state.selected[slotId];
      if (typeof updateQuotePreviewTotal === "function") updateQuotePreviewTotal();
      refreshSelectionUi(catalog);
      return;
    }
    const item = findItem(catalog, productId);
    if (!item) return;
    state.selected[slotId] = String(item.id);
    if (typeof addQuoteItemRow === "function") {
      addQuoteItemRow({
        name: item.name || "",
        brand: item.brand || "",
        spec: item.spec || item.category_type || "",
        qty: 1,
        price: Number(item.price) || 0,
        warranty: "依產品或原廠保固條件辦理",
        taxMode: "none",
        builderSlot: slotId,
        productId: item.id,
      });
      const rows = document.querySelectorAll("#quoteItemRows .quote-item-row");
      const last = rows[rows.length - 1];
      if (last) {
        last.dataset.builderSlot = slotId;
        last.dataset.productId = String(item.id);
      }
    }
    if (typeof updateQuotePreviewTotal === "function") updateQuotePreviewTotal();
    refreshSelectionUi(catalog);
  }

  function selectedCount() {
    return Object.keys(state.selected).filter((id) => state.selected[id]).length;
  }

  function selectedTotal() {
    let total = 0;
    Object.keys(state.selected).forEach((slotId) => {
      const item = findItem(state.catalog, state.selected[slotId]);
      if (item) total += Number(item.price) || 0;
    });
    return total;
  }

  function refreshBuilderTotal() {
    const box = document.getElementById("quoteBuilderTotal");
    if (!box) return;
    const count = selectedCount();
    box.innerHTML = count
      ? "已選 <b>" + count + "</b> 項，合計 <b>" + money(selectedTotal()) + "</b>"
      : "尚未選件";
  }

  function ensureStyles() {
    if (document.getElementById("quoteBuilderStyles")) return;
    const el = document.createElement("style");
    el.id = "quoteBuilderStyles";
    el.textContent = [
      "#quoteBuilderBox{background:#f8fafc}",
      ".qb-head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:12px}",
      ".qb-head h6{margin:0;font-size:18px;font-weight:800;color:#0f172a}",
      "#quoteBuilderTotal{color:#0f766e;font-weight:800}",
      ".qb-picked{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;margin-bottom:12px}",
      ".qb-picked:empty{display:none}",
      ".qb-picked-card{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:8px;align-items:center;background:#fff;border:1px solid #cfe8e3;border-left:5px solid #0f766e;border-radius:10px;padding:8px 10px}",
      ".qb-picked-card img,.qb-picked-card .qb-noimg{width:44px;height:44px;object-fit:cover;border-radius:8px;background:#eef2f7;display:grid;place-items:center;color:#94a3b8;font-size:11px}",
      ".qb-picked-card b{display:block;font-size:13px;line-height:1.3;color:#0f172a}",
      ".qb-picked-card small{display:block;color:#64748b}",
      ".qb-picked-card .qb-clear{border:0;background:#fee2e2;color:#b91c1c;border-radius:8px;min-width:36px;min-height:32px;font-weight:800}",
      ".qb-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}",
      ".qb-pill{border:1px solid #d6e1ef;background:#fff;border-radius:999px;padding:7px 12px;font-weight:800;color:#334155;cursor:pointer}",
      ".qb-pill.is-active{background:#0f766e;border-color:#0f766e;color:#fff}",
      ".qb-pill.is-picked:not(.is-active){border-color:#0f766e;color:#0f766e;background:#ecfdf5}",
      ".qb-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:end;justify-content:space-between;margin-bottom:10px}",
      ".qb-toolbar h6{margin:0;font-size:16px}",
      ".qb-toolbar input{min-width:min(280px,100%)}",
      ".qb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:10px;max-height:520px;overflow:auto;padding:2px}",
      ".qb-card{display:grid;grid-template-rows:auto 1fr auto;gap:8px;text-align:left;background:#fff;border:1px solid #dbe5f2;border-radius:12px;padding:10px;cursor:pointer;color:#0f172a;box-shadow:0 6px 14px rgba(15,23,42,.04)}",
      ".qb-card:hover{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.12)}",
      ".qb-card.is-selected{border-color:#0f766e;background:#ecfdf5;box-shadow:0 0 0 3px rgba(15,118,110,.16)}",
      ".qb-card.is-zero{opacity:.78}",
      ".qb-card img,.qb-card .qb-noimg{width:100%;height:92px;object-fit:cover;border-radius:8px;background:#eef2f7;display:grid;place-items:center;color:#94a3b8;font-weight:800}",
      ".qb-card .qb-name{font-weight:800;line-height:1.35;min-height:2.6em}",
      ".qb-card .qb-meta{color:#64748b;font-size:12px;font-weight:700}",
      ".qb-card .qb-foot{display:flex;justify-content:space-between;gap:8px;align-items:center}",
      ".qb-price{font-weight:900;color:#0f172a}",
      ".qb-price.is-cost{color:#b45309}",
      ".qb-price.is-empty{color:#94a3b8}",
      ".qb-stock{border-radius:999px;padding:3px 8px;font-size:12px;font-weight:800;background:#ecfdf5;color:#047857}",
      ".qb-stock.is-zero{background:#fff7ed;color:#c2410c}",
      ".qb-empty{border:1px dashed #cbd5e1;border-radius:12px;padding:18px;color:#64748b;font-weight:700}",
      ".qb-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}",
    ].join("");
    document.head.appendChild(el);
  }

  function pickedHtml(catalog) {
    const cards = allSlots(catalog).map((slot) => {
      const item = findItem(catalog, state.selected[slot.id]);
      if (!item) return "";
      const price = itemPriceText(item);
      const img = item.image
        ? '<img src="' + esc(item.image) + '" alt="">'
        : '<span class="qb-noimg">無圖</span>';
      return '<div class="qb-picked-card">' +
        img +
        "<div><small>" + esc(slot.label) + "</small><b>" + esc(item.name) + "</b><small>" + esc(price.text) + "　庫存 " + esc(item.stock) + "</small></div>" +
        '<button type="button" class="qb-clear" data-clear-slot="' + esc(slot.id) + '">×</button>' +
        "</div>";
    }).join("");
    return '<div class="qb-picked">' + cards + "</div>";
  }

  function pillsHtml(catalog) {
    const extraCount = (catalog.extra || []).filter((s) => (s.count || 0) > 0).length;
    const pills = visibleSlots(catalog).map((slot) => {
      const picked = !!state.selected[slot.id];
      const cls = [
        "qb-pill",
        slot.id === state.activeSlotId ? "is-active" : "",
        picked ? "is-picked" : "",
      ].filter(Boolean).join(" ");
      return '<button type="button" class="' + cls + '" data-slot-pill="' + esc(slot.id) + '" data-slot-label="' + esc(slot.label) + '">' +
        esc(slot.label) + (picked ? " ✓" : "") +
        "</button>";
    }).join("");
    const extraBtn = extraCount
      ? '<button type="button" class="qb-pill" data-toggle-extra="1">' + (state.showExtra ? "收合其他分類" : "其他分類 " + extraCount) + "</button>"
      : "";
    return '<div class="qb-pills">' + pills + extraBtn + "</div>";
  }

  function cardsHtml(slot) {
    const q = String(state.filter || "").trim().toLowerCase();
    const items = (slot.items || []).filter((item) => {
      if (!q) return true;
      return [item.name, item.brand, item.spec, item.barcode, item.category_type].join(" ").toLowerCase().includes(q);
    });
    if (!items.length) return '<div class="qb-empty">這個分類沒有符合的產品。</div>';
    const selectedId = String(state.selected[slot.id] || "");
    return '<div class="qb-grid">' + items.map((item) => {
      const selected = String(item.id) === selectedId;
      const stock = Number(item.stock) || 0;
      const price = itemPriceText(item);
      const img = item.image
        ? '<img src="' + esc(item.image) + '" alt="">'
        : '<span class="qb-noimg">無圖</span>';
      const meta = [item.brand, item.spec].filter(Boolean).join(" ／ ") || (item.category_type || "");
      return '<button type="button" class="qb-card' + (selected ? " is-selected" : "") + (stock <= 0 ? " is-zero" : "") + '" data-pick-item="' + esc(item.id) + '">' +
        img +
        '<div class="qb-name">' + esc(item.name) + "</div>" +
        '<div class="qb-meta">' + esc(meta) + "</div>" +
        '<div class="qb-foot"><span class="qb-price' + (price.kind === "sale" ? "" : " is-" + price.kind) + '">' + esc(price.text) + '</span><span class="qb-stock' + (stock <= 0 ? " is-zero" : "") + '">庫存 ' + stock + "</span></div>" +
        "</button>";
    }).join("") + "</div>";
  }

  function renderBuilder(catalog) {
    const box = document.getElementById("quoteBuilderBox");
    if (!box) return;
    ensureStyles();
    state.catalog = catalog;
    const slots = visibleSlots(catalog);
    if (!state.activeSlotId || !findSlot(catalog, state.activeSlotId) || (!state.showExtra && String(state.activeSlotId).indexOf("extra-") === 0)) {
      state.activeSlotId = slots[0] ? slots[0].id : "";
    }
    const slot = findSlot(catalog, state.activeSlotId);
    box.innerHTML =
      '<div class="qb-head">' +
        "<div><h6>組裝估價</h6></div>" +
        '<div id="quoteBuilderTotal">尚未選件</div>' +
      "</div>" +
      pickedHtml(catalog) +
      pillsHtml(catalog) +
      (slot
        ? '<div class="qb-toolbar"><div><h6>' + esc(slot.label) + '</h6><div class="small text-muted">' + (slot.count || 0) + ' 筆</div></div>' +
          '<input class="form-control form-control-sm" id="quoteBuilderFilter" value="' + esc(state.filter) + '" placeholder="搜尋這個分類">' +
          "</div>" +
          '<div id="quoteBuilderCards">' + cardsHtml(slot) + "</div>"
        : '<div class="qb-empty">目前沒有可選分類。</div>') +
      '<div class="qb-actions">' +
        '<button class="btn btn-sm btn-outline-primary" type="button" onclick="insertQuoteComputerService && insertQuoteComputerService(\'assembly\')">帶入組裝費 1,500</button>' +
        '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService && insertQuoteComputerService(\'windowsHome\')">Windows 家用版</button>' +
        '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService && insertQuoteComputerService(\'windowsPro\')">Windows 專業版</button>' +
      "</div>";
    refreshBuilderTotal();
    bindBuilder(catalog);
  }

  function refreshSelectionUi(catalog) {
    const box = document.getElementById("quoteBuilderBox");
    if (!box) return;
    const picked = box.querySelector(".qb-picked");
    if (picked) {
      const wrap = document.createElement("div");
      wrap.innerHTML = pickedHtml(catalog);
      const next = wrap.firstElementChild;
      if (next) {
        picked.replaceWith(next);
        box.querySelectorAll("[data-clear-slot]").forEach((btn) => {
          btn.addEventListener("click", () => applySlot(catalog, btn.getAttribute("data-clear-slot"), ""));
        });
      }
    }
    box.querySelectorAll("[data-slot-pill]").forEach((btn) => {
      const id = btn.getAttribute("data-slot-pill") || "";
      const pickedItem = !!state.selected[id];
      btn.classList.toggle("is-picked", pickedItem);
      btn.classList.toggle("is-active", id === state.activeSlotId);
      const label = btn.getAttribute("data-slot-label") || btn.textContent.replace(" ✓", "");
      btn.textContent = label + (pickedItem ? " ✓" : "");
    });
    box.querySelectorAll("[data-pick-item]").forEach((btn) => {
      btn.classList.toggle("is-selected", String(state.selected[state.activeSlotId] || "") === String(btn.getAttribute("data-pick-item") || ""));
    });
    refreshBuilderTotal();
  }

  function bindPickCards(catalog, root) {
    (root || document).querySelectorAll("[data-pick-item]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.getAttribute("data-pick-item") || "";
        const current = String(state.selected[state.activeSlotId] || "");
        applySlot(catalog, state.activeSlotId, current === id ? "" : id);
      });
    });
  }

  function refreshBuilder() {
    if (state.catalog) renderBuilder(state.catalog);
  }

  function bindBuilder(catalog) {
    const box = document.getElementById("quoteBuilderBox");
    if (!box) return;
    box.querySelectorAll("[data-slot-pill]").forEach((btn) => {
      btn.addEventListener("click", () => {
        state.activeSlotId = btn.getAttribute("data-slot-pill") || "";
        state.filter = "";
        renderBuilder(catalog);
      });
    });
    box.querySelector("[data-toggle-extra]")?.addEventListener("click", () => {
      state.showExtra = !state.showExtra;
      renderBuilder(catalog);
    });
    box.querySelectorAll("[data-clear-slot]").forEach((btn) => {
      btn.addEventListener("click", () => applySlot(catalog, btn.getAttribute("data-clear-slot"), ""));
    });
    bindPickCards(catalog, box);
    const filter = document.getElementById("quoteBuilderFilter");
    if (filter) {
      filter.addEventListener("input", () => {
        state.filter = filter.value || "";
        const slot = findSlot(catalog, state.activeSlotId);
        const host = document.getElementById("quoteBuilderCards");
        if (!slot || !host) return;
        host.innerHTML = cardsHtml(slot);
        bindPickCards(catalog, host);
      });
    }
  }

  function mount(catalog) {
    if (document.getElementById("quoteBuilderBox")) {
      renderBuilder(catalog);
      return;
    }
    const search = document.getElementById("quoteProductCatalogSearch");
    if (!search) return;
    const wrap = search.closest(".border.rounded") || search.parentElement;
    if (!wrap || !wrap.parentNode) return;
    const box = document.createElement("div");
    box.id = "quoteBuilderBox";
    box.className = "border rounded p-3 my-3";
    wrap.parentNode.insertBefore(box, wrap);
    renderBuilder(catalog);
  }

  let catalogCache = null;
  async function loadCatalog() {
    if (catalogCache) return catalogCache;
    const res = await fetch("quote-builder-catalog.php", { credentials: "same-origin", cache: "no-store" });
    const json = await res.json();
    if (!json || !json.ok) throw new Error(json && json.error ? json.error : "無法載入產品分類");
    catalogCache = json;
    return json;
  }

  async function boot() {
    try {
      const catalog = await loadCatalog();
      mount(catalog);
    } catch (err) {
      const search = document.getElementById("quoteProductCatalogSearch");
      if (!search) return;
      const wrap = search.closest(".border.rounded") || search.parentElement;
      if (!wrap || document.getElementById("quoteBuilderBox")) return;
      const box = document.createElement("div");
      box.id = "quoteBuilderBox";
      box.className = "alert alert-warning";
      box.textContent = "組裝估價選單載入失敗：" + (err && err.message ? err.message : "請重新登入");
      wrap.parentNode.insertBefore(box, wrap);
    }
  }

  const origGo = window.goPage;
  window.goPage = function (page) {
    if (typeof origGo === "function") origGo(page);
    if (page === "quotationManager") setTimeout(boot, 60);
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => setTimeout(boot, 200));
  else setTimeout(boot, 200);
  window.addEventListener("load", () => setTimeout(boot, 400));
  window.baohuiQuoteBuilder = { loadCatalog, applySlot };
})();
