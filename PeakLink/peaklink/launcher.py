"""啟動器：一打開就顯示隨機 ID／密碼。"""

from __future__ import annotations

from peaklink.boot import run_app
from peaklink.dashboard import build_dashboard


def main() -> None:
    run_app(build_dashboard)


if __name__ == "__main__":
    main()
