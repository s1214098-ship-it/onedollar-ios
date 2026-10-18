(function () {
  "use strict";

  const KNOWN = Array.isArray(window.BAOHUI_CUSTOMER_COMPANIES) ? window.BAOHUI_CUSTOMER_COMPANIES : [
    {
      key: "daowenxi",
      name: "宜蘭縣私立達文西幼兒園",
      aliases: ["宜蘭縣私立達文西幼兒園", "達文西幼兒園", "達文西幼", "達文西"],
      branches: [
        { name: "雪山村", code: "209" },
        { name: "幼兒園", code: "" },
        { name: "托嬰中心", code: "" }
      ]
    }
  ];

  function norm(value) {
    return String(value || "").toLowerCase().replace(/\s+/g, "").trim();
  }

  function parsePartyName(raw) {
    const text = String(raw || "").trim();
    const match = text.match(/^(.+?)\s*[（(]([^）)]+)[）)](?:\s*[（(]([^）)]+)[）)])?\s*$/);
    if (!match) return { raw: text, company: text, branch: "", code: "" };
    const first = String(match[2] || "").trim();
    const second = String(match[3] || "").trim();
    let branch = "";
    let code = "";
    if (second) {
      branch = first;
      code = second;
    } else if (/^\d{2,}$/.test(first)) {
      code = first;
    } else {
      branch = first;
    }
    return { raw: text, company: String(match[1] || "").trim(), branch, code };
  }

  function companyByText(text) {
    const parsed = parsePartyName(text);
    const hay = norm(parsed.company || parsed.raw);
    const rawHay = norm(parsed.raw);
    if (!hay && !rawHay) return null;
    for (let i = 0; i < KNOWN.length; i += 1) {
      const company = KNOWN[i];
      const names = [company.name].concat(company.aliases || []);
      for (let j = 0; j < names.length; j += 1) {
        const alias = norm(names[j]);
        if (!alias || alias.length < 3) continue;
        if (hay === alias || rawHay === alias || hay.indexOf(alias) === 0 || rawHay.indexOf(alias) === 0 || hay.indexOf(alias) !== -1 || rawHay.indexOf(alias) !== -1) {
          return company;
        }
      }
      if (company.key === "daowenxi" && (hay.indexOf("達文西") !== -1 || rawHay.indexOf("達文西") !== -1)) return company;
    }
    return null;
  }

  function matchBranch(company, branchText) {
    const wanted = String(branchText || "").trim();
    if (!wanted || wanted === "（未分店）" || wanted === "全公司") return "";
    const list = (company && Array.isArray(company.branches)) ? company.branches : [];
    const wantedNorm = norm(wanted);
    for (let i = 0; i < list.length; i += 1) {
      const name = String(list[i].name || "").trim();
      const code = String(list[i].code || "").trim();
      if (name && (norm(name) === wantedNorm || wantedNorm === norm(code) || wanted === code)) return name;
    }
    return wanted;
  }

  function resolveParty(rawName, explicitBranch) {
    const parsed = parsePartyName(rawName);
    const company = companyByText(rawName) || companyByText(parsed.company);
    let branch = String(explicitBranch || "").trim();
    if (branch === "（未分店）" || branch === "全公司") branch = "";
    if (!branch) branch = parsed.branch;
    if (company) {
      branch = matchBranch(company, branch) || matchBranch(company, parsed.code);
      return { company: company.name, branch, code: parsed.code, known: true, key: company.key, branches: company.branches || [] };
    }
    return { company: parsed.company || parsed.raw, branch, code: parsed.code, known: false, key: "", branches: [] };
  }

  function directoryRows() {
    if (Array.isArray(window.salesCustomerDirectory) && window.salesCustomerDirectory.length) return window.salesCustomerDirectory;
    if (Array.isArray(window.opsMemberDirectory) && window.opsMemberDirectory.length) return window.opsMemberDirectory;
    return [];
  }

  function findDirectoryRow(value) {
    const resolved = resolveParty(value, "");
    const query = norm(value);
    const rows = directoryRows();
    if (!query && !resolved.company) return null;
    const scored = [];
    rows.forEach((row) => {
      if (!row) return;
      const names = [row.name, row.company_name].concat(row.aliases || []);
      const hit = names.some((alias) => {
        const aliasNorm = norm(alias);
        if (!aliasNorm) return false;
        return aliasNorm === query || aliasNorm === norm(resolved.company) || aliasNorm.indexOf(query) !== -1 || query.indexOf(aliasNorm) !== -1;
      });
      const companyHit = resolved.known && (row.company_key === resolved.key || norm(row.name) === norm(resolved.company) || norm(row.company_name) === norm(resolved.company));
      if (hit || companyHit) scored.push(row);
    });
    if (scored.length === 1) return scored[0];
    if (resolved.known) {
      const canonical = scored.find((row) => norm(row.name) === norm(resolved.company) || row.company_key === resolved.key);
      if (canonical) return canonical;
    }
    return scored[0] || null;
  }

  function fillBranchSelect(selectEl, branches, selected, options) {
    if (!selectEl) return;
    const opts = options || {};
    const list = Array.isArray(branches) ? branches : [];
    const current = String(selected || selectEl.dataset.current || selectEl.value || "").trim();
    const emptyLabel = opts.allLabel || (selectEl.id === "billingCustomerBranch" ? "全公司（含各分店）" : "（未分店）");
    selectEl.innerHTML = "";
    const emptyOpt = document.createElement("option");
    emptyOpt.value = "";
    emptyOpt.textContent = emptyLabel;
    selectEl.appendChild(emptyOpt);
    list.forEach((br) => {
      const name = String(br && br.name || "").trim();
      if (!name) return;
      const opt = document.createElement("option");
      opt.value = name;
      opt.textContent = br.code ? (name + "（" + br.code + "）") : name;
      if (current && current === name) opt.selected = true;
      selectEl.appendChild(opt);
    });
    if (current && !list.some((br) => String(br && br.name || "").trim() === current)) {
      const opt = document.createElement("option");
      opt.value = current;
      opt.textContent = current + "（本單原值）";
      opt.selected = true;
      selectEl.appendChild(opt);
    }
  }

  const origFill = window.opsFillBranchSelect;
  window.opsFillBranchSelect = function (selectEl, branches, selected) {
    const resolved = resolveParty(
      (document.getElementById("salesCustomerName") && selectEl && selectEl.id === "salesCustomerBranch")
        ? document.getElementById("salesCustomerName").value
        : (document.getElementById("billingCustomerName") && selectEl && selectEl.id === "billingCustomerBranch")
          ? document.getElementById("billingCustomerName").value
          : "",
      selected
    );
    const known = resolved.known ? resolved.branches : [];
    const merged = [];
    const seen = {};
    (known.concat(Array.isArray(branches) ? branches : [])).forEach((br) => {
      const name = String(br && br.name || "").trim();
      if (!name || seen[name]) return;
      seen[name] = true;
      merged.push(br);
    });
    fillBranchSelect(selectEl, merged, selected || resolved.branch, {
      allLabel: selectEl && selectEl.id === "billingCustomerBranch" ? "全公司（含各分店）" : "（未分店）"
    });
    if (typeof origFill === "function" && origFill !== window.opsFillBranchSelect && !merged.length) {
      origFill(selectEl, branches, selected);
    }
  };

  function applyResolvedName(nameInput, branchSelect) {
    if (!nameInput) return null;
    const resolved = resolveParty(nameInput.value, branchSelect ? branchSelect.value : "");
    const row = findDirectoryRow(nameInput.value) || findDirectoryRow(resolved.company);
    if (resolved.known && resolved.company && nameInput.value.trim() !== resolved.company) {
      nameInput.dataset.originalPartyName = nameInput.value.trim();
      nameInput.value = resolved.company;
    }
    if (branchSelect) {
      const branches = (row && row.branches && row.branches.length) ? row.branches : (resolved.branches || []);
      window.opsFillBranchSelect(branchSelect, branches, resolved.branch || branchSelect.dataset.current || branchSelect.value);
      if (resolved.branch) branchSelect.value = resolved.branch;
    }
    return row;
  }

  function bindSales() {
    const nameInput = document.getElementById("salesCustomerName");
    const branchSelect = document.getElementById("salesCustomerBranch");
    if (!nameInput) return;
    const sync = () => applyResolvedName(nameInput, branchSelect);
    nameInput.addEventListener("change", sync);
    nameInput.addEventListener("blur", sync);
  }

  function bindBilling() {
    const nameInput = document.getElementById("billingCustomerName");
    const branchSelect = document.getElementById("billingCustomerBranch");
    if (!nameInput || !branchSelect) return;
    const sync = () => {
      applyResolvedName(nameInput, branchSelect);
      if (typeof window.baohuiRenderBillingPicker === "function") window.baohuiRenderBillingPicker();
    };
    nameInput.addEventListener("change", sync);
    nameInput.addEventListener("blur", sync);
    branchSelect.addEventListener("change", () => {
      if (typeof window.baohuiRenderBillingPicker === "function") window.baohuiRenderBillingPicker();
    });
    if (!branchSelect.options.length || (branchSelect.options.length === 1 && !branchSelect.options[0].value)) {
      applyResolvedName(nameInput, branchSelect);
    }
  }

  function boot() {
    bindSales();
    bindBilling();
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
  window.addEventListener("load", boot);
  window.baohuiResolveCustomerParty = resolveParty;
  window.baohuiFindCustomerCompany = findDirectoryRow;
})();
