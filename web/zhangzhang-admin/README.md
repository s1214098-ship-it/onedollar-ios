# 張張管理後台（伺服器）

獨立的電商營運後台。對外入口掛在 **www.lingzanzan.com**（領讚讚 IIS），作業檔仍在 PHT-SR 的 `F:\Web`，**不必先登入寶輝總部**。

線上入口：

- https://www.lingzanzan.com/one-dollar-auction/ （張張登入，對外主網址）
- https://www.lingzanzan.com/zhangzhang/
- https://www.lingzanzan.com/zhangzhang.html
- https://www.lingzanzan.com/lingzanzan-computer-receipts.php （張張電腦已帶入）
- https://www.lingzanzan.com/admin.html （領讚讚後台側欄「張張／一元競標」）
- https://paohui.org/ （第五張卡，連到 lingzanzan.com）

本包另有一份 Node 可攜版，可在 Windows / Linux 用 `node server.js` 跑，資料存在本機 `data/db.json`。路徑對照見 `WEB-MAP.md`。

## 功能

- 登入、權限身分、操作 LOG
- **張張電腦已帶入**：店內現貨送出即進產品庫，現貨免 7-11 / 全家 / 郵局單號
- 產品建檔（條碼、顏色、成本、倉位）
- 庫存進貨 / 出庫 / 預留
- 貨倉、貨架、層位
- 一元競標排程與發文草稿
- 得標結算、收款、出貨、物流單號
- 客戶 / 黑名單、廠商
- 營收 / 成本 / 毛利
- JSON 備份與匯入（可合併 `one-dollar-auction/data/*.json`）

## 啟動

```bash
cd web/zhangzhang-admin
node server.js
```

瀏覽器開 <http://127.0.0.1:8788>

預設帳號：`admin` / `ChangeMe-2026!`（與一元競標後台相同提示，上線請立刻改密碼）

環境變數：

| 變數 | 說明 |
| --- | --- |
| `PORT` | 埠號，預設 `8788` |
| `ZHANGZHANG_DATA_DIR` | 資料目錄，預設 `./data` |

Windows 可把此資料夾拷到 `F:\Web` 以外的目錄用工作排程跑 Node。正式線上作業走 IIS 上的 PHP（見上列網址），不必再開 8788。

## 公司 VPN / WEB 在哪裡

公司 Tailscale：`100.92.117.104`（PHT-SR Windows）。網站磁碟是 `F:\Web`，分享名稱是 **`PHT-Web`**：

```
\\100.92.117.104\PHT-Web
```

QNAP（`100.97.127.26`）另有 `WEB` 分享，與這台 IIS 不是同一份。詳見 `WEB-MAP.md`。

| WEB 內容 | 公開位址 |
| --- | --- |
| 張張登入（對外） | https://www.lingzanzan.com/one-dollar-auction/ |
| 張張電腦已帶入 | https://www.lingzanzan.com/lingzanzan-computer-receipts.php |
| paohui.org 張張卡片 | https://paohui.org/ |
| 產品／會員／廠商／貨倉 JSON | https://baohui.paohui.org/one-dollar-auction/data/ |

在**已連 VPN 的公司電腦**：

```powershell
powershell -File scripts/copy-from-vpn.ps1
```

在雲端或任何能上網的地方，拉已上線的 WEB 副本（不含密碼）：

```bash
node scripts/pull-live-web.js
# 產品 + 會員很大，要加 --all
```

拉下來後，後台「備份匯入」或 `POST /api/import-live` 會依 id 合併進 `data/db.json`。

## 匯入線上資料

把現有檔案內容貼進「備份匯入」：

- `one-dollar-auction/data/products.json` → `{ "products": [ ... ] }`
- `members.json` → `{ "members": [ ... ] }`
- `suppliers.json` → `{ "suppliers": [ ... ] }`
- `warehouses.json` → `{ "warehouses": [ ... ] }`

也可以一次貼多個 key。依 `id` 合併，不會整包覆蓋。

## 測試

```bash
cd web/zhangzhang-admin
node --test test/api.test.js
```
