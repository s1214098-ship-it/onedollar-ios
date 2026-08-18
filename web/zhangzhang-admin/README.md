# 張張管理後台（伺服器）

獨立的電商營運後台伺服器，對應寶輝總部「電商營運管理 / 張張後台」，但**不必先登入寶輝總部**。

線上原本開在：

- 寶輝總部 iframe：`/one-dollar-auction/operations.php`
- 張張電腦已帶入：`/lingzanzan-computer-receipts.php`

本包可以在 Windows / Linux 用 Node 直接跑，資料存在本機 `data/db.json`。

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

Windows 可把此資料夾拷到 `Z:\WEB\zhangzhang-admin`，用工作排程或 `node server.js` 常駐。IIS 可用 HttpPlatformHandler / iisnode 反向代理到 8788。`paohui.org` 入口可加一張卡片指到這台伺服器。

## 公司 VPN / WEB 在哪裡

公司 Tailscale：`100.92.117.104`（QNAP / PHT-SR）。網站後台磁碟是 `Z:\WEB`，對應分享多半是 `\\100.92.117.104\WEB`。

Cursor 雲端機**不在**公司 Tailscale 裡：連 80/445 會 `Connection reset`，所以雲端讀不到 QNAP 上的 WEB 原始 PHP。已上線的 IIS 副本在：

| WEB 內容 | 公開位址 |
| --- | --- |
| 張張／一元競標後台 | https://baohui.paohui.org/one-dollar-auction/ |
| 張張電腦已帶入 | https://baohui.paohui.org/lingzanzan-computer-receipts.php |
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
