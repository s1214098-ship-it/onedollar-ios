(function () {
  "use strict";

  var CARD_ID = "facebookDailyProgressCard";
  var BODY_ID = "facebookDailyProgress";
  var META_ID = "facebookDailyProgressMeta";
  var URL = "/one-dollar-auction/facebook-daily-progress.php";

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (ch) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[ch];
    });
  }

  function money(value) {
    var n = Number(value || 0);
    if (!Number.isFinite(n) || n <= 0) return "-";
    return Math.round(n).toLocaleString("zh-TW") + " 元";
  }

  function thumbUrl(src) {
    src = String(src || "").trim();
    if (!src) return "";
    if (/^https?:\/\//i.test(src) || src.indexOf("//") === 0) return src;
    if (src.charAt(0) === "/") return src;
    return "/one-dollar-auction/" + src.replace(/^\.\//, "");
  }

  function ensureStyle() {
    if (document.getElementById("facebookDailyProgressStyle")) return;
    var style = document.createElement("style");
    style.id = "facebookDailyProgressStyle";
    style.textContent = ""
      + "#facebookDailyProgressCard .fb-daily-product{display:flex;align-items:flex-start;gap:10px;text-align:left}"
      + "#facebookDailyProgressCard .fb-daily-thumb,#facebookDailyProgressCard .fb-daily-noimg{width:56px;height:56px;flex:0 0 56px;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc}"
      + "#facebookDailyProgressCard .fb-daily-thumb{object-fit:cover;display:block}"
      + "#facebookDailyProgressCard .fb-daily-noimg{color:#94a3b8;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}"
      + "#facebookDailyProgressCard .fb-daily-product-text{min-width:0;line-height:1.35}";
    document.head.appendChild(style);
  }

  function ensureCard() {
    if (document.getElementById(CARD_ID)) return document.getElementById(CARD_ID);
    ensureStyle();
    var html = ""
      + '<div class="form-card mb-4" id="' + CARD_ID + '">'
      + '  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">'
      + "    <div>"
      + '      <h5 class="mb-1">當日臉書上架進度表</h5>'
      + '      <div class="small text-muted" id="' + META_ID + '">載入今天要上架／已上架的場次。</div>'
      + "    </div>"
      + '    <div class="d-flex flex-wrap align-items-center gap-2">'
      + '      <button class="btn btn-sm btn-outline-secondary" type="button" id="facebookDailyProgressRefreshBtn">重新整理</button>'
      + '      <button class="btn btn-sm btn-outline-primary" type="button" id="facebookDailyProgressOpenBtn">打開當日日報</button>'
      + "    </div>"
      + "  </div>"
      + '  <div class="d-flex flex-wrap gap-2 mb-3" id="facebookDailyProgressSummary"></div>'
      + '  <div class="table-responsive">'
      + '    <table class="table table-sm table-bordered align-middle text-center">'
      + "      <thead class=\"table-light\">"
      + "        <tr><th>序</th><th>產品</th><th>預定上架</th><th>截標</th><th>狀態</th><th>待辦</th><th>金額</th><th>貼文</th></tr>"
      + "      </thead>"
      + '      <tbody id="' + BODY_ID + '"><tr><td colspan="8" class="text-muted">載入中…</td></tr></tbody>'
      + "    </table>"
      + "  </div>"
      + "</div>";
    var wrap = document.createElement("div");
    wrap.innerHTML = html;
    var card = wrap.firstChild;
    var leave = document.getElementById("leaveDashboardCard");
    var task = document.getElementById("taskDashboardCard")
      || document.getElementById("taskReportCenterCard");
    if (leave && leave.parentNode) {
      if (leave.nextSibling) leave.parentNode.insertBefore(card, leave.nextSibling);
      else leave.parentNode.appendChild(card);
    } else if (task && task.parentNode) {
      task.parentNode.insertBefore(card, task);
    } else {
      var dash = document.getElementById("page-dashboard");
      if (dash) dash.appendChild(card);
    }
    var refreshBtn = document.getElementById("facebookDailyProgressRefreshBtn");
    if (refreshBtn) refreshBtn.addEventListener("click", function () { loadFacebookDailyProgress(); });
    var openBtn = document.getElementById("facebookDailyProgressOpenBtn");
    if (openBtn) openBtn.addEventListener("click", function () {
      if (typeof openOneDollarModule === "function") openOneDollarModule("daily");
      else if (typeof goPage === "function") goPage("oneDollarAdmin");
    });
    return card;
  }

  function summaryChip(label, value, warn) {
    var cls = warn ? "badge text-bg-warning" : "badge text-bg-light border";
    return '<span class="' + cls + '">' + esc(label) + " " + esc(value) + "</span>";
  }

  function renderPayload(payload) {
    var body = document.getElementById(BODY_ID);
    var meta = document.getElementById(META_ID);
    var summary = document.getElementById("facebookDailyProgressSummary");
    if (!body) return;
    var compare = (payload && payload.compare) || {};
    var rows = (payload && payload.rows) || [];
    var date = (payload && payload.date) || "";
    var open = Number((payload && payload.open) || 0);
    if (meta) {
      meta.textContent = date
        ? (date + "　場次 " + (compare.planned || 0) + "　未完成 " + open + " 筆")
        : "載入今天要上架／已上架的場次。";
    }
    if (summary) {
      summary.innerHTML = [
        summaryChip("當日場次", compare.planned || 0, false),
        summaryChip("已上架", compare.posted || 0, false),
        summaryChip("待發文", compare.missing_post || 0, Number(compare.missing_post || 0) > 0),
        summaryChip("缺網址", compare.missing_url || 0, Number(compare.missing_url || 0) > 0),
        summaryChip("今日截標", compare.closing || 0, false),
        summaryChip("待得標", (Number(compare.need_winner || 0) + Number(compare.need_winner_record || 0)), (Number(compare.need_winner || 0) + Number(compare.need_winner_record || 0)) > 0)
      ].join("");
    }
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="8" class="text-muted">這天沒有上架或截標場次。</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (row) {
      var todos = Array.isArray(row.todos) ? row.todos : [];
      var todoHtml = todos.length
        ? '<span class="badge bg-warning text-dark">' + esc(row.todo_text || todos.join("、")) + "</span>"
        : '<span class="text-muted">已齊</span>';
      var post = row.post_url
        ? '<a href="' + esc(row.post_url) + '" target="_blank" rel="noopener">開貼文</a>'
        : '<span class="text-muted">未回填</span>';
      var imgSrc = thumbUrl(row.image);
      var thumb = imgSrc
        ? '<a href="' + esc(imgSrc) + '" target="_blank" rel="noopener"><img class="fb-daily-thumb" src="' + esc(imgSrc) + '" alt="' + esc(row.title || row.product_id || "") + '"></a>'
        : '<div class="fb-daily-noimg">無圖</div>';
      return "<tr>"
        + "<td>" + esc(String(row.queue || "").padStart(2, "0")) + "</td>"
        + '<td class="text-start"><div class="fb-daily-product">' + thumb
        + '<div class="fb-daily-product-text"><b>' + esc(row.product_id || "-") + "</b><br>" + esc(row.title || "-") + "</div></div></td>"
        + "<td>" + esc(row.publish_at || "-") + "</td>"
        + "<td>" + esc(row.close_at || "-") + "</td>"
        + "<td>" + esc(row.publish_status || "-") + '<div class="small text-muted">' + esc(row.auction_status || "") + "</div></td>"
        + "<td>" + todoHtml + "</td>"
        + "<td>" + esc(money(row.current_bid)) + "</td>"
        + "<td>" + post + "</td>"
        + "</tr>";
    }).join("");
  }

  function loadFacebookDailyProgress() {
    ensureCard();
    var body = document.getElementById(BODY_ID);
    if (body) body.innerHTML = '<tr><td colspan="8" class="text-muted">載入中…</td></tr>';
    fetch(URL + "?t=" + Date.now(), { credentials: "same-origin", cache: "no-store" })
      .then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
      .then(function (result) {
        if (!result.json || result.json.ok === false) {
          if (body) body.innerHTML = '<tr><td colspan="8" class="text-muted">還沒載入到當日上架資料，請打開當日日報或重新整理。</td></tr>';
          return;
        }
        renderPayload(result.json);
      })
      .catch(function () {
        if (body) body.innerHTML = '<tr><td colspan="8" class="text-muted">當日上架進度讀取失敗，請重新整理。</td></tr>';
      });
  }

  function wrapRefresh() {
    if (typeof window.refreshDashboard !== "function" || window.refreshDashboard.__fbDaily) return;
    var orig = window.refreshDashboard;
    window.refreshDashboard = function () {
      var result = orig.apply(this, arguments);
      loadFacebookDailyProgress();
      return result;
    };
    window.refreshDashboard.__fbDaily = true;
  }

  function apply() {
    wrapRefresh();
    loadFacebookDailyProgress();
  }

  apply();
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", apply);
})();
