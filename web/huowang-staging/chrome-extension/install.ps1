$ErrorActionPreference = "Stop"
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$Version = "0.5.35"
$Dst = Join-Path $env:LOCALAPPDATA "HuowangFbExtension"
$SrcLocal = "F:\Web\huowang-staging\chrome-extension"
$BaseUrl = "https://huowang.paohui.org/chrome-extension"
$Files = @(
  "manifest.json",
  "background.js",
  "composer.js",
  "popup.html",
  "popup.js",
  "options.html",
  "options.js",
  "config.example.js",
  "icon16.png",
  "icon48.png",
  "icon128.png",
  "INSTALL.txt"
)

Write-Host "火旺專屬房屋自動化 $Version 終端機安裝" -ForegroundColor Cyan
New-Item -ItemType Directory -Force -Path $Dst | Out-Null

function Copy-FromLocal {
  param([string]$From)
  Get-ChildItem $From -File | Where-Object {
    $_.Name -notin @("web.config", "install.ps1", "install.cmd")
  } | ForEach-Object {
    Copy-Item $_.FullName (Join-Path $Dst $_.Name) -Force
  }
}

if (Test-Path (Join-Path $SrcLocal "manifest.json")) {
  Write-Host "從 $SrcLocal 複製檔案"
  Copy-FromLocal $SrcLocal
} else {
  Write-Host "本機沒有 F: 磁碟，改從網站下載"
  foreach ($name in $Files) {
    $out = Join-Path $Dst $name
    Write-Host "  下載 $name"
    Invoke-WebRequest -UseBasicParsing -Uri "$BaseUrl/$name" -OutFile $out
  }
}

$settingsFile = "F:\Web\huowang-staging\data\facebook-group-candidates.json"
$localConfig = Join-Path $SrcLocal "config.local.js"
if (Test-Path $localConfig) {
  Copy-Item $localConfig (Join-Path $Dst "config.local.js") -Force
} elseif (Test-Path $settingsFile) {
  try {
    $settings = Get-Content $settingsFile -Raw -Encoding UTF8 | ConvertFrom-Json
    $key = [string]$settings.settings.workerKey
    if ($key) {
      @"
var HUOWANG_FB_DEFAULTS = {
  apiBase: "https://huowang.paohui.org",
  workerKey: "$key",
  workerId: "chrome-ext-fengzhi"
};
"@ | Set-Content -Path (Join-Path $Dst "config.local.js") -Encoding UTF8
    }
  } catch {}
}

$manifestPath = Join-Path $Dst "manifest.json"
if (-not (Test-Path $manifestPath)) { throw "安裝失敗：找不到 manifest.json" }
$manifest = Get-Content $manifestPath -Raw -Encoding UTF8
if ($manifest -notmatch '"version"\s*:\s*"' + [regex]::Escape($Version) + '"') {
  Write-Host "警告：資料夾版本可能不是 $Version，請看 chrome://extensions" -ForegroundColor Yellow
}

$chrome = @(
  "${env:ProgramFiles}\Google\Chrome\Application\chrome.exe",
  "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
  "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chrome) { throw "找不到 Google Chrome" }

$launcher = Join-Path $Dst "start-huowang-chrome.cmd"
@"
@echo off
start "" "$chrome" --load-extension="$Dst" --restore-last-session
"@ | Set-Content -Path $launcher -Encoding ASCII

$desktop = [Environment]::GetFolderPath("Desktop")
$shortcutPath = Join-Path $desktop "火旺Facebook發文.lnk"
$w = New-Object -ComObject WScript.Shell
$sc = $w.CreateShortcut($shortcutPath)
$sc.TargetPath = $chrome
$sc.Arguments = "--load-extension=`"$Dst`" --restore-last-session"
$sc.WorkingDirectory = $Dst
$sc.WindowStyle = 1
$sc.Description = "火旺專屬房屋自動化 $Version"
$sc.Save()

Write-Host "會先關閉目前的 Chrome，再載入 $Version。請確認分頁可還原。" -ForegroundColor Yellow
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep -Seconds 2
Start-Process -FilePath $chrome -ArgumentList @("--load-extension=$Dst", "--restore-last-session")

Write-Host ""
Write-Host "完成。擴充功能資料夾：$Dst" -ForegroundColor Green
Write-Host "桌面捷徑：火旺Facebook發文.lnk"
Write-Host "請打開 chrome://extensions 確認版本是 $Version，Service Worker 為正在執行中。"
Write-Host "之後請用桌面「火旺Facebook發文」開啟 Chrome，擴充功能才會跟著載入。"
