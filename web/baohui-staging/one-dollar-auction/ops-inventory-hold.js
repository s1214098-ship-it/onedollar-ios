(function () {
  "use strict";

  let lastHoldMatches = [];

  function injectHoldPickerStyles() {
    if (document.getElementById("baohui-hold-picker-style")) return;
    const style = document.createElement("style");
    style.id = "baohui-hold-picker-style";
    style.textContent = [
      "#manualHoldProductResults{position:relative;z-index:30;max-height:340px;overflow:auto;margin-top:8px;display:grid;gap:8px}",
      "#manualHoldProductResults .product-result-card{cursor:pointer;pointer-events:auto;width:100%;text-align:left}",
      "#manualHoldProductResults .product-pick-action{pointer-events:none}"
    ].join("");
    document.head.appendChild(style);
  }

  function hydrateInventoryHoldTab() {
    const form = document.getElementById("manualInventoryHoldForm");
    if (!form) return;
    injectHoldPickerStyles();
    if (typeof loadScheduleProducts === "function") loadScheduleProducts();
    if (typeof loadSalesCustomerDirectory === "function") loadSalesCustomerDirectory();
    if (typeof setupManualInventoryHoldForm === "function" && form.dataset.ready !== "1") {
      setupManualInventoryHoldForm();
    }
    renderHoldPickerResults();
  }

  function wrapTabLoaders() {
    const origOpen = window.openOpsTab;
    if (typeof origOpen === "function" && !origOpen.__baohuiHoldHydrate) {
      const wrapped = function (id) {
        const target = origOpen(id);
        if (String(target || id || "") === "inventory-holds") hydrateInventoryHoldTab();
        return target;
      };
      wrapped.__baohuiHoldHydrate = true;
      window.openOpsTab = wrapped;
      window.showOpsTab = wrapped;
    }
    const origLoad = window.loadOpsTab;
    if (typeof origLoad === "function" && !origLoad.__baohuiHoldHydrate) {
      const wrappedLoad = function (id, url) {
        const result = origLoad(id, url);
        const done = function (ok) {
          if (String(id || "") === "inventory-holds" && ok) hydrateInventoryHoldTab();
          return ok;
        };
        if (result && typeof result.then === "function") return result.then(done);
        if (String(id || "") === "inventory-holds") done(true);
        return result;
      };
      wrappedLoad.__baohuiHoldHydrate = true;
      window.loadOpsTab = wrappedLoad;
    }
  }

  function escapeHoldHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function productLookupKeys(product) {
    const aliases = Array.isArray(product && product.search_aliases) ? product.search_aliases : [];
    return [product && product.id, product && product.barcode, product && product.original_product_code, product && product.cost_barcode]
      .concat(aliases)
      .map(function (value) { return String(value || "").trim(); })
      .filter(Boolean);
  }

  function normalizeHoldCode(value) {
    return String(value || "").toLowerCase().replace(/[^a-z0-9]/g, "");
  }

  function findHoldProduct(id) {
    const wanted = String(id || "").trim();
    if (!wanted) return null;
    const fromLast = lastHoldMatches.find(function (product) {
      return productLookupKeys(product).indexOf(wanted) !== -1;
    });
    if (fromLast) return fromLast;
    if (typeof findProductById === "function") {
      const byId = findProductById(wanted);
      if (byId) return byId;
    }
    if (typeof findScheduleProduct === "function") {
      const byCode = findScheduleProduct(wanted);
      if (byCode) return byCode;
    }
    const matches = typeof productSearchMatches === "function" ? productSearchMatches(wanted, 8, true) : [];
    const code = normalizeHoldCode(wanted);
    return matches.find(function (product) {
      return productLookupKeys(product).some(function (key) {
        return key === wanted || normalizeHoldCode(key) === code;
      });
    }) || null;
  }

  function holdPickerCardHtml(product) {
    if (typeof manualHoldProductResultHtml === "function") return manualHoldProductResultHtml(product);
    const available = typeof scheduleProductAvailable === "function" ? scheduleProductAvailable(product) : 0;
    const id = String(product.id || product.barcode || "");
    const spec = [product.barcode, product.color, product.size, product.spec].filter(Boolean).join(" / ");
    return '<button type="button" class="product-result-card' + (available <= 0 ? " is-zero-stock" : "") + '" data-manual-hold-product="' + escapeHoldHtml(id) + '">' +
      (product.image ? '<img src="' + escapeHoldHtml(product.image) + '" alt="">' : '<span class="no-img">無圖</span>') +
      "<span><b>" + escapeHoldHtml(product.title || id || "商品") + "</b><small>" + escapeHoldHtml(spec) + "</small>" +
      '<small class="' + (available <= 0 ? "stock-warning" : "") + '">可用庫存 ' + escapeHoldHtml(String(available)) + "</small>" +
      '<span class="product-pick-action">' + (available > 0 ? "加入寄庫" : "無庫存") + "</span></span></button>";
  }

  function holdPickerMatches(query) {
    const q = String(query || "").trim();
    let matches = typeof productSearchMatches === "function" ? productSearchMatches(q, q ? 12 : 20, true) : [];
    if (q && typeof findScheduleProduct === "function") {
      const exact = findScheduleProduct(q);
      if (exact && !matches.some(function (row) { return String(row.id || "") === String(exact.id || ""); })) {
        matches = [exact].concat(matches);
      }
    }
    matches = matches.slice().sort(function (a, b) {
      const availableA = typeof scheduleProductAvailable === "function" ? scheduleProductAvailable(a) : 0;
      const availableB = typeof scheduleProductAvailable === "function" ? scheduleProductAvailable(b) : 0;
      return availableB - availableA;
    });
    lastHoldMatches = matches;
    return matches;
  }

  function renderHoldPickerResults() {
    const input = document.getElementById("manualHoldProductSearch");
    const box = document.getElementById("manualHoldProductResults");
    if (!input || !box) return;
    const paint = function () {
      const matches = holdPickerMatches(input.value);
      if (!matches.length) {
        box.innerHTML = String(input.value || "").trim()
          ? '<div class="muted">找不到符合的庫存商品。</div>'
          : '<div class="muted">點選下方現有庫存，或輸入編號、條碼、名稱。</div>';
        return;
      }
      box.innerHTML = matches.map(holdPickerCardHtml).join("");
    };
    if (typeof window.scheduleProductsLoaded === "boolean" && !window.scheduleProductsLoaded) {
      box.innerHTML = '<div class="muted">商品目錄載入中…</div>';
    }
    if (typeof loadScheduleProducts === "function") {
      Promise.resolve(loadScheduleProducts()).then(paint);
      return;
    }
    paint();
  }

  function addHoldProduct(product) {
    if (!product) {
      alert("找不到這個商品，請再搜尋一次後點「加入寄庫」。");
      return false;
    }
    if (typeof addManualHoldProduct === "function") {
      try {
        addManualHoldProduct(product);
        lastHoldMatches = [];
        return true;
      } catch (error) {
        console.error("[inventory-hold] addManualHoldProduct", error);
      }
    }
    alert("商品無法加入寄庫單，請重新整理後再試。");
    return false;
  }

  function holdProductButton(event) {
    return event.target && event.target.closest ? event.target.closest("[data-manual-hold-product]") : null;
  }

  function pickHoldProduct(event) {
    const button = holdProductButton(event);
    if (!button) return false;
    event.preventDefault();
    const raw = button.getAttribute("data-manual-hold-product") || button.dataset.manualHoldProduct || "";
    addHoldProduct(findHoldProduct(raw));
    return true;
  }

  function isBarcodeLikeQuery(value) {
    const q = String(value || "").trim();
    if (!q) return false;
    if (/[\u3400-\u9fff]/.test(q)) return false;
    return /[a-z0-9]/i.test(q);
  }

  function isComposingEnter(event) {
    return !!(event.isComposing || event.keyCode === 229);
  }

  document.addEventListener("input", function (event) {
    if (event.target && event.target.id === "manualHoldProductSearch") renderHoldPickerResults();
  });
  document.addEventListener("focusin", function (event) {
    if (!event.target || event.target.id !== "manualHoldProductSearch") return;
    renderHoldPickerResults();
  });
  document.addEventListener("pointerdown", function (event) {
    pickHoldProduct(event);
  }, true);
  document.addEventListener("keydown", function (event) {
    if (!event.target || event.target.id !== "manualHoldProductSearch" || event.key !== "Enter") return;
    if (isComposingEnter(event)) return;
    event.preventDefault();
    const run = function () {
      const query = event.target.value;
      const matches = holdPickerMatches(query);
      if (!matches.length) {
        renderHoldPickerResults();
        return;
      }
      if (!isBarcodeLikeQuery(query) && matches.length > 1) {
        renderHoldPickerResults();
        return;
      }
      const product = (typeof findScheduleProduct === "function" ? findScheduleProduct(query) : null)
        || matches.find(function (row) {
          return typeof scheduleProductAvailable === "function" ? scheduleProductAvailable(row) > 0 : true;
        })
        || matches[0];
      addHoldProduct(product);
    };
    if (typeof withScheduleProducts === "function") withScheduleProducts(run);
    else run();
  });
  document.addEventListener("submit", function (event) {
    const form = event.target;
    if (!form || form.id !== "manualInventoryHoldForm") return;
    if (form.querySelector('input[name="hold_product_ids[]"]')) return;
    event.preventDefault();
    alert("請先搜尋並加入至少一項商品。");
  });
  document.addEventListener("click", function (event) {
    const link = event.target.closest ? event.target.closest("#stock-search .stock-tool-btn, #stock-search .stock-search-clear") : null;
    if (!link) return;
    const status = document.getElementById("stockFilterStatus");
    if (status) {
      status.textContent = "正在套用篩選，請稍候…";
      status.hidden = false;
    }
  });

  function boot() {
    wrapTabLoaders();
    injectHoldPickerStyles();
    const current = String(location.hash || "").replace("#", "");
    if (current === "inventory-holds" || document.getElementById("manualInventoryHoldForm")) hydrateInventoryHoldTab();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
  window.baohuiHydrateInventoryHoldTab = hydrateInventoryHoldTab;
  window.baohuiRenderHoldPickerResults = renderHoldPickerResults;
})();
