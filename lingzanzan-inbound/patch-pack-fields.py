#!/usr/bin/env python3
"""Turn the huge package-pool boxes into one-cell-per-tracking fields."""
from pathlib import Path

SRC = Path("/tmp/lz-pack-fields")
OUT = Path("/workspace/lingzanzan-inbound")
OUT.mkdir(parents=True, exist_ok=True)
MARKER = "LZ_PACK_FIELDS_20260927"
TAG = "20260927-pack-fields-1"

html = (SRC / "admin-freight.html").read_text(encoding="utf-8")
css = (SRC / "admin.css").read_text(encoding="utf-8")
js = (SRC / "admin.js").read_text(encoding="utf-8")


def once(src, old, new, label):
    if old not in src:
        raise SystemExit(f"failed to bind {label}")
    if src.count(old) != 1:
        raise SystemExit(f"ambiguous {label} count={src.count(old)}")
    return src.replace(old, new, 1)


old_block = """                  <p>先完成下方商品、顏色、尺寸與成本，再貼上物流單號。每行可填「物流單號｜數量」；只填單號時，系統會依採購總件數平均分配。建立後仍是未到貨包裹池，不會增加庫存。</p>
                  <label>本次同賣家採購總件數<input type="number" min="1" step="1" data-freight-package-pool-total placeholder="例如 50"></label>
                  <label class="freight-wide">物流單號清單<textarea rows="7" data-freight-package-pool-lines placeholder="每行一筆，例如：&#10;YT123456789｜3&#10;YT987654321｜2&#10;也可以只貼 20 個物流單號，由系統平均分配 50 件"></textarea></label>
                  <div class="freight-package-pool-preview freight-wide" data-freight-package-pool-preview>尚未預覽包裹分配。</div>"""

new_block = """                  <p>先完成下方商品、顏色、尺寸與成本，再一格一格填物流單號。每一格一個單號，可填數量；只填單號時，系統會依採購總件數平均分配。建立後仍是未到貨包裹池，不會增加庫存。</p>
                  <label class="freight-pack-cell">本次同賣家採購總件數<input type="number" min="1" step="1" data-freight-package-pool-total placeholder="例如 50"></label>
                  <div class="freight-package-pool-toolbar freight-wide">
                    <small>物流單號欄位：一格一格填，可貼多行自動拆成多格。</small>
                    <button type="button" class="ghost-button" data-freight-package-pool-add-cell>＋新增一格</button>
                  </div>
                  <div class="freight-package-pool-grid freight-wide" data-freight-package-pool-grid></div>
                  <textarea hidden data-freight-package-pool-lines></textarea>
                  <div class="freight-package-pool-preview freight-wide" data-freight-package-pool-preview>尚未預覽包裹分配。</div>"""
html = once(html, old_block, new_block, "freight html pool fields")
html = html.replace("?v=20260927-recv-row-1", f"?v={TAG}")
html = html.replace("?v=20260927-cat-pick-1", f"?v={TAG}")
if TAG not in html:
    raise SystemExit("html bust missing")

