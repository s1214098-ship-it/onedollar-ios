# 廠商進貨單：空白可直接打入新增

Live-only. 不把線上 PHP/JS 提交進 iOS app 原始碼。

## 行為

- 廠商／採購平台都是**空白輸入欄**，可直接打新名稱，不必先去廠商管理建檔。
- 舊頁若還殘留 `<select>請選擇已建檔廠商</select>`，進頁時會換成 input。
- ＋新增廠商／＋新增平台停在這一張單：聚焦空白欄（已打字就把名稱記住進建議清單），不再跳走。
- 選到拼直／拼張／拼郭仍自動帶入拼多多；其他廠商平台留空讓人打。

## 部署

- sidecar：`admin-inventory-entry.html.new-vendor-blank-1`
- sidecar：`assets/inventory-freight-entry-20260810.js.new-vendor-blank-1`
- swap：`swap-vendor-blank-1.php?k=vendor-blank-swap-20261024-1`
- cache-bust：`?v=20261024-vendor-blank-1`
