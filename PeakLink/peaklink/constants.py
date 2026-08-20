"""產品常數：版本、埠號、免費限制、預設中繼。"""

from __future__ import annotations

APP_NAME = "峰連遠端"
APP_NAME_EN = "PeakLink"
VERSION = "1.0.0"

# 被控端在 Taliscale / 區網直連時開啟的本機埠
DIRECT_PORT = 18787

# 中繼服務預設 HTTP/WS 埠（正式環境請前面加 TLS 443 反代）
DEFAULT_RELAY_PORT = 8787

# 免費版：每一次遠端連線最長 5 分鐘
FREE_SESSION_SECONDS = 5 * 60

# 畫面
DEFAULT_FPS = 8
DEFAULT_JPEG_QUALITY = 55
DEFAULT_MAX_WIDTH = 1280

# 連入確認等待（免費版強制詢問）
CONSENT_TIMEOUT_SECONDS = 25

APP_DATA_DIRNAME = "PeakLink"
LICENSE_FILENAME = "license.peaklic"
CONFIG_FILENAME = "config.json"

# Tailscale CGNAT 網段
TAILSCALE_CGNAT_PREFIX = "100."

# 預設兩岸中繼候選。請改成你實際部署的網域。
# 設計目標：走 TLS/WebSocket（建議 443），讓台灣與大陸都能連到「共同可達」的中繼，
# 而不是內建翻牆協定。
DEFAULT_RELAYS: list[dict[str, str]] = [
    {
        "id": "auto-local",
        "label": "本機測試中繼",
        "http": "http://127.0.0.1:8787",
        "ws": "ws://127.0.0.1:8787/ws",
        "region": "local",
    },
    {
        "id": "tw",
        "label": "台灣中繼",
        "http": "https://relay-tw.s1214098.com.tw",
        "ws": "wss://relay-tw.s1214098.com.tw/ws",
        "region": "tw",
    },
    {
        "id": "dual-shore",
        "label": "海外中繼（台灣／大陸共同可達）",
        "http": "https://relay-sea.s1214098.com.tw",
        "ws": "wss://relay-sea.s1214098.com.tw/ws",
        "region": "sea",
    },
]

# 付費方案（付款金額 → 使用期間）。可在發行授權時覆寫。
PRICE_PACKAGES: dict[str, dict] = {
    "month": {"days": 30, "amount": 199, "currency": "TWD", "label": "月費會員"},
    "quarter": {"days": 90, "amount": 499, "currency": "TWD", "label": "季費會員"},
    "year": {"days": 365, "amount": 1599, "currency": "TWD", "label": "年費會員"},
}

# 自訂金額換算：每 10 元新台幣 ≈ 1 天，最少 7 天
CUSTOM_TWD_PER_DAY = 10
CUSTOM_MIN_DAYS = 7
