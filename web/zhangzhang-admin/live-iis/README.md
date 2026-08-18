# 已套到 PHT-SR 的 PHP 登入修正

不要把 `operations.php`（約 969KB）提交進 git。線上檔在 `F:\Web\baohui-staging\`，還原用 `*.bak-zhangzhang-login-20260818`。

## `one-dollar-auction/operations.php`

- 若 `$_SESSION['user']` 已由張張／一元競標登入寫入、且沒有 `baohui_logged_in`，`baohui_bridge_one_dollar_user()` 直接 return，不再改寫 session、也不再因寶輝權限不足而 403。
- 未登入改導 `/one-dollar-auction/`，不再導 `/admin.php`。

## `one-dollar-auction/index.php`

- `require_login()` 未登入改導 `./`。
- 登入頁標題改為「張張管理後台」。登入成功仍進 `operations.php`。

## `lingzanzan-computer-receipts.php`

- 未登入改導 `/one-dollar-auction/`；embed 401 文案改為請登入張張。
