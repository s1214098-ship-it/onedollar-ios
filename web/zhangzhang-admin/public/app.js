const pages = {
  overview: { kicker: "OVERVIEW", title: "總覽", desc: "現貨、排程、應收與出貨進度。" },
  receipts: { kicker: "READY STOCK", title: "張張電腦已帶入", desc: "店內現貨送出即進產品庫，免物流單號。" },
  products: { kicker: "PRODUCTS", title: "產品建檔", desc: "條碼、品名、顏色、成本與倉位。" },
  stock: { kicker: "STOCK", title: "庫存管理", desc: "進貨、出庫、預留與庫存異動。" },
  warehouses: { kicker: "WAREHOUSE", title: "貨倉管理", desc: "部門、倉庫、貨架、層位。" },
  schedule: { kicker: "SCHEDULE", title: "排程上架", desc: "競標時間、頻道與發文草稿。" },
  settlement: { kicker: "SETTLEMENT", title: "得標結算", desc: "收款、出貨、物流單號與沖帳。" },
  members: { kicker: "MEMBERS", title: "客戶 / 會員", desc: "買家資料與黑名單。" },
  suppliers: { kicker: "SUPPLIERS", title: "廠商建檔", desc: "供應商與聯絡資料。" },
  analytics: { kicker: "ANALYTICS", title: "數據分析", desc: "營收、成本、毛利與虧損。" },
  backup: { kicker: "BACKUP", title: "備份匯入", desc: "匯出 JSON，或把 one-dollar-auction 資料匯入。" }
};

const state = { user: null, cache: {}, q: "" };

const $ = (sel) => document.querySelector(sel);
function toast(message) {
  const el = $("#toast");
  el.textContent = message;
  el.hidden = false;
  clearTimeout(toast.t);
  toast.t = setTimeout(() => { el.hidden = true; }, 2400);
}

async function api(path, options = {}) {
  const res = await fetch(path, {
    credentials: "same-origin",
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
    ...options,
    body: options.body ? JSON.stringify(options.body) : undefined
  });
  const data = await res.json().catch(() => ({ ok: false, error: "伺服器沒有回 JSON" }));
  if (!res.ok || data.ok === false) throw new Error(data.error || `HTTP ${res.status}`);
  return data;
}

function route() {
  return (location.hash.replace("#", "") || "overview");
}

function money(n) {
  return `NT$${Number(n || 0).toLocaleString("zh-TW")}`;
}

function pill(text, tone) {
  return `<span class="pill ${tone || ""}">${escapeHtml(text || "")}</span>`;
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (ch) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch]));
}

function optionList(rows, valueKey, labelKey, selected) {
  return rows.map((row) => {
    const value = row[valueKey] || row.name || row.id;
    const label = row[labelKey] || row.name || row.id;
    return `<option value="${escapeHtml(value)}" ${value === selected ? "selected" : ""}>${escapeHtml(label)}</option>`;
  }).join("");
}

function table(headers, rowsHtml) {
  return `<div class="panel" style="overflow:auto"><table><thead><tr>${headers.map((h) => `<th>${h}</th>`).join("")}</tr></thead><tbody>${rowsHtml || `<tr><td class="empty" colspan="${headers.length}">沒有資料</td></tr>`}</tbody></table></div>`;
}

async function loadAll() {
  const keys = ["products", "stock", "receipts", "warehouses", "schedules", "settlements", "members", "suppliers", "overview", "analytics"];
  const q = state.q ? `?q=${encodeURIComponent(state.q)}` : "";
  const pairs = await Promise.all(keys.map(async (key) => {
    const path = key === "overview" || key === "analytics" ? `/api/${key}` : `/api/${key === "schedules" ? "schedules" : key}${q}`;
    const data = await api(path);
    return [key, data.data];
  }));
  state.cache = Object.fromEntries(pairs);
}

function warehouses() {
  return state.cache.warehouses || [];
}

