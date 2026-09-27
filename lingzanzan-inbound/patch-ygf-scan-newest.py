#!/usr/bin/env python3
from pathlib import Path

ROOT = Path('/tmp/lz-ygf-xfer')
MARKER = 'LZ_YGF_SCAN_NEWEST_20260927'
TAG = '20260927-scan-newest-1'


def must_replace(t, old, new, label):
    if old not in t:
        raise SystemExit('missing: ' + label)
    return t.replace(old, new, 1)


def patch_js():
    p = ROOT / 'scanner-ygf-v59.js'
    t = p.read_text()
    if MARKER in t:
        print('js already patched')
        return t
    t = t.replace(
        "  /* LZ_YGF_XFER_STAY_20260927: keep 調撥 tab after reload; ignore rescan while picker open; 確定帶入 uses the tapped card. */",
        "  /* LZ_YGF_XFER_STAY_20260927: keep 調撥 tab after reload; ignore rescan while picker open; 確定帶入 uses the tapped card. */\n  /* " + MARKER + ": newest scan first on 調撥/盤點; match cards handle touch as well as click. */",
        1,
    )

    old_focus = """      if (currentTab === 'stocktake' && matchPanel && !matchPanel.hidden) {
        field.blur();
        return;
      }"""
    new_focus = """      if (matchPanel && !matchPanel.hidden && matchGrid && !matchGrid.hidden) {
        field.blur();
        return;
      }"""
    t = must_replace(t, old_focus, new_focus, 'focusActiveScanInput match blur')

    old_fast = """  function stocktakeFastRowKey(row, shelf, layer) {
    return [row && (row.skuId || row.barcode || row.productCode) || '', shelf || '', layer || ''].join('|');
  }"""
    new_fast = """  function stocktakeFastRowKey(row, shelf, layer) {
    return [row && (row.skuId || row.barcode || row.productCode) || '', shelf || '', layer || ''].join('|');
  }
  function scanInstant(value) {
    return Date.parse(value || '') || 0;
  }
  function syncMatchPickerChrome() {
    var open = !!(matchPanel && !matchPanel.hidden && matchGrid && !matchGrid.hidden);
    document.body.classList.toggle('is-match-picker-open', open);
    if (open) {
      if (input) try { input.blur(); } catch (error) {}
      if (manualInput) try { manualInput.blur(); } catch (error) {}
    }
  }"""
    t = must_replace(t, old_fast, new_fast, 'scan helpers')

    t = must_replace(
        t,
        "    }).forEach(function (indexed) {\n      var line=indexed.line, draftIndex=indexed.index;\n      if (!(line && line.action === 'stocktake' && line.at && new Date(line.at).getTime() >= scannerOpenedAt)) return;",
        "    }).forEach(function (indexed) {\n      var line=indexed.line, draftIndex=indexed.index;\n      if (!(line && line.action === 'stocktake')) return;\n      var scannedMs = scanInstant(line.serverScannedAt || line.at);\n      if (!scannedMs || scannedMs < scannerOpenedAt - 2000) return;",
        'fast row time filter',
    )

    old_render_transfer = """    list.innerHTML = (hasPreviousDraft ? '<p class="empty">注意：下列含上次未儲存草稿，不是本次空白掃描產生；不需要請按「清空本次調撥單」。</p>' : '') + transferDraft.map(function (line, index) {
      var at = Date.parse(line.lastScannedAt || line.scannedAt || '');
      var scanLabel = (!at || at < scannerOpenedAt) ? '上次未儲存草稿' : '本次掃描';
      return '<article class="transfer-draft-line">' +"""
    new_render_transfer = """    var orderedTransfer = transferDraft.map(function (line, index) { return {line:line, index:index}; }).sort(function (a, b) {
      return scanInstant(b.line.lastScannedAt || b.line.scannedAt) - scanInstant(a.line.lastScannedAt || a.line.scannedAt) || a.index - b.index;
    });
    list.scrollTop = 0;
    list.innerHTML = (hasPreviousDraft ? '<p class="empty">注意：下列含上次未儲存草稿，不是本次空白掃描產生；不需要請按「清空本次調撥單」。</p>' : '') + orderedTransfer.map(function (item) {
      var line = item.line, index = item.index;
      var at = scanInstant(line.lastScannedAt || line.scannedAt);
      var scanLabel = (!at || at < scannerOpenedAt) ? '上次未儲存草稿' : '本次掃描';
      return '<article class="transfer-draft-line">' +"""
    t = must_replace(t, old_render_transfer, new_render_transfer, 'renderTransferDraft newest first')

    old_dup = """      transferDraft[existingIndex].qty = nextQty;
      transferDraft[existingIndex].lastScannedAt = new Date().toISOString();
      saveTransferDraft();"""
    new_dup = """      var existingLine = transferDraft.splice(existingIndex, 1)[0];
      existingLine.qty = nextQty;
      existingLine.lastScannedAt = new Date().toISOString();
      transferDraft.unshift(existingLine);
      saveTransferDraft();"""
    t = must_replace(t, old_dup, new_dup, 'promote duplicate transfer line')

    t = must_replace(
        t,
        "      transferDraft.push({",
        "      transferDraft.unshift({",
        'unshift new transfer line',
    )
    t = must_replace(
        t,
        "        computerCategory: '',\n        scannedAt: new Date().toISOString()\n      });",
        "        computerCategory: '',\n        scannedAt: new Date().toISOString(),\n        lastScannedAt: new Date().toISOString()\n      });",
        'lastScannedAt on new transfer line',
    )

    t = must_replace(
        t,
        "    if (stockinQty) stockinQty.value = '1';\n    if (stockinSummary) stockinSummary.textContent = '先選建檔倉與本批預設值；掃碼會加入下方清單，送審前仍可逐筆修改。';\n  }",
        "    if (stockinQty) stockinQty.value = '1';\n    if (stockinSummary) stockinSummary.textContent = '先選建檔倉與本批預設值；掃碼會加入下方清單，送審前仍可逐筆修改。';\n    syncMatchPickerChrome();\n  }",
        'resetProductSelection sync picker',
    )

    old_render_end = """      return '<article class="match-card' + (selected === row ? ' is-selected' : '') + '" data-match-index="' + index + '" data-match-sku="' + escapeHtml(String(row.skuId || '')) + '">"""
    # keep same card html; append chrome after join
    old_after_matches = """    }).join('');
  }
  function choose(row, index, manualColorConfirmation, options) {"""
    new_after_matches = """    }).join('');
    syncMatchPickerChrome();
    if (!matchPanel.hidden && matchPanel.scrollIntoView) {
      try { matchPanel.scrollIntoView({block:'start'}); } catch (error) {}
    }
  }
  function choose(row, index, manualColorConfirmation, options) {"""
    t = must_replace(t, old_after_matches, new_after_matches, 'renderMatches chrome')

    old_restore = """    draft = draft.filter(function (line) { return !line || line.action !== 'stocktake'; });
    stocktakeLiveCounts = {};
    lines.forEach(function (line, index) {
      line = line || {};
      var barcode = String(line.barcode || line.sourceSkuId || '');
      var countedQty = Math.max(0, Number(line.countedQty || 0));
      var previousStock = Number(line.previousStock || 0);
      draft.push({
        at: new Date(restoredAt + index).toISOString(),
        serverScannedAt: line.scannedAt || '',"""
    new_restore = """    draft = draft.filter(function (line) { return !line || line.action !== 'stocktake'; });
    stocktakeLiveCounts = {};
    lines.slice().sort(function (a, b) {
      return scanInstant((b || {}).scannedAt) - scanInstant((a || {}).scannedAt);
    }).forEach(function (line, index) {
      line = line || {};
      var barcode = String(line.barcode || line.sourceSkuId || '');
      var countedQty = Math.max(0, Number(line.countedQty || 0));
      var previousStock = Number(line.previousStock || 0);
      draft.push({
        at: line.scannedAt || new Date(restoredAt - index).toISOString(),
        serverScannedAt: line.scannedAt || '',"""
    t = must_replace(t, old_restore, new_restore, 'restore newest first')

    old_details = """          var details = canCorrectQty && (item.lines || []).length ? '<div class="own-session-lines"><div class="own-session-selection"><button type="button" data-own-stocktake-select-all="' + sessionIndex + '">全選</button><span data-own-stocktake-selected-count="' + sessionIndex + '">已選 0 筆</span><button type="button" class="remove-selected-own-session" data-own-stocktake-remove-selected="' + sessionIndex + '" disabled>刪除已選</button></div>' + item.lines.map(function (line, lineIndex) { return ownSessionLineHtml(sessionIndex, line, lineIndex); }).join('') + '<button type="button" class="clear-own-session" data-own-stocktake-clear="' + sessionIndex + '">清除這張盤點單全部商品</button></div>' : '';"""
    new_details = """          var details = canCorrectQty && (item.lines || []).length ? '<div class="own-session-lines"><div class="own-session-selection"><button type="button" data-own-stocktake-select-all="' + sessionIndex + '">全選</button><span data-own-stocktake-selected-count="' + sessionIndex + '">已選 0 筆</span><button type="button" class="remove-selected-own-session" data-own-stocktake-remove-selected="' + sessionIndex + '" disabled>刪除已選</button></div>' + item.lines.map(function (line, lineIndex) { return {line:line, lineIndex:lineIndex}; }).sort(function (a, b) { return scanInstant(b.line.scannedAt) - scanInstant(a.line.scannedAt) || b.lineIndex - a.lineIndex; }).map(function (entry) { return ownSessionLineHtml(sessionIndex, entry.line, entry.lineIndex); }).join('') + '<button type="button" class="clear-own-session" data-own-stocktake-clear="' + sessionIndex + '">清除這張盤點單全部商品</button></div>' : '';"""
    t = must_replace(t, old_details, new_details, 'own-session newest first')

    old_click_start = """  document.addEventListener('click', function (event) {
    var modeButton = event.target.closest('[data-stockin-mode-button]');"""
    new_click_start = """  var lastMatchPickAt = 0;
  function handleMatchPickFromEvent(event) {
    if (!event || !event.target || !event.target.closest) return false;
    var confirmPick = event.target.closest('[data-match-confirm]');
    var selectPick = event.target.closest('[data-match-select]');
    var matchCard = (!confirmPick && !selectPick) ? event.target.closest('[data-match-index]') : null;
    if (!confirmPick && !selectPick && !matchCard) return false;
    if (event.preventDefault) event.preventDefault();
    if (event.stopPropagation) event.stopPropagation();
    if (Date.now() - lastMatchPickAt < 450) return true;
    lastMatchPickAt = Date.now();
    var pickEl = confirmPick || selectPick || matchCard;
    var pickIndex = Number(pickEl.getAttribute(confirmPick ? 'data-match-confirm' : (selectPick ? 'data-match-select' : 'data-match-index')));
    var pickSku = String(pickEl.getAttribute('data-match-sku') || '');
    var pickRow = (pickSku && (allMatches || []).find(function (row) { return String(row && row.skuId || '') === pickSku; })) || (allMatches || [])[pickIndex];
    if (!pickRow) { setMessage('產品資料已過期，請再掃一次。', 'error'); return true; }
    if (confirmPick) confirmMatchPick(pickRow, pickIndex);
    else {
      choose(pickRow, pickIndex, true, {keepPicker: true});
      document.querySelectorAll('.match-card').forEach(function (card) {
        card.classList.toggle('is-selected', String(card.getAttribute('data-match-sku') || '') === String(pickRow.skuId || ''));
      });
      setMessage('已選 ' + (pickRow.productCode || '') + '／' + (pickRow.color || '') + '。確認是這件就按「確定帶入」。', 'ok');
    }
    return true;
  }
  document.addEventListener('click', function (event) {
    if (handleMatchPickFromEvent(event)) return;
    var modeButton = event.target.closest('[data-stockin-mode-button]');"""
    t = must_replace(t, old_click_start, new_click_start, 'match pick helper')

    old_confirm_block = """    var confirmPick = event.target.closest && event.target.closest('[data-match-confirm]');
    var selectPick = event.target.closest && event.target.closest('[data-match-select]');
    var matchCard = (!confirmPick && !selectPick) ? (event.target.closest && event.target.closest('[data-match-index]')) : null;
    var target = confirmPick || selectPick || matchCard || (event.target.closest && event.target.closest('button'));
    if (!target) return;
    if (confirmPick || selectPick) {
      event.preventDefault();
      if (event.stopPropagation) event.stopPropagation();
      var pickIndex = Number((confirmPick || selectPick).getAttribute(confirmPick ? 'data-match-confirm' : 'data-match-select'));
      var pickSku = String((confirmPick || selectPick).getAttribute('data-match-sku') || '');
      var pickRow = (pickSku && (allMatches || []).find(function (row) { return String(row && row.skuId || '') === pickSku; })) || allMatches[pickIndex];
      if (!pickRow) { setMessage('產品資料已過期，請再掃一次。', 'error'); return; }
      if (confirmPick) confirmMatchPick(pickRow, pickIndex);
      else {
        choose(pickRow, pickIndex, true, {keepPicker: true});
        setMessage('已選 ' + (pickRow.productCode || '') + '／' + (pickRow.color || '') + '。確認是這件就按「確定帶入」。', 'ok');
      }
      return;
    }"""
    new_confirm_block = """    var target = event.target.closest && event.target.closest('button,[data-match-index]');
    if (!target) return;"""
    t = must_replace(t, old_confirm_block, new_confirm_block, 'remove duplicate pick block')

    old_boot = """  renderSession();
  applyPermissionUi();
  initStockinSelectControls();"""
    new_boot = """  renderSession();
  applyPermissionUi();
  ['pointerup', 'touchend'].forEach(function (type) {
    document.addEventListener(type, function (event) {
      if (handleMatchPickFromEvent(event)) return;
    }, {capture: true, passive: false});
  });
  initStockinSelectControls();"""
    if "['pointerup', 'touchend']" not in t:
        t = must_replace(t, old_boot, new_boot, 'pointer/touch pick bind')

    p.write_text(t)
    print('patched js', p.stat().st_size)
    return t


