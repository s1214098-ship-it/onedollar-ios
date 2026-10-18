param(
  [string]$WebRoot = "F:\Web\huowang-staging",
  [string]$TaskName = "HuowangYungchingPoolsSync"
)

$ErrorActionPreference = "Stop"
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($task) {
  if ($task.State -eq "Disabled") {
    Enable-ScheduledTask -TaskName $TaskName | Out-Null
    Write-Host "Enabled $TaskName"
  } else {
    Write-Host "OK $TaskName state=$($task.State)"
  }
  exit 0
}

$install = @(
  (Join-Path $WebRoot "api\install-yungching-sync-task.ps1"),
  (Join-Path $WebRoot "install-yungching-sync-task.ps1")
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

if (-not $install) {
  throw "install-yungching-sync-task.ps1 not found"
}

Write-Host "Missing $TaskName; installing from $install"
& $install -WebRoot $WebRoot
