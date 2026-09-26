(function () {
  /* LZ_NO_HINTS_20260926 */
  function scrubProductHints() {
    var preview = document.querySelector('[data-product-main-preview]');
    if (preview && !preview.querySelector('img')) {
      var empty = String(preview.textContent || '').replace(/\s+/g, '');
      if (empty === '先新增主圖' || empty === '尚未選擇圖片') preview.textContent = '';
    }
    var similar = document.querySelector('[data-product-name-similar-panel]');
    if (similar && similar.querySelector('.product-name-similar-empty')) {
      similar.hidden = true;
      similar.innerHTML = '';
    }
    var rule = document.querySelector('[data-product-official-barcode-rule]');
    if (rule) {
      rule.hidden = true;
      rule.textContent = '';
    }
    var note = document.querySelector('[data-product-auto-code-note]');
    if (note) {
      note.hidden = true;
      note.textContent = '';
    }
    var sample = document.querySelector('[data-product-official-barcode-sample]');
    if (sample && /選分類|例如/.test(String(sample.textContent || ''))) {
      sample.textContent = '';
    }
  }
  var scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      scrubProductHints();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scrubProductHints);
  else scrubProductHints();
  try {
    new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true, characterData: true });
  } catch (error) {}
})();
