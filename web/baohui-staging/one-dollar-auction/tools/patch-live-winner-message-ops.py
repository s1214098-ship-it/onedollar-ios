#!/usr/bin/env python3
"""Drop internal inventory wording from Facebook 得標私訊.

Customers should not see「出貨時庫存扣 N 件」. Keep 本標內容：共 N 件.
"""

from __future__ import annotations

from pathlib import Path


PATCHES = [
    (
        "    '本標內容：共 ' + qty + ' 件（出貨時庫存扣 ' + qty + ' 件）',\n",
        "    '本標內容：共 ' + qty + ' 件',\n",
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
