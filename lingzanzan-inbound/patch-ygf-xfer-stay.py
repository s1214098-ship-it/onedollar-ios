#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('/tmp/lz-ygf-xfer')
MARKER = 'LZ_YGF_XFER_STAY_20260927'
TAG = '20260927-xfer-stay-1'


def patch_js():
    p = ROOT / 'scanner-ygf-v59.js'
    t = p.read_text()
    if MARKER in t:
        print('js already patched')
        return
    t = t.replace(
        "  /* LZ_YGF_PICK_DEL_20260927: match cards stay tappable with 確定帶入; old stocktake sheets can be deleted. */",
        "  /* LZ_YGF_PICK_DEL_20260927: match cards stay tappable with 確定帶入; old stocktake sheets can be deleted. */\n  /* " + MARKER + ": keep 調撥 tab after reload; ignore rescan while picker open; 確定帶入 uses the tapped card. */",
        1,
    )
    old_tab = """  var currentTab = 'stocktake';
  var requestedTab = (new URLSearchParams(window.location.search || '')).get('tab') || '';
"""
    new_tab = """  var currentTab = 'stocktake';
  var requestedTab = (new URLSearchParams(window.location.search || '')).get('tab') || '';
  var scannerTabKey = 'lingzanzan-scanner-tab-v1';
  try {
    if (!requestedTab) requestedTab = String(sessionStorage.getItem(scannerTabKey) || localStorage.getItem(scannerTabKey) || '').trim();
  } catch (error) {}
  if (['stocktake', 'transfer', 'arrival', 'stockin', 'lookup'].indexOf(requestedTab) !== -1) currentTab = requestedTab;
"""
    if old_tab not in t:
        raise SystemExit('tab init not found')
    t = t.replace(old_tab, new_tab, 1)

    old_perm = "    input.disabled = false;\n    if (!canUseTab(currentTab)) switchTab(firstAllowed.dataset.tab, firstAllowed);\n"
    new_perm = """    input.disabled = false;
    var preferredTab = requestedTab || currentTab;
    if (canUseTab(preferredTab)) {
      var preferredButton = document.querySelector('[data-tab="' + preferredTab + '"]');
      if (currentTab !== preferredTab) switchTab(preferredTab, preferredButton);
      else applyScannerTabChrome(preferredTab, preferredButton);
    } else if (!canUseTab(currentTab)) switchTab(firstAllowed.dataset.tab, firstAllowed);
"""
    if old_perm not in t:
        raise SystemExit('applyPermissionUi tab switch not found')
    t = t.replace(old_perm, new_perm, 1)

    t = t.replace(
        "      beep(3);\n      setMessage('三聲：來源倉庫與目的倉庫設定不正確，尚未加入。', 'error');\n      return;\n",
        "      beep(3);\n      setMessage('三聲：來源倉庫與目的倉庫設定不正確，尚未加入。', 'error');\n      return false;\n",
        1,
    )
    t = t.replace(
        "      beep(3); setMessage('所選商品在「' + row.warehouse + '」，但調撥來源是「' + from + '」。請先選正確來源倉再查詢；尚未加入或扣庫存。', 'error'); return;\n",
        "      beep(3); setMessage('所選商品在「' + row.warehouse + '」，但調撥來源是「' + from + '」。請先選正確來源倉再查詢；尚未加入或扣庫存。', 'error'); return false;\n",
        1,
    )
    t = t.replace(
        "      setMessage('三聲：來源倉沒有庫存，尚未加入調撥單。', 'error');\n      return;\n",
        "      setMessage('三聲：來源倉沒有庫存，尚未加入調撥單。', 'error');\n      return false;\n",
        1,
    )
    t = t.replace(
        "        setMessage('兩聲：疑似重複掃描，累加後會超過來源庫存 ' + available + ' 件，本次未增加。', 'error');\n        return;\n",
        "        setMessage('兩聲：疑似重複掃描，累加後會超過來源庫存 ' + available + ' 件，本次未增加。請按「確定帶入」只用畫面上這張有庫存的卡。', 'error');\n        return false;\n",
        1,
    )
    t = t.replace(
        "        setMessage('三聲：單次數量超過來源庫存 ' + available + ' 件，尚未加入。', 'error');\n        return;\n",
        "        setMessage('三聲：單次數量超過來源庫存 ' + available + ' 件，尚未加入。', 'error');\n        return false;\n",
        1,
    )
    t = t.replace(
        "    resetProductSelection();\n    readyForNextScan();\n  }\n  function saveTransferSlip() {",
        "    resetProductSelection();\n    readyForNextScan();\n    return true;\n  }\n  function saveTransferSlip() {",
        1,
    )

    old_render_btn = """      return '<article class="match-card' + (selected === row ? ' is-selected' : '') + '" data-match-index="' + index + '"><span class="match-card-photo"><img src="' + escapeHtml(resolveImage(row.image)) + '" alt="商品對照圖" draggable="false"></span><span><b>' + escapeHtml(row.productCode) + '</b><em>' + escapeHtml(row.title) + '</em><strong>' + escapeHtml(row.color) + ' · ' + escapeHtml(row.size) + '</strong><small>分類：' + escapeHtml(row.category || '未設定分類') + '</small><small>' + escapeHtml(row.warehouse) + '／庫存 ' + stockOf(row) + ' 件</small><small>倉位：' + escapeHtml([row.shelf || '未設定貨架',row.layer || '未設定層位'].join('／')) + '</small><small>' + escapeHtml(stockPurposeText(row)) + '</small><small>' + escapeHtml(barcodeCheck) + '</small><small>' + escapeHtml(rowCostText(row)) + '</small><small>' + escapeHtml(last) + '</small><small class="target-stock">本次 ' + escapeHtml(wh === 'all' ? '全部倉庫' : wh) + ' 盤點前：' + targetStock + ' 件</small><code>' + escapeHtml(row.skuId) + '</code></span><span class="match-card-actions"><button type="button" data-match-select="' + index + '">選這個</button><button type="button" class="primary" data-match-confirm="' + index + '">確定帶入</button></span></article>';"""
    new_render_btn = """      return '<article class="match-card' + (selected === row ? ' is-selected' : '') + '" data-match-index="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '"><span class="match-card-photo"><img src="' + escapeHtml(resolveImage(row.image)) + '" alt="商品對照圖" draggable="false"></span><span><b>' + escapeHtml(row.productCode) + '</b><em>' + escapeHtml(row.title) + '</em><strong>' + escapeHtml(row.color) + ' · ' + escapeHtml(row.size) + '</strong><small>分類：' + escapeHtml(row.category || '未設定分類') + '</small><small>' + escapeHtml(row.warehouse) + '／庫存 ' + stockOf(row) + ' 件</small><small>倉位：' + escapeHtml([row.shelf || '未設定貨架',row.layer || '未設定層位'].join('／')) + '</small><small>' + escapeHtml(stockPurposeText(row)) + '</small><small>' + escapeHtml(barcodeCheck) + '</small><small>' + escapeHtml(rowCostText(row)) + '</small><small>' + escapeHtml(last) + '</small><small class="target-stock">本次 ' + escapeHtml(wh === 'all' ? '全部倉庫' : wh) + ' 盤點前：' + targetStock + ' 件</small><code>' + escapeHtml(row.skuId) + '</code></span><span class="match-card-actions"><button type="button" data-match-select="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">選這個</button><button type="button" class="primary" data-match-confirm="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">確定帶入</button></span></article>';"""
    if old_render_btn not in t:
        raise SystemExit('renderMatches card html not found')
    t = t.replace(old_render_btn, new_render_btn, 1)

    old_lookup = """  function lookup(code, options) {
    options = options || {};
    code = normalizeScanCode(code);
    if (!code) return;
"""
    new_lookup = """  function lookup(code, options) {
    options = options || {};
    code = normalizeScanCode(code);
    if (!code) return;
    if (matchPanel && !matchPanel.hidden && matchGrid && !matchGrid.hidden && (allMatches || []).length) {
      var waitingCode = normalizeScanCode(lastLookupCode || currentStocktakeScanCode || '');
      if (!waitingCode || waitingCode === code) {
        setMessage('請先選正確產品再按「確定帶入」。同一條碼不會再叮。', 'error');
        readyForNextScan();
        return;
      }
    }
"""
    if old_lookup not in t:
        raise SystemExit('lookup() start not found')
    t = t.replace(old_lookup, new_lookup, 1)

    old_chrome = """  function applyScannerTabChrome(tab, button) {
    currentTab = tab;
    document.body.setAttribute('data-current-scanner-tab', tab);
"""
    new_chrome = """  function applyScannerTabChrome(tab, button) {
    currentTab = tab;
    document.body.setAttribute('data-current-scanner-tab', tab);
    try {
      sessionStorage.setItem(scannerTabKey, tab);
      localStorage.setItem(scannerTabKey, tab);
    } catch (error) {}
"""
    if old_chrome not in t:
        raise SystemExit('applyScannerTabChrome not found')
    t = t.replace(old_chrome, new_chrome, 1)

    old_switch = """  function switchTab(tab, button) {
    if (!canUseTab(tab)) {
      setMessage('此帳號沒有這項功能權限。', 'error');
      return;
    }
    resetProductSelection();
    readyForNextScan();
    applyScannerTabChrome(tab, button);
"""
    new_switch = """  function switchTab(tab, button) {
    if (!canUseTab(tab)) {
      setMessage('此帳號沒有這項功能權限。', 'error');
      return;
    }
    var sameTab = currentTab === tab;
    if (!sameTab) {
      resetProductSelection();
      readyForNextScan();
    }
    applyScannerTabChrome(tab, button);
"""
    if old_switch not in t:
        raise SystemExit('switchTab not found')
    t = t.replace(old_switch, new_switch, 1)

    old_wh = """        if (requestedTab && canUseTab(requestedTab)) {
          var requestedButton = document.querySelector('[data-tab="' + requestedTab + '"]');
          if (requestedButton && !requestedButton.hidden) {
            switchTab(requestedTab, requestedButton);
            setTabsCollapsed(true);
          }
        }
"""
    new_wh = """        if (requestedTab && canUseTab(requestedTab)) {
          var requestedButton = document.querySelector('[data-tab="' + requestedTab + '"]');
          if (requestedButton && !requestedButton.hidden) {
            if (currentTab !== requestedTab) switchTab(requestedTab, requestedButton);
            else applyScannerTabChrome(requestedTab, requestedButton);
            setTabsCollapsed(true);
          }
        }
"""
    if old_wh not in t:
        raise SystemExit('warehouse requestedTab restore not found')
    t = t.replace(old_wh, new_wh, 1)

    old_click = """      var pickIndex = Number((confirmPick || selectPick).getAttribute(confirmPick ? 'data-match-confirm' : 'data-match-select'));
      var pickRow = allMatches[pickIndex];
      if (!pickRow) { setMessage('產品資料已過期，請再掃一次。', 'error'); return; }
      if (confirmPick) confirmMatchPick(pickRow, pickIndex);
"""
    new_click = """      var pickIndex = Number((confirmPick || selectPick).getAttribute(confirmPick ? 'data-match-confirm' : 'data-match-select'));
      var pickSku = String((confirmPick || selectPick).getAttribute('data-match-sku') || '');
      var pickRow = (pickSku && (allMatches || []).find(function (row) { return String(row && row.skuId || '') === pickSku; })) || allMatches[pickIndex];
      if (!pickRow) { setMessage('產品資料已過期，請再掃一次。', 'error'); return; }
      if (confirmPick) confirmMatchPick(pickRow, pickIndex);
"""
    if old_click not in t:
        raise SystemExit('click pickRow not found')
    t = t.replace(old_click, new_click, 1)

    p.write_text(t)
    print('patched js', p.stat().st_size)


