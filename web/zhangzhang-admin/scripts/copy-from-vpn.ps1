# 在公司電腦（已連 Tailscale VPN）執行，把 PHT-SR 上的 WEB 拷到目前資料夾。

$ErrorActionPreference = "Stop"
$dest = Join-Path $PSScriptRoot "..\data\vpn-web"
New-Item -ItemType Directory -Force -Path $dest | Out-Null

$roots = @(
  "\\100.92.117.104\PHT-Web",
  "\\100.92.117.104\F-SR(W)\Web",
  "\\pht-sr\PHT-Web",
  "\\100.92.117.104\WEB",
  "\\100.92.117.104\Web"
)

$found = $null
foreach ($root in $roots) {
  Write-Host "試 $root"
  if (Test-Path $root) {
    $found = $root
    break
  }
}

if (-not $found) {
  Write-Host "列分享："
  cmd /c "net view \\100.92.117.104"
  throw "找不到 PHT-Web。公司網站在 \\100.92.117.104\PHT-Web（F:\Web），不是分享名 WEB。"
}

Write-Host "從 $found 複製到 $dest"
robocopy $found $dest /E /XD node_modules .git /NFL /NDL /NJH /NJS
Write-Host "完成。張張相關請看："
Get-ChildItem $dest -Directory | Select-Object Name
Get-ChildItem (Join-Path $dest "baohui-staging") -ErrorAction SilentlyContinue | Select-Object Name
Get-ChildItem $dest -Filter "*zhang*" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
Get-ChildItem $dest -Filter "*one-dollar*" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
Get-ChildItem $dest -Filter "*computer-receipts*" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
