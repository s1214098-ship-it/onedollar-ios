# 公司伺服器上的張張後台

PHT-SR（Tailscale `100.92.117.104` / `pht-sr`）網站磁碟是 `F:\Web`，SMB 分享名稱是 **`PHT-Web`**。

```
\\100.92.117.104\PHT-Web          = F:\Web
```

IIS 站台：

| 站台 | 實體路徑 | HTTPS 主機 |
| --- | --- | --- |
| LINGZANZAN-Staging | `F:\Web\lingzanzan-staging` | **www.lingzanzan.com**（對外） |
| BaoHui-Staging | `F:\Web\baohui-staging` | `baohui.paohui.org` |
| Paohui-Portal-Staging | `F:\Web\paohui-portal-staging` | `paohui.org` |

張張 PHP 作業檔仍在 `baohui-staging\one-dollar-auction\`。LINGZANZAN 站用 IIS 虛擬目錄接到同一份檔：

| 虛擬目錄 | 實體路徑 |
| --- | --- |
| `/one-dollar-auction` | `F:\Web\baohui-staging\one-dollar-auction` |
| `/zhangzhang` | 同上 |

## 線上入口（對外以 lingzanzan.com 為準）

| 用途 | 網址 |
| --- | --- |
| 張張登入 | https://www.lingzanzan.com/one-dollar-auction/ |
| 捷徑 | https://www.lingzanzan.com/zhangzhang/ |
| 捷徑 | https://www.lingzanzan.com/zhangzhang.html |
| 電商營運作業 | https://www.lingzanzan.com/one-dollar-auction/operations.php |
| 張張電腦已帶入 | https://www.lingzanzan.com/lingzanzan-computer-receipts.php |
| 領讚讚後台側欄 | https://www.lingzanzan.com/admin.html |
| paohui.org 第五張卡 | https://paohui.org/ |

`baohui.paohui.org/one-dollar-auction/` 仍可用（同一份檔），但對外請走 lingzanzan.com。

## 原始檔位置

```
F:\Web\baohui-staging\one-dollar-auction\index.php
F:\Web\baohui-staging\one-dollar-auction\operations.php
F:\Web\baohui-staging\lingzanzan-computer-receipts.php
F:\Web\lingzanzan-staging\zhangzhang.html
F:\Web\lingzanzan-staging\lingzanzan-computer-receipts.php   （轉呼叫寶輝那份）
F:\Web\lingzanzan-staging\admin-order-tracking.html          （訂單列表／進度；取消單不進「全部狀態」）
F:\Web\lingzanzan-staging\assets\admin.js                    （?v=20260819-blacklist-card-1 姓名／電話黑名單）
F:\Web\lingzanzan-staging\assets\member-risk-v2.js           （訂單卡姓名電話旁顯示黑名單）
F:\Web\lingzanzan-staging\assets\image-upload-paste.js       （所有圖片上傳欄可貼上／拖放）
F:\Web\lingzanzan-staging\assets\admin-navigation.js
F:\Web\paohui-portal-staging\index.html
```
