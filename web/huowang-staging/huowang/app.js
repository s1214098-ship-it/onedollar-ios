const state = {
  lang: "zh",
  area: "all",
  type: "all",
  price: "all",
};

const labels = {
  zh: {
    allAreas: "全部區域",
    allTypes: "全部類型",
    featured: "精選",
    view: "檢視DM",
    area: "區域",
    land: "土地",
    build: "建物",
    unit: "單價",
    layout: "格局",
    call: "直接撥打",
    line: "LINE詢問",
    countSuffix: "件可看",
  },
  vi: {
    allAreas: "Tất cả khu vực",
    allTypes: "Tất cả loại",
    featured: "Chọn lọc",
    view: "Xem DM",
    area: "Khu vực",
    land: "Đất",
    build: "Diện tích",
    unit: "Đơn giá",
    layout: "Phòng",
    call: "Gọi ngay",
    line: "Hỏi qua LINE",
    countSuffix: "bất động sản",
  },
};

const grid = document.querySelector("#propertyGrid");
const dialog = document.querySelector("#detailDialog");
const detailBody = document.querySelector("#detailBody");
const countNow = document.querySelector("#countNow");
const LINE_URL = "https://line.me/ti/p/~0911294374";
const REQUIRED_CONTACT_ZH = {
  agentName: "郭火旺",
  agentPhone: "0911-294-374",
  lineId: "0911294374",
  storeName: "永慶不動產羅東文化盛群加盟店",
  brokerText: "經紀人：馮雋引（113）000395字號",
};
const REQUIRED_CONTACT_VI = {
  agentName: "Chú Hỏa Vượng",
  agentPhone: "0911-294-374",
  lineId: "0911294374",
  storeName: "Yungching Real Estate Luodong Wenhua Shengqun",
  brokerText: "Người môi giới: Feng Jun Yin, chứng chỉ (113)000395",
};
let allItems = Array.isArray(window.PROPERTY_ITEMS) ? [...window.PROPERTY_ITEMS] : [];

function text(key) {
  return labels[state.lang][key];
}

function itemText(item, zhKey, viKey) {
  if (state.lang === "vi") return item[viKey] || item[zhKey] || "";
  return item[zhKey] || "";
}

function areaValue(value) {
  const raw = String(value || "").trim();
  if (!raw || raw === "0" || raw === "0.0" || raw === "0.00" || raw === "洽詢") {
    return state.lang === "vi" ? "Liên hệ" : "洽詢";
  }
  const number = raw.replace(/坪/g, "").trim();
  return state.lang === "vi" ? `${number} ping` : (raw.includes("坪") ? raw : `${raw} 坪`);
}

function layoutValue(value) {
  const raw = String(value || "").trim();
  if (!raw || raw === "格局洽詢") return state.lang === "vi" ? "Liên hệ" : "格局洽詢";
  if (state.lang !== "vi") return raw;
  return raw
    .replace(/房/g, " phòng ")
    .replace(/廳/g, " phòng khách ")
    .replace(/衛/g, " WC ")
    .replace(/室/g, " phòng ")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/格局洽詢/g, "Liên hệ");
}

function priceValue(price) {
  const match = String(price || "").replace(/,/g, "").match(/(\d+(?:\.\d+)?)/);
  return match ? Number(match[1]) : 0;
}

function contactInfo(item) {
  const base = state.lang === "vi" ? REQUIRED_CONTACT_VI : REQUIRED_CONTACT_ZH;
  return {
    agentName: item.agentName || base.agentName,
    agentPhone: item.agentPhone || base.agentPhone,
    lineId: item.lineId || base.lineId,
    storeName: item.storeName || base.storeName,
    brokerText: item.brokerText || base.brokerText,
  };
}

function summaryText(item) {
  const summary = state.lang === "vi" ? (item.summaryVi || item.summaryZh || "") : (item.summaryZh || "");
  if (summary.includes("0911-294-374") || summary.includes("0911294374")) return summary;
  const extra = state.lang === "vi"
    ? " Liên hệ xem nhà: Chú Hỏa Vượng 0911-294-374. LINE ID: 0911294374. Người môi giới: Feng Jun Yin, chứng chỉ (113)000395."
    : " 看屋專線：0911-294-374。LINE ID：0911294374。經紀人：馮雋引（113）000395字號。";
  return `${summary}${extra}`;
}

