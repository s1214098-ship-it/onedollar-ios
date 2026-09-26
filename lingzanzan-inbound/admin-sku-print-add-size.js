/* Live sidecar excerpt patched into product matrix JS
 * Marker: LZ_SKU_PRINT_ADD_SIZE_20260926
 * 產品建檔明細：數量旁可按「列印條碼」；可用同一區的數量快速加入尺寸。
 */
  function addProductSizeFromMatrix() {
    // LZ_SKU_PRINT_ADD_SIZE_20260926: 明細列的數量可直接拿來加尺寸。
    syncProductVariantDraftFromMatrix();
    var typed = document.querySelector('[data-matrix-add-size-name]');
    var preset = document.querySelector('[data-matrix-add-size-preset]');
    var qtyInput = document.querySelector('[data-matrix-add-size-qty]');
    var sizeName = String((typed && typed.value) || (preset && preset.value) || '').trim();
    var qty = Math.max(0, Math.floor(Number(qtyInput && qtyInput.value || 0)));
    if (!sizeName) {
      toast('請輸入尺寸');
      return;
    }
    if (productDraft.sizes.indexOf(sizeName) === -1) productDraft.sizes.push(sizeName);
    (productDraft.colors.length ? productDraft.colors : [{ name: '未設定顏色' }]).forEach(function (color) {
      productDraft.stocks[(color.name || '未設定顏色') + '||' + sizeName] = qty;
    });
    rememberMatrixSize(sizeName);
    if (typed) typed.value = '';
    renderProductCreate();
    toast('已加入尺寸 ' + sizeName + (qty ? '／數量 ' + qty : ''));
  }