css += f"""

/* {MARKER} 包裹池改成一格一格欄位，不再拉成兩塊空板。 */
.freight-package-pool-body {{
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)) !important;
  align-items: start !important;
  gap: 10px !important;
}}
.freight-package-pool-body > p,
.freight-package-pool-body .freight-package-pool-toolbar,
.freight-package-pool-body .freight-package-pool-grid,
.freight-package-pool-body .freight-package-pool-preview,
.freight-package-pool-body .freight-actions,
.freight-package-pool-body textarea[hidden] {{
  grid-column: 1 / -1 !important;
}}
.freight-package-pool-body > p {{
  margin: 0 !important;
}}
.freight-pack-cell,
.freight-package-pool-body > label {{
  display: grid !important;
  gap: 6px !important;
  min-height: 0 !important;
  height: auto !important;
  align-content: start !important;
  padding: 10px 12px !important;
  border-radius: 12px !important;
  background: rgba(5,14,17,.55) !important;
  border: 1px solid rgba(143,233,217,.22) !important;
}}
.freight-pack-cell input,
.freight-package-pool-body > label input {{
  width: 100% !important;
  min-height: 42px !important;
  height: 42px !important;
  max-height: 42px !important;
}}
.freight-package-pool-toolbar {{
  display: flex !important;
  flex-wrap: wrap !important;
  align-items: center !important;
  justify-content: space-between !important;
  gap: 8px !important;
}}
.freight-package-pool-toolbar small {{
  color: #c7bdc7 !important;
}}
.freight-package-pool-grid {{
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important;
  gap: 8px !important;
}}
.freight-pack-slot {{
  display: grid !important;
  grid-template-columns: minmax(0, 1fr) 72px 36px !important;
  gap: 6px !important;
  align-items: center !important;
  padding: 8px 10px !important;
  border-radius: 12px !important;
  background: rgba(5,14,17,.55) !important;
  border: 1px solid rgba(143,233,217,.22) !important;
}}
.freight-pack-slot input {{
  min-height: 38px !important;
  height: 38px !important;
  width: 100% !important;
}}
.freight-pack-slot [data-freight-pack-remove] {{
  min-height: 36px !important;
  border: 0 !important;
  background: transparent !important;
  color: #ffb4bc !important;
  font-size: 18px !important;
  cursor: pointer !important;
}}
"""

old_rows = """  function freightPackagePoolRows() {
    var text = String(document.querySelector('[data-freight-package-pool-lines]')?.value || '');
    var total = Math.max(0, Math.floor(Number(document.querySelector('[data-freight-package-pool-total]')?.value || 0)));"""

new_rows = """  function freightPackagePoolSyncFromGrid() {
    var grid = document.querySelector('[data-freight-package-pool-grid]');
    var area = document.querySelector('[data-freight-package-pool-lines]');
    if (!grid || !area) return;
    var lines = [];
    grid.querySelectorAll('[data-freight-pack-slot]').forEach(function (slot) {
      var no = String((slot.querySelector('[data-freight-pack-no]') || {}).value || '').trim();
      var qty = String((slot.querySelector('[data-freight-pack-qty]') || {}).value || '').trim();
      if (!no) return;
      lines.push(qty ? (no + '｜' + qty) : no);
    });
    area.value = lines.join('\\n');
  }
  function freightPackagePoolAddSlot(trackingNo, quantity) {
    var grid = document.querySelector('[data-freight-package-pool-grid]');
    if (!grid) return null;
    var slot = document.createElement('div');
    slot.className = 'freight-pack-slot';
    slot.setAttribute('data-freight-pack-slot', '');
    slot.innerHTML = '<input data-freight-pack-no placeholder="物流單號" autocomplete="off"><input data-freight-pack-qty type="number" min="1" step="1" placeholder="件"><button type="button" data-freight-pack-remove aria-label="刪這格">×</button>';
    var no = slot.querySelector('[data-freight-pack-no]');
    var qty = slot.querySelector('[data-freight-pack-qty]');
    if (trackingNo) no.value = trackingNo;
    if (quantity) qty.value = String(quantity);
    grid.appendChild(slot);
    return slot;
  }
  function freightPackagePoolEnsureGrid(minEmpty) {
    var grid = document.querySelector('[data-freight-package-pool-grid]');
    if (!grid) return;
    minEmpty = minEmpty == null ? 1 : minEmpty;
    var slots = grid.querySelectorAll('[data-freight-pack-slot]');
    if (!slots.length) {
      for (var i = 0; i < 6; i += 1) freightPackagePoolAddSlot('', '');
      return;
    }
    var last = slots[slots.length - 1];
    var lastNo = String((last.querySelector('[data-freight-pack-no]') || {}).value || '').trim();
    if (lastNo) freightPackagePoolAddSlot('', '');
  }
  function installFreightPackagePoolGrid() {
    var grid = document.querySelector('[data-freight-package-pool-grid]');
    if (!grid || grid.getAttribute('data-pack-fields-bound') === '1') return;
    grid.setAttribute('data-pack-fields-bound', '1');
    freightPackagePoolEnsureGrid(6);
    grid.addEventListener('input', function (event) {
      var noInput = event.target && event.target.closest && event.target.closest('[data-freight-pack-no]');
      if (noInput && event.inputType === 'insertFromPaste') {
        var pasted = String(noInput.value || '');
        if (/[\\r\\n]/.test(pasted) || pasted.split(/[｜|,\\t ]+/).length > 2) {
          var parts = pasted.split(/\\r?\\n/).map(function (line) { return String(line || '').trim(); }).filter(Boolean);
          noInput.value = '';
          parts.forEach(function (line) {
            var bits = line.split(/[｜|,\\t]/).map(function (part) { return String(part || '').trim(); });
            var empty = Array.prototype.find.call(grid.querySelectorAll('[data-freight-pack-no]'), function (input) { return !String(input.value || '').trim(); });
            if (empty) {
              empty.value = bits[0] || '';
              var qty = empty.parentElement.querySelector('[data-freight-pack-qty]');
              if (qty && bits[1]) qty.value = bits[1];
            } else freightPackagePoolAddSlot(bits[0] || '', bits[1] || '');
          });
        }
      }
      freightPackagePoolEnsureGrid(1);
      freightPackagePoolSyncFromGrid();
      renderFreightPackagePoolPreview();
    });
    grid.addEventListener('click', function (event) {
      var remove = event.target && event.target.closest && event.target.closest('[data-freight-pack-remove]');
      if (!remove) return;
      var slot = remove.closest('[data-freight-pack-slot]');
      if (slot && grid.querySelectorAll('[data-freight-pack-slot]').length > 1) slot.remove();
      else if (slot) {
        slot.querySelectorAll('input').forEach(function (input) { input.value = ''; });
      }
      freightPackagePoolEnsureGrid(1);
      freightPackagePoolSyncFromGrid();
      renderFreightPackagePoolPreview();
    });
    var addBtn = document.querySelector('[data-freight-package-pool-add-cell]');
    if (addBtn && !addBtn._packFieldsBound) {
      addBtn._packFieldsBound = true;
      addBtn.addEventListener('click', function () {
        freightPackagePoolAddSlot('', '');
      });
    }
  }
  function freightPackagePoolRows() {
    freightPackagePoolSyncFromGrid();
    var text = String(document.querySelector('[data-freight-package-pool-lines]')?.value || '');
    var total = Math.max(0, Math.floor(Number(document.querySelector('[data-freight-package-pool-total]')?.value || 0)));"""
