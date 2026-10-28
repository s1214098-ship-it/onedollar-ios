#Requires -Version 5.1
param(
  [Parameter(Mandatory = $true)]
  [ValidateSet('12:00', '15:00')]
  [string]$Slot
)

$ErrorActionPreference = 'Continue'
$tz = [TimeZoneInfo]::FindSystemTimeZoneById('Taipei Standard Time')
$now = [TimeZoneInfo]::ConvertTimeFromUtc((Get-Date).ToUniversalTime(), $tz)
$rootCandidates = @(
  'C:\PHT-Web\lingzanzan-staging',
  'D:\PHT-Web\lingzanzan-staging',
  '\\192.168.2.142\PHT-Web\lingzanzan-staging'
)
$root = $rootCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $root) {
  Write-Error 'lingzanzan-staging not found. Edit $rootCandidates.'
  exit 1
}

$dataDir = Join-Path $root 'data'
New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
$stamp = $now.ToString('yyyy-MM-ddTHH:mm:ss+08:00')
$slotFile = Join-Path $dataDir 'official-check-slot.json'
@{
  slot              = $Slot
  timezone          = 'Asia/Taipei'
  at                = $stamp
  thoroughWindow    = '12:00 and 15:00'
  retiredWindows    = @('12:00 and 18:00', '12:00 and 16:00')
  auditOnlyIsNotDone = $true
  line429MustNotSkip = $true
  captchaAuthorized  = $true
  carriers = @(
    '7-11 shopmore https://tracking.shopmore.com.tw/',
    '全家 inner FME https://ecfme.fme.com.tw/FMEDCFPWebV2_II/index.aspx?orderno=',
    '郵局 https://postserv.post.gov.tw/pstmail/main_mail.html?targetTxn=EB500100',
    '新竹 HCT https://www.hct.com.tw/Search/SearchGoods_n.aspx',
    '大榮 KTJ https://www.kerrytj.com/zh/checking'
  )
} | ConvertTo-Json -Depth 5 | Set-Content -Encoding UTF8 $slotFile

Write-Host "Slot $Slot Asia/Taipei written to $slotFile"
Write-Host 'BINDING: real official lookups + write-back. carrier-thorough-audit.js leftover counts are NOT completion.'

$php = Get-Command php -ErrorAction SilentlyContinue
$scheduled = Join-Path $root 'carrier-tracking-scheduled-run.php'
if ($php -and (Test-Path $scheduled)) {
  # Rebuild queue / LINE report. --no-line so a 429 cannot be treated as "skip the window".
  & $php.Source $scheduled --force --no-line
}

$audit = Join-Path $root 'scripts\carrier-thorough-audit.js'
$node = Get-Command node -ErrorAction SilentlyContinue
if ($node -and (Test-Path $audit)) {
  & $node.Source $audit --force --no-mail
  Write-Host 'Audit listed leftovers. An agent must still open the five official sites and write back.'
}

exit 0
