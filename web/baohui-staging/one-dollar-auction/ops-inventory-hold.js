(function () {
  "use strict";

  function hydrateInventoryHoldTab() {
    const form = document.getElementById("manualInventoryHoldForm");
    if (!form) return;
    if (typeof loadScheduleProducts === "function") loadScheduleProducts();
    if (typeof loadSalesCustomerDirectory === "function") loadSalesCustomerDirectory();
    if (typeof setupManualInventoryHoldForm === "function") {
      form.dataset.ready = "";
      setupManualInventoryHoldForm();
    }
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
          if (String(id || "") === "inventory-holds" || ok) hydrateInventoryHoldTab();
          return ok;
        };
        if (result && typeof result.then === "function") return result.then(done);
        done(true);
        return result;
      };
      wrappedLoad.__baohuiHoldHydrate = true;
      window.loadOpsTab = wrappedLoad;
    }
  }

  document.addEventListener("input", function (event) {
    if (event.target && event.target.id === "manualHoldProductSearch" && typeof renderManualHoldProductResults === "function") {
      renderManualHoldProductResults();
    }
  });
  document.addEventListener("focusin", function (event) {
    if (event.target && event.target.id !== "manualHoldProductSearch") return;
    if (typeof loadScheduleProducts === "function") {
      loadScheduleProducts().then(function () {
        if (typeof renderManualHoldProductResults === "function") renderManualHoldProductResults();
      });
    }
  });
  document.addEventListener("keydown", function (event) {
    if (!event.target || event.target.id !== "manualHoldProductSearch" || event.key !== "Enter") return;
    event.preventDefault();
    if (typeof withScheduleProducts !== "function") return;
    withScheduleProducts(function () {
      const matches = typeof productSearchMatches === "function" ? productSearchMatches(event.target.value, 10, true) : [];
      const product = matches.find(function (row) {
        return typeof scheduleProductAvailable === "function" ? scheduleProductAvailable(row) > 0 : true;
      }) || matches[0];
      if (product && typeof addManualHoldProduct === "function") addManualHoldProduct(product);
    });
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
    const current = String(location.hash || "").replace("#", "");
    if (current === "inventory-holds" || document.getElementById("manualInventoryHoldForm")) hydrateInventoryHoldTab();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
  window.baohuiHydrateInventoryHoldTab = hydrateInventoryHoldTab;
})();
