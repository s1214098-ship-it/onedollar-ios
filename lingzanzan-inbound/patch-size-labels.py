#!/usr/bin/env python3
"""Put size text back on each same-color size-matrix cell."""
from pathlib import Path

SRC = Path("/tmp/lz-size-labels")
OUT = Path("/workspace/lingzanzan-inbound")
OUT.mkdir(parents=True, exist_ok=True)
MARKER = "LZ_SIZE_LABELS_20260927"
TAG = "20260927-size-labels-1"

html = (SRC / "admin-freight.html").read_text(encoding="utf-8")
inv = (SRC / "admin-inventory.html").read_text(encoding="utf-8")
css = (SRC / "admin.css").read_text(encoding="utf-8")
js = (SRC / "admin.js").read_text(encoding="utf-8")


def once(src, old, new, label):
    if old not in src:
        raise SystemExit(f"failed to bind {label}")
    if src.count(old) != 1:
        raise SystemExit(f"ambiguous {label} count={src.count(old)}")
    return src.replace(old, new, 1)


old_cell = (
    "return '<label class=\"freight-size-matrix-cell\"><b>' + escapeHtml(size) + '</b><span>"
    "<button type=\"button\" aria-label=\"減一件 ' + escapeHtml(size) + '\" data-freight-size-matrix-minus>－</button>"
    "<input type=\"number\" min=\"0\" max=\"999\" step=\"1\" inputmode=\"numeric\" value=\"' + freightParseQty(quantity, 0) + '\" data-size=\"' + escapeHtml(size) + '\" data-freight-size-matrix-qty>"
    "<button type=\"button\" class=\"freight-qty-add-one\" aria-label=\"加一件 ' + escapeHtml(size) + '\" data-freight-size-matrix-plus>＋</button></span></label>';"
)
new_cell = (
    "return '<label class=\"freight-size-matrix-cell\" data-size-label=\"' + escapeHtml(size) + '\">"
    "<em class=\"freight-size-matrix-name\">' + escapeHtml(size) + '</em>"
    "<span class=\"freight-size-matrix-stepper\">"
    "<button type=\"button\" aria-label=\"減一件 ' + escapeHtml(size) + '\" data-freight-size-matrix-minus>－</button>"
    "<input type=\"number\" min=\"0\" max=\"999\" step=\"1\" inputmode=\"numeric\" value=\"' + freightParseQty(quantity, 0) + '\" data-size=\"' + escapeHtml(size) + '\" data-freight-size-matrix-qty>"
    "<button type=\"button\" class=\"freight-qty-add-one\" aria-label=\"加一件 ' + escapeHtml(size) + '\" data-freight-size-matrix-plus>＋</button>"
    "</span></label>';"
)
js = once(js, old_cell, new_cell, "size matrix cell markup")
if MARKER not in js:
    js += f"\n/* {MARKER} size labels restored on each matrix cell */\n"

html = html.replace("?v=20260927-pack-fields-1", f"?v={TAG}")
inv = inv.replace("?v=20260927-pack-fields-1", f"?v={TAG}")
if TAG not in html:
    raise SystemExit("freight html bust missing")
if TAG not in inv:
    raise SystemExit("inventory html bust missing")

css += f"""

/* {MARKER} 同色多尺寸每一格都要看得見 XS/S/M/L，不能被淺底奶油字吃掉。 */
.freight-size-matrix-grid {{
  display: grid !important;
  grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)) !important;
  gap: 8px !important;
  align-items: stretch !important;
}}
.freight-size-matrix-cell {{
  display: grid !important;
  grid-template-columns: 1fr !important;
  grid-template-rows: auto auto !important;
  grid-auto-flow: row !important;
  align-items: stretch !important;
  justify-items: stretch !important;
  gap: 4px !important;
  min-width: 0 !important;
  min-height: 0 !important;
  height: auto !important;
  overflow: visible !important;
  padding: 8px 6px 9px !important;
  border: 1px solid #d5e4e0 !important;
  border-radius: 14px !important;
  background: #f7fbfa !important;
  color: #10231f !important;
  -webkit-text-fill-color: #10231f !important;
  box-shadow: none !important;
}}
.freight-size-matrix-cell > b,
.freight-size-matrix-cell > em,
.freight-size-matrix-cell .freight-size-matrix-name {{
  display: block !important;
  grid-row: 1 !important;
  position: static !important;
  width: auto !important;
  min-width: 0 !important;
  max-width: none !important;
  min-height: 18px !important;
  height: auto !important;
  margin: 0 !important;
  padding: 0 !important;
  overflow: visible !important;
  opacity: 1 !important;
  visibility: visible !important;
  font-size: 13px !important;
  font-weight: 950 !important;
  font-style: normal !important;
  line-height: 1.2 !important;
  letter-spacing: .04em !important;
  text-align: center !important;
  color: #0f3d36 !important;
  -webkit-text-fill-color: #0f3d36 !important;
  background: transparent !important;
}}
.freight-size-matrix-cell > span,
.freight-size-matrix-cell .freight-size-matrix-stepper {{
  display: grid !important;
  grid-template-columns: 28px minmax(28px, 1fr) 28px !important;
  grid-row: 2 !important;
  align-items: center !important;
  gap: 4px !important;
  min-width: 0 !important;
}}
.freight-size-matrix-cell button {{
  display: grid !important;
  place-items: center !important;
  min-width: 28px !important;
  width: 28px !important;
  min-height: 34px !important;
  padding: 0 !important;
  border-radius: 8px !important;
  font-size: 16px !important;
  line-height: 1 !important;
}}
.freight-size-matrix-cell input,
.freight-size-matrix-cell input[data-freight-size-matrix-qty] {{
  width: 100% !important;
  min-width: 0 !important;
  min-height: 34px !important;
  height: 34px !important;
  padding: 2px 0 !important;
  text-align: center !important;
  font-size: 15px !important;
  font-weight: 950 !important;
  color: #10231f !important;
  -webkit-text-fill-color: #10231f !important;
  background: #ffffff !important;
}}
@media (max-width: 560px) {{
  .freight-size-matrix-grid {{
    grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
  }}
}}
"""

(OUT / "admin.js.new-size-labels-1").write_text(js, encoding="utf-8")
(OUT / "admin.css.new-size-labels-1").write_text(css, encoding="utf-8")
(OUT / "admin-freight.html.new-size-labels-1").write_text(html, encoding="utf-8")
(OUT / "admin-inventory.html.new-size-labels-1").write_text(inv, encoding="utf-8")
print("js", js.count("freight-size-matrix-name"), "css", MARKER in css, "bust", TAG in html and TAG in inv)
print("ok")
