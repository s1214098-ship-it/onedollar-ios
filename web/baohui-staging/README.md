# 寶輝電子發票（現有後台這條線）

這份 PHP **就是線上 `F:\Web\baohui-staging\` 那套**，不是另開 Node 環境。資料庫、Gmail token、PDF 仍只放在公司機 `F:\Data\BaohuiAccounting\`，不要進 git。

對應線上：

- 畫面：`https://baohui.paohui.org/admin.php` → 左邊「電子發票記帳」iframe 載入 `accounting-invoices.php`
- OAuth 回跳：**只能是** `https://baohui.paohui.org/accounting-gmail-oauth.php`

| 檔 | 放到哪 |
| --- | --- |
| `accounting-*.php`、`tools/gmail-sync.php` | `F:\Web\baohui-staging\`（與現有 `admin.php` 同一層） |
| 發票 SQLite／Gmail token／PDF | `F:\Data\BaohuiAccounting\`（不要進 git） |
| 進貨單 | `F:\Web\baohui-staging\one-dollar-auction\data\stock_movements.json` |
| Google TLS | `certs\cacert.pem` 或資料目錄的 `cacert.pem` |

規則：

- 讀信：`s1214098@gmail.com`
- 捷元：`ebill@gcnc-group.com`／`捷元ebill@gcnc-group.com`／`EInvoice@gcnc-group.com`；主旨「捷元電子對帳單」；統編 `23134543`
- 買方統編：`23365425`
- 只比 **2026-08-18** 起的進貨入庫單
- 供應商名稱空白的進貨單也列入（例如 `JH-20260818-001`）
- 金額可對未稅，或含稅 ±5%
- 不抓 Agoda；統一數網 `70537075` 不當捷元

覆蓋 PHP 後，用後台帳號登入再開「電子發票記帳」→「連接 Gmail」／「捷元進貨比對」。

Cursor 信件整理（同一個 `s1214098@gmail.com`）：

- 後台：電子發票記帳 →「整理 Cursor 信件」，或直接開 `https://baohui.paohui.org/accounting-gmail-organize.php`
- 第一次要再按一次 Google 授權（現有連線只有讀信，不能建資料夾）
- 會建立 Gmail 資料夾 `Cursor`，把 cursor[bot]／GitHub Cursor 通知從收件匣移進去，並設自動篩選
- 備用：Gmail 設定 → 篩選器 → 匯入 `gmail-filter-cursor.xml`