def patch_css():
    p = ROOT / 'scanner-ygf-v59.css'
    t = p.read_text()
    if MARKER in t:
        print('css already patched')
        return
    t += """

/* """ + MARKER + """
   任何分頁的產品卡都不能再用 transform 浮層；底部「登入盤點機」也不能蓋住選這個／確定帶入。 */
html body .match-panel:not([hidden]) .match-grid:not([hidden]),
html body .match-panel:not([hidden]) [data-match-first-location]:not([hidden]) {
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
  z-index: auto !important;
  overflow: visible !important;
}
html body.is-match-picker-open footer {
  pointer-events: none !important;
}
html body.is-match-picker-open .match-card,
html body.is-match-picker-open .match-card-actions,
html body.is-match-picker-open .match-card-actions button {
  position: relative !important;
  z-index: 30 !important;
  pointer-events: auto !important;
}
html body .transfer-draft-list,
html body [data-stocktake-fast-rows],
html body .own-session-lines {
  display: flex !important;
  flex-direction: column !important;
}
"""
    p.write_text(t)
    print('patched css', p.stat().st_size)


def patch_html():
    p = ROOT / 'scanner.html'
    t = p.read_text()
    t = t.replace('scanner-ygf-v59.css?v=20260927-xfer-stay-1', f'scanner-ygf-v59.css?v={TAG}')
    t = t.replace('scanner-ygf-v59.js?v=20260927-xfer-stay-1', f'scanner-ygf-v59.js?v={TAG}')
    t = t.replace('<b class="scanner-build-badge">版本 v157</b>', '<b class="scanner-build-badge">版本 v158</b>')
    p.write_text(t)
    print('patched html')


