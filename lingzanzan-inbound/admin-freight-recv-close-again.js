/* LZ_RECV_CLOSE_AGAIN_20260927
   入庫完成層右上與底部都有關閉；點了立刻拿掉，不被其他倉庫列表擋住。 */
panel.innerHTML = '<section><header class="freight-received-print-head">…<button type="button" data-freight-print-ready-close>關閉</button></header>…<div class="freight-received-print-actions">…<button type="button" data-freight-print-ready-close>關閉</button></div></section>';
panel.querySelectorAll('[data-freight-print-ready-close]').forEach(function (button) {
  button.addEventListener('pointerdown', closeNow, true);
});
