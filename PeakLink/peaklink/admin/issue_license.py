"""依付款金額／方案發行會員授權檔。"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
from pathlib import Path

from peaklink.constants import PRICE_PACKAGES
from peaklink.license import (
    dumps_license,
    load_private_key,
    member_payload,
    sign_payload,
)


def main() -> None:
    parser = argparse.ArgumentParser(description="發行峰連遠端會員授權")
    parser.add_argument("--name", required=True, help="客戶名稱")
    parser.add_argument("--amount", type=float, default=None, help="付款金額")
    parser.add_argument("--currency", default="TWD")
    parser.add_argument("--package", choices=sorted(PRICE_PACKAGES), help="month / quarter / year")
    parser.add_argument("--days", type=int, default=None, help="自訂天數（不走方案時）")
    parser.add_argument("--note", default="")
    parser.add_argument("--key", default="", help="private.pem 路徑")
    parser.add_argument("--out", default="")
    args = parser.parse_args()

    if not args.package and args.amount is None:
        raise SystemExit("請提供 --package 或 --amount")

    key_path = Path(args.key) if args.key else Path(__file__).resolve().parent.parent / "keys" / "private.pem"
    if not key_path.exists():
        raise SystemExit(f"找不到私鑰：{key_path}（請先 peaklink-gen-keys）")
    private = load_private_key(key_path.read_bytes())
    payload = member_payload(
        customer_name=args.name,
        amount=args.amount if args.amount is not None else 0,
        currency=args.currency,
        package=args.package,
        days=args.days,
        note=args.note,
        now=datetime.now(timezone.utc),
    )
    document = sign_payload(payload, private)
    out = Path(args.out) if args.out else Path(f"{payload.license_id}.peaklic")
    out.write_text(dumps_license(document), encoding="utf-8")
    print(f"已發行：{out}")
    print(payload.display_status())
    print(payload.note)


if __name__ == "__main__":
    main()
