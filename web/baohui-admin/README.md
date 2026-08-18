# 寶輝科技管理後台伺服器

給 [寶輝科技有限公司](https://www.s1214098.com.tw/) 使用的獨立後台伺服器：把線上報修、回收、專案申請、員工請假，以及工單／人資／薪資／任務寫進同一份 SQLite 共用資料。

現有入口 [baohui.paohui.org](https://baohui.paohui.org/) 的 `repair`、`customer-api/repair` 在部分主機上會 404。這套伺服器補上同一組路徑，可單獨跑，也可當現有 `admin.php` 前端的 `api.php` 相容後端。

## 功能

- 服務入口、線上報修／回收／專案申請（`/customer-api/*`）
- 員工請假申請與管理者審核
- 維修／回收工單派工與完修
- 會員、員工、權限、獎懲、特休與薪資月報（底薪不低於法定基本工資）
- 工作任務、備忘錄、CODEX 回報、原廠送修
- 電子發票、關稅單號（手續費 30 元）、報價、硬體行情
- 與現有前端相容的 `api.php?action=login|load|save|status|live_sync|...`
- 樂觀鎖定：多人同時存檔時回 `409`，避免覆蓋別人剛寫入的資料

## 啟動

需要 Node.js 22+（使用內建 `node:sqlite`，不必 `npm install`）。

```bash
cd web/baohui-admin
cp .env.example .env   # 可選，也可用環境變數
node server.js
```

預設網址：

- 服務入口 <http://127.0.0.1:8787/>
- 後台登入 <http://127.0.0.1:8787/admin>
- 報修 <http://127.0.0.1:8787/repair>
- 回收 <http://127.0.0.1:8787/recycle>
- 請假 <http://127.0.0.1:8787/leave>

預設管理員：`admin` / `ChangeMe-2026!`（請立刻改掉）。示範員工：`曾麒`、`李御榛`、`李建宏`，密碼 `1234`。

## 環境變數

| 變數 | 說明 |
| --- | --- |
| `PORT` | 監聽埠，預設 `8787` |
| `BAOHUI_ADMIN_USER` | 管理員帳號 |
| `BAOHUI_ADMIN_PASSWORD` | 管理員密碼（首次寫入 SQLite 後以雜湊保存） |
| `BAOHUI_SESSION_SECRET` | Cookie 簽名密鑰 |
| `BAOHUI_DATA_DIR` | SQLite 與上傳檔目錄，預設 `./data` |

資料檔：`data/baohui.sqlite`。整份後台 JSON 存在 `kv.app_data`，與現有 PHP 後台的「伺服器共用儲存」相同。

## 測試

```bash
cd web/baohui-admin
node --test tests/*.test.js
```

## 接到現有網站

1. 用反向代理把 `baohui.paohui.org` 的 `/api.php`、`/customer-api/`、`/admin`、`/repair` 指到本服務。
2. 或只把 `api.php` 指過來，繼續使用原本的 `admin.php` 畫面。
3. 若要匯入舊後台資料：登入後 `GET /api.php?action=load` 可看到資料形狀；也可把舊 JSON 透過 `action=save` 寫入（需帶目前的 `updated_at`）。
