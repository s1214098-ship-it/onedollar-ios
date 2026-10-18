const AREAS = {
  "宜蘭縣": ["宜蘭市","羅東鎮","蘇澳鎮","頭城鎮","礁溪鄉","壯圍鄉","員山鄉","冬山鄉","五結鄉","三星鄉","大同鄉","南澳鄉"],
  "台北市": ["中正區","大同區","中山區","松山區","大安區","萬華區","信義區","士林區","北投區","內湖區","南港區","文山區"],
  "新北市": ["板橋區","新莊區","中和區","永和區","土城區","三重區","蘆洲區","汐止區","淡水區","林口區"],
  "基隆市": ["中正區","七堵區","暖暖區","仁愛區","安樂區","信義區","中山區"],
  "桃園市": ["桃園區","中壢區","平鎮區","八德區","楊梅區","蘆竹區","大溪區","龜山區"],
  "新竹市": ["東區","北區","香山區"],
  "新竹縣": ["竹北市","竹東鎮","湖口鄉","新豐鄉"],
  "台中市": ["西屯區","南屯區","北屯區","北區","西區","南區","東區","中區","太平區","大里區"],
  "台南市": ["中西區","東區","南區","北區","安平區","安南區","永康區"],
  "高雄市": ["苓雅區","三民區","左營區","楠梓區","前鎮區","鳳山區"],
  "花蓮縣": ["花蓮市","吉安鄉","新城鄉","壽豐鄉"],
  "台東縣": ["台東市"]
};

function fillCountyDistrict(countyId, districtId, countyValue, districtValue) {
  const county = document.getElementById(countyId);
  const district = document.getElementById(districtId);
  if (!county || !district) return;
  county.innerHTML = `<option value="">請選擇縣市</option>` + Object.keys(AREAS).map((k) => `<option>${k}</option>`).join("");
  county.value = countyValue || "宜蘭縣";
  const refresh = () => {
    const list = AREAS[county.value] || [];
    district.innerHTML = `<option value="">請選擇鄉鎮市區</option>` + list.map((k) => `<option>${k}</option>`).join("");
    if (districtValue && list.includes(districtValue)) district.value = districtValue;
    else if (county.value === "宜蘭縣") district.value = "頭城鎮";
  };
  county.onchange = refresh;
  refresh();
}

async function postJson(url, payload) {
  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json;charset=UTF-8" },
    credentials: "same-origin",
    body: JSON.stringify(payload)
  });
  let data = {};
  try { data = await res.json(); } catch {}
  if (!res.ok || data.ok === false) throw new Error(data.error || "送出失敗");
  return data;
}

function showMsg(el, text, ok) {
  if (!el) return alert(text);
  el.className = "notice " + (ok ? "ok" : "err");
  el.textContent = text;
  el.hidden = false;
}
