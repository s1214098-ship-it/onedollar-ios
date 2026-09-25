# 廠商進貨單：廠商 ↔ 採購平台串聯

Live-only. 不把線上 PHP/JS 提交進 onedollar-ios。

## 對照

| 廠商 | 採購平台 |
| --- | --- |
| 拼直／拚直、拼張／拚張、拼郭／拚郭 | 拼多多 |
| 名稱含 拼多多（例如 拼多多集運倉） | 拼多多 |
| 抖音 | 抖音 |
| 其他廠商 | 微信 |
| 印尼廠商（預先加入，之後用） | 印尼商場 |

廠商變更為來源。先選平台不覆寫。＋新增廠商／＋新增平台保留。

## 部署

- sidecar：`admin-inventory-entry.html.new-vendor-plat-1`
- sidecar：`assets/inventory-freight-entry-20260810.js.new-vendor-plat-1`
- swap：`swap-vendor-plat-1.php?k=vendor-plat-swap-20260925-1`
- cache-bust：`?v=20260925-vendor-plat-1`

已填寫的進貨分頁不會自動重載，請 Ctrl+F5。
