/* Live sidecar excerpt patched into assets/admin.js
 * Marker: LZ_PRINT_BTN_TEXT_20260926
 * 其他倉按鈕由「勾選有現貨」改為「全選列印／取消全選／無可列印」。
 */
  function syncFreightOtherStockSelectButtons(panel) {
    // LZ_PRINT_BTN_TEXT_20260926: 按鈕寫全選列印／取消全選，不再寫勾選有現貨。
    Array.from((panel && panel.querySelectorAll('[data-freight-other-stock-select]')) || []).forEach(function (button) {
      var warehouseCode = String(button.getAttribute('data-freight-other-stock-select') || '');
      var article = button.closest('[data-other-wh]') || (panel && panel.querySelector('[data-other-wh="' + warehouseCode + '"]'));
      var inputs = Array.from((article && article.querySelectorAll('[data-freight-other-stock-print]')) || []).filter(function (input) {
        return !input.disabled;
      });
      if (!inputs.length) {
        button.textContent = '無可列印';
        button.disabled = true;
        return;
      }
      button.disabled = false;
      var allOn = inputs.every(function (input) { return input.checked; });
      button.textContent = allOn ? '取消全選' : '全選列印';
    });
  }