function renderOverview() {
  const o = state.cache.overview || {};
  return `
    <section class="cards">
      <article class="card"><span>產品建檔</span><strong>${o.products || 0}</strong><small>全部 SKU</small></article>
      <article class="card"><span>有庫存產品</span><strong>${o.inStock || 0}</strong><small>現貨可排程</small></article>
      <article class="card"><span>排程場次</span><strong>${o.liveSchedules || 0}</strong><small>共 ${o.schedules || 0} 場</small></article>
      <article class="card"><span>待出貨</span><strong>${o.pendingShip || 0}</strong><small>已收款尚未寄出</small></article>
      <article class="card"><span>已收款</span><strong>${money(o.received)}</strong><small>結算入帳</small></article>
      <article class="card"><span>應收未收</span><strong>${money(o.receivable)}</strong><small>${o.unpaidCount || 0} 張單</small></article>
      <article class="card"><span>張張已帶入</span><strong>${o.receipts || 0}</strong><small>電腦現貨入庫單</small></article>
      <article class="card"><span>客戶 / 廠商</span><strong>${(o.members || 0) + (o.suppliers || 0)}</strong><small>會員 ${o.members || 0}</small></article>
    </section>
    <div class="grid-2">
      <section class="panel">
        <h2>最近張張電腦已帶入</h2>
        ${(o.recentReceipts || []).map((r) => `<p><b>${escapeHtml(r.id)}</b>　${escapeHtml(r.warehouse || "")}　${pill(r.readyStock ? "現貨免物流單號" : r.logisticsNo || "待補單號", r.readyStock ? "" : "warn")}<br><span class="muted">${escapeHtml((r.items || []).map((i) => i.title + " x" + i.qty).join("、"))}</span></p>`).join("") || `<p class="empty">還沒有入庫單</p>`}
      </section>
      <section class="panel">
        <h2>最近結算</h2>
        ${(o.recentSettlements || []).map((s) => `<p><b>${escapeHtml(s.title || s.id)}</b>　${money(s.amount)}　${pill(s.status, s.status.includes("待") ? "warn" : "")}<br><span class="muted">${escapeHtml(s.memberName || "")}　${escapeHtml(s.carrier || "")} ${escapeHtml(s.trackingNo || "")}</span></p>`).join("") || `<p class="empty">還沒有結算單</p>`}
      </section>
    </div>`;
}

function renderReceipts() {
  const rows = state.cache.receipts || [];
  const wh = warehouses().filter((w) => w.type === "warehouse");
  const shelves = warehouses().filter((w) => w.type === "shelf");
  return `
    <section class="grid-2">
      <form class="panel" id="receiptForm">
        <h2>現貨入庫（免物流單號）</h2>
        <p class="muted">張張電腦已帶入：送出後直接進入產品庫。現貨不用填 7-11 / 全家 / 郵局單號。</p>
        <div class="form-grid">
          <label>條碼<input name="barcode" placeholder="空白則自動編號"></label>
          <label>品名<input name="product_name" required placeholder="例如：二手主機"></label>
          <label>顏色<input name="color" placeholder="黑色"></label>
          <label>規格<input name="spec" placeholder="i5 / 16G"></label>
          <label>成本<input name="cost" type="number" min="0" value="0"></label>
          <label>數量<input name="qty" type="number" min="1" value="1"></label>
          <label>倉庫<select name="warehouse">${optionList(wh, "name", "name")}</select></label>
          <label>貨架<select name="shelf"><option value="">未上架</option>${optionList(shelves, "shelf", "name")}</select></label>
          <label class="full">層位<input name="layer" placeholder="上 / 中 / 下"></label>
          <label class="full">備註<textarea name="note">店內現貨，免物流單號。</textarea></label>
          <label><input type="checkbox" name="readyStock" checked> 現貨（免物流單號）</label>
          <label>物流單號<input name="logisticsNo" placeholder="非現貨才需要"></label>
        </div>
        <div class="actions"><button class="primary" type="submit">送出即進產品庫</button></div>
      </form>
      <section>
        ${table(["時間", "倉庫", "品項", "單號"], rows.map((r) => `<tr>
          <td>${escapeHtml(r.created_at || "")}<br>${pill(r.readyStock ? "現貨" : "集運", r.readyStock ? "" : "warn")}</td>
          <td>${escapeHtml(r.warehouse || "")} ${escapeHtml(r.shelf || "")}</td>
          <td>${escapeHtml((r.items || []).map((i) => `${i.title} x${i.qty}`).join("、"))}</td>
          <td>${escapeHtml(r.logisticsNo || "—")}</td>
        </tr>`).join(""))}
      </section>
    </section>`;
}

