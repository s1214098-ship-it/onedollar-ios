# 已套到 PHT-SR 的張張入口

對外網址是 **https://www.lingzanzan.com/**。作業 PHP 仍在 `F:\Web\baohui-staging\one-dollar-auction\`，IIS 虛擬目錄接到領讚讚站。

不要把 `operations.php`（約 969KB）或會員 JSON 提交進 git。

## 領讚讚站

- IIS：`LINGZANZAN-Staging` 虛擬目錄 `/one-dollar-auction`、`/zhangzhang`
- `zhangzhang.html` 轉到 `/one-dollar-auction/`
- `lingzanzan-computer-receipts.php` `require` 寶輝那份收據程式
- `assets/admin-navigation.js` 側欄加「張張／一元競標」
- `order-admin-api-v6.php` + `json-atomic-write.php`：填物流單號存檔時，Windows 不再因 `orders.json` rename 存取被拒而 HTTP 500

## 登入修正（`baohui-staging`）

- 未登入改導 `/one-dollar-auction/`，不再導寶輝 `/admin.php`
- 張張獨立登入的 `$_SESSION['user']` 不會被寶輝權限橋接蓋掉


## `one-dollar-auction/operations.php`

- 若 `$_SESSION['user']` 已由張張／一元競標登入寫入、且沒有 `baohui_logged_in`，`baohui_bridge_one_dollar_user()` 直接 return，不再改寫 session、也不再因寶輝權限不足而 403。
- 未登入改導 `/one-dollar-auction/`，不再導 `/admin.php`。

## `one-dollar-auction/index.php`

- `require_login()` 未登入改導 `./`。
- 登入頁標題改為「張張管理後台」。登入成功仍進 `operations.php`。

## `lingzanzan-computer-receipts.php`

- 未登入改導 `/one-dollar-auction/`；embed 401 文案改為請登入張張。
