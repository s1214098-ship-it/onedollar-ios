# 公司伺服器上的張張後台

PHT-SR（Tailscale `100.92.117.104` / `pht-sr`）網站磁碟是 `F:\Web`，SMB 分享名稱是 **`PHT-Web`**，不是 `WEB`。

```
\\100.92.117.104\PHT-Web          = F:\Web
\\100.92.117.104\F-SR(W)\Web      = 同一棵樹
```

IIS 站台（`applicationHost.config`）：

| 站台 | 實體路徑 | HTTPS 主機 |
| --- | --- | --- |
| BaoHui-Staging | `F:\Web\baohui-staging` | `baohui.paohui.org` |
| Paohui-Portal-Staging | `F:\Web\paohui-portal-staging` | `paohui.org` |
| Huowang-Staging | `F:\Web\huowang-staging` | `huowang.paohui.org` |

## 線上入口

| 用途 | 網址 |
| --- | --- |
| paohui.org 第五張卡 | https://paohui.org/ |
| 捷徑 | https://paohui.org/zhangzhang/ |
| 張張登入（不必先登寶輝） | https://baohui.paohui.org/one-dollar-auction/ |
| 捷徑 | https://baohui.paohui.org/zhangzhang/ |
| 電商營運作業 | https://baohui.paohui.org/one-dollar-auction/operations.php |
| 張張電腦已帶入 | https://baohui.paohui.org/lingzanzan-computer-receipts.php |

未登入時，`operations.php` / 電腦已帶入會導到 `/one-dollar-auction/`，不再踢去寶輝 `/admin.php`。寶輝總部左側「電商營運管理」仍可用同一組 `BAOHUI_ADMIN` cookie。

`zhangzhang.paohui.org` 目前沒有 DNS，所以獨立子網域還沒開。若要做成和火旺／寶輝一樣的子網域，需在 Cloudflare 加 CNAME，並在 IIS 幫 BaoHui-Staging 加 SNI 綁定與憑證。

## 原始檔位置

```
F:\Web\baohui-staging\one-dollar-auction\index.php
F:\Web\baohui-staging\one-dollar-auction\operations.php
F:\Web\baohui-staging\lingzanzan-computer-receipts.php
F:\Web\paohui-portal-staging\index.html
F:\Web\baohui-staging\zhangzhang\index.php
F:\Web\paohui-portal-staging\zhangzhang\index.html
```

還原備份檔名結尾 `*.bak-zhangzhang-login-20260818` / `index.html.bak-zhangzhang-card-20260818`。

本 repo 的 `live-iis/` 只放入口頁與捷徑小檔，不提交 `operations.php`（約 969KB）或 `data/*.json`（含會員個資）。