def patch_php():
    p = ROOT / 'scanner-api.php'
    t = p.read_text()
    if MARKER in t:
        print('php already patched')
        return
    old = """    $lineIndex=null;foreach(($sessions[$found]['lines']??[]) as $i=>$old)if((string)($old['productId']??'')===$line['productId']&&(string)($old['color']??'')===$line['color']&&(string)($old['size']??'')===$line['size']&&(string)($old['warehouse']??'')===$warehouse&&(string)($old['shelf']??'')===$shelf&&(string)($old['layer']??'')===$layer){$lineIndex=$i;break;}if($lineIndex===null)$sessions[$found]['lines'][]=$line;else$sessions[$found]['lines'][$lineIndex]=$line;"""
    new = """    if(!isset($sessions[$found]['lines'])||!is_array($sessions[$found]['lines']))$sessions[$found]['lines']=[];
    /* """ + MARKER + """ newest scanned SKU stays first on the slip */
    $lineIndex=null;foreach($sessions[$found]['lines'] as $i=>$old)if((string)($old['productId']??'')===$line['productId']&&(string)($old['color']??'')===$line['color']&&(string)($old['size']??'')===$line['size']&&(string)($old['warehouse']??'')===$warehouse&&(string)($old['shelf']??'')===$shelf&&(string)($old['layer']??'')===$layer){$lineIndex=$i;break;}
    if($lineIndex===null){array_unshift($sessions[$found]['lines'],$line);}else{$sessions[$found]['lines'][$lineIndex]=$line;$moved=array_splice($sessions[$found]['lines'],$lineIndex,1);array_unshift($sessions[$found]['lines'],$moved[0]);}"""
    if old not in t:
        raise SystemExit('php stocktake line append not found')
    t = t.replace(old, new, 1)
    p.write_text(t)
    print('patched php', p.stat().st_size)


if __name__ == '__main__':
    patch_js()
    patch_css()
    patch_html()
    patch_php()
    print('ok')