function localizeStatic() {
  document.documentElement.lang = state.lang === "vi" ? "vi" : "zh-Hant";
  document.querySelectorAll("[data-zh][data-vi]").forEach((el) => {
    el.textContent = el.dataset[state.lang];
  });
  document.querySelectorAll(".lang-btn").forEach((btn) => {
    btn.classList.toggle("active", btn.dataset.lang === state.lang);
  });
}

function makeFilterButton(label, value, group) {
  const button = document.createElement("button");
  button.className = "filter";
  button.textContent = label;
  button.dataset[group] = value;
  button.addEventListener("click", () => {
    state[group] = value;
    render();
  });
  return button;
}

function buildFilters() {
  const areaWrap = document.querySelector("#areaFilters");
  const typeWrap = document.querySelector("#typeFilters");
  areaWrap.replaceChildren();
  typeWrap.replaceChildren();

  const areas = [...new Set(allItems.map((item) => item.area))].filter(Boolean);
  const types = [...new Set(allItems.map((item) => item.category))].filter(Boolean);

  areaWrap.appendChild(makeFilterButton(text("allAreas"), "all", "area"));
  areas.forEach((area) => areaWrap.appendChild(makeFilterButton(area, area, "area")));

  typeWrap.appendChild(makeFilterButton(text("allTypes"), "all", "type"));
  ["農地", "農舍", "土地", "透天", "華廈", "套房"].forEach((type) => {
    if (types.includes(type)) typeWrap.appendChild(makeFilterButton(type, type, "type"));
  });
  types.forEach((type) => {
    if (!["農地", "農舍", "土地", "透天", "華廈", "套房"].includes(type)) {
      typeWrap.appendChild(makeFilterButton(type, type, "type"));
    }
  });
}

function matches(item) {
  const amount = priceValue(item.price);
  if (state.area !== "all" && item.area !== state.area) return false;
  if (state.type !== "all" && item.category !== state.type) return false;
  if (state.price === "featured" && !item.featured) return false;
  if (state.price === "low" && amount >= 400) return false;
  if (state.price === "high" && amount < 1000) return false;
  return true;
}

function card(item) {
  const article = document.createElement("article");
  article.className = "property";
  const title = itemText(item, "title", "titleVi");
  const area = itemText(item, "area", "areaVi");
  const category = itemText(item, "category", "categoryVi");
  const address = itemText(item, "address", "addressVi");
  const contact = contactInfo(item);
  article.innerHTML = `
    <img src="${item.image}" alt="${title}" loading="lazy">
    <div class="property-body">
      <div class="tagline">
        <span class="tag">${area}</span>
        <span class="tag">${category}</span>
        ${item.featured ? `<span class="tag featured">${text("featured")}</span>` : ""}
      </div>
      <h2>${title}</h2>
      <div class="price">${item.price}</div>
      <div class="facts">
        <div>${address}</div>
        <div>${layoutValue(item.layout)}</div>
        <div>${text("land")} ${areaValue(item.land)}｜${text("build")} ${areaValue(item.build)}</div>
        <div>${state.lang === "vi" ? "Liên hệ" : "看屋專線"}：${contact.agentPhone}</div>
      </div>
      <div class="card-actions">
        <button class="view-btn">${text("view")}</button>
        <a class="card-line" href="${LINE_URL}" target="_blank" rel="noopener">LINE</a>
      </div>
    </div>
  `;
  article.querySelector(".view-btn").addEventListener("click", () => openDetail(item));
  return article;
}

function render() {
  localizeStatic();
  buildFilters();

  document.querySelectorAll(".filter").forEach((btn) => {
    const group = btn.dataset.area ? "area" : btn.dataset.type ? "type" : btn.dataset.price ? "price" : "";
    const value = btn.dataset[group];
    btn.classList.toggle("active", state[group] === value);
  });

  const list = allItems.filter(matches);
  countNow.textContent = list.length;
  grid.replaceChildren(...list.map(card));
}