js = once(js, old_rows, new_rows, "js grid helpers")

js = once(
    js,
    "    bindIf('[data-freight-package-pool-preview-button]', 'click', renderFreightPackagePoolPreview);\n    bindIf('[data-freight-package-pool-create]', 'click', createFreightPackagePool);\n    bindIf('[data-freight-package-pool-lines]', 'input', renderFreightPackagePoolPreview);\n    bindIf('[data-freight-package-pool-total]', 'input', renderFreightPackagePoolPreview);",
    "    installFreightPackagePoolGrid();\n    bindIf('[data-freight-package-pool-preview-button]', 'click', renderFreightPackagePoolPreview);\n    bindIf('[data-freight-package-pool-create]', 'click', createFreightPackagePool);\n    bindIf('[data-freight-package-pool-lines]', 'input', renderFreightPackagePoolPreview);\n    bindIf('[data-freight-package-pool-total]', 'input', renderFreightPackagePoolPreview);",
    "js install grid",
)

js = js.replace("請先貼上物流單號清單", "請先填物流單號（一格一個）")

if MARKER not in js:
    # marker lives in css; stamp js too
    js = js.replace(
        "function freightPackagePoolSyncFromGrid() {",
        "function freightPackagePoolSyncFromGrid() { /* LZ_PACK_FIELDS_20260927 */",
        1,
    )

(OUT / "admin-freight.html.new-pack-fields-1").write_text(html, encoding="utf-8")
(OUT / "admin.css.new-pack-fields-1").write_text(css, encoding="utf-8")
(OUT / "admin.js.new-pack-fields-1").write_text(js, encoding="utf-8")
print("html", len(html), "grid" in html, TAG in html)
print("css", MARKER in css)
print("js", "installFreightPackagePoolGrid" in js, "LZ_PACK_FIELDS_20260927" in js)
