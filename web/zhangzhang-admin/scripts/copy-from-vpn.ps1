# 在公司電腦（已連 Tailscale VPN）執行，把 QNAP/主機上的 WEB 拷到目前資料夾。
# 雲端 Cursor 連 100.92.117.104 會 Connection reset，一定要在 VPN 內的 Windows 跑。

$ErrorActionPreference = "Stop"
$dest = Join-Path $PSScriptRoot "..\data\vpn-web"
New-Item -ItemType Directory -Force -Path $dest | Out-Null

$roots = @(
  "\\100.92.117.104\WEB",
  "\\100.92.117.104\Web",
  "\\100.92.117.104\web",
  "\\100.92.117.104\Public\WEB",
  "\\100.92.117.104\Public"
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
  throw "找不到 WEB 分享。請確認已連公司 VPN，且分享名稱是 WEB。"
}

Write-Host "從 $found 複製到 $dest"
robocopy $found $dest /E /XD node_modules .git /NFL /NDL /NJH /NJS
Write-Host "完成。張張相關請看："
Get-ChildItem $dest -Directory | Select-Object Name
Get-ChildItem $dest -Filter "*zhang*" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
Get-ChildItem $dest -Filter "*one-dollar*" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
Get-ChildItem $dest -Filter "*computer-receipts*" -Recurse -ErrorAction SilentlyContinue | Select-Object FullName
