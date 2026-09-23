#!/usr/bin/env python3
"""Let schedule metric dialogs select products to edit or delete."""

from __future__ import annotations

from pathlib import Path

PATCHES = [
    (
        """$notice = '';
$opsInitialTab = '';
$instantDispatchTaskIds = [];
""",
        """$notice = '';
$opsInitialTab = '';
$reopenScheduleMetricKey = '';
$instantDispatchTaskIds = [];
""",
    ),
    (
        """            $notice = $parts ? implode('；', $parts) : '沒有產品被刪除。';
        }
    }

    if ($action === 'delete_product') {
""",
        """            $notice = $parts ? implode('；', $parts) : '沒有產品被刪除。';
        }
        $requestedTab = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['ops_tab'] ?? ''));
        if ($requestedTab !== '') $opsInitialTab = $requestedTab;
        $reopenScheduleMetricKey = preg_replace('/[^a-z_]/', '', (string)($_POST['schedule_metric_key'] ?? ''));
    }

    if ($action === 'delete_product') {
""",
    ),
    (
        """        $notice = $noticeParts ? implode(' ', $noticeParts) : '沒有刪到排程：請先勾選要刪除的排程。';
        $opsInitialTab = 'schedule';
    }
""",
        """        $notice = $noticeParts ? implode(' ', $noticeParts) : '沒有刪到排程：請先勾選要刪除的排程。';
        $requestedTab = preg_replace('/[^a-z0-9_-]/i', '', (string)($_POST['ops_tab'] ?? 'schedule'));
        $opsInitialTab = $requestedTab !== '' ? $requestedTab : 'schedule';
        $reopenScheduleMetricKey = preg_replace('/[^a-z_]/', '', (string)($_POST['schedule_metric_key'] ?? ''));
    }
""",
    ),
    (
        """.schedule-metric-list{display:grid;gap:10px}.schedule-metric-row{display:grid;grid-template-columns:58px minmax(180px,1fr) minmax(150px,.8fr) auto;gap:12px;align-items:center;padding:10px;border:1px solid #d8e2ef;border-radius:9px;background:#f8fafc}.schedule-metric-row img,.schedule-metric-no-image{width:58px;height:52px;object-fit:cover;border-radius:7px;background:#e2e8f0;display:grid;place-items:center;color:#64748b;font-size:11px}.schedule-metric-row b,.schedule-metric-row span,.schedule-metric-row small{display:block}.schedule-metric-row b{color:#0f3158}.schedule-metric-row span{color:#0f766e;font-weight:800}.schedule-metric-row small{margin-top:3px;color:#64748b}
@media(max-width:700px){.schedule-metric-row{grid-template-columns:52px minmax(0,1fr)}.schedule-metric-row>.schedule-metric-time,.schedule-metric-row>.button-like{grid-column:1/-1}.schedule-metric-row img,.schedule-metric-no-image{width:52px;height:48px}}
""",
        """.schedule-metric-list{display:grid;gap:10px}.schedule-metric-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 12px}.schedule-metric-row{display:grid;grid-template-columns:24px 58px minmax(180px,1fr) minmax(150px,.8fr) minmax(160px,auto);gap:12px;align-items:center;padding:10px;border:1px solid #d8e2ef;border-radius:9px;background:#f8fafc}.schedule-metric-row img,.schedule-metric-no-image{width:58px;height:52px;object-fit:cover;border-radius:7px;background:#e2e8f0;display:grid;place-items:center;color:#64748b;font-size:11px}.schedule-metric-row b,.schedule-metric-row span,.schedule-metric-row small{display:block}.schedule-metric-row b{color:#0f3158}.schedule-metric-row span{color:#0f766e;font-weight:800}.schedule-metric-row small{margin-top:3px;color:#64748b}.schedule-metric-actions{display:flex;flex-wrap:wrap;gap:6px;justify-content:flex-end}.schedule-metric-pick-label{display:flex;align-items:center;margin:0}
@media(max-width:700px){.schedule-metric-row{grid-template-columns:24px 52px minmax(0,1fr)}.schedule-metric-row>.schedule-metric-time,.schedule-metric-row>.schedule-metric-actions{grid-column:1/-1}.schedule-metric-row img,.schedule-metric-no-image{width:52px;height:48px}}
""",
    ),
    (
        """    <?php foreach($scheduleMetricGroups as $metricKey=>$metricGroup): ?>
      <dialog id="scheduleMetricDialog-<?=h($metricKey)?>" class="today-listed-dialog schedule-metric-dialog">
        <div class="today-listed-dialog-shell">
          <header class="today-listed-dialog-head"><div><h3><?=h($metricGroup['title'])?>（<?=h(count($metricGroup['rows']))?> 筆）</h3><p><?=h($metricGroup['note'])?></p></div><button type="button" class="today-listed-dialog-close close-schedule-metric-dialog">關閉</button></header>
          <div class="today-listed-dialog-body"><div class="schedule-metric-list">
             <?php foreach($metricGroup['rows'] as $metricRow): $metricProduct=product_by_id($products,$metricRow['product_id']??''); $metricTitle=trim((string)($metricProduct['title']??($metricRow['product_title']??'')))?:'未命名產品'; $metricImage=product_image_public_url(trim((string)($metricRow['schedule_image']??($metricProduct['image']??'')))); $metricListingSerial=schedule_listing_serial($metricRow); ?>
              <div class="schedule-metric-row">
                <?php if($metricImage!==''): ?><img src="<?=h($metricImage)?>" alt="<?=h($metricTitle)?>"><?php else: ?><div class="schedule-metric-no-image">無圖片</div><?php endif; ?>
                <div><b><?=h($metricTitle)?></b><span><?=h($metricRow['product_id']??'-')?></span><small><?=h(schedule_publish_result_reason($metricRow)?:($metricRow['publish_status']??'未上架'))?></small></div>
                <div class="schedule-metric-time"><small>上架：<?=h($metricRow['actual_publish_at']??($metricRow['scheduled_publish_at']??($metricRow['publish_at']??'-')))?></small><small>結標：<?=h($metricRow['close_at']??'-')?></small></div>
                <?php if(!empty($metricRow['post_url'])): ?><a class="button-like" href="<?=h(safe_http_url($metricRow['post_url']))?>" target="_blank" rel="noopener">開 Facebook 貼文</a><?php elseif($metricKey==='missing_url' && $metricListingSerial!==''): ?><a class="button-like" href="https://www.facebook.com/groups/onecheep/search/?q=<?=rawurlencode('#'.$metricListingSerial)?>" target="_blank" rel="noopener">用排程碼查 Facebook</a><?php else: ?><span></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
            <?php if(!$metricGroup['rows']): ?><div class="today-listed-empty">目前沒有符合這個條件的產品。</div><?php endif; ?>
          </div></div>
        </div>
      </dialog>
    <?php endforeach; ?>
""",
        """    <form id="scheduleMetricDeleteSchedulesForm" method="post" onsubmit="return confirm('確定刪除勾選的排程？實體庫存數量不變。');">
      <input type="hidden" name="action" value="delete_schedules">
      <input type="hidden" name="ops_tab" value="schedule">
      <input type="hidden" name="schedule_metric_key" value="" class="schedule-metric-key-field">
    </form>
    <form id="scheduleMetricDeleteProductsForm" method="post" onsubmit="return confirm('確定刪除勾選產品？已有排程的產品會保護不刪；若要從這個區塊拿掉，請改按刪除勾選排程。');">
      <input type="hidden" name="action" value="delete_products_bulk">
      <input type="hidden" name="ops_tab" value="schedule">
      <input type="hidden" name="schedule_metric_key" value="" class="schedule-metric-key-field">
    </form>
    <?php foreach($scheduleMetricGroups as $metricKey=>$metricGroup): ?>
      <dialog id="scheduleMetricDialog-<?=h($metricKey)?>" class="today-listed-dialog schedule-metric-dialog" data-metric-key="<?=h($metricKey)?>">
        <div class="today-listed-dialog-shell">
          <header class="today-listed-dialog-head"><div><h3><?=h($metricGroup['title'])?>（<?=h(count($metricGroup['rows']))?> 筆）</h3><p><?=h($metricGroup['note'])?></p></div><button type="button" class="today-listed-dialog-close close-schedule-metric-dialog">關閉</button></header>
          <div class="today-listed-dialog-body">
            <?php if($metricGroup['rows']): ?>
            <div class="schedule-metric-toolbar">
              <label class="check"><input type="checkbox" class="schedule-metric-select-all"> 全選產品</label>
              <button type="button" class="button-like schedule-metric-edit-selected">編輯勾選產品</button>
              <button type="button" class="danger-button schedule-metric-delete-schedules">刪除勾選排程</button>
              <button type="button" class="danger schedule-metric-delete-products">刪除勾選產品</button>
            </div>
            <?php endif; ?>
            <div class="schedule-metric-list">
             <?php foreach($metricGroup['rows'] as $metricRow): $metricProduct=product_by_id($products,$metricRow['product_id']??''); $metricTitle=trim((string)($metricProduct['title']??($metricRow['product_title']??'')))?:'未命名產品'; $metricImage=product_image_public_url(trim((string)($metricRow['schedule_image']??($metricProduct['image']??'')))); $metricListingSerial=schedule_listing_serial($metricRow); $metricProductId=trim((string)($metricRow['product_id']??($metricProduct['id']??''))); $metricScheduleId=trim((string)($metricRow['id']??'')); ?>
              <div class="schedule-metric-row">
                <label class="check schedule-metric-pick-label"><input type="checkbox" class="schedule-metric-pick" data-schedule-id="<?=h($metricScheduleId)?>" data-product-id="<?=h($metricProductId)?>" aria-label="選取 <?=h($metricTitle)?>"></label>
                <?php if($metricImage!==''): ?><img src="<?=h($metricImage)?>" alt="<?=h($metricTitle)?>"><?php else: ?><div class="schedule-metric-no-image">無圖片</div><?php endif; ?>
                <div><b><?=h($metricTitle)?></b><span><?=h($metricProductId!==''?$metricProductId:'-')?></span><small><?=h(schedule_publish_result_reason($metricRow)?:($metricRow['publish_status']??'未上架'))?></small></div>
                <div class="schedule-metric-time"><small>上架：<?=h($metricRow['actual_publish_at']??($metricRow['scheduled_publish_at']??($metricRow['publish_at']??'-')))?></small><small>結標：<?=h($metricRow['close_at']??'-')?></small></div>
                <div class="schedule-metric-actions">
                  <?php if($metricProductId!==''): ?><a class="button-like small" href="operations.php?edit_product=<?=urlencode($metricProductId)?>#products">編輯產品</a><?php endif; ?>
                  <?php if(!empty($metricRow['post_url'])): ?><a class="button-like small" href="<?=h(safe_http_url($metricRow['post_url']))?>" target="_blank" rel="noopener">開 Facebook 貼文</a><?php elseif($metricKey==='missing_url' && $metricListingSerial!==''): ?><a class="button-like small" href="https://www.facebook.com/groups/onecheep/search/?q=<?=rawurlencode('#'.$metricListingSerial)?>" target="_blank" rel="noopener">用排程碼查 Facebook</a><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if(!$metricGroup['rows']): ?><div class="today-listed-empty">目前沒有符合這個條件的產品。</div><?php endif; ?>
          </div></div>
        </div>
      </dialog>
    <?php endforeach; ?>
""",
    ),
    (
        """document.querySelectorAll('.open-schedule-metric-dialog').forEach((button) => {
  button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.dialog || '');
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  });
});
""",
        """document.querySelectorAll('.open-schedule-metric-dialog').forEach((button) => {
  button.addEventListener('click', () => {
    const dialog = document.getElementById(button.dataset.dialog || '');
    if (!dialog) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', 'open');
  });
});
(function bindScheduleMetricProductActions() {
  const scheduleForm = document.getElementById('scheduleMetricDeleteSchedulesForm');
  const productForm = document.getElementById('scheduleMetricDeleteProductsForm');
  function collectPicks(dialog) {
    return Array.from(dialog.querySelectorAll('.schedule-metric-pick:checked')).map((input) => ({
      scheduleId: String(input.dataset.scheduleId || ''),
      productId: String(input.dataset.productId || ''),
    })).filter((row) => row.scheduleId || row.productId);
  }
  function fillForm(form, fieldName, values) {
    if (!form) return;
    form.querySelectorAll('input[name="' + fieldName + '"]').forEach((el) => el.remove());
    Array.from(new Set(values.filter(Boolean))).forEach((value) => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = fieldName;
      input.value = value;
      form.appendChild(input);
    });
  }
  function setMetricKey(form, key) {
    const field = form?.querySelector('.schedule-metric-key-field');
    if (field) field.value = key || '';
  }
  document.querySelectorAll('.schedule-metric-select-all').forEach((box) => {
    box.addEventListener('change', () => {
      const dialog = box.closest('dialog');
      dialog?.querySelectorAll('.schedule-metric-pick').forEach((input) => { input.checked = box.checked; });
    });
  });
  document.querySelectorAll('.schedule-metric-edit-selected').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = button.closest('dialog');
      const picks = collectPicks(dialog || document);
      const productId = picks.find((row) => row.productId)?.productId || '';
      if (!productId) { alert('請先勾選要編輯的產品。'); return; }
      window.location.href = 'operations.php?edit_product=' + encodeURIComponent(productId) + '#products';
    });
  });
  document.querySelectorAll('.schedule-metric-delete-schedules').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = button.closest('dialog');
      const picks = collectPicks(dialog || document);
      const ids = picks.map((row) => row.scheduleId).filter(Boolean);
      if (!ids.length) { alert('請先勾選要刪除的排程。'); return; }
      setMetricKey(scheduleForm, dialog?.dataset.metricKey || '');
      fillForm(scheduleForm, 'schedule_ids[]', ids);
      if (scheduleForm?.requestSubmit) scheduleForm.requestSubmit();
      else scheduleForm?.submit();
    });
  });
  document.querySelectorAll('.schedule-metric-delete-products').forEach((button) => {
    button.addEventListener('click', () => {
      const dialog = button.closest('dialog');
      const picks = collectPicks(dialog || document);
      const ids = picks.map((row) => row.productId).filter(Boolean);
      if (!ids.length) { alert('請先勾選要刪除的產品。'); return; }
      setMetricKey(productForm, dialog?.dataset.metricKey || '');
      fillForm(productForm, 'product_ids[]', ids);
      if (productForm?.requestSubmit) productForm.requestSubmit();
      else productForm?.submit();
    });
  });
  const reopenKey = <?= json_encode($reopenScheduleMetricKey ?? '', JSON_UNESCAPED_UNICODE) ?>;
  if (reopenKey) {
    const reopenDialog = document.getElementById('scheduleMetricDialog-' + reopenKey);
    if (reopenDialog) {
      if (typeof reopenDialog.showModal === 'function') reopenDialog.showModal();
      else reopenDialog.setAttribute('open', 'open');
    }
  }
})();
""",
    ),
    (
        """    <div class="unsold-list">
      <?php foreach ($unsoldRowsPage as $unsoldRow):
""",
        """    <form id="unsoldDeleteSchedulesForm" method="post" onsubmit="return confirm('確定刪除勾選的流標排程？實體庫存數量不變。');">
      <input type="hidden" name="action" value="delete_schedules">
      <input type="hidden" name="ops_tab" value="unsold-management">
    </form>
    <form id="unsoldDeleteProductsForm" method="post" onsubmit="return confirm('確定刪除勾選產品？已有排程的產品會保護不刪。');">
      <input type="hidden" name="action" value="delete_products_bulk">
      <input type="hidden" name="ops_tab" value="unsold-management">
    </form>
    <div class="bulk-bar">
      <label class="check"><input type="checkbox" class="unsold-select-all"> 全選本頁產品</label>
      <button type="button" class="button-like" id="unsoldEditSelected">編輯勾選產品</button>
      <button type="button" class="danger-button" id="unsoldDeleteSchedules">刪除勾選排程</button>
      <button type="button" class="danger" id="unsoldDeleteProducts">刪除勾選產品</button>
    </div>
    <div class="unsold-list">
      <?php foreach ($unsoldRowsPage as $unsoldRow):
""",
    ),
    (
        """      <article class="unsold-card">
        <?php if ($unsoldImage !== ''): ?><img loading="lazy" class="unsold-card-thumb zoomable" src="<?=h($unsoldImage)?>" alt="<?=h($unsoldRow['product_title']??'')?>"><?php else: ?><div class="unsold-card-thumb stock-no-img">無圖</div><?php endif; ?>
        <div>
          <h3><?=h($unsoldRow['product_title']??'未命名商品')?></h3>
          <b><?=h($unsoldRow['product_id']??'')?><?=!empty($unsoldRow['product_barcode'])?' / '.h($unsoldRow['product_barcode']):''?></b>
""",
        """      <article class="unsold-card">
        <label class="check unsold-pick-label"><input type="checkbox" class="unsold-pick" data-schedule-id="<?=h($unsoldRow['id']??'')?>" data-product-id="<?=h($unsoldRow['product_id']??'')?>" aria-label="選取 <?=h($unsoldRow['product_title']??'')?>"></label>
        <?php if ($unsoldImage !== ''): ?><img loading="lazy" class="unsold-card-thumb zoomable" src="<?=h($unsoldImage)?>" alt="<?=h($unsoldRow['product_title']??'')?>"><?php else: ?><div class="unsold-card-thumb stock-no-img">無圖</div><?php endif; ?>
        <div>
          <h3><?=h($unsoldRow['product_title']??'未命名商品')?></h3>
          <b><?=h($unsoldRow['product_id']??'')?><?=!empty($unsoldRow['product_barcode'])?' / '.h($unsoldRow['product_barcode']):''?></b>
          <?php if (!empty($unsoldRow['product_id'])): ?><a class="button-like small" href="operations.php?edit_product=<?=urlencode((string)$unsoldRow['product_id'])?>#products">編輯產品</a><?php endif; ?>
""",
    ),
    (
        """    <?=ops_pager_bar($unsoldPage, $unsoldPages, $unsoldTotal, $unsoldPerPage, 'unsold_page', 'unsold-management', '項商品')?>
  </section>
""",
        """    <?=ops_pager_bar($unsoldPage, $unsoldPages, $unsoldTotal, $unsoldPerPage, 'unsold_page', 'unsold-management', '項商品')?>
    <script>
    (function bindUnsoldProductActions() {
      const scheduleForm = document.getElementById('unsoldDeleteSchedulesForm');
      const productForm = document.getElementById('unsoldDeleteProductsForm');
      function collectPicks() {
        return Array.from(document.querySelectorAll('.unsold-pick:checked')).map((input) => ({
          scheduleId: String(input.dataset.scheduleId || ''),
          productId: String(input.dataset.productId || ''),
        }));
      }
      function fillForm(form, fieldName, values) {
        if (!form) return;
        form.querySelectorAll('input[name="' + fieldName + '"]').forEach((el) => el.remove());
        Array.from(new Set(values.filter(Boolean))).forEach((value) => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = fieldName;
          input.value = value;
          form.appendChild(input);
        });
      }
      document.querySelector('.unsold-select-all')?.addEventListener('change', (event) => {
        document.querySelectorAll('.unsold-pick').forEach((input) => { input.checked = event.target.checked; });
      });
      document.getElementById('unsoldEditSelected')?.addEventListener('click', () => {
        const productId = collectPicks().find((row) => row.productId)?.productId || '';
        if (!productId) { alert('請先勾選要編輯的產品。'); return; }
        window.location.href = 'operations.php?edit_product=' + encodeURIComponent(productId) + '#products';
      });
      document.getElementById('unsoldDeleteSchedules')?.addEventListener('click', () => {
        const ids = collectPicks().map((row) => row.scheduleId).filter(Boolean);
        if (!ids.length) { alert('請先勾選要刪除的排程。'); return; }
        fillForm(scheduleForm, 'schedule_ids[]', ids);
        if (scheduleForm?.requestSubmit) scheduleForm.requestSubmit();
        else scheduleForm?.submit();
      });
      document.getElementById('unsoldDeleteProducts')?.addEventListener('click', () => {
        const ids = collectPicks().map((row) => row.productId).filter(Boolean);
        if (!ids.length) { alert('請先勾選要刪除的產品。'); return; }
        fillForm(productForm, 'product_ids[]', ids);
        if (productForm?.requestSubmit) productForm.requestSubmit();
        else productForm?.submit();
      });
    })();
    </script>
  </section>
""",
    ),
    (
        """.unsold-card{display:grid;grid-template-columns:86px minmax(230px,1.25fr) minmax(170px,.65fr) minmax(180px,.7fr);gap:14px;padding:14px;border:1px solid #e2c678;border-left:6px solid #d97706;border-radius:8px;background:#fffdf5}.unsold-card-thumb{""",
        """.unsold-card{display:grid;grid-template-columns:24px 86px minmax(230px,1.25fr) minmax(170px,.65fr) minmax(180px,.7fr);gap:14px;padding:14px;border:1px solid #e2c678;border-left:6px solid #d97706;border-radius:8px;background:#fffdf5}.unsold-pick-label{display:flex;align-items:flex-start;margin:0}.unsold-card-thumb{""",
    ),
]


def apply(text: str) -> str:
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:180].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:180].replace("\n", " / "))
            continue
        text = text.replace(old, new, 1)
    if missing:
        raise SystemExit("patch targets missing or not unique:\n- " + "\n- ".join(missing))
    return text


def main() -> None:
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("path")
    args = parser.parse_args()
    path = Path(args.path)
    original = path.read_text(encoding="utf-8", errors="surrogateescape")
    updated = apply(original)
    path.write_text(updated, encoding="utf-8", errors="surrogateescape")
    print(f"patched {path} bytes {len(original.encode('utf-8'))} -> {len(updated.encode('utf-8'))}")


if __name__ == "__main__":
    main()
