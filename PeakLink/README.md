# 峰連遠端 PeakLink

Windows 遠端協助軟體：給客戶用的一般遠端，以及 Taliscale（Tailscale）網內直連。同一套程式分成免費版與會員版。

## 版本怎麼分

| 版本 | 收費 | 限制 |
| --- | --- | --- |
| 免費用戶端 | 不收費 | **每一次遠端連線最長 5 分鐘**。時間到會斷線，可再連（仍是每次 5 分鐘）。連入一定會詢問被控端。 |
| 收費會員 | 依付款換算使用期間 | 軟體可用到到期日；**單次遠端不限 5 分鐘**。可開「自動接受連入」。到期後要續費重發授權檔。 |

會員期間由你收款後發行 `.peaklic`：

- 方案：月費 199／30 天、季費 499／90 天、年費 1599／365 天（可改 `peaklink/constants.py`）
- 或不走方案：`--amount 金額`，預設每 10 元新台幣 1 天，最少 7 天；也可用 `--days` 直接指定天數

## 兩種連線模式

1. **一般遠端（給客戶）**  
   被控端顯示 9 位數 ID + 6 位數密碼。客戶用操作端輸入即可。流量走中繼，畫面用連線密碼做端到端加密，中繼只轉送。

2. **Taliscale 模式（Tailscale 相容）**  
   雙方都在 Tailscale 網內時，被控端會在 `100.x` 位址開直連埠（預設 18787）。操作端選 Taliscale 模式並填對方 `100.x` IP。這條路不經過公共中繼。

兩種模式可同時開。沒有 Tailscale 時，一般遠端仍可用。

## 台灣 ⇄ 大陸怎麼串

這套軟體**不安裝翻牆客戶端**（例如 V2Ray／Shadowsocks）。兩岸要連得上，靠的是：

1. 把中繼架在**兩邊都連得到**的位置（常見是香港、新加坡、日本），對外 **443 + WSS**。
2. 在被控端／操作端設定檔填自訂中繼 `custom_relay_http` / `custom_relay_ws`。
3. 軟體會探測候選中繼，**優先選共同可達、延遲可接受的海外中繼**。
4. 若雙方都在 Tailscale，改走 Taliscale 直連。

請自行準備一台 VPS 跑 `peaklink-server`，並用 Nginx／Caddy 做 TLS 反代到 8787。

## Windows 安裝檔

安裝檔由 GitHub Actions 在 Windows 上打包，產出：

- `PeakLink-Setup-1.0.0.exe`：雙擊安裝（不需系統管理員，裝到使用者目錄）
- `PeakLink-Portable-1.0.0.zip`：免安裝解壓即用

在此 PR／Actions 下載：**PeakLink-Windows-Setup** artifact。

本機（Windows）自行打包：

```powershell
cd PeakLink
# 可選：choco install innosetup
.\scripts\build-windows.ps1
```

安裝後啟動「峰連遠端」即可當免費用戶。會員把 `.peaklic` 用啟動器「匯入授權」。

## Windows 開發執行

```powershell
cd PeakLink
py -3.12 -m venv .venv
.\.venv\Scripts\pip install -e ".[desktop]"
peaklink-app
```

## 發行會員授權（你收款後）

```powershell
peaklink-gen-keys --out peaklink\keys     # 正式環境只做一次，私鑰不要外流
peaklink-license --name "王小明" --package month --out wang.peaklic
peaklink-license --name "客戶B" --amount 800 --days 60 --out b.peaklic
```

把 `.peaklic` 傳給對方匯入。內建 `public.pem` 必須與發行用的 `private.pem` 成對。倉庫內金鑰僅供開發測試，上線請自行重產並替換 `peaklink/keys/public.pem` 後再打包。

## 本機開發測試

```bash
cd PeakLink
python3 -m pip install -e ".[dev]"
python3 -m pytest -q
python3 -m peaklink.server.app --port 8787
```

被控端與操作端若在 Linux 測試，畫面擷取需要可用的顯示環境；授權、中繼配對、5 分鐘政策不依賴 GUI。

## 安全說明

- 被控端會顯示「正在被遠端控制」。免費版連入必詢問。
- 這是遠端協助工具，不是隱藏後門。請只裝在你有權操作的電腦，並把 ID／密碼當一次性分享。
- 中繼看得到連線配對，看不到以密碼加密的畫面內容。
