# 寶輝電子發票（現有後台這條線）

這份 PHP 是給 **現在的寶輝後台**用的，不是另開一套 Node 環境。

對應線上：

- 畫面：`https://baohui.paohui.org/admin.php` → 左邊「電子發票記帳」iframe 載入 `accounting-invoices.php`
- OAuth 回跳：**只能是** `https://baohui.paohui.org/accounting-gmail-oauth.php`

對應公司機路徑：

| 檔 | 放到哪 |
| --- | --- |
| `accounting-lib.php` `accounting-api.php` `accounting-invoices.php` `accounting-gmail-oauth.php` `accounting-rules.json` | `F:\Web\baohui-staging\`（與現有 `admin.php` 同一層） |
| 發票 SQLite／Gmail token／PDF | `F:\Data\BaohuiAccounting\`（不要進 git） |
| 進貨單 | `F:\Web\baohui-staging\one-dollar-auction\data\stock_movements.json` |
| Google 憑證 | `F:\Web\baohui-staging\certs\cacert.pem` |

規則（`accounting-rules.json`）：

- 讀信：`s1214098@gmail.com`
- 捷元：`捷元ebill@gcnc-group.com`／主旨「捷元電子對帳單」／統編 `23134543`
- 買方統編：`23365425`
- 只比 **2026-08-18** 起的進貨單
- 不抓 Agoda；統一數網 `70537075` 不當捷元

把這幾個 PHP 覆蓋到 `F:\Web\baohui-staging\` 後，用後台帳號登入再開「電子發票記帳」→「連接 Gmail」。
