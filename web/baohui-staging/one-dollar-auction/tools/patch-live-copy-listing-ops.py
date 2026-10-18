#!/usr/bin/env python3
"""Make 複製上架文案 / 複製問答包 actually copy in HQ iframe cards.

The live handler bound querySelectorAll once, then awaited clipboard.writeText.
In the HQ embed that promise rejects after the click gesture is spent, so
execCommand('copy') also fails and the button never changes — it looks dead.
Use document click delegation, copy with a sync textarea+execCommand first,
then clipboard, and always show 已複製 / 複製失敗 / 沒有文案.
"""

from __future__ import annotations

from pathlib import Path


NEW_COPY_HANDLER = """(function bindOpsCopyListingButtons() {
  if (window.__baohuiCopyListingBound20260923) return;
  window.__baohuiCopyListingBound20260923 = true;
  function opsCopyTextSync(text) {
    const value = String(text || '');
    const holder = document.createElement('textarea');
    holder.value = value;
    holder.setAttribute('readonly', '');
    holder.setAttribute('aria-hidden', 'true');
    holder.style.cssText = 'position:fixed;top:0;left:0;width:2px;height:2px;padding:0;border:0;opacity:0.01;z-index:2147483647;';
    document.body.appendChild(holder);
    try { holder.focus(); } catch (e) {}
    holder.select();
    try { holder.setSelectionRange(0, holder.value.length); } catch (e) {}
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    holder.remove();
    return !!ok;
  }
  function markCopyButton(btn, ok, empty) {
    const original = btn.getAttribute('data-copy-label') || (btn.textContent || '').trim();
    btn.setAttribute('data-copy-label', original);
    btn.textContent = empty ? '沒有文案' : (ok ? '已複製' : '複製失敗');
    clearTimeout(btn._copyResetTimer);
    btn._copyResetTimer = setTimeout(() => { btn.textContent = original; }, 1400);
  }
  document.addEventListener('click', (event) => {
    const listingBtn = event.target.closest?.('.copy-facebook-listing');
    const qaBtn = event.target.closest?.('.copy-schedule-qa');
    if (!listingBtn && !qaBtn) return;
    event.preventDefault();
    const btn = listingBtn || qaBtn;
    const root = btn.closest('.schedule-work-card, .schedule-card-actions, form, article') || document;
    const area = root.querySelector(listingBtn ? '.schedule-listing-draft' : '.schedule-qa-draft');
    const text = (area && typeof area.value === 'string') ? area.value : '';
    if (!String(text).trim()) {
      markCopyButton(btn, false, true);
      return;
    }
    if (opsCopyTextSync(text)) {
      markCopyButton(btn, true, false);
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(() => {});
      }
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => markCopyButton(btn, true, false)).catch(() => markCopyButton(btn, false, false));
      return;
    }
    if (area) {
      try { area.focus(); area.select(); } catch (e) {}
    }
    markCopyButton(btn, false, false);
  });
})();
"""

PATCHES = [
    (
        """document.querySelectorAll('.copy-facebook-listing').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.closest('form')?.querySelector('.schedule-listing-draft')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製上架文案', 1200); }
    catch (e) { btn.closest('form')?.querySelector('.schedule-listing-draft')?.select(); document.execCommand('copy'); }
  });
});
""",
        NEW_COPY_HANDLER,
    ),
    (
        """document.querySelectorAll('.copy-schedule-qa').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.closest('form')?.querySelector('.schedule-qa-draft')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製問答包', 1200); }
    catch (e) { btn.closest('form')?.querySelector('.schedule-qa-draft')?.select(); document.execCommand('copy'); }
  });
});
""",
        "/* copy-schedule-qa is handled by bindOpsCopyListingButtons */\n",
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
