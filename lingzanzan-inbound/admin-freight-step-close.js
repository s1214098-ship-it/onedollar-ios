/* Live sidecar excerpt patched into assets/admin.js
 * Marker: LZ_SIZE_STEP_20260927 / LZ_RECV_CLOSE_KEEP_20260927
 * 尺寸格只顯示 ＋／－；入庫完成「關閉」拿掉完成層，不重置建檔資料、不自動跳出用途選單。
 */
function dismissFreightReceivedPrintReadyKeepForm() {
  try { closeInventoryBarcodePrintOverlay(); } catch (error) {}
  document.querySelectorAll('[data-inventory-label-purpose-picker],[data-inventory-barcode-print-overlay],[data-freight-received-print-ready],[data-freight-quantity-confirm]').forEach(function (node) {
    try { node.remove(); } catch (error) {}
  });
}

function sizeMatrixPlusButton(size) {
  return '<button type="button" class="freight-qty-add-one" aria-label="加一件 ' + size + '" data-freight-size-matrix-plus>＋</button>';
}
