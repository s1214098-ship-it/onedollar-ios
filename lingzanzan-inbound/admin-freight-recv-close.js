/* Live sidecar excerpt patched into assets/admin.js
 * Marker: LZ_RECV_CLOSE_20260926
 * 正式入庫完成「關閉」要拿掉入庫完成層、條碼預覽、用途選單與驗收工作台，點背景／Esc 也能關。
 */
    var closeFreightReceivedPrintReady = function () {
      // LZ_RECV_CLOSE_20260926: 關閉要拿掉入庫完成層、條碼預覽、驗收工作台，點背景也能關。
      try { closeInventoryBarcodePrintOverlay(); } catch (error) {}
      try { closeInventoryLabelPurposePicker(); } catch (error) {}
      document.querySelectorAll('[data-freight-received-print-ready],[data-freight-receiving-workbench],[data-freight-quantity-confirm],[data-inventory-barcode-print-overlay],[data-inventory-label-purpose-picker]').forEach(function (node) {
        try { node.remove(); } catch (error) {}
      });
      try { clearFreightScanInputsAfterPrintClose(); } catch (clearError) { console.warn('print close clear failed', clearError); }
    };
    var readyCloseButton = panel.querySelector('[data-freight-print-ready-close]');
    if (readyCloseButton) {
      readyCloseButton.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        closeFreightReceivedPrintReady();
      }, true);
    }
    panel.addEventListener('click', function (event) {
      if (event.target === panel) {
        event.preventDefault();
        closeFreightReceivedPrintReady();
      }
    });
    document.addEventListener('keydown', function onReceivedEsc(event) {
      if (event.key !== 'Escape') return;
      if (!document.body.contains(panel)) {
        document.removeEventListener('keydown', onReceivedEsc);
        return;
      }
      event.preventDefault();
      document.removeEventListener('keydown', onReceivedEsc);
      closeFreightReceivedPrintReady();
    });
