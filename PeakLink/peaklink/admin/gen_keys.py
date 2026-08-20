"""產生 Ed25519 授權金鑰。正式環境請自行保管 private.pem，不要提交到公開倉庫。"""

from __future__ import annotations

import argparse
from pathlib import Path

from peaklink.license import generate_keypair


def main() -> None:
    parser = argparse.ArgumentParser(description="產生峰連遠端授權金鑰")
    parser.add_argument("--out", default="", help="輸出目錄，預設 peaklink/keys")
    args = parser.parse_args()
    out = Path(args.out) if args.out else Path(__file__).resolve().parent.parent / "keys"
    out.mkdir(parents=True, exist_ok=True)
    private_pem, public_pem = generate_keypair()
    (out / "private.pem").write_bytes(private_pem)
    (out / "public.pem").write_bytes(public_pem)
    print(f"已寫入 {out / 'private.pem'} 與 {out / 'public.pem'}")
    print("請把 public.pem 放進軟體內建金鑰，private.pem 只放在發行授權的電腦。")


if __name__ == "__main__":
    main()
