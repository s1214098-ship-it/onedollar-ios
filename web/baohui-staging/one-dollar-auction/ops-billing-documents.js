(function () {
  "use strict";

  function normalizeName(value) {
    return String(value || "").toLowerCase().replace(/\s+/g, " ").trim();
  }

  function customerCore(value) {
    return normalizeName(value).replace(/[（(].*$/, "").replace(/\s+/g, "");
  }

  function customerNameMatches(rowName, query) {
    const row = normalizeName(rowName);
    const wanted = normalizeName(query);
    if (!wanted || !row) return false;
    if (row === wanted) return true;
    if (row.indexOf(wanted) !== -1 || wanted.indexOf(row) !== -1) return true;
    const coreRow = customerCore(rowName);
    const coreWanted = customerCore(query);
    if (!coreRow || !coreWanted) return false;
    return coreRow === coreWanted || coreRow.indexOf(coreWanted) !== -1 || coreWanted.indexOf(coreRow) !== -1;
  }

  function billingCandidateRows() {
    const deliveries = Array.isArray(window.billingDeliveryCandidates) ? window.billingDeliveryCandidates : [];
    if (deliveries.length) return deliveries;
    const receipts = Array.isArray(window.receiptDocumentCandidates) ? window.receiptDocumentCandidates : [];
    return receipts.filter(function (row) {
      return row && (row.selection_type === "delivery" || row.selection_id || row.delivery_no);
    });
  }

  function filterRows(customerValue, sourceRows, from, to, basis) {
    const rows = Array.isArray(sourceRows) ? sourceRows : [];
    const matches = rows.filter(function (row) {
      return customerNameMatches(row.customer || row.customer_name || "", customerValue);
    });
    const dated = matches.filter(function (row) {
      const date = String((basis === "delivery" ? (row.delivery_date || row.date) : row.date) || "").slice(0, 10);
      if (from && date && date < from) return false;
      if (to && date && date > to) return false;
      return true;
    });
    return { inPeriod: dated, all: matches };
  }

  function renderBillingPicker() {
    const input = document.getElementById("billingCustomerName");
    const box = document.getElementById("billingCustomerDocuments");
    if (!input || !box) return false;
    const query = String(input.value || "").trim();
    if (!query) {
      box.innerHTML = '<div class="customer-document-empty">請先選擇客戶，系統才會列出該客戶的未結單據。</div>';
      return true;
    }
    const from = document.getElementById("billingPeriodFrom") ? document.getElementById("billingPeriodFrom").value : "";
    const to = document.getElementById("billingPeriodTo") ? document.getElementById("billingPeriodTo").value : "";
    const source = billingCandidateRows();
    const grouped = filterRows(query, source, from, to, "delivery");
    let rows = grouped.inPeriod;
    let note = "";
    if (!rows.length && grouped.all.length) {
      rows = grouped.all;
      note = '<div class="customer-document-empty">本期區間沒有單據，改列出這位客戶其他未結出貨單。</div>';
    }
    if (!rows.length) {
      box.innerHTML = source.length
        ? '<div class="customer-document-empty">找不到這位客戶的未結單據。可改期間，或從上方月結客戶清單按「帶入月結請款單」。</div>'
        : '<div class="customer-document-empty">未結單據清單還在載入，請再選一次客戶。</div>';
      return true;
    }
    if (typeof customerDocumentOption === "function") {
      box.innerHTML = note + rows.map(function (row) {
        return customerDocumentOption(row, "billing_delivery_ids[]");
      }).join("");
    } else {
      box.innerHTML = note + rows.map(function (row) {
        const id = row.selection_id || row.delivery_id || row.schedule_id || "";
        return '<label class="customer-document-option"><input type="checkbox" name="billing_delivery_ids[]" value="' + String(id).replace(/"/g, "&quot;") + '" data-outstanding="' + Number(row.outstanding || 0) + '" checked><span><b>' + String(row.date || "-") + "</b><small>" + String(row.document_no || "") + "</small></span><span><b>" + String(row.product || "出貨單") + "</b></span><strong>未收<br>" + String(row.outstanding || 0) + "</strong></label>";
      }).join("");
    }
    box.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
      checkbox.checked = true;
    });
    return true;
  }

  function hydrateBillingTab() {
    if (!document.getElementById("billingCreateForm") && !document.getElementById("billingCustomerDocuments")) return;
    if (typeof refreshFinanceDocumentPickers === "function") {
      try { refreshFinanceDocumentPickers(); } catch (error) { console.error(error); }
    }
    renderBillingPicker();
  }

  function wrapTabLoaders() {
    const origOpen = window.openOpsTab;
    if (typeof origOpen === "function" && !origOpen.__baohuiBillingHydrate) {
      const wrapped = function (id) {
        const target = origOpen(id);
        if (String(target || id || "") === "finance-request") hydrateBillingTab();
        return target;
      };
      wrapped.__baohuiBillingHydrate = true;
      window.openOpsTab = wrapped;
    }
    const origLoad = window.loadOpsTab;
    if (typeof origLoad === "function" && !origLoad.__baohuiBillingHydrate) {
      const wrappedLoad = function (id, url) {
        const result = origLoad(id, url);
        const done = function (ok) {
          if (String(id || "") === "finance-request" && ok) hydrateBillingTab();
          return ok;
        };
        if (result && typeof result.then === "function") return result.then(done);
        if (String(id || "") === "finance-request") done(true);
        return result;
      };
      wrappedLoad.__baohuiBillingHydrate = true;
      window.loadOpsTab = wrappedLoad;
    }
  }

  document.addEventListener("input", function (event) {
    if (event.target && (event.target.id === "billingCustomerName" || event.target.id === "billingCycleMonth" || event.target.id === "billingPeriodFrom" || event.target.id === "billingPeriodTo")) {
      renderBillingPicker();
    }
  });
  document.addEventListener("change", function (event) {
    if (event.target && (event.target.id === "billingCustomerName" || event.target.id === "billingCycleType" || event.target.id === "billingCycleMonth" || event.target.id === "billingPeriodFrom" || event.target.id === "billingPeriodTo" || event.target.id === "billingCustomerBranch")) {
      if (event.target.id === "billingCycleType" || event.target.id === "billingCycleMonth") {
        if (typeof syncBillingCreatePeriod === "function") syncBillingCreatePeriod();
      }
      renderBillingPicker();
    }
  });
  document.addEventListener("click", function (event) {
    const button = event.target.closest ? event.target.closest("[data-billing-auto-customer]") : null;
    if (!button) return;
    event.preventDefault();
    const input = document.getElementById("billingCustomerName");
    if (input) input.value = button.getAttribute("data-billing-auto-customer") || "";
    const month = document.getElementById("billingCycleMonth");
    if (month && /^\d{4}-\d{2}$/.test(button.getAttribute("data-billing-month") || "")) month.value = button.getAttribute("data-billing-month");
    if (typeof syncBillingCreatePeriod === "function") syncBillingCreatePeriod();
    renderBillingPicker();
    input && input.closest("form") && input.closest("form").scrollIntoView({ behavior: "smooth", block: "start" });
  });

  function boot() {
    wrapTabLoaders();
    const current = String(location.hash || "").replace("#", "");
    if (current === "finance-request" || document.getElementById("billingCreateForm")) hydrateBillingTab();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
  window.baohuiHydrateBillingTab = hydrateBillingTab;
  window.baohuiRenderBillingPicker = renderBillingPicker;
})();
