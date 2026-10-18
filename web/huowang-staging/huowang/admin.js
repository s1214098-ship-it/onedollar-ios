let items = [];
const REQUIRED_INFO_ZH = "看屋專線：0911-294-374。LINE ID：0911294374。營業員：郭火旺。永慶不動產羅東文化盛群加盟店。經紀人：馮雋引（113）000395字號。";
const REQUIRED_INFO_VI = "Liên hệ xem nhà: Chú Hỏa Vượng 0911-294-374. LINE ID: 0911294374. Yungching Real Estate Luodong Wenhua Shengqun. Người môi giới: Feng Jun Yin, chứng chỉ (113)000395.";

const fields = [
  "id", "title", "titleVi", "price", "area", "areaVi", "category", "categoryVi",
  "layout", "land", "build", "unit", "address", "addressVi", "image", "summaryZh", "summaryVi"
];

function byId(id) {
  return document.getElementById(id);
}

function setStatus(message) {
  byId("status").textContent = message || "";
}

function goBack() {
  if (history.length > 1) {
    history.back();
  } else {
    location.href = "index.html";
  }
}

function formData() {
  const item = {};
  fields.forEach((field) => item[field] = byId(field).value.trim());
  item.city = "宜蘭縣";
  item.featured = byId("featured").checked;
  if (!item.categoryVi) item.categoryVi = translateCategory(item.category);
  if (!item.areaVi) item.areaVi = translateArea(item.area);
  if (!item.addressVi) item.addressVi = item.areaVi ? `${item.areaVi}, Yilan` : "";
  if (!item.image) item.image = "assets/huowang-card.jpg";
  if (!item.summaryZh) item.summaryZh = `${item.area}${item.category}精選，${item.title}。歡迎聯絡火旺伯看屋。`;
  if (!item.summaryVi) item.summaryVi = `${item.titleVi || item.title}. Vui lòng liên hệ Chú Hỏa Vượng để xem nhà.`;
  item.summaryZh = ensureRequiredInfo(item.summaryZh, REQUIRED_INFO_ZH);
  item.summaryVi = ensureRequiredInfo(item.summaryVi, REQUIRED_INFO_VI);
  item.agentName = "郭火旺";
  item.agentPhone = "0911-294-374";
  item.lineId = "0911294374";
  item.storeName = "永慶不動產羅東文化盛群加盟店";
  item.brokerText = "經紀人：馮雋引（113）000395字號";
  item.requiredInfoZh = REQUIRED_INFO_ZH;
  item.requiredInfoVi = REQUIRED_INFO_VI;
  return item;
}

function ensureRequiredInfo(text, required) {
  const value = String(text || "").trim();
  if (value.includes("0911-294-374") && (value.includes("經紀人") || value.includes("Người môi giới"))) {
    return value;
  }
  return `${value} ${required}`.trim();
}

function fillForm(item) {
  fields.forEach((field) => byId(field).value = item[field] || "");
  byId("featured").checked = !!item.featured;
  window.scrollTo({ top: 0, behavior: "smooth" });
}

function clearForm() {
  fields.forEach((field) => byId(field).value = "");
  byId("featured").checked = false;
  setStatus("");
}

function translateCategory(value) {
  return {
    "透天": "Nhà phố",
    "華廈": "Căn hộ",
    "套房": "Phòng suite",
    "農地": "Đất nông nghiệp",
    "農舍": "Nhà vườn",
    "土地": "Đất",
    "住宅": "Nhà ở",
  }[value] || value || "";
}

function translateArea(value) {
  return {
    "宜蘭市": "TP. Yilan",
    "羅東鎮": "Luodong",
    "五結鄉": "Wujie",
    "冬山鄉": "Dongshan",
    "頭城鎮": "Toucheng",
    "礁溪鄉": "Jiaoxi",
    "壯圍鄉": "Zhuangwei",
    "員山鄉": "Yuanshan",
    "三星鄉": "Sanxing",
    "蘇澳鎮": "Su'ao",
    "南澳鄉": "Nan'ao",
  }[value] || value || "";
}

async function loadItems() {
  setStatus("讀取中...");
  try {
    const res = await fetch("api/properties.php", { cache: "no-store" });
    if (!res.ok) throw new Error("api");
    const data = await res.json();
    items = Array.isArray(data.items) ? data.items : [];
    localStorage.setItem("huowangCustomProperties", JSON.stringify(items));
    setStatus("");
  } catch (err) {
    items = JSON.parse(localStorage.getItem("huowangCustomProperties") || "[]");
    setStatus("目前使用瀏覽器暫存模式；若正式網址有 PHP，會自動存到伺服器。");
  }
  renderList();
}

async function saveItem() {
  const item = formData();
  if (!item.title) {
    setStatus("請先填中文物件名稱");
    return;
  }
  try {
    const res = await fetch("api/properties.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(item),
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || "save failed");
    setStatus("已儲存到後台資料");
    clearForm();
    await loadItems();
  } catch (err) {
    if (!item.id) item.id = "LOCAL-" + Date.now();
    const idx = items.findIndex((x) => x.id === item.id);
    if (idx >= 0) items[idx] = item;
    else items.unshift(item);
    localStorage.setItem("huowangCustomProperties", JSON.stringify(items));
    setStatus("已先存到本機瀏覽器暫存；伺服器 API 目前沒有回應。");
    clearForm();
    renderList();
  }
}

async function uploadImage() {
  const file = byId("imageFile").files[0];
  if (!file) {
    setStatus("請先選照片");
    return;
  }
  const body = new FormData();
  body.append("image", file);
  setStatus("照片上傳中...");
  try {
    const res = await fetch("api/properties.php", { method: "POST", body });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || "upload failed");
    byId("image").value = data.image;
    setStatus("照片已上傳，記得按儲存物件");
  } catch (err) {
    setStatus("照片上傳失敗，正式伺服器需支援 PHP 上傳。");
  }
}

async function deleteItem(id) {
  if (!confirm("確定刪除這筆新增物件？")) return;
  try {
    await fetch("api/properties.php", {
      method: "DELETE",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id }),
    });
  } catch (_) {}
  items = items.filter((item) => item.id !== id);
  localStorage.setItem("huowangCustomProperties", JSON.stringify(items));
  renderList();
}

function renderList() {
  const list = byId("list");
  if (!items.length) {
    list.innerHTML = "<div style='font-weight:900;color:#64748b'>目前還沒有後台新增物件。</div>";
    return;
  }
  list.innerHTML = items.map((item) => `
    <div class="row">
      <img src="${item.image || "assets/huowang-card.jpg"}" alt="">
      <div>
        <div class="row-title">${item.title || ""}</div>
        <div class="row-sub">${item.titleVi || ""}</div>
        <div class="row-sub">${item.area || ""}｜${item.category || ""}｜${item.price || ""}</div>
      </div>
      <div class="row-actions">
        <button class="edit" type="button" onclick='fillForm(${JSON.stringify(item).replace(/'/g, "&apos;")})'>編輯</button>
        <button class="delete" type="button" onclick='deleteItem(${JSON.stringify(item.id)})'>刪除</button>
      </div>
    </div>
  `).join("");
}

loadItems();
