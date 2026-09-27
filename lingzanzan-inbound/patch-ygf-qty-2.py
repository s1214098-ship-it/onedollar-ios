#!/usr/bin/env python3
"""Make 確認產品 save the same way as 完成這一件."""
from pathlib import Path

SRC = Path("/workspace/lingzanzan-inbound")
OUT = SRC
MARKER = "LZ_YGF_QTY_SAVE2_20260927"
TAG = "20260927-ygf-qty-2"

js = (SRC / "scanner-ygf-v59.js.new-ygf-qty-1").read_text(encoding="utf-8")
css = (SRC / "scanner-ygf-v59.css.new-ygf-qty-1").read_text(encoding="utf-8")
html = (SRC / "scanner.html.new-ygf-qty-1").read_text(encoding="utf-8")


def once(src, old, new, label):
    if old not in src:
        raise SystemExit(f"failed to bind {label}")
    if src.count(old) != 1:
        raise SystemExit(f"ambiguous {label} count={src.count(old)}")
    return src.replace(old, new, 1)


old_btns = (
    "' : '<button type=\"button\" data-fast-change-location>' + (waitingForSetup ? '確認產品' : (entry.shelf && entry.layer ? '更正本筆位置' : '選倉位／層架')) + '</button>' + editTools + '<button type=\"button\" data-fast-split-location>同產品另放一處（新增）</button><button type=\"button\" class=\"primary\" data-fast-confirm-stocktake>完成這一件</button>"
)
new_btns = (
    "' : '<button type=\"button\" class=\"primary\" data-fast-confirm-stocktake data-fast-alias-confirm>確認產品</button>"
    "<button type=\"button\" data-fast-change-location>' + (entry.shelf && entry.layer ? '更正本筆位置' : '選倉位／層架') + '</button>' + editTools + "
    "'<button type=\"button\" data-fast-split-location>同產品另放一處（新增）</button>"
    "<button type=\"button\" class=\"primary\" data-fast-confirm-stocktake>完成這一件</button>"
)
js = once(js, old_btns, new_btns, "confirm alias buttons")

save_fn = """
  function saveCurrentStocktakeItem() {
    if (!selected) { setMessage('請先掃描並選擇商品。', 'error'); return; }
    if (selected.colorConfirmationRequired && !selected.colorConfirmed) {
      beep(2);
      setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。', 'error');
      return;
    }
    setCurrentStocktakeQty(Math.max(0, currentStocktakeQty()));
    submit('stocktake');
  }
"""
js = once(
    js,
    "  function openStocktakeVariantEditor() {",
    save_fn + "  function openStocktakeVariantEditor() {",
    "save helper",
)

js = once(
    js,
    """    if (target.matches('[data-fast-confirm-stocktake]')) {
      if (selected && selected.colorConfirmationRequired && !selected.colorConfirmed) { beep(2); setMessage('兩聲：這筆舊顏色尚未由行政重新選取，不能帶入庫存。請先按「更換顏色／尺寸」。','error'); return; }
      setCurrentStocktakeQty(Math.max(0, currentStocktakeQty()));
      submit('stocktake');
    }""",
    """    if (target.matches('[data-fast-confirm-stocktake],[data-fast-alias-confirm]')) {
      saveCurrentStocktakeItem();
      return;
    }""",
    "confirm click",
)

js = once(
    js,
    """    if (target.matches('[data-fast-change-location],[data-fast-split-location]')) {
      var currentSegmentQty = Number((document.querySelector('[data-fast-stocktake-qty]') || document.querySelector('[data-qty]') || {}).value || 0);""",
    """    if (target.matches('[data-fast-change-location],[data-fast-split-location]')) {
      if (target.matches('[data-fast-change-location]') && /確認產品/.test(String(target.textContent || ''))) {
        saveCurrentStocktakeItem();
        return;
      }
      var currentSegmentQty = Number((document.querySelector('[data-fast-stocktake-qty]') || document.querySelector('[data-qty]') || {}).value || 0);""",
    "legacy 確認產品 on location",
)

# Don't let a re-render immediately hide an opened location editor.
js = once(
    js,
    "    if (hasCurrent) hideMatchPickerNow();",
    """    var locOpen = document.querySelector('[data-match-first-location]:not([hidden])');
    if (hasCurrent && !(stocktakeChangingLocation && locOpen)) hideMatchPickerNow();""",
    "keep location editor",
)

js = once(
    js,
    ": '第一刷已計 1 件。請核對縮圖、編號、顏色與尺寸；正確請按「確認產品」，再選擇層架。第二刷才會變 2。');",
    ": '第一刷已計 1 件。請核對縮圖、編號、顏色與尺寸；正確請按「確認產品」或「完成這一件」儲存，倉位可之後再補。第二刷才會變 2。');",
    "status copy",
)

if MARKER not in js:
    js += f"\n/* {MARKER} 確認產品 = 完成這一件 */\n"

html = html.replace("?v=20260927-ygf-qty-1", f"?v={TAG}")
html = html.replace("版本 v162", "版本 v163")
if TAG not in html or "版本 v163" not in html:
    raise SystemExit("html bust/version missing")

css += f"""

/* {MARKER} 確認產品跟完成這一件同一件事：把目前數量存進本次盤點。 */
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-alias-confirm],
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-confirm-stocktake] {{
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  width: 100% !important;
  min-height: 52px !important;
  margin: 0 !important;
  background: #1f4fb2 !important;
  color: #fff !important;
  -webkit-text-fill-color: #fff !important;
  font-size: 18px !important;
  font-weight: 950 !important;
  z-index: 6 !important;
  pointer-events: auto !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-alias-confirm] {{
  grid-column: 1 / -1 !important;
  grid-row: 5 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-change-location] {{
  grid-column: 1 / -1 !important;
  grid-row: 6 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > .stocktake-fast-edit-tools {{
  grid-row: 7 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-print-stocktake-label] {{
  grid-row: 8 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-split-location] {{
  grid-row: 9 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-fast-confirm-stocktake]:not([data-fast-alias-confirm]) {{
  grid-row: 10 !important;
}}
html body.scanner-pda-layout .stocktake-fast-row.is-current > [data-cancel-current-stocktake] {{
  grid-row: 11 !important;
}}
"""

(OUT / "scanner-ygf-v59.js.new-ygf-qty-2").write_text(js, encoding="utf-8")
(OUT / "scanner-ygf-v59.css.new-ygf-qty-2").write_text(css, encoding="utf-8")
(OUT / "scanner.html.new-ygf-qty-2").write_text(html, encoding="utf-8")
print("alias", js.count("data-fast-alias-confirm"), "saveFn", "saveCurrentStocktakeItem" in js, "v163", "v163" in html)
print("ok")
