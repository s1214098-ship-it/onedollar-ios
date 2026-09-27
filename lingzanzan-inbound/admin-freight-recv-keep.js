/* LZ_RECV_KEEP_FORM_20260927
   關閉入庫完成層：立刻隱藏、不重畫工作台、FIFO 不再卡在這筆 0 客戶。 */
function dismissFreightReceivedPrintReadyKeepForm() {
  window.__lzRecvOverlaySwallowUntil = Date.now() + 500;
  try { closeInventoryBarcodePrintOverlay(); } catch (error) {}
  document.querySelectorAll('[data-inventory-label-purpose-picker],[data-inventory-barcode-print-overlay],[data-freight-received-print-ready],[data-freight-quantity-confirm],[data-freight-fifo-item-adjust-modal]').forEach(function (node) {
    node.style.setProperty('display', 'none', 'important');
    node.style.setProperty('pointer-events', 'none', 'important');
    node.setAttribute('hidden', '');
  });
  document.querySelectorAll('.freight-receiving-workbench,[data-freight-receiving-workbench]').forEach(function (node) {
    node.style.removeProperty('pointer-events');
  });
  try { setFreightFifoProductScope([], ''); } catch (error) {}
  setTimeout(function () {
    document.querySelectorAll('[data-inventory-label-purpose-picker],[data-inventory-barcode-print-overlay],[data-freight-received-print-ready],[data-freight-quantity-confirm],[data-freight-fifo-item-adjust-modal]').forEach(function (node) {
      try { node.remove(); } catch (error) {}
    });
  }, 80);
}