def patch_css():
    p = ROOT / 'scanner-ygf-v59.css'
    t = p.read_text()
    if MARKER in t:
        print('css already patched')
        return
    t += """

/* """ + MARKER + """
   調撥／入庫／到貨選產品時也不能用 transform 浮層，否則 YGF 點「確定帶入」會點到空白再掃出兩聲嘟。 */
html body.scanner-pda-layout .match-panel:not([hidden]) .match-grid:not([hidden]),
html body[data-current-scanner-tab="transfer"] .match-panel:not([hidden]) .match-grid:not([hidden]),
html body[data-current-scanner-tab="arrival"] .match-panel:not([hidden]) .match-grid:not([hidden]),
html body[data-current-scanner-tab="stockin"] .match-panel:not([hidden]) .match-grid:not([hidden]),
html body[data-current-scanner-tab="lookup"] .match-panel:not([hidden]) .match-grid:not([hidden]) {
  position: static !important;
  transform: none !important;
  inset: auto !important;
  left: auto !important;
  right: auto !important;
  top: auto !important;
  bottom: auto !important;
  width: 100% !important;
  max-width: 100% !important;
  max-height: none !important;
  box-shadow: none !important;
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) !important;
  overflow: visible !important;
  z-index: auto !important;
}
html body.scanner-pda-layout .match-card-actions,
html body.scanner-pda-layout .match-card-actions button {
  position: relative !important;
  z-index: 5 !important;
  pointer-events: auto !important;
}
"""
    p.write_text(t)
    print('patched css', p.stat().st_size)


def patch_html():
    p = ROOT / 'scanner.html'
    t = p.read_text()
    t = t.replace('scanner-ygf-v59.css?v=20260927-pick-del-1', f'scanner-ygf-v59.css?v={TAG}')
    t = t.replace('scanner-ygf-v59.js?v=20260927-pick-del-1', f'scanner-ygf-v59.js?v={TAG}')
    t = t.replace('<b class="scanner-build-badge">版本 v156</b>', '<b class="scanner-build-badge">版本 v157</b>')
    p.write_text(t)
    print('patched html')


if __name__ == '__main__':
    patch_js()
    patch_css()
    patch_html()
    print('ok')