function renderProducts() {
  const rows = state.cache.products || [];
  return `
    <form class="panel form-grid" id="productForm">
      <label>條碼<input name="barcode" required placeholder="ZZ21P1201"></label>
      <label>品名<input name="product_name" required></label>
      <label>分類<input name="main_category" value="電腦"></label>
      <label>顏色<input name="color"></label>
      <label>規格<input name="spec"></label>
      <label>成本<input name="cost" type="number" min="0" value="0"></label>
      <label>倉庫<input name="warehouse_name" value="電腦倉"></label>
      <label>貨架 / 層位<input name="shelf_code" placeholder="B01 / 中"></label>
      <div class="full actions"><button class="primary" type="submit">新增產品</button></div>
    </form>
    ${table(["條碼", "品名", "成本", "庫存", "倉位", "狀態", ""], rows.map((p) => `<tr>
      <td>${escapeHtml(p.barcode)}</td>
      <td>${escapeHtml(p.title || p.product_name)}</td>
      <td>${money(p.cost)}</td>
      <td>${Number(p.stock_total || 0)} / 可售 ${Number(p.stock_available || 0)}</td>
      <td>${escapeHtml(p.warehouse_name || "")} ${escapeHtml(p.shelf_code || "")}</td>
      <td>${pill(p.status || "可排程")}</td>
      <td><button class="danger" data-del-product="${escapeHtml(p.id)}" type="button">刪除</button></td>
    </tr>`).join(""))}`;
}

function renderStock() {
  const products = state.cache.products || [];
  const moves = state.cache.stock || [];
  return `
    <form class="panel form-grid" id="stockForm">
      <label>產品<select name="productId">${products.map((p) => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.barcode)} ${escapeHtml(p.product_name)}</option>`).join("")}</select></label>
      <label>類型<select name="type"><option value="in">進貨</option><option value="out">出庫</option><option value="reserve">預留</option><option value="release">取消預留</option></select></label>
      <label>數量<input name="qty" type="number" min="1" value="1"></label>
      <label>倉庫<input name="warehouse" value="台灣倉"></label>
      <label>貨架<input name="shelf"></label>
      <label>層位<input name="layer"></label>
      <label class="full">備註<input name="note"></label>
      <div class="full actions"><button class="primary" type="submit">寫入庫存異動</button></div>
    </form>
    ${table(["時間", "類型", "條碼", "數量", "倉位", "單號"], moves.map((m) => `<tr>
      <td>${escapeHtml(m.created_at || "")}</td>
      <td>${pill(m.type)}</td>
      <td>${escapeHtml(m.barcode)}</td>
      <td>${m.qty}</td>
      <td>${escapeHtml(m.warehouse || "")} ${escapeHtml(m.shelf || "")}</td>
      <td>${escapeHtml(m.logisticsNo || (m.readyStock ? "現貨" : "—"))}</td>
    </tr>`).join(""))}`;
}

function renderWarehouses() {
  const rows = warehouses();
  return `
    <form class="panel form-grid" id="warehouseForm">
      <label>類型<select name="type"><option value="warehouse">倉庫</option><option value="shelf">貨架</option></select></label>
      <label>名稱<input name="name" required placeholder="台灣倉 / A01"></label>
      <label>倉庫<input name="warehouse" placeholder="台灣倉"></label>
      <label>貨架<input name="shelf"></label>
      <label>層位<input name="layer"></label>
      <label>部門<input name="department" value="電腦部門"></label>
      <div class="full actions"><button class="primary" type="submit">新增倉位</button></div>
    </form>
    ${table(["類型", "名稱", "倉庫", "貨架", "層位", "部門", ""], rows.map((w) => `<tr>
      <td>${pill(w.type)}</td><td>${escapeHtml(w.name)}</td><td>${escapeHtml(w.warehouse || "")}</td>
      <td>${escapeHtml(w.shelf || "")}</td><td>${escapeHtml(w.layer || "")}</td><td>${escapeHtml(w.department || "")}</td>
      <td><button class="danger" data-del-warehouse="${escapeHtml(w.id)}" type="button">刪除</button></td>
    </tr>`).join(""))}`;
}

function renderSchedule() {
  const products = state.cache.products || [];
  const rows = state.cache.schedules || [];
  return `
    <form class="panel form-grid" id="scheduleForm">
      <label>產品<select name="productId">${products.map((p) => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.title || p.product_name)}</option>`).join("")}</select></label>
      <label>頻道<select name="channel"><option>Facebook 社團</option><option>臉書粉絲團</option><option>LINE</option><option>現場</option></select></label>
      <label>開始時間<input name="startAt" type="datetime-local"></label>
      <label>結束時間<input name="endAt" type="datetime-local"></label>
      <label>起標價<input name="startPrice" type="number" value="1"></label>
      <label>狀態<select name="status"><option>排程中</option><option>競標中</option><option>已截標</option><option>取消</option></select></label>
      <label class="full">發文草稿<textarea name="draft" placeholder="現貨、規格、交貨方式"></textarea></label>
      <div class="full actions"><button class="primary" type="submit">新增排程</button></div>
    </form>
    ${table(["產品", "頻道", "時間", "狀態", "草稿"], rows.map((s) => `<tr>
      <td>${escapeHtml(s.title || s.productId)}</td>
      <td>${escapeHtml(s.channel || "")}</td>
      <td>${escapeHtml(s.startAt || "")}<br>${escapeHtml(s.endAt || "")}</td>
      <td>${pill(s.status || "")}</td>
      <td>${escapeHtml(s.draft || "")}</td>
    </tr>`).join(""))}`;
}

