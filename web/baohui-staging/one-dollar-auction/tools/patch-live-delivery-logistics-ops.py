#!/usr/bin/env python3
"""Inject logistics-only overlay JS into live operations.php.

Does not rewrite sales_out. Copy ops-delivery-logistics.php / -lib.php /
ops-delivery-logistics.js next to live operations.php, then run this patcher
so the edit form gets「只更新物流（不改庫存）」.
"""

from __future__ import annotations

from pathlib import Path

SCRIPT_TAG = (
    '<script src="ops-delivery-logistics.js?v=logistics-safe-20261027" '
    'charset="UTF-8"></script>\n'
)

PATCHES = [
    (
        '<script src="ops-search-panels.js?v=20260819-supplier-source-1" charset="UTF-8"></script>\n'
        '<script src="assets/app.js"></script>',
        '<script src="ops-search-panels.js?v=20260819-supplier-source-1" charset="UTF-8"></script>\n'
        + SCRIPT_TAG
        + '<script src="assets/app.js"></script>',
    ),
    (
        '<script src="ops-billing-documents.js?v=billing-docs-20260922b" charset="UTF-8"></script>\n'
        '<script src="../baohui-paste-image.js?v=20260819-paste-1"></script>',
        '<script src="ops-billing-documents.js?v=billing-docs-20260922b" charset="UTF-8"></script>\n'
        + SCRIPT_TAG
        + '<script src="../baohui-paste-image.js?v=20260819-paste-1"></script>',
    ),
]


def apply(text: str) -> str:
    if "ops-delivery-logistics.js" in text:
        return text
    missing = []
    for old, new in PATCHES:
        if old not in text:
            missing.append(old[:180].replace("\n", " / "))
            continue
        if text.count(old) != 1:
            missing.append(f"count={text.count(old)} :: " + old[:180].replace("\n", " / "))
            continue
        return text.replace(old, new, 1)
    raise SystemExit("patch targets missing or not unique:\n- " + "\n- ".join(missing))


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("path")
    args = parser.parse_args()
    path = Path(args.path)
    original = path.read_text(encoding="utf-8", errors="surrogateescape")
    updated = apply(original)
    if updated == original:
        print(f"already patched {path}")
        return
    path.write_text(updated, encoding="utf-8", errors="surrogateescape")
    print(f"patched {path} bytes {len(original.encode('utf-8'))} -> {len(updated.encode('utf-8'))}")


if __name__ == "__main__":
    main()
