(function (global) {
  "use strict";

  function qtyFallback(n) {
    const v = Number(n || 0);
    return Number.isFinite(v) ? v.toLocaleString("zh-TW") : "0";
  }

  global.baohuiRenderOneDollarMetrics = function (s, qtyText) {
    const fmt = typeof qtyText === "function" ? qtyText : qtyFallback;
    const summary = s || {};
    const productCount = Number(summary.product_count || 0);
    const inStock = Number(summary.in_stock_count || 0);
    const zeroStock = summary.zero_stock_count != null
      ? Number(summary.zero_stock_count)
      : Math.max(0, productCount - inStock);
    return `
      <div class="col-xl-3 col-md-6"><div class="ops-metric-card tone-blue"><div class="ops-metric-label">產品建檔</div><div class="ops-metric-value">${fmt(productCount)}</div><div class="ops-metric-meta">含零庫存 ${fmt(zeroStock)} 筆</div></div></div>
      <div class="col-xl-3 col-md-6"><div class="ops-metric-card tone-green"><div class="ops-metric-label">有可用庫存</div><div class="ops-metric-value">${fmt(inStock)}</div><div class="ops-metric-meta">可用 ${fmt(summary.stock_total)} 件</div></div></div>
      <div class="col-xl-3 col-md-6"><div class="ops-metric-card tone-amber"><div class="ops-metric-label">排程場次</div><div class="ops-metric-value">${fmt(summary.schedule_count)}</div><div class="ops-metric-meta">未上架 ${fmt(summary.pending_schedule_count)}</div></div></div>
      <div class="col-xl-3 col-md-6"><div class="ops-metric-card tone-violet"><div class="ops-metric-label">客戶 / 廠商</div><div class="ops-metric-value">${fmt(summary.member_count)} / ${fmt(summary.supplier_count)}</div><div class="ops-metric-meta">買家與供應商資料</div></div></div>
    `;
  };
})(window);