function renderSettlement() {
  const products = state.cache.products || [];
  const members = state.cache.members || [];
  const rows = state.cache.settlements || [];
  return `
    <form class="panel form-grid" id="settlementForm">
      <label>產品<select name="productId">${products.map((p) => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.title || p.product_name)}</option>`).join("")}</select></label>
      <label>買家<select name="memberId">${members.map((m) => `<option value="${escapeHtml(m.id)}">${escapeHtml(m.name)}</option>`).join("")}</select></label>
      <label>數量<input name="qty" type="number" min="1" value="1"></label>
      <label>金額<input name="amount" type="number" min="0" value="0"></label>
      <label>已收<input name="paid_amount" type="number" min="0" value="0"></label>
      <label>狀態<select name="status"><option>待收款</option><option>已收款待出貨</option><option>已出貨</option><option>已完成</option><option>虧損</option></select></label>
      <label>物流<select name="carrier"><option>7-11</option><option>全家</option><option>郵局</option><option>新竹</option><option>大榮</option><option>自取</option></select></label>
      <label>物流單號<input name="trackingNo" placeholder="出貨才填；現貨入庫不必填"></label>
      <label class="full">備註<input name="note"></label>
      <div class="full actions">
        <button class="primary" type="submit">新增結算</button>
        <a class="secondary" href="https://tracking.shopmore.com.tw/" target="_blank" rel="noopener">查 7-11</a>
        <a class="secondary" href="https://ecfme.fme.com.tw/FMEDCFPWebV2_II/index.aspx" target="_blank" rel="noopener">查全家</a>
      </div>
    </form>
    ${table(["買家", "商品", "金額", "狀態", "物流", ""], rows.map((s) => `<tr>
      <td>${escapeHtml(s.memberName || s.memberId)}</td>
      <td>${escapeHtml(s.title || s.productId)} x${s.qty || 1}</td>
      <td>${money(s.amount)} / 已收 ${money(s.paid_amount)}</td>
      <td>${pill(s.status, String(s.status).includes("待") ? "warn" : "")}</td>
      <td>${escapeHtml(s.carrier || "")}<br>${escapeHtml(s.trackingNo || "尚未填單號")}</td>
      <td><button class="secondary" data-ship="${escapeHtml(s.id)}" type="button">標為已出貨</button></td>
    </tr>`).join(""))}`;
}

function renderPeople(kind) {
  const isMember = kind === "members";
  const rows = state.cache[kind] || [];
  const formId = isMember ? "memberForm" : "supplierForm";
  return `
    <form class="panel form-grid" id="${formId}">
      <label>名稱<input name="name" required></label>
      <label>電話<input name="phone"></label>
      <label class="full">地址<input name="${isMember ? "address" : "address"}"></label>
      ${isMember ? `<label>黑名單<select name="blacklist_status"><option>正常</option><option>黑名單</option></select></label><label>風險<select name="risk_level"><option>一般</option><option>注意</option><option>高風險</option></select></label>` : `<label>統一編號<input name="tax_id"></label><label>聯絡人<input name="contact"></label>`}
      <label class="full">備註<input name="note"></label>
      <div class="full actions"><button class="primary" type="submit">新增</button></div>
    </form>
    ${table(isMember ? ["名稱", "電話", "狀態", "備註"] : ["名稱", "電話", "聯絡人", "備註"], rows.map((row) => `<tr>
      <td>${escapeHtml(row.name)}</td>
      <td>${escapeHtml(row.phone || "")}</td>
      <td>${isMember ? pill(row.blacklist_status || "正常", row.blacklist_status === "黑名單" ? "danger" : "") : escapeHtml(row.contact || "")}</td>
      <td>${escapeHtml(row.note || "")}</td>
    </tr>`).join(""))}`;
}

function renderAnalytics() {
  const a = state.cache.analytics || {};
  return `
    <section class="cards">
      <article class="card"><span>營收</span><strong>${money(a.revenue)}</strong></article>
      <article class="card"><span>成本</span><strong>${money(a.cost)}</strong></article>
      <article class="card"><span>毛利</span><strong>${money(a.margin)}</strong><small>${Number(a.marginRate || 0).toFixed(1)}%</small></article>
      <article class="card"><span>虧損</span><strong>${money(a.loss)}</strong></article>
    </section>
    <div class="grid-2">
      <section class="panel"><h2>頻道營收</h2>${(a.byChannel || []).map((x) => `<p>${escapeHtml(x.name)}　${money(x.value)}</p>`).join("") || `<p class="empty">尚無結算</p>`}</section>
      <section class="panel"><h2>入庫倉別</h2>${(a.byWarehouse || []).map((x) => `<p>${escapeHtml(x.name)}　${x.value} 件</p>`).join("") || `<p class="empty">尚無入庫</p>`}</section>
    </div>`;
}

function renderBackup() {
  return `
    <section class="panel">
      <h2>備份 / 匯入</h2>
      <p class="muted">可匯出目前這台伺服器的 JSON。若要把線上 one-dollar-auction/data 的 products.json、members.json、suppliers.json、warehouses.json 帶進來，貼到下方後匯入。</p>
      <div class="actions">
        <button class="primary" type="button" id="exportBtn">下載備份</button>
      </div>
      <label class="full" style="margin-top:12px">匯入 JSON（可含 products / members / suppliers / warehouses / schedules / settlements）
        <textarea id="importBox" placeholder='{"products":[],"members":[]}'></textarea>
      </label>
      <div class="actions"><button class="secondary" type="button" id="importBtn">匯入並合併</button></div>
    </section>`;
}

const renderers = {
  overview: renderOverview,
  receipts: renderReceipts,
  products: renderProducts,
  stock: renderStock,
  warehouses: renderWarehouses,
  schedule: renderSchedule,
  settlement: renderSettlement,
  members: () => renderPeople("members"),
  suppliers: () => renderPeople("suppliers"),
  analytics: renderAnalytics,
  backup: renderBackup
};

async function draw() {
  const page = renderers[route()] ? route() : "overview";
  const meta = pages[page];
  $("#pageKicker").textContent = meta.kicker;
  $("#pageTitle").textContent = meta.title;
  $("#pageDesc").textContent = meta.desc;
  document.querySelectorAll("#nav a").forEach((a) => a.classList.toggle("active", a.getAttribute("href") === `#${page}`));
  await loadAll();
  $("#view").innerHTML = renderers[page]();
  bindPage(page);
}

