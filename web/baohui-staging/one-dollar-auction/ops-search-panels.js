(function () {
  'use strict';

  const SEARCH_SECTIONS = [
    { id: 'members', title: '會員搜尋', hint: '姓名、Facebook、電話、地址、黑名單、備註', table: '.member-table' },
    { id: 'suppliers', title: '廠商搜尋', hint: '廠商、分類、聯絡人、電話、統編、地址', table: 'table' },
    { id: 'products', title: '產品搜尋', hint: '產品編號、條碼、名稱、顏色、尺寸、分類、倉位', table: 'table' },
    { id: 'stock-in', title: '進貨搜尋', hint: '產品、條碼、廠商、倉位、操作人、備註', table: 'table' },
    { id: 'customer-shipping', title: '銷售 / 出貨搜尋', hint: '客戶、電話、出貨單號、狀態', table: 'table' },
    { id: 'orders', title: '記單出貨搜尋', hint: '客戶、產品、電話、狀態、物流單號', table: 'table' },
    { id: 'schedule', title: '排程上架搜尋', hint: '產品、條碼、日期、上架狀態、結標狀態', table: 'table' }
  ];

  function pad(n) { return String(n).padStart(2, '0'); }
  function formatDate(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }
  function defaultDates() {
    const end = new Date();
    const start = new Date();
    start.setMonth(start.getMonth() - 1);
    return { start: formatDate(start), end: formatDate(end) };
  }
  function rowDateValue(row) {
    const source = row.getAttribute('data-created-at') || row.getAttribute('data-date') || row.textContent || '';
    const match = source.match(/20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}/);
    if (!match) return '';
    return match[0].replace(/\//g, '-').replace(/-(\d)(?=-|$)/g, '-0$1');
  }
  function rowText(row) {
    return (row.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }
  function getRows(section, selector) {
    const tables = Array.from(section.querySelectorAll(selector || 'table'));
    const rows = [];
    tables.forEach(table => {
      table.querySelectorAll('tbody tr').forEach(row => {
        if (!row.querySelector('td')) return;
        rows.push(row);
      });
    });
    return rows;
  }
  function filterSection(config) {
    const section = document.getElementById(config.id);
    if (!section) return;
    const panel = section.querySelector('[data-ops-search-panel]');
    if (!panel) return;
    const q = (panel.querySelector('[data-search-q]').value || '').trim().toLowerCase();
    const start = panel.querySelector('[data-search-start]').value || '';
    const end = panel.querySelector('[data-search-end]').value || '';
    const rows = getRows(section, config.table);
    let shown = 0;
    rows.forEach(row => {
      const textOk = !q || rowText(row).includes(q);
      const d = rowDateValue(row);
      const dateOk = !d || ((!start || d >= start) && (!end || d <= end));
      const ok = textOk && dateOk;
      row.style.display = ok ? '' : 'none';
      if (ok) shown += 1;
    });
    const result = panel.querySelector('[data-search-result]');
    if (result) result.textContent = '搜尋結果：' + shown + ' 筆';
  }
  function clearSection(config) {
    const section = document.getElementById(config.id);
    if (!section) return;
    const panel = section.querySelector('[data-ops-search-panel]');
    if (!panel) return;
    const dates = defaultDates();
    panel.querySelector('[data-search-q]').value = '';
    panel.querySelector('[data-search-start]').value = dates.start;
    panel.querySelector('[data-search-end]').value = dates.end;
    getRows(section, config.table).forEach(row => row.style.display = '');
    const result = panel.querySelector('[data-search-result]');
    if (result) result.textContent = '尚未篩選';
  }
  function makePanel(config) {
    const dates = defaultDates();
    const card = document.createElement('div');
    card.className = 'ops-search-card';
    card.setAttribute('data-ops-search-panel', config.id);
    card.innerHTML = `
      <div class="ops-search-title">
        <strong>${config.title}</strong>
        <span>${config.hint}</span>
      </div>
      <div class="ops-search-fields">
        <label>關鍵字<input type="search" data-search-q placeholder="${config.hint}"></label>
        <label>建檔起日<input type="date" data-search-start value="${dates.start}"></label>
        <label>建檔迄日<input type="date" data-search-end value="${dates.end}"></label>
        <button type="button" class="primary" data-search-submit>搜尋</button>
        <button type="button" class="secondary" data-search-clear>清除</button>
      </div>
      <div class="muted small" data-search-result>尚未篩選，日期預設近一個月；沒有日期的舊資料不會被隱藏。</div>
    `;
    card.querySelector('[data-search-submit]').addEventListener('click', () => filterSection(config));
    card.querySelector('[data-search-clear]').addEventListener('click', () => clearSection(config));
    card.querySelector('[data-search-q]').addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        filterSection(config);
      }
    });
    return card;
  }
  function mountSearchPanels() {
    SEARCH_SECTIONS.forEach(config => {
      const section = document.getElementById(config.id);
      if (!section || section.querySelector('[data-ops-search-panel]')) return;
      const firstHeading = section.querySelector('h2');
      const panel = makePanel(config);
      if (firstHeading && firstHeading.parentNode) {
        const after = firstHeading.nextElementSibling;
        if (after && after.classList.contains('muted')) after.insertAdjacentElement('afterend', panel);
        else firstHeading.insertAdjacentElement('afterend', panel);
      } else {
        section.prepend(panel);
      }
    });
  }
  function mountMemberCreateBridge() {
    const section = document.getElementById('member-create');
    if (!section || section.querySelector('[data-member-search-bridge]')) return;
    const box = document.createElement('div');
    box.className = 'ops-search-card';
    box.setAttribute('data-member-search-bridge', '1');
    box.innerHTML = `
      <div class="ops-search-title"><strong>會員搜尋 / 建檔整合</strong><span>先搜尋既有會員，沒有資料再新增，避免重複建檔。</span></div>
      <div class="ops-search-fields">
        <label>會員關鍵字<input type="search" data-member-bridge-q placeholder="姓名 / Facebook / 電話 / 地址"></label>
        <button type="button" class="primary">到會員資料搜尋</button>
      </div>
    `;
    box.querySelector('button').addEventListener('click', function () {
      const q = box.querySelector('[data-member-bridge-q]').value || '';
      if (typeof window.openOpsTab === 'function') window.openOpsTab('members');
      setTimeout(function () {
        const members = document.getElementById('members');
        const input = members && members.querySelector('[data-ops-search-panel] [data-search-q]');
        const button = members && members.querySelector('[data-ops-search-panel] [data-search-submit]');
        if (input) input.value = q;
        if (button) button.click();
      }, 80);
    });
    const h2 = section.querySelector('h2');
    if (h2) h2.insertAdjacentElement('afterend', box);
    else section.prepend(box);
  }
  function injectStyles() {
    if (document.getElementById('opsSearchPanelStyle')) return;
    const style = document.createElement('style');
    style.id = 'opsSearchPanelStyle';
    style.textContent = `
      .ops-search-card{border:1px solid #d9e3ee;background:#f8fbff;border-radius:8px;padding:14px;margin:12px 0 16px}
      .ops-search-title{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:10px}
      .ops-search-title strong{font-size:18px}
      .ops-search-title span{color:#667085}
      .ops-search-fields{display:grid;grid-template-columns:minmax(240px,2fr) minmax(150px,1fr) minmax(150px,1fr) auto auto;gap:10px;align-items:end}
      .ops-search-fields label{display:flex;flex-direction:column;gap:5px;font-weight:700}
      .ops-search-fields input{border:1px solid #d5dde7;border-radius:7px;padding:10px;background:#fff}
      @media (max-width:900px){.ops-search-fields{grid-template-columns:1fr}.ops-search-fields button{width:100%}}
    `;
    document.head.appendChild(style);
  }
  function init() {
    injectStyles();
    mountSearchPanels();
    mountMemberCreateBridge();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
