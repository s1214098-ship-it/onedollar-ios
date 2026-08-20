(function () {
  "use strict";

  function esc(v) {
    return String(v == null ? "" : v).replace(/[&<>'"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" }[c]));
  }

  function money(n) {
    return (Number(n) || 0).toLocaleString("zh-TW") + " 元";
  }

  function optionLabel(item) {
    const bits = [item.name || "-"];
    if (item.brand) bits.push(item.brand);
    if (item.spec) bits.push(item.spec);
    const price = Number(item.price) || 0;
    const cost = Number(item.cost) || 0;
    bits.push(price ? ("售 " + money(price)) : (cost ? ("成本 " + money(cost) + "／售價待填") : "售價待填"));
    bits.push("庫存 " + (item.stock || 0));
    return bits.join(" ｜ ");
  }

  function allSlots(catalog) {
    return (catalog.slots || []).concat(catalog.extra || []);
  }

  function findItem(catalog, productId) {
    const id = String(productId || "");
    for (const slot of allSlots(catalog)) {
      const hit = (slot.items || []).find((it) => String(it.id) === id);
      if (hit) return hit;
    }
    return null;
  }

  function removeSlotRow(slotId) {
    document.querySelectorAll("#quoteItemRows .quote-item-row").forEach((tr) => {
      if (tr.dataset.builderSlot === slotId) tr.remove();
    });
  }

  function applySlot(catalog, slotId, productId) {
    removeSlotRow(slotId);
    if (!productId) {
      if (typeof updateQuotePreviewTotal === "function") updateQuotePreviewTotal();
      refreshBuilderTotal();
      return;
    }
    const item = findItem(catalog, productId);
    if (!item) return;
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
    refreshBuilderTotal();
  }

  function refreshBuilderTotal() {
    const box = document.getElementById("quoteBuilderTotal");
    if (!box) return;
    let total = 0;
    let count = 0;
    document.querySelectorAll("#quoteItemRows .quote-item-row").forEach((tr) => {
      const qty = Number(String(tr.querySelector(".quote-item-qty")?.value || "").replace(/[^\d.]/g, "")) || 0;
      const price = Number(String(tr.querySelector(".quote-item-price")?.value || "").replace(/[^\d.]/g, "")) || 0;
      if (tr.querySelector(".quote-item-name")?.value) {
        count += 1;
        total += qty * price;
      }
    });
    box.textContent = "目前已選 " + count + " 項，合計 " + money(total) + "（售價空白的請在明細補上）";
  }

  function slotSelect(slot) {
    const opts = ['<option value="">— 不選 ' + esc(slot.label) + "（" + (slot.count || 0) + "）—</option>"];
    (slot.items || []).forEach((item) => {
      opts.push('<option value="' + esc(item.id) + '">' + esc(optionLabel(item)) + "</option>");
    });
    return '<select class="form-select form-select-sm quote-builder-select" data-slot="' + esc(slot.id) + '" size="6">' + opts.join("") + "</select>";
  }

  function renderBuilder(catalog) {
    const box = document.getElementById("quoteBuilderBox");
    if (!box) return;
    const main = (catalog.slots || []).filter((s) => s.count > 0);
    const extra = catalog.extra || [];
    const rows = main.map((slot) => {
      const types = (slot.types || []).join("、");
      return '<tr><th class="text-nowrap align-top" style="width:180px;background:#eef4fb">' +
        esc(slot.label) +
        (types ? '<div class="small fw-normal text-muted">分類：' + esc(types) + "</div>" : "") +
        "</th><td>" + slotSelect(slot) + "</td></tr>";
    }).join("");
    const extraRows = extra.map((slot) => {
      return '<tr><th class="text-nowrap align-top" style="width:180px;background:#fff7e8">' +
        esc(slot.label) +
        '<div class="small fw-normal text-muted">其他分類</div></th><td>' + slotSelect(slot) + "</td></tr>";
    }).join("");
    box.innerHTML =
      '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">' +
        '<div><h6 class="mb-0">原價屋式組裝估價</h6>' +
        '<div class="small text-muted">版型比照原價屋估價單：一列一個零件。選項連動寶輝產品主檔與分類，不是原價屋網站售價。</div></div>' +
        '<div class="small"><b id="quoteBuilderTotal">尚未選件</b></div>' +
      "</div>" +
      '<div class="table-responsive" style="max-height:640px;overflow:auto">' +
        '<table class="table table-bordered table-sm align-middle mb-2 bg-white"><tbody>' + rows + extraRows + "</tbody></table>" +
      "</div>" +
      '<div class="d-flex flex-wrap gap-2">' +
        '<button class="btn btn-sm btn-outline-primary" type="button" onclick="insertQuoteComputerService && insertQuoteComputerService(\'assembly\')">帶入組裝費 1,500</button>' +
        '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService && insertQuoteComputerService(\'windowsHome\')">Windows 家用版</button>' +
        '<button class="btn btn-sm btn-outline-secondary" type="button" onclick="insertQuoteComputerService && insertQuoteComputerService(\'windowsPro\')">Windows 專業版</button>' +
        '<span class="small text-muted">產品 ' + esc(catalog.productCount || 0) + " 筆已依分類套進對應欄位。售價空白請在下方明細填。</span>" +
      "</div>";
    box.querySelectorAll(".quote-builder-select").forEach((sel) => {
      sel.addEventListener("change", () => applySlot(catalog, sel.getAttribute("data-slot"), sel.value));
    });
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
    box.style.background = "#f8fafc";
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
      box.textContent = "原價屋式估價選單載入失敗：" + (err && err.message ? err.message : "請重新登入");
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