function openDetail(item) {
  const title = itemText(item, "title", "titleVi");
  const area = itemText(item, "area", "areaVi");
  const category = itemText(item, "category", "categoryVi");
  const address = itemText(item, "address", "addressVi");
  const contact = contactInfo(item);
  const images = Array.isArray(item.images) && item.images.length ? item.images : [item.image || "assets/huowang-card.jpg"];
  detailBody.innerHTML = `
    <div class="detail-photo">
      <img class="detail-main-img" src="${images[0]}" alt="${title}">
      <div class="photo-tip">${state.lang === "vi" ? "Bấm ảnh nhỏ để xem thêm hình" : "點下方照片可看更多圖片"}</div>
      <div class="detail-thumbs">
        ${images.map((src, index) => `<button class="thumb ${index === 0 ? "active" : ""}" type="button" data-src="${src}"><img src="${src}" alt="${title} ${index + 1}" loading="lazy"></button>`).join("")}
      </div>
    </div>
    <div class="detail-info">
      <div class="tagline">
        <span class="tag">${state.lang === "vi" ? area : item.city + item.area}</span>
        <span class="tag">${category}</span>
        ${item.featured ? `<span class="tag featured">${text("featured")}</span>` : ""}
      </div>
      <h2>${title}</h2>
      <div class="detail-price">${item.price}</div>
      <div class="detail-summary">${summaryText(item)}</div>
      <div class="detail-list">
        <div>${text("area")}：${address}</div>
        <div>${text("layout")}：${layoutValue(item.layout)}</div>
        <div>${text("land")}：${areaValue(item.land)}</div>
        <div>${text("build")}：${areaValue(item.build)}</div>
        <div>${text("unit")}：${item.unit}</div>
        <div>編號：${item.id}</div>
      </div>
      <div class="listing-contact">
        <strong>${state.lang === "vi" ? "Thông tin liên hệ" : "上架固定聯絡資訊"}</strong>
        <div>${contact.agentName}｜${contact.agentPhone}</div>
        <div>LINE ID：${contact.lineId}</div>
        <div>${contact.storeName}</div>
        <div>${contact.brokerText}</div>
      </div>
      <div class="detail-cta">
        <a href="tel:0911294374">${text("call")}</a>
        <a href="${LINE_URL}" target="_blank" rel="noopener">${text("line")}</a>
      </div>
    </div>
  `;
  detailBody.querySelectorAll(".thumb").forEach((btn) => {
    btn.addEventListener("click", () => {
      const main = detailBody.querySelector(".detail-main-img");
      main.src = btn.dataset.src;
      detailBody.querySelectorAll(".thumb").forEach((x) => x.classList.remove("active"));
      btn.classList.add("active");
    });
  });
  dialog.showModal();
}

async function loadCustomItems() {
  try {
    const res = await fetch("api/properties.php", { cache: "no-store" });
    if (!res.ok) return;
    const data = await res.json();
    if (Array.isArray(data.items)) {
      allItems = [...(window.PROPERTY_ITEMS || []), ...data.items.filter((item) => !item.hidden)];
      render();
    }
  } catch (err) {
    const saved = localStorage.getItem("huowangCustomProperties");
    if (saved) {
      try {
        const data = JSON.parse(saved);
        if (Array.isArray(data)) allItems = [...(window.PROPERTY_ITEMS || []), ...data];
      } catch (_) {}
    }
  }
}

document.querySelectorAll(".lang-btn").forEach((btn) => {
  btn.addEventListener("click", () => {
    state.lang = btn.dataset.lang;
    buildFilters();
    render();
  });
});

document.querySelectorAll("[data-price]").forEach((btn) => {
  btn.addEventListener("click", () => {
    state.price = btn.dataset.price;
    render();
  });
});

document.querySelector("#closeDialog").addEventListener("click", () => dialog.close());
dialog.addEventListener("click", (event) => {
  if (event.target === dialog) dialog.close();
});

render();
loadCustomItems();