function formValues(form) {
  const data = {};
  new FormData(form).forEach((value, key) => { data[key] = value; });
  if (form.readyStock) data.readyStock = form.readyStock.checked;
  return data;
}

function bindPage(page) {
  const view = $("#view");
  const receiptForm = view.querySelector("#receiptForm");
  if (receiptForm) {
    receiptForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const body = formValues(receiptForm);
      await api("/api/receipts", { method: "POST", body });
      toast("已帶入產品庫");
      draw();
    });
  }
  const productForm = view.querySelector("#productForm");
  if (productForm) {
    productForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await api("/api/products", { method: "POST", body: formValues(productForm) });
      toast("已建檔");
      draw();
    });
  }
  view.querySelectorAll("[data-del-product]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      if (!confirm("確定刪除產品？")) return;
      await api(`/api/products/${encodeURIComponent(btn.dataset.delProduct)}`, { method: "DELETE" });
      toast("已刪除");
      draw();
    });
  });
  const stockForm = view.querySelector("#stockForm");
  if (stockForm) {
    stockForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await api("/api/stock", { method: "POST", body: formValues(stockForm) });
      toast("庫存已更新");
      draw();
    });
  }
  const warehouseForm = view.querySelector("#warehouseForm");
  if (warehouseForm) {
    warehouseForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const body = formValues(warehouseForm);
      if (body.type === "warehouse") body.warehouse = body.warehouse || body.name;
      await api("/api/warehouses", { method: "POST", body });
      toast("倉位已新增");
      draw();
    });
  }
  view.querySelectorAll("[data-del-warehouse]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      await api(`/api/warehouses/${encodeURIComponent(btn.dataset.delWarehouse)}`, { method: "DELETE" });
      draw();
    });
  });
  const scheduleForm = view.querySelector("#scheduleForm");
  if (scheduleForm) {
    scheduleForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const body = formValues(scheduleForm);
      const product = (state.cache.products || []).find((p) => p.id === body.productId);
      body.title = product ? (product.title || product.product_name) : "";
      await api("/api/schedules", { method: "POST", body });
      toast("排程已建立");
      draw();
    });
  }
  const settlementForm = view.querySelector("#settlementForm");
  if (settlementForm) {
    settlementForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const body = formValues(settlementForm);
      const product = (state.cache.products || []).find((p) => p.id === body.productId);
      const member = (state.cache.members || []).find((m) => m.id === body.memberId);
      body.title = product ? (product.title || product.product_name) : "";
      body.memberName = member ? member.name : "";
      await api("/api/settlements", { method: "POST", body });
      toast("結算已建立");
      draw();
    });
  }
  view.querySelectorAll("[data-ship]").forEach((btn) => {
    btn.addEventListener("click", async () => {
      await api(`/api/settlements/${encodeURIComponent(btn.dataset.ship)}`, { method: "PATCH", body: { status: "已出貨" } });
      toast("已標為出貨");
      draw();
    });
  });
  const memberForm = view.querySelector("#memberForm");
  if (memberForm) {
    memberForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await api("/api/members", { method: "POST", body: formValues(memberForm) });
      toast("會員已新增");
      draw();
    });
  }
  const supplierForm = view.querySelector("#supplierForm");
  if (supplierForm) {
    supplierForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      await api("/api/suppliers", { method: "POST", body: formValues(supplierForm) });
      toast("廠商已新增");
      draw();
    });
  }
  const exportBtn = view.querySelector("#exportBtn");
  if (exportBtn) {
    exportBtn.addEventListener("click", async () => {
      const data = await api("/api/backup");
      const blob = new Blob([JSON.stringify(data.data, null, 2)], { type: "application/json" });
      const a = document.createElement("a");
      a.href = URL.createObjectURL(blob);
      a.download = `zhangzhang-backup-${Date.now()}.json`;
      a.click();
    });
  }
  const importBtn = view.querySelector("#importBtn");
  if (importBtn) {
    importBtn.addEventListener("click", async () => {
      const raw = view.querySelector("#importBox").value;
      const json = JSON.parse(raw);
      const result = await api("/api/import", { method: "POST", body: json });
      toast("匯入完成 " + JSON.stringify(result.data));
      draw();
    });
  }
}

async function boot() {
  try {
    const me = await api("/api/me");
    state.user = me.user;
    $("#loginScreen").hidden = true;
    $("#appShell").hidden = false;
    $("#currentUser").textContent = `${me.user.name}（${me.user.account}）`;
    await draw();
  } catch (error) {
    $("#loginScreen").hidden = false;
    $("#appShell").hidden = true;
  }
}

$("#loginForm").addEventListener("submit", async (event) => {
  event.preventDefault();
  $("#loginError").hidden = true;
  try {
    const body = formValues(event.target);
    await api("/api/login", { method: "POST", body });
    boot();
  } catch (error) {
    $("#loginError").hidden = false;
    $("#loginError").textContent = error.message;
  }
});

$("#logoutBtn").addEventListener("click", async () => {
  await api("/api/logout", { method: "POST", body: {} });
  location.hash = "#overview";
  boot();
});

$("#reloadBtn").addEventListener("click", () => draw());
$("#searchBox").addEventListener("keydown", (event) => {
  if (event.key === "Enter") {
    state.q = event.target.value.trim();
    draw();
  }
});
window.addEventListener("hashchange", () => draw());
boot();
